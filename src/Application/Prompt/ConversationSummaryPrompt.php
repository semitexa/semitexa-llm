<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * Thin, self-binding prompt — body in resources/prompts/llm.conversation-summary.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'llm',
    template: 'resources/prompts/llm.conversation-summary.twig',
    description: 'Rolling conversation-summary system prompt (JSON summary + active_intent).',
)]
final class ConversationSummaryPrompt implements BoundPromptInterface
{
    public const ID = 'llm.conversation-summary';

    public function promptId(): string
    {
        return self::ID;
    }
}
