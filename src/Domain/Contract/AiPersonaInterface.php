<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Contract;

/**
 * A persona is the identity/role framing the planner opens its system prompt
 * with — it tells the model WHO it is and WHAT its job is in a given situation.
 *
 * The same planning machinery can therefore serve different roles: driving
 * Semitexa OS on a user's behalf, acting as a framework assistant in the
 * console, or any other context a package chooses to register. Personas are
 * discovered via {@see \Semitexa\Llm\Attribute\AsAiPersona} and resolved by
 * {@see \Semitexa\Llm\Application\Service\PersonaRegistry}.
 */
interface AiPersonaInterface
{
    /** The situation this persona serves (e.g. 'os', 'framework'). */
    public function contextName(): string;

    /** The identity/role framing prepended to the planner's system prompt. */
    public function systemFraming(): string;
}
