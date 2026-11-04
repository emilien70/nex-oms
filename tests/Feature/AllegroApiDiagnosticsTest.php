<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Enums\AllegroFulfillmentStatus;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroClient;
use Modules\Integrations\Allegro\Services\AllegroOAuthService;
use Modules\Shipments\Models\IntegrationApiLog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllegroApiDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_unauthorized_refresh_and_success_have_one_operation_id_and_separate_traces(): void
    {
        $account = $this->account();
        $this->fakeRefresh();
        Http::fakeSequence('https://api.allegro.pl/order/events*')
            ->push(['access_token' => 'PRIVATE-FIRST-BODY', 'buyer' => ['email' => 'private@example.test']], 401, [
                'Trace-Id' => 'api-first-trace', 'Set-Cookie' => 'PRIVATE-COOKIE',
            ])
            ->push(['events' => []], 200, ['trace-id' => 'api-second-trace']);

        $this->assertSame(['events' => []], app(AllegroClient::class)->orderEvents($account, '123'));

        $logs = IntegrationApiLog::query()->orderBy('id')->get();
        $this->assertSame(['order_events', 'refresh_token', 'order_events'], $logs->pluck('operation')->all());
        $this->assertSame([401, 200, 200], $logs->pluck('response_status')->all());
        $this->assertSame([1, 1, 2], $logs->pluck('attempt_number')->all());
        $this->assertSame(['api-first-trace', 'oauth-trace', 'api-second-trace'], $logs->pluck('response_trace_id')->all());
        $this->assertCount(1, $logs->pluck('request_id')->unique());
        $this->assertTrue(Str::isUuid($logs->first()->request_id));
        $this->assertSame([false, true, true], $logs->pluck('successful')->all());
        $this->assertNull($logs->first()->response_payload);
        $this->assertNull($logs->last()->error_message);
        $this->assertSame('new-access', $account->fresh()->access_token);
        $this->assertSafeLogs();
        $this->assertRequestHeaders();
        Http::assertSentCount(3);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_second_unauthorized_response_is_not_retried_again(): void
    {
        $account = $this->account();
        $this->fakeRefresh();
        Http::fakeSequence('https://api.allegro.pl/order/checkout-forms/order-id')
            ->push([], 401, ['Trace-Id' => 'first-401'])
            ->push([], 401, ['Trace-Id' => 'second-401']);

        $this->assertApiError(401, fn () => app(AllegroClient::class)->order($account, 'order-id'));

        $logs = IntegrationApiLog::query()->where('operation', 'get_order')->orderBy('id')->get();
        $this->assertSame([1, 2], $logs->pluck('attempt_number')->all());
        $this->assertSame(['first-401', 'second-401'], $logs->pluck('response_trace_id')->all());
        $this->assertSame([false, false], $logs->pluck('successful')->all());
        $this->assertCount(1, $logs->pluck('request_id')->unique());
        Http::assertSentCount(3);
    }

    public function test_failed_refresh_preserves_first_401_and_does_not_send_a_second_api_request(): void
    {
        $account = $this->account();
        Http::fake([
            'https://api.allegro.pl/order/events*' => Http::response([], 401, ['Trace-Id' => 'first-401']),
            'https://allegro.pl/auth/oauth/token' => Http::response(['error' => 'invalid_grant'], 400, ['Trace-Id' => 'failed-refresh']),
        ]);

        $this->assertApiError(400, fn () => app(AllegroClient::class)->orderEvents($account, null));

        $logs = IntegrationApiLog::query()->orderBy('id')->get();
        $this->assertSame([401, 400], $logs->pluck('response_status')->all());
        $this->assertSame(['first-401', 'failed-refresh'], $logs->pluck('response_trace_id')->all());
        $this->assertCount(1, $logs->pluck('request_id')->unique());
        $this->assertSame('old-access', $account->fresh()->access_token);
        Http::assertSentCount(2);
    }

    public function test_connection_failure_during_second_attempt_keeps_its_number_and_null_trace(): void
    {
        $account = $this->account();
        $this->fakeRefresh();
        $attempts = 0;
        Http::fake(['https://api.allegro.pl/order/events*' => function () use (&$attempts) {
            if (++$attempts === 1) {
                return Http::response([], 401, ['Trace-Id' => 'first-401']);
            }
            throw new ConnectionException('Network timeout');
        }]);

        $this->assertApiError(null, fn () => app(AllegroClient::class)->orderEvents($account, null));

        $last = IntegrationApiLog::query()->latest('id')->firstOrFail();
        $this->assertSame('order_events', $last->operation);
        $this->assertSame(2, $last->attempt_number);
        $this->assertNull($last->response_status);
        $this->assertNull($last->response_trace_id);
        $this->assertSame(2, $attempts);
        $this->assertSame(1, IntegrationApiLog::query()->pluck('request_id')->unique()->count());
    }

    #[DataProvider('serverErrors')]
    public function test_server_errors_are_logged_without_adding_automatic_retries(int $status, string $operation): void
    {
        $account = $this->account();
        $original = (array) DB::table('allegro_accounts')->find($account->id);
        Http::fake(['https://api.allegro.pl/*' => Http::response(['errors' => [['code' => 'SERVICE_ERROR']]], $status, [
            'Trace-Id' => 'service-trace', 'Authorization' => 'PRIVATE-RESPONSE-HEADER',
        ])]);

        $this->assertApiError($status, fn () => $operation === 'get_order'
            ? app(AllegroClient::class)->order($account, 'order-id')
            : app(AllegroClient::class)->customerReturn($account, 'return-id'));

        $log = IntegrationApiLog::query()->sole();
        $this->assertSame($operation, $log->operation);
        $this->assertSame('service-trace', $log->response_trace_id);
        $this->assertSame(1, $log->attempt_number);
        $this->assertFalse($log->successful);
        $this->assertSame($original, (array) DB::table('allegro_accounts')->find($account->id));
        $this->assertSafeLogs();
        Http::assertSentCount(1);
    }

    public static function serverErrors(): array
    {
        return ['order 500' => [500, 'get_order'], 'return 503' => [503, 'get_customer_return']];
    }

    public function test_proactive_refresh_is_correlated_without_changing_expiration_policy(): void
    {
        $account = $this->account();
        $account->update(['access_token_expires_at' => now()->addMinutes(4)]);
        $this->fakeRefresh();
        Http::fake(['https://api.allegro.pl/order/events*' => Http::response(['events' => []], 200, ['Trace-Id' => 'api-trace'])]);

        app(AllegroClient::class)->orderEvents($account, null);

        $logs = IntegrationApiLog::query()->orderBy('id')->get();
        $this->assertSame(['refresh_token', 'order_events'], $logs->pluck('operation')->all());
        $this->assertSame([1, 1], $logs->pluck('attempt_number')->all());
        $this->assertCount(1, $logs->pluck('request_id')->unique());
        $this->assertRequestHeaders();
        Http::assertSentCount(2);
    }

    public function test_single_argument_oauth_caller_still_gets_an_independent_operation_id(): void
    {
        $account = $this->account();
        $this->fakeRefresh();

        app(AllegroOAuthService::class)->refresh($account);

        $log = IntegrationApiLog::query()->sole();
        $this->assertTrue(Str::isUuid($log->request_id));
        $this->assertSame('oauth-trace', $log->response_trace_id);
        $this->assertSame(1, $log->attempt_number);
        $this->assertSafeLogs();
        Http::assertSentCount(1);
    }

    public function test_put_retry_keeps_method_body_and_user_agent(): void
    {
        $account = $this->account();
        $this->fakeRefresh();
        Http::fakeSequence('https://api.allegro.pl/order/checkout-forms/order-id/fulfillment*')
            ->push([], 401, ['Trace-Id' => 'put-1'])->push([], 204, ['Trace-Id' => 'put-2']);

        app(AllegroClient::class)->updateFulfillment($account, 'order-id', AllegroFulfillmentStatus::Sent, 'revision-1');

        $requests = Http::recorded(fn (Request $request) => $request->method() === 'PUT');
        $this->assertCount(2, $requests);
        foreach ($requests as [$request]) {
            $this->assertSame(['status' => 'SENT'], $request->data());
            $this->assertStringEndsWith('fulfillment?checkoutForm.revision=revision-1', $request->url());
        }
        $logs = IntegrationApiLog::query()->where('operation', 'set_fulfillment')->orderBy('id')->get();
        $this->assertSame([401, 204], $logs->pluck('response_status')->all());
        $this->assertSame([1, 2], $logs->pluck('attempt_number')->all());
        $this->assertRequestHeaders();
        Http::assertSentCount(3);
    }

    public function test_pdf_retry_keeps_the_attachment_and_does_not_log_its_contents(): void
    {
        $account = $this->account();
        $this->fakeRefresh();
        Http::fakeSequence('https://api.allegro.pl/order/order-id/billing-documents/files')
            ->push([], 401)->push(['id' => 'file-id'], 200);

        $result = app(AllegroClient::class)->uploadOrderInvoice($account, 'order-id', 'N 3/2026', 'Faktura N_3-2026.pdf', 'PRIVATE-PDF');

        $this->assertSame(['id' => 'file-id'], $result);
        $requests = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/billing-documents/files'));
        $this->assertCount(2, $requests);
        foreach ($requests as [$request]) {
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasFile('file', 'PRIVATE-PDF', 'Faktura N_3-2026.pdf'));
        }
        $this->assertSafeLogs();
        Http::assertSentCount(3);
    }

    public function test_migration_preserves_old_logs_and_is_compatible_with_other_integrations(): void
    {
        $migration = require database_path('migrations/2026_11_04_020000_add_allegro_diagnostics_to_integration_api_logs.php');
        $migration->down();
        $id = DB::table('integration_api_logs')->insertGetId([
            'integration' => 'inpost', 'operation' => 'get_shipment', 'request_id' => (string) Str::uuid(),
            'method' => 'GET', 'url' => 'https://example.test/shipment', 'response_status' => 200,
            'successful' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = (array) DB::table('integration_api_logs')->find($id);

        $migration->up();

        $this->assertTrue(Schema::hasColumns('integration_api_logs', ['response_trace_id', 'attempt_number']));
        $after = (array) DB::table('integration_api_logs')->find($id);
        $this->assertNull($after['response_trace_id']);
        $this->assertNull($after['attempt_number']);
        unset($after['response_trace_id'], $after['attempt_number']);
        $this->assertSame($before, $after);
        $legacy = IntegrationApiLog::query()->findOrFail($id);
        $this->assertNull($legacy->attempt_number);
        $this->assertNull($legacy->response_trace_id);
    }

    private function account(): AllegroAccount
    {
        return AllegroAccount::query()->create([
            'account_key' => AllegroAccount::DEFAULT_ACCOUNT_KEY, 'name' => 'Test Allegro', 'environment' => 'production',
            'client_id' => 'client-id', 'client_secret' => 'PRIVATE-CLIENT-SECRET',
            'access_token' => 'old-access', 'refresh_token' => 'old-refresh', 'access_token_expires_at' => now()->addHours(10),
            'granted_scopes' => AllegroAccount::REQUIRED_SCOPES, 'allegro_user_id' => '12345678',
            'is_active' => true, 'last_test_status' => AllegroAccount::STATUS_SUCCESS,
        ]);
    }

    private function fakeRefresh(): void
    {
        Http::fake(['https://allegro.pl/auth/oauth/token' => Http::response([
            'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 43200,
        ], 200, ['Trace-Id' => 'oauth-trace', 'Set-Cookie' => 'PRIVATE-COOKIE'])]);
    }

    private function assertApiError(?int $status, callable $request): void
    {
        try {
            $request();
            $this->fail('The API call should fail.');
        } catch (AllegroApiException $exception) {
            $this->assertSame($status, $exception->statusCode);
        }
    }

    private function assertSafeLogs(): void
    {
        $logs = DB::table('integration_api_logs')->get()->toJson();
        foreach (['old-access', 'old-refresh', 'new-access', 'new-refresh', 'PRIVATE-CLIENT-SECRET',
            'PRIVATE-FIRST-BODY', 'private@example.test', 'PRIVATE-COOKIE', 'PRIVATE-RESPONSE-HEADER', 'PRIVATE-PDF'] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }
    }

    private function assertRequestHeaders(): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->hasHeader('User-Agent', config('services.allegro.user_agent')));
        }
    }
}
