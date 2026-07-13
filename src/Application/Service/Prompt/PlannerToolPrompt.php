<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * The planner system prompt for the native function-calling path (Gemini).
 * Migrated out of {@see \Semitexa\Llm\Application\Service\Planner::buildToolSystemPrompt()}.
 *
 * Carries NO skills list and NO JSON-format block: skills arrive as tool
 * declarations and the model replies by calling exactly one tool. Two bound
 * variables — {{ persona }} and the trailing {{ now_line }} date anchor (kept
 * last for the same KV-cache reason as {@see PlannerJsonPrompt}).
 */
#[AsPrompt(
    id: self::ID,
    channel: 'llm',
    description: 'Planner system prompt, native tool-calling path (Gemini).',
)]
final class PlannerToolPrompt implements PromptDefinitionInterface
{
    public const ID = 'llm.planner.tool';

    public function system(): string
    {
        return <<<'PROMPT'
        {{ persona }}

        Act by calling exactly one tool per turn:
        - Call a skill tool to perform an action; fill its arguments only from the tool's declared parameters.
        - Call `final_answer` to reply directly when no skill is needed, or to report the result after skills have run.
        - Call `ask_user` when the request is ambiguous or missing information a skill requires.
        - Call `refuse_request` only for a safety or policy reason.

        Never invent tools or arguments. Prefer acting over asking when the request is clear.

        {{ now_line }}
        PROMPT;
    }
}
