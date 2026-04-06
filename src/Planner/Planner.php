<?php

declare(strict_types=1);

namespace Semitexa\Llm\Planner;

use Semitexa\Llm\Data\LlmResponse;
use Semitexa\Llm\Data\PlannerResponse;
use Semitexa\Llm\Data\PlannerResponseType;
use Semitexa\Llm\Data\SkillManifest;

final class Planner
{
    public function buildSystemPrompt(SkillManifest $manifest): string
    {
        $skillsPrompt = $manifest->toCompactPrompt();

        return <<<PROMPT
You are a Semitexa framework assistant. Your job is to interpret operator requests and map them to available framework skills.

{$skillsPrompt}

Always respond with valid JSON in exactly one of these formats:

Direct answer (no skill needed):
{"type":"answer","message":"Your answer.","reason":"Why no skill is needed."}

Clarification question:
{"type":"ask","message":"Your question.","reason":"What information is missing."}

Propose a skill:
{"type":"propose_skill","skill":"skill-name","arguments":{},"reason":"Why this skill matches.","confidence":0.9}

Refuse:
{"type":"refuse","message":"Why you cannot help.","reason":"Safety or policy reason."}

Rules:
- Only propose skills from the list above. Never invent skill names or argument names.
- Arguments must only use names listed in the skill inputs.
- If the request is ambiguous, ask for clarification.
- If no skill matches, answer directly or refuse.
- Output valid JSON only. No markdown, no code fences, no extra text.
PROMPT;
    }

    public function parseResponse(LlmResponse $response, string $rawUserMessage = ''): PlannerResponse
    {
        if (!$response->success) {
            return new PlannerResponse(
                type: PlannerResponseType::Refuse,
                reason: 'Provider error',
                message: 'The assistant is currently unavailable: ' . ($response->error ?? 'unknown error'),
            );
        }

        $content = trim($response->content);
        $decoded = $this->extractJson($content);

        if (!is_array($decoded) || !isset($decoded['type'])) {
            return new PlannerResponse(
                type: PlannerResponseType::Answer,
                reason: 'Raw text response (JSON extraction failed)',
                message: $content !== '' ? $content : 'No response from assistant.',
            );
        }

        $type = PlannerResponseType::tryFrom((string) $decoded['type']);
        if ($type === null) {
            return new PlannerResponse(
                type: PlannerResponseType::Refuse,
                reason: 'Unrecognized response type: ' . $decoded['type'],
                message: 'The assistant returned an unrecognized response type.',
            );
        }

        return new PlannerResponse(
            type: $type,
            skill: isset($decoded['skill']) ? (string) $decoded['skill'] : null,
            arguments: is_array($decoded['arguments'] ?? null) ? $decoded['arguments'] : [],
            reason: isset($decoded['reason']) ? (string) $decoded['reason'] : '',
            confidence: isset($decoded['confidence']) ? (float) $decoded['confidence'] : null,
            message: isset($decoded['message']) ? (string) $decoded['message'] : null,
        );
    }

    /**
     * Extract JSON from LLM output that may contain markdown fences, preamble text, or trailing content.
     *
     * @return array<string, mixed>|null
     */
    public function extractJson(string $raw): ?array
    {
        // 1. Try direct decode first
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 2. Strip markdown code fences (```json ... ``` or ``` ... ```)
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $raw, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 3. Find the first { ... } block (greedy, outermost braces)
        if (preg_match('/\{(?:[^{}]|(?:\{(?:[^{}]|\{[^{}]*\})*\}))*\}/s', $raw, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }

            // 4. Try fixing common issues: trailing commas before } or ]
            $fixed = preg_replace('/,\s*([}\]])/', '$1', $matches[0]);
            if ($fixed !== null) {
                $decoded = json_decode($fixed, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }
}
