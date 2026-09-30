<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Concerns;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Real multi-process concurrency for tests. Every task runs in its own OS process with its own
 * database connections and transactions, against the database this test is using.
 *
 * Child processes boot a fresh application: runtime config, container bindings, fakes and
 * HTTP guards are not inherited. Capture scalar IDs in task closures, never models, and return
 * serializable values.
 */
trait RunsConcurrentProcesses
{
    /** MySQL ER_LOCK_NOWAIT: a competing transaction already holds the row. */
    protected const int ROW_LOCK_HELD = 3572;

    /**
     * @param  array<int|string, Closure(): mixed>  $tasks
     * @return array<int|string, mixed>
     */
    protected function runConcurrently(array $tasks): array
    {
        $databases = $this->concurrentProcessDatabases();

        foreach ($tasks as $key => $task) {
            $tasks[$key] = static function () use ($task, $databases): mixed {
                foreach ($databases as $connectionName => $databaseName) {
                    config()->set("database.connections.{$connectionName}.database", $databaseName);
                    DB::purge($connectionName);
                }

                return $task();
            };
        }

        return Concurrency::driver('process')->run($tasks, timeout: 60);
    }

    /**
     * Commit the per-test transaction on the default connection so child processes can see this
     * test's fixtures, then reopen it afterwards. Committed rows survive the test: $cleanup must
     * delete them.
     */
    protected function withCommittedFixtures(Closure $test, Closure $cleanup): void
    {
        $connection = DB::connection();

        $this->assertSame(1, $connection->transactionLevel(), 'Expected exactly the per-test transaction to be open.');

        $connection->commit();

        try {
            $test();
        } finally {
            $cleanup();

            $connection->beginTransaction();
        }
    }

    /**
     * Run $callback once, right before the connection executes the first statement matching
     * $sqlPattern (a Str::is wildcard, e.g. 'insert into `posts`*'). Static so a child closure
     * can hook its own process too.
     */
    protected static function onFirstQuery(string $sqlPattern, Closure $callback, ?string $connection = null): void
    {
        $fired = false;

        DB::connection($connection)->beforeExecuting(static function (string $query) use ($sqlPattern, $callback, &$fired): void {
            if ($fired || ! Str::is($sqlPattern, $query)) {
                return;
            }

            $fired = true;
            $callback();
        });
    }

    /**
     * Prove $action holds a lock on the row while executing its first statement matching
     * $sqlPattern: a competing process probing the row with NOWAIT must be refused.
     */
    protected function assertRowLockedDuring(
        string $sqlPattern,
        string $table,
        int|string $rowId,
        Closure $action,
        string $lockMode = 'for update',
    ): void {
        $probeResult = 'never ran';

        $this->onFirstQuery($sqlPattern, function () use (&$probeResult, $table, $rowId, $lockMode): void {
            [$probeResult] = $this->runConcurrently([
                static fn (): ?int => self::probeRowLock($table, $rowId, $lockMode),
            ]);
        });

        $action();

        $this->assertSame(
            self::ROW_LOCK_HELD,
            $probeResult,
            "Row {$table}#{$rowId} was not locked [{$lockMode}] while a statement matching [{$sqlPattern}] ran.",
        );
    }

    /**
     * Try to take the row lock without waiting. Null when acquired, the MySQL error number otherwise.
     */
    private static function probeRowLock(string $table, int|string $rowId, string $lockMode): ?int
    {
        try {
            DB::transaction(static fn () => DB::table($table)->where('id', $rowId)->lock("{$lockMode} nowait")->first());

            return null;
        } catch (QueryException $queryException) {
            return (int) $queryException->errorInfo[1];
        }
    }

    /**
     * Child-side: wait until another transaction holds the row. Lets a racer start only once the
     * holder it must queue behind owns the row. The shared probe counts only an exclusive holder,
     * so a transient shared lock, such as a foreign-key check on the row, cannot start the racer
     * early; probe 'for update' when the holder itself only shares the row.
     */
    protected static function awaitRowLocked(
        string $table,
        int|string $rowId,
        string $probeLockMode = 'for share',
        float $timeoutSeconds = 20,
    ): bool {
        $deadline = microtime(true) + $timeoutSeconds;

        while (self::probeRowLock($table, $rowId, $probeLockMode) !== self::ROW_LOCK_HELD) {
            if (microtime(true) > $deadline) {
                return false;
            }

            usleep(1000);
        }

        return true;
    }

    /**
     * Child-side: wait until another transaction in this database is blocked on a row lock the
     * calling connection holds, in $table or, when null, in any table. Lets a holder proceed only
     * once the racer is parked behind it, which is what a holder/racer or deadlock proof needs.
     * Pass null when the engine may pick any of several rows the holder owns to block on first.
     */
    protected static function awaitLockWaitOn(?string $table, float $timeoutSeconds = 20): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $transactionsBlockedByThisConnection = DB::selectOne(
                'select count(*) as total
                   from performance_schema.data_lock_waits waits
                   join performance_schema.data_locks requested on requested.engine_lock_id = waits.requesting_engine_lock_id
                   join performance_schema.data_locks blocking on blocking.engine_lock_id = waits.blocking_engine_lock_id
                   join performance_schema.threads holder on holder.thread_id = blocking.thread_id
                  where requested.object_schema = database()
                    and (? is null or requested.object_name = ?)
                    and holder.processlist_id = connection_id()',
                [$table, $table],
            )->total;

            if ((int) $transactionsBlockedByThisConnection > 0) {
                return true;
            }

            usleep(1000);
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function concurrentProcessDatabases(): array
    {
        return array_map(
            static fn (Connection $connection): string => $connection->getDatabaseName(),
            DB::getConnections(),
        );
    }
}
