<?php

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Integrations\NexPrinter\Models\NexPrinter;
use Modules\Integrations\NexPrinter\Models\NexPrinterAgent;
use Modules\Integrations\NexPrinter\Models\NexPrintJob;
use Modules\Integrations\NexPrinter\Services\NexPrinterPairingService;
use Modules\Integrations\NexPrinter\Services\NexPrintJobService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfService;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class NexPrinterTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_integration_page_uses_nex_printer_name_and_creates_one_time_pairing_code(): void
    {
        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('NEX Printer')
            ->assertSee(route('integrations.nex-printer.index'), false);

        $this->get(route('integrations.nex-printer.index'))
            ->assertOk()
            ->assertSee('NEX Printer')
            ->assertSee('Połącz program');

        $response = $this->post(route('integrations.nex-printer.pairing-codes.store'))
            ->assertRedirect()
            ->assertSessionHas('nex_printer_pairing_code');

        $pairingCode = $response->getSession()->get('nex_printer_pairing_code');
        $this->assertIsArray($pairingCode);
        $this->assertSame(10, strlen($pairingCode['code']));
        $this->assertDatabaseCount('nex_printer_pairing_codes', 1);
        $this->assertDatabaseMissing('nex_printer_pairing_codes', [
            'code_hash' => $pairingCode['code'],
        ]);
    }

    public function test_release_and_client_version_states_are_visible_on_the_integration_page(): void
    {
        Storage::fake('local');
        $release = $this->storeNexPrinterRelease();
        $this->createVersionedAgent('0.5.0', 'Komputer aktualny');
        $this->createVersionedAgent('0.4.0', 'Komputer starszy');
        $this->createVersionedAgent('0.6.0', 'Komputer testowy');
        $this->createVersionedAgent(null, 'Komputer bez wersji');

        $this->get(route('integrations.nex-printer.index'))
            ->assertOk()
            ->assertSee('Wersja programu')
            ->assertSee('NEX Printer 0.5.0')
            ->assertSee('Pobierz instalator')
            ->assertSee(route('integrations.nex-printer.release.download'), false)
            ->assertSee(substr($release['sha256'], 0, 12))
            ->assertSee('Aktualna')
            ->assertSee('Dostępna aktualizacja')
            ->assertSee('Wersja testowa')
            ->assertSee('Nieznana');
    }

    public function test_published_installer_is_downloaded_only_when_its_checksum_matches(): void
    {
        Storage::fake('local');
        $contents = "MZ\0NEX PRINTER INSTALLER";
        $release = $this->storeNexPrinterRelease(contents: $contents);

        $response = $this->post(route('integrations.nex-printer.release.download'))
            ->assertOk()
            ->assertDownload($release['filename'])
            ->assertHeader('Content-Type', 'application/vnd.microsoft.portable-executable')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-NEX-Printer-SHA256', $release['sha256']);
        $this->assertSame($contents, $response->streamedContent());

        Storage::disk('local')->put($release['path'], str_repeat('X', strlen($contents)));

        $this->post(route('integrations.nex-printer.release.download'))
            ->assertNotFound();
    }

    public function test_missing_or_invalid_release_manifest_is_not_exposed(): void
    {
        Storage::fake('local');

        $this->get(route('integrations.nex-printer.index'))
            ->assertOk()
            ->assertSee('Brak opublikowanego instalatora NEX Printer.')
            ->assertDontSee('Pobierz instalator');
        $this->post(route('integrations.nex-printer.release.download'))
            ->assertNotFound();

        Storage::disk('local')->put(
            'nex-printer/releases/latest.json',
            json_encode([
                'version' => '0.5.0',
                'filename' => '../NEX-Printer-Setup-0.5.0-x64.exe',
                'sha256' => str_repeat('a', 64),
                'size' => 10,
            ], JSON_THROW_ON_ERROR),
        );

        $this->post(route('integrations.nex-printer.release.download'))
            ->assertNotFound();
    }

    public function test_client_pairs_once_and_synchronizes_pdf_printers(): void
    {
        $pairingCode = app(NexPrinterPairingService::class)->createCode();

        $paired = $this->postJson(route('nex-printer.api.pair'), [
            'code' => $pairingCode['code'],
            'name' => 'Magazyn Windows',
            'platform' => 'windows',
            'client_version' => '1.0.0',
        ])->assertCreated()
            ->assertJsonPath('agent.name', 'Magazyn Windows')
            ->assertJsonStructure(['agent' => ['id', 'name'], 'token']);

        $token = $paired->json('token');
        $this->assertIsString($token);
        $this->assertDatabaseHas('nex_printer_agents', [
            'name' => 'Magazyn Windows',
            'token_hash' => hash('sha256', $token),
            'is_active' => true,
        ]);

        $this->postJson(route('nex-printer.api.pair'), [
            'code' => $pairingCode['code'],
            'name' => 'Powtórka',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_pairing_code');

        $this->withToken($token)->postJson(route('nex-printer.api.heartbeat'), [
            'client_version' => '1.0.1',
        ])->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->withToken($token)->putJson(route('nex-printer.api.printers.update'), [
            'printers' => [[
                'system_name' => 'Microsoft Print to PDF',
                'display_name' => 'Microsoft Print to PDF',
                'is_default' => true,
                'capabilities' => ['formats' => ['pdf']],
            ]],
        ])->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertDatabaseHas('nex_printers', [
            'system_name' => 'Microsoft Print to PDF',
            'is_enabled' => true,
            'is_available' => true,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('nex_printer_agents', [
            'name' => 'Magazyn Windows',
            'client_version' => '1.0.1',
        ]);

        $this->withToken($token)->putJson(route('nex-printer.api.printers.update'), [
            'printers' => [
                [
                    'system_name' => 'Microsoft Print to PDF',
                    'display_name' => 'Microsoft Print to PDF',
                    'is_default' => true,
                    'capabilities' => ['formats' => ['pdf']],
                ],
                [
                    'system_name' => 'Fax',
                    'display_name' => 'Fax',
                    'is_default' => false,
                    'capabilities' => ['formats' => ['pdf']],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('nex_printers', [
            'system_name' => 'Microsoft Print to PDF',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('nex_printers', [
            'system_name' => 'Fax',
            'is_enabled' => false,
            'is_available' => true,
            'is_default' => false,
        ]);

        $this->withToken($token)->putJson(route('nex-printer.api.printers.update'), [
            'printers' => [[
                'system_name' => 'Fax',
                'display_name' => 'Fax',
                'is_default' => true,
                'capabilities' => ['formats' => ['pdf']],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('nex_printers', [
            'system_name' => 'Fax',
            'is_enabled' => false,
            'is_available' => true,
            'is_default' => false,
        ]);

        NexPrinter::query()->update([
            'is_enabled' => false,
            'is_default' => false,
        ]);

        $this->withToken($token)->putJson(route('nex-printer.api.printers.update'), [
            'printers' => [[
                'system_name' => 'Fax',
                'display_name' => 'Fax',
                'is_default' => true,
                'capabilities' => ['formats' => ['pdf']],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('nex_printers', [
            'system_name' => 'Fax',
            'is_enabled' => false,
            'is_default' => false,
        ]);
        $this->assertDatabaseMissing('nex_printers', [
            'is_default' => true,
        ]);
    }

    public function test_user_controls_enabled_printers_without_a_default_printer_requirement(): void
    {
        [$agent, $firstPrinter] = $this->createPdfPrinter();
        $secondaryPrinter = NexPrinter::query()->create([
            'agent_id' => $agent->getKey(),
            'system_name' => 'Drukarka magazynowa',
            'display_name' => 'Drukarka magazynowa',
            'is_default' => false,
            'is_enabled' => false,
            'is_available' => true,
            'capabilities' => ['formats' => ['pdf']],
            'last_seen_at' => now(),
        ]);

        $this->get(route('integrations.nex-printer.index'))
            ->assertOk()
            ->assertSee('Aktywna')
            ->assertSee(route('integrations.nex-printer.printers.enabled', $secondaryPrinter), false)
            ->assertDontSee('Ustaw domyślną')
            ->assertDontSee('Domyślna');

        $this->patch(route('integrations.nex-printer.printers.enabled', $secondaryPrinter), [
            'is_enabled' => true,
        ])->assertRedirect()
            ->assertSessionHas('success');
        $this->assertTrue($secondaryPrinter->fresh()->is_enabled);

        $this->patch(route('integrations.nex-printer.printers.enabled', $secondaryPrinter), [
            'is_enabled' => false,
        ])->assertRedirect()
            ->assertSessionHas('success');
        $this->assertFalse($secondaryPrinter->fresh()->is_enabled);

        $this->patch(route('integrations.nex-printer.printers.enabled', $firstPrinter), [
            'is_enabled' => false,
        ])->assertRedirect()
            ->assertSessionHas('success');
        $this->assertFalse($firstPrinter->fresh()->is_enabled);
        $this->assertFalse($firstPrinter->fresh()->is_default);
    }

    public function test_disabled_selected_printer_cannot_receive_a_new_job(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        [, $printer] = $this->createPdfPrinter();
        $printer->forceFill(['is_enabled' => false])->save();

        $this->postJson(route('invoices.nex-printer.store', $invoice), [
            'printer_id' => $printer->getKey(),
            'idempotency_key' => '88f5d50c-c8cc-4e37-8b4e-fc758bfa35c6',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'printer_unavailable');

        $this->assertDatabaseCount('nex_print_jobs', 0);
    }

    public function test_disconnected_agent_can_be_removed_with_printers_while_job_history_is_preserved(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        [$agent, $printer] = $this->createPdfPrinter();
        $this->mock(InvoicePdfService::class)
            ->shouldReceive('contents')
            ->once()
            ->andReturn("%PDF-1.4\nAGENT REMOVAL");
        $job = app(NexPrintJobService::class)->queueInvoice(
            $invoice,
            $printer,
            'd141ee2d-f15f-46e3-b3ec-7e1f550077b4',
        );

        $this->get(route('integrations.nex-printer.index'))
            ->assertOk()
            ->assertDontSee(route('integrations.nex-printer.agents.remove', $agent), false);

        $this->delete(route('integrations.nex-printer.agents.remove', $agent))
            ->assertRedirect()
            ->assertSessionHasErrors('nex_printer');
        $this->assertDatabaseHas('nex_printer_agents', ['id' => $agent->getKey()]);

        $this->delete(route('integrations.nex-printer.agents.destroy', $agent))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->get(route('integrations.nex-printer.index'))
            ->assertOk()
            ->assertSee('Odłączony')
            ->assertSee('Usuń')
            ->assertSee(route('integrations.nex-printer.agents.remove', $agent), false);

        $this->delete(route('integrations.nex-printer.agents.remove', $agent))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('nex_printer_agents', ['id' => $agent->getKey()]);
        $this->assertDatabaseMissing('nex_printers', ['id' => $printer->getKey()]);
        $this->assertDatabaseHas('nex_print_jobs', [
            'id' => $job->getKey(),
            'agent_id' => null,
            'printer_id' => null,
            'status' => 'cancelled',
            'last_error_code' => 'agent_removed',
        ]);
        $this->assertDatabaseHas('nex_print_job_events', [
            'print_job_id' => $job->getKey(),
            'event_type' => 'cancelled',
            'status' => 'cancelled',
        ]);
    }

    public function test_invoice_printer_icon_queues_private_pdf_once_and_keeps_pdf_preview_link(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        [$agent, $printer] = $this->createPdfPrinter();
        $agent->forceFill(['last_seen_at' => now()->subHour()])->save();
        $otherPrinter = NexPrinter::query()->create([
            'agent_id' => $agent->getKey(),
            'system_name' => 'Drukarka zapasowa',
            'display_name' => 'Drukarka zapasowa',
            'is_default' => false,
            'is_enabled' => true,
            'is_available' => true,
            'capabilities' => ['formats' => ['pdf']],
            'last_seen_at' => now(),
        ]);
        NexPrinter::query()->create([
            'agent_id' => $agent->getKey(),
            'system_name' => 'Drukarka ZPL',
            'display_name' => 'Drukarka ZPL',
            'is_default' => false,
            'is_enabled' => true,
            'is_available' => true,
            'capabilities' => ['formats' => ['zpl']],
            'last_seen_at' => now(),
        ]);

        $this->mock(InvoicePdfService::class)
            ->shouldReceive('contents')
            ->once()
            ->withArgs(fn ($document): bool => $document->is($invoice))
            ->andReturn("%PDF-1.4\nNEX PRINTER TEST");

        $page = $this->get(route('orders.show', $order))->assertOk();
        $page->assertSee(route('invoices.pdf', $invoice), false)
            ->assertSee(route('invoices.nex-printer.store', $invoice), false)
            ->assertSee('name="csrf-token"', false)
            ->assertSee('Drukuj - prześlij bezpośrednio do drukarki')
            ->assertSee('Microsoft Print to PDF (Komputer testowy)')
            ->assertSee('Drukarka zapasowa (Komputer testowy)')
            ->assertDontSee('Drukarka ZPL (Komputer testowy)')
            ->assertDontSee('Liczba kopii');

        $idempotencyKey = '9cb468f2-58a0-4ced-a98b-e739aa6c3ef0';
        $this->postJson(route('invoices.nex-printer.store', $invoice), [
            'printer_id' => $printer->getKey(),
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated()
            ->assertJsonPath('message', 'Zlecenie wydruku zostało dodane.');

        $job = NexPrintJob::query()->sole();
        $this->assertSame('invoice', $job->document_type);
        $this->assertSame($invoice->number, $job->document_number);
        $this->assertSame($printer->getKey(), $job->printer_id);
        $this->assertSame(1, $job->copies);
        $this->assertSame(NexPrintJob::ORIGIN_MANUAL, $job->origin);
        $this->assertSame(hash('sha256', "%PDF-1.4\nNEX PRINTER TEST"), $job->payload_sha256);
        Storage::disk('local')->assertExists($job->payload_path);

        $this->postJson(route('invoices.nex-printer.store', $invoice), [
            'printer_id' => $printer->getKey(),
            'idempotency_key' => $idempotencyKey,
        ])->assertOk();
        $this->assertDatabaseCount('nex_print_jobs', 1);
        $this->assertDatabaseCount('nex_print_job_events', 1);

        $this->postJson(route('invoices.nex-printer.store', $invoice), [
            'printer_id' => $otherPrinter->getKey(),
            'idempotency_key' => $idempotencyKey,
        ])->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertDatabaseCount('nex_print_jobs', 1);
    }

    public function test_shipment_label_printer_icon_queues_the_courier_pdf_once(): void
    {
        Storage::fake('local');
        $shipment = $this->createDpdShipment();
        [, $printer] = $this->createPdfPrinter();
        Http::fake([
            'https://dpdservicesdemo.dpd.com.pl/public/shipment/v1/generateSpedLabels' => Http::response([
                'status' => 'OK',
                'documentData' => base64_encode("%PDF-1.4\nDPD LABEL"),
            ]),
        ]);

        $this->get(route('orders.show', $shipment->order_id))
            ->assertOk()
            ->assertSee(route('shipments.label', $shipment), false)
            ->assertSee(route('shipments.nex-printer.store', $shipment), false)
            ->assertSee('class="btn-group shipment-label-group"', false)
            ->assertSee('shipment-label-print-button', false)
            ->assertSee('Drukuj - prześlij bezpośrednio do drukarki')
            ->assertSee('Microsoft Print to PDF (Komputer testowy)');

        $idempotencyKey = '3634383d-cffe-48cb-af8e-2e3029ad9aad';
        $this->postJson(route('shipments.nex-printer.store', $shipment), [
            'printer_id' => $printer->getKey(),
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated()
            ->assertJsonPath('message', 'Zlecenie wydruku zostało dodane.')
            ->assertJsonPath('job.document_number', $shipment->tracking_number);

        $job = NexPrintJob::query()->sole();
        $this->assertSame('shipment_label', $job->document_type);
        $this->assertSame($shipment->tracking_number, $job->document_number);
        $this->assertSame($shipment->order_id, $job->order_id);
        $this->assertNull($job->invoice_id);
        $this->assertSame('dpd-'.$shipment->tracking_number.'.pdf', $job->payload_filename);
        $this->assertSame(NexPrintJob::ORIGIN_MANUAL, $job->origin);
        $this->assertSame(hash('sha256', "%PDF-1.4\nDPD LABEL"), $job->payload_sha256);
        Storage::disk('local')->assertExists($job->payload_path);

        $this->postJson(route('shipments.nex-printer.store', $shipment), [
            'printer_id' => $printer->getKey(),
            'idempotency_key' => $idempotencyKey,
        ])->assertOk();

        $this->assertDatabaseCount('nex_print_jobs', 1);
        $this->assertDatabaseCount('nex_print_job_events', 1);
        Http::assertSentCount(1);
    }

    public function test_non_pdf_shipment_label_is_not_added_to_the_print_queue(): void
    {
        Storage::fake('local');
        $shipment = $this->createDpdShipment(['label_format' => 'ZPL']);
        [, $printer] = $this->createPdfPrinter();
        Http::fake();

        $this->postJson(route('shipments.nex-printer.store', $shipment), [
            'printer_id' => $printer->getKey(),
            'idempotency_key' => 'fa80f0b9-8288-40cb-8b35-bb837ef797ce',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'shipment_label_not_pdf');

        $this->assertDatabaseCount('nex_print_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_ajax_print_requires_and_accepts_the_session_csrf_token(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        [, $printer] = $this->createPdfPrinter();

        $this->app->bind(
            ValidateCsrfToken::class,
            fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            },
        );

        $payload = [
            'printer_id' => $printer->getKey(),
            'idempotency_key' => '9cb468f2-58a0-4ced-a98b-e739aa6c3ef1',
        ];

        $this->postJson(route('invoices.nex-printer.store', $invoice), $payload)
            ->assertStatus(419);

        $this->mock(InvoicePdfService::class)
            ->shouldReceive('contents')
            ->once()
            ->withArgs(fn ($document): bool => $document->is($invoice))
            ->andReturn("%PDF-1.4\nNEX PRINTER CSRF TEST");

        $this->withSession(['_token' => 'NEX_PRINTER_CSRF_TOKEN'])
            ->withHeader('X-CSRF-TOKEN', 'NEX_PRINTER_CSRF_TOKEN')
            ->postJson(route('invoices.nex-printer.store', $invoice), $payload)
            ->assertCreated()
            ->assertJsonPath('message', 'Zlecenie wydruku zostało dodane.');

        $this->assertDatabaseCount('nex_print_jobs', 1);
    }

    public function test_client_claims_downloads_and_confirms_a_print_job(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        [$agent, $printer, $token] = $this->createPdfPrinter();

        $this->mock(InvoicePdfService::class)
            ->shouldReceive('contents')
            ->once()
            ->andReturn("%PDF-1.4\nCLIENT FLOW");

        $job = app(NexPrintJobService::class)->queueInvoice(
            $invoice,
            $printer,
            '70c67f58-ce1d-461b-829b-19783c1f70a8',
        );
        $this->assertTrue($job->agent->is($agent));
        $this->assertTrue($job->printer->is($printer));

        $claim = $this->withToken($token)
            ->postJson(route('nex-printer.api.jobs.claim'))
            ->assertOk()
            ->assertJsonPath('job.id', $job->public_id)
            ->assertJsonPath('job.content_type', 'application/pdf')
            ->assertJsonPath('job.printer.system_name', $printer->system_name);
        $leaseToken = $claim->json('lease_token');
        $job->refresh();
        $leaseBeforeDownload = $job->lease_expires_at;
        $this->travel(1)->minute();

        $this->withToken($token)
            ->withHeader('X-NEX-Printer-Lease', $leaseToken)
            ->get(route('nex-printer.api.jobs.payload', $job->public_id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertContent("%PDF-1.4\nCLIENT FLOW");
        $job->refresh();
        $this->assertTrue($job->lease_expires_at->greaterThan($leaseBeforeDownload));

        $this->withToken($token)
            ->withHeader('X-NEX-Printer-Lease', $leaseToken)
            ->postJson(route('nex-printer.api.jobs.complete', $job->public_id), [
                'status' => 'spooled',
            ])->assertOk()
            ->assertJsonPath('job.status', 'spooled');

        $this->assertDatabaseHas('nex_print_jobs', [
            'id' => $job->getKey(),
            'status' => 'spooled',
        ]);
        $this->assertSame(
            ['queued', 'claimed', 'payload_downloaded', 'spooled'],
            $job->events()->pluck('event_type')->all(),
        );
        $this->travelBack();
    }

    public function test_print_request_requires_an_explicit_printer(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $this->postJson(route('invoices.nex-printer.store', $invoice), [
            'idempotency_key' => '516c6830-0c58-43cc-b3be-44cbd31d315d',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('printer_id');

        $this->assertDatabaseCount('nex_print_jobs', 0);
    }

    public function test_another_authenticated_agent_cannot_complete_someone_elses_job(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        [, $printer, $token] = $this->createPdfPrinter();
        $this->mock(InvoicePdfService::class)
            ->shouldReceive('contents')
            ->once()
            ->andReturn("%PDF-1.4\nAGENT OWNERSHIP");
        $job = app(NexPrintJobService::class)->queueInvoice(
            $invoice,
            $printer,
            '8fd2d38e-1c35-4bc2-91b0-140a18a5f6d3',
        );

        $claim = $this->withToken($token)
            ->postJson(route('nex-printer.api.jobs.claim'))
            ->assertOk();

        $otherToken = bin2hex(random_bytes(32));
        NexPrinterAgent::query()->create([
            'public_id' => '5798a15b-ef6a-4ffb-a2ec-a93dcc27fbeb',
            'name' => 'Inny komputer',
            'platform' => 'windows',
            'token_hash' => hash('sha256', $otherToken),
            'is_active' => true,
            'paired_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->withToken($otherToken)
            ->withHeader('X-NEX-Printer-Lease', $claim->json('lease_token'))
            ->postJson(route('nex-printer.api.jobs.complete', $job->public_id), [
                'status' => 'spooled',
            ])->assertConflict()
            ->assertJsonPath('error.code', 'job_agent_mismatch');

        $this->assertDatabaseHas('nex_print_jobs', [
            'id' => $job->getKey(),
            'status' => 'claimed',
        ]);
    }

    /** @return array{NexPrinterAgent, NexPrinter, string} */
    private function createPdfPrinter(): array
    {
        $token = bin2hex(random_bytes(32));
        $agent = NexPrinterAgent::query()->create([
            'public_id' => 'ee9a4cc4-e170-4c99-b9f9-2a11218fbd23',
            'name' => 'Komputer testowy',
            'platform' => 'windows',
            'client_version' => '1.0.0',
            'token_hash' => hash('sha256', $token),
            'is_active' => true,
            'paired_at' => now(),
            'last_seen_at' => now(),
        ]);
        $printer = NexPrinter::query()->create([
            'agent_id' => $agent->getKey(),
            'system_name' => 'Microsoft Print to PDF',
            'display_name' => 'Microsoft Print to PDF',
            'is_default' => false,
            'is_enabled' => true,
            'is_available' => true,
            'capabilities' => ['formats' => ['pdf']],
            'last_seen_at' => now(),
        ]);

        return [$agent, $printer, $token];
    }

    private function createVersionedAgent(?string $version, string $name): NexPrinterAgent
    {
        return NexPrinterAgent::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'platform' => 'windows',
            'client_version' => $version,
            'token_hash' => hash('sha256', random_bytes(32)),
            'is_active' => true,
            'paired_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /** @return array{filename: string, path: string, sha256: string} */
    private function storeNexPrinterRelease(
        string $version = '0.5.0',
        string $contents = "MZ\0NEX PRINTER INSTALLER",
    ): array {
        $filename = "NEX-Printer-Setup-{$version}-x64.exe";
        $path = 'nex-printer/releases/'.$filename;
        $sha256 = hash('sha256', $contents);
        Storage::disk('local')->put($path, $contents);
        Storage::disk('local')->put(
            'nex-printer/releases/latest.json',
            json_encode([
                'version' => $version,
                'filename' => $filename,
                'sha256' => $sha256,
                'size' => strlen($contents),
                'published_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
        );

        return compact('filename', 'path', 'sha256');
    }

    private function createDpdShipment(array $overrides = []): Shipment
    {
        $order = $this->createDocumentOrder();
        $account = CourierAccount::query()->create([
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
        $shipment = $order->shipments()->create(array_merge([
            'courier_account_id' => $account->getKey(),
            'provider' => CourierAccount::PROVIDER_DPD,
            'service' => Shipment::SERVICE_DPD_DOMESTIC,
            'status' => '30103',
            'oms_status' => Shipment::OMS_STATUS_CREATED,
            'external_id' => '771122',
            'tracking_number' => '00000000000001',
            'currency' => 'PLN',
            'label_format' => 'PDF',
            'label_type' => 'LABEL',
            'request_uuid' => (string) Str::uuid(),
        ], $overrides));
        $shipment->parcels()->create([
            'position' => 1,
            'weight' => 1,
            'length' => 25,
            'width' => 20,
            'height' => 10,
            'tracking_number' => $shipment->tracking_number,
        ]);

        return $shipment->fresh(['order', 'courierAccount', 'parcels']);
    }
}
