<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Prompt;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\Prompt\PromptRequestFactory;
use Semitexa\Prompt\Domain\Model\PromptMessage;
use Semitexa\Prompt\Domain\Model\RenderedPrompt;

final class PromptRequestFactoryTest extends TestCase
{
    public function testMapsSystemAndUserMessage(): void
    {
        $rendered = new RenderedPrompt(promptId: 'p', system: 'You are Solomiia.');

        $request = (new PromptRequestFactory())->fromRendered($rendered, 'Hello');

        self::assertSame('You are Solomiia.', $request->systemPrompt);
        self::assertSame('Hello', $request->userMessage);
        self::assertSame([], $request->history);
        self::assertSame([], $request->tools);
    }

    public function testFewShotBecomesLeadingHistory(): void
    {
        $rendered = new RenderedPrompt(
            promptId: 'p',
            system: 'sys',
            messages: [
                PromptMessage::user('example in'),
                PromptMessage::assistant('example out'),
            ],
        );

        $request = (new PromptRequestFactory())->fromRendered($rendered, 'live turn');

        self::assertSame([
            ['role' => 'user', 'content' => 'example in'],
            ['role' => 'assistant', 'content' => 'example out'],
        ], $request->history);
        self::assertSame('live turn', $request->userMessage);
    }

    public function testToolsArePassedThrough(): void
    {
        $rendered = new RenderedPrompt(promptId: 'p', system: 'sys');
        $tools = [['name' => 'search', 'description' => 'find', 'parameters' => []]];

        $request = (new PromptRequestFactory())->fromRendered($rendered, 'q', $tools);

        self::assertSame($tools, $request->tools);
    }
}
