<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Llm\Application\Prompt\PlannerJsonPrompt;
use Semitexa\Llm\Application\Prompt\PlannerToolPrompt;
use Semitexa\Llm\Domain\Model\LlmResponse;
use Semitexa\Llm\Domain\Model\PlannerResponse;
use Semitexa\Llm\Domain\Enum\PlannerResponseType;
use Semitexa\Llm\Domain\Model\SkillManifest;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

final class Planner
{
    private ?PromptTemplate $jsonTemplate = null;
    private ?PromptTemplate $toolTemplate = null;

    /**
     * The planner's two system prompts live in the prompt catalog as
     * {@see PlannerJsonPrompt} and {@see PlannerToolPrompt}. The renderer binds
     * the dynamic parts (persona, skills, date anchor). $renderer is optional so
     * every existing `new Planner()` call site keeps working; it defaults to a
     * plain renderer, and the templates are resolved from their owning classes
     * directly (no global discovery) so rendering is deterministic everywhere.
     */
    public function __construct(private ?PromptRenderer $renderer = null) {}
    /**
     * @param string|null $persona identity/role framing supplied by the caller
     *        (e.g. Semitexa OS injects who the assistant is and who it serves).
     *        When null, a generic framework-assistant framing is used.
     * @param \DateTimeZone|null $timezone the USER's wall-clock timezone for the
     *        date anchor. The server usually runs in UTC, so around midnight a
     *        server-time anchor makes the model resolve "tomorrow"/"завтра" to
     *        the wrong day. Null keeps the server default (legacy behavior).
     */
    public function buildSystemPrompt(SkillManifest $manifest, ?string $persona = null, ?\DateTimeZone $timezone = null): string
    {
        $persona ??= 'You are a Semitexa framework assistant. Your job is to interpret operator requests and map them to available framework skills.';

        return $this->renderer()->renderTemplate(
            $this->jsonTemplate ??= $this->template(PlannerJsonPrompt::class, PlannerJsonPrompt::ID),
            [
                'persona' => $persona,
                'skills' => $manifest->toCompactPrompt(),
                'now_line' => $this->nowLine($timezone),
            ],
        )->system;
    }

    /**
     * The system prompt for the native function-calling path (Gemini). Unlike
     * {@see buildSystemPrompt()}, it carries NO skills list and NO JSON-format
     * block: the skills are supplied as tool declarations, and the model replies
     * by calling exactly one tool (a skill, or `final_answer`/`ask_user`/
     * `refuse_request`). Keeping the JSON instructions here would fight the tool
     * schema and coax the model back into emitting text.
     *
     * @param \DateTimeZone|null $timezone the USER's wall-clock timezone for the
     *        date anchor (see {@see buildSystemPrompt()} for why this matters).
     */
    public function buildToolSystemPrompt(?string $persona = null, ?\DateTimeZone $timezone = null): string
    {
        $persona ??= 'You are a Semitexa framework assistant. Your job is to interpret operator requests and act by calling the available tools.';

        return $this->renderer()->renderTemplate(
            $this->toolTemplate ??= $this->template(PlannerToolPrompt::class, PlannerToolPrompt::ID),
            [
                'persona' => $persona,
                'now_line' => $this->nowLine($timezone),
            ],
        )->system;
    }

    /**
     * The absolute time anchor bound into both planner prompts as `{{ now_line }}`.
     *
     * Give the model an absolute time anchor so it can resolve relative dates
     * ("today", "tomorrow", "next Friday", "завтра") into concrete values instead
     * of passing language-specific phrases downstream skills can't parse. Both
     * catalog prompts keep this at the very END on purpose (see their docblocks):
     * it changes every minute, and an Ollama runtime caches the KV of the longest
     * matching prompt PREFIX, so a volatile tail preserves the static prefix cache.
     */
    private function nowLine(?\DateTimeZone $timezone): string
    {
        $now = new \DateTimeImmutable('now', $timezone);

        return 'Current date and time: ' . $now->format('l, j F Y, H:i')
            . ' (' . $now->format('T') . ', ISO ' . $now->format('Y-m-d H:i') . ').'
            . ' Resolve any relative date the user gives ("today", "tomorrow", "next Friday", "завтра", etc.) against this into an absolute date before using it.';
    }

    private function renderer(): PromptRenderer
    {
        return $this->renderer ??= new PromptRenderer();
    }

    /**
     * Resolve a catalog prompt straight from its owning class — no global
     * discovery — so the planner's own prompts render deterministically in every
     * context (booted app, CLI, unit test).
     */
    private function template(string $class, string $id): PromptTemplate
    {
        return (new PromptRegistry())->buildFromClasses([$class])[$id];
    }

