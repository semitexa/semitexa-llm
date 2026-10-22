<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Client\FakeHttpClient;
use Semitexa\Core\Http\Client\HttpResponse;
use Semitexa\Core\Http\Client\HttpTransportKind;
use Semitexa\Core\Http\Exception\HttpTransportException;
use Semitexa\Llm\Application\Service\AnthropicProvider;
use Semitexa\Llm\Application\Service\GeminiProvider;
use Semitexa\Llm\Application\Service\LocalOllamaProvider;
use Semitexa\Llm\Application\Service\RemoteOllamaProvider;
use Semitexa\Llm\Domain\Enum\LlmFailure;
use Semitexa\Llm\Domain\Model\LlmRequest;

/**
 * The providers' HTTP path, through the framework's client. Each provider ran
 * its own curl loop, and none of that path had a test: what was sent, what
 * was retried, how a failure was named.
 */
final class ProviderHttpPathTest extends TestCase
{
    protected function tearDown(): void
    {
        (new \ReflectionProperty(GeminiProvider::class, 'cachedPrefixes'))->setValue(null, []);
        (new \ReflectionProperty(GeminiProvider::class, 'cacheRefusals'))->setValue(null, []);
    }

    #[Test]
    public function claude_sends_its_key_and_reads_the_answer(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200, (string) json_encode([
            'content' => [['type' => 'text', 'text' => 'Привіт']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ])));

        $response = $this->anthropic($http)->complete(new LlmRequest('system', 'hi'));

