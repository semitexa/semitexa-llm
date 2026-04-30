<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Console\Command\AiAssistantCommand;
use Semitexa\Llm\Application\Console\Command\AiSkillsCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AiAssistantCommandTest extends TestCase
{
    public function test_ai_skills_command_outputs_json(): void
    {
        $application = new Application('Test', '1.0');
        $application->setAutoExit(false);
        $application->add(new AiSkillsCommand());

        $command = $application->find('ai:skills');
        $tester = new CommandTester($command);

        // This will use ClassDiscovery, which may return empty results in test context.
        // We just verify the command runs without error and produces valid JSON.
        $tester->execute(['--json' => true]);

        $output = $tester->getDisplay();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertSame('semitexa.ai-skills/v1', $decoded['artifact']);
        $this->assertArrayHasKey('skills', $decoded);
        $this->assertIsArray($decoded['skills']);
    }

    public function test_ai_skills_command_human_readable_output(): void
    {
        $application = new Application('Test', '1.0');
        $application->setAutoExit(false);
        $application->add(new AiSkillsCommand());

        $command = $application->find('ai:skills');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('AI Skills Manifest', $output);
    }
}
