<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shipments\Services\IntegrationApiLogPruner;
use Tests\TestCase;

class IntegrationApiLogPrunerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_api_logs_according_to_their_type_and_result(): void
    {
        config()->set('services.inpost.api_log_retention', [
            'successful_status_days' => 45,
            'successful_days' => 180,
            'failed_days' => 365,
        ]);

        $now = Carbon::parse('2026-07-20 12:00:00');
        $expiredStatus = $this->log('get_shipment', true, $now->copy()->subDays(46));
        $recentStatus = $this->log('get_shipment', true, $now->copy()->subDays(44));
        $expiredSuccess = $this->log('create_shipment', true, $now->copy()->subDays(181));
        $expiredFailure = $this->log('get_shipment', false, $now->copy()->subDays(366));

        $deleted = app(IntegrationApiLogPruner::class)->prune($now);

        $this->assertSame([
            'successful_status' => 1,
            'successful_other' => 1,
            'failed' => 1,
        ], $deleted);
        $this->assertDatabaseMissing('integration_api_logs', ['id' => $expiredStatus]);
        $this->assertDatabaseHas('integration_api_logs', ['id' => $recentStatus]);
        $this->assertDatabaseMissing('integration_api_logs', ['id' => $expiredSuccess]);
        $this->assertDatabaseMissing('integration_api_logs', ['id' => $expiredFailure]);
    }

    private function log(string $operation, bool $successful, Carbon $createdAt): int
    {
        return DB::table('integration_api_logs')->insertGetId([
            'integration' => 'inpost_courier',
            'operation' => $operation,
            'request_id' => (string) Str::uuid(),
            'method' => 'GET',
            'url' => 'https://example.test/v1/shipments/1',
            'successful' => $successful,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
