<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Contract;

/**
 * A non-console Skill: a class carrying `#[AsAiSkill(name: ..., channels: [...])]`
 * WITHOUT `#[AsCommand]`, executed directly by {@see \Semitexa\Llm\Application\Service\SkillExecutor}
 * instead of through the Symfony console.
 *
 * This is the seam that lifts the historical `#[AsCommand]`-only constraint
 * (concept §9.1): web/service capabilities become first-class Skills the planner
 * can propose and the loop can run on a non-`console` channel.
 */
interface InvocableSkillInterface
{
    /**
     * Run the skill and return its textual output (the Observe payload).
     * Throw to signal failure — the executor maps it to a non-zero exit code.
     *
     * @param array<string, scalar|null> $arguments validated against the skill's argument policy
     */
    public function invoke(array $arguments): string;
}
