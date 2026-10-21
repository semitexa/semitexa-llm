<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service\Failover;

use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Contract\SupportsToolCallingInterface;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;

/**
 * Several models as one: a call goes to the first that can take it, and
 * whatever that one fails — an outage, a refusal, credit run out — is asked
 * of the next. The answer says which model gave it (LlmResponse::$servedBy).
 *
 * A failure that will repeat whatever is asked (LlmFailure::isLasting(): no
 * key, a refused key, no credit, a daily quota) sets the model aside for
 * LLM_BENCH_SECONDS, in every worker (LlmProviderHealth): otherwise each
 * call would first wait for the same refusal. A model that does not answer
 * at all (a timeout, an unreachable host) is set aside for
 * LLM_BENCH_TRANSIENT_SECONDS, so the next calls do not each wait out the
 * same timeout. When every model is set aside they are asked anyway, in
 * order — set aside is a guess, silence is not an answer.
 *
 * A request with tools goes only to models that take tools
 * (SupportsToolCallingInterface); the others would answer in text that the
 * caller reads as a tool call. When none of them does, all are asked.
 *
 * Made per use by LlmProviderResolver::route(), or by hand:
 * `new FailoverProvider([$claude, $gemini], $health, $logger)`.
 */
final class FailoverProvider implements LlmProviderInterface
{
    /** @var non-empty-list<LlmProviderInterface> */
    private readonly array $providers;

    /**
     * @param list<LlmProviderInterface> $providers in order of preference
     */
    public function __construct(
        array $providers,
        private readonly LlmProviderHealth $health,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?int $benchSeconds = null,
    ) {
        if ($providers === []) {
            throw new \InvalidArgumentException('A FailoverProvider needs at least one provider.');
        }
        $this->providers = $providers;
    }

    /** @return non-empty-list<LlmProviderInterface> */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * Every model with these limits, where it takes them: the call that caps
     * a provider's timeout or tokens caps the whole chain.
     */
    public function withLimits(int $timeoutSeconds, int $maxRetries, ?int $maxTokens = null, bool $thinking = true): self
    {
        return $this->mapped(static fn (LlmProviderInterface $p): LlmProviderInterface => method_exists($p, 'withLimits')
            ? $p->withLimits($timeoutSeconds, $maxRetries, $maxTokens, $thinking)
            : $p);
    }

    /** The first choice on $model; the reserves keep their own (a model id names one vendor's model). */
    public function withModel(string $model): self
    {
        $first = $this->providers[0];
        if (!method_exists($first, 'withModel')) {
            return $this;
        }
        $switched = $first->withModel($model);
        if (!$switched instanceof LlmProviderInterface) {
            return $this;
        }
        $providers = $this->providers;
        $providers[0] = $switched;

        return new self($providers, $this->health, $this->logger, $this->benchSeconds);
    }

    /** @param \Closure(LlmProviderInterface): mixed $map */
    private function mapped(\Closure $map): self
    {
        $providers = [];
        foreach ($this->providers as $provider) {
            $mapped = $map($provider);
            $providers[] = $mapped instanceof LlmProviderInterface ? $mapped : $provider;
        }

        return new self($providers, $this->health, $this->logger, $this->benchSeconds);
    }

    /** The first choice's name: what is asked for. The answer says what answered. */
    public function name(): string
    {
        return $this->providers[0]->name();
    }

    public function baseUrl(): string
    {
        return $this->providers[0]->baseUrl();
    }

    public function model(): string
    {
        return $this->providers[0]->model();
    }

    /** Whether some model here takes native tools: a request with tools goes only to those. */
    public function supportsTools(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof SupportsToolCallingInterface) {
                return true;
            }
        }

        return false;
    }

    public function healthCheck(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->healthCheck()) {
                return true;
            }
        }

        return false;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $tried = [];
        $reasons = [];
        $last = null;
        foreach ($this->order($request) as $provider) {
            $key = self::key($provider);
            $response = $provider->complete($request);
            if ($response->success) {
                if ($tried !== []) {
                    $this->logger?->info('llm.failover.served', ['by' => $key, 'after' => $tried]);
                }

                return $response->withServedBy($key);
            }

            $bench = match (true) {
                $response->failure?->isLasting() === true => $this->benchSeconds ?? LlmProviderHealth::benchSeconds(),
                $response->failure?->isUnreachable() === true => LlmProviderHealth::transientBenchSeconds(),
                default => 0,
            };
            if ($bench > 0) {
                $this->health->bench(self::benchKey($provider), $bench);
            }
            $this->logger?->warning('llm.failover', [
                'from' => $key,
                'failure' => $response->failure?->value,
                'error' => mb_substr((string) $response->error, 0, 300),
                'benched_s' => $bench,
            ]);
            $tried[] = $key;
            $reasons[] = $key . ': ' . ($response->failure->value ?? 'failed');
            $last = $response;
        }

        // order() is never empty, so the loop ran. The last failure, with
        // every model's reason: the first one's "no credit" is the news.
        return new LlmResponse(
            content: '',
            success: false,
            error: $last->error . (count($reasons) > 1 ? ' [' . implode('; ', $reasons) . ']' : ''),
            latencyMs: $last->latencyMs,
            failure: $last->failure,
            servedBy: $tried[count($tried) - 1],
        );
    }

    /**
     * Who is asked, in turn: those that can take the request and are not set
     * aside; when every one of them is set aside, all of them.
     *
     * @return non-empty-list<LlmProviderInterface>
     */
    private function order(LlmRequest $request): array
    {
        $able = $this->providers;
        if ($request->tools !== []) {
            $withTools = array_values(array_filter($able, static fn (LlmProviderInterface $p): bool => $p instanceof SupportsToolCallingInterface));
            if ($withTools !== []) {
                $able = $withTools;
            }
        }
        $ready = array_values(array_filter($able, fn (LlmProviderInterface $p): bool => !$this->health->isBenched(self::benchKey($p))));

        return $ready !== [] ? $ready : $able;
    }

    /** What LlmResponse::$servedBy names: `<provider>:<model>`. */
    private static function key(LlmProviderInterface $provider): string
    {
        return $provider->name() . ':' . $provider->model();
    }

    /**
     * What is set aside: the model at its address. A local and a remote Ollama
     * both call themselves `ollama`, and one with no such model pulled must
     * not set the other aside.
     */
    public static function benchKey(LlmProviderInterface $provider): string
    {
        return self::key($provider) . '@' . $provider->baseUrl();
    }
}
