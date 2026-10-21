<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\AnthropicProvider;
use Semitexa\Llm\Domain\Enum\LlmBackend;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;

final class AnthropicProviderTest extends TestCase
{
    public function test_backend_enum_exposes_anthropic_case(): void
    {
        $this->assertSame('anthropic', LlmBackend::Anthropic->value);
        $this->assertSame(LlmBackend::Anthropic, LlmBackend::tryFrom('anthropic'));
    }

    public function test_name_identifies_the_provider(): void
    {
        $this->assertSame('anthropic', (new AnthropicProvider())->name());
    }

    public function test_complete_fails_closed_without_an_api_key(): void
    {
        $provider = $this->configuredProvider();
        $this->setProtected($provider, 'apiKey', '');

        $response = $provider->complete(new LlmRequest(systemPrompt: 'You are helpful.', userMessage: 'Hi'));

        // No network is touched when the key is missing.
        $this->assertFalse($response->success);
        $this->assertSame('ANTHROPIC_API_KEY is not configured', $response->error);
        $this->assertSame(0.0, $response->latencyMs);
    }

    public function test_health_check_fails_closed_without_an_api_key(): void
    {
        $provider = $this->configuredProvider();
        $this->setProtected($provider, 'apiKey', '');

        $this->assertFalse($provider->healthCheck());
    }

    public function test_with_limits_returns_a_tuned_clone_and_maps_no_thinking_to_low_effort(): void
    {
        $provider = $this->configuredProvider();
        $tuned = $provider->withLimits(5, 0, 256, false);

        $this->assertNotSame($provider, $tuned);
        $this->assertSame(5, $this->getProtected($tuned, 'timeout'));
        $this->assertSame(0, $this->getProtected($tuned, 'maxRetries'));
        $this->assertSame(256, $this->getProtected($tuned, 'maxTokens'));
        // Some Claude models refuse to switch thinking off; low effort is what every one accepts.
        $this->assertSame('low', $this->getProtected($tuned, 'effort'));
        $this->assertSame('', $this->getProtected($provider, 'effort'));
    }

    public function test_with_limits_keeps_the_configured_max_tokens_when_none_is_given(): void
    {
        $tuned = $this->configuredProvider()->withLimits(5, 0);

        $this->assertSame(16000, $this->getProtected($tuned, 'maxTokens'));
        $this->assertSame('', $this->getProtected($tuned, 'effort'));
    }

    public function test_with_model_clones_to_a_different_model_and_ignores_blank_or_same(): void
    {
        $provider = $this->configuredProvider();

        $haiku = $provider->withModel('claude-haiku-5-5');
        $this->assertNotSame($provider, $haiku);
        $this->assertSame('claude-haiku-5-5', $haiku->model());
        $this->assertSame('claude-opus-5-5', $provider->model());

        $this->assertSame($provider, $provider->withModel(''));
        $this->assertSame($provider, $provider->withModel('  '));
        $this->assertSame($provider, $provider->withModel('claude-opus-5-5'));
    }

