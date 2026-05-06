<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Enum;

enum AiRiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
