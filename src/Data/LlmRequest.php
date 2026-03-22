<?php

declare(strict_types=1);

namespace Semitexa\Llm\Data;

final readonly class LlmRequest
{
    /**
     * @param string $systemPrompt
     * @param string $userMessage
     * @param list<array{role: string, content: string}> $history
     */
    public function __construct(
        public string $systemPrompt,
        public string $userMessage,
        public array $history = [],
    ) {}
}
