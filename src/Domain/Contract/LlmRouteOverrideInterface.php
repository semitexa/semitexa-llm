<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Contract;

/**
 * A project's say over which models a route uses, decided while it runs (a
 * setting an administrator changes) rather than by its env. Bind one with
 * `#[SatisfiesServiceContract(of: LlmRouteOverrideInterface::class)]`;
 * LlmProviderResolver asks it before LLM_ROUTE_<NAME>.
 */
interface LlmRouteOverrideInterface
{
    /**
     * The models for $route, best first, each `<backend>` or
     * `<backend>:<model>` (`anthropic`, `gemini:gemini-3.8-flash`); null to
     * leave the route to its env. Asked on every route() call: keep it cheap.
     *
     * @return list<string>|null
     */
    public function members(string $route): ?array;
}
