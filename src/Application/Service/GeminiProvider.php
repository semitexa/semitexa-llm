<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Enum\LlmBackend;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;

/**
 * Google Gemini provider — talks to the Generative Language REST API
 * (`{baseUrl}/models/{model}:generateContent`), authenticating with the
 * `x-goog-api-key` header. Selected via `LLM_BACKEND=gemini`.
 *
 * The API key comes from Google AI Studio (https://aistudio.google.com/apikey),
 * NOT from a Google One subscription — the free tier is enough for development,
 * and pay-as-you-go billing (separate from Google One) lifts the rate limits.
 *
 * Gemini's wire shape differs from Ollama: the system prompt is a top-level
 * `systemInstruction`, turns live under `contents[]` with roles `user`/`model`
 * (there is no `assistant`/`system` turn role), and the answer is a concatenation
 * of `candidates[0].content.parts[].text`. This class is the only place that
 * knowledge lives — the rest of the module speaks `LlmRequest`/`LlmResponse`.
 */
#[SatisfiesServiceContract(of: LlmProviderInterface::class, factoryKey: LlmBackend::Gemini)]
final class GeminiProvider implements LlmProviderInterface
{
    #[Config(env: 'GEMINI_API_KEY', default: '')]
    protected string $apiKey;

    #[Config(env: 'GEMINI_BASE_URL', default: 'https://generativelanguage.googleapis.com/v1beta')]
    protected string $baseUrl;

    #[Config(env: 'GEMINI_MODEL', default: 'gemini-2.5-flash')]
    protected string $model;

    #[Config(env: 'GEMINI_TIMEOUT', default: 120)]
    protected int $timeout;

    #[Config(env: 'GEMINI_RETRIES', default: 2)]
    protected int $maxRetries;

    /**
     * Clone-only tuning (never injected). null $maxTokens = no `maxOutputTokens`
     * cap; $thinking = false sends `thinkingConfig.thinkingBudget = 0` so a
     * thinking-capable model (e.g. gemini-2.5-flash) spends its output budget on
     * the answer, not on a reasoning trace we don't consume.
     */
    protected ?int $maxTokens = null;
    protected bool $thinking = true;

    public function name(): string
    {
        return 'gemini';
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
     * `maxOutputTokens` cap and thinking toggle) — mirrors the Ollama providers so
     * callers that must not hang on a slow model (e.g. the skin seed picker) get
     * the same seam regardless of the active backend.
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
        if ($this->apiKey === '') {
            return false;
        }

        $ch = curl_init($this->baseUrl . '/models/' . rawurlencode($this->model));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $this->apiKey],
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

        return is_array($decoded) && isset($decoded['name']);
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($this->apiKey === '') {
            return new LlmResponse(
                content: '',
                success: false,
                error: 'GEMINI_API_KEY is not configured',
                latencyMs: 0,
            );
        }

        $contents = [];

        foreach ($request->history as $entry) {
            $role = ($entry['role'] === 'assistant' || $entry['role'] === 'model') ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => $entry['content']]]];
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $request->userMessage]]];

        $body = ['contents' => $contents];

        if ($request->systemPrompt !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $request->systemPrompt]]];
        }

        // Native function-calling: expose the caller's tools so the model returns a
        // structured `functionCall` (name + typed args) instead of JSON-in-text we
        // would have to parse and salvage. Only set when tools are supplied, so the
        // plain completion path is byte-identical to before.
        if ($request->tools !== []) {
            $body['tools'] = [['functionDeclarations' => $request->tools]];
        }

        $generationConfig = [];
        if ($this->maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $this->maxTokens;
        }
        if (!$this->thinking) {
            // Only sent when explicitly disabled — a thinking-capable model would
            // otherwise spend part of its output budget on the reasoning trace.
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
        }
        if ($generationConfig !== []) {
            $body['generationConfig'] = $generationConfig;
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

        $url = $this->baseUrl . '/models/' . rawurlencode($this->model) . ':generateContent';

        $attempts = 0;
        $requestStart = hrtime(true);
        $maxAttempts = max(1, $this->maxRetries + 1);

        while (true) {
            $attempts++;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->apiKey,
                ],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // 429 (rate limit) and 5xx are transient; 4xx auth/quota errors are not.
            $isTransientFailure = $response === false || $httpCode >= 500 || $httpCode === 429;

            if ($isTransientFailure && $attempts < $maxAttempts) {
                usleep($attempts * 500_000); // 0.5s, 1s, 1.5s backoff
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
        if (!is_array($decoded)) {
            return new LlmResponse(
                content: '',
                success: false,
                error: 'Invalid response structure from Gemini',
                latencyMs: $latencyMs,
            );
        }

        // A prompt blocked before generation carries no candidates, only feedback.
        if (isset($decoded['promptFeedback']['blockReason'])) {
            return new LlmResponse(
                content: '',
                success: false,
                error: 'Prompt blocked by Gemini: ' . (string) $decoded['promptFeedback']['blockReason'],
                latencyMs: $latencyMs,
            );
        }

        $parts = $decoded['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            $finishReason = $decoded['candidates'][0]['finishReason'] ?? 'unknown';
            return new LlmResponse(
                content: '',
                success: false,
                error: 'Gemini returned no content (finishReason: ' . (string) $finishReason . ')',
                latencyMs: $latencyMs,
            );
        }

        $content = '';
        $toolCall = null;
        foreach ($parts as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $content .= $part['text'];
            }
            // Gemini names the call `functionCall` and its arguments `args`; we
            // normalise to the provider-agnostic {name, arguments} shape. First
            // call wins — the planner acts on exactly one tool per turn.
            if ($toolCall === null
                && isset($part['functionCall']['name'])
                && is_string($part['functionCall']['name'])
            ) {
                $args = $part['functionCall']['args'] ?? [];
                $toolCall = [
                    'name' => $part['functionCall']['name'],
                    'arguments' => is_array($args) ? $args : [],
                ];
            }
        }

        $tokensUsed = null;
        if (isset($decoded['usageMetadata']['candidatesTokenCount'])) {
            $tokensUsed = (int) $decoded['usageMetadata']['candidatesTokenCount'];
        }

        return new LlmResponse(
            content: $content,
            success: true,
            tokensUsed: $tokensUsed,
            latencyMs: $latencyMs,
            toolCall: $toolCall,
        );
    }
}
