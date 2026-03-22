<?php

declare(strict_types=1);

namespace Semitexa\Llm\Data;

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
                    $args[] = "{$name} ({$meta['type']}, {$req})";
                }
                $lines[] = "  Inputs: " . implode(', ', $args);
            }
        }
        return implode("\n", $lines);
    }
}
