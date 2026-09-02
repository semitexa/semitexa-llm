<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Model;

/**
 * Whose skills these are.
 *
 * A multi-tenant install discovers every skill of every site in one process:
 * the museum's publish-page skill sits in the same manifest as the vet clinic's
 * and the operator's own server commands. Handing that whole manifest to a
 * planner acting for one site's admin is how a request about the museum ends up
 * editing the clinic.
 *
 * There is no "unscoped" constructor on purpose — a caller has to say which of
 * the two cases it is, and the one that sees everything is named for what it
 * means.
 */
final readonly class SkillScope
{
    private function __construct(
        public ?string $tenantId,
        public bool $unrestricted,
    ) {}

    /** The server operator: every tenant, plus the cross-tenant tooling. */
    public static function unrestricted(): self
    {
        return new self(null, true);
    }

    /** An administrator of exactly one site. */
    public static function forTenant(string $tenantId): self
    {
        $tenantId = trim($tenantId);

        if ($tenantId === '') {
            throw new \InvalidArgumentException(
                'A tenant scope needs a tenant id; use SkillScope::unrestricted() for the operator.',
            );
        }

        return new self($tenantId, false);
    }
}
