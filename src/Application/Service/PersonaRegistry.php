<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use ReflectionClass;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Llm\Attribute\AsAiPersona;
use Semitexa\Llm\Domain\Contract\AiPersonaInterface;

/**
 * Discovers {@see AsAiPersona}-marked personas and resolves the right one for a
 * situation, so initialization is flexible: the OS shell asks for the `os`
 * persona, the console asks for `framework`, and new contexts can be added by
 * simply declaring a new persona class — no changes to the planner.
 */
final class PersonaRegistry
{
    /** @var array<string, AiPersonaInterface> */
    private array $byContext = [];

    private ?string $defaultContext = null;

    private bool $built = false;

    public function __construct(
        private ?ClassDiscovery $classDiscovery = null,
    ) {}

    /**
     * The persona for a situation, falling back to the default persona, then
     * null when none is registered at all.
     */
    public function resolve(?string $context): ?AiPersonaInterface
    {
        $this->build();

        if ($context !== null && isset($this->byContext[$context])) {
            return $this->byContext[$context];
        }

        return $this->defaultContext !== null ? ($this->byContext[$this->defaultContext] ?? null) : null;
    }

    /**
     * Convenience: the framing string for a situation, or '' when no persona
     * resolves (the planner then applies its own generic fallback).
     */
    public function framing(?string $context): string
    {
        return $this->resolve($context)?->systemFraming() ?? '';
    }

    private function build(): void
    {
        if ($this->built) {
            return;
        }
        $this->built = true;

        foreach ($this->classDiscovery()->findClassesWithAttribute(AsAiPersona::class) as $class) {
            $ref = new ReflectionClass($class);
            $attributes = $ref->getAttributes(AsAiPersona::class);
            if ($attributes === []) {
                continue;
            }
            $meta = $attributes[0]->newInstance();

            $instance = new $class();
            if (!$instance instanceof AiPersonaInterface) {
                continue;
            }

            $this->byContext[$meta->context] = $instance;
            if ($meta->isDefault) {
                $this->defaultContext = $meta->context;
            }
        }
    }

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ??= new ClassDiscovery();
    }
}
