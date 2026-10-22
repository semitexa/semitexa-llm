<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service\Http;

use Semitexa\Core\Http\Client\HttpCallOptions;
use Semitexa\Core\Http\Client\HttpClientInterface;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Client\RetryPolicy;
use Semitexa\Core\Http\Exception\HttpTransportException;
use Semitexa\Llm\Domain\Enum\LlmFailure;

/**
 * The one HTTP exchange every provider makes, over the framework's client:
 * its retries (RetryPolicy: 429, 5xx, a connection that failed — 0.5 s, 1 s,
 * 1.5 s apart, Retry-After honoured up to 5 s), its timeouts, and a transport
 * failure as a status 0 with a failure kind. Each provider used to run its
 * own curl loop, each a little different.
 */
final class ProviderHttp
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, error: string, failure: ?LlmFailure}
     *         status 0 with `error` and `failure` set when no HTTP answer came
     */
    public static function post(HttpClientInterface $http, string $url, array $headers, string $payload, int $timeout, int $connectTimeout, int $retries): array
    {
        return self::send($http, static fn (): HttpRequest => new HttpRequest('POST', $url, self::headers($headers + ['Content-Type' => 'application/json']), $payload), $timeout, $connectTimeout, $retries);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, error: string, failure: ?LlmFailure}
     */
    public static function get(HttpClientInterface $http, string $url, array $headers, int $timeout, int $connectTimeout): array
    {
        return self::send($http, static fn (): HttpRequest => new HttpRequest('GET', $url, self::headers($headers)), $timeout, $connectTimeout, 0);
    }

    /** @return array{status: int, body: string, error: string, failure: ?LlmFailure} */
    private static function send(HttpClientInterface $http, \Closure $request, int $timeout, int $connectTimeout, int $retries): array
    {
        try {
            // A header value with a line break or NUL (a key read with a
            // trailing CR) is refused when the request is built: a failed
            // call, so a reserve can answer, not an exception out of complete().
            $request = $request();
        } catch (\InvalidArgumentException $e) {
            return ['status' => 0, 'body' => '', 'error' => $e->getMessage(), 'failure' => LlmFailure::Unconfigured];
        }
        try {
            $response = $http->send($request, new HttpCallOptions(
                // 0 used to mean "no limit" to curl; an hour is as good and keeps a bound.
                timeout: (float) ($timeout > 0 ? $timeout : 3600),
                connectTimeout: (float) max(1, $connectTimeout),
                retry: RetryPolicy::linear(max(0, $retries) + 1, 500, 5000)->allowingNonIdempotent(),
            ));
        } catch (HttpTransportException $e) {
            return ['status' => 0, 'body' => '', 'error' => $e->detail, 'failure' => LlmFailure::fromTransport($e->kind)];
        }

        return ['status' => $response->status, 'body' => $response->body, 'error' => '', 'failure' => null];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, list<string>>
     */
    private static function headers(array $headers): array
    {
        return array_map(static fn (string $value): array => [$value], $headers);
    }
}
