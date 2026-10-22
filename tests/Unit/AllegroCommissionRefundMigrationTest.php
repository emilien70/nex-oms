<?php

namespace Tests\Unit;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AllegroCommissionRefundMigrationTest extends TestCase
{
    public function test_mysql_8_has_short_constraints_and_unique_line_identity(): void
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
        $migration = require database_path('migrations/2026_10_22_050000_add_allegro_commission_refund_claims.php');
        $queries = $connection->pretend(fn () => $migration->up());
        $sql = implode("\n", array_column($queries, 'query'));
        foreach (['acrc_account_fk' => 'allegro_accounts', 'acrc_order_fk' => 'orders', 'acrc_return_fk' => 'order_returns'] as $key => $table) {
            $this->assertStringContainsString('constraint `'.$key.'` foreign key', $sql);
            $this->assertStringContainsString('references `'.$table.'` (`id`) on delete set null', $sql);
        }
        $this->assertStringContainsString('add unique `acrc_line_unique`(`environment`, `seller_user_id`, `line_item_id`)', $sql);
        $this->assertStringContainsString('add unique `acrc_external_unique`(`environment`, `seller_user_id`, `external_claim_id`)', $sql);
        preg_match_all('/(?:constraint|(?:unique|index)) `([^`]+)`/', $sql, $matches);
        foreach ($matches[1] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Automatyczny rollback jest zablokowany.');
        $migration->down();
    }
}
