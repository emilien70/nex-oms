<?php

namespace Tests\Feature;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CurrencySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fetches_tables_a_and_b_and_upserts_without_deleting_local_rows(): void
    {
        Currency::query()->create(['code' => 'CHF', 'name' => 'lokalny frank', 'nbp_table' => null]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/A/')) {
                return Http::response([['rates' => [
                    ['currency' => 'euro', 'code' => 'EUR'],
                    ['currency' => 'dolar amerykański', 'code' => 'USD'],
                ]]]);
            }

            return Http::response([['rates' => [
                ['currency' => 'afgani', 'code' => 'AFN'],
            ]]]);
        });

        $this->artisan('currencies:sync-nbp')
            ->expectsOutput('Zsynchronizowano katalog walut NBP: 5 walut.')
            ->assertSuccessful();

        $this->assertDatabaseHas('currencies', ['code' => 'PLN', 'nbp_table' => null]);
        $this->assertDatabaseHas('currencies', ['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        $this->assertDatabaseHas('currencies', ['code' => 'AFN', 'name' => 'afgani', 'nbp_table' => 'B']);
        $this->assertDatabaseHas('currencies', ['code' => 'CHF', 'name' => 'lokalny frank']);
        Http::assertSentCount(2);
    }

    public function test_invalid_second_response_causes_no_partial_persistence(): void
    {
        Currency::query()->create(['code' => 'USD', 'name' => 'stara nazwa', 'nbp_table' => 'A']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbp.pl/api/exchangerates/tables/A/*' => Http::response([['rates' => [
                ['currency' => 'euro', 'code' => 'EUR'],
            ]]]),
            'https://api.nbp.pl/api/exchangerates/tables/B/*' => Http::response(['invalid' => true]),
        ]);

        $this->artisan('currencies:sync-nbp')->assertFailed();

        $this->assertDatabaseMissing('currencies', ['code' => 'EUR']);
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'name' => 'stara nazwa']);
        $this->assertDatabaseHas('currencies', ['code' => 'PLN', 'name' => 'PLN']);
    }

    public function test_duplicate_code_between_nbp_tables_is_rejected_atomically(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbp.pl/api/exchangerates/tables/A/*' => Http::response([['rates' => [
                ['currency' => 'euro', 'code' => 'EUR'],
            ]]]),
            'https://api.nbp.pl/api/exchangerates/tables/B/*' => Http::response([['rates' => [
                ['currency' => 'inne euro', 'code' => 'EUR'],
            ]]]),
        ]);

        $this->artisan('currencies:sync-nbp')->assertFailed();

        $this->assertDatabaseMissing('currencies', ['code' => 'EUR']);
    }

    public function test_repeated_sync_updates_name_and_table_without_duplicates_or_deletions(): void
    {
        Currency::query()->insert([
            ['code' => 'EUR', 'name' => 'stare euro', 'nbp_table' => 'A'],
            ['code' => 'CHF', 'name' => 'lokalny frank', 'nbp_table' => null],
        ]);
        Currency::query()->whereKey('PLN')->update(['name' => 'PLN', 'nbp_table' => null]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbp.pl/api/exchangerates/tables/A/*' => Http::response([['rates' => [
                ['currency' => 'dolar amerykański', 'code' => 'USD', 'mid' => 3.99],
            ]]]),
            'https://api.nbp.pl/api/exchangerates/tables/B/*' => Http::response([['rates' => [
                ['currency' => 'euro po zmianie', 'code' => 'EUR', 'mid' => 4.25],
            ]]]),
        ]);

        $this->artisan('currencies:sync-nbp')->assertSuccessful();
        $this->artisan('currencies:sync-nbp')->assertSuccessful();

        $this->assertDatabaseCount('currencies', 4);
        $this->assertDatabaseHas('currencies', [
            'code' => 'EUR',
            'name' => 'euro po zmianie',
            'nbp_table' => 'B',
        ]);
        $this->assertDatabaseHas('currencies', ['code' => 'CHF', 'name' => 'lokalny frank']);
        $this->assertDatabaseHas('currencies', ['code' => 'PLN', 'name' => 'PLN', 'nbp_table' => null]);
        $this->assertFalse(Schema::hasColumn('currencies', 'mid'));
    }

    public function test_invalid_record_in_first_table_returns_failure_without_database_changes(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbp.pl/api/exchangerates/tables/A/*' => Http::response([['rates' => [
                ['currency' => 'wadliwa', 'code' => 'E1R'],
            ]]]),
            'https://api.nbp.pl/api/exchangerates/tables/B/*' => Http::response([['rates' => []]]),
        ]);

        $this->artisan('currencies:sync-nbp')->assertFailed();

        $this->assertDatabaseCount('currencies', 1);
        $this->assertDatabaseHas('currencies', ['code' => 'PLN']);
    }

    public function test_http_failure_returns_failure_without_partial_persistence(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbp.pl/api/exchangerates/tables/A/*' => Http::response([], 503),
            'https://api.nbp.pl/api/exchangerates/tables/B/*' => Http::response([['rates' => []]]),
        ]);

        $this->artisan('currencies:sync-nbp')->assertFailed();

        $this->assertDatabaseCount('currencies', 1);
    }
}
