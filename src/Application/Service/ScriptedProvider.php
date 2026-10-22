<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Contract\SupportsToolCallingInterface;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;

/**
 * A provider that answers with the replies it was given, in order — never a
 * network call. For tests, and for demos that must behave the same every time
 * (a repair loop shown with a first answer known to be wrong). Every request it
 * received is kept, so a test can read what the model would have been sent.
 */
final class ScriptedProvider implements LlmProviderInterface, SupportsToolCallingInterface
{
    /** @var list<LlmRequest> */
    public array $requests = [];

    /**
     * @param list<string|LlmResponse> $replies a string answers with that text;
     *        an LlmResponse is returned as it is — a scripted failure, or a tool call
     */
    public function __construct(
        private array $replies,
        private readonly string $name = 'scripted',
        private readonly string $model = 'scripted',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function baseUrl(): string
    {
        return '';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function healthCheck(): bool
    {
        return true;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;
        $reply = array_shift($this->replies);

        return match (true) {
            $reply === null => new LlmResponse('', false, 'The script has no more replies.'),
            $reply instanceof LlmResponse => $reply,
            default => new LlmResponse($reply, true),
        };
    }
}
