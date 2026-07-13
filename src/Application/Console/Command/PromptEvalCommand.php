<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Application\Service\Prompt\PromptRequestFactory;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Exception\PromptNotFoundException;
use Semitexa\Prompt\Domain\Model\PromptTemplate;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Evaluate a catalog prompt against the live LLM: render it (effective — the
 * tenant override wins, catalog otherwise), attach a user message, send it via
 * the active provider, and print the reply. With --compare, it also runs the
 * catalog DEFAULT of the same prompt so you can see how an override changes the
 * model's behaviour before committing to it.
 */
#[AsCommand(name: 'prompt:eval', description: 'Send a prompt (+ optional message) to the LLM; compare an override against the catalog default.')]
final class PromptEvalCommand extends Command
{
    #[InjectAsReadonly]
    protected LlmProviderResolver $providers;

    #[InjectAsReadonly]
    protected PromptRequestFactory $requests;

    /** The effective (override-aware) repository. */
    #[InjectAsReadonly]
    protected PromptRepositoryInterface $repository;

    protected function configure(): void
    {
        $this
            ->setName('prompt:eval')
            ->setDescription('Send a prompt (+ optional message) to the LLM; compare an override against the catalog default.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Prompt id to evaluate')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'User message to send with the prompt', '')
            ->addOption('var', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Variable binding as name=value (repeatable)')
            ->addOption('compare', null, InputOption::VALUE_NONE, 'Also run the catalog default and show both side by side')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = $input->getOption('id');
        if (!is_string($id) || $id === '') {
            $io->error('Provide a prompt id with --id=<id>.');

            return Command::INVALID;
        }

        $variables = [];
        foreach ((array) $input->getOption('var') as $pair) {
            $eq = strpos((string) $pair, '=');
            if ($eq === false) {
                $io->error(sprintf('Invalid --var "%s": expected name=value.', $pair));

                return Command::INVALID;
            }
            $variables[substr((string) $pair, 0, $eq)] = substr((string) $pair, $eq + 1);
        }

        $message = (string) $input->getOption('message');
        $compare = (bool) $input->getOption('compare');

        try {
            $effective = $this->evaluate($this->repository->get($id), $variables, $message);
            $default = $compare ? $this->evaluate((new PromptRegistry())->get($id), $variables, $message) : null;
        } catch (PromptNotFoundException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(
                ['id' => $id, 'provider' => $this->providers->provider()->name(), 'effective' => $effective, 'default' => $default],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return Command::SUCCESS;
        }

        $io->title(sprintf('prompt:eval %s (%s)', $id, $this->providers->provider()->name()));
        $this->render($io, $compare ? 'EFFECTIVE (override → catalog)' : 'Result', $effective);
        if ($default !== null) {
            $this->render($io, 'CATALOG DEFAULT', $default);
        }

        return $effective['ok'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string, string> $variables
     * @return array{system: string, ok: bool, content: string, error: string|null}
     */
    private function evaluate(PromptTemplate $template, array $variables, string $message): array
    {
        $rendered = (new PromptRenderer())->renderTemplate($template, $variables);
        $request = $this->requests->fromRendered($rendered, $message);
        $response = $this->providers->provider()->complete($request);

        return [
            'system' => $rendered->system,
            'ok' => $response->success,
            'content' => $response->content,
            'error' => $response->error,
        ];
    }

    /**
     * @param array{system: string, ok: bool, content: string, error: string|null} $result
     */
    private function render(SymfonyStyle $io, string $label, array $result): void
    {
        $io->section($label);
        if ($result['ok']) {
            $io->writeln($result['content'] !== '' ? $result['content'] : '(empty response)');
        } else {
            $io->error('Provider error: ' . ($result['error'] ?? 'unknown'));
        }
    }
}
