<?php

namespace Tests\Unit;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class OrderReturnCorrectionMigrationTest extends TestCase
{
    public function test_sqlite_migration_preserves_existing_rows_and_unique_link_survives_parent_deletion(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE order_returns (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, number TEXT NOT NULL)');
        $pdo->exec("INSERT INTO invoices VALUES (1, 'FV 1'), (2, 'FV 2')");
        $pdo->exec('INSERT INTO order_returns VALUES (10)');
        $connection = new SQLiteConnection($pdo);
        Schema::swap($connection->getSchemaBuilder());
        $migration = require database_path('migrations/2026_10_22_060000_link_return_corrections_to_invoices.php');
        $migration->up();
        $this->assertSame(['FV 1', 'FV 2'], $pdo->query('SELECT number FROM invoices ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE order_return_id IS NOT NULL')->fetchColumn());
        $pdo->exec('UPDATE invoices SET order_return_id = 10 WHERE id = 1');
        try {
            $pdo->exec('UPDATE invoices SET order_return_id = 10 WHERE id = 2');
            $this->fail('A return cannot belong to two documents.');
        } catch (\PDOException $exception) {
            $this->assertStringContainsString('UNIQUE', $exception->getMessage());
        }
        $pdo->exec('DELETE FROM order_returns WHERE id = 10');
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE order_return_id IS NOT NULL')->fetchColumn());
    }

    public function test_mysql_adds_nullable_unique_return_link_without_removing_history(): void
    {
        $connection = new class(fn () => throw new RuntimeException('No connection allowed.'), 'preview') extends MySqlConnection
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
        $migration = require database_path('migrations/2026_10_22_060000_link_return_corrections_to_invoices.php');
        $queries = $connection->pretend(fn () => $migration->up());
        $sql = implode("\n", array_column($queries, 'query'));
        $this->assertStringContainsString('add `order_return_id` bigint unsigned null', $sql);
        $this->assertStringContainsString('unique `invoices_return_unique`(`order_return_id`)', $sql);
        $this->assertStringContainsString('constraint `invoices_return_fk` foreign key (`order_return_id`) references `order_returns` (`id`) on delete set null', $sql);
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
