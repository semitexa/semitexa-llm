<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Model;

final readonly class SkillManifest
{
    /**
     * @param list<SkillEntry> $skills
     */
    public function __construct(
        public string $artifact,
        public string $generatedAt,
        public array $skills,
    ) {}

    public function toArray(): array
    {
        return [
            'artifact' => $this->artifact,
            'generated_at' => $this->generatedAt,
            'skills' => array_map(fn(SkillEntry $s) => $s->toArray(), $this->skills),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A copy keeping only skills exposed on at least one of the given channels.
     * Used to scope a manifest to a surface (e.g. the OS chat gets web + ui skills,
     * never console-only dev commands) — which both narrows routing and shrinks the
     * planner system prompt.
     *
     * @param list<string> $channels
     */
    public function forChannels(array $channels): self
    {
        $kept = array_values(array_filter(
            $this->skills,
            static fn(SkillEntry $s): bool => array_intersect($s->channels, $channels) !== [],
        ));

        return new self($this->artifact, $this->generatedAt, $kept);
    }

    public function findSkill(string $name): ?SkillEntry
    {
        foreach ($this->skills as $skill) {
            if ($skill->name === $name) {
                return $skill;
            }
        }
        return null;
    }

    public function toCompactPrompt(): string
    {
        $lines = ["Available skills:\n"];
        foreach ($this->skills as $skill) {
            $lines[] = "- {$skill->name}: {$skill->summary}";
            $lines[] = "  Use when: {$skill->useWhen}";
            $lines[] = "  Avoid when: {$skill->avoidWhen}";
            if ($skill->inputs) {
                $args = [];
                foreach ($skill->inputs as $name => $meta) {
                    $req = $meta['required'] ? 'required' : 'optional';
                    $desc = $meta['description'] !== '' ? " — {$meta['description']}" : '';
                    $args[] = "{$name} ({$meta['type']}, {$req}){$desc}";
                }
                $lines[] = "  Inputs: " . implode('; ', $args);
            }
        }
        return implode("\n", $lines);
    }
}
