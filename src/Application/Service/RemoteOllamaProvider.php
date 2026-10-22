<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Http\Client\CurlHttpClient;
use Semitexa\Core\Http\Client\HttpClientInterface;
use Semitexa\Llm\Application\Service\Http\ProviderHttp;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Enum\LlmBackend;
use Semitexa\Llm\Domain\Enum\LlmFailure;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;

#[SatisfiesServiceContract(of: LlmProviderInterface::class, factoryKey: LlmBackend::RemoteOllama)]
final class RemoteOllamaProvider implements LlmProviderInterface
{
    #[Config(env: 'LLM_REMOTE_OLLAMA_URL', default: '')]
    protected string $baseUrl;

    #[Config(env: 'LLM_REMOTE_OLLAMA_MODEL', default: 'gemma4:e2b')]
    protected string $model;

    #[Config(env: 'LLM_REMOTE_OLLAMA_TIMEOUT', default: 120)]
    protected int $timeout;

    #[Config(env: 'LLM_REMOTE_OLLAMA_RETRIES', default: 2)]
    protected int $maxRetries;

    #[InjectAsReadonly]
    protected HttpClientInterface $http;

    /**
     * Connect phase only — see the note on {@see LocalOllamaProvider::$connectTimeout}.
     * A generous generation budget must not also become the price of an endpoint that
     * has gone away and never answers the handshake.
     */
    #[Config(env: 'LLM_REMOTE_OLLAMA_CONNECT_TIMEOUT', default: 5)]
    protected int $connectTimeout;

    /**
     * Clone-only tuning (never injected). null $maxTokens = no `num_predict` cap;
     * $thinking = false sends `think:false` so a thinking-capable model spends its
     * token budget on the answer, not on a reasoning trace we don't consume.
     */
    protected ?int $maxTokens = null;
    protected bool $thinking = true;

    /**
     * Gives up after the first failure: a model with a reserve behind it hands
     * over at once instead of retrying (FailoverProvider, LlmProviderResolver::route()).
     */
    public function withoutRetries(): self
    {
        $c = clone $this;
        $c->maxRetries = 0;

        return $c;
    }

    public function name(): string
    {
        return 'remote_ollama';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * A clone with a bounded per-call timeout + retry count (and, optionally, a
     * `num_predict` cap and thinking toggle) — for callers that must not hang a
     * request on a slow model (e.g. the skin seed picker, which asks for one short
     * answer then falls back). The shared worker provider keeps its own limits.
     */
    public function withLimits(int $timeoutSeconds, int $maxRetries, ?int $maxTokens = null, bool $thinking = true): self
    {
        $c = clone $this;
        $c->timeout = max(1, $timeoutSeconds);
        $c->maxRetries = max(0, $maxRetries);
        $c->maxTokens = $maxTokens !== null ? max(1, $maxTokens) : null;
        $c->thinking = $thinking;

        return $c;
    }

    public function healthCheck(): bool
    {
        $answer = ProviderHttp::get($this->http(), $this->baseUrl . '/api/tags', [], timeout: 5, connectTimeout: 3);
        if ($answer['status'] !== 200) {
            return false;
        }
        $response = $answer['body'];

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['models'])) {
            return false;
        }

        $availableModels = array_column($decoded['models'], 'name');

        return in_array($this->model, $availableModels, true)
            || in_array($this->model . ':latest', $availableModels, true);
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        // Without an address curl fails with "no host part": read as a
        // network failure, the model was set aside for seconds and asked again forever.
        if (trim($this->baseUrl) === '') {
            return new LlmResponse(content: '', success: false, error: 'LLM_REMOTE_OLLAMA_URL is not set.', latencyMs: 0, failure: LlmFailure::Unconfigured);
        }

        $messages = [];

        if ($request->systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }

        foreach ($request->history as $entry) {
            $messages[] = $entry;
        }

        $messages[] = ['role' => 'user', 'content' => $request->userMessage];

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
        ];
        if ($this->maxTokens !== null) {
            $body['options'] = ['num_predict' => $this->maxTokens];
        }
        if (!$this->thinking) {
            // Only sent when explicitly disabled — a thinking-capable model would
            // otherwise burn a small num_predict budget on the reasoning trace.
            $body['think'] = false;
        }

        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            return new LlmResponse(
                content: '',
                success: false,
                error: 'Failed to encode request: ' . json_last_error_msg(),
                failure: LlmFailure::BadRequest,
                latencyMs: 0,
            );
        }

        // A connection that failed, a 429 and a 5xx are retried (ProviderHttp).
        $requestStart = hrtime(true);
        $answer = ProviderHttp::post($this->http(), $this->baseUrl . '/api/chat', [], $payload, timeout: $this->timeout, connectTimeout: $this->connectTimeout, retries: $this->maxRetries);
        $latencyMs = (hrtime(true) - $requestStart) / 1_000_000;
        $response = $answer['body'];

        if ($answer['status'] !== 200) {
            $detail = $response !== '' ? ': ' . substr($response, 0, 300) : '';

            return new LlmResponse(
                content: '',
                success: false,
                error: $answer['error'] !== '' ? $answer['error'] : "HTTP {$answer['status']}{$detail}",
                failure: $answer['failure'] ?? LlmFailure::fromHttp($answer['status'], '', $response),
                latencyMs: $latencyMs,
            );
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['message']['content'])) {
            return new LlmResponse(
                content: '',
                success: false,
                error: 'Invalid response structure from Ollama',
                failure: LlmFailure::Malformed,
                latencyMs: $latencyMs,
            );
        }

        $tokensUsed = null;
        if (isset($decoded['eval_count'])) {
            $tokensUsed = (int) $decoded['eval_count'];
        }

        return new LlmResponse(
            content: $decoded['message']['content'],
            success: true,
            tokensUsed: $tokensUsed,
            latencyMs: $latencyMs,
        );
    }

    private function http(): HttpClientInterface
    {
        // Tests build a provider by hand; the container always injects it.
        return isset($this->http) ? $this->http : ($this->http = new CurlHttpClient());
    }
}
