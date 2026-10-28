<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service\Server;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Llm\Application\Service\Failover\LlmProviderHealth;
use Swoole\Table;

/** Gives this worker's LlmProviderHealth the table every worker shares. */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterServerBindings->value,
    priority: 0,
    requiresContainer: true,
)]
final class InstallLlmHealthTableListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        $table = $context->bootstrapState?->get(LlmProviderHealth::TABLE_STATE_KEY);
        $container = $context->container;
        if (!$table instanceof Table || $container === null || !$container->has(LlmProviderHealth::class)) {
            return;
        }
        $health = $container->get(LlmProviderHealth::class);
        if ($health instanceof LlmProviderHealth) {
            $health->useTable($table);
        }
    }
}
