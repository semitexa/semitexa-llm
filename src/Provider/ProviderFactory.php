<?php

declare(strict_types=1);

namespace Semitexa\Llm\Provider;

use Semitexa\Llm\Configuration\LlmConfig;
use Semitexa\Llm\Contract\LlmProviderInterface;

final class ProviderFactory
{
    public static function create(LlmConfig $config): LlmProviderInterface
    {
        return match ($config->provider) {
            'ollama' => new OllamaProvider($config),
            default => throw new \RuntimeException("Unknown LLM provider: {$config->provider}"),
        };
    }
}
