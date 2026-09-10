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

    /** @var list<string> */
    public readonly array $resolvedChannels;

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
        /**
         * The surfaces this skill is exposed on.
         *
         * `array|string` because one env var is often the natural unit: a
         * project turning a skill on for its bot wants
         * `env::CMS_SKILL_CHANNELS::console,web`, not an array whose entries it
         * has to know in advance. A list resolves entry by entry; a string is
         * split on commas after resolving.
         *
         * @var list<string>|string
         */
        public array|string $channels = ['console'],
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
        $this->resolvedChannels = self::resolveChannels($channels);

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

    /**
     * @param list<string>|string $channels
     * @return list<string>
     */
    private static function resolveChannels(array|string $channels): array
    {
        /** @var list<string>|string $resolved */
        $resolved = EnvValueResolver::resolve($channels);

        $parts = is_string($resolved) ? explode(',', $resolved) : $resolved;

        $out = [];
        foreach ($parts as $part) {
            // A list entry can itself resolve to a comma-separated string, so
            // splitting happens after resolution either way.
            foreach (explode(',', (string) $part) as $channel) {
                $channel = trim($channel);
                if ($channel !== '' && !in_array($channel, $out, true)) {
                    $out[] = $channel;
                }
            }
        }

        if ($out === []) {
            // A skill on no surface is not "off" — `allowed: false` is off, and
            // it says so. This is a skill that IS in the manifest and that
            // forChannels() can never return: invisible everywhere, with no
            // signal anywhere. An unset env var with no default lands here, so
            // the silence would be a typo away.
            //
            // SkillRegistry catches this and drops the skill with a logged
            // warning naming the class, which is the right severity: loud
            // enough to diagnose, not a boot failure over one misconfigured
            // skill.
            throw new \ValueError(
                'AsAiSkill channels must resolve to at least one surface; got an empty list. '
                . 'Use allowed: false to turn a skill off, and give an env-driven channels a default.',
            );
        }

        return $out;
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
