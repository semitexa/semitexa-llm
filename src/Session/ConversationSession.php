<?php

declare(strict_types=1);

namespace Semitexa\Llm\Session;

final class ConversationSession
{
    /** @var list<array{role: string, content: string}> */
    private array $history = [];

    public function addUserMessage(string $content): void
    {
        $this->history[] = ['role' => 'user', 'content' => $content];
    }

    public function addAssistantMessage(string $content): void
    {
        $this->history[] = ['role' => 'assistant', 'content' => $content];
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
}
