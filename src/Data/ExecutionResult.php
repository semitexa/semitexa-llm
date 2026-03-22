<?php

declare(strict_types=1);

namespace Semitexa\Llm\Data;

final readonly class ExecutionResult
{
    public function __construct(
        public string $skill,
        public int $exitCode,
        public string $output,
        public bool $approved,
        public ?string $error = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }

    public function toArray(): array
    {
        return [
            'skill' => $this->skill,
            'exit_code' => $this->exitCode,
            'output' => $this->output,
            'approved' => $this->approved,
            'error' => $this->error,
        ];
    }
}
