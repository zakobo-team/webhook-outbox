<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\Tests\TestCase;

/**
 * DDL implicitly commits in MySQL, so this class runs without the per-test transaction and drops what it creates.
 */
final class WebhookOutboxMigrationTest extends TestCase
{
    private const string CUSTOM_TABLE = 'custom_outbox_messages';

    protected function tearDown(): void
    {
        DB::connection('mysql')->getSchemaBuilder()->dropIfExists(self::CUSTOM_TABLE);

        parent::tearDown();
    }

    #[Test]
    public function it_creates_the_final_schema_under_the_configured_table_name_with_derived_index_names(): void
    {
        config(['webhook-outbox.table' => self::CUSTOM_TABLE]);

        $this->runOutboxMigration('up');

        $this->assertTrue(Schema::hasTable(self::CUSTOM_TABLE));
        $this->assertSame(
            [
                'id', 'event_id', 'event', 'subscriber', 'url', 'payload', 'log_context', 'status', 'attempts',
                'last_status_code', 'last_error', 'enqueued_at', 'delivered_at', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing(self::CUSTOM_TABLE),
        );
        $indexNames = array_column(Schema::getIndexes(self::CUSTOM_TABLE), 'name');
        foreach (['event_id_subscriber_unique', 'status_index', 'created_at_index', 'enqueued_at_index'] as $suffix) {
            $this->assertContains(self::CUSTOM_TABLE.'_'.$suffix, $indexNames);
        }
        $this->assertSame(1, WebhookOutboxMessage::factory()->create()->id);
        $this->assertSame(1, DB::table(self::CUSTOM_TABLE)->count());
    }

    #[Test]
    public function down_drops_the_table(): void
    {
        config(['webhook-outbox.table' => self::CUSTOM_TABLE]);
        $this->runOutboxMigration('up');

        $this->runOutboxMigration('down');

        $this->assertFalse(Schema::hasTable(self::CUSTOM_TABLE));
    }

    #[Test]
    public function it_runs_on_the_configured_connection_instead_of_the_default_one(): void
    {
        config([
            'database.connections.unrelated' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'unrelated',
            'webhook-outbox.connection' => 'mysql',
            'webhook-outbox.table' => self::CUSTOM_TABLE,
        ]);

        $this->runOutboxMigration('up');

        $this->assertTrue(DB::connection('mysql')->getSchemaBuilder()->hasTable(self::CUSTOM_TABLE));
        $this->assertFalse(DB::connection('unrelated')->getSchemaBuilder()->hasTable(self::CUSTOM_TABLE));
    }

    /** @param  'up'|'down'  $direction */
    private function runOutboxMigration(string $direction): void
    {
        $migration = require __DIR__.'/../../database/migrations/create_webhook_outbox_table.php';

        $migration->{$direction}();
    }
}
