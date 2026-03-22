<?php

declare(strict_types=1);

namespace Semitexa\Llm\Attributes;

use Attribute;
use Semitexa\Llm\Policy\AiArgumentPolicy;
use Semitexa\Llm\Policy\AiConfirmationMode;
use Semitexa\Llm\Policy\AiExecutionKind;
use Semitexa\Llm\Policy\AiRiskLevel;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsAiSkill
{
    public readonly AiRiskLevel $resolvedRiskLevel;
    public readonly AiConfirmationMode $resolvedConfirmation;
    public readonly AiArgumentPolicy $resolvedArgumentPolicy;
    public readonly AiExecutionKind $resolvedExecutionKind;

    /**
     * @param list<string> $exposeArguments
     * @param list<string> $requiredArguments
     * @param list<string> $channels
     */
    public function __construct(
        public bool $allowed = true,
        public ?string $summary = null,
        public ?string $useWhen = null,
        public ?string $avoidWhen = null,
        public AiRiskLevel|string $riskLevel = AiRiskLevel::Low,
        public AiConfirmationMode|string $confirmation = AiConfirmationMode::Always,
        public bool $supportsDryRun = false,
        public AiArgumentPolicy|string $argumentPolicy = AiArgumentPolicy::Allowlisted,
        public array $exposeArguments = [],
        public array $requiredArguments = [],
        public AiExecutionKind|string $executionKind = AiExecutionKind::DirectCommand,
        public array $channels = ['console'],
    ) {
        $this->resolvedRiskLevel = $riskLevel instanceof AiRiskLevel
            ? $riskLevel
            : AiRiskLevel::from($riskLevel);

        $this->resolvedConfirmation = $confirmation instanceof AiConfirmationMode
            ? $confirmation
            : AiConfirmationMode::from($confirmation);

        $this->resolvedArgumentPolicy = $argumentPolicy instanceof AiArgumentPolicy
            ? $argumentPolicy
            : AiArgumentPolicy::from($argumentPolicy);

        $this->resolvedExecutionKind = $executionKind instanceof AiExecutionKind
            ? $executionKind
            : AiExecutionKind::from($executionKind);
    }
}
