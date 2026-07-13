<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Llm\Application\Prompt\ConversationSummaryPrompt;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;

/**
 * Folds an aging batch of conversation turns into a compact rolling summary so a
 * long dialogue stays cheap to replay without losing its thread.
 *
 * The orchestrator keeps only a short window of the most-recent turns verbatim;
 * everything older is compressed here into (a) a running natural-language summary
 * of durable facts / decisions / open tasks, and (b) an explicit `active_intent`
 * line naming what the user is currently trying to accomplish. The intent line is
 * what lets the planner hold focus while the user's request arrives in fragments.
 *
 * Pure and provider-agnostic (mirrors {@see Planner}): the caller passes the
 * provider, so this is trivially unit-testable with a fake. Best-effort by
 * contract — if the model errors or returns unparseable output, the PRIOR summary
 * is returned unchanged rather than dropping context on the floor.
 */
final class ConversationSummarizer
{
    /**
     * Cap the summary LLM call: a summary is background context, not the answer,
     * so it must never dominate latency or token spend. Reasoning trace off for
     * the same reason (we only consume the JSON), where the provider supports it.
     */
    private const SUMMARY_MAX_TOKENS = 400;

    /**
     * The system prompt now lives in the prompt catalog as
     * {@see ConversationSummaryPrompt} (id `llm.conversation-summary`) instead of
     * an inline heredoc. Production resolves it through discovery; the optional
     * $prompts seam lets tests bind an in-memory catalog without a full scan.
     */
    public function __construct(
        private ?PromptRenderer $renderer = null,
        private ?PromptRepositoryInterface $prompts = null,
    ) {}

    /**
     * @param list<array{role: string, content: string}> $agingTurns oldest → newest
     * @return array{summary: string, active_intent: string, changed: bool}
     *         `changed` is false whenever the PRIOR summary is handed back
     *         unmodified (empty batch or any provider/parse failure) — callers
     *         MUST check it before treating the aging turns as absorbed, or a
     *         transient failure silently drops that slice of the conversation.
     */
    public function summarize(
        LlmProviderInterface $provider,
        string $priorSummary,
        string $priorIntent,
        array $agingTurns,
    ): array {
        $prior = ['summary' => $priorSummary, 'active_intent' => $priorIntent, 'changed' => false];

        if ($agingTurns === []) {
            return $prior;
        }

        $lines = [];
        foreach ($agingTurns as $turn) {
            $role = $turn['role'] === 'user' ? 'User' : 'Assistant';
            $content = trim($turn['content']);
            if ($content === '') {
                continue;
            }
            $lines[] = $role . ': ' . $content;
        }
        if ($lines === []) {
            return $prior;
        }

        $userMessage = "PRIOR SUMMARY:\n" . ($priorSummary !== '' ? $priorSummary : '(none)')
            . "\n\nPRIOR ACTIVE INTENT:\n" . ($priorIntent !== '' ? $priorIntent : '(none)')
            . "\n\nNEW TURNS:\n" . implode("\n", $lines);

        $response = $provider->complete(new LlmRequest(
            systemPrompt: $this->systemPrompt(),
            userMessage: $userMessage,
            history: [],
        ));

        if (!$response->success) {
            return $prior;
        }

        $decoded = (new Planner())->extractJson(trim($response->content));
        if (!is_array($decoded)) {
            return $prior;
        }

        $summary = trim((string) ($decoded['summary'] ?? ''));
        $intent = trim((string) ($decoded['active_intent'] ?? ''));

        // Never let a degenerate empty summary/intent erase real prior context —
        // a missing key and an explicit "" both decode to '' here, so an omitted
        // active_intent can't be told apart from the model's deliberate "no
        // ongoing task" signal. Falling back to the prior value on either is the
        // safe read: the worst case is one stale turn's lag, not silently losing
        // a real in-progress intent to a model that simply left the field out.
        return [
            'summary' => $summary !== '' ? $summary : $priorSummary,
            'active_intent' => $intent !== '' ? $intent : $priorIntent,
            'changed' => true,
        ];
    }

    /** Token budget the caller should cap the summary provider to, if it can. */
    public function maxTokens(): int
    {
        return self::SUMMARY_MAX_TOKENS;
    }

    /**
     * Resolve the summary system prompt from the catalog. Rendering is a no-op
     * substitution here (the prompt has no variables), but routing through the
     * renderer is the canonical consumer path and keeps the text in one
     * addressable place.
     */
    private function systemPrompt(): string
    {
        return ($this->renderer ??= new PromptRenderer())
            ->render(ConversationSummaryPrompt::ID, [], $this->prompts)
            ->system;
    }
}
