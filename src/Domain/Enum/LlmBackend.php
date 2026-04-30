<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Enum;

enum LlmBackend: string
{
    case Local = 'local';
    case RemoteOllama = 'remote_ollama';
}
