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

#[SatisfiesServiceContract(of: LlmProviderInterface::class, factoryKey: LlmBackend::Local)]
final class LocalOllamaProvider implements LlmProviderInterface
{
    #[Config(env: 'LLM_BASE_URL', default: 'http://127.0.0.1:11434')]
    protected string $baseUrl;

    #[Config(env: 'LLM_MODEL', default: 'gemma3:4b')]
    protected string $model;

    #[Config(env: 'LLM_TIMEOUT', default: 60)]
    protected int $timeout;

    #[Config(env: 'LLM_RETRIES', default: 1)]
    protected int $maxRetries;

    #[InjectAsReadonly]
    protected HttpClientInterface $http;

    /**
     * The connect phase only — how long to wait for the TCP handshake, separate from
     * {@see $timeout}, which budgets generation and has to stay generous for a slow
     * local model. Splitting them matters because the two failures are nothing alike:
     * a model thinking for 60s is working, while a host that has gone away never
     * completes the handshake at all and costs the full connect timeout on EVERY
     * attempt, retries included. That bill is paid on the worker boot path too, via
     * the OS planner warm-up, so a stale LLM_BASE_URL used to be enough to keep a
     * Swoole worker dying and respawning indefinitely.
     */
    #[Config(env: 'LLM_CONNECT_TIMEOUT', default: 5)]
    protected int $connectTimeout;

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
        return 'ollama';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function model(): string
    {
        return $this->model;
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
        $messages = [];

        if ($request->systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }

        foreach ($request->history as $entry) {
            $messages[] = $entry;
        }

        $messages[] = ['role' => 'user', 'content' => $request->userMessage];

        $payload = json_encode([
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

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
