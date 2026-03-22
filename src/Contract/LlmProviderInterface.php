<?php

declare(strict_types=1);

namespace Semitexa\Llm\Contract;

use Semitexa\Llm\Data\LlmRequest;
use Semitexa\Llm\Data\LlmResponse;

interface LlmProviderInterface
{
    public function name(): string;

    public function healthCheck(): bool;

    public function complete(LlmRequest $request): LlmResponse;
}
