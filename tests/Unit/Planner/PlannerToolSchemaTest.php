<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Planner;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\PlannerToolSchema;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Llm\Domain\Enum\PlannerResponseType;
use Semitexa\Llm\Domain\Model\SkillEntry;
use Semitexa\Llm\Domain\Model\SkillManifest;

final class PlannerToolSchemaTest extends TestCase
{
    private PlannerToolSchema $schema;
    private SkillManifest $manifest;

    protected function setUp(): void
    {
        $this->schema = new PlannerToolSchema();
        $this->manifest = new SkillManifest('test', 'now', [
            // Colon in the name — invalid as a Gemini function name, must sanitize.
            $this->skill('os:design-skin', inputs: [
                'prompt' => ['type' => 'string', 'required' => true, 'description' => 'The mood.'],
            ]),
            // No inputs — must still produce a valid (bare object) parameters schema.
            $this->skill('list-tasks', inputs: []),
        ]);
    }

    public function test_declarations_sanitize_names_and_append_meta_tools(): void
    {
        $declarations = $this->schema->declarationsFor($this->manifest);
        $names = array_column($declarations, 'name');

        // Colon sanitized to underscore; plain name untouched.
        self::assertContains('os_design-skin', $names);
        self::assertContains('list-tasks', $names);

        // The three meta tools are always present.
        self::assertContains(PlannerToolSchema::FINAL_ANSWER, $names);
        self::assertContains(PlannerToolSchema::ASK_USER, $names);
        self::assertContains(PlannerToolSchema::REFUSE, $names);

        self::assertCount(5, $declarations);
    }

    public function test_input_schema_carries_typed_required_parameter(): void
    {
        $declarations = $this->schema->declarationsFor($this->manifest);
        $designSkin = $this->declarationNamed($declarations, 'os_design-skin');

        self::assertSame('OBJECT', $designSkin['parameters']['type']);
        self::assertSame('STRING', $designSkin['parameters']['properties']['prompt']['type']);
        self::assertSame(['prompt'], $designSkin['parameters']['required']);
    }

    public function test_no_input_skill_gets_a_bare_object_schema(): void
    {
        $declarations = $this->schema->declarationsFor($this->manifest);
        $listTasks = $this->declarationNamed($declarations, 'list-tasks');

        // No empty `properties` map (Gemini rejects it), just a bare object type.
        self::assertSame(['type' => 'OBJECT'], $listTasks['parameters']);
    }

    public function test_maps_sanitized_tool_call_back_to_canonical_skill(): void
    {
        $response = $this->schema->mapToolCall(
            ['name' => 'os_design-skin', 'arguments' => ['prompt' => 'calm ocean']],
            $this->manifest,
        );

        self::assertSame(PlannerResponseType::ProposeSkill, $response->type);
        self::assertSame('os:design-skin', $response->skill); // canonical, not sanitized
        self::assertSame(['prompt' => 'calm ocean'], $response->arguments);
    }

    public function test_meta_tool_calls_map_to_answer_ask_refuse(): void
    {
        $answer = $this->schema->mapToolCall(
            ['name' => PlannerToolSchema::FINAL_ANSWER, 'arguments' => ['message' => 'Done.']],
            $this->manifest,
        );
        self::assertSame(PlannerResponseType::Answer, $answer->type);
        self::assertSame('Done.', $answer->message);

        $ask = $this->schema->mapToolCall(
            ['name' => PlannerToolSchema::ASK_USER, 'arguments' => ['message' => 'Which one?']],
            $this->manifest,
        );
        self::assertSame(PlannerResponseType::Ask, $ask->type);

        $refuse = $this->schema->mapToolCall(
            ['name' => PlannerToolSchema::REFUSE, 'arguments' => ['message' => 'No.']],
            $this->manifest,
        );
        self::assertSame(PlannerResponseType::Refuse, $refuse->type);
    }

    public function test_unknown_tool_name_refuses_rather_than_guessing(): void
    {
        $response = $this->schema->mapToolCall(
            ['name' => 'not_a_real_skill', 'arguments' => []],
            $this->manifest,
        );

        self::assertSame(PlannerResponseType::Refuse, $response->type);
    }

    /**
     * @param array<string, array{type: string, required: bool, description: string}> $inputs
     */
    private function skill(string $name, array $inputs): SkillEntry
    {
        return new SkillEntry(
            name: $name,
            sourceCommand: $name,
            summary: 'Summary of ' . $name,
            useWhen: 'When testing.',
            avoidWhen: 'Never.',
            riskLevel: AiRiskLevel::Low,
            confirmation: AiConfirmationMode::Never,
            supportsDryRun: false,
            argumentPolicy: AiArgumentPolicy::Allowlisted,
            inputs: $inputs,
            channels: ['web'],
            executionKind: AiExecutionKind::DirectCommand,
        );
    }

    /**
     * @param list<array{name: string, description: string, parameters: array<string, mixed>}> $declarations
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    private function declarationNamed(array $declarations, string $name): array
    {
        foreach ($declarations as $declaration) {
            if ($declaration['name'] === $name) {
                return $declaration;
            }
        }

        self::fail("No declaration named {$name}.");
    }
}
