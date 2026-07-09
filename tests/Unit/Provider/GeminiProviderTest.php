<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\GeminiProvider;
use Semitexa\Llm\Domain\Enum\LlmBackend;
use Semitexa\Llm\Domain\Model\LlmRequest;

final class GeminiProviderTest extends TestCase
{
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
}
