<?php

namespace Tests\Feature\Database;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Products\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PrestashopProductLinkIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('mysqlOperations')]
    public function test_mysql_creates_replacement_index_before_dropping_existing_index(string $direction, array $expected): void
    {
        $this->assertSame($expected, $this->mysqlSql(fn () => $this->migration()->{$direction}()));
    }

    public static function mysqlOperations(): array
    {
        return [
            'up' => ['up', [
                'alter table `prestashop_product_links` add index `prestashop_product_links_product_account_index`(`product_id`, `prestashop_account_id`)',
                'alter table `prestashop_product_links` drop index `prestashop_product_links_product_account_unique`',
            ]],
            'down' => ['down', [
                'alter table `prestashop_product_links` add unique `prestashop_product_links_product_account_unique`(`product_id`, `prestashop_account_id`)',
                'alter table `prestashop_product_links` drop index `prestashop_product_links_product_account_index`',
            ]],
        ];
    }

    public function test_round_trip_preserves_rows_foreign_keys_and_external_offer_uniqueness(): void
    {
        [$product, $account] = $this->seedLinks();
        $rows = $this->rows();
        $foreignKeys = Schema::getForeignKeys('prestashop_product_links');
        $this->assertCount(2, $foreignKeys);
        $migration = $this->migration();

        $migration->down();
        $this->assertTrue(Schema::hasIndex('prestashop_product_links', 'prestashop_product_links_product_account_unique', 'unique'));
        $this->assertFalse(Schema::hasIndex('prestashop_product_links', 'prestashop_product_links_product_account_index'));
        $this->assertSame($rows, $this->rows());
        $this->assertEqualsCanonicalizing($foreignKeys, Schema::getForeignKeys('prestashop_product_links'));

        $migration->up();
        $indexes = collect(Schema::getIndexes('prestashop_product_links'))->keyBy('name');
        $this->assertSame(['product_id', 'prestashop_account_id'], $indexes['prestashop_product_links_product_account_index']['columns']);
        $this->assertFalse($indexes['prestashop_product_links_product_account_index']['unique']);
        $this->assertArrayNotHasKey('prestashop_product_links_product_account_unique', $indexes->all());
        $this->assertTrue($indexes['prestashop_product_links_external_unique']['unique']);
        $this->assertSame($rows, $this->rows());
        $this->assertEqualsCanonicalizing($foreignKeys, Schema::getForeignKeys('prestashop_product_links'));

        $product->prestashopProductLinks()->create(['prestashop_account_id' => $account->id, 'external_product_id' => 102, 'external_product_attribute_id' => 0]);
        $this->assertDatabaseCount('prestashop_product_links', 2);
    }

    public function test_down_with_duplicates_stops_before_schema_changes_and_keeps_all_rows(): void
    {
        $this->seedLinks(2);
        $rows = $this->rows();
        $indexes = Schema::getIndexes('prestashop_product_links');
        $foreignKeys = Schema::getForeignKeys('prestashop_product_links');
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            $this->migration()->down();
            $this->fail('Duplicate links must prevent restoring uniqueness.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Nie można przywrócić pojedynczego powiązania', $exception->getMessage());
        } finally {
            $queries = $connection->getQueryLog();
            $connection->disableQueryLog();
        }

        $this->assertSame([], array_values(array_filter($queries, fn ($query) => preg_match('/^\s*(alter|create|drop)\b/i', $query['query']))));
        $this->assertSame($rows, $this->rows());
        $this->assertEqualsCanonicalizing($indexes, Schema::getIndexes('prestashop_product_links'));
        $this->assertEqualsCanonicalizing($foreignKeys, Schema::getForeignKeys('prestashop_product_links'));
    }

    #[DataProvider('deletedParents')]
    public function test_round_trip_keeps_cascade_for_both_parent_relations(string $parent): void
    {
        [$product, $account] = $this->seedLinks();
        $migration = $this->migration();
        $migration->down();
        $migration->up();

        ($parent === 'product' ? $product : $account)->delete();
        $this->assertDatabaseCount('prestashop_product_links', 0);
        $this->assertDatabaseHas($parent === 'product' ? 'prestashop_accounts' : 'products', ['id' => $parent === 'product' ? $account->id : $product->id]);
    }

    public static function deletedParents(): array
    {
        return [['product'], ['account']];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_19_010000_allow_multiple_prestashop_product_links_per_product.php');
    }

    private function mysqlSql(Closure $operation): array
    {
        $schema = Schema::getFacadeRoot();
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
        try {
            return array_column($connection->pretend($operation), 'query');
        } finally {
            Schema::swap($schema);
        }
    }

    private function seedLinks(int $count = 1): array
    {
        $product = Product::query()->create(['name' => 'Produkt testowy', 'price_gross' => '99.99', 'currency' => 'PLN', 'vat_rate' => '23.00', 'stock_quantity' => 10]);
        $account = PrestashopAccount::query()->create(['account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY, 'name' => 'Test',
            'base_url' => 'https://example.test', 'api_key' => str_repeat('1', 32), 'is_active' => true]);
        for ($i = 0; $i < $count; $i++) {
            $product->prestashopProductLinks()->create(['prestashop_account_id' => $account->id, 'external_product_id' => 100 + $i, 'external_product_attribute_id' => 0]);
        }

        return [$product, $account];
    }

    private function rows(): array
    {
        return DB::table('prestashop_product_links')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
}
