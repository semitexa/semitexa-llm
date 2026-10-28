<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Llm\Application\Service\AnthropicProvider;
use Semitexa\Llm\Application\Service\GeminiProvider;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Application\Service\LocalOllamaProvider;
use Semitexa\Llm\Application\Service\RemoteOllamaProvider;
use Semitexa\Llm\Application\Service\ScriptedProvider;
use Semitexa\Llm\Application\Service\Failover\FailoverProvider;
use Semitexa\Llm\Domain\Contract\LlmRouteOverrideInterface;
use Semitexa\Llm\Domain\Enum\LlmBackend;

/**
 * Naming a backend explicitly is how one feature runs on Claude while the rest
 * of the application stays on `LLM_BACKEND` — and the decider model of one
 * provider must never be handed to another.
 */
final class LlmProviderResolverTest extends TestCase
{
    private const ENV = ['LLM_BACKEND', 'LLM_DECIDER_MODEL', 'LLM_DECIDER_MODEL_ANTHROPIC', 'LLM_DECIDER_MODEL_GEMINI', 'LLM_FALLBACK', 'LLM_ROUTE_CHAT', 'LLM_ROUTE_POSTS'];

    /** @var array<string, string|false> what the process had before, put back after: the environment is global */
    private array $savedEnv = [];

    /** @var array<string, true> the resolver's once-per-worker warnings, static: put back after */
    private array $savedWarned = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        $warned = new \ReflectionProperty(LlmProviderResolver::class, 'warnedUnknown');
        /** @var array<string, true> $saved */
        $saved = $warned->getValue();
        $this->savedWarned = $saved;
        $warned->setValue(null, []);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        (new \ReflectionProperty(LlmProviderResolver::class, 'warnedUnknown'))->setValue(null, $this->savedWarned);
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

    public function test_reserves_stand_behind_the_env_backend_on_a_route(): void
    {
        putenv('LLM_BACKEND=anthropic');
        putenv('LLM_FALLBACK=gemini:gemini-flash-lite-latest, nonsense ,local');
        $resolver = $this->resolver();
        $route = $resolver->route('posts');

        $this->assertInstanceOf(FailoverProvider::class, $route);
        $this->assertSame(['anthropic:claude-opus-5-5', 'gemini:gemini-flash-lite-latest', 'ollama:gemma'], array_map(static fn ($p) => $p->name() . ':' . $p->model(), $route->providers()), 'an unknown name is left out, not read as local; a reserve keeps its model');
        $this->assertSame(['anthropic', 'gemini:gemini-flash-lite-latest', 'local'], $resolver->routeSpecs('posts'));
        // provider() stays one model: its callers tune it by its class.
        $this->assertInstanceOf(AnthropicProvider::class, $resolver->provider());
        $this->assertInstanceOf(AnthropicProvider::class, $resolver->deciderProvider());
    }

    public function test_a_reserve_behind_a_model_means_that_model_does_not_retry(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_ROUTE_CHAT=anthropic,gemini');
        $members = $this->resolver()->route('chat')->providers();

        $this->assertSame(0, $this->get($members[0], 'maxRetries'), 'Claude hands over at its first failure');
        $this->assertNotSame(0, $this->get($members[1], 'maxRetries'), 'the last one keeps its retries');
    }

    /** The primary repeated as a reserve cost one wasted call per failure, under one set-aside key. */
    public function test_a_member_named_twice_is_asked_once(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_FALLBACK=gemini, anthropic, gemini');

        $this->assertSame(['gemini', 'anthropic'], $this->resolver()->routeSpecs('chat'));
    }

    public function test_a_backend_and_its_own_default_model_are_one_member(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_ROUTE_CHAT=gemini,gemini:gemini-3.8-flash');

        $this->assertInstanceOf(GeminiProvider::class, $this->resolver()->route('chat'), 'one model: no chain at all');
    }

    public function test_no_reserves_keeps_the_plain_provider(): void
    {
        putenv('LLM_BACKEND=gemini');

        $this->assertInstanceOf(GeminiProvider::class, $this->resolver()->provider());
        $this->assertInstanceOf(GeminiProvider::class, $this->resolver()->route('chat'), 'an unconfigured route is the env backend');
    }

    public function test_a_route_runs_on_its_own_models_and_its_deciders_on_theirs(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_ROUTE_CHAT=anthropic:claude-opus-5-5,gemini');
        putenv('LLM_DECIDER_MODEL_ANTHROPIC=claude-haiku-5-5');
        putenv('LLM_DECIDER_MODEL=gemini-flash-lite-latest');
        $resolver = $this->resolver();

        $route = $resolver->route('chat');
        $this->assertInstanceOf(FailoverProvider::class, $route);
        $this->assertSame(['claude-opus-5-5', 'gemini-3.8-flash'], array_map(static fn ($p) => $p->model(), $route->providers()));

        $decider = $resolver->deciderRoute('chat');
        $this->assertInstanceOf(FailoverProvider::class, $decider);
        $this->assertSame(['claude-haiku-5-5', 'gemini-flash-lite-latest'], array_map(static fn ($p) => $p->model(), $decider->providers()));
        $this->assertTrue($resolver->routeSupportsTools('chat'));
        $this->assertFalse($resolver->membersSupportTools(['anthropic', 'local']), 'a text-only reserve would never get a request with tools');
        $this->assertInstanceOf(GeminiProvider::class, $resolver->provider(), 'the rest of the application stays on LLM_BACKEND');
    }

