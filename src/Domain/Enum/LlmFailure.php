<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Enum;

use Semitexa\Core\Http\Client\HttpTransportKind;

/**
 * Why a model call failed, as a provider tells it — what a FailoverProvider
 * decides on. Reading it from the error text broke as soon as a provider
 * worded its error differently, and a model out of credit was then asked
 * again on every call before the one that works.
 */
enum LlmFailure: string
{
    /** No API key, or a model or endpoint that does not exist. */
    case Unconfigured = 'unconfigured';
    /** The key was refused (401/403). */
    case Unauthorized = 'unauthorized';
    /** The account has no credit left. */
    case NoCredit = 'no_credit';
    /** Too many requests (429): later may work. */
    case RateLimited = 'rate_limited';
    /** A daily quota used up (429 on a per-day limit): not before tomorrow. */
    case QuotaExhausted = 'quota_exhausted';
    case Timeout = 'timeout';
    /** No answer at all: DNS, connection refused, TLS. */
    case Network = 'network';
    /** 5xx, overloaded. */
    case ServerError = 'server_error';
    /** This request was refused (400, too long, could not be encoded); another may pass. */
    case BadRequest = 'bad_request';
    /** The model declined to answer, or the prompt was blocked. */
    case Refused = 'refused';
    /** An answer with nothing usable in it. */
    case Malformed = 'malformed';

    /**
     * Will the next call fail the same way, whatever it asks? Then the model
     * is set aside for a while instead of being asked first every time.
     */
    public function isLasting(): bool
    {
        return match ($this) {
            self::Unconfigured, self::Unauthorized, self::NoCredit, self::QuotaExhausted => true,
            default => false,
        };
    }

    /**
     * No answer at all — a hung or unreachable endpoint. Not lasting, but
     * every call would wait out the same timeout before the next model:
     * such a model is set aside briefly (LLM_BENCH_TRANSIENT_SECONDS).
     */
    public function isUnreachable(): bool
    {
        return $this === self::Timeout || $this === self::Network;
    }

    /**
     * A failed HTTP exchange. $transportError is curl's error text (empty
     * when an HTTP answer arrived); $body the answer.
     *
     * The status decides first: a 429 is a rate limit and a 5xx a server
     * error whatever their text says — Gemini's 429 always mentions "plan and
     * billing details", and read as "no credit" it set Gemini aside for
     * minutes over a per-minute burst. Only a 400, 402 or 403 is read
     * further, from the API's structured error fields.
     */
    public static function fromHttp(int $status, string $transportError = '', string $body = ''): self
    {
        if ($transportError !== '' || $status === 0) {
            return stripos($transportError, 'timed out') !== false || stripos($transportError, 'timeout') !== false
                ? self::Timeout
                : self::Network;
        }
        if ($status === 429) {
            // Gemini names the quota it hit; a per-day one will not reset in a minute.
            return preg_match('/PerDay|per_day|daily/i', $body) === 1 ? self::QuotaExhausted : self::RateLimited;
        }
        if ($status >= 500) {
            return self::ServerError;
        }

        return match ($status) {
            401 => self::Unauthorized,
            402 => self::NoCredit,
            404 => self::Unconfigured,
            408 => self::Timeout,
            400, 403 => self::fromErrorBody($status, $body),
            default => self::BadRequest,
        };
    }

    /** No HTTP answer came: what the client says went wrong. */
    public static function fromTransport(HttpTransportKind $kind): self
    {
        return match ($kind) {
            HttpTransportKind::Timeout => self::Timeout,
            HttpTransportKind::TooLarge => self::Malformed,
            default => self::Network,
        };
    }

    /** A 400 or 403 by the API's own error fields: Anthropic `error.type`/`message`, Gemini `error.status`/`details[].reason`. */
    private static function fromErrorBody(int $status, string $body): self
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $reasons = [];
        foreach (is_array($error['details'] ?? null) ? $error['details'] : [] as $detail) {
            if (is_array($detail) && is_string($detail['reason'] ?? null)) {
                $reasons[] = $detail['reason'];
            }
        }
        $type = is_string($error['type'] ?? null) ? $error['type'] : '';
        $state = is_string($error['status'] ?? null) ? $error['status'] : '';
        $message = strtolower(is_string($error['message'] ?? null) ? $error['message'] : '');

        return match (true) {
            in_array('API_KEY_INVALID', $reasons, true), $type === 'authentication_error',
            $state === 'PERMISSION_DENIED', $type === 'permission_error' => self::Unauthorized,
            // Anthropic answers an empty balance and a reached spend cap with 400.
            str_contains($message, 'credit balance'), str_contains($message, 'usage limits') => self::NoCredit,
            $status === 403 => self::Unauthorized,
            // Gemini: billing not enabled, a region not served — every call
            // fails the same way until someone changes the account.
            $state === 'FAILED_PRECONDITION' => self::Unconfigured,
            default => self::BadRequest,
        };
    }
}
