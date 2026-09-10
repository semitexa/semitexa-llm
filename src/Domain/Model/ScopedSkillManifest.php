<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Model;

/**
 * A manifest that has been narrowed to the surfaces it is for.
 *
 * The distinction is not bookkeeping. What a planner is shown is what it may
 * propose, so an unscoped list lets it propose a skill the surface cannot run —
 * and the CLI assistant did exactly that: it planned off every channel and then
 * SkillExecutor, defaulting to 'console', refused what the planner had just
 * suggested. Nothing complained, because nothing could: both were holding the
 * same type.
 *
 * So the narrowing became a type. Every planner-facing signature takes this,
 * {@see SkillManifest::forChannels()} is the only way to produce one, and the
 * filtering happens on the way in — a scoped manifest that was never scoped
 * cannot be fabricated.
 *
 * Composition rather than extending {@see SkillManifest}: that class is final
 * readonly, and un-finaling a domain model to express a subtype is a worse
 * trade than delegating the handful of reads a planner actually performs.
 */
final readonly class ScopedSkillManifest
{
    /** @var list<string> */
    public array $channels;

    private SkillManifest $manifest;

    /**
     * @param list<string> $channels
     *
     * @throws \ValueError when no surface is named — an empty scope is not a
     *         narrower manifest, it is a manifest nothing can ever match, and
     *         the caller almost certainly meant to pass a channel.
     */
    public function __construct(SkillManifest $manifest, array $channels)
    {
        $channels = array_values(array_unique(array_filter(
            array_map(static fn(string $c): string => trim($c), $channels),
            static fn(string $c): bool => $c !== '',
        )));

        if ($channels === []) {
            throw new \ValueError('A scoped manifest needs at least one channel; got none.');
        }

        $this->channels = $channels;
        $this->manifest = new SkillManifest(
            $manifest->artifact,
            $manifest->generatedAt,
            array_values(array_filter(
                $manifest->skills,
                static fn(SkillEntry $s): bool => array_intersect($s->channels, $channels) !== [],
            )),
            $channels,
        );
    }

    /** @return list<SkillEntry> */
    public function skills(): array
    {
        return $this->manifest->skills;
    }

    public function isEmpty(): bool
    {
        return $this->manifest->skills === [];
    }

    public function findSkill(string $name): ?SkillEntry
    {
        return $this->manifest->findSkill($name);
    }

    public function toCompactPrompt(): string
    {
        return $this->manifest->toCompactPrompt();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->manifest->toArray();
    }

    /**
     * Narrow further. Never widens: the result is the intersection.
     *
     * The incoming names are normalised the same way the constructor does them,
     * so ' Web ' intersects with 'web' rather than matching nothing and failing
     * as an empty scope.
     *
     * @param list<string> $channels
     */
    public function forChannels(array $channels): self
    {
        $wanted = array_map(static fn(string $c): string => trim($c), $channels);

        return new self($this->manifest, array_values(array_intersect($this->channels, $wanted)));
    }
}
