<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Llm\Attributes\AsAiSkill;
use Semitexa\Llm\Policy\AiConfirmationMode;
use Semitexa\Llm\Policy\AiRiskLevel;
use Semitexa\Llm\Registry\SkillRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SkillRegistryTest extends TestCase
{
    public function test_builds_manifest_from_annotated_class(): void
    {
        $registry = new SkillRegistry();
        $manifest = $registry->buildManifestFromClasses([StubSkillCommand::class]);

        $this->assertSame('semitexa.ai-skills/v1', $manifest->artifact);
        $this->assertCount(1, $manifest->skills);

        $skill = $manifest->skills[0];
        $this->assertSame('stub:command', $skill->name);
        $this->assertSame('stub:command', $skill->sourceCommand);
        $this->assertSame('Stub skill for testing.', $skill->summary);
        $this->assertSame(AiRiskLevel::Low, $skill->riskLevel);
        $this->assertSame(AiConfirmationMode::Never, $skill->confirmation);
        $this->assertFalse($skill->supportsDryRun);
    }

    public function test_excluded_when_allowed_false(): void
    {
        $registry = new SkillRegistry();
        $manifest = $registry->buildManifestFromClasses([DisabledSkillCommand::class]);

        $this->assertCount(0, $manifest->skills);
    }

    public function test_inputs_derived_from_expose_arguments(): void
    {
        $registry = new SkillRegistry();
        $manifest = $registry->buildManifestFromClasses([StubSkillCommand::class]);

        $skill = $manifest->skills[0];
        $this->assertArrayHasKey('verbose', $skill->inputs);
        $this->assertSame('flag', $skill->inputs['verbose']['type']);
        $this->assertFalse($skill->inputs['verbose']['required']);
    }

    public function test_skips_class_without_as_command(): void
    {
        $registry = new SkillRegistry();
        $manifest = $registry->buildManifestFromClasses([NoCommandAttributeClass::class]);

        $this->assertCount(0, $manifest->skills);
    }

    public function test_manifest_skills_sorted_by_name(): void
    {
        $registry = new SkillRegistry();
        $manifest = $registry->buildManifestFromClasses([
            StubSkillCommand::class,
            AnotherStubSkillCommand::class,
        ]);

        $this->assertCount(2, $manifest->skills);
        $this->assertSame('aaa:command', $manifest->skills[0]->name);
        $this->assertSame('stub:command', $manifest->skills[1]->name);
    }
}

// --- Test fixtures ---

#[AsCommand(name: 'stub:command', description: 'A stub command')]
#[AsAiSkill(
    allowed: true,
    summary: 'Stub skill for testing.',
    useWhen: 'Testing.',
    avoidWhen: 'Production.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: 'allowlisted',
    exposeArguments: ['verbose'],
)]
final class StubSkillCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('verbose-extra', null, InputOption::VALUE_NONE, 'Extra verbose flag');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}

#[AsCommand(name: 'stub:disabled', description: 'Disabled stub')]
#[AsAiSkill(allowed: false)]
final class DisabledSkillCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}

#[AsAiSkill(allowed: true, summary: 'No AsCommand')]
final class NoCommandAttributeClass
{
}

#[AsCommand(name: 'aaa:command', description: 'Sorts first')]
#[AsAiSkill(allowed: true, summary: 'First alphabetically.')]
final class AnotherStubSkillCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
