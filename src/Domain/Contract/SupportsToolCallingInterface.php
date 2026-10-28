<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Contract;

/**
 * A provider that takes LlmRequest::$tools as native declarations and can
 * answer with LlmResponse::$toolCall. One without it ignores the tools and
 * answers in text: a FailoverProvider gives a request with tools only to the
 * providers that carry this.
 */
interface SupportsToolCallingInterface
{
}
