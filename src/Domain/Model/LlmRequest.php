<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Model;

final readonly class LlmRequest
{
    /**
     * @param string $systemPrompt
     * @param string $userMessage
     * @param list<array{role: string, content: string}> $history
     * @param list<array{name: string, description: string, parameters: array<string, mixed>}> $tools
     *        native function-calling declarations. Provider-agnostic in shape;
     *        only providers that support tool-calling (currently Gemini) consume
     *        them — others ignore the field entirely, so it is safe to always set.
     *        Empty = plain text/JSON completion (every existing caller's behavior).
     */
    public function __construct(
        public string $systemPrompt,
        public string $userMessage,
        public array $history = [],
        public array $tools = [],
    ) {}
}
