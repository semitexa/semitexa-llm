<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;

/**
 * Thin prompt definition — the body lives in resources/prompts/llm.planner.json.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'llm',
    description: 'Planner system prompt, JSON-contract path (no native tool-calling).',
)]
final class PlannerJsonPrompt
{
    public const ID = 'llm.planner.json';
}
