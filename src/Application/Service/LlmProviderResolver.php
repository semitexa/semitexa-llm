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
 * (`local` | `remote_ollama` | `gemini`), defaulting to local on an unset/unknown
 * value.
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

    public function provider(): LlmProviderInterface
    {
        return match ($this->backend()) {
            LlmBackend::RemoteOllama => $this->remote,
            LlmBackend::Gemini => $this->gemini,
            LlmBackend::Local => $this->local,
        };
    }

    /**
     * Two-tier routing (RFC-002 §4.1): the provider for SILENT classification /
     * tool-picking (the decider phase), routed to the cheaper/faster model named
     * in `LLM_DECIDER_MODEL` when set — the strong {@see provider()} still writes
     * the reply. Falls back to the normal provider when the env is unset or the
     * active provider can't switch models, so it is safe to call unconditionally.
     */
    public function deciderProvider(): LlmProviderInterface
    {
        $provider = $this->provider();
        $model = Environment::getEnvValue('LLM_DECIDER_MODEL');
        if ($model !== null && trim($model) !== '' && method_exists($provider, 'withModel')) {
            return $provider->withModel($model);
        }

        return $provider;
    }

    public function backend(): LlmBackend
    {
        return LlmBackend::tryFrom(Environment::getEnvValue('LLM_BACKEND') ?? LlmBackend::Local->value)
            ?? LlmBackend::Local;
    }
}
