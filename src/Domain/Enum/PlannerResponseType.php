<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Enum;

enum PlannerResponseType: string
{
    case Answer = 'answer';
    case Ask = 'ask';
    case ProposeSkill = 'propose_skill';
    /** An ordered chain of skills to run in sequence (executionKind: orchestration). */
    case ProposePipeline = 'propose_pipeline';
    case Refuse = 'refuse';
}
