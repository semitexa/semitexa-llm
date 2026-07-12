<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Llm\Domain\Enum\PlannerResponseType;
use Semitexa\Llm\Domain\Model\PlannerResponse;
use Semitexa\Llm\Domain\Model\SkillEntry;
use Semitexa\Llm\Domain\Model\SkillManifest;

/**
 * Bridges the {@see SkillManifest} to native function-calling: it turns the skills
 * into provider-agnostic tool declarations and maps a returned tool call back into
 * a {@see PlannerResponse}.
 *
 * With a tool-calling provider (Gemini) the model returns a STRUCTURED call —
 * function name + typed args validated by the API — instead of JSON-in-text the
 * planner has to extract and salvage. Every decision the JSON contract expressed
 * as a `type` becomes a tool call here: each skill is one tool, and three meta
 * tools ({@see FINAL_ANSWER}/{@see ASK_USER}/{@see REFUSE}) carry the answer / ask
 * / refuse replies, so the model always acts by calling exactly one tool. The
 * `propose_pipeline` type has no tool: the observe→act loop chains skills one call
 * at a time, which is what a static pipeline approximated.
 */
final class PlannerToolSchema
{
    public const FINAL_ANSWER = 'final_answer';
    public const ASK_USER = 'ask_user';
    public const REFUSE = 'refuse_request';

    /** Reserved for the meta tools — a skill can never shadow one of these. */
    private const RESERVED_NAMES = [self::FINAL_ANSWER, self::ASK_USER, self::REFUSE];

    /**
     * Function declarations for the manifest's skills plus the answer/ask/refuse
     * meta tools, in the provider-agnostic shape {@see \Semitexa\Llm\Domain\Model\LlmRequest::$tools}
     * carries.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function declarationsFor(SkillManifest $manifest): array
    {
        $names = $this->assignedNames($manifest);
        $declarations = [];

        foreach ($manifest->skills as $skill) {
            $declarations[] = $this->skillDeclaration($skill, $names[$skill->name]);
        }

        $declarations[] = $this->metaDeclaration(
            self::FINAL_ANSWER,
            'Reply to the user directly with a final answer. Use when no skill is needed, or after skills have produced the result you want to report.',
            'The reply to show the user.',
        );
        $declarations[] = $this->metaDeclaration(
            self::ASK_USER,
            'Ask the user a clarifying question when the request is ambiguous or is missing information a skill requires.',
            'The question to ask the user.',
        );
        $declarations[] = $this->metaDeclaration(
            self::REFUSE,
            'Decline the request for a safety or policy reason.',
            'The explanation of why you cannot help.',
        );

        return $declarations;
    }

    /**
     * Map a provider tool call ({name, arguments}) back to a {@see PlannerResponse}.
     * The meta tools become answer/ask/refuse; anything else is resolved to the
     * canonical skill name via {@see resolveSkillName()} (tool names are sanitized
     * — and disambiguated on collision — so a reverse lookup against the manifest
     * is required, e.g. `os_design-skin` → `os:design-skin`). An unknown tool name
     * refuses rather than guessing.
     *
     * @param array{name: string, arguments: array<string, mixed>} $toolCall
     */
    public function mapToolCall(array $toolCall, SkillManifest $manifest): PlannerResponse
    {
        $name = $toolCall['name'];
        $arguments = $toolCall['arguments'];
        $message = isset($arguments['message']) ? (string) $arguments['message'] : null;

        // $message stays null (not '') when the model omits the "required" arg —
        // required is a schema hint, not an enforced constraint, and models do
        // occasionally omit it. A real null lets SkillLoopRunner::observe()'s
        // `$response->message ?? $response->reason` fallback actually fire; an
        // empty string would short-circuit it and show the user a blank reply.
        if ($name === self::FINAL_ANSWER) {
            return new PlannerResponse(
                type: PlannerResponseType::Answer,
                reason: 'Tool call: final_answer',
                message: $message,
            );
        }
        if ($name === self::ASK_USER) {
            return new PlannerResponse(
                type: PlannerResponseType::Ask,
                reason: 'Tool call: ask_user',
                message: $message,
            );
        }
        if ($name === self::REFUSE) {
            return new PlannerResponse(
                type: PlannerResponseType::Refuse,
                reason: 'Tool call: refuse_request',
                message: $message,
            );
        }

        $canonical = $this->resolveSkillName($name, $manifest);
        if ($canonical === null) {
            return new PlannerResponse(
                type: PlannerResponseType::Refuse,
                reason: 'Tool call named an unknown skill: ' . $name,
                message: 'The assistant tried to use an unavailable skill.',
            );
        }

        return new PlannerResponse(
            type: PlannerResponseType::ProposeSkill,
            skill: $canonical,
            arguments: $arguments,
            reason: 'Tool call: ' . $name,
        );
    }

