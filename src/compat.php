<?php

declare(strict_types=1);

namespace Semitexa\Llm;

use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Backward compatibility shims for Semitexa LLM namespace migration.
 */

if (!class_exists('Semitexa\Llm\Policy\AiArgumentPolicy', false)) {
    class_alias(AiArgumentPolicy::class, 'Semitexa\Llm\Policy\AiArgumentPolicy');
}

if (!class_exists('Semitexa\Llm\Policy\AiConfirmationMode', false)) {
    class_alias(AiConfirmationMode::class, 'Semitexa\Llm\Policy\AiConfirmationMode');
}

if (!class_exists('Semitexa\Llm\Policy\AiRiskLevel', false)) {
    class_alias(AiRiskLevel::class, 'Semitexa\Llm\Policy\AiRiskLevel');
}
