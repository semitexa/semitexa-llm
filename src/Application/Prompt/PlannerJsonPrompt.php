<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * Thin, self-binding prompt — body in resources/prompts/llm.planner.json.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'llm',
    template: 'resources/prompts/llm.planner.json.twig',
    description: 'Planner system prompt, JSON-contract path (no native tool-calling).',
)]
final class PlannerJsonPrompt implements BoundPromptInterface
{
    public const ID = 'llm.planner.json';

    public function __construct(
        private readonly ?string $persona = null,
        private readonly ?string $nowLine = null,
        private readonly ?string $skills = null,
    ) {}

    public function withData(string $persona, string $nowLine, string $skills): self
    {
        return new self($persona, $nowLine, $skills);
    }

    public function promptId(): string
    {
        return self::ID;
    }

    public function persona(): string
    {
        return (string) $this->persona;
    }

    public function nowLine(): string
    {
        return (string) $this->nowLine;
    }

    public function skills(): string
    {
        return (string) $this->skills;
    }
}
