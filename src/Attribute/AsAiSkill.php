<?php

declare(strict_types=1);

namespace Semitexa\Llm\Attribute;

use Attribute;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsAiSkill
{
    public readonly bool $resolvedAllowed;
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
        public bool|string $allowed = true,
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
        /**
         * Skill name for non-command skills (classes without `#[AsCommand]` that
         * implement {@see \Semitexa\Llm\Domain\Contract\InvocableSkillInterface}).
         * Command skills leave this null and take their name from the command.
         */
        public ?string $name = null,
        /**
         * UI-skill: an icon (Lucide name or glyph) shown in Focus / launchers.
         * Presence of the `'ui'` channel marks the skill as raising a dialog.
         */
        public ?string $icon = null,
        /**
         * UI-skill: the entry route/path whose GET response renders inside the
         * dialog surface (e.g. '/os/app/notes'). Hosted by the Focus zone.
         */
        public ?string $entry = null,
        /**
         * Per-argument guidance shown to the planner (argName => one-line hint).
         * Command skills inherit descriptions from their console option
         * definitions automatically; invocable skills have no such source, so
         * without hints their inputs render bare and the model guesses what to
         * put in them (e.g. a whole sentence where a short name was expected).
         *
         * @var array<string, string>
         */
        public array $argumentHints = [],
    ) {
        $this->resolvedAllowed = $this->resolveAllowed($allowed);

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

    private function resolveAllowed(bool|string $allowed): bool
    {
        if (is_bool($allowed)) {
            return $allowed;
        }

        $resolved = EnvValueResolver::resolve($allowed);
        $normalized = strtolower(trim((string) $resolved));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => throw new \ValueError(sprintf(
                'AsAiSkill allowed must resolve to a boolean-like value, got "%s".',
                (string) $resolved,
            )),
        };
    }
}
