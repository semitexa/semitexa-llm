<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Planner;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\Planner;
use Semitexa\Llm\Application\Prompt\PlannerJsonPrompt;
use Semitexa\Llm\Application\Prompt\PlannerToolPrompt;
use Semitexa\Llm\Domain\Model\SkillManifest;
use Semitexa\Prompt\Application\Service\PromptRegistry;

/**
 * Byte-identity guard for the Planner -> prompt-catalog migration.
 *
 * The golden fixtures were generated from the pre-migration Planner (its inline
 * heredocs). The catalog-rendered output must match them exactly, with only the
 * volatile "Current date and time: ..." line neutralised to __NOW__ — proving the
 * static prompt text and the variable placements (persona, skills, tail date
 * anchor) are preserved to the byte.
 */
final class PlannerCatalogMigrationTest extends TestCase
{
    private function emptyManifest(): SkillManifest
    {
        return new SkillManifest('semitexa.ai-skills/v1', '2026-01-01T00:00:00+00:00', []);
    }

    private function neutralize(string $prompt): string
    {
        // Matches both the legacy minute-granular anchor ("Current date and
        // time: …") captured in the goldens and the current day-granular one.
        return (string) preg_replace('/^Current date( and time)?:.*$/m', '__NOW__', $prompt);
    }

    private function golden(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    public function testJsonPromptIsByteIdenticalToTheLegacyOutput(): void
    {
        $rendered = (new Planner())->buildSystemPrompt($this->emptyManifest(), '__PERSONA__', new \DateTimeZone('UTC'));

        self::assertSame($this->golden('planner-json.golden.txt'), $this->neutralize($rendered));
    }

    public function testToolPromptIsByteIdenticalToTheLegacyOutput(): void
    {
        $rendered = (new Planner())->buildToolSystemPrompt('__PERSONA__', new \DateTimeZone('UTC'));

        self::assertSame($this->golden('planner-tool.golden.txt'), $this->neutralize($rendered));
    }

    public function testTheTwoPlannerPromptsAreCatalogEntries(): void
    {
        $catalog = (new PromptRegistry())->buildFromClasses([PlannerJsonPrompt::class, PlannerToolPrompt::class]);

        self::assertArrayHasKey('llm.planner.json', $catalog);
        self::assertArrayHasKey('llm.planner.tool', $catalog);

        $jsonVars = $catalog['llm.planner.json']->variableNames();
        sort($jsonVars);
        self::assertSame(['nowLine', 'persona', 'skills'], $jsonVars);

        $toolVars = $catalog['llm.planner.tool']->variableNames();
        sort($toolVars);
        self::assertSame(['nowLine', 'persona'], $toolVars);
    }
}
