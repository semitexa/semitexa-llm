<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Failover;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\Failover\FailoverProvider;
use Semitexa\Llm\Application\Service\Failover\LlmProviderHealth;
use Semitexa\Llm\Application\Service\ScriptedProvider;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Enum\LlmFailure;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;

/**
 * Several models as one, the reserves asked when the first fails — made for a
 * guest chat that must never go silent (apartments), kept general: any number
 * of models, a failure kind rather than error text, and models set aside in
 * every worker rather than in the one that saw the refusal.
 */
final class FailoverProviderTest extends TestCase
{
    private LlmProviderHealth $health;

    protected function setUp(): void
    {
        $this->health = new LlmProviderHealth();
    }

    #[Test]
    public function the_first_model_answers_when_it_can(): void
    {
        $claude = new ScriptedProvider(['from claude'], 'anthropic', 'claude-opus-5-5');
        $gemini = new ScriptedProvider(['from gemini'], 'gemini', 'gemini-3.8-flash');

        $response = $this->chain($claude, $gemini)->complete(self::request());

        self::assertSame('from claude', $response->content);
        self::assertSame('anthropic:claude-opus-5-5', $response->servedBy);
        self::assertSame([], $gemini->requests, 'the reserve is not asked');
    }

    #[Test]
    public function each_reserve_is_asked_in_turn_until_one_answers(): void
    {
        $claude = new ScriptedProvider([LlmResponse::failed('HTTP 503', LlmFailure::ServerError)], 'anthropic', 'opus');
        $gemini = new ScriptedProvider([LlmResponse::failed('timed out', LlmFailure::Timeout)], 'gemini', 'flash');
        $local = new ScriptedProvider(['from ollama'], 'local', 'gemma');

        $response = $this->chain($claude, $gemini, $local)->complete(self::request());

        self::assertTrue($response->success);
        self::assertSame('local:gemma', $response->servedBy);
        self::assertFalse($this->health->isBenched('anthropic:opus@'), 'a 503 may pass next time: not set aside');
    }

    #[Test]
    public function a_model_out_of_credit_is_set_aside_and_not_asked_again(): void
    {
        $claude = new ScriptedProvider([LlmResponse::failed('HTTP 400: credit balance is too low', LlmFailure::NoCredit), 'never'], 'anthropic', 'opus');
        $gemini = new ScriptedProvider(['one', 'two'], 'gemini', 'flash');
        $chain = $this->chain($claude, $gemini);

        self::assertSame('one', $chain->complete(self::request())->content);
        self::assertSame('two', $chain->complete(self::request())->content);
        self::assertCount(1, $claude->requests, 'set aside after the first refusal');
        self::assertTrue($this->health->isBenched(FailoverProvider::benchKey($claude)));

        $this->health->reset();
        self::assertSame('never', $chain->complete(self::request())->content, 'asked again once reset');
    }

    #[Test]
    public function every_model_set_aside_means_all_are_asked_anyway(): void
    {
        $claude = new ScriptedProvider(['claude is back'], 'anthropic', 'opus');
        $gemini = new ScriptedProvider([], 'gemini', 'flash');
        $this->health->bench(FailoverProvider::benchKey($claude), 300);
        $this->health->bench(FailoverProvider::benchKey($gemini), 300);

        $response = $this->chain($claude, $gemini)->complete(self::request());

        self::assertSame('claude is back', $response->content, 'set aside is a guess; silence is not an answer');
    }

    #[Test]
    public function a_request_with_tools_goes_only_to_models_that_take_tools(): void
    {
        $textOnly = new TextOnlyProvider();
        $gemini = new ScriptedProvider([new LlmResponse('', true, toolCall: ['name' => 'book', 'arguments' => []])], 'gemini', 'flash');

        $response = $this->chain($textOnly, $gemini)->complete(new LlmRequest('s', 'u', tools: [['name' => 'book', 'description' => 'd', 'parameters' => []]]));

        self::assertSame('book', $response->toolCall['name'] ?? null);
        self::assertSame(0, $textOnly->calls, 'it would have answered in text the caller reads as a tool call');
        self::assertSame('ok', $this->chain($textOnly, $gemini)->complete(self::request())->content, 'without tools it is first again');
    }

    #[Test]
    public function when_all_fail_the_last_failure_comes_back_named(): void
    {
        $response = $this->chain(
            new ScriptedProvider([LlmResponse::failed('a', LlmFailure::ServerError)], 'anthropic', 'opus'),
            new ScriptedProvider([LlmResponse::failed('b', LlmFailure::RateLimited)], 'gemini', 'flash'),
        )->complete(self::request());

        self::assertFalse($response->success);
        self::assertSame(LlmFailure::RateLimited, $response->failure);
        self::assertSame('gemini:flash', $response->servedBy);
    }

    #[Test]
    public function it_names_what_was_asked_for_not_what_answered(): void
    {
        $chain = $this->chain(new ScriptedProvider([], 'anthropic', 'opus'), new ScriptedProvider([], 'gemini', 'flash'));

        self::assertSame(['anthropic', 'opus'], [$chain->name(), $chain->model()]);
        self::assertTrue($chain->supportsTools());
    }

    /** A hung endpoint: each call would wait out its timeout before the reserve. */
    #[Test]
    public function a_model_that_does_not_answer_is_set_aside_briefly(): void
    {
        $claude = new ScriptedProvider([LlmResponse::failed('Operation timed out', LlmFailure::Timeout)], 'anthropic', 'opus');
        $gemini = new ScriptedProvider(['one', 'two'], 'gemini', 'flash');
        $chain = $this->chain($claude, $gemini);

        $chain->complete(self::request());
        $chain->complete(self::request());

        self::assertCount(1, $claude->requests, 'not asked again while set aside');
    }

    #[Test]
    public function the_last_failure_carries_every_model_s_reason(): void
    {
        $response = $this->chain(
            new ScriptedProvider([LlmResponse::failed('HTTP 400', LlmFailure::NoCredit)], 'anthropic', 'opus'),
            new ScriptedProvider([LlmResponse::failed('HTTP 503', LlmFailure::ServerError)], 'gemini', 'flash'),
        )->complete(self::request());

        self::assertStringContainsString('anthropic:opus: no_credit', (string) $response->error, 'the first one\'s "no credit" is the news');
        self::assertSame(LlmFailure::ServerError, $response->failure);
    }

    #[Test]
    public function limits_and_a_model_reach_the_members_that_take_them(): void
    {
        $chain = $this->chain(new ScriptedProvider([], 'anthropic', 'opus'), new TextOnlyProvider())->withModel('haiku');

        self::assertSame(['opus', 'gemma'], array_map(static fn ($p) => $p->model(), $chain->providers()), 'ScriptedProvider cannot switch: kept');
        self::assertInstanceOf(FailoverProvider::class, $chain->withLimits(10, 0, 100, false));
    }

    private function chain(LlmProviderInterface ...$providers): FailoverProvider
    {
        return new FailoverProvider($providers, $this->health, benchSeconds: 300);
    }

    private static function request(): LlmRequest
    {
        return new LlmRequest('system', 'hello');
    }
}

/** A provider without native tools, such as Ollama. */
final class TextOnlyProvider implements LlmProviderInterface
{
    public int $calls = 0;

    public function name(): string { return 'local'; }
    public function baseUrl(): string { return ''; }
    public function model(): string { return 'gemma'; }
    public function healthCheck(): bool { return true; }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->calls++;

        return new LlmResponse('ok', true);
    }
}
