<?php

namespace Tests\Feature\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class MySqlMigrationIdentifiersTest extends TestCase
{
    #[DataProvider('repairedMigrations')]
    public function test_repaired_migrations_compile_mysql_sql_without_changing_relations(string $file, array $expectedSql, ?string $droppedTable): void
    {
        $connection = new class(fn () => throw new RuntimeException('SQL preview must not connect to a database.'), 'schema_preview') extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.46';
            }
        };
        Schema::swap($connection->getSchemaBuilder());
        $migration = require database_path('migrations/'.$file.'.php');
        $queries = $connection->pretend(fn () => $migration->up());
        $sql = implode("\n", array_column($queries, 'query'));
        foreach ($expectedSql as $expected) {
            $this->assertStringContainsString($expected, $sql);
        }
        $this->assertStringNotContainsString('on update', $sql);
        if ($droppedTable === null) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cofniecie wymaga osobnej migracji danych.');
            $migration->down();
        } else {
            $down = $connection->pretend(fn () => $migration->down());
            $this->assertStringContainsString('drop table if exists `'.$droppedTable.'`', implode("\n", array_column($down, 'query')));
        }
    }

    public static function repairedMigrations(): array
    {
        return [
            'counter adjustments' => ['2026_07_28_020100_create_invoice_number_counter_adjustments_table', [
                'constraint `inv_num_adj_counter_fk` foreign key (`invoice_number_counter_id`) references `invoice_number_counters` (`id`) on delete cascade',
                '`invoice_number_counter_id` bigint unsigned not null',
                '`previous_protected_floor_sequence_number` bigint unsigned not null',
                '`created_at` timestamp not null default CURRENT_TIMESTAMP',
            ], 'invoice_number_counter_adjustments'],
            'offline certificate selections' => ['2026_08_13_077000_create_ksef_offline_certificate_tables', [
                'constraint `ksef_off_cert_selection_cert_fk` foreign key (`offline_certificate_id`) references `ksef_offline_certificates` (`id`) on delete cascade',
                'add unique `ksef_off_cert_selection_cert_uq`(`offline_certificate_id`)',
            ], 'ksef_offline_certificate_selections'],
            'offline technical corrections' => ['2026_08_13_088000_create_ksef_offline_technical_corrections', [
                'constraint `ksef_off_tech_rejected_sub_fk` foreign key (`rejected_submission_id`) references `ksef_invoice_submissions` (`id`) on delete restrict',
            ], 'ksef_offline_technical_corrections'],
            'email attachments' => ['2026_09_28_010000_create_order_email_message_tables', [
                'add unique `order_email_attachment_message_position_uq`(`order_email_message_id`, `position`)',
            ], 'order_email_message_attachments'],
            'bundle components' => ['2026_10_16_030000_create_product_bundles_tables', [
                'add unique `order_bundle_item_component_uq`(`order_item_id`, `component_product_id`)',
                'add index `order_bundle_product_item_idx`(`bundle_product_id`, `order_item_id`)',
            ], 'order_item_bundle_components'],
            'refund allocations' => ['2026_10_22_030000_add_allegro_payment_refunds', [
                'constraint `return_refund_allocation_refund_fk` foreign key (`allegro_payment_refund_id`) references `allegro_payment_refunds` (`id`) on delete restrict',
            ], null],
        ];
    }

    public function test_fresh_migrations_keep_generated_and_explicit_key_names_within_mysql_limit(): void
    {
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $this->assertFalse(Schema::hasTable('migrations'));
        $migration = 'migration repository';
        $names = [];
        $violations = [];
        Event::listen(MigrationStarted::class, function (MigrationStarted $event) use (&$migration): void {
            $migration = basename((new ReflectionClass($event->migration))->getFileName());
        });
        $inspect = function (Blueprint $blueprint) use (&$migration, &$names, &$violations): void {
            foreach ($blueprint->getCommands() as $command) {
                if (! in_array($command->name, ['foreign', 'index', 'unique', 'fulltext', 'spatialIndex', 'dropForeign', 'dropIndex', 'dropUnique', 'renameIndex'], true)) {
                    continue;
                }
                foreach (['index', 'from', 'to'] as $attribute) {
                    $name = $command->{$attribute};
                    if (is_string($name)) {
                        $names[$blueprint->getTable().':'.$name] = $name;
                        if (mb_strlen($name) > 64) {
                            $violations[] = $migration.': '.$name.' ('.mb_strlen($name).')';
                        }
                    }
                }
            }
        };
        $schema = $connection->getSchemaBuilder();
        $schema->blueprintResolver(function (Connection $connection, string $table, ?Closure $callback = null) use ($inspect): Blueprint {
            return new class($connection, $table, $callback, $inspect) extends Blueprint
            {
                public function __construct(Connection $connection, string $table, ?Closure $callback, private readonly Closure $inspect)
                {
                    parent::__construct($connection, $table, $callback);
                }

                public function toSql()
                {
                    $sql = parent::toSql();
                    ($this->inspect)($this);

                    return $sql;
                }
            };
        });
        Schema::swap($schema);

        // Only the isolated in-memory database is migrated; SQLite also keeps names MySQL would reject.
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertArrayHasKey('invoice_number_counter_adjustments:inv_num_adj_counter_fk', $names);
        $this->assertGreaterThan(100, count($names));
        $this->assertSame([], array_values(array_unique($violations)), implode("\n", array_unique($violations)));
    }
}
