<?php

declare(strict_types=1);

namespace Semitexa\Llm\Data;

enum PlannerResponseType: string
{
    case Answer = 'answer';
    case Ask = 'ask';
    case ProposeSkill = 'propose_skill';
    case Refuse = 'refuse';
}
