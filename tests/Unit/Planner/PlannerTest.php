<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Planner;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Domain\Model\LlmResponse;
use Semitexa\Llm\Domain\Enum\PlannerResponseType;
use Semitexa\Llm\Domain\Model\SkillManifest;
use Semitexa\Llm\Application\Service\Planner;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Llm\Domain\Model\SkillEntry;

final class PlannerTest extends TestCase
{
    private Planner $planner;

    protected function setUp(): void
    {
        $this->planner = new Planner();
    }

    public function test_parses_answer_response(): void
    {
        $response = new LlmResponse(
            content: '{"type":"answer","message":"Cache was cleared.","reason":"Direct answer."}',
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::Answer, $result->type);
        $this->assertSame('Cache was cleared.', $result->message);
        $this->assertSame('Direct answer.', $result->reason);
    }

    public function test_parses_ask_response(): void
    {
        $response = new LlmResponse(
            content: '{"type":"ask","message":"Which cache?","reason":"Ambiguous request."}',
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::Ask, $result->type);
        $this->assertSame('Which cache?', $result->message);
    }

    public function test_parses_propose_skill_response(): void
    {
        $response = new LlmResponse(
            content: '{"type":"propose_skill","skill":"cache:clear","arguments":{"twig":true},"reason":"User asked to clear template cache.","confidence":0.95}',
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::ProposeSkill, $result->type);
        $this->assertSame('cache:clear', $result->skill);
        $this->assertSame(['twig' => true], $result->arguments);
        $this->assertEqualsWithDelta(0.95, $result->confidence, 0.001);
    }

    public function test_parses_refuse_response(): void
    {
        $response = new LlmResponse(
            content: '{"type":"refuse","message":"Cannot do that.","reason":"Policy."}',
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::Refuse, $result->type);
        $this->assertSame('Cannot do that.', $result->message);
    }

    public function test_provider_error_returns_refuse(): void
    {
        $response = new LlmResponse(
            content: '',
            success: false,
            error: 'Connection refused',
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::Refuse, $result->type);
        $this->assertStringContainsString('Connection refused', $result->message ?? '');
    }

    public function test_invalid_json_falls_back_to_answer(): void
    {
        $response = new LlmResponse(
            content: 'This is plain text, not JSON.',
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::Answer, $result->type);
        $this->assertSame('This is plain text, not JSON.', $result->message);
    }

    public function test_unknown_type_returns_refuse(): void
    {
        $response = new LlmResponse(
            content: '{"type":"unknown_action","message":"Do something."}',
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::Refuse, $result->type);
    }

    public function test_parses_json_wrapped_in_code_fences(): void
    {
        $response = new LlmResponse(
            content: "```json\n{\"type\":\"answer\",\"message\":\"Done.\",\"reason\":\"Direct.\"}\n```",
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::Answer, $result->type);
        $this->assertSame('Done.', $result->message);
    }

    public function test_parses_json_with_preamble_text(): void
    {
        $response = new LlmResponse(
            content: "Here is my response:\n{\"type\":\"answer\",\"message\":\"Hello.\",\"reason\":\"Greeting.\"}",
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::Answer, $result->type);
        $this->assertSame('Hello.', $result->message);
    }

    public function test_parses_json_with_trailing_text(): void
    {
        $response = new LlmResponse(
            content: "{\"type\":\"propose_skill\",\"skill\":\"cache:clear\",\"arguments\":{},\"reason\":\"Match.\",\"confidence\":0.9}\nLet me know if you need more.",
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::ProposeSkill, $result->type);
        $this->assertSame('cache:clear', $result->skill);
    }

    public function test_parses_json_with_trailing_comma(): void
    {
        $response = new LlmResponse(
            content: '{"type":"answer","message":"Done.","reason":"Direct.",}',
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::Answer, $result->type);
        $this->assertSame('Done.', $result->message);
    }

    public function test_extract_json_returns_null_for_no_json(): void
    {
        $result = $this->planner->extractJson('No JSON here at all.');
        $this->assertNull($result);
    }

