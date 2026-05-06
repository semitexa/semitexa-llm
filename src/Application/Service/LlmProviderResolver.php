<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Llm\Domain\Contract\FactoryLlmProviderInterface;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Enum\LlmBackend;

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
