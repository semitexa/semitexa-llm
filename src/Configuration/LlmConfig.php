<?php

declare(strict_types=1);

namespace Semitexa\Llm\Configuration;

use Semitexa\Core\Environment;

readonly class LlmConfig
{
    public function __construct(
        public string $provider = 'ollama',
        public string $baseUrl = 'http://127.0.0.1:11434',
        public string $model = 'gemma3:4b',
        public int $timeout = 60,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            provider: Environment::getEnvValue('LLM_PROVIDER', 'ollama'),
            baseUrl: Environment::getEnvValue('LLM_BASE_URL', 'http://127.0.0.1:11434'),
            model: Environment::getEnvValue('LLM_MODEL', 'gemma3:4b'),
            timeout: (int) Environment::getEnvValue('LLM_TIMEOUT', '60'),
        );
    }
}
