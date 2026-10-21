<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Llm\Application\Service\Failover\FailoverProvider;
use Semitexa\Llm\Application\Service\Failover\LlmProviderHealth;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Contract\LlmRouteOverrideInterface;
use Semitexa\Llm\Domain\Contract\SupportsToolCallingInterface;
use Semitexa\Llm\Domain\Enum\LlmBackend;

/**
 * Selects the active LLM provider from the `LLM_BACKEND` env
 * (`local` | `remote_ollama` | `gemini` | `anthropic`), defaulting to local on an
 * unset/unknown value. A caller that routes some of its traffic elsewhere (one
 * feature on Claude while the rest stays on the env backend) names the backend
 * explicitly: `provider(LlmBackend::Anthropic)`.
 *
 * Several models at once, with reserves: a feature asks for its route,
 * route('chat'), and an operator decides what serves it —
 * `LLM_ROUTE_CHAT=anthropic:claude-opus-5-5,gemini` asks Claude, then Gemini
 * when Claude fails (a FailoverProvider). A bound LlmRouteOverrideInterface
 * decides before the env (an administrator's setting); a route nobody
 * configured is LLM_BACKEND with the LLM_FALLBACK reserves behind it.
 * provider() stays one model: its callers tune it by its class
 * (withLimits() on a GeminiProvider), which a chain is not.
 *
 * This is the canonical, env-aware seam every consumer should use. We inject the
 * concrete providers directly rather than the generated factory contract:
 * `FactoryLlmProviderInterface` is not a regular injectable binding, and the
 * generated `App\Registry\Contracts\LlmProviderFactory` lives in the app
 * namespace (a package cannot depend on it). Injecting a bare
 * `LlmProviderInterface` is also wrong here — for a factory-keyed contract that
 * resolves to the priority-default implementation and silently ignores
 * `LLM_BACKEND`, which is exactly the bug this class exists to avoid.
 */
#[AsService]
final class LlmProviderResolver
{
    #[InjectAsReadonly]
    protected LocalOllamaProvider $local;

    #[InjectAsReadonly]
    protected RemoteOllamaProvider $remote;

    #[InjectAsReadonly]
    protected GeminiProvider $gemini;

    #[InjectAsReadonly]
    protected AnthropicProvider $anthropic;

    #[InjectAsReadonly]
    protected LlmProviderHealth $health;

    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    #[InjectAsReadonly(optional: true)]
    protected LlmRouteOverrideInterface $routeOverride;

    /** Set only by {@see withProvider()}; null in every production path. */
    private ?LlmProviderInterface $override = null;

    /**
     * Test seam — production always resolves from `LLM_BACKEND`.
     *
     * Every consumer of this resolver reaches a live model, and both this class
     * and its callers are final with concrete provider properties, so anything
     * downstream of it was untestable without a network. The rolling
     * conversation summary is what exposed that: it could never be shown to work
     * except by calling a real model, which is why it sat unproven.
     *
     * Same shape as the ORM and prompt-repository seams elsewhere in the
     * framework: a `with…()` that a test calls and production never does.
     */
    public function withProvider(LlmProviderInterface $provider): self
    {
        $this->override = $provider;

        return $this;
    }

    /**
     * The provider for `$backend`, or for `LLM_BACKEND` when none is named:
     * one model, no reserves (route() has them). The test override wins
     * either way, so a test never reaches a network through an explicitly
     * named backend.
     */
    public function provider(?LlmBackend $backend = null): LlmProviderInterface
    {
        return $this->override ?? $this->single($backend ?? $this->backend());
    }

    /**
     * The models of route `$name`, best first, as one provider: the bound
     * LlmRouteOverrideInterface's choice, else `LLM_ROUTE_<NAME>`
     * (`anthropic:claude-opus-5-5,gemini`), else LLM_BACKEND and its
     * LLM_FALLBACK reserves.
     */
    public function route(string $name): LlmProviderInterface
    {
        return $this->fromMembers($this->routeSpecs($name));
    }

