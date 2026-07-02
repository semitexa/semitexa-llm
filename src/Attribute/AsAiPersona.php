<?php

declare(strict_types=1);

namespace Semitexa\Llm\Attribute;

use Attribute;

/**
 * Marks a class as an AI persona — an identity/role framing selectable by
 * situation ({@see \Semitexa\Llm\Domain\Contract\AiPersonaInterface}).
 *
 * Discovered by {@see \Semitexa\Llm\Application\Service\PersonaRegistry}. Exactly
 * one persona should set `isDefault: true` as the fallback for unknown contexts.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsAiPersona
{
    public function __construct(
        public string $context,
        public bool $isDefault = false,
    ) {}
}
