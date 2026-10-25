<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntegrationDefaultVatMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_nullable_settings_preserve_existing_accounts_and_rollback_only_removes_the_settings(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $migration = require database_path('migrations/2026_10_25_010000_add_default_vat_rate_to_integration_accounts.php');
        $migration->down();

        foreach (['allegro_accounts', 'prestashop_accounts'] as $table) {
            $attributes = ['account_key' => 'vat-test', 'name' => 'Existing account'];
            if ($table === 'prestashop_accounts') {
                $attributes['base_url'] = 'https://store.example.test';
            }
            DB::table($table)->insert($attributes);
        }
        $before = [];
        foreach (['allegro_accounts', 'prestashop_accounts'] as $table) {
            $before[$table] = (array) DB::table($table)->where('account_key', 'vat-test')->first();
        }

        $migration->up();
        foreach ($before as $table => $attributes) {
            $this->assertTrue(Schema::hasColumn($table, 'default_vat_rate'));
            $this->assertSame($attributes + ['default_vat_rate' => null], (array) DB::table($table)->where('account_key', 'vat-test')->first());
            DB::table($table)->where('account_key', 'vat-test')->update(['default_vat_rate' => '0.00']);
        }

        $migration->down();
        foreach ($before as $table => $attributes) {
            $this->assertFalse(Schema::hasColumn($table, 'default_vat_rate'));
            $this->assertSame($attributes, (array) DB::table($table)->where('account_key', 'vat-test')->first());
        }
        $migration->up();
    }
}