    /**
     * route() for the decider phase: each member on its own decider model
     * (LLM_DECIDER_MODEL_<BACKEND>, see deciderProvider()), so a cheap model
     * picks the tool and the route's strong one writes the reply.
     */
    public function deciderRoute(string $name): LlmProviderInterface
    {
        return $this->fromMembers($this->routeSpecs($name), true);
    }

    /**
     * Whether a request on route `$name` may carry native tools: every model
     * of the route takes them. Were one reserve text-only, a request with
     * tools would never reach it (a FailoverProvider skips it), and the
     * route would have no reserve for it. False means: ask for JSON in text.
     */
    public function routeSupportsTools(string $name): bool
    {
        return $this->membersSupportTools($this->routeSpecs($name));
    }

    /**
     * The members of route `$name` as configured right now, best first: the
     * bound override's choice, else `LLM_ROUTE_<NAME>`, else LLM_BACKEND and
     * LLM_FALLBACK. Read it once per conversation turn and build both phases
     * from it (fromMembers()), so a setting changed mid-turn cannot split one
     * turn across two sets of models.
     *
     * @return list<string> `<backend>` or `<backend>:<model>`
     */
    public function routeSpecs(string $name): array
    {
        $specs = null;
        if (isset($this->routeOverride)) {
            try {
                $specs = $this->routeOverride->members($name);
            } catch (\Throwable $e) {
                // A setting that cannot be read must not silence the feature.
                $this->logger()?->warning('llm.route_override_failed', ['route' => $name, 'error' => $e->getMessage()]);
            }
        }
        if ($specs === null || self::parse($specs) === []) {
            $env = trim((string) Environment::getEnvValue('LLM_ROUTE_' . strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name)), ''));
            $specs = $env !== '' ? explode(',', $env) : [];
        }
        if (self::parse($specs) === []) {
            $specs = [$this->backend()->value, ...explode(',', (string) Environment::getEnvValue('LLM_FALLBACK', ''))];
        }

        return array_map(
            static fn (array $member): string => $member[0]->value . ($member[1] !== null ? ':' . $member[1] : ''),
            self::parse($specs),
        );
    }

    /**
     * $specs (`<backend>` or `<backend>:<model>`, best first) as one provider:
     * the model alone, or a FailoverProvider whose every member but the last
     * gives up at its first failure, so the next one is asked at once. With
     * $decider, each on its decider model (LLM_DECIDER_MODEL_<BACKEND> before
     * a model named in the spec).
     *
     * @param list<string> $specs
     */
    public function fromMembers(array $specs, bool $decider = false): LlmProviderInterface
    {
        if ($this->override !== null) {
            return $this->override;
        }
        $members = self::parse($specs);
        if ($members === []) {
            $members = [[$this->backend(), null]];
        }
        $providers = [];
        foreach ($members as [$backend, $model]) {
            $providers[] = $decider ? $this->decider($backend, $model) : self::switched($this->single($backend), $model);
        }
        if (count($providers) === 1) {
            return $providers[0];
        }
        $last = count($providers) - 1;
        foreach ($providers as $i => $provider) {
            if ($i < $last && method_exists($provider, 'withoutRetries')) {
                $providers[$i] = $provider->withoutRetries();
            }
        }

        return new FailoverProvider($providers, $this->health(), $this->logger());
    }

    /** @param list<string> $specs */
    public function membersSupportTools(array $specs): bool
    {
        if ($this->override !== null) {
            return $this->override instanceof SupportsToolCallingInterface;
        }
        $members = self::parse($specs);
        foreach ($members === [] ? [[$this->backend(), null]] : $members as [$backend]) {
            if (!$this->supportsTools($backend)) {
                return false;
            }
        }

        return true;
    }

    /** Ask every set-aside model again (after a key or credit was fixed), in every worker. */
    public function resetBenches(): void
    {
        $this->health()->reset();
    }

    private function single(LlmBackend $backend): LlmProviderInterface
    {
        return match ($backend) {
            LlmBackend::RemoteOllama => $this->remote,
            LlmBackend::Gemini => $this->gemini,
            LlmBackend::Anthropic => $this->anthropic,
            LlmBackend::Local => $this->local,
        };
    }

    /**
     * Two-tier routing (RFC-002 §4.1): the provider for SILENT classification /
     * tool-picking (the decider phase), routed to a cheaper/faster model — the
     * strong {@see provider()} still writes the reply. Falls back to the normal
     * provider when no decider model is configured or the provider can't switch
     * models, so it is safe to call unconditionally.
     *
     * The model is `LLM_DECIDER_MODEL_<BACKEND>` (e.g. `LLM_DECIDER_MODEL_ANTHROPIC`)
     * when set. The plain `LLM_DECIDER_MODEL` applies only to the `LLM_BACKEND`
     * provider: it names a model of THAT provider, and handing a Gemini model id
     * to Claude would fail every decider call.
     */
    public function deciderProvider(?LlmBackend $backend = null): LlmProviderInterface
    {
        return $this->override ?? $this->decider($backend ?? $this->backend(), null);
    }

    /** $backend's provider on its decider model; $model, when given, if none is configured. */
    private function decider(LlmBackend $backend, ?string $model): LlmProviderInterface
    {
        $provider = self::switched($this->single($backend), $model);
        return self::switched($provider, $this->deciderModel($backend));
    }

    /**
     * Whether `$backend` (or `LLM_BACKEND`) takes native tool declarations and
     * answers with a structured tool call. Callers send tools only where this is
     * true, and fall back to JSON-in-text otherwise.
     */
    public function supportsTools(?LlmBackend $backend = null): bool
    {
        return match ($backend ?? $this->backend()) {
            LlmBackend::Gemini, LlmBackend::Anthropic => true,
            LlmBackend::Local, LlmBackend::RemoteOllama => false,
        };
    }

    public function backend(): LlmBackend
    {
        return LlmBackend::tryFrom(Environment::getEnvValue('LLM_BACKEND') ?? LlmBackend::Local->value)
            ?? LlmBackend::Local;
    }

    /**
     * `<backend>` or `<backend>:<model>`; an unknown backend is left out
     * rather than read as `local`, which would quietly answer for it.
     *
     * @param list<string> $specs
     * @return list<array{LlmBackend, ?string}>
     */
    private static function parse(array $specs): array
    {
        $members = [];
        foreach ($specs as $spec) {
            [$name, $model] = array_map('trim', explode(':', trim($spec), 2)) + [1 => ''];
            $backend = LlmBackend::tryFrom(strtolower($name));
            if ($backend !== null) {
                $members[] = [$backend, $model !== '' ? $model : null];
            }
        }

        return $members;
    }

    /** $provider on $model, when it can switch; as it is otherwise. */
    private static function switched(LlmProviderInterface $provider, ?string $model): LlmProviderInterface
    {
        if ($model !== null && method_exists($provider, 'withModel')) {
            $switched = $provider->withModel($model);
            if ($switched instanceof LlmProviderInterface) {
                return $switched;
            }
        }

        return $provider;
    }

    private function logger(): ?LoggerInterface
    {
        return isset($this->logger) ? $this->logger : null;
    }

    private function health(): LlmProviderHealth
    {
        // Tests build the resolver by hand; the container always injects it.
        return isset($this->health) ? $this->health : ($this->health = new LlmProviderHealth());
    }

    private function deciderModel(LlmBackend $backend): ?string
    {
        $candidates = ['LLM_DECIDER_MODEL_' . strtoupper($backend->value)];
        if ($backend === $this->backend()) {
            $candidates[] = 'LLM_DECIDER_MODEL';
        }
        foreach ($candidates as $key) {
            $model = Environment::getEnvValue($key);
            if ($model !== null && trim($model) !== '') {
                return trim($model);
            }
        }

        return null;
    }
}
