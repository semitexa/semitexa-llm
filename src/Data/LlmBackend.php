<?php

declare(strict_types=1);

namespace Semitexa\Llm\Data;

enum LlmBackend: string
{
    case Local = 'local';
    case RemoteOllama = 'remote_ollama';
}
