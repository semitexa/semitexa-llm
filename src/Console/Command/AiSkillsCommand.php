<?php

declare(strict_types=1);

namespace Semitexa\Llm\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Llm\Registry\SkillRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ai:skills', description: 'List AI-executable skills with full metadata. Use for debugging or AI tooling.')]
final class AiSkillsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('ai:skills')
            ->setDescription('List AI-executable skills with full metadata. Use for debugging or AI tooling.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (default: human-readable table)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = new SkillRegistry();
        $manifest = $registry->buildManifest();

        if ((bool) $input->getOption('json')) {
            $output->writeln($manifest->toJson());
            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('AI Skills Manifest — ' . $manifest->artifact);

        if ($manifest->skills === []) {
            $io->warning('No AI skills registered. Add #[AsAiSkill] to command classes.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($manifest->skills as $skill) {
            $inputNames = $skill->inputs !== [] ? implode(', ', array_keys($skill->inputs)) : '—';
            $rows[] = [
                $skill->name,
                $skill->riskLevel->value,
                $skill->confirmation->value,
                $skill->supportsDryRun ? 'yes' : 'no',
                $inputNames,
                $skill->summary,
            ];
        }

        $io->table(
            ['Skill', 'Risk', 'Confirmation', 'Dry-Run', 'Inputs', 'Summary'],
            $rows,
        );

        $io->text(sprintf('Total: %d skill(s). Generated at: %s', count($manifest->skills), $manifest->generatedAt));

        return Command::SUCCESS;
    }
}
