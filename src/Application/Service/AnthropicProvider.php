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
use Semitexa\Llm\Domain\Contract\SupportsToolCallingInterface;
use Semitexa\Llm\Domain\Enum\LlmBackend;
use Semitexa\Llm\Domain\Enum\LlmFailure;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;

/**
 * Anthropic Claude provider — talks to the Messages API (`{baseUrl}/messages`),
 * authenticating with the `x-api-key` header. Selected via `LLM_BACKEND=anthropic`,
 * or asked for by name through {@see LlmProviderResolver::provider()}.
 *
 * The API key comes from the Claude Console (https://platform.claude.com).
 *
 * Wire shape: the system prompt is a top-level `system` array of text blocks,
 * turns live under `messages[]` with roles `user`/`assistant` and the first one
 * must be `user`, and the answer is the `text` blocks of `content[]`. A tool call
 * comes back as a `tool_use` block whose `input` is the arguments object. This
 * class is the only place that knowledge lives — the rest of the module speaks
 * `LlmRequest`/`LlmResponse`.
 *
 * TOOLS. Callers declare tools in the Gemini dialect the module grew up with
 * (`parameters` with upper-case OpenAPI types: OBJECT, STRING…). Claude wants a
 * JSON Schema `input_schema` with lower-case types, so the declarations are
 * translated here rather than asking every caller to write two schemas.
 *
 * THINKING. Current Claude models think adaptively and some cannot switch it
 * off at all, so there is no "no thinking" request to send. `withLimits(...,
 * thinking: false)` instead drops the effort to `low`: the closest thing to
 * Gemini's `thinkingBudget = 0` that every model accepts. Thinking blocks in the
 * response are ignored — callers only consume the text and the tool call.
 *
 * PROMPT CACHING. Two breakpoints, both free to declare: one on the system block
 * (which also covers the tools, rendered before it), so the static persona/rules
 * prefix is read from cache even while the history changes; and the top-level
 * automatic one, which caches the conversation up to the newest message so the
 * next turn of the same conversation pays for the cached part at the read rate.
 * Prefixes below the model's minimum are simply not cached — no error. Watch
 * `LlmResponse::$cachedTokens` to see whether it pays off.
 */
#[SatisfiesServiceContract(of: LlmProviderInterface::class, factoryKey: LlmBackend::Anthropic)]
final class AnthropicProvider implements LlmProviderInterface, SupportsToolCallingInterface
{
    private const API_VERSION = '2023-06-01';

    /** Stands in for the opening user turn when a history starts with the assistant. */
    private const CONVERSATION_START = '[conversation start]';

    #[Config(env: 'ANTHROPIC_API_KEY', default: '')]
    protected string $apiKey;

    #[Config(env: 'ANTHROPIC_BASE_URL', default: 'https://api.anthropic.com/v1')]
    protected string $baseUrl;

    #[Config(env: 'ANTHROPIC_MODEL', default: 'claude-opus-5-5')]
    protected string $model;

    #[Config(env: 'ANTHROPIC_TIMEOUT', default: 120)]
    protected int $timeout;

    #[Config(env: 'ANTHROPIC_RETRIES', default: 2)]
    protected int $maxRetries;

    #[InjectAsReadonly]
    protected HttpClientInterface $http;

    /**
     * Connect phase only — see the note on {@see LocalOllamaProvider::$connectTimeout}.
     * A generous generation budget must not also become the price of an endpoint that
     * has gone away and never answers the handshake.
     */
    #[Config(env: 'ANTHROPIC_CONNECT_TIMEOUT', default: 5)]
    protected int $connectTimeout;

    /**
     * Required by the Messages API. Thinking counts against it, so it is kept
     * well above any reply a caller expects.
     */
    #[Config(env: 'ANTHROPIC_MAX_TOKENS', default: 16000)]
    protected int $maxTokens;

    /**
     * `output_config.effort`: low | medium | high | xhigh | max. Empty = the
     * model's own default (which differs between models), so it is only sent
     * when set.
     */
    #[Config(env: 'ANTHROPIC_EFFORT', default: '')]
    protected string $effort;

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
        return 'anthropic';
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
     * A clone with a bounded per-call timeout + retry count, and optionally a
     * `max_tokens` cap and a thinking toggle — the same seam the other providers
     * offer, so callers that must not hang on a slow model behave the same on
     * every backend. `$thinking = false` means effort `low` (see class doc).
     */
    public function withLimits(int $timeoutSeconds, int $maxRetries, ?int $maxTokens = null, bool $thinking = true): self
    {
        $c = clone $this;
        $c->timeout = max(1, $timeoutSeconds);
        $c->maxRetries = max(0, $maxRetries);
        if ($maxTokens !== null) {
            $c->maxTokens = max(1, $maxTokens);
        }
        if (!$thinking) {
            $c->effort = 'low';
        }

        return $c;
    }

