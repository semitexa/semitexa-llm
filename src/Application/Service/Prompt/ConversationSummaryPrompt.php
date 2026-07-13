<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * The rolling-summary system prompt used by {@see \Semitexa\Llm\Application\Service\ConversationSummarizer}.
 *
 * Migrated out of an inline heredoc const into the prompt catalog: the text is
 * now discoverable (`prompt:list`), inspectable (`prompt:show --id=llm.conversation-summary`)
 * and owned in one addressable place instead of being buried in a service.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'llm',
    description: 'Rolling conversation-summary system prompt (JSON summary + active_intent).',
)]
final class ConversationSummaryPrompt implements PromptDefinitionInterface
{
    public const ID = 'llm.conversation-summary';

    public function system(): string
    {
        return <<<'PROMPT'
        You maintain a running summary of an ongoing conversation between a user and their assistant OS.
        You are given the PRIOR SUMMARY, the PRIOR ACTIVE INTENT, and a batch of NEW TURNS that are aging out of the verbatim window.
        Produce an UPDATED summary that folds the new turns into the prior one.

        Rules:
        - Keep it concise (at most ~120 words). Preserve durable facts, names, numbers, decisions, preferences, and any unfinished task.
        - Drop small talk and anything already superseded.
        - "active_intent": ONE short sentence naming what the user is currently trying to accomplish across turns, or "" if there is no ongoing task.
        - Write the summary in the same language the conversation uses.
        - Respond with valid JSON only, exactly: {"summary":"...","active_intent":"..."}
        - No markdown, no code fences, no extra text.
        PROMPT;
    }
}
