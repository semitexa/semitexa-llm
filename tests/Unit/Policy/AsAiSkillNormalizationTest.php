<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Policy;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Policy\AiArgumentPolicy;
use Semitexa\Llm\Policy\AiConfirmationMode;
use Semitexa\Llm\Policy\AiExecutionKind;
use Semitexa\Llm\Policy\AiRiskLevel;

final class AsAiSkillNormalizationTest extends TestCase
{
    public function test_defaults_are_normalized(): void
    {
        $skill = new AsAiSkill();

        $this->assertSame(AiRiskLevel::Low, $skill->resolvedRiskLevel);
        $this->assertSame(AiConfirmationMode::Always, $skill->resolvedConfirmation);
        $this->assertSame(AiArgumentPolicy::Allowlisted, $skill->resolvedArgumentPolicy);
        $this->assertSame(AiExecutionKind::DirectCommand, $skill->resolvedExecutionKind);
    }

    public function test_string_risk_level_is_normalized(): void
    {
        $skill = new AsAiSkill(riskLevel: 'high');
        $this->assertSame(AiRiskLevel::High, $skill->resolvedRiskLevel);
    }

    public function test_enum_risk_level_passes_through(): void
    {
        $skill = new AsAiSkill(riskLevel: AiRiskLevel::Medium);
        $this->assertSame(AiRiskLevel::Medium, $skill->resolvedRiskLevel);
    }

    public function test_string_confirmation_is_normalized(): void
    {
        $skill = new AsAiSkill(confirmation: 'never');
        $this->assertSame(AiConfirmationMode::Never, $skill->resolvedConfirmation);
    }

    public function test_string_argument_policy_is_normalized(): void
    {
        $skill = new AsAiSkill(argumentPolicy: 'none');
        $this->assertSame(AiArgumentPolicy::None, $skill->resolvedArgumentPolicy);
    }

    public function test_string_execution_kind_is_normalized(): void
    {
        $skill = new AsAiSkill(executionKind: 'orchestration');
        $this->assertSame(AiExecutionKind::Orchestration, $skill->resolvedExecutionKind);
    }

    public function test_invalid_risk_level_throws(): void
    {
        $this->expectException(\ValueError::class);
        new AsAiSkill(riskLevel: 'extreme');
    }

    public function test_channels_default(): void
    {
        $skill = new AsAiSkill();
        $this->assertSame(['console'], $skill->channels);
    }

    public function test_expose_arguments(): void
    {
        $skill = new AsAiSkill(exposeArguments: ['twig', 'json']);
        $this->assertSame(['twig', 'json'], $skill->exposeArguments);
    }
}