    /**
     * A clone that talks to a DIFFERENT Claude model — the seam for two-tier
     * routing (RFC-002 §4.1): a cheap/fast model for silent classification and
     * tool-picking, the strong one for the reply. A blank model is a no-op.
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

        $answer = ProviderHttp::get($this->http(), $this->baseUrl . '/models/' . rawurlencode($this->model), $this->headers(), timeout: 5, connectTimeout: 3);
        if ($answer['status'] !== 200) {
            return false;
        }

        $decoded = json_decode($answer['body'], true);

        return is_array($decoded) && isset($decoded['id']);
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($this->apiKey === '') {
            return new LlmResponse(
                content: '',
                success: false,
                error: 'ANTHROPIC_API_KEY is not configured',
                failure: LlmFailure::Unconfigured,
                latencyMs: 0,
            );
        }

        $payload = $this->encodeRequestBody($request);
        if ($payload === null) {
            return new LlmResponse(
                content: '',
                success: false,
                error: 'Failed to encode request: ' . json_last_error_msg(),
                failure: LlmFailure::BadRequest,
                latencyMs: 0,
            );
        }

        // 429, 529 (overloaded) and other 5xx are retried; 4xx auth, credit and
        // validation errors are not — retrying them only delays whatever
        // reserve the caller has.
        $requestStart = hrtime(true);
        $answer = ProviderHttp::post($this->http(), $this->baseUrl . '/messages', $this->headers(), $payload, timeout: $this->timeout, connectTimeout: $this->connectTimeout, retries: $this->maxRetries);
        $latencyMs = (hrtime(true) - $requestStart) / 1_000_000;
        $response = $answer['body'];

        if ($answer['status'] !== 200) {
            return new LlmResponse(
                content: '',
                success: false,
                error: $answer['error'] !== '' ? $answer['error'] : "HTTP {$answer['status']}" . $this->errorDetail($response),
                failure: $answer['failure'] ?? LlmFailure::fromHttp($answer['status'], '', $response),
                latencyMs: $latencyMs,
            );
        }

        return $this->decodeResponse($response, $latencyMs);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
        ];
    }

    private function http(): HttpClientInterface
    {
        // Tests build a provider by hand; the container always injects it.
        return isset($this->http) ? $this->http : ($this->http = new CurlHttpClient());
    }

    /** The request body as JSON, or null when encoding fails. */
    private function encodeRequestBody(LlmRequest $request): ?string
    {
        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->messages($request),
            // Automatic breakpoint: caches the conversation up to the newest message.
            'cache_control' => ['type' => 'ephemeral'],
        ];