    /**
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    private function skillDeclaration(SkillEntry $skill, string $toolName): array
    {
        $description = $skill->summary;
        if ($skill->useWhen !== '') {
            $description .= ' Use when: ' . $skill->useWhen;
        }

        $properties = [];
        $required = [];
        foreach ($skill->inputs as $inputName => $meta) {
            $properties[$inputName] = [
                'type' => self::schemaType($meta['type']),
                'description' => $meta['description'],
            ];
            if ($meta['required']) {
                $required[] = $inputName;
            }
        }

        // A no-input skill gets a bare object schema (a valid zero-argument
        // function); Gemini rejects an empty `properties` map, so it is omitted.
        $parameters = ['type' => 'OBJECT'];
        if ($properties !== []) {
            $parameters['properties'] = $properties;
            if ($required !== []) {
                $parameters['required'] = $required;
            }
        }

        return [
            'name' => $toolName,
            'description' => $description,
            'parameters' => $parameters,
        ];
    }

    /**
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    private function metaDeclaration(string $name, string $description, string $messageDescription): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'message' => ['type' => 'STRING', 'description' => $messageDescription],
                ],
                'required' => ['message'],
            ],
        ];
    }

    private function resolveSkillName(string $toolName, SkillManifest $manifest): ?string
    {
        $canonical = array_search($toolName, $this->assignedNames($manifest), true);
        if ($canonical !== false) {
            return $canonical;
        }

        // Fall back to the manifest's own drift-tolerant lookup (handles case /
        // separator variance the model may introduce on top of sanitization).
        return $manifest->findSkill($toolName)?->name;
    }

    /**
     * Deterministically assigns every manifest skill a UNIQUE Gemini-safe tool
     * name. Plain {@see sanitizeName()} is lossy — distinct names can collapse to
     * the same sanitized form (`os:status` and `os_status` both become
     * `os_status`), or share the same 64-char prefix once truncated — so a bare
     * reverse lookup would silently resolve every colliding name to whichever
     * skill happens to come first in the manifest, routing a tool call to the
     * WRONG skill. `RESERVED_NAMES` is seeded first so a skill can never shadow a
     * meta tool either. Any later collision gets a numeric suffix instead.
     *
     * Pure function of `$manifest` (skills in a fixed, stable order) — this is
     * called separately by {@see declarationsFor()} (building declarations) and
     * {@see resolveSkillName()} (mapping a call back), typically on two different
     * instances, so both computations must independently agree on the same
     * assignment rather than relying on shared instance state.
     *
     * @return array<string, string> skill name (canonical) => assigned tool name
     */
    private function assignedNames(SkillManifest $manifest): array
    {
        $used = array_fill_keys(self::RESERVED_NAMES, true);
        $assigned = [];

        foreach ($manifest->skills as $skill) {
            $base = self::sanitizeName($skill->name);
            $candidate = $base;
            for ($suffix = 2; isset($used[$candidate]); $suffix++) {
                $candidate = self::withSuffix($base, (string) $suffix);
            }
            $used[$candidate] = true;
            $assigned[$skill->name] = $candidate;
        }

        return $assigned;
    }

    /**
     * Function names must match Gemini's `[A-Za-z0-9_.-]` charset (skill names like
     * `os:design-skin` and `cache:clear` carry a colon), capped at 64 chars. Lossy
     * by itself (collisions are possible) — always go through
     * {@see assignedNames()} for the collision-free name actually declared/resolved.
     */
    private static function sanitizeName(string $name): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?? $name;

        return substr($sanitized, 0, 64);
    }

    /** Append a disambiguating suffix, re-truncating so the result stays ≤ 64 chars. */
    private static function withSuffix(string $base, string $suffix): string
    {
        $suffix = '_' . $suffix;

        return substr($base, 0, 64 - strlen($suffix)) . $suffix;
    }

    private static function schemaType(string $inputType): string
    {
        return match (strtolower($inputType)) {
            'int', 'integer' => 'INTEGER',
            'float', 'double', 'number' => 'NUMBER',
            'bool', 'boolean' => 'BOOLEAN',
            'array', 'list' => 'ARRAY',
            default => 'STRING',
        };
    }
}
