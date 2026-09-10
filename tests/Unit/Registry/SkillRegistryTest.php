<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Llm\Application\Service\SkillRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SkillRegistryTest extends TestCase
{
    /** Local rather than borrowed: a test double is not worth a cross-package dependency. */
    private static function capturingLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $warnings = [];

            public function error(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void { $this->warnings[] = [$message, $context]; }
            public function info(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        };
    }

    public function test_a_ui_skill_that_cannot_run_may_not_claim_a_channel_that_runs(): void
    {
        // It has no invoke(): a UI skill opens a window. Declaring 'console'
        // alongside 'ui' put it in the console manifest, where the planner could
        // propose it and SkillExecutor would then fail to invoke what does not
        // implement the interface — a proposal the executor refuses, which is
        // exactly what the scoped manifest exists to prevent.
        $logger = self::capturingLogger();
        StaticLoggerBridge::set($logger);
        try {
            $manifest = (new SkillRegistry())->buildManifestFromClasses([UiSkillOnExecutableChannel::class]);
        } finally {
            StaticLoggerBridge::reset();
        }

        $this->assertSame([], $manifest->skills, 'a skill nothing can run must not reach a manifest');
        $this->assertStringContainsString(
            'UiSkillOnExecutableChannel',
            json_encode($logger->warnings, JSON_UNESCAPED_SLASHES),
            'dropped without a trace is the failure this drop exists to avoid',
        );
    }

    public function test_channels_can_come_from_one_env_var(): void
    {
        // A project turning a skill on for its bot wants to set one variable,
        // not to know the array's entries in advance.
        putenv('TEST_LLM_SKILL_CHANNELS=console,telegram');
        try {
            $entry = (new SkillRegistry())->buildManifestFromClasses([EnvChannelledSkill::class])->findSkill('env-channelled');
        } finally {
            putenv('TEST_LLM_SKILL_CHANNELS');
        }

        $this->assertNotNull($entry);
        $this->assertSame(['console', 'telegram'], $entry->channels);
    }

    public function test_an_unset_env_var_falls_back_to_the_declared_default(): void
    {
        putenv('TEST_LLM_SKILL_CHANNELS');
        $entry = (new SkillRegistry())->buildManifestFromClasses([EnvChannelledSkill::class])->findSkill('env-channelled');

        $this->assertNotNull($entry);
        $this->assertSame(['console', 'web'], $entry->channels);
    }

    public function test_a_single_list_entry_may_resolve_and_empties_drop_out(): void
    {
        // The entry resolves to nothing here, which must not leave a blank
        // channel in the list — a channel nobody can name is a channel nothing
        // matches.
        putenv('TEST_LLM_SKILL_EXTRA_CHANNEL');
        $entry = (new SkillRegistry())->buildManifestFromClasses([EnvChannelledEntrySkill::class])->findSkill('env-channelled-entry');
        $this->assertNotNull($entry);
        $this->assertSame(['console'], $entry->channels);

        putenv('TEST_LLM_SKILL_EXTRA_CHANNEL=telegram');
        try {
            $entry = (new SkillRegistry())->buildManifestFromClasses([EnvChannelledEntrySkill::class])->findSkill('env-channelled-entry');
        } finally {
            putenv('TEST_LLM_SKILL_EXTRA_CHANNEL');
        }
        $this->assertNotNull($entry);
        $this->assertSame(['console', 'telegram'], $entry->channels);
    }

    public function test_a_skill_that_resolves_to_no_surface_is_dropped_and_logged(): void
    {
        // Not "off" — allowed: false is off and says so. This is a skill that
        // would sit in the manifest and that forChannels() could never return:
        // invisible everywhere, with no signal anywhere. An unset env var with
        // no default is one typo away from it.
        putenv('TEST_LLM_SKILL_MISSING_CHANNELS');

        $logger = self::capturingLogger();
        StaticLoggerBridge::set($logger);
        try {
            $manifest = (new SkillRegistry())->buildManifestFromClasses([NoSurfaceSkill::class]);
        } finally {
            StaticLoggerBridge::reset();
        }

        $this->assertSame([], $manifest->skills);
        $this->assertNotSame([], $logger->warnings, 'a skill dropped for having no surface must leave a trace');
        $this->assertStringContainsString('NoSurfaceSkill', json_encode($logger->warnings, JSON_UNESCAPED_SLASHES));
    }

    public function test_a_ui_skill_can_declare_inputs_without_a_command_to_take_them_from(): void
    {
        // A UI skill has no #[AsCommand], so there is no console definition to read
        // option metadata from. Without declared inputs it can only ever open at
        // nothing, which is what made "open the pricing page" impossible from chat.
        $registry = new SkillRegistry();
        $manifest = $registry->buildManifestFromClasses([UiSkillWithArguments::class]);

        $entry = $manifest->findSkill('Content');
        $this->assertNotNull($entry);
        $this->assertTrue($entry->isUi());
        $this->assertSame(['ui'], $entry->channels);
        $this->assertSame('/os/app/cms', $entry->entry);

        $this->assertArrayHasKey('ref', $entry->inputs);
        $this->assertSame('string', $entry->inputs['ref']['type']);
        $this->assertSame('Which page to open.', $entry->inputs['ref']['description']);
    }

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

    public function test_invocable_skill_argument_hints_become_described_string_inputs(): void
    {
        $registry = new SkillRegistry();
        $manifest = $registry->buildManifestFromClasses([HintedInvocableSkill::class]);

        $this->assertCount(1, $manifest->skills);
        $skill = $manifest->skills[0];

        $this->assertSame('string', $skill->inputs['what']['type']);
        $this->assertSame('The short NAME only.', $skill->inputs['what']['description']);
        // No hint declared → the legacy bare-flag fallback is untouched.
        $this->assertSame('flag', $skill->inputs['kind']['type']);
        $this->assertSame('', $skill->inputs['kind']['description']);
    }

    public function test_env_disabled_skill_is_excluded(): void
    {
        putenv('TEST_LLM_SKILL_ENABLED=0');

        try {
            $registry = new SkillRegistry();
            $manifest = $registry->buildManifestFromClasses([EnvControlledSkillCommand::class]);

            $this->assertCount(0, $manifest->skills);
        } finally {
            putenv('TEST_LLM_SKILL_ENABLED');
        }
    }

    public function test_a_skill_that_fails_to_build_is_logged_not_silently_dropped(): void
    {
        // A skill dropped from the manifest for ANY reason (bad metadata, or a
        // missing/miswired dependency that makes it unbuildable) must be logged,
        // not vanish silently — otherwise it surfaces only as "the assistant
        // can't do X" with no signal. A non-existent class name reflects that
        // failure mode deterministically (ReflectionException — a non-ValueError
        // Throwable, the branch that used to be silent).
        $logger = new CapturingLogger();
        $registry = new SkillRegistry(null, $logger);

        $manifest = $registry->buildManifestFromClasses(['Semitexa\\Llm\\Tests\\Unit\\Registry\\NoSuchSkill_Missing']);

        $this->assertCount(0, $manifest->skills);
        $this->assertCount(1, $logger->warnings, 'a dropped skill must be logged, not silently skipped');
        [$message, $context] = $logger->warnings[0];
        $this->assertSame('Failed to build skill manifest entry', $message);
        $this->assertStringContainsString('NoSuchSkill_Missing', (string) $context['class']);
        $this->assertStringContainsString('ReflectionException', (string) $context['exception']);
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

#[AsAiSkill(
    allowed: true,
    name: 'hinted',
    summary: 'Invocable skill with argument hints.',
    useWhen: 'Testing.',
    avoidWhen: 'Production.',
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: 'allowlisted',
    exposeArguments: ['what', 'kind'],
    argumentHints: ['what' => 'The short NAME only.'],
    channels: ['web'],
)]
final class HintedInvocableSkill implements \Semitexa\Llm\Domain\Contract\InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        return 'ok';
    }
}

