<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Failover;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\Failover\LlmProviderHealth;
use Semitexa\Llm\Domain\Enum\LlmFailure;

/** Why a call failed decides whether its model is set aside: from the status and the answer, not the wording. */
final class LlmFailureTest extends TestCase
{
    #[Test]
    public function a_failure_is_read_from_what_the_api_answered(): void
    {
        self::assertSame(LlmFailure::Timeout, LlmFailure::fromHttp(0, 'Operation timed out after 30001 milliseconds'));
        self::assertSame(LlmFailure::Network, LlmFailure::fromHttp(0, 'Could not resolve host: api.example'));
        self::assertSame(LlmFailure::Unauthorized, LlmFailure::fromHttp(401));
        self::assertSame(LlmFailure::NoCredit, LlmFailure::fromHttp(402));
        self::assertSame(LlmFailure::NoCredit, LlmFailure::fromHttp(400, '', '{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low to access the Anthropic API."}}'));
        self::assertSame(LlmFailure::NoCredit, LlmFailure::fromHttp(400, '', '{"type":"error","error":{"type":"invalid_request_error","message":"You have reached your specified API usage limits."}}'), 'a spend cap');
        self::assertSame(LlmFailure::Unauthorized, LlmFailure::fromHttp(400, '', '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT","details":[{"reason":"API_KEY_INVALID"}]}}'));
        self::assertSame(LlmFailure::Unauthorized, LlmFailure::fromHttp(403, '', '{"error":{"code":403,"status":"PERMISSION_DENIED","message":"denied"}}'));
        self::assertSame(LlmFailure::Unconfigured, LlmFailure::fromHttp(404, '', '{"error":"model \'gemma\' not found"}'));
        self::assertSame(LlmFailure::ServerError, LlmFailure::fromHttp(529, '', '{"error":{"type":"overloaded_error"}}'));
        self::assertSame(LlmFailure::BadRequest, LlmFailure::fromHttp(400, '', '{"error":{"type":"invalid_request_error","message":"prompt is too long"}}'));
    }

    /** Gemini's every 429 says "check your plan and billing details": read as no credit, a burst set Gemini aside for minutes. */
    #[Test]
    public function the_status_decides_before_the_text(): void
    {
        $minute = '{"error":{"code":429,"message":"You exceeded your current quota, please check your plan and billing details.","status":"RESOURCE_EXHAUSTED","details":[{"violations":[{"quotaId":"GenerateRequestsPerMinutePerProjectPerModel"}]}]}}';
        $day = '{"error":{"code":429,"message":"You exceeded your current quota, please check your plan and billing details.","status":"RESOURCE_EXHAUSTED","details":[{"violations":[{"quotaId":"GenerateRequestsPerDayPerProjectPerModel"}]}]}}';

        self::assertSame(LlmFailure::RateLimited, LlmFailure::fromHttp(429, '', $minute));
        self::assertSame(LlmFailure::QuotaExhausted, LlmFailure::fromHttp(429, '', $day), 'a daily quota will not reset in a minute');
        self::assertSame(LlmFailure::ServerError, LlmFailure::fromHttp(500, '', '{"error":{"message":"billing backend unavailable"}}'));
        self::assertSame(LlmFailure::BadRequest, LlmFailure::fromHttp(400, '', '{"error":{"message":"Unknown field billing_address"}}'));
    }

    #[Test]
    public function only_what_will_repeat_sets_a_model_aside(): void
    {
        $lasting = array_values(array_filter(LlmFailure::cases(), static fn (LlmFailure $f): bool => $f->isLasting()));

        self::assertSame([LlmFailure::Unconfigured, LlmFailure::Unauthorized, LlmFailure::NoCredit, LlmFailure::QuotaExhausted], $lasting, 'a refused request is that request\'s: another may pass');
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function the_shared_table_is_one_state_for_every_holder(): void
    {
        $table = LlmProviderHealth::createTable();
        $worker1 = new LlmProviderHealth();
        $worker1->useTable($table);
        $worker2 = new LlmProviderHealth();
        $worker2->useTable($table);

        $worker1->bench('anthropic:' . str_repeat('m', 100), 300);
        self::assertTrue($worker2->isBenched('anthropic:' . str_repeat('m', 100)), 'a key longer than a table key still works');

        $worker2->reset();
        self::assertFalse($worker1->isBenched('anthropic:' . str_repeat('m', 100)), 'a reset reaches every worker');
        self::assertFalse($worker1->isBenched('x', time()), 'an unknown model is not set aside');
    }
}
