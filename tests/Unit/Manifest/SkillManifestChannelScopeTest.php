<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Manifest;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Llm\Domain\Model\SkillEntry;
use Semitexa\Llm\Domain\Model\ScopedSkillManifest;
use Semitexa\Llm\Domain\Model\SkillManifest;

/**
 * A manifest handed to a planner is the list of things that planner may propose,
 * so the surface it was narrowed to is part of what the manifest IS.
 *
 * It used to be a fact the caller had to remember separately, and the four callers
 * did not agree — one narrowed to web+ui, one re-implemented a console filter by
 * hand, one leaned on isUi(), and one filtered nothing at all.
 */
final class SkillManifestChannelScopeTest extends TestCase
{
    private function entry(string $name, array $channels): SkillEntry
    {
        return new SkillEntry(
            name: $name,
            sourceCommand: null,
            summary: '',
            useWhen: '',
            avoidWhen: '',
            riskLevel: AiRiskLevel::Low,
            confirmation: AiConfirmationMode::Never,
            supportsDryRun: false,
            argumentPolicy: AiArgumentPolicy::None,
            inputs: [],
            channels: $channels,
            executionKind: AiExecutionKind::DirectCommand,
            skillClass: null,
            icon: null,
            entry: null,
        );
    }

    private function manifest(): SkillManifest
    {
        return new SkillManifest('semitexa.ai-skills/v1', '2026-09-09T00:00:00+00:00', [
            $this->entry('deploy', ['console']),
            $this->entry('open-page', ['ui']),
            $this->entry('ask', ['web', 'ui']),
        ]);
    }

    public function test_a_manifest_nobody_narrowed_cannot_reach_a_planner(): void
    {
        // Unscoped is every skill the tenant owns, console-only ones included:
        // the right answer for tooling and the wrong one for planning. It is
        // now a different TYPE, so the distinction is not a habit any caller
        // has to remember — Planner, PlannerToolSchema and SkillExecutor all
        // take ScopedSkillManifest and nothing else.
        $manifest = $this->manifest();

        $this->assertCount(3, $manifest->skills);
        $this->assertNull($manifest->channels);

        $doors = [
            [\Semitexa\Llm\Application\Service\Planner::class, 'buildSystemPrompt', 0],
            [\Semitexa\Llm\Application\Service\PlannerToolSchema::class, 'declarationsFor', 0],
            [\Semitexa\Llm\Application\Service\SkillExecutor::class, 'execute', 2],
        ];
        foreach ($doors as [$class, $method, $position]) {
            $type = (new \ReflectionMethod($class, $method))->getParameters()[$position]->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type);
            $this->assertSame(
                SkillManifestChannelScopeTest::scopedClass(),
                ltrim((string) $type, '?'),
                $class . '::' . $method . ' would accept an unscoped manifest',
            );
        }
    }

    private static function scopedClass(): string
    {
        return \Semitexa\Llm\Domain\Model\ScopedSkillManifest::class;
    }

    public function test_an_empty_scope_is_refused_rather_than_treated_as_every_channel(): void
    {
        // A manifest scoped to nothing matches nothing, so it is not a narrower
        // manifest — it is a mistake, and almost always a caller that meant to
        // pass a channel.
        $this->expectException(\ValueError::class);

        $this->manifest()->forChannels([]);
    }

    public function test_narrowing_records_the_surface_it_was_narrowed_to(): void
    {
        $scoped = $this->manifest()->forChannels(['web', 'ui']);

        $this->assertSame(['web', 'ui'], $scoped->channels);
        $this->assertSame(
            ['open-page', 'ask'],
            array_map(static fn(SkillEntry $s): string => $s->name, $scoped->skills()),
            'a console-only skill must not reach a web+ui planner',
        );
    }

    public function test_narrowing_to_console_keeps_only_console(): void
    {
        $scoped = $this->manifest()->forChannels(['console']);

        $this->assertSame(['console'], $scoped->channels);
        $this->assertSame(
            ['deploy'],
            array_map(static fn(SkillEntry $s): string => $s->name, $scoped->skills()),
        );
    }

    public function test_the_scope_travels_with_the_serialised_manifest(): void
    {
        // Whoever reads a dumped manifest has to be able to tell which surface it
        // describes; without this the same JSON means two different things.
        $row = $this->manifest()->forChannels(['ui'])->toArray();

        $this->assertSame(['ui'], $row['channels']);
        $this->assertNull($this->manifest()->toArray()['channels']);
    }

    public function test_narrowing_is_idempotent_and_never_widens(): void
    {
        $once = $this->manifest()->forChannels(['web', 'ui']);
        $twice = $once->forChannels(['web', 'ui']);
        $this->assertSame($once->channels, $twice->channels);
        $this->assertCount(count($once->skills()), $twice->skills());

        // Narrowing a narrowed manifest cannot bring a dropped skill back, and
        // narrowing to a surface it never covered is refused rather than
        // silently widening to it.
        $this->expectException(\ValueError::class);
        $once->forChannels(['console']);
    }
}
