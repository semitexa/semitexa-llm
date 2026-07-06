<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service\Persona;

use Semitexa\Llm\Attribute\AsAiPersona;
use Semitexa\Llm\Domain\Contract\AiPersonaInterface;

/**
 * The default persona: a Semitexa framework assistant. Used in the console and
 * anywhere no more specific situation applies.
 */
#[AsAiPersona(context: 'framework', isDefault: true)]
final class FrameworkPersona implements AiPersonaInterface
{
    public function contextName(): string
    {
        return 'framework';
    }

    public function systemFraming(): string
    {
        return 'You are a Semitexa framework assistant. Your job is to interpret operator requests and map them to available framework skills. Be concise and precise; confirm before anything risky, and refuse unsafe or out-of-scope requests.';
    }
}