    public function test_system_prompt_contains_skill_manifest(): void
    {
        $manifest = new SkillManifest(
            artifact: 'semitexa.ai-skills/v1',
            generatedAt: '2026-03-22T12:00:00+00:00',
            skills: [],
        );

        $prompt = $this->planner->buildSystemPrompt($manifest);

        $this->assertStringContainsString('Semitexa framework assistant', $prompt);
        $this->assertStringContainsString('propose_skill', $prompt);
        $this->assertStringContainsString('JSON', $prompt);
    }

    public function test_system_prompt_date_anchor_uses_given_timezone(): void
    {
        $manifest = new SkillManifest(artifact: 'semitexa.ai-skills/v1', generatedAt: '2026-03-22T12:00:00+00:00', skills: []);
        $zone = new \DateTimeZone('Pacific/Kiritimati'); // UTC+14 — maximally far from a UTC server clock

        $before = new \DateTimeImmutable('now', $zone);
        $prompt = $this->planner->buildSystemPrompt($manifest, null, $zone);
        $after = new \DateTimeImmutable('now', $zone);

        // The anchor must carry the zone's wall-clock minute (tolerate a tick over
        // a minute boundary between the two probes).
        $this->assertTrue(
            str_contains($prompt, 'ISO ' . $before->format('Y-m-d H:i'))
            || str_contains($prompt, 'ISO ' . $after->format('Y-m-d H:i')),
            'Prompt date anchor is not in the requested timezone.',
        );
    }

    public function test_skill_name_as_type_is_salvaged_into_proposal_with_manifest(): void
    {
        $response = new LlmResponse(
            content: '{"type":"remember","arguments":{"title":"Дмитро"},"confidence":0.8}',
            success: true,
        );

        $result = $this->planner->parseResponse($response, manifest: $this->manifestWith('remember'));

        $this->assertSame(PlannerResponseType::ProposeSkill, $result->type);
        $this->assertSame('remember', $result->skill);
        $this->assertSame(['title' => 'Дмитро'], $result->arguments);
        $this->assertSame(0.8, $result->confidence);
    }

    public function test_unknown_type_with_explicit_skill_field_is_salvaged(): void
    {
        $response = new LlmResponse(
            content: '{"type":"skill","skill":"remember","arguments":{}}',
            success: true,
        );

        $result = $this->planner->parseResponse($response, manifest: $this->manifestWith('remember'));

        $this->assertSame(PlannerResponseType::ProposeSkill, $result->type);
        $this->assertSame('remember', $result->skill);
    }

    public function test_unknown_type_not_in_manifest_still_refuses(): void
    {
        $response = new LlmResponse(
            content: '{"type":"unknown_action","message":"Do something."}',
            success: true,
        );

        $result = $this->planner->parseResponse($response, manifest: $this->manifestWith('remember'));

        $this->assertSame(PlannerResponseType::Refuse, $result->type);
    }

    public function test_skill_name_as_type_without_manifest_keeps_refusing(): void
    {
        $response = new LlmResponse(
            content: '{"type":"remember","arguments":{}}',
            success: true,
        );

        $result = $this->planner->parseResponse($response);

        $this->assertSame(PlannerResponseType::Refuse, $result->type);
    }

    private function manifestWith(string $skillName): SkillManifest
    {
        return new SkillManifest(
            artifact: 'semitexa.ai-skills/v1',
            generatedAt: '2026-03-22T12:00:00+00:00',
            skills: [new SkillEntry(
                name: $skillName,
                sourceCommand: null,
                summary: 'Record something in the user\'s world.',
                useWhen: 'The user asks to remember something.',
                avoidWhen: '',
                riskLevel: AiRiskLevel::Low,
                confirmation: AiConfirmationMode::Never,
                supportsDryRun: false,
                argumentPolicy: AiArgumentPolicy::Allowlisted,
                inputs: [],
                channels: ['web'],
                executionKind: AiExecutionKind::DirectCommand,
                skillClass: 'App\\Fake\\RememberSkill',
            )],
        );
    }
}
