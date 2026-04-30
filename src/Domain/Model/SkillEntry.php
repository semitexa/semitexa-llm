<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Model;

use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

final readonly class SkillEntry
{
    /**
     * @param string $name
     * @param string $sourceCommand
     * @param string $summary
     * @param string $useWhen
     * @param string $avoidWhen
     * @param AiRiskLevel $riskLevel
     * @param AiConfirmationMode $confirmation
     * @param bool $supportsDryRun
     * @param AiArgumentPolicy $argumentPolicy
     * @param array<string, array{type: string, required: bool, description: string}> $inputs
     * @param list<string> $channels
     * @param AiExecutionKind $executionKind
     */
    public function __construct(
        public string $name,
        public string $sourceCommand,
        public string $summary,
        public string $useWhen,
        public string $avoidWhen,
        public AiRiskLevel $riskLevel,
        public AiConfirmationMode $confirmation,
        public bool $supportsDryRun,
        public AiArgumentPolicy $argumentPolicy,
        public array $inputs,
        public array $channels,
        public AiExecutionKind $executionKind,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'source_command' => $this->sourceCommand,
            'summary' => $this->summary,
            'use_when' => $this->useWhen,
            'avoid_when' => $this->avoidWhen,
            'risk_level' => $this->riskLevel->value,
            'confirmation' => $this->confirmation->value,
            'supports_dry_run' => $this->supportsDryRun,
            'argument_policy' => $this->argumentPolicy->value,
            'inputs' => $this->inputs,
            'channels' => $this->channels,
            'execution_kind' => $this->executionKind->value,
        ];
    }
}
