<?php

declare(strict_types=1);

namespace Semitexa\Llm\Policy;

enum AiConfirmationMode: string
{
    case Never = 'never';
    case Always = 'always';
    case WhenMutating = 'when_mutating';
}
