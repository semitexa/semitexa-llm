<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Contract;

use Semitexa\Llm\Domain\Enum\LlmBackend;

interface FactoryLlmProviderInterface
{
    public function getDefault(): LlmProviderInterface;

    public function get(LlmBackend $key): LlmProviderInterface;

    /** @return list<LlmBackend> */
    public function keys(): array;
}