        if ($request->systemPrompt !== '') {
            $body['system'] = [[
                'type' => 'text',
                'text' => $request->systemPrompt,
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        }

        // Only set when tools are supplied, so the plain completion path carries none.
        if ($request->tools !== []) {
            $body['tools'] = array_map(self::tool(...), $request->tools);
        }

        if (trim($this->effort) !== '') {
            $body['output_config'] = ['effort' => trim($this->effort)];
        }

        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $payload === false ? null : $payload;
    }

    /**
     * History + the new message as Messages API turns. Claude rejects empty text
     * blocks and a conversation that opens with the assistant, while callers'
     * histories may hold both (a greeting the assistant sent first, a blank turn),
     * so empty turns are dropped, same-role neighbours are joined, and an opening
     * assistant turn gets a placeholder user turn in front of it. A blank new
     * message after an assistant turn would leave the assistant last, which Claude
     * reads as a prefill — refused with a 400 by the models that no longer take
     * one — so a placeholder user turn closes the conversation too.
     *
     * @return list<array{role: string, content: string}>
     */
    private function messages(LlmRequest $request): array
    {
        $turns = [];
        foreach ($request->history as $entry) {
            $role = ($entry['role'] === 'assistant' || $entry['role'] === 'model') ? 'assistant' : 'user';
            $turns[] = ['role' => $role, 'content' => $entry['content']];
        }
        $turns[] = ['role' => 'user', 'content' => $request->userMessage];

        /** @var list<array{role: string, content: string}> $messages */
        $messages = [];
        foreach ($turns as $turn) {
            $text = trim($turn['content']);
            if ($text === '') {
                continue;
            }
            $last = array_key_last($messages);
            if ($last !== null && $messages[$last]['role'] === $turn['role']) {
                $messages[$last]['content'] .= "\n\n" . $text;
                continue;
            }
            $messages[] = ['role' => $turn['role'], 'content' => $text];
        }

        if ($messages === [] || $messages[0]['role'] !== 'user') {
            array_unshift($messages, ['role' => 'user', 'content' => self::CONVERSATION_START]);
        }
        if ($messages[array_key_last($messages)]['role'] !== 'user') {
            $messages[] = ['role' => 'user', 'content' => self::CONVERSATION_START];
        }

        return $messages;
    }

    /**
     * One declaration in the module's (Gemini) dialect as a Claude tool.
     *
     * @param array<array-key, mixed> $declaration
     * @return array{name: mixed, description: string, input_schema: array<array-key, mixed>}
     */
    private static function tool(array $declaration): array
    {
        $schema = is_array($declaration['parameters'] ?? null) ? self::schema($declaration['parameters']) : [];
        // The root must be an object schema, even for a tool that takes nothing.
        $schema['type'] = 'object';
        $schema['properties'] ??= new \stdClass();

        return [
            'name' => $declaration['name'] ?? '',
            'description' => is_string($declaration['description'] ?? null) ? $declaration['description'] : '',
            'input_schema' => $schema,
        ];
    }

    /**
     * Gemini/OpenAPI schema → JSON Schema: lower-case types, `nullable` as a
     * union with null, and empty `properties` kept an object on the wire.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private static function schema(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            switch ($key) {
                case 'type':
                    $out['type'] = is_string($value) ? strtolower($value) : $value;
                    break;
                case 'properties':
                    $props = [];
                    foreach (is_array($value) ? $value : [] as $name => $sub) {
                        $props[$name] = is_array($sub) ? self::schema($sub) : $sub;
                    }
                    $out['properties'] = $props === [] ? new \stdClass() : $props;
                    break;
                case 'items':
                    $out['items'] = is_array($value) ? self::schema($value) : $value;
                    break;
                case 'nullable':
                case 'propertyOrdering':
                    break; // folded into `type` below / Gemini-only
                default:
                    $out[$key] = $value;
            }
        }

        if (($schema['nullable'] ?? false) === true && isset($out['type']) && is_string($out['type'])) {
            $out['type'] = [$out['type'], 'null'];
        }

        return $out;
    }

    private function decodeResponse(string $response, float $latencyMs): LlmResponse
    {
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !is_array($decoded['content'] ?? null)) {
            return new LlmResponse(
                content: '',
                success: false,
                error: 'Invalid response structure from Claude',
                failure: LlmFailure::Malformed,
                latencyMs: $latencyMs,
            );
        }

        $stopReason = is_string($decoded['stop_reason'] ?? null) ? $decoded['stop_reason'] : '';

        // A safety decline is an HTTP 200 whose content must not be used; it is a
        // failure to the caller so its own fallback can take over.
        if ($stopReason === 'refusal') {
            $details = $decoded['stop_details'] ?? null;
            $category = is_array($details) ? ($details['category'] ?? null) : null;

            return new LlmResponse(
                content: '',
                success: false,
                error: 'Claude declined the request (refusal' . (is_string($category) ? ": {$category}" : '') . ')',
                failure: LlmFailure::Refused,
                latencyMs: $latencyMs,
            );
        }

        $content = '';
        $toolCall = null;
        foreach ($decoded['content'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $content .= $block['text'];
            }
            // Normalised to the provider-agnostic {name, arguments} shape. First
            // call wins — the planner acts on exactly one tool per turn.
            if ($toolCall === null && ($block['type'] ?? '') === 'tool_use' && is_string($block['name'] ?? null)) {
                /** @var array<string, mixed> $arguments the input object Claude filled against the tool's schema */
                $arguments = is_array($block['input'] ?? null) ? $block['input'] : [];
                $toolCall = ['name' => $block['name'], 'arguments' => $arguments];
            }
        }

        if ($content === '' && $toolCall === null) {
            return new LlmResponse(
                content: '',
                success: false,
                error: 'Claude returned no content (stop_reason: ' . ($stopReason !== '' ? $stopReason : 'unknown') . ')',
                failure: LlmFailure::Malformed,
                latencyMs: $latencyMs,
            );
        }

        // input_tokens counts only the uncached tail; the prompt is all three.
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $uncached = self::count($usage, 'input_tokens') ?? 0;
        $written = self::count($usage, 'cache_creation_input_tokens') ?? 0;
        $read = self::count($usage, 'cache_read_input_tokens') ?? 0;

        return new LlmResponse(
            content: $content,
            success: true,
            tokensUsed: self::count($usage, 'output_tokens'),
            latencyMs: $latencyMs,
            toolCall: $toolCall,
            promptTokens: $usage !== [] ? $uncached + $written + $read : null,
            cachedTokens: $usage !== [] ? $read : null,
        );
    }

    /** @param array<array-key, mixed> $usage */
    private static function count(array $usage, string $key): ?int
    {
        return is_int($usage[$key] ?? null) ? $usage[$key] : null;
    }

    /** `: <error type>: <message>` from an API error body, or its first 300 bytes. */
    private function errorDetail(string|bool $response): string
    {
        if (!is_string($response) || $response === '') {
            return '';
        }
        $decoded = json_decode($response, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $type = $error['type'] ?? null;
        $message = $error['message'] ?? null;
        if (is_string($type) && is_string($message)) {
            return ": {$type}: {$message}";
        }

        return ': ' . substr($response, 0, 300);
    }
}
