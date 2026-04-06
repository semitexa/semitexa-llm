<?php

declare(strict_types=1);

namespace Semitexa\Llm\Provider;

use Semitexa\Llm\Contract\FactoryLlmProviderInterface;
use Semitexa\Llm\Contract\LlmProviderInterface;
use Semitexa\Llm\Data\LlmBackend;

final class LlmProviderResolver
{
    public function __construct(
        private readonly FactoryLlmProviderInterface $factory,
        private readonly string $backend = 'local',
    ) {}

    public function provider(): LlmProviderInterface
    {
        return $this->factory->get(LlmBackend::from($this->backend));
    }
}
