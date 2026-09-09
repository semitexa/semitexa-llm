<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Manifest;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Llm\Domain\Model\SkillEntry;
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

    public function test_a_manifest_nobody_narrowed_says_so(): void
    {
        $manifest = $this->manifest();

        // Unscoped is every skill the tenant owns, console-only ones included. That
        // is the right answer for tooling and the wrong one for planning.
        $this->assertFalse($manifest->isScoped());
        $this->assertNull($manifest->channels);
        $this->assertCount(3, $manifest->skills);
    }

    public function test_narrowing_records_the_surface_it_was_narrowed_to(): void
    {
        $scoped = $this->manifest()->forChannels(['web', 'ui']);

        $this->assertTrue($scoped->isScoped());
        $this->assertSame(['web', 'ui'], $scoped->channels);
        $this->assertSame(
            ['open-page', 'ask'],
            array_map(static fn(SkillEntry $s): string => $s->name, $scoped->skills),
            'a console-only skill must not reach a web+ui planner',
        );
    }

    public function test_narrowing_to_console_keeps_only_console(): void
    {
        $scoped = $this->manifest()->forChannels(['console']);

        $this->assertSame(['console'], $scoped->channels);
        $this->assertSame(
            ['deploy'],
            array_map(static fn(SkillEntry $s): string => $s->name, $scoped->skills),
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
        $this->assertCount(count($once->skills), $twice->skills);

        // Narrowing a narrowed manifest cannot bring a dropped skill back.
        $narrower = $once->forChannels(['console']);
        $this->assertSame([], $narrower->skills);
    }
}