    /**
     * @param SkillManifest|null $manifest when given, an unrecognized `type` that
     *        actually names a manifest skill is salvaged into a skill proposal —
     *        smaller models routinely emit `{"type":"remember",...}` instead of
     *        `{"type":"propose_skill","skill":"remember",...}`, and refusing such
     *        a reply throws away a perfectly routable intent.
     */
    public function parseResponse(LlmResponse $response, string $rawUserMessage = '', ?SkillManifest $manifest = null): PlannerResponse
    {
        if (!$response->success) {
            return new PlannerResponse(
                type: PlannerResponseType::Refuse,
                reason: 'Provider error',
                message: 'The assistant is currently unavailable: ' . ($response->error ?? 'unknown error'),
            );
        }

        $content = trim($response->content);
        $decoded = $this->extractJson($content);

        if (!is_array($decoded) || !isset($decoded['type'])) {
            return new PlannerResponse(
                type: PlannerResponseType::Answer,
                reason: 'Raw text response (JSON extraction failed)',
                jsonExtractionFailed: true,
                message: $content !== '' ? $content : 'No response from assistant.',
            );
        }

        $type = PlannerResponseType::tryFrom((string) $decoded['type']);
        if ($type === null) {
            $salvaged = $this->salvageSkillProposal($decoded, $manifest);
            if ($salvaged !== null) {
                return $salvaged;
            }

            return new PlannerResponse(
                type: PlannerResponseType::Refuse,
                reason: 'Unrecognized response type: ' . $decoded['type'],
                message: 'The assistant returned an unrecognized response type.',
            );
        }

        return new PlannerResponse(
            type: $type,
            skill: isset($decoded['skill']) ? (string) $decoded['skill'] : null,
            arguments: is_array($decoded['arguments'] ?? null) ? $decoded['arguments'] : [],
            reason: isset($decoded['reason']) ? (string) $decoded['reason'] : '',
            confidence: isset($decoded['confidence']) ? (float) $decoded['confidence'] : null,
            message: isset($decoded['message']) ? (string) $decoded['message'] : null,
            steps: $this->parseSteps($decoded['steps'] ?? null),
        );
    }

    /**
     * Model-drift salvage: the reply's `type` is not one of ours, but if it (or
     * an explicit `skill` field next to it) names a skill that EXISTS in the
     * manifest, the model clearly meant a proposal — route it as one. Without a
     * manifest to check against, nothing is coerced (no false positives), which
     * also keeps every legacy `parseResponse()` call byte-identical in behavior.
     *
     * @param array<string, mixed> $decoded
     */
    private function salvageSkillProposal(array $decoded, ?SkillManifest $manifest): ?PlannerResponse
    {
        if ($manifest === null) {
            return null;
        }

        $skillField = trim((string) ($decoded['skill'] ?? ''));
        $candidate = $skillField !== '' ? $skillField : trim((string) $decoded['type']);
        if ($candidate === '' || $manifest->findSkill($candidate) === null) {
            return null;
        }

        return new PlannerResponse(
            type: PlannerResponseType::ProposeSkill,
            skill: $candidate,
            arguments: is_array($decoded['arguments'] ?? null) ? $decoded['arguments'] : [],
            reason: 'Salvaged: model answered with skill name "' . $candidate . '" as the response type.',
            confidence: isset($decoded['confidence']) ? (float) $decoded['confidence'] : null,
            message: isset($decoded['message']) ? (string) $decoded['message'] : null,
        );
    }

    /**
     * Normalise a raw `steps` array into a clean ordered skill chain.
     *
     * @return list<array{skill: string, arguments: array<string, mixed>}>
     */
    private function parseSteps(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $steps = [];
        foreach ($raw as $step) {
            if (!is_array($step) || !isset($step['skill'])) {
                continue;
            }

            $steps[] = [
                'skill' => (string) $step['skill'],
                'arguments' => is_array($step['arguments'] ?? null) ? $step['arguments'] : [],
            ];
        }

        return $steps;
    }

    /**
     * Extract JSON from LLM output that may contain markdown fences, preamble text, or trailing content.
     *
     * @return array<string, mixed>|null
     */
    public function extractJson(string $raw): ?array
    {
        // 1. Try direct decode first
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 2. Strip markdown code fences (```json ... ``` or ``` ... ```)
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $raw, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 3. Find the first balanced { ... } block without regex backtracking.
        $jsonBlock = $this->extractBalancedJsonBlock($raw);
        if ($jsonBlock !== null) {
            $decoded = json_decode($jsonBlock, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            // 4. Try fixing common issues: trailing commas before } or ]
            $fixed = preg_replace('/,\s*([}\]])/', '$1', $jsonBlock);
            if ($fixed !== null) {
                $decoded = json_decode($fixed, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    private function extractBalancedJsonBlock(string $raw): ?string
    {
        $start = strpos($raw, '{');
        while ($start !== false) {
            $depth = 0;
            $inString = false;
            $escape = false;

            $length = strlen($raw);
            for ($i = $start; $i < $length; $i++) {
                $char = $raw[$i];

                if ($inString) {
                    if ($escape) {
                        $escape = false;
                        continue;
                    }

                    if ($char === '\\') {
                        $escape = true;
                        continue;
                    }

                    if ($char === '"') {
                        $inString = false;
                    }
                    continue;
                }

                if ($char === '"') {
                    $inString = true;
                    continue;
                }

                if ($char === '{') {
                    $depth++;
                    continue;
                }

                if ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($raw, $start, $i - $start + 1);
                    }
                }
            }

            $start = strpos($raw, '{', $start + 1);
        }

        return null;
    }
}