#[AsCommand(name: 'env:controlled', description: 'Env-controlled skill')]
#[AsAiSkill(allowed: 'env::TEST_LLM_SKILL_ENABLED::true', summary: 'Enabled only when env flag allows it.')]
final class EnvControlledSkillCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}

final class CapturingLogger implements LoggerInterface
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $warnings = [];

    public function error(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}

    public function warning(string $message, array $context = []): void
    {
        $this->warnings[] = [$message, $context];
    }

    public function info(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function debug(string $message, array $context = []): void {}
}

#[AsAiSkill(
    allowed: true,
    name: 'Content',
    summary: 'A UI skill that opens at a particular record.',
    useWhen: 'Testing.',
    avoidWhen: 'Production.',
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: 'allowlisted',
    exposeArguments: ['ref'],
    argumentHints: ['ref' => 'Which page to open.'],
    channels: ['ui'],
    entry: '/os/app/cms',
)]
final class UiSkillWithArguments
{
}

#[AsAiSkill(
    allowed: true,
    name: 'env-channelled',
    summary: 'Channels come from one env var.',
    channels: 'env::TEST_LLM_SKILL_CHANNELS::console,web',
)]
final class EnvChannelledSkill implements \Semitexa\Llm\Domain\Contract\InvocableSkillInterface
{
    public function invoke(array $arguments): string { return 'ok'; }
}

#[AsAiSkill(
    allowed: true,
    name: 'env-channelled-entry',
    summary: 'One entry of the list comes from an env var.',
    channels: ['console', 'env::TEST_LLM_SKILL_EXTRA_CHANNEL::'],
)]
final class EnvChannelledEntrySkill implements \Semitexa\Llm\Domain\Contract\InvocableSkillInterface
{
    public function invoke(array $arguments): string { return 'ok'; }
}

#[AsAiSkill(
    allowed: true,
    name: 'no-surface',
    summary: 'Resolves to no surface at all.',
    channels: 'env::TEST_LLM_SKILL_MISSING_CHANNELS::',
)]
final class NoSurfaceSkill implements \Semitexa\Llm\Domain\Contract\InvocableSkillInterface
{
    public function invoke(array $arguments): string { return 'ok'; }
}

#[AsAiSkill(
    allowed: true,
    name: 'ui-on-console',
    summary: 'A UI skill that also claims an executable channel.',
    channels: ['ui', 'console'],
    entry: '/os/app/nowhere',
)]
final class UiSkillOnExecutableChannel
{
}
