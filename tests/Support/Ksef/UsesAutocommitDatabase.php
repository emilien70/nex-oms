<?php

namespace Tests\Support\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;

trait UsesAutocommitDatabase
{
    public function setUpUsesAutocommitDatabase(): void
    {
        if (! app()->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:'
            || ! in_array(config('database.connections.sqlite.url'), [null, ''], true)) {
            throw new LogicException('Autocommit tests require isolated SQLite :memory:.');
        }

        // A new migrated database per test, without RefreshDatabase's outer transaction.
        DB::purge('sqlite');
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new LogicException('Isolated test migrations failed.');
        }
    }

    public function tearDownUsesAutocommitDatabase(): void
    {
        DB::disconnect('sqlite');
        // Do not let subsequent RefreshDatabase tests reuse this test's database state.
        RefreshDatabaseState::$migrated = false;
    }
}
