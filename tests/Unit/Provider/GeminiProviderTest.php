<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\GeminiProvider;
use Semitexa\Llm\Domain\Enum\LlmBackend;
use Semitexa\Llm\Domain\Model\LlmRequest;

final class GeminiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        // The explicit-cache registry is worker-global by design; tests must not
        // leak entries into each other.
        $this->setStatic('cachedPrefixes', []);
        $this->setStatic('cacheRefusals', []);
    }

    public function test_backend_enum_exposes_gemini_case(): void
    {
        $this->assertSame('gemini', LlmBackend::Gemini->value);
        $this->assertSame(LlmBackend::Gemini, LlmBackend::tryFrom('gemini'));
    }

    public function test_name_identifies_the_provider(): void
    {
        $this->assertSame('gemini', (new GeminiProvider())->name());
    }

    public function test_complete_fails_closed_without_an_api_key(): void
    {
        $provider = new GeminiProvider();
        $this->setProtected($provider, 'apiKey', '');

        $response = $provider->complete(new LlmRequest(
            systemPrompt: 'You are helpful.',
            userMessage: 'Hi',
        ));

        // No network is touched when the key is missing.
        $this->assertFalse($response->success);
        $this->assertSame('GEMINI_API_KEY is not configured', $response->error);
        $this->assertSame(0.0, $response->latencyMs);
    }

    public function test_health_check_fails_closed_without_an_api_key(): void
    {
        $provider = new GeminiProvider();
        $this->setProtected($provider, 'apiKey', '');

        $this->assertFalse($provider->healthCheck());
    }

    public function test_with_limits_returns_a_tuned_clone(): void
    {
        $provider = new GeminiProvider();
        $tuned = $provider->withLimits(5, 0, 256, false);

        $this->assertNotSame($provider, $tuned);
        $this->assertSame(5, $this->getProtected($tuned, 'timeout'));
        $this->assertSame(0, $this->getProtected($tuned, 'maxRetries'));
        $this->assertSame(256, $this->getProtected($tuned, 'maxTokens'));
        $this->assertFalse($this->getProtected($tuned, 'thinking'));
    }

    public function test_llm_response_usage_fields_default_to_null(): void
    {
        $response = new \Semitexa\Llm\Domain\Model\LlmResponse(content: 'x', success: true);

        $this->assertNull($response->promptTokens);
        $this->assertNull($response->cachedTokens);
    }

    public function test_request_body_with_cache_reference_omits_the_cached_prefix_fields(): void
    {
        $provider = $this->configuredProvider();
        $request = new LlmRequest(
            systemPrompt: 'You are helpful.',
            userMessage: 'Hi',
            tools: [['name' => 'lookup', 'description' => 'Find a thing.']],
        );

        $plain = json_decode((string) $this->invokePrivate($provider, 'encodeRequestBody', [$request, null]), true);
        $this->assertArrayHasKey('systemInstruction', $plain);
        $this->assertArrayHasKey('tools', $plain);
        $this->assertArrayNotHasKey('cachedContent', $plain);

        $cached = json_decode((string) $this->invokePrivate($provider, 'encodeRequestBody', [$request, 'cachedContents/abc']), true);
        $this->assertSame('cachedContents/abc', $cached['cachedContent']);
        // Gemini rejects a request that carries both the cache reference and the
        // fields the cache already contains.
        $this->assertArrayNotHasKey('systemInstruction', $cached);
        $this->assertArrayNotHasKey('tools', $cached);
        $this->assertSame($plain['contents'], $cached['contents']);
    }

    public function test_prefix_fingerprint_keys_on_model_system_prompt_and_tools(): void
    {
        $provider = $this->configuredProvider();
        $a = new LlmRequest(systemPrompt: 'Persona A', userMessage: 'x');
        $b = new LlmRequest(systemPrompt: 'Persona B', userMessage: 'x');
        $aWithTools = new LlmRequest(systemPrompt: 'Persona A', userMessage: 'x', tools: [['name' => 't']]);

        $this->assertSame(
            $this->invokePrivate($provider, 'prefixFingerprint', [$a]),
            $this->invokePrivate($provider, 'prefixFingerprint', [$a]),
        );
        $this->assertNotSame(
            $this->invokePrivate($provider, 'prefixFingerprint', [$a]),
            $this->invokePrivate($provider, 'prefixFingerprint', [$b]),
        );
        $this->assertNotSame(
            $this->invokePrivate($provider, 'prefixFingerprint', [$a]),
            $this->invokePrivate($provider, 'prefixFingerprint', [$aWithTools]),
        );

        $other = $this->configuredProvider();
        $this->setProtected($other, 'model', 'gemini-2.5-pro');
        $this->assertNotSame(
            $this->invokePrivate($provider, 'prefixFingerprint', [$a]),
            $this->invokePrivate($other, 'prefixFingerprint', [$a]),
        );
    }

    public function test_ensure_cached_prefix_reuses_a_live_entry_without_touching_the_network(): void
    {
        $provider = $this->configuredProvider();
        $request = new LlmRequest(systemPrompt: 'Persona', userMessage: 'x');
        $fingerprint = (string) $this->invokePrivate($provider, 'prefixFingerprint', [$request]);

        $this->setStatic('cachedPrefixes', [
            $fingerprint => ['name' => 'cachedContents/live', 'expiresAt' => time() + 3600],
        ]);

        $this->assertSame(
            'cachedContents/live',
            $this->invokePrivate($provider, 'ensureCachedPrefix', [$fingerprint, $request]),
        );
    }

    public function test_ensure_cached_prefix_drops_an_expired_entry_and_respects_the_refusal_backoff(): void
    {
        $provider = $this->configuredProvider();
        $request = new LlmRequest(systemPrompt: 'Persona', userMessage: 'x');
        $fingerprint = (string) $this->invokePrivate($provider, 'prefixFingerprint', [$request]);

        $this->setStatic('cachedPrefixes', [
            $fingerprint => ['name' => 'cachedContents/stale', 'expiresAt' => time() - 1],
        ]);
        $this->setStatic('cacheRefusals', [$fingerprint => time() + 600]);

        // Stale entry is not served; the refusal window blocks a re-create.
        $this->assertNull($this->invokePrivate($provider, 'ensureCachedPrefix', [$fingerprint, $request]));
        $this->assertSame([], $this->getStatic('cachedPrefixes'));
    }

    /**
     * A provider with the #[Config] scalars a container would inject. The base
     * URL is empty so any accidental HTTP attempt in a unit test fails loudly.
     */
    private function configuredProvider(): GeminiProvider
    {
        $provider = new GeminiProvider();
        $this->setProtected($provider, 'apiKey', 'k');
        $this->setProtected($provider, 'baseUrl', '');
        $this->setProtected($provider, 'model', 'gemini-2.5-flash');
        $this->setProtected($provider, 'contextCacheTtl', 3600);
        $this->setProtected($provider, 'contextCacheMinChars', 6000);

        return $provider;
    }

    private function setProtected(object $target, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($target, $property);
        $ref->setValue($target, $value);
    }

    private function getProtected(object $target, string $property): mixed
    {
        $ref = new \ReflectionProperty($target, $property);

        return $ref->getValue($target);
    }

    private function invokePrivate(object $target, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($target, $method);

        return $ref->invokeArgs($target, $args);
    }

    private function setStatic(string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty(GeminiProvider::class, $property);
        $ref->setValue(null, $value);
    }

    private function getStatic(string $property): mixed
    {
        $ref = new \ReflectionProperty(GeminiProvider::class, $property);

        return $ref->getValue();
    }
}
