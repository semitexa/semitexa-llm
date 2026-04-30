<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Enum;

enum AiExecutionKind: string
{
    case DirectCommand = 'direct_command';
    case Orchestration = 'orchestration';
}
