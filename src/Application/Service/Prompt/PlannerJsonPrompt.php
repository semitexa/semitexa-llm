<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * The planner system prompt for the JSON-contract path (Ollama and any provider
 * without native tool-calling). Migrated out of {@see \Semitexa\Llm\Application\Service\Planner::buildSystemPrompt()}.
 *
 * Three bound variables:
 *   - {{ persona }}   identity/role framing (caller-supplied or a default)
 *   - {{ skills }}    the compact skills manifest
 *   - {{ now_line }}  absolute date/time anchor — kept LAST on purpose: it changes
 *                     every minute, and an Ollama runtime caches the KV of the
 *                     longest matching prompt PREFIX. A volatile tail lets the
 *                     large static skills prefix stay cached between turns.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'llm',
    description: 'Planner system prompt, JSON-contract path (no native tool-calling).',
)]
final class PlannerJsonPrompt implements PromptDefinitionInterface
{
    public const ID = 'llm.planner.json';

    public function system(): string
    {
        return <<<'PROMPT'
        {{ persona }}

        {{ skills }}

        Always respond with valid JSON in exactly one of these formats:

        Direct answer (no skill needed):
        {"type":"answer","message":"Your answer.","reason":"Why no skill is needed."}

        Clarification question:
        {"type":"ask","message":"Your question.","reason":"What information is missing."}

        Propose a skill:
        {"type":"propose_skill","skill":"skill-name","arguments":{},"reason":"Why this skill matches.","confidence":0.9}

        Propose a pipeline (an ordered chain of skills run in sequence — ONLY when the request genuinely needs several skills one after another):
        {"type":"propose_pipeline","steps":[{"skill":"skill-a","arguments":{}},{"skill":"skill-b","arguments":{}}],"reason":"Why this chain.","confidence":0.8}

        Refuse:
        {"type":"refuse","message":"Why you cannot help.","reason":"Safety or policy reason."}

        Rules:
        - Only propose skills from the list above. Never invent skill names or argument names.
        - Prefer a single propose_skill; use propose_pipeline only when one skill must follow another.
        - Arguments must only use names listed in the skill inputs.
        - If the request is ambiguous, ask for clarification.
        - If no skill matches, answer directly or refuse.
        - Output valid JSON only. No markdown, no code fences, no extra text.

        {{ now_line }}
        PROMPT;
    }
}