        self::assertTrue($response->success, (string) $response->error);
        self::assertSame('Привіт', $response->content);
        $request = $http->recorded()[0];
        self::assertSame(['POST', 'https://claude.test/v1/messages'], [$request->method, $request->url]);
        self::assertSame('k', $request->header('x-api-key'));
        self::assertSame('application/json', $request->header('Content-Type'), 'the Messages API refuses a body without it');
        $options = $http->recordedOptions()[0];
        self::assertSame([120.0, 5.0], [$options->timeout, $options->connectTimeout]);
        self::assertSame(3, $options->retry->maxAttempts, 'ANTHROPIC_RETRIES 2: three attempts');
        self::assertTrue($options->retry->appliesTo('POST'), 'a POST to a model is safe to resend');
    }

    #[Test]
    public function a_reserve_s_first_choice_is_sent_once(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(529, '{"error":{"type":"overloaded_error"}}'));

        $response = $this->anthropic($http)->withoutRetries()->complete(new LlmRequest('s', 'u'));

        self::assertSame(LlmFailure::ServerError, $response->failure);
        self::assertFalse($http->recordedOptions()[0]->retry->appliesTo('POST'));
    }

    #[Test]
    public function an_api_refusal_and_a_dead_connection_are_named(): void
    {
        $http = (new FakeHttpClient())->queue(
            HttpResponse::make(400, '{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low"}}'),
            new HttpTransportException(HttpTransportKind::Timeout, 'POST', 'https://claude.test/v1/messages', 'Operation timed out after 120000 milliseconds'),
        );
        $claude = $this->anthropic($http);

        $broke = $claude->complete(new LlmRequest('s', 'u'));
        self::assertSame(LlmFailure::NoCredit, $broke->failure);
        self::assertStringContainsString('credit balance', (string) $broke->error);

        $silent = $claude->complete(new LlmRequest('s', 'u'));
        self::assertSame(LlmFailure::Timeout, $silent->failure);
        self::assertSame('Operation timed out after 120000 milliseconds', $silent->error);
    }

    /** An expired cachedContents answers 4xx: drop it and ask again without it, once. */
    #[Test]
    public function gemini_replays_a_request_whose_cache_expired_upstream(): void
    {
        $http = (new FakeHttpClient())->queue(
            HttpResponse::make(400, '{"error":{"message":"CachedContent not found"}}'),
            HttpResponse::make(200, (string) json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])),
        );
        $gemini = $this->gemini($http);
        $request = new LlmRequest('A long persona', 'hi');
        $fingerprint = (string) (new \ReflectionMethod($gemini, 'prefixFingerprint'))->invoke($gemini, $request);
        (new \ReflectionProperty(GeminiProvider::class, 'cachedPrefixes'))->setValue(null, [$fingerprint => ['name' => 'cachedContents/gone', 'expiresAt' => time() + 3600]]);

        $response = $gemini->complete($request);

        self::assertTrue($response->success, (string) $response->error);
        [$first, $second] = $http->recorded();
        self::assertStringContainsString('cachedContents/gone', $first->body);
        self::assertStringNotContainsString('cachedContents/gone', $second->body, 'replayed self-contained');
        self::assertSame('k', $second->header('x-goog-api-key'));
        self::assertSame([], (new \ReflectionProperty(GeminiProvider::class, 'cachedPrefixes'))->getValue(), 'the dead entry is dropped');
    }

    #[Test]
    public function gemini_replays_on_an_expired_cache_in_either_wording(): void
    {
        $http = (new FakeHttpClient())->queue(
            HttpResponse::make(400, '{"error":{"status":"INVALID_ARGUMENT","message":"Cache content 1234 is expired."}}'),
            HttpResponse::make(200, (string) json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])),
        );
        $gemini = $this->gemini($http);
        $request = new LlmRequest('A long persona', 'hi');
        $fingerprint = (string) (new \ReflectionMethod($gemini, 'prefixFingerprint'))->invoke($gemini, $request);
        (new \ReflectionProperty(GeminiProvider::class, 'cachedPrefixes'))->setValue(null, [$fingerprint => ['name' => 'cachedContents/old', 'expiresAt' => time() + 3600]]);

        self::assertTrue($gemini->complete($request)->success);
        self::assertCount(2, $http->recorded());
    }

    /** A request that is simply bad failed twice, and dropped a good cache for every worker. */
    #[Test]
    public function gemini_does_not_replay_a_bad_request_that_is_not_about_the_cache(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(400, '{"error":{"status":"INVALID_ARGUMENT","message":"Invalid JSON payload received."}}'));
        $gemini = $this->gemini($http);
        $request = new LlmRequest('A long persona', 'hi');
        $fingerprint = (string) (new \ReflectionMethod($gemini, 'prefixFingerprint'))->invoke($gemini, $request);
        $cached = [$fingerprint => ['name' => 'cachedContents/live', 'expiresAt' => time() + 3600]];
        (new \ReflectionProperty(GeminiProvider::class, 'cachedPrefixes'))->setValue(null, $cached);

        $response = $gemini->complete($request);

        self::assertSame(LlmFailure::BadRequest, $response->failure);
        self::assertCount(1, $http->recorded());
        self::assertSame($cached, (new \ReflectionProperty(GeminiProvider::class, 'cachedPrefixes'))->getValue(), 'the cache is kept');
    }

    #[Test]
    public function remote_ollama_without_an_address_is_unconfigured_not_a_network_failure(): void
    {
        $http = new FakeHttpClient();
        $ollama = $this->configure(new RemoteOllamaProvider(), ['baseUrl' => '', 'model' => 'gemma', 'timeout' => 60, 'maxRetries' => 0, 'connectTimeout' => 5, 'http' => $http]);

        self::assertSame(LlmFailure::Unconfigured, $ollama->complete(new LlmRequest('s', 'u'))->failure);
        self::assertSame([], $http->recorded());
    }

    #[Test]
    public function gemini_replays_once_and_no_more(): void
    {
        $http = (new FakeHttpClient())->queue(
            HttpResponse::make(400, '{"error":{"message":"CachedContent not found"}}'),
            HttpResponse::make(400, '{"error":{"message":"still refused"}}'),
            HttpResponse::make(200, '{}'),
        );
        $gemini = $this->gemini($http);
        $request = new LlmRequest('A long persona', 'hi');
        $fingerprint = (string) (new \ReflectionMethod($gemini, 'prefixFingerprint'))->invoke($gemini, $request);
        (new \ReflectionProperty(GeminiProvider::class, 'cachedPrefixes'))->setValue(null, [$fingerprint => ['name' => 'cachedContents/gone', 'expiresAt' => time() + 3600]]);

        $response = $gemini->complete($request);

        self::assertSame(LlmFailure::BadRequest, $response->failure);
        self::assertCount(2, $http->recorded(), 'one replay, then the failure stands');
    }

    #[Test]
    public function gemini_makes_its_cache_in_one_attempt(): void
    {
        $http = (new FakeHttpClient())->queue(
            HttpResponse::make(200, '{"name":"cachedContents/new"}'),
            HttpResponse::make(200, (string) json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])),
        );

        $response = $this->gemini($http)->complete(new LlmRequest('A long persona', 'hi'));

        self::assertTrue($response->success, (string) $response->error);
        self::assertStringEndsWith('/cachedContents', $http->recorded()[0]->url);
        self::assertSame(1, $http->recordedOptions()[0]->retry->maxAttempts, 'a cache that cannot be made is only a missed saving');
        self::assertStringContainsString('cachedContents/new', $http->recorded()[1]->body);
    }

    /** A key read with a trailing CR: the request cannot be built — a failure a reserve can answer, not an exception. */
    #[Test]
    public function a_key_with_a_line_break_fails_the_call_instead_of_throwing(): void
    {
        $http = new FakeHttpClient();
        $claude = $this->anthropic($http);
        (new \ReflectionProperty($claude, 'apiKey'))->setValue($claude, "k\r");

        $response = $claude->complete(new LlmRequest('s', 'u'));

        self::assertSame(LlmFailure::Unconfigured, $response->failure);
        self::assertFalse($claude->healthCheck());
        self::assertSame([], $http->recorded(), 'nothing was sent');
    }

    #[Test]
    public function a_timeout_of_zero_is_an_hour_not_one_second(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200, '{"message":{"content":"ok"}}'));
        $ollama = $this->configure(new LocalOllamaProvider(), ['baseUrl' => 'http://ollama.test', 'model' => 'gemma', 'timeout' => 0, 'maxRetries' => 0, 'connectTimeout' => 5, 'http' => $http]);

        $ollama->complete(new LlmRequest('s', 'u'));

        self::assertSame(3600.0, $http->recordedOptions()[0]->timeout);
    }

    #[Test]
    public function a_transport_failure_has_a_kind(): void
    {
        self::assertSame(LlmFailure::Timeout, LlmFailure::fromTransport(HttpTransportKind::Timeout));
        self::assertSame(LlmFailure::Network, LlmFailure::fromTransport(HttpTransportKind::Connect));
        self::assertSame(LlmFailure::Network, LlmFailure::fromTransport(HttpTransportKind::Tls));
        self::assertSame(LlmFailure::Malformed, LlmFailure::fromTransport(HttpTransportKind::TooLarge), 'an answer past 16 MiB is no answer');
    }

    #[Test]
    public function ollama_reads_its_answer_and_names_a_missing_model(): void
    {
        foreach ([new LocalOllamaProvider(), new RemoteOllamaProvider()] as $ollama) {
            $http = (new FakeHttpClient())->queue(
                HttpResponse::make(200, '{"message":{"content":"ok"}}'),
                HttpResponse::make(404, '{"error":"model \'gemma\' not found, try pulling it first"}'),
                HttpResponse::make(200, '{"models":[{"name":"gemma:latest"}]}'),
            );
            $this->configure($ollama, ['baseUrl' => 'http://ollama.test', 'model' => 'gemma', 'timeout' => 60, 'maxRetries' => 1, 'connectTimeout' => 5, 'http' => $http]);

            self::assertSame('ok', $ollama->complete(new LlmRequest('s', 'u'))->content, $ollama::class);
            $missing = $ollama->complete(new LlmRequest('s', 'u'));
            self::assertSame(LlmFailure::Unconfigured, $missing->failure, $ollama::class);
            self::assertTrue($ollama->healthCheck(), $ollama::class . ': gemma:latest counts as gemma');
            self::assertSame('http://ollama.test/api/chat', $http->recorded()[0]->url);
            self::assertSame([3.0, 5.0], [$http->recordedOptions()[2]->connectTimeout, $http->recordedOptions()[2]->timeout], 'the probe stays cheap');
        }
    }

    private function anthropic(FakeHttpClient $http): AnthropicProvider
    {
        return $this->configure(new AnthropicProvider(), [
            'apiKey' => 'k', 'baseUrl' => 'https://claude.test/v1', 'model' => 'claude-opus-5-5', 'timeout' => 120,
            'maxRetries' => 2, 'connectTimeout' => 5, 'maxTokens' => 1000, 'effort' => '', 'http' => $http,
        ]);
    }

    private function gemini(FakeHttpClient $http): GeminiProvider
    {
        return $this->configure(new GeminiProvider(), [
            'apiKey' => 'k', 'baseUrl' => 'https://gemini.test/v1beta', 'model' => 'gemini-2.5-flash', 'timeout' => 120,
            'maxRetries' => 2, 'connectTimeout' => 5, 'contextCache' => true, 'contextCacheTtl' => 3600,
            'contextCacheMinChars' => 1, 'http' => $http,
        ]);
    }

    /**
     * @template T of object
     * @param T $provider
     * @param array<string, mixed> $values
     * @return T
     */
    private function configure(object $provider, array $values): object
    {
        foreach ($values as $property => $value) {
            (new \ReflectionProperty($provider, $property))->setValue($provider, $value);
        }

        return $provider;
    }
}
