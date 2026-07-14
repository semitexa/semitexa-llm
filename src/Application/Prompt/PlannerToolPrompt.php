<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * Thin, self-binding prompt — body in resources/prompts/llm.planner.tool.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'llm',
    template: 'resources/prompts/llm.planner.tool.twig',
    description: 'Planner system prompt, native tool-calling path (Gemini).',
)]
final class PlannerToolPrompt implements BoundPromptInterface
{
    public const ID = 'llm.planner.tool';

    public function __construct(
        private readonly ?string $persona = null,
        private readonly ?string $nowLine = null,
    ) {}

    public function withData(string $persona, string $nowLine): self
    {
        return new self($persona, $nowLine);
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
}
