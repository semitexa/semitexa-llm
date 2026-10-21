<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\AnthropicProvider;
use Semitexa\Llm\Application\Service\GeminiProvider;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Application\Service\LocalOllamaProvider;
use Semitexa\Llm\Application\Service\RemoteOllamaProvider;
use Semitexa\Llm\Application\Service\ScriptedProvider;
use Semitexa\Llm\Domain\Enum\LlmBackend;

/**
 * Naming a backend explicitly is how one feature runs on Claude while the rest
 * of the application stays on `LLM_BACKEND` — and the decider model of one
 * provider must never be handed to another.
 */
final class LlmProviderResolverTest extends TestCase
{
    private const ENV = ['LLM_BACKEND', 'LLM_DECIDER_MODEL', 'LLM_DECIDER_MODEL_ANTHROPIC', 'LLM_DECIDER_MODEL_GEMINI'];

    protected function setUp(): void
    {
        foreach (self::ENV as $key) {
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV as $key) {
            putenv($key);
        }
    }

    public function test_an_explicit_backend_wins_over_the_env_one(): void
    {
        putenv('LLM_BACKEND=gemini');
        $resolver = $this->resolver();

        $this->assertSame('gemini', $resolver->provider()->name());
        $this->assertSame('anthropic', $resolver->provider(LlmBackend::Anthropic)->name());
        $this->assertSame(LlmBackend::Gemini, $resolver->backend());
    }

    public function test_anthropic_is_selectable_from_the_env(): void
    {
        putenv('LLM_BACKEND=anthropic');

        $this->assertSame('anthropic', $this->resolver()->provider()->name());
    }

    public function test_the_plain_decider_model_applies_only_to_the_env_backend(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_DECIDER_MODEL=gemini-flash-lite-latest');
        $resolver = $this->resolver();

        $this->assertSame('gemini-flash-lite-latest', $resolver->deciderProvider()->model());
        // A Gemini model id must not reach Claude.
        $this->assertSame('claude-opus-5-5', $resolver->deciderProvider(LlmBackend::Anthropic)->model());
    }

    public function test_a_backend_scoped_decider_model_routes_that_backend(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_DECIDER_MODEL=gemini-flash-lite-latest');
        putenv('LLM_DECIDER_MODEL_ANTHROPIC=claude-haiku-5-5');
        $resolver = $this->resolver();

        $this->assertSame('claude-haiku-5-5', $resolver->deciderProvider(LlmBackend::Anthropic)->model());
        $this->assertSame('gemini-flash-lite-latest', $resolver->deciderProvider()->model());
    }

    public function test_the_scoped_decider_model_outranks_the_plain_one_on_the_env_backend(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_DECIDER_MODEL=gemini-flash-lite-latest');
        putenv('LLM_DECIDER_MODEL_GEMINI=gemini-2.5-flash-lite');

        $this->assertSame('gemini-2.5-flash-lite', $this->resolver()->deciderProvider()->model());
    }

    public function test_tool_support_follows_the_backend(): void
    {
        putenv('LLM_BACKEND=local');
        $resolver = $this->resolver();

        $this->assertFalse($resolver->supportsTools());
        $this->assertTrue($resolver->supportsTools(LlmBackend::Anthropic));
        $this->assertTrue($resolver->supportsTools(LlmBackend::Gemini));
        $this->assertFalse($resolver->supportsTools(LlmBackend::RemoteOllama));
    }

    public function test_the_test_override_wins_for_an_explicit_backend_too(): void
    {
        $scripted = new ScriptedProvider([]);
        $resolver = $this->resolver()->withProvider($scripted);

        $this->assertSame($scripted, $resolver->provider(LlmBackend::Anthropic));
        $this->assertSame($scripted, $resolver->deciderProvider(LlmBackend::Anthropic));
    }

    private function resolver(): LlmProviderResolver
    {
        $resolver = new LlmProviderResolver();
        $gemini = new GeminiProvider();
        $this->set($gemini, 'model', 'gemini-3.8-flash');
        $anthropic = new AnthropicProvider();
        $this->set($anthropic, 'model', 'claude-opus-5-5');

        $this->set($resolver, 'local', new LocalOllamaProvider());
        $this->set($resolver, 'remote', new RemoteOllamaProvider());
        $this->set($resolver, 'gemini', $gemini);
        $this->set($resolver, 'anthropic', $anthropic);

        return $resolver;
    }

    private function set(object $target, string $property, mixed $value): void
    {
        (new \ReflectionProperty($target, $property))->setValue($target, $value);
    }
}
