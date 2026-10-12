<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationActivityFeed;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationEngine;
use Modules\Automation\Services\AutomationNexPrinterActionService;
use Modules\Integrations\NexPrinter\Models\NexPrinter;
use Modules\Integrations\NexPrinter\Models\NexPrinterAgent;
use Modules\Integrations\NexPrinter\Models\NexPrintJob;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfService;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class AutomationNexPrinterActionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_editor_lists_print_action_and_only_available_pdf_printers_without_a_default(): void
    {
        [$agent, $printer] = $this->createPdfPrinter();
        NexPrinter::query()->create([
            'agent_id' => $agent->getKey(),
            'system_name' => 'Disabled printer',
            'display_name' => 'Disabled printer',
            'is_default' => false,
            'is_enabled' => false,
            'is_available' => true,
            'capabilities' => ['formats' => ['pdf']],
            'last_seen_at' => now(),
        ]);
        NexPrinter::query()->create([
            'agent_id' => $agent->getKey(),
            'system_name' => 'ZPL printer',
            'display_name' => 'ZPL printer',
            'is_default' => false,
            'is_enabled' => true,
            'is_available' => true,
            'capabilities' => ['formats' => ['zpl']],
            'last_seen_at' => now(),
        ]);

        $catalog = app(AutomationCatalog::class);
        $this->assertSame('Drukuj', $catalog->actionLabel(AutomationCatalog::ACTION_PRINT));
        $this->assertSame(
            [$printer->getKey() => 'Drukarka faktur (Komputer księgowości)'],
            $catalog->nexPrinters(),
        );

        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertSee(AutomationCatalog::ACTION_PRINT)
            ->assertSee('const nexPrinters', false)
            ->assertSee('DOKUMENT')
            ->assertSee('DRUKARKA')
            ->assertSee('Faktura VAT')
            ->assertSee('Najnowsza etykieta')
            ->assertSee('Wszystkie etykiety')
            ->assertSee('Drukarka faktur')
            ->assertDontSee('Disabled printer')
            ->assertDontSee('ZPL printer')
            ->assertSee('const printDocumentTypes', false)
            ->assertSee("printer_id: ''", false);
    }

    public function test_rule_accepts_both_shipment_label_document_types(): void
    {
        [, $printer] = $this->createPdfPrinter();

        foreach ([
            AutomationCatalog::PRINT_DOCUMENT_LATEST_SHIPMENT_LABEL,
            AutomationCatalog::PRINT_DOCUMENT_ALL_SHIPMENT_LABELS,
        ] as $index => $documentType) {
            $payload = $this->rulePayload($printer->getKey(), $documentType);
            $payload['name'] = 'Wydruk etykiet '.($index + 1);

            $this->postJson(route('orders.automatic-actions.store'), $payload)
                ->assertCreated();
        }

        $this->assertDatabaseCount('automation_rules', 2);
        $this->assertSame(
            [
                AutomationCatalog::PRINT_DOCUMENT_LATEST_SHIPMENT_LABEL,
                AutomationCatalog::PRINT_DOCUMENT_ALL_SHIPMENT_LABELS,
            ],
            AutomationRule::query()
                ->with('actions')
                ->orderBy('id')
                ->get()
                ->map(fn (AutomationRule $rule): string => (string) $rule->actions->firstOrFail()->configuration['document_type'])
                ->all(),
        );
    }

    public function test_rule_requires_an_explicit_available_pdf_printer(): void
    {
        [, $printer] = $this->createPdfPrinter();

        $this->postJson(route('orders.automatic-actions.store'), $this->rulePayload(null))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actions.0.configuration.printer_id');

        $this->postJson(route('orders.automatic-actions.store'), $this->rulePayload(
            $printer->getKey(),
            'unsupported_document',
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actions.0.configuration.document_type');

        $printer->update(['is_enabled' => false]);
        $this->postJson(route('orders.automatic-actions.store'), $this->rulePayload($printer->getKey()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actions.0.configuration.printer_id');

        $this->assertDatabaseCount('automation_rules', 0);
    }

    public function test_automatic_print_queues_the_invoice_once_even_when_the_computer_is_offline(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        [, $printer] = $this->createPdfPrinter(lastSeenAt: now()->subHour());
        $rule = $this->createPrintRule($printer);
        $this->mock(InvoicePdfService::class)
            ->shouldReceive('contents')
            ->once()
            ->withArgs(fn ($document): bool => $document->is($invoice))
            ->andReturn("%PDF-1.4\nAUTOMATIC NEX PRINTER");

        app(AutomationEngine::class)->evaluate('invoice.issued', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'invoice.issued',
            'order_id' => $order->getKey(),
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->number,
            'source' => 'manual',
        ]);

        $run = AutomationRun::query()->with('steps')->firstOrFail();
        $step = $run->steps->firstOrFail();
        $job = NexPrintJob::query()->firstOrFail();

        $this->assertSame($rule->getKey(), $run->automation_rule_id);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('completed', $step->status);
        $this->assertSame($job->getKey(), $step->output['print_job_id']);
        $this->assertSame($invoice->getKey(), $job->invoice_id);
        $this->assertSame($printer->getKey(), $job->printer_id);
        $this->assertSame(NexPrintJob::ORIGIN_AUTOMATION, $job->origin);
        $this->assertSame(1, $job->copies);
        $this->assertSame(
            '✓ Dodano do kolejki NEX Printer',
            app(AutomationActivityFeed::class)->recent()[0]['steps'][0]['status_label'],
        );

        app(AutomationNexPrinterActionService::class)->execute($run, $step, $step->configuration);

        $this->assertDatabaseCount('nex_print_jobs', 1);
        $this->assertDatabaseCount('nex_print_job_events', 1);
        Storage::disk('local')->assertExists($job->payload_path);
    }

    public function test_automatic_print_queues_only_the_latest_available_shipment_label(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $account = $this->createDpdAccount();
        $older = $this->createDpdShipment($order, $account, '00000000000001', now()->subMinutes(3));
        $newer = $this->createDpdShipment($order, $account, '00000000000002', now()->subMinutes(2));
        $this->createDpdShipment(
            $order,
            $account,
            '00000000000003',
            now()->subMinute(),
            Shipment::STATUS_CANCELLED,
        );
        [, $printer] = $this->createPdfPrinter();
        $this->createPrintRule(
            $printer,
            'shipment.created',
            AutomationCatalog::PRINT_DOCUMENT_LATEST_SHIPMENT_LABEL,
        );
        $this->fakeDpdLabels();

        app(AutomationEngine::class)->evaluate('shipment.created', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'shipment.created',
            'order_id' => $order->getKey(),
            'shipment_id' => $newer->getKey(),
            'source' => 'manual',
        ]);

        $run = AutomationRun::query()->with('steps')->firstOrFail();
        $job = NexPrintJob::query()->sole();

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame($newer->getKey(), $run->steps->firstOrFail()->output['shipment_id']);
        $this->assertSame($newer->tracking_number, $job->document_number);
        $this->assertNotSame($older->tracking_number, $job->document_number);
        $this->assertSame('shipment_label', $job->document_type);
        $this->assertSame(NexPrintJob::ORIGIN_AUTOMATION, $job->origin);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => (
            data_get($request->data(), 'labelSearchParams.session.packages.0.parcels.0.waybill')
                === $newer->tracking_number
        ));
    }

    public function test_automatic_print_queues_all_available_labels_once_in_creation_order(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $account = $this->createDpdAccount();
        $older = $this->createDpdShipment($order, $account, '00000000000011', now()->subMinutes(2));
        $newer = $this->createDpdShipment($order, $account, '00000000000012', now()->subMinute());
        $this->createDpdShipment(
            $order,
            $account,
            '00000000000013',
            now(),
            Shipment::STATUS_CANCELLED,
        );
        [, $printer] = $this->createPdfPrinter();
        $this->createPrintRule(
            $printer,
            'order.status_changed',
            AutomationCatalog::PRINT_DOCUMENT_ALL_SHIPMENT_LABELS,
        );
        $this->fakeDpdLabels();

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->getKey(),
            'source' => 'manual',
        ]);

        $run = AutomationRun::query()->with('steps')->firstOrFail();
        $step = $run->steps->firstOrFail();
        $jobs = NexPrintJob::query()->orderBy('id')->get();

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $step->output['document_count']);
        $this->assertSame([$older->getKey(), $newer->getKey()], $step->output['shipment_ids']);
        $this->assertSame(
            [$older->tracking_number, $newer->tracking_number],
            $jobs->pluck('document_number')->all(),
        );
        $this->assertSame(
            [NexPrintJob::ORIGIN_AUTOMATION, NexPrintJob::ORIGIN_AUTOMATION],
            $jobs->pluck('origin')->all(),
        );

        app(AutomationNexPrinterActionService::class)->execute($run, $step, $step->configuration);

        $this->assertDatabaseCount('nex_print_jobs', 2);
        $this->assertDatabaseCount('nex_print_job_events', 2);
        Http::assertSentCount(2);
    }

    public function test_automatic_label_print_fails_when_order_has_no_available_pdf_label(): void
    {
        $order = $this->createDocumentOrder();
        [, $printer] = $this->createPdfPrinter();
        $this->createPrintRule(
            $printer,
            'order.status_changed',
            AutomationCatalog::PRINT_DOCUMENT_LATEST_SHIPMENT_LABEL,
        );

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->getKey(),
            'source' => 'manual',
        ]);

        $run = AutomationRun::query()->with('steps')->firstOrFail();

        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertSame(
            'Nie znaleziono dostępnej etykiety PDF do wydruku.',
            $run->steps->firstOrFail()->error_message,
        );
        $this->assertDatabaseCount('nex_print_jobs', 0);
    }

    public function test_print_can_follow_invoice_issuing_in_the_same_rule(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        [, $printer] = $this->createPdfPrinter();
        $rule = AutomationRule::query()->create([
            'name' => 'Wystaw i wydrukuj Fakturę VAT',
            'trigger' => 'order.status_changed',
            'conditions' => [],
            'is_active' => true,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_ISSUE_INVOICE,
            'configuration' => ['invoice_series_id' => $series->getKey()],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_PRINT,
            'configuration' => [
                'document_type' => 'invoice',
                'printer_id' => $printer->getKey(),
            ],
            'stop_on_error' => true,
            'sort_order' => 2,
        ]);
        $this->mock(InvoicePdfService::class)
            ->shouldReceive('contents')
            ->once()
            ->andReturn("%PDF-1.4\nISSUE AND PRINT");

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->getKey(),
            'source' => 'manual',
        ]);

        $run = AutomationRun::query()->with('steps')->firstOrFail();
        $job = NexPrintJob::query()->firstOrFail();

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(['completed', 'completed'], $run->steps->pluck('status')->all());
        $this->assertSame(
            $run->steps->first()->output['invoice_id'],
            $run->steps->last()->output['invoice_id'],
        );
        $this->assertSame($run->steps->last()->output['invoice_id'], $job->invoice_id);
    }

    public function test_automatic_print_fails_without_an_issued_invoice(): void
    {
        $order = $this->createDocumentOrder();
        [, $printer] = $this->createPdfPrinter();
        $this->createPrintRule($printer, 'order.status_changed');

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->getKey(),
            'source' => 'manual',
        ]);

        $run = AutomationRun::query()->with('steps')->firstOrFail();

        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertSame('failed', $run->steps->firstOrFail()->status);
        $this->assertSame(
            'Nie znaleziono wystawionej Faktury VAT do wydruku.',
            $run->steps->firstOrFail()->error_message,
        );
        $this->assertDatabaseCount('nex_print_jobs', 0);
    }

    private function createPrintRule(
        NexPrinter $printer,
        string $trigger = 'invoice.issued',
        string $documentType = AutomationCatalog::PRINT_DOCUMENT_INVOICE,
    ): AutomationRule {
        $rule = AutomationRule::query()->create([
            'name' => 'Automatyczny wydruk Faktury VAT',
            'trigger' => $trigger,
            'conditions' => [],
            'is_active' => true,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_PRINT,
            'configuration' => [
                'document_type' => $documentType,
                'printer_id' => $printer->getKey(),
            ],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);

        return $rule;
    }

    /** @return array{NexPrinterAgent, NexPrinter} */
    private function createPdfPrinter(mixed $lastSeenAt = null): array
    {
        $agent = NexPrinterAgent::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Komputer księgowości',
            'platform' => 'windows',
            'client_version' => '0.3.1',
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'is_active' => true,
            'paired_at' => now(),
            'last_seen_at' => $lastSeenAt ?? now(),
        ]);
        $printer = NexPrinter::query()->create([
            'agent_id' => $agent->getKey(),
            'system_name' => 'Drukarka faktur',
            'display_name' => 'Drukarka faktur',
            'is_default' => false,
            'is_enabled' => true,
            'is_available' => true,
            'capabilities' => ['formats' => ['pdf']],
            'last_seen_at' => $lastSeenAt ?? now(),
        ]);

        return [$agent, $printer];
    }

    private function rulePayload(
        mixed $printerId,
        string $documentType = AutomationCatalog::PRINT_DOCUMENT_INVOICE,
    ): array {
        return [
            'name' => 'Automatyczny wydruk',
            'description' => '',
            'trigger' => 'invoice.issued',
            'is_active' => '1',
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_PRINT,
                'configuration' => [
                    'document_type' => $documentType,
                    'printer_id' => $printerId,
                ],
                'stop_on_error' => '1',
            ]],
        ];
    }

    private function createDpdAccount(): CourierAccount
    {
        return CourierAccount::query()->create([
            'provider' => CourierAccount::PROVIDER_DPD,
            'name' => 'DPD',
            'environment' => 'sandbox',
            'api_token' => 'dpd-password',
            'organization_id' => '100001',
            'settings' => [
                'api_login' => 'dpd-login',
                'info_channel' => 'NEXOMS',
                'label_format' => 'PDF',
                'label_type' => 'LABEL',
            ],
            'is_active' => true,
        ]);
    }

    private function createDpdShipment(
        Order $order,
        CourierAccount $account,
        string $trackingNumber,
        mixed $createdAt,
        string $status = '30103',
    ): Shipment {
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->getKey(),
            'provider' => CourierAccount::PROVIDER_DPD,
            'service' => Shipment::SERVICE_DPD_DOMESTIC,
            'status' => $status,
            'oms_status' => Shipment::OMS_STATUS_CREATED,
            'external_id' => 'dpd-'.$trackingNumber,
            'tracking_number' => $trackingNumber,
            'currency' => 'PLN',
            'label_format' => 'PDF',
            'label_type' => 'LABEL',
            'request_uuid' => (string) Str::uuid(),
        ]);
        $shipment->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
        $shipment->parcels()->create([
            'position' => 1,
            'weight' => 1,
            'length' => 25,
            'width' => 20,
            'height' => 10,
            'tracking_number' => $trackingNumber,
        ]);

        return $shipment->fresh(['courierAccount', 'parcels']);
    }

    private function fakeDpdLabels(): void
    {
        Http::fake([
            'https://dpdservicesdemo.dpd.com.pl/public/shipment/v1/generateSpedLabels' => function (Request $request) {
                $trackingNumber = (string) data_get(
                    $request->data(),
                    'labelSearchParams.session.packages.0.parcels.0.waybill',
                );

                return Http::response([
                    'status' => 'OK',
                    'documentData' => base64_encode("%PDF-1.4\nLABEL {$trackingNumber}"),
                ]);
            },
        ]);
    }
}
