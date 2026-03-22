<?php

declare(strict_types=1);

namespace Semitexa\Llm\Policy;

enum AiArgumentPolicy: string
{
    case None = 'none';
    case Allowlisted = 'allowlisted';
    case All = 'all';
}
