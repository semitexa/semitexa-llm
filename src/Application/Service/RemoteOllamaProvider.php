<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Enum\LlmBackend;
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

    /**
     * Clone-only tuning (never injected). null $maxTokens = no `num_predict` cap;
     * $thinking = false sends `think:false` so a thinking-capable model spends its
     * token budget on the answer, not on a reasoning trace we don't consume.
     */
    protected ?int $maxTokens = null;
    protected bool $thinking = true;

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
        $ch = curl_init($this->baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return false;
        }

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
                latencyMs: 0,
            );
        }

        $attempts = 0;
        $requestStart = hrtime(true);
        $maxAttempts = max(1, $this->maxRetries + 1);

        while (true) {
            $attempts++;

            $ch = curl_init($this->baseUrl . '/api/chat');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $isTransientFailure = $response === false || $httpCode >= 500;

            if ($isTransientFailure && $attempts < $maxAttempts) {
                usleep($attempts * 500_000);
                continue;
            }

            $latencyMs = (hrtime(true) - $requestStart) / 1_000_000;

            if ($response === false || $httpCode !== 200) {
                $detail = is_string($response) && $response !== '' ? ': ' . substr($response, 0, 300) : '';
                return new LlmResponse(
                    content: '',
                    success: false,
                    error: $curlError !== '' ? $curlError : "HTTP {$httpCode}{$detail}",
                    latencyMs: $latencyMs,
                );
            }

            break;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['message']['content'])) {
            return new LlmResponse(
                content: '',
                success: false,
                error: 'Invalid response structure from Ollama',
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
}
