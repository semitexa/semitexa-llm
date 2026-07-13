<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;

/**
 * Thin prompt definition — the body lives in resources/prompts/llm.conversation-summary.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'llm',
    description: 'Rolling conversation-summary system prompt (JSON summary + active_intent).',
)]
final class ConversationSummaryPrompt
{
    public const ID = 'llm.conversation-summary';
}
