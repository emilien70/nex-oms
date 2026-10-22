<?php

namespace Tests\Unit;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AllegroRefundCommandMigrationTest extends TestCase
{
    public function test_mysql_8_schema_preserves_money_and_uses_short_explicit_constraints(): void
    {
        $connection = new class(fn () => throw new RuntimeException('Preview must not connect.'), 'schema_preview') extends MySqlConnection
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
        $migration = require database_path('migrations/2026_10_22_040000_add_allegro_refund_commands.php');
        $queries = $connection->pretend(fn () => $migration->up());
        $sql = implode("\n", array_column($queries, 'query'));
        foreach (['arc_account_fk' => 'allegro_accounts', 'arc_order_fk' => 'orders', 'arc_return_fk' => 'order_returns'] as $key => $table) {
            $this->assertStringContainsString('constraint `'.$key.'` foreign key', $sql);
            $this->assertStringContainsString('references `'.$table.'` (`id`) on delete set null', $sql);
        }
        $this->assertStringContainsString('references `allegro_payment_refunds` (`id`) on delete restrict', $sql);
        $this->assertStringContainsString('`refund_write_enabled` tinyint(1) not null default \'0\'', $sql);
        $this->assertStringContainsString('`total_value` decimal(18, 2) not null', $sql);
        $this->assertStringContainsString('add unique `arc_command_unique`(`command_id`)', $sql);
        $this->assertStringContainsString('add unique `arc_external_unique`(`environment`, `seller_user_id`, `external_refund_id`)', $sql);
        preg_match_all('/(?:constraint|(?:unique|index)) `([^`]+)`/', $sql, $matches);
        foreach ($matches[1] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Automatyczny rollback jest zablokowany.');
        $migration->down();
    }
}
