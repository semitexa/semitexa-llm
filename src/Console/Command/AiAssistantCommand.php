<?php

declare(strict_types=1);

namespace Semitexa\Llm\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Llm\Contract\LlmProviderInterface;
use Semitexa\Llm\Data\LlmRequest;
use Semitexa\Llm\Data\LlmResponse;
use Semitexa\Llm\Data\PlannerResponse;
use Semitexa\Llm\Data\PlannerResponseType;
use Semitexa\Llm\Data\SkillManifest;
use Semitexa\Llm\Exception\PolicyViolationException;
use Semitexa\Llm\Executor\SkillExecutor;
use Semitexa\Llm\Planner\Planner;
use Semitexa\Llm\Policy\AiConfirmationMode;
use Semitexa\Llm\Registry\SkillRegistry;
use Semitexa\Llm\Session\ConversationSession;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ai', description: 'Start an interactive AI assistant backed by a local LLM. Uses skills defined with #[AsAiSkill].')]
final class AiAssistantCommand extends Command
{
    public function __construct(
        private readonly LlmProviderInterface $provider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ai')
            ->setDescription('Start an interactive AI assistant backed by a local LLM. Uses skills defined with #[AsAiSkill].')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be executed without running anything')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompts and execute all proposed skills automatically');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $autoConfirm = (bool) $input->getOption('yes');

        $io->title('Semitexa AI Assistant');
        $io->text([
            'Provider : ' . $this->provider->name() . ' @ ' . $this->provider->baseUrl(),
            'Model    : ' . $this->provider->model(),
            'Type "exit" or "quit" to end the session. Type "clear" to reset conversation.',
        ]);

        if (!$this->provider->healthCheck()) {
            $io->error([
                'Cannot reach the LLM provider at ' . $this->provider->baseUrl(),
                'Ensure Ollama (or your LLM runtime) is running and accessible.',
            ]);
            return Command::FAILURE;
        }

        $io->success('Provider is healthy.');

        $registry = new SkillRegistry();
        $manifest = $registry->buildManifest();

        if ($manifest->skills === []) {
            $io->warning('No AI skills are registered. Add #[AsAiSkill] to command classes to enable skill execution.');
        } else {
            $io->text(sprintf('%d skill(s) available. Run `ai:skills` to see them.', count($manifest->skills)));
        }

        $planner = new Planner();
        $systemPrompt = $planner->buildSystemPrompt($manifest);
        $session = new ConversationSession();

        $application = $this->getApplication();
        $executor = $application !== null ? new SkillExecutor($application) : null;

        $io->newLine();

