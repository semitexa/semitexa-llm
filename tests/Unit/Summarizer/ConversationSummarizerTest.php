<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Summarizer;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\ConversationSummarizer;
use Semitexa\Llm\Application\Prompt\ConversationSummaryPrompt;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

final class ConversationSummarizerTest extends TestCase
{
    /**
     * Byte-for-byte guard: the catalog prompt must equal the exact text that
     * used to live in the ConversationSummarizer::SYSTEM_PROMPT const, so the
     * migration is behaviour-preserving (the flexible-heredoc indentation in the
     * new class is stripped correctly).
     */
    public function testCatalogPromptTextIsUnchangedFromTheOriginalConst(): void
    {
        $original = <<<'PROMPT'
You maintain a running summary of an ongoing conversation between a user and their assistant OS.
You are given the PRIOR SUMMARY, the PRIOR ACTIVE INTENT, and a batch of NEW TURNS that are aging out of the verbatim window.
Produce an UPDATED summary that folds the new turns into the prior one.

Rules:
- Keep it concise (at most ~120 words). Preserve durable facts, names, numbers, decisions, preferences, and any unfinished task.
- Drop small talk and anything already superseded.
- "active_intent": ONE short sentence naming what the user is currently trying to accomplish across turns, or "" if there is no ongoing task.
- Write the summary in the same language the conversation uses.
- Respond with valid JSON only, exactly: {"summary":"...","active_intent":"..."}
- No markdown, no code fences, no extra text.
PROMPT;

        self::assertSame($original, rtrim((new PromptRegistry())->buildFromClasses([ConversationSummaryPrompt::class])['llm.conversation-summary']->system));
    }

    public function testSummarizeSendsTheCatalogSystemPromptToTheProvider(): void
    {
        $provider = new CapturingProvider(new LlmResponse('{"summary":"s","active_intent":"i"}', true));
        $summarizer = new ConversationSummarizer(new PromptRenderer(), $this->catalog());

        $summarizer->summarize($provider, 'prior', 'intent', [
            ['role' => 'user', 'content' => 'hello'],
        ]);

        self::assertNotNull($provider->lastRequest);
        self::assertSame(rtrim((new PromptRegistry())->buildFromClasses([ConversationSummaryPrompt::class])['llm.conversation-summary']->system), $provider->lastRequest->systemPrompt);
        self::assertStringContainsString('NEW TURNS:', $provider->lastRequest->userMessage);
    }

    public function testSummarizeParsesProviderJson(): void
    {
        $provider = new CapturingProvider(new LlmResponse('{"summary":"folded","active_intent":"ship it"}', true));
        $summarizer = new ConversationSummarizer(new PromptRenderer(), $this->catalog());

        $result = $summarizer->summarize($provider, 'prior', 'intent', [
            ['role' => 'user', 'content' => 'do the thing'],
        ]);

        self::assertSame('folded', $result['summary']);
        self::assertSame('ship it', $result['active_intent']);
        self::assertTrue($result['changed']);
    }

    public function testEmptyBatchReturnsPriorWithoutCallingProvider(): void
    {
        $provider = new CapturingProvider(new LlmResponse('{}', true));
        $summarizer = new ConversationSummarizer(new PromptRenderer(), $this->catalog());

        $result = $summarizer->summarize($provider, 'prior', 'intent', []);

        self::assertNull($provider->lastRequest);
        self::assertFalse($result['changed']);
        self::assertSame('prior', $result['summary']);
    }

    private function catalog(): PromptRepositoryInterface
    {
        $templates = (new PromptRegistry())->buildFromClasses([ConversationSummaryPrompt::class]);

        return new class($templates) implements PromptRepositoryInterface {
            /** @param array<string, PromptTemplate> $templates */
            public function __construct(private array $templates) {}

            public function get(string $id): PromptTemplate
            {
                return $this->templates[$id];
            }

            public function tryGet(string $id): ?PromptTemplate
            {
                return $this->templates[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return isset($this->templates[$id]);
            }

            public function all(): array
            {
                return array_values($this->templates);
            }
        };
    }
}

final class CapturingProvider implements LlmProviderInterface
{
    public ?LlmRequest $lastRequest = null;

    public function __construct(private LlmResponse $response) {}

    public function name(): string
    {
        return 'capturing';
    }

    public function baseUrl(): string
    {
        return '';
    }

    public function model(): string
    {
        return 'fake';
    }

    public function healthCheck(): bool
    {
        return true;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->lastRequest = $request;

        return $this->response;
    }
}
