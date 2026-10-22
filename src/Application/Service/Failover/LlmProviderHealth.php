<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service\Failover;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Environment;
use Swoole\Table;

/**
 * Which models are set aside, and until when: a model whose failure will
 * repeat (no key, a refused key, no credit) is skipped by every
 * FailoverProvider instead of being asked first on every call.
 *
 * Under the server the state lives in a Swoole table the master creates
 * before the workers fork, so every worker skips the same model and a reset
 * (a key or credit fixed in a console) reaches all of them. Kept in a static
 * array per worker, a reset reached only the worker that handled it, and the
 * others went on refusing a working model for minutes. In a command, the
 * process's own memory.
 */
#[AsService]
final class LlmProviderHealth
{
    public const TABLE_STATE_KEY = 'llm.provider_health_table';

    private const ROWS = 256;

    private ?Table $table = null;

    /** @var array<string, int> key hash → unix time the bench ends */
    private array $local = [];

    /** Created in the master, before the workers fork (CreateLlmHealthTableListener). */
    public static function createTable(): Table
    {
        $table = new Table(self::ROWS);
        $table->column('until', Table::TYPE_INT);
        $table->create();

        return $table;
    }

    public function useTable(Table $table): void
    {
        $this->table = $table;
    }

    /** How long a lasting failure sets a model aside: LLM_BENCH_SECONDS, 300 by default, 0 = never. */
    public static function benchSeconds(): int
    {
        $value = trim((string) Environment::getEnvValue('LLM_BENCH_SECONDS', ''));

        return ctype_digit($value) ? min((int) $value, 86_400) : 300;
    }

    /** How long a model that did not answer at all is set aside: LLM_BENCH_TRANSIENT_SECONDS, 30 by default, 0 = never. */
    public static function transientBenchSeconds(): int
    {
        $value = trim((string) Environment::getEnvValue('LLM_BENCH_TRANSIENT_SECONDS', ''));

        return ctype_digit($value) ? min((int) $value, 3600) : 30;
    }

    public function bench(string $key, int $seconds, ?int $now = null): void
    {
        if ($seconds <= 0) {
            return;
        }
        $until = ($now ?? time()) + $seconds;
        $id = self::id($key);
        if ($this->table !== null) {
            if (!@$this->table->set($id, ['until' => $until])) {
                // Full: drop what has expired and try once more. Still full,
                // the bench is forgotten — the model is asked as before,
                // never wrongly skipped.
                $this->sweep($now ?? time());
                @$this->table->set($id, ['until' => $until]);
            }

            return;
        }
        $this->local[$id] = $until;
    }

    public function isBenched(string $key, ?int $now = null): bool
    {
        $id = self::id($key);
        $until = $this->table !== null
            ? (int) ($this->table->get($id, 'until') ?: 0)
            : ($this->local[$id] ?? 0);

        return $until > ($now ?? time());
    }

    /**
     * Ask every model again, or the one named: after its key or credit was
     * fixed. Called in the server it reaches every worker; a console command
     * has a process of its own and clears only that.
     */
    public function reset(?string $key = null): void
    {
        if ($key !== null) {
            $this->table?->del(self::id($key));
            unset($this->local[self::id($key)]);

            return;
        }
        if ($this->table !== null) {
            $ids = [];
            foreach ($this->table as $id => $row) {
                $ids[] = (string) $id;
            }
            foreach ($ids as $id) {
                $this->table->del($id);
            }
        }
        $this->local = [];
    }

    private function sweep(int $now): void
    {
        if ($this->table === null) {
            return;
        }
        $expired = [];
        foreach ($this->table as $id => $row) {
            if ((int) ($row['until'] ?? 0) <= $now) {
                $expired[] = (string) $id;
            }
        }
        foreach ($expired as $id) {
            $this->table->del($id);
        }
    }

    /** A table key is at most 63 bytes; `provider:model` can be longer. */
    private static function id(string $key): string
    {
        return md5($key);
    }
}
