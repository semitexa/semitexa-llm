<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Session\ConversationSession;

final class ConversationSessionTest extends TestCase
{
    public function test_trims_history_when_exceeding_max(): void
    {
        $session = new ConversationSession(maxMessages: 4);

        $session->addUserMessage('msg1');
        $session->addAssistantMessage('reply1');
        $session->addUserMessage('msg2');
        $session->addAssistantMessage('reply2');
        $session->addUserMessage('msg3');

        $this->assertCount(4, $session->getHistory());
        $this->assertSame('reply1', $session->getHistory()[0]['content']);
        $this->assertSame('msg3', $session->getHistory()[3]['content']);
    }

    public function test_clear_resets_history(): void
    {
        $session = new ConversationSession();
        $session->addUserMessage('hello');
        $session->clear();

        $this->assertCount(0, $session->getHistory());
    }

    public function test_default_max_allows_at_least_20_messages(): void
    {
        $session = new ConversationSession();

        for ($i = 0; $i < 10; $i++) {
            $session->addUserMessage("msg{$i}");
            $session->addAssistantMessage("reply{$i}");
        }

        $this->assertCount(20, $session->getHistory());

        $session->addUserMessage('overflow');
        $this->assertCount(20, $session->getHistory());
        $this->assertSame('reply0', $session->getHistory()[0]['content']);
    }

    public function test_count_returns_history_size(): void
    {
        $session = new ConversationSession();
        $this->assertSame(0, $session->count());

        $session->addUserMessage('hello');
        $this->assertSame(1, $session->count());
    }
}
