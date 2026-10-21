<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Environment;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Enum\LlmBackend;

/**
 * Selects the active LLM provider from the `LLM_BACKEND` env
 * (`local` | `remote_ollama` | `gemini` | `anthropic`), defaulting to local on an
 * unset/unknown value. A caller that routes some of its traffic elsewhere (one
 * feature on Claude while the rest stays on the env backend) names the backend
 * explicitly: `provider(LlmBackend::Anthropic)`.
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
     * The provider for `$backend`, or for `LLM_BACKEND` when none is named. The
     * test override wins either way, so a test never reaches a network through
     * an explicitly named backend.
     */
    public function provider(?LlmBackend $backend = null): LlmProviderInterface
    {
        if ($this->override !== null) {
            return $this->override;
        }

        return match ($backend ?? $this->backend()) {
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
        $provider = $this->provider($backend);
        $model = $this->deciderModel($backend ?? $this->backend());
        if ($model !== null && method_exists($provider, 'withModel')) {
            $switched = $provider->withModel($model);
            if ($switched instanceof LlmProviderInterface) {
                return $switched;
            }
        }

        return $provider;
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
