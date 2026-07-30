<?php

declare(strict_types=1);

namespace Semitexa\Llm;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'llm.skills',
    summary: 'Model-facing skills declared with #[AsAiSkill] and personas with #[AsAiPersona], executed by a planner that keeps history and calls tools.',
    useWhen: 'The application should act on what a user asked in prose rather than on a form they filled in.',
    avoidWhen: 'The decision is deterministic. A rule you can write down does not need a model to reach it.',
    replaces: [
        'direct HTTP calls to a provider with prompt strings inline in the handler',
        'a hand-rolled loop parsing model output to decide which function to call',
    ],
    seeAlso: 'semitexa/prompt',
)]
final class Capabilities
{
}
