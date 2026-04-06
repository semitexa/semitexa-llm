<?php

declare(strict_types=1);

namespace Semitexa\Llm\Session;

final class ConversationSession
{
    private const DEFAULT_MAX_MESSAGES = 20;

    /** @var list<array{role: string, content: string}> */
    private array $history = [];

    public function __construct(
        private readonly int $maxMessages = self::DEFAULT_MAX_MESSAGES,
    ) {}

    public function addUserMessage(string $content): void
    {
        $this->history[] = ['role' => 'user', 'content' => $content];
        $this->trim();
    }

    public function addAssistantMessage(string $content): void
    {
        $this->history[] = ['role' => 'assistant', 'content' => $content];
        $this->trim();
    }

    /** @return list<array{role: string, content: string}> */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function clear(): void
    {
        $this->history = [];
    }

    public function count(): int
    {
        return count($this->history);
    }

    private function trim(): void
    {
        if (count($this->history) > $this->maxMessages) {
            $this->history = array_values(array_slice($this->history, -$this->maxMessages));
        }
    }
}