    public function test_request_body_puts_the_system_prompt_in_a_cached_block(): void
    {
        $body = $this->body($this->configuredProvider(), new LlmRequest(systemPrompt: 'Persona', userMessage: 'Hi'));

        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame(16000, $body['max_tokens']);
        $this->assertSame([['type' => 'text', 'text' => 'Persona', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);
        $this->assertSame(['type' => 'ephemeral'], $body['cache_control']);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $body['messages']);
        // Nothing the caller did not ask for.
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('output_config', $body);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function test_request_body_sends_effort_only_when_configured(): void
    {
        $provider = $this->configuredProvider();
        $this->setProtected($provider, 'effort', 'low');

        $body = $this->body($provider, new LlmRequest(systemPrompt: '', userMessage: 'Hi'));

        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertArrayNotHasKey('system', $body);
    }

    public function test_history_is_normalised_to_alternating_turns_that_open_with_the_user(): void
    {
        $body = $this->body($this->configuredProvider(), new LlmRequest(
            systemPrompt: 'Persona',
            userMessage: 'And a balcony?',
            history: [
                ['role' => 'assistant', 'content' => 'Привіт! Я Соломія.'],
                ['role' => 'user', 'content' => 'Lviv, two nights'],
                ['role' => 'user', 'content' => '   '],
                ['role' => 'model', 'content' => 'Found three.'],
                ['role' => 'user', 'content' => 'Cheaper?'],
            ],
        ));

        $this->assertSame([
            ['role' => 'user', 'content' => '[conversation start]'],
            ['role' => 'assistant', 'content' => 'Привіт! Я Соломія.'],
            ['role' => 'user', 'content' => 'Lviv, two nights'],
            ['role' => 'assistant', 'content' => 'Found three.'],
            ['role' => 'user', 'content' => "Cheaper?\n\nAnd a balcony?"],
        ], $body['messages']);
    }

    public function test_gemini_style_tool_declarations_become_json_schema_tools(): void
    {
        $body = $this->body($this->configuredProvider(), new LlmRequest(
            systemPrompt: 'Decide.',
            userMessage: 'Lviv',
            tools: [
                [
                    'name' => 'search',
                    'description' => 'Find places.',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'city' => ['type' => 'STRING', 'description' => 'city name'],
                            'guests' => ['type' => 'INTEGER', 'nullable' => true],
                            'ids' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                            'filters' => ['type' => 'OBJECT', 'properties' => []],
                        ],
                        'required' => ['city'],
                        'propertyOrdering' => ['city', 'guests'],
                    ],
                ],
                ['name' => 'finish', 'description' => 'Done.'],
            ],
        ));

        $this->assertSame([
            [
                'name' => 'search',
                'description' => 'Find places.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string', 'description' => 'city name'],
                        'guests' => ['type' => ['integer', 'null']],
                        'ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'filters' => ['type' => 'object', 'properties' => []],
                    ],
                    'required' => ['city'],
                ],
            ],
            [
                'name' => 'finish',
                'description' => 'Done.',
                'input_schema' => ['type' => 'object', 'properties' => []],
            ],
        ], $body['tools']);

        // Empty properties must go out as a JSON object — Claude rejects `[]` there.
        $raw = (string) $this->invokePrivate($this->configuredProvider(), 'encodeRequestBody', [new LlmRequest(
            systemPrompt: '',
            userMessage: 'x',
            tools: [['name' => 'finish', 'description' => 'Done.']],
        )]);
        $this->assertStringContainsString('"input_schema":{"type":"object","properties":{}}', $raw);
    }

    public function test_decodes_text_tool_call_and_usage(): void
    {
        $response = $this->decode([
            'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
                ['type' => 'text', 'text' => 'Looking.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search', 'input' => ['city' => 'Lviv']],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'other', 'input' => []],
            ],
            'usage' => [
                'input_tokens' => 120,
                'cache_creation_input_tokens' => 30,
                'cache_read_input_tokens' => 9000,
                'output_tokens' => 42,
            ],
        ]);

        $this->assertTrue($response->success);
        $this->assertSame('Looking.', $response->content);
        $this->assertSame(['name' => 'search', 'arguments' => ['city' => 'Lviv']], $response->toolCall);
        $this->assertSame(42, $response->tokensUsed);
        $this->assertSame(9150, $response->promptTokens);
        $this->assertSame(9000, $response->cachedTokens);
    }

    public function test_a_refusal_is_a_failure_so_the_caller_can_fall_back(): void
    {
        $response = $this->decode([
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => null],
            'content' => [['type' => 'text', 'text' => 'partial']],
        ]);

        $this->assertFalse($response->success);
        $this->assertSame('', $response->content);
        $this->assertSame('Claude declined the request (refusal: cyber)', $response->error);
    }

    public function test_an_empty_answer_is_a_failure(): void
    {
        $response = $this->decode(['stop_reason' => 'max_tokens', 'content' => [['type' => 'thinking', 'thinking' => '']]]);

        $this->assertFalse($response->success);
        $this->assertSame('Claude returned no content (stop_reason: max_tokens)', $response->error);
    }

    public function test_error_detail_reads_the_api_error_body(): void
    {
        $provider = $this->configuredProvider();
        $body = '{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low."}}';

        $this->assertSame(
            ': invalid_request_error: Your credit balance is too low.',
            $this->invokePrivate($provider, 'errorDetail', [$body]),
        );
        $this->assertSame(': upstream down', $this->invokePrivate($provider, 'errorDetail', ['upstream down']));
        $this->assertSame('', $this->invokePrivate($provider, 'errorDetail', [false]));
    }

    /** @param array<string, mixed> $wire */
    private function decode(array $wire): LlmResponse
    {
        $response = $this->invokePrivate($this->configuredProvider(), 'decodeResponse', [json_encode($wire), 12.5]);
        $this->assertInstanceOf(LlmResponse::class, $response);

        return $response;
    }

    /** @return array<string, mixed> */
    private function body(AnthropicProvider $provider, LlmRequest $request): array
    {
        $json = $this->invokePrivate($provider, 'encodeRequestBody', [$request]);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * A provider with the #[Config] scalars a container would inject. The base
     * URL is empty so any accidental HTTP attempt in a unit test fails loudly.
     */
    private function configuredProvider(): AnthropicProvider
    {
        $provider = new AnthropicProvider();
        $this->setProtected($provider, 'apiKey', 'k');
        $this->setProtected($provider, 'baseUrl', '');
        $this->setProtected($provider, 'model', 'claude-opus-5-5');
        $this->setProtected($provider, 'timeout', 120);
        $this->setProtected($provider, 'maxRetries', 2);
        $this->setProtected($provider, 'connectTimeout', 5);
        $this->setProtected($provider, 'maxTokens', 16000);
        $this->setProtected($provider, 'effort', '');

        return $provider;
    }

    private function setProtected(object $target, string $property, mixed $value): void
    {
        (new \ReflectionProperty($target, $property))->setValue($target, $value);
    }

    private function getProtected(object $target, string $property): mixed
    {
        return (new \ReflectionProperty($target, $property))->getValue($target);
    }

    /** @param list<mixed> $args */
    private function invokePrivate(object $target, string $method, array $args): mixed
    {
        return (new \ReflectionMethod($target, $method))->invokeArgs($target, $args);
    }
}
