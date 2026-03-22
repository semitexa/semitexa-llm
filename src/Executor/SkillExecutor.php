<?php

declare(strict_types=1);

namespace Semitexa\Llm\Executor;

use Semitexa\Llm\Data\ExecutionResult;
use Semitexa\Llm\Data\SkillEntry;
use Semitexa\Llm\Data\SkillManifest;
use Semitexa\Llm\Exception\PolicyViolationException;
use Semitexa\Llm\Policy\AiArgumentPolicy;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class SkillExecutor
{
    public function __construct(
        private readonly Application $application,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(
        string $skillName,
        array $arguments,
        SkillManifest $manifest,
        string $channel = 'console',
    ): ExecutionResult {
        $entry = $manifest->findSkill($skillName);
        if ($entry === null) {
            throw new PolicyViolationException(
                "Skill '{$skillName}' is not in the allowed skill manifest."
            );
        }

        if (!in_array($channel, $entry->channels, true)) {
            throw new PolicyViolationException(
                "Skill '{$skillName}' is not available on channel '{$channel}'."
            );
        }

        $this->validateArguments($entry, $arguments);

        $inputArgs = ['command' => $entry->sourceCommand];
        foreach ($arguments as $name => $value) {
            if ($value === true) {
                $inputArgs["--{$name}"] = true;
            } elseif ($value !== false && $value !== null) {
                $inputArgs["--{$name}"] = $value;
            }
        }

        $input = new ArrayInput($inputArgs);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        try {
            $command = $this->application->find($entry->sourceCommand);
            $exitCode = $command->run($input, $output);
        } catch (\Throwable $e) {
            return new ExecutionResult(
                skill: $skillName,
                exitCode: 1,
                output: $output->fetch(),
                approved: true,
                error: $e->getMessage(),
            );
        }

        return new ExecutionResult(
            skill: $skillName,
            exitCode: $exitCode,
            output: $output->fetch(),
            approved: true,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function validateArguments(SkillEntry $entry, array $arguments): void
    {
        foreach ($entry->inputs as $argName => $meta) {
            if ($meta['required'] && !array_key_exists($argName, $arguments)) {
                throw new PolicyViolationException(
                    "Required argument '{$argName}' is missing for skill '{$entry->name}'."
                );
            }
        }

        if ($entry->argumentPolicy === AiArgumentPolicy::None && $arguments !== []) {
            throw new PolicyViolationException(
                "Skill '{$entry->name}' does not accept arguments."
            );
        }

        if ($entry->argumentPolicy === AiArgumentPolicy::Allowlisted) {
            $allowedArgs = array_keys($entry->inputs);
            foreach (array_keys($arguments) as $argName) {
                if (!in_array($argName, $allowedArgs, true)) {
                    throw new PolicyViolationException(
                        "Argument '{$argName}' is not allowlisted for skill '{$entry->name}'."
                    );
                }
            }
        }
    }
}
