<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;

/**
 * Thin prompt definition — the body lives in resources/prompts/llm.planner.tool.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'llm',
    template: 'llm.planner.tool.twig',
    description: 'Planner system prompt, native tool-calling path (Gemini).',
)]
final class PlannerToolPrompt
{
    public const ID = 'llm.planner.tool';
}
