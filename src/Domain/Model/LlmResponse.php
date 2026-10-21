<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Model;

use Semitexa\Llm\Domain\Enum\LlmFailure;

final readonly class LlmResponse
{
    /**
     * @param array{name: string, arguments: array<string, mixed>}|null $toolCall
     *        the structured function call a tool-calling provider (Gemini) chose,
     *        when the request carried tools and the model called one. Null on the
     *        text path (no tools, or the model answered with text instead of a
     *        call) — callers fall back to parsing {@see $content}.
     * @param int|null $promptTokens input-side token count for the whole prompt
     *        (system + tools + history + message), when the provider reports it.
     * @param int|null $cachedTokens how many of {@see $promptTokens} were served
     *        from the provider's context cache (implicit or explicit) and billed
     *        at the discounted rate. 0/null on a cache miss — watching this field
     *        is how callers verify their prompts actually hit the cache.
     * @param LlmFailure|null $failure why a failed call failed, when the provider
     *        can tell; null on success, and from a provider that does not say.
     * @param string|null $servedBy `<provider>:<model>` that produced this
     *        answer, set by a FailoverProvider: which of its models answered.
     */
    public function __construct(
        public string $content,
        public bool $success,
        public ?string $error = null,
        public ?int $tokensUsed = null,
        public ?float $latencyMs = null,
        public ?array $toolCall = null,
        public ?int $promptTokens = null,
        public ?int $cachedTokens = null,
        public ?LlmFailure $failure = null,
        public ?string $servedBy = null,
    ) {}

    public function withServedBy(string $servedBy): self
    {
        return new self(
            $this->content,
            $this->success,
            $this->error,
            $this->tokensUsed,
            $this->latencyMs,
            $this->toolCall,
            $this->promptTokens,
            $this->cachedTokens,
            $this->failure,
            $servedBy,
        );
    }

    /** A failure as a provider reports one. */
    public static function failed(string $error, LlmFailure $failure, ?float $latencyMs = 0.0): self
    {
        return new self(content: '', success: false, error: $error, latencyMs: $latencyMs, failure: $failure);
    }
}
