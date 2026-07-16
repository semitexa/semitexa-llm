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
 *
 * CONTEXT CACHING. Two layers, both surfaced via `LlmResponse::$cachedTokens`:
 *
 * - Implicit (Google-side, free, on by default for Gemini 2.5+): requests whose
 *   token PREFIX matches a recent request get the cached part discounted. The
 *   provider can't opt in or out — what makes hits happen is callers keeping
 *   the prefix stable: static persona/rules first, volatile lines (dates, user
 *   profile) at the very end, history append-only. Minimum prompt size applies
 *   (2048 tokens on gemini-2.5-flash), so small prompts never hit it.
 *
 * - Explicit (`GEMINI_CONTEXT_CACHE=true`, off by default): the static prefix
 *   (systemInstruction + tools) is uploaded once as a `cachedContents` object
 *   with a TTL and referenced by name on every call, so its tokens are billed
 *   at the cached rate regardless of request timing. The cache object is
 *   immutable — per-conversation caching of growing history is deliberately
 *   NOT attempted (it would recreate the cache every turn and cost more in
 *   storage than it saves). Only prefixes at least
 *   GEMINI_CONTEXT_CACHE_MIN_CHARS long are cached: Gemini rejects caches
 *   below the model minimum (2048 tokens on 2.5 Flash), and tiny prefixes
 *   aren't worth a storage-billed object. Cache names are remembered per
 *   worker; expiry, deletion upstream, or refusal to cache all degrade
 *   gracefully to the uncached path.
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

    #[Config(env: 'GEMINI_CONTEXT_CACHE', default: false)]
    protected bool $contextCache;

    #[Config(env: 'GEMINI_CONTEXT_CACHE_TTL', default: 3600)]
    protected int $contextCacheTtl;

    /**
     * Prefixes shorter than this many characters are never cached explicitly.
     * Cyrillic runs ~2 chars/token, English ~4, so the 6000 default clears the
     * 2048-token model minimum for the mixed prompts Solomiia-style assistants
     * send, without attempting doomed cache creates for small prompts.
     */
    #[Config(env: 'GEMINI_CONTEXT_CACHE_MIN_CHARS', default: 6000)]
    protected int $contextCacheMinChars;

    /**
     * Clone-only tuning (never injected). null $maxTokens = no `maxOutputTokens`
     * cap; $thinking = false sends `thinkingConfig.thinkingBudget = 0` so a
     * thinking-capable model (e.g. gemini-2.5-flash) spends its output budget on
     * the answer, not on a reasoning trace we don't consume.
     */
    protected ?int $maxTokens = null;
    protected bool $thinking = true;

    /**
     * Worker-local registry of live explicit caches: prefix fingerprint →
     * `cachedContents/...` name + unix expiry. Static so every clone (and every
     * request the worker serves) reuses one upload; each Swoole worker keeps its
     * own copy, which at worst duplicates a cheap cache object per worker.
     *
     * @var array<string, array{name: string, expiresAt: int}>
     */
    private static array $cachedPrefixes = [];

    /**
     * Prefix fingerprints Gemini refused to cache (e.g. below the model's token
     * minimum) → unix time until which we won't retry creating them. Prevents a
     * failed create from being re-attempted on every single completion.
     *
     * @var array<string, int>
     */
    private static array $cacheRefusals = [];

    private const CACHE_REFUSAL_BACKOFF_S = 600;

    /** Reuse a cache only while it has at least this long left to live. */
    private const CACHE_EXPIRY_MARGIN_S = 30;

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

    /**
     * A clone that talks to a DIFFERENT Gemini model — the seam for two-tier
     * routing (RFC-002 §4.1): a cheap/fast model (e.g. gemini-2.5-flash-lite)
     * for silent classification/tool-picking, the strong model for the reply.
     * Everything else (key, base URL, caching) is inherited; the context cache
     * keys on the model, so each tier gets its own cache. A blank model is a
     * no-op, so callers can pass an unset config safely.
     */
    public function withModel(string $model): self
    {
        $model = trim($model);
        if ($model === '' || $model === $this->model) {
            return $this;
        }
        $c = clone $this;
        $c->model = $model;

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

        // Explicit context caching: reference the (already or freshly) uploaded
        // static prefix instead of resending it. Any failure along the way simply
        // leaves $cachedContent null and the request goes out self-contained.
        $fingerprint = null;
        $cachedContent = null;
        if ($this->contextCache && $request->systemPrompt !== '' && $this->prefixSize($request) >= $this->contextCacheMinChars) {
            $fingerprint = $this->prefixFingerprint($request);
            $cachedContent = $this->ensureCachedPrefix($fingerprint, $request);
        }

        $payload = $this->encodeRequestBody($request, $cachedContent);

        if ($payload === null) {
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

            // A non-transient 4xx while referencing a cache usually means the
            // cachedContents object expired or was deleted upstream — drop it
            // and replay the same request self-contained (one extra attempt at
            // most, since $cachedContent is now null). The next completion will
            // recreate the cache.
            if ($cachedContent !== null && $response !== false && $httpCode >= 400 && $httpCode < 500 && $httpCode !== 429) {
                unset(self::$cachedPrefixes[$fingerprint]);
                $cachedContent = null;
                $retryPayload = $this->encodeRequestBody($request, null);
                if ($retryPayload !== null) {
                    $payload = $retryPayload;
                    continue;
                }
            }

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

        // Full usage picture: output tokens (tokensUsed, as before), input tokens,
        // and how many input tokens the context cache served — the field to watch
        // when verifying that prompts are actually cache-friendly.
        $usage = is_array($decoded['usageMetadata'] ?? null) ? $decoded['usageMetadata'] : [];
        $tokensUsed = isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null;
        $promptTokens = isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null;
        $cachedTokens = isset($usage['cachedContentTokenCount']) ? (int) $usage['cachedContentTokenCount'] : null;

        return new LlmResponse(
            content: $content,
            success: true,
            tokensUsed: $tokensUsed,
            latencyMs: $latencyMs,
            toolCall: $toolCall,
            promptTokens: $promptTokens,
            cachedTokens: $cachedTokens,
        );
    }

    /**
     * The request body as JSON, or null when encoding fails. With a cache name,
     * `cachedContent` replaces `systemInstruction` + `tools` — Gemini rejects a
     * request that carries both the cache reference and the fields it contains.
     */
    private function encodeRequestBody(LlmRequest $request, ?string $cachedContent): ?string
    {
        $contents = [];

        foreach ($request->history as $entry) {
            $role = ($entry['role'] === 'assistant' || $entry['role'] === 'model') ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => $entry['content']]]];
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $request->userMessage]]];

        $body = ['contents' => $contents];

        if ($cachedContent !== null) {
            $body['cachedContent'] = $cachedContent;
        } else {
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

        return $payload === false ? null : $payload;
    }

    /** Rough size of the cacheable prefix (system prompt + tool declarations). */
    private function prefixSize(LlmRequest $request): int
    {
        $toolsJson = $request->tools !== [] ? (string) json_encode($request->tools, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : '';

        return strlen($request->systemPrompt) + strlen($toolsJson);
    }

    /** Identity of the cacheable prefix: same model + system + tools ⇒ same cache. */
    private function prefixFingerprint(LlmRequest $request): string
    {
        $toolsJson = $request->tools !== [] ? (string) json_encode($request->tools, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : '';

        return sha1($this->model . '|' . $this->contextCacheTtl . '|' . $request->systemPrompt . '|' . $toolsJson);
    }

    /**
     * The live `cachedContents/...` name for this prefix — reused while valid,
     * created via the API when absent, null when Gemini can't or won't cache it
     * (below the model's token minimum, quota, transient failure). A refusal is
     * remembered for a while so we don't pay a failed create per completion.
     */
    private function ensureCachedPrefix(string $fingerprint, LlmRequest $request): ?string
    {
        $now = time();

        $entry = self::$cachedPrefixes[$fingerprint] ?? null;
        if ($entry !== null && $entry['expiresAt'] - self::CACHE_EXPIRY_MARGIN_S > $now) {
            return $entry['name'];
        }
        unset(self::$cachedPrefixes[$fingerprint]);

        if ((self::$cacheRefusals[$fingerprint] ?? 0) > $now) {
            return null;
        }

        $body = [
            'model' => 'models/' . $this->model,
            'systemInstruction' => ['parts' => [['text' => $request->systemPrompt]]],
            'ttl' => $this->contextCacheTtl . 's',
        ];
        if ($request->tools !== []) {
            $body['tools'] = [['functionDeclarations' => $request->tools]];
        }

        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            self::$cacheRefusals[$fingerprint] = $now + self::CACHE_REFUSAL_BACKOFF_S;

            return null;
        }

        $ch = curl_init($this->baseUrl . '/cachedContents');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => min($this->timeout, 20),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        $name = is_array($decoded) && isset($decoded['name']) && is_string($decoded['name']) ? $decoded['name'] : null;

        if ($httpCode !== 200 || $name === null) {
            self::$cacheRefusals[$fingerprint] = $now + self::CACHE_REFUSAL_BACKOFF_S;

            return null;
        }

        unset(self::$cacheRefusals[$fingerprint]);
        self::$cachedPrefixes[$fingerprint] = ['name' => $name, 'expiresAt' => $now + $this->contextCacheTtl];

        return $name;
    }
}
