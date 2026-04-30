<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Executor;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Domain\Model\SkillEntry;
use Semitexa\Llm\Domain\Model\SkillManifest;
use Semitexa\Llm\Exception\PolicyViolationException;
use Semitexa\Llm\Application\Service\SkillExecutor;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SkillExecutorTest extends TestCase
{
    private Application $application;
    private SkillManifest $manifest;

    protected function setUp(): void
    {
        $this->application = new Application('Test', '1.0');
        $this->application->setAutoExit(false);
        $this->application->add(new StubExecutorCommand());

        $this->manifest = new SkillManifest(
            artifact: 'semitexa.ai-skills/v1',
            generatedAt: '2026-03-22T12:00:00+00:00',
            skills: [
                new SkillEntry(
                    name: 'stub:exec',
                    sourceCommand: 'stub:exec',
                    summary: 'Stub executor command',
                    useWhen: 'Testing.',
                    avoidWhen: 'Never.',
                    riskLevel: AiRiskLevel::Low,
                    confirmation: AiConfirmationMode::Never,
                    supportsDryRun: false,
                    argumentPolicy: AiArgumentPolicy::Allowlisted,
                    inputs: [
                        'flag' => ['type' => 'flag', 'required' => false, 'description' => 'Test flag'],
                    ],
                    channels: ['console'],
                    executionKind: AiExecutionKind::DirectCommand,
                ),
            ],
        );
    }

    public function test_executes_skill_successfully(): void
    {
        $executor = new SkillExecutor($this->application);
        $result = $executor->execute('stub:exec', [], $this->manifest);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('stub:exec', $result->skill);
        $this->assertTrue($result->approved);
    }

    public function test_rejects_unknown_skill(): void
    {
        $executor = new SkillExecutor($this->application);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessageMatches('/not in the allowed skill manifest/');

        $executor->execute('unknown:command', [], $this->manifest);
    }

    public function test_rejects_non_allowlisted_argument(): void
    {
        $executor = new SkillExecutor($this->application);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessageMatches('/not allowlisted/');

        $executor->execute('stub:exec', ['dangerous_arg' => true], $this->manifest);
    }

    public function test_rejects_wrong_channel(): void
    {
        $executor = new SkillExecutor($this->application);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessageMatches('/not available on channel/');

        $executor->execute('stub:exec', [], $this->manifest, channel: 'http');
    }

    public function test_requires_mandatory_argument(): void
    {
        $manifest = new SkillManifest(
            artifact: 'semitexa.ai-skills/v1',
            generatedAt: '2026-03-22T12:00:00+00:00',
            skills: [
                new SkillEntry(
                    name: 'stub:exec',
                    sourceCommand: 'stub:exec',
                    summary: 'Test',
                    useWhen: 'Test',
                    avoidWhen: 'Never',
                    riskLevel: AiRiskLevel::Low,
                    confirmation: AiConfirmationMode::Never,
                    supportsDryRun: false,
                    argumentPolicy: AiArgumentPolicy::Allowlisted,
                    inputs: [
                        'flag' => ['type' => 'flag', 'required' => true, 'description' => 'Required flag'],
                    ],
                    channels: ['console'],
                    executionKind: AiExecutionKind::DirectCommand,
                ),
            ],
        );

        $executor = new SkillExecutor($this->application);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessageMatches('/Required argument/');

        $executor->execute('stub:exec', [], $manifest);
    }
}

// --- Test fixture ---

final class StubExecutorCommand extends Command
{
    public function __construct()
    {
        parent::__construct('stub:exec');
    }

    protected function configure(): void
    {
        $this->addOption('flag', null, InputOption::VALUE_NONE, 'Test flag');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('stub executed');
        return Command::SUCCESS;
    }
}
