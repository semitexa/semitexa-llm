<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Model;

final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        public bool $success,
        public ?string $error = null,
        public ?int $tokensUsed = null,
        public ?float $latencyMs = null,
    ) {}
}
