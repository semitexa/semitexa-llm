<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service\Server;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Llm\Application\Service\Failover\LlmProviderHealth;
use Swoole\Table;

/**
 * Creates the table of set-aside models in the master, before the workers
 * fork: a table made in a worker is that worker's alone.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::PreStart->value,
    priority: 0,
    requiresContainer: false,
)]
final class CreateLlmHealthTableListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        if ($context->bootstrapState === null || !class_exists(Table::class, false)) {
            return;
        }

        $context->bootstrapState->set(LlmProviderHealth::TABLE_STATE_KEY, LlmProviderHealth::createTable());
    }
}