        while (true) {
            $userInput = $io->ask('You');

            if ($userInput === null || $userInput === '') {
                continue;
            }

            $normalized = strtolower(trim($userInput));
            if (in_array($normalized, ['exit', 'quit'], true)) {
                $io->text('Goodbye.');
                return Command::SUCCESS;
            }

            if ($normalized === 'clear') {
                $session->clear();
                $io->text('Conversation cleared.');
                continue;
            }

            $session->addUserMessage($userInput);

            $request = new LlmRequest(
                systemPrompt: $systemPrompt,
                userMessage: $userInput,
                history: array_slice($session->getHistory(), 0, -1),
            );

            $io->text('<comment>Thinking...</comment>');

            $llmResponse = $this->provider->complete($request);
            $plannerResponse = $planner->parseResponse($llmResponse);

            if ($output->isVerbose() && !$llmResponse->success) {
                $io->text(sprintf('<fg=yellow>[debug] LLM error: %s</>', $llmResponse->error ?? 'unknown'));
            }

            if ($output->isVerbose()
                && $llmResponse->success
                && $plannerResponse->jsonExtractionFailed
            ) {
                $io->text(sprintf(
                    '<fg=yellow>[debug] JSON extraction failed, raw: %s</>',
                    mb_substr($llmResponse->content, 0, 200),
                ));
            }

            if ($llmResponse->success && $llmResponse->content !== '') {
                $session->addAssistantMessage($llmResponse->content);
            }

            $this->renderObservabilityLine($io, $llmResponse);

            match ($plannerResponse->type) {
                PlannerResponseType::Answer => $io->text('<info>Assistant:</info> ' . ($plannerResponse->message ?? $plannerResponse->reason)),
                PlannerResponseType::Ask => $io->text('<info>Assistant (needs more info):</info> ' . ($plannerResponse->message ?? '')),
                PlannerResponseType::Refuse => $io->warning('Assistant declined: ' . ($plannerResponse->message ?? $plannerResponse->reason)),
                PlannerResponseType::ProposeSkill => $this->handleSkillProposal(
                    io: $io,
                    plannerResponse: $plannerResponse,
                    manifest: $manifest,
                    executor: $executor,
                    dryRun: $dryRun,
                    autoConfirm: $autoConfirm,
                ),
            };

            $io->newLine();
        }
    }

    private function handleSkillProposal(
        SymfonyStyle $io,
        PlannerResponse $plannerResponse,
        SkillManifest $manifest,
        ?SkillExecutor $executor,
        bool $dryRun,
        bool $autoConfirm,
    ): void {
        $skillName = $plannerResponse->skill;
        if ($skillName === null) {
            $io->warning('Assistant proposed a skill but did not name one.');
            return;
        }

        $entry = $manifest->findSkill($skillName);
        if ($entry === null) {
            $io->error("Assistant proposed unknown skill '{$skillName}'. This proposal was rejected.");
            return;
        }

        $io->section('Proposed Action');
        $io->definitionList(
            ['Skill' => $skillName],
            ['Risk level' => $entry->riskLevel->value],
            ['Reason' => $plannerResponse->reason],
            ['Confidence' => $plannerResponse->confidence !== null ? number_format($plannerResponse->confidence * 100, 0) . '%' : 'N/A'],
        );

        if ($plannerResponse->arguments !== []) {
            $io->text('Arguments:');
            foreach ($plannerResponse->arguments as $k => $v) {
                $io->text(sprintf('  --%s = %s', $k, is_bool($v) ? ($v ? 'true' : 'false') : (string) $v));
            }
        }

        if ($dryRun) {
            $io->note('[dry-run] Execution skipped.');
            return;
        }

        if ($executor === null) {
            $io->error('Executor is not available — cannot run skill in this context.');
            return;
        }

        $needsConfirmation = match ($entry->confirmation) {
            AiConfirmationMode::Always => true,
            AiConfirmationMode::WhenMutating => true,
            AiConfirmationMode::Never => false,
        };

        if ($needsConfirmation && !$autoConfirm && !$io->confirm(sprintf('Execute skill "%s"?', $skillName), false)) {
            $io->text('Execution cancelled.');
            return;
        }

        try {
            $result = $executor->execute($skillName, $plannerResponse->arguments, $manifest);
            if ($result->isSuccess()) {
                $io->success('Skill executed successfully (exit code 0).');
            } else {
                $io->warning(sprintf('Skill exited with code %d.', $result->exitCode));
            }
            if ($result->output !== '') {
                $io->text($result->output);
            }
        } catch (PolicyViolationException $e) {
            $io->error('Policy violation: ' . $e->getMessage());
        }
    }

    private function renderObservabilityLine(SymfonyStyle $io, LlmResponse $llmResponse): void
    {
        $parts = [];
        if ($llmResponse->latencyMs !== null) {
            $parts[] = sprintf('latency=%.0fms', $llmResponse->latencyMs);
        }
        if ($llmResponse->tokensUsed !== null) {
            $parts[] = sprintf('tokens=%d', $llmResponse->tokensUsed);
        }
        if ($parts !== []) {
            $io->text('<fg=gray>[' . implode(' | ', $parts) . ']</>');
        }
    }
}
