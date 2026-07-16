<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Model;

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
    ) {}
}
