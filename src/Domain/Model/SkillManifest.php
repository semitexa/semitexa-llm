<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Model;

final readonly class SkillManifest
{
    /**
     * @param list<SkillEntry> $skills
     * @param list<string>|null $channels the surfaces this manifest was scoped to,
     *        or null when it was never scoped. A manifest handed to a planner is
     *        the list of things that planner may propose, so "which surface is this
     *        for" is part of what it IS, not a fact the caller has to remember
     *        separately — see {@see isScoped()}.
     */
    public function __construct(
        public string $artifact,
        public string $generatedAt,
        public array $skills,
        public ?array $channels = null,
    ) {}

    /**
     * Whether this manifest has been narrowed to a surface. An unscoped manifest is
     * every skill the tenant owns, console-only ones included, which is the right
     * answer for tooling and the wrong answer for anything that plans or lists.
     */
    public function isScoped(): bool
    {
        return $this->channels !== null;
    }

    public function toArray(): array
    {
        return [
            'artifact' => $this->artifact,
            'generated_at' => $this->generatedAt,
            'skills' => array_map(fn(SkillEntry $s) => $s->toArray(), $this->skills),
            'channels' => $this->channels,
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

        return new self($this->artifact, $this->generatedAt, $kept, array_values($channels));
    }

    public function findSkill(string $name): ?SkillEntry
    {
        foreach ($this->skills as $skill) {
            if ($skill->name === $name) {
                return $skill;
            }
        }

        // Model drift tolerance: planners routinely emit 'attach_folder' for
        // 'attach-folder' (or vary case). Normalise separators and case before
        // giving up — names stay canonical, only the LOOKUP is forgiving.
        $loose = self::looseName($name);
        foreach ($this->skills as $skill) {
            if (self::looseName($skill->name) === $loose) {
                return $skill;
            }
        }

        return null;
    }

    private static function looseName(string $name): string
    {
        return strtolower(str_replace('_', '-', trim($name)));
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
