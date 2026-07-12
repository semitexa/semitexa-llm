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
     */
    public function __construct(
        public string $content,
        public bool $success,
        public ?string $error = null,
        public ?int $tokensUsed = null,
        public ?float $latencyMs = null,
        public ?array $toolCall = null,
    ) {}
}
