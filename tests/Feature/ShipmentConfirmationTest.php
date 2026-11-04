<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Integrations\AllegroShipping\Jobs\ResolveAllegroShipmentCommandJob;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingOperations;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingPayloadFactory;
use Modules\Integrations\InPost\Jobs\RefreshInPostShipmentJob;
use Modules\Integrations\InPost\Jobs\ResolveInPostShipmentJob;
use Modules\Integrations\InPost\Services\InPostShipmentOperations;
use Modules\Shipments\Events\ShipmentCreated;
use Modules\Shipments\Events\ShipmentCreationFailed;
use Modules\Shipments\Events\ShipmentStatusChanged;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentCreationAttempt;
use Modules\Shipments\Support\ShipmentQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShipmentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Queue::fake();
        Event::fake([ShipmentCreated::class, ShipmentCreationFailed::class, ShipmentStatusChanged::class]);
        Http::preventStrayRequests();
    }

    #[DataProvider('inpostProviders')]
    public function test_inpost_waits_one_second_and_keeps_reading_until_number_arrives(string $provider): void
    {
        $shipment = $this->shipment($provider, null);
        Http::fakeSequence()->push(['id' => 123, 'status' => 'created'])
            ->push(['id' => 123, 'status' => 'created'])
            ->push(['id' => 123, 'status' => 'confirmed', 'tracking_number' => '620000000000000001']);

        app(InPostShipmentOperations::class)->create($shipment, ['test' => true]);
        Queue::assertPushedOn(ShipmentQueue::ACTIONS, ResolveInPostShipmentJob::class,
            fn ($job): bool => $job->delay->equalTo(now()->addSecond()));
        Queue::assertNotPushed(RefreshInPostShipmentJob::class);

        $job = (new ResolveInPostShipmentJob($shipment))->withFakeQueueInteractions();
        $job->handle(app(InPostShipmentOperations::class));
        $job->assertReleased(1);
        $job->withFakeQueueInteractions()->handle(app(InPostShipmentOperations::class));
        $job->assertNotReleased();
        $job->handle(app(InPostShipmentOperations::class));

        $this->assertSame('620000000000000001', $shipment->fresh()->tracking_number);
        $this->assertSame(ShipmentCreationAttempt::STATUS_SUCCEEDED, $shipment->creationAttempt->fresh()->status);
        Event::assertDispatchedTimes(ShipmentCreated::class, 1);
        $this->assertSame(1, $shipment->order->events()->where('event_type', 'shipment_created')->count());
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST');
    }

    public static function inpostProviders(): array
    {
        return [[CourierAccount::PROVIDER_INPOST_LOCKERS], [CourierAccount::PROVIDER_INPOST_COURIER]];
    }

    public function test_allegro_respects_retry_after_then_keeps_reading_details_without_recreating_command(): void
    {
        $shipment = $this->shipment(CourierAccount::PROVIDER_ALLEGRO_SHIPPING, null);
        $this->mock(AllegroShippingPayloadFactory::class)->shouldReceive('make')->once()->andReturn(['commandId' => $shipment->request_uuid]);
        Http::fakeSequence()->push(['commandId' => $shipment->request_uuid], 202, ['Retry-After' => '2'])
            ->push(['status' => 'IN_PROGRESS'], 200, ['Retry-After' => '7'])
            ->push(['status' => 'SUCCESS', 'shipmentId' => 'shipment-id'])
            ->push(['id' => 'shipment-id', 'packages' => []], 200, ['Retry-After' => '3'])
            ->push(['id' => 'shipment-id', 'carrier' => 'INPOST', 'packages' => [['waybill' => '620000000000000001']]]);

        app(AllegroShippingOperations::class)->create($shipment);
        Http::assertSentCount(1);
        Queue::assertPushed(ResolveAllegroShipmentCommandJob::class,
            fn ($job): bool => $job->delay->equalTo(now()->addSeconds(2)));

        $job = (new ResolveAllegroShipmentCommandJob($shipment))->withFakeQueueInteractions();
        $job->handle(app(AllegroShippingOperations::class));
        $job->assertReleased(7);
        $job->withFakeQueueInteractions()->handle(app(AllegroShippingOperations::class));
        $job->assertReleased(3);
        $this->assertSame('shipment-id', $shipment->fresh()->external_id);
        $this->assertNull($shipment->fresh()->tracking_number);
        $this->assertTrue(app(AllegroShippingOperations::class)->resolveCreation($shipment->fresh()));
        $job->withFakeQueueInteractions()->handle(app(AllegroShippingOperations::class));
        $job->assertNotReleased();

        Event::assertDispatchedTimes(ShipmentCreated::class, 1);
        $this->assertSame(1, $shipment->order->events()->where('event_type', 'shipment_created')->count());
        Http::assertSentCount(5);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
        $this->assertSame(2, Http::recorded(fn ($request) => str_contains($request->url(), '/create-commands/'))->count());
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', config('services.allegro.user_agent')));
    }

    public function test_allegro_without_retry_after_checks_immediately_and_returns_false_without_a_waybill(): void
    {
        $shipment = $this->shipment(CourierAccount::PROVIDER_ALLEGRO_SHIPPING, null);
        Http::fakeSequence()->push(['status' => 'SUCCESS', 'shipmentId' => 'shipment-id'])
            ->push(['id' => 'shipment-id', 'packages' => []]);
        $this->assertFalse(app(AllegroShippingOperations::class)->resolveCreation($shipment));
        $this->assertSame('shipment-id', $shipment->fresh()->external_id);
        Event::assertNotDispatched(ShipmentCreated::class);
    }

    public function test_error_reading_an_accepted_allegro_command_never_repeats_its_post(): void
    {
        $shipment = $this->shipment(CourierAccount::PROVIDER_ALLEGRO_SHIPPING, null);
        $this->mock(AllegroShippingPayloadFactory::class)->shouldReceive('make')->once()->andReturn([]);
        Http::fakeSequence()->push(['commandId' => $shipment->request_uuid], 202)
            ->push([], 429, ['Retry-After' => '19'])
            ->push(['status' => 'SUCCESS', 'shipmentId' => 'shipment-id'])
            ->push(['id' => 'shipment-id', 'carrier' => 'INPOST', 'packages' => [['waybill' => '620000000000000001']]]);
        app(AllegroShippingOperations::class)->create($shipment);
        Queue::assertPushed(ResolveAllegroShipmentCommandJob::class,
            fn ($job): bool => $job->delay->equalTo(now()->addSeconds(19)));
        $job = (new ResolveAllegroShipmentCommandJob($shipment))->withFakeQueueInteractions();
        $job->handle(app(AllegroShippingOperations::class));
        $job->assertNotReleased();
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
        $this->assertSame('620000000000000001', $shipment->fresh()->tracking_number);
    }

    public function test_allegro_rejection_ends_confirmation_without_another_create_request(): void
    {
        $shipment = $this->shipment(CourierAccount::PROVIDER_ALLEGRO_SHIPPING, null);
        Http::fake(fn () => Http::response(['status' => 'ERROR', 'errors' => [['message' => 'Rejected']]]));
        $job = (new ResolveAllegroShipmentCommandJob($shipment))->withFakeQueueInteractions();
        $job->handle(app(AllegroShippingOperations::class));
        $job->assertNotReleased();
        $this->assertSame(Shipment::STATUS_CREATION_UNKNOWN, $shipment->fresh()->status);
        Http::assertSentCount(1);
        Event::assertDispatchedTimes(ShipmentCreationFailed::class, 1);
    }

    #[DataProvider('transientErrors')]
    public function test_confirmation_get_errors_are_retried_without_post(string $provider, ?int $status): void
    {
        $shipment = $this->shipment($provider);
        Http::fake($status === null ? Http::failedConnection() : fn () => Http::response([], $status, ['Retry-After' => '41']));
        $job = $this->job($shipment)->withFakeQueueInteractions();
        $job->handle($this->operations($provider));
        $job->assertReleased($status === null ? 10 : 41);
        $this->assertSame(ShipmentCreationAttempt::STATUS_PROCESSING, $shipment->creationAttempt->fresh()->status);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        Event::assertNotDispatched(ShipmentCreationFailed::class);
    }

    public static function transientErrors(): array
    {
        $cases = [];
        foreach ([CourierAccount::PROVIDER_INPOST_LOCKERS, CourierAccount::PROVIDER_ALLEGRO_SHIPPING] as $provider) {
            foreach ([404, 408, 429, 500, null] as $status) {
                $cases[] = [$provider, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('providers')]
    public function test_deadline_preserves_existing_shipment_and_records_uncertainty_only_once(string $provider): void
    {
        $shipment = $this->shipment($provider);
        $job = $this->job($shipment)->withFakeQueueInteractions();
        $this->travel(900)->seconds();
        $job->handle($this->operations($provider));
        $job->failed(null);
        $job->assertNotReleased();
        Http::assertNothingSent();
        $this->assertSame('external-id', $shipment->fresh()->external_id);
        $this->assertSame(Shipment::STATUS_CREATION_UNKNOWN, $shipment->fresh()->status);
        $this->assertFalse($shipment->fresh()->canRetryCreation());
        Event::assertDispatchedTimes(ShipmentCreationFailed::class, 1);
    }

    #[DataProvider('providers')]
    public function test_a_cooldown_beyond_deadline_does_not_make_an_early_get(string $provider): void
    {
        $shipment = $this->shipment($provider);
        Http::fake(fn () => Http::response([], 429, ['Retry-After' => '1200']));
        $job = $this->job($shipment)->withFakeQueueInteractions();
        $job->handle($this->operations($provider));
        $job->assertReleased(900);
        $this->travel(900)->seconds();
        $job->handle($this->operations($provider));
        Http::assertSentCount(1);
        $this->assertSame(Shipment::STATUS_CREATION_UNKNOWN, $shipment->fresh()->status);
    }

    #[DataProvider('providers')]
    public function test_cancelled_or_removed_shipments_do_not_trigger_confirmation(string $provider): void
    {
        $shipment = $this->shipment($provider);
        $job = $this->job($shipment)->withFakeQueueInteractions();
        $shipment->update(['status' => Shipment::STATUS_CANCELLED]);
        $job->handle($this->operations($provider));
        $job->assertNotReleased();
        $shipment->delete();
        $job->handle($this->operations($provider));
        $job->failed(null);
        Http::assertNothingSent();
        Event::assertNotDispatched(ShipmentCreated::class);
        Event::assertNotDispatched(ShipmentCreationFailed::class);
    }

    public function test_legacy_allegro_job_uses_stable_shipment_creation_time_as_deadline(): void
    {
        $shipment = $this->shipment(CourierAccount::PROVIDER_ALLEGRO_SHIPPING);
        $job = new ResolveAllegroShipmentCommandJob($shipment);
        $job->confirmationStartedAt = null;
        $this->travel(600)->seconds();
        $this->assertSame($shipment->created_at->getTimestamp() + 900, $job->retryUntil()->getTimestamp());
        $this->travel(100)->seconds();
        $this->assertSame($shipment->created_at->getTimestamp() + 900, $job->retryUntil()->getTimestamp());
    }

    #[DataProvider('providers')]
    public function test_confirmation_uses_the_same_per_shipment_mutex_and_rate_limiter_as_normal_refresh(string $provider): void
    {
        $shipment = $this->shipment($provider);
        $job = $this->job($shipment);
        [$lock, $limit] = $job->middleware();
        $this->assertTrue($lock->shareKey);
        $this->assertSame(($provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING ? 'allegro' : 'inpost').'-shipment-'.$shipment->id, $lock->key);
        $this->assertSame(ShipmentQueue::ACTIONS, $job->queue);
        $this->assertInstanceOf(RateLimited::class, $limit);
    }

    #[DataProvider('providers')]
    public function test_confirmation_waits_while_another_job_holds_the_shared_shipment_lock(string $provider): void
    {
        $shipment = $this->shipment($provider);
        $job = $this->job($shipment)->withFakeQueueInteractions();
        $middleware = $job->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($job), 120);
        $this->assertTrue($lock->get());
        try {
            $middleware->handle($job, fn () => $this->fail('Confirmation must not overlap another shipment job.'));
            $job->assertReleased(5);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    #[DataProvider('providers')]
    public function test_new_confirmation_window_starts_after_waiting_in_creation_queue(string $provider): void
    {
        $shipment = $this->shipment($provider);
        $this->travel(1800)->seconds();
        $job = $this->job($shipment);
        $this->assertSame(now()->addSeconds(900)->getTimestamp(), $job->retryUntil()->getTimestamp());
    }

    public static function providers(): array
    {
        return [[CourierAccount::PROVIDER_INPOST_LOCKERS], [CourierAccount::PROVIDER_ALLEGRO_SHIPPING]];
    }

    private function job(Shipment $shipment): ResolveInPostShipmentJob|ResolveAllegroShipmentCommandJob
    {
        return $shipment->provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING
            ? new ResolveAllegroShipmentCommandJob($shipment) : new ResolveInPostShipmentJob($shipment);
    }

    private function operations(string $provider): InPostShipmentOperations|AllegroShippingOperations
    {
        return app($provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING ? AllegroShippingOperations::class : InPostShipmentOperations::class);
    }

    private function shipment(string $provider, ?string $externalId = 'external-id'): Shipment
    {
        $account = CourierAccount::query()->create([
            'provider' => $provider, 'name' => 'Test', 'environment' => 'sandbox',
            'api_token' => 'test-token', 'organization_id' => '12345', 'is_active' => true,
        ]);
        $order = Order::query()->create([
            'source' => 'manual', 'status' => Order::STATUS_NEW, 'customer_email' => 'test@example.test',
            'shipping_name' => 'Test', 'currency' => 'PLN', 'total_gross' => 100, 'paid_amount' => 0,
        ]);
        $attempt = $order->shipmentCreationAttempts()->create([
            'courier_account_id' => $account->id, 'provider' => $provider, 'request_uuid' => (string) Str::uuid(),
            'status' => ShipmentCreationAttempt::STATUS_PROCESSING, 'started_at' => now(),
        ]);

        return $order->shipments()->create([
            'courier_account_id' => $account->id, 'creation_attempt_id' => $attempt->id, 'provider' => $provider,
            'external_id' => $externalId, 'request_uuid' => $attempt->request_uuid,
            'service' => $provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING ? Shipment::SERVICE_ALLEGRO_DELIVERY : Shipment::SERVICE_INPOST_LOCKER_STANDARD,
            'status' => Shipment::STATUS_CREATED, 'currency' => 'PLN', 'sending_method' => 'dispatch_order',
        ]);
    }
}
