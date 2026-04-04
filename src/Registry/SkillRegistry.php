<?php

declare(strict_types=1);

namespace Semitexa\Llm\Registry;

use ReflectionClass;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Llm\Attributes\AsAiSkill;
use Semitexa\Llm\Data\SkillEntry;
use Semitexa\Llm\Data\SkillManifest;
use Semitexa\Llm\Policy\AiArgumentPolicy;
use Symfony\Component\Console\Command\Command;

final class SkillRegistry
{
    public function __construct(
        private ?ClassDiscovery $classDiscovery = null,
    ) {}

    public function buildManifest(): SkillManifest
    {
        $classes = $this->classDiscovery()->findClassesWithAttribute(AsAiSkill::class);
        return $this->buildManifestFromClasses($classes);
    }

    /**
     * @param list<class-string> $classes
     */
    public function buildManifestFromClasses(array $classes): SkillManifest
    {
        $entries = [];
        foreach ($classes as $className) {
            $entry = $this->buildEntry($className);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn(SkillEntry $a, SkillEntry $b): int => strcmp($a->name, $b->name));

        return new SkillManifest(
            artifact: 'semitexa.ai-skills/v1',
            generatedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            skills: $entries,
        );
    }

    private function buildEntry(string $className): ?SkillEntry
    {
        try {
            $ref = new ReflectionClass($className);

            $skillAttrs = $ref->getAttributes(AsAiSkill::class);
            if ($skillAttrs === []) {
                return null;
            }

            /** @var AsAiSkill $skill */
            $skill = $skillAttrs[0]->newInstance();
            if (!$skill->allowed) {
                return null;
            }

            $commandAttrs = $ref->getAttributes(AsCommand::class);
            if ($commandAttrs === []) {
                return null;
            }

            /** @var AsCommand $commandAttr */
            $commandAttr = $commandAttrs[0]->newInstance();

            $inputs = $this->buildInputs($ref, $skill);

            return new SkillEntry(
                name: $commandAttr->name,
                sourceCommand: $commandAttr->name,
                summary: $skill->summary ?? $commandAttr->description ?? '',
                useWhen: $skill->useWhen ?? '',
                avoidWhen: $skill->avoidWhen ?? '',
                riskLevel: $skill->resolvedRiskLevel,
                confirmation: $skill->resolvedConfirmation,
                supportsDryRun: $skill->supportsDryRun,
                argumentPolicy: $skill->resolvedArgumentPolicy,
                inputs: $inputs,
                channels: $skill->channels,
                executionKind: $skill->resolvedExecutionKind,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, array{type: string, required: bool, description: string}>
     */
    private function buildInputs(ReflectionClass $ref, AsAiSkill $skill): array
    {
        if ($skill->resolvedArgumentPolicy === AiArgumentPolicy::None) {
            return [];
        }

        if ($skill->exposeArguments === [] && $skill->resolvedArgumentPolicy !== AiArgumentPolicy::All) {
            return [];
        }

        $optionDescriptions = $this->tryGetOptionDescriptions($ref);

        $argsToExpose = $skill->resolvedArgumentPolicy === AiArgumentPolicy::All
            ? array_keys($optionDescriptions)
            : $skill->exposeArguments;

        $inputs = [];
        foreach ($argsToExpose as $argName) {
            $required = in_array($argName, $skill->requiredArguments, true);
            $description = $optionDescriptions[$argName] ?? '';

            $inputs[$argName] = [
                'type' => 'flag',
                'required' => $required,
                'description' => $description,
            ];
        }

        return $inputs;
    }

    /**
     * @return array<string, string>
     */
    private function tryGetOptionDescriptions(ReflectionClass $ref): array
    {
        try {
            if (!$ref->isSubclassOf(Command::class)) {
                return [];
            }

            $ctor = $ref->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                return [];
            }

            /** @var Command $command */
            $command = $ref->newInstance();

            $descriptions = [];
            foreach ($command->getDefinition()->getOptions() as $option) {
                $descriptions[$option->getName()] = $option->getDescription();
            }
            return $descriptions;
        } catch (\Throwable) {
            return [];
        }
    }

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ??= new ClassDiscovery();
    }
}
