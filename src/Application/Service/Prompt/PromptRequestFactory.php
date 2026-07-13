<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service\Prompt;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Model\PromptMessage;
use Semitexa\Prompt\Domain\Model\RenderedPrompt;

/**
 * Bridges the provider-agnostic prompt catalog (semitexa/prompt) into the LLM
 * layer: renders a catalog prompt and maps the result onto an {@see LlmRequest}.
 *
 * This is the single seam that turns a catalog entry into something a provider
 * can send. A prompt's few-shot examples become the leading {@see
 * LlmRequest::$history}; the caller supplies the live user turn and any native
 * tool declarations.
 */
#[AsService]
final class PromptRequestFactory
{
    #[InjectAsReadonly]
    protected PromptRenderer $renderer;

    /**
     * Render a catalog prompt by id and build the request.
     *
     * @param array<string, string> $variables
     * @param list<array{name: string, description: string, parameters: array<string, mixed>}> $tools
     */
    public function fromPrompt(string $promptId, string $userMessage, array $variables = [], array $tools = []): LlmRequest
    {
        return $this->fromRendered($this->renderer->render($promptId, $variables), $userMessage, $tools);
    }

    /**
     * Map an already-rendered prompt onto a request. Pure mapping — no rendering.
     *
     * @param list<array{name: string, description: string, parameters: array<string, mixed>}> $tools
     */
    public function fromRendered(RenderedPrompt $rendered, string $userMessage, array $tools = []): LlmRequest
    {
        $history = array_map(
            static fn(PromptMessage $message): array => $message->toArray(),
            $rendered->messages,
        );

        return new LlmRequest(
            systemPrompt: $rendered->system,
            userMessage: $userMessage,
            history: $history,
            tools: $tools,
        );
    }
}