    public function test_a_bound_override_decides_before_the_env(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_ROUTE_CHAT=gemini');
        $resolver = $this->resolver();
        $this->set($resolver, 'routeOverride', new class implements LlmRouteOverrideInterface {
            public function members(string $route): ?array
            {
                return $route === 'chat' ? ['anthropic', 'gemini'] : null;
            }
        });

        $this->assertSame('anthropic', $resolver->route('chat')->name());
        $this->assertInstanceOf(GeminiProvider::class, $resolver->route('posts'), 'null leaves a route to its env');
    }

    public function test_an_override_that_throws_leaves_the_route_to_its_env(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_ROUTE_CHAT=anthropic');
        $resolver = $this->resolver();
        $this->set($resolver, 'routeOverride', new class implements LlmRouteOverrideInterface {
            public function members(string $route): ?array
            {
                throw new \RuntimeException('settings table is gone');
            }
        });

        $this->assertSame('anthropic', $resolver->route('chat')->name(), 'a setting that cannot be read does not silence the feature');
    }

    public function test_one_read_builds_both_phases_of_a_turn(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_DECIDER_MODEL_ANTHROPIC=claude-haiku-5-5');
        $resolver = $this->resolver();
        $specs = ['anthropic', 'gemini'];

        $this->assertSame('claude-opus-5-5', $resolver->fromMembers($specs)->model());
        $this->assertSame('claude-haiku-5-5', $resolver->fromMembers($specs, decider: true)->model());
        $this->assertTrue($resolver->membersSupportTools($specs));
    }

    public function test_a_route_of_local_models_takes_no_tools(): void
    {
        putenv('LLM_BACKEND=local');
        putenv('LLM_ROUTE_CHAT=local,remote_ollama');

        $this->assertFalse($this->resolver()->routeSupportsTools('chat'));
    }

    /** `local:<model>` was listed as honoured while Ollama answered on its own model: said once per worker. */
    public function test_a_model_the_provider_cannot_switch_to_is_warned_about_once(): void
    {
        putenv('LLM_BACKEND=gemini');
        putenv('LLM_ROUTE_CHAT=gemini:gemini-3.8-flash,local:llama3.2:1b');
        $logger = new class implements LoggerInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $warnings = [];

            public function error(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void { $this->warnings[] = [$message, $context]; }
            public function info(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        };
        $resolver = $this->resolver();
        $this->set($resolver, 'logger', $logger);

        $route = $resolver->route('chat');
        $resolver->route('chat');
        $resolver->deciderRoute('chat');

        $this->assertInstanceOf(FailoverProvider::class, $route);
        $this->assertSame(['gemini-3.8-flash', 'gemma'], array_map(static fn ($p) => $p->model(), $route->providers()));
        $this->assertSame([
            ['llm.route_model_ignored', ['backend' => 'local', 'asked' => 'llama3.2:1b', 'used' => 'gemma']],
        ], $logger->warnings);
    }

    public function test_the_test_seam_answers_every_route(): void
    {
        putenv('LLM_ROUTE_CHAT=anthropic,gemini');
        $scripted = new ScriptedProvider(['hi']);
        $resolver = $this->resolver()->withProvider($scripted);

        $this->assertSame($scripted, $resolver->route('chat'));
        $this->assertSame($scripted, $resolver->deciderRoute('chat'));
    }

    private function resolver(): LlmProviderResolver
    {
        $resolver = new LlmProviderResolver();
        $gemini = new GeminiProvider();
        $this->set($gemini, 'model', 'gemini-3.8-flash');
        $this->set($gemini, 'maxRetries', 2);
        $anthropic = new AnthropicProvider();
        $this->set($anthropic, 'model', 'claude-opus-5-5');
        $this->set($anthropic, 'maxRetries', 2);
        $local = new LocalOllamaProvider();
        $this->set($local, 'model', 'gemma');
        $remote = new RemoteOllamaProvider();
        // The address is part of what a route tells members apart by.
        foreach ([[$gemini, 'https://gemini.test'], [$anthropic, 'https://claude.test'], [$local, 'http://ollama.local'], [$remote, 'http://ollama.remote']] as [$provider, $url]) {
            $this->set($provider, 'baseUrl', $url);
        }

        $this->set($resolver, 'local', $local);
        $this->set($resolver, 'remote', $remote);
        $this->set($resolver, 'gemini', $gemini);
        $this->set($resolver, 'anthropic', $anthropic);

        return $resolver;
    }

    private function get(object $target, string $property): mixed
    {
        return (new \ReflectionProperty($target, $property))->getValue($target);
    }

    private function set(object $target, string $property, mixed $value): void
    {
        (new \ReflectionProperty($target, $property))->setValue($target, $value);
    }
}
