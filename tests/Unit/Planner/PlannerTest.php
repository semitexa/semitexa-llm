<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Planner;

use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Data\LlmResponse;
use Semitexa\Llm\Data\PlannerResponseType;
use Semitexa\Llm\Data\SkillManifest;
use Semitexa\Llm\Planner\Planner;

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
}
