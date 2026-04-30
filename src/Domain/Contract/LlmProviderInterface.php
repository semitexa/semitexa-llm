<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Contract;

use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;

interface LlmProviderInterface
{
    public function name(): string;

    public function baseUrl(): string;

    public function model(): string;

    public function healthCheck(): bool;

    public function complete(LlmRequest $request): LlmResponse;
}
