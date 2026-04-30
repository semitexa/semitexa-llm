<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Enum;

enum AiArgumentPolicy: string
{
    case None = 'none';
    case Allowlisted = 'allowlisted';
    case All = 'all';
}
