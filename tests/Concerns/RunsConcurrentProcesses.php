<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Concerns;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

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
