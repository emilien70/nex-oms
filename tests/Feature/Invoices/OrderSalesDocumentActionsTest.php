<?php

namespace Tests\Feature\Invoices;

use App\Models\Order;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\OrderSalesDocumentActionsView;
use Modules\Invoices\Services\ProformaService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class OrderSalesDocumentActionsTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_management_fragment_has_stable_container_and_existing_actions(): void
    {
        $order = $this->createDocumentOrder();
        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString('id="order-sales-document-actions"', $html);
        $this->assertStringContainsString('WYSTAW FAKTUR', $html);
        $this->assertStringContainsString('PRO FORMA', $html);
        $this->assertStringContainsString('data-sales-document-form', $html);
        $this->assertStringNotContainsString('Wystawianie', $html);
        $this->assertStringNotContainsString('Tworzenie', $html);
        $this->assertStringNotContainsString('Brak aktywnej serii', $html);
    }

    public function test_single_active_series_uses_plain_button_and_filters_other_series(): void
    {
        $order = $this->createDocumentOrder();
        $invoiceSeries = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Invoice)
            ->firstOrFail();
        $proformaSeries = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Proforma)
            ->firstOrFail();
        $hidden = $this->createDocumentSeries(attributes: [
            'name' => 'Ukryta seria faktur',
            'is_active' => false,
        ]);
        $otherType = $this->createDocumentSeries(InvoiceDocumentType::Correction, [
            'name' => 'Seria korekt niewidoczna w akcjach',
        ]);

        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString('value="'.$invoiceSeries->getKey().'"', $html);
        $this->assertStringContainsString('value="'.$proformaSeries->getKey().'"', $html);
        $this->assertStringNotContainsString('dropdown-toggle', $html);
        $this->assertStringNotContainsString($hidden->name, $html);
        $this->assertStringNotContainsString($otherType->name, $html);
    }

    public function test_multiple_active_invoice_series_are_presented_only_by_name(): void
    {
        $order = $this->createDocumentOrder();
        $first = $this->createDocumentSeries();
        $second = $this->createDocumentSeries(attributes: ['name' => 'Faktury dodatkowe']);
        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString('dropdown-toggle', $html);
        $this->assertStringContainsString($first->name, $html);
        $this->assertStringContainsString($second->name, $html);
        $this->assertStringNotContainsString($first->number_format, $html);
        $this->assertStringNotContainsString('systemowa', $html);
    }

    public function test_order_page_contains_only_one_sales_document_actions_fragment(): void
    {
        $template = file_get_contents(resource_path('views/orders/show.blade.php'));

        $this->assertIsString($template);
        $this->assertStringContainsString('Zarz&#261;dzanie', $template);
        $this->assertSame(1, substr_count($template, "@include('orders.partials.sales-document-actions'"));
    }

    public function test_order_page_keeps_one_shared_delete_modal_outside_the_replaceable_fragment(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $fragment = app(OrderSalesDocumentActionsView::class)->render($order);
        $response = $this->get(route('orders.show', $order))->assertOk();
        $page = $response->getContent();

        $this->assertStringContainsString('data-sales-document-delete-trigger', $fragment);
        $this->assertStringContainsString('data-document-id="'.$invoice->getKey().'"', $fragment);
        $this->assertStringContainsString('data-document-type="invoice"', $fragment);
        $this->assertStringContainsString('data-document-number="'.$invoice->number.'"', $fragment);
        $this->assertStringContainsString('data-delete-url="'.route('invoices.destroy', $invoice).'"', $fragment);
        $this->assertStringContainsString('data-expected-lock-version="'.$invoice->lock_version.'"', $fragment);
        $this->assertStringContainsString('data-return-to="order"', $fragment);
        $this->assertStringContainsString('data-bs-target="#deleteSalesDocumentModal"', $fragment);
        $this->assertStringNotContainsString('data-sales-document-delete-form', $fragment);
        $this->assertStringNotContainsString('id="deleteSalesDocumentModal"', $fragment);

        $this->assertSame(1, substr_count($page, 'id="deleteSalesDocumentModal"'));
        $this->assertSame(1, substr_count(
            $page,
            '<form method="POST" action="" data-sales-document-form data-sales-document-delete-form>',
        ));
        $this->assertSame(1, substr_count($page, 'id="orderKsefSendConfirmationModal"'));
        $this->assertGreaterThan(
            strpos($page, 'id="order-sales-document-actions"'),
            strpos($page, 'id="deleteSalesDocumentModal"'),
        );
        $this->assertStringContainsString('salesDocumentInteractionActive()', $page);
        $this->assertStringContainsString('deferredSalesDocumentHtml', $page);
        $this->assertStringContainsString("modal.addEventListener('hidden.bs.modal'", $page);
        $this->assertStringContainsString('flushDeferredSalesDocumentRefresh()', $page);
        $this->assertStringNotContainsString("document.querySelectorAll('.modal-backdrop')", $page);
        $this->assertStringNotContainsString("document.body.classList.remove('modal-open')", $page);
    }

    public function test_proforma_number_is_visible_until_invoice_is_issued(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $invoiceSeries = $this->createDocumentSeries();
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order,
            $proformaSeries,
            $this->documentContext(),
        )->invoice;

        $before = app(OrderSalesDocumentActionsView::class)->render($order);
        $this->assertStringContainsString($proforma->number, $before);
        $this->assertStringContainsString('WYSTAW FAKTUR', $before);
        $this->assertStringContainsString('data-open-document-after-submit', $before);
        $this->assertStringContainsString(route('orders.proforma.store', $order), $before);
        $this->assertStringContainsString('value="'.$proforma->invoice_series_id.'"', $before);

        $invoice = app(InvoiceIssuingService::class)->issue($order, $invoiceSeries, $this->documentContext());
        $after = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString($invoice->number, $after);
        $this->assertStringContainsString(route('invoices.edit', [
            'invoice' => $invoice,
            'return_to' => 'order',
        ]), $after);
        $this->assertStringNotContainsString($proforma->number, $after);
        $this->assertStringNotContainsString('PRO FORMA', $after);
    }

    public function test_order_page_uses_the_existing_ksef_submission_flow_for_an_eligible_invoice(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        );

        $this->enableKsefForSeries($series);

        $response = $this->get(route('orders.show', $order));

        $response->assertOk()
            ->assertSee(route('invoices.ksef.submissions.first-attempt', $invoice), false)
            ->assertSee('data-order-ksef-send-form', false)
            ->assertSee('data-order-ksef-send-trigger', false)
            ->assertSee('name="return_to" value="order"', false)
            ->assertSee('data-bs-title="Przeka&#380; do KSeF"', false)
            ->assertSee('data-order-ksef-send-modal', false)
            ->assertSee('Czy przekaza&#263; faktur&#281; do KSeF 2.0?', false)
            ->assertSee('data-order-ksef-send-confirm', false)
            ->assertSee('HTMLFormElement.prototype.submit.call(form);', false)
            ->assertDontSee('data-sales-document-ksef-label', false);
    }

    public function test_automatic_ksef_invoice_marks_management_fragment_for_temporary_ajax_refresh(): void
    {
        config()->set('ksef.invoice_submission_enabled', true);
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'is_active' => true,
            'automatic_submission' => true,
            'environment' => KsefEnvironment::Test,
            'context_nip' => '9876543210',
        ])->save();

        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        KsefSeriesSetting::query()->create([
            'invoice_series_id' => $series->getKey(),
            'is_enabled' => true,
        ]);
        app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        );

        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString('data-ksef-automatic-refresh="1"', $html);
    }

    public function test_order_page_hides_ksef_action_for_an_invoice_series_not_enabled_for_ksef(): void
    {
        config()->set('ksef.invoice_submission_enabled', true);
        app(KsefSettingsService::class)->get()->forceFill([
            'is_active' => true,
            'environment' => KsefEnvironment::Test,
        ])->save();

        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringNotContainsString('management-issued-invoice-ksef', $html);
        $this->assertStringNotContainsString('data-order-ksef-send-form', $html);
        $this->assertStringNotContainsString('data-order-ksef-reference', $html);
    }

    public function test_pending_ksef_submission_shows_disabled_indicator_while_ajax_refresh_continues(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        );
        $this->enableKsefForSeries($series);

        $payload = '<Faktura>ORDER CARD KSEF TEST</Faktura>';
        KsefInvoiceSubmission::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Submitted,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now(),
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
        ]);
        KsefSeriesSetting::query()
            ->where('invoice_series_id', $series->getKey())
            ->update(['is_enabled' => false]);

        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString('data-ksef-automatic-refresh="1"', $html);
        $this->assertStringContainsString('data-order-ksef-pending', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringContainsString('data-bs-title="Faktura jest przekazywana do KSeF"', $html);
        $this->assertStringNotContainsString('data-order-ksef-reference', $html);
        $this->assertStringNotContainsString('KSeF: '.$invoice->number, $html);
        $this->assertStringNotContainsString('data-order-ksef-send-form', $html);
        $this->assertStringNotContainsString('data-order-ksef-send-trigger', $html);
        $this->assertStringNotContainsString('data-sales-document-ksef-label', $html);
        $this->assertStringNotContainsString('data-order-ksef-invoice-pdf', $html);
    }

    public function test_accepted_ksef_submission_links_oms_invoice_number_to_official_pdf_download_flow(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        );
        $this->enableKsefForSeries($series);

        $payload = '<Faktura>ORDER CARD ACCEPTED KSEF TEST</Faktura>';
        $submission = KsefInvoiceSubmission::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now(),
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
            'ksef_number' => '9876543210-20260825-AAAAAAAAAA-BB',
            'acquisition_date' => now(),
        ]);

        $response = $this->get(route('orders.show', $order));
        $downloadRoute = route('invoices.ksef.submissions.invoice.download', compact('invoice', 'submission'));

        $response->assertOk()
            ->assertSee('data-ksef-automatic-refresh="0"', false)
            ->assertSee('data-order-ksef-invoice-pdf', false)
            ->assertSee('data-ksef-invoice-source-url="'.$downloadRoute.'"', false)
            ->assertSee('data-ksef-number="'.$submission->ksef_number.'"', false)
            ->assertSee('KSeF: '.$invoice->number)
            ->assertSee('Pobierz PDF Faktury z KSeF')
            ->assertSee('ksef-fe-invoice-converter.umd.js', false)
            ->assertSee("generator.generateInvoice(invoiceFile, additionalData, 'blob')", false)
            ->assertSee('rgba(220, 53, 69, 0.8)', false);
        $this->assertFileExists(public_path(
            'vendor/ksef-pdf-generator/1.1.31/ksef-fe-invoice-converter.umd.js',
        ));
        $this->assertFileExists(public_path('vendor/ksef-pdf-generator/1.1.31/LICENSE'));
    }

    public function test_issued_correction_is_presented_with_invoice_style_actions(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $correctionSeries = $this->createDocumentSeries(InvoiceDocumentType::Correction);
        $correction = app(CorrectionService::class)->issue(
            $invoice,
            $correctionSeries,
            $invoice->getKey(),
            $invoice->lock_version,
            [
                'correction_series_id' => $correctionSeries->getKey(),
                'reason' => CorrectionReason::BuyerDataUpdate->value,
                'other_reason' => null,
                'issue_date' => '2026-08-05',
                'sale_date' => '2026-07-20',
                'payment_method' => 'Przelew',
                'issuer_name' => 'Tester korekty',
                'additional_information' => null,
                'change_items' => false,
                'change_buyer' => true,
                'items' => [],
                'buyer' => array_merge($invoice->buyer_snapshot, [
                    'name' => 'Jan Nowak',
                    'company_name' => null,
                ]),
            ],
            $this->documentContext('2026-08-05 10:00:00'),
        );

        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString('Korekta:', $html);
        $this->assertStringContainsString('management-issued-correction-actions', $html);
        $this->assertStringContainsString($correction->number, $html);
        $this->assertSame(1, substr_count($html, route('invoices.pdf', $correction)));
        $this->assertStringContainsString(route('invoices.nex-printer.store', $correction), $html);
        $this->assertStringContainsString(route('invoices.corrections.edit', [
            'correction' => $correction,
            'return_to' => 'order',
        ]), $html);
        $this->assertStringContainsString('data-bs-target="#deleteSalesDocumentModal"', $html);
        $this->assertStringContainsString('data-document-type="correction"', $html);
        $this->assertStringContainsString(route('invoices.destroy', $correction), $html);
        $this->assertSame(2, substr_count($html, 'data-sales-document-number'));
    }

    public function test_finalized_correction_history_and_current_correction_are_presented_separately(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $series = $this->createDocumentSeries(InvoiceDocumentType::Correction);
        $first = $this->issueBuyerCorrection($invoice, $series, 'Pierwszy nabywca');
        app(InvoiceFinalizationService::class)->finalize($first);
        $second = $this->issueBuyerCorrection($invoice, $series, 'Drugi nabywca');

        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString($first->number, $html);
        $this->assertStringContainsString($second->number, $html);
        $this->assertStringContainsString('Otwórz zamkniętą Korektę', $html);
        $this->assertStringContainsString('Edytuj Korekt', $html);
        $this->assertSame(1, substr_count($html, 'data-bs-target="#deleteSalesDocumentModal"'));
        $this->assertStringNotContainsString('data-document-id="'.$first->getKey().'"', $html);
    }

    #[DataProvider('correctionSeriesCases')]
    public function test_correction_ksef_send_visibility_depends_on_its_own_series(bool $invoiceEnabled, bool $correctionEnabled): void
    {
        [$order, $invoice, $series, $correction] = $this->correctionFixture();
        $this->enableKsefForSeries($series);
        KsefSeriesSetting::query()->where('invoice_series_id', $series->id)->update(['is_enabled' => $correctionEnabled]);
        if ($invoiceEnabled) {
            $this->enableKsefForSeries($invoice->series);
        }
        $before = $correction->fresh()->getAttributes();

        $html = $this->correctionActionsHtml(app(OrderSalesDocumentActionsView::class)->render($order), $correction);

        $this->assertSame($correctionEnabled, str_contains($html, 'data-order-ksef-send-form'));
        $this->assertSame($correctionEnabled, str_contains($html, 'management-issued-invoice-ksef'));
        $this->assertStringNotContainsString('bi-paperclip', $html);
        $this->assertStringContainsString('data-sales-document-delete-trigger', $html);
        if ($correctionEnabled) {
            $this->assertStringContainsString(route('invoices.ksef.submissions.first-attempt', $correction), $html);
            $this->assertStringContainsString('Czy przekazać Korektę '.$correction->number.' do KSeF TEST?', $html);
            $this->assertStringContainsString('nie będzie można jej edytować ani usunąć', $html);
            $this->assertStringContainsString('name="return_to" value="order"', $html);
        }
        $this->assertSame($before, $correction->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public static function correctionSeriesCases(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    #[DataProvider('correctionSendGates')]
    public function test_correction_ksef_send_is_disabled_when_integration_or_deployment_gate_is_off(string $gate): void
    {
        [$order, , $series, $correction] = $this->correctionFixture();
        $this->enableKsefForSeries($series);
        if ($gate === 'integration') {
            app(KsefSettingsService::class)->get()->forceFill(['is_active' => false])->save();
        } else {
            config()->set('ksef.invoice_submission_enabled', false);
        }

        $html = $this->correctionActionsHtml(app(OrderSalesDocumentActionsView::class)->render($order), $correction);

        $this->assertStringContainsString('management-issued-invoice-ksef', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('data-order-ksef-send-form', $html);
        Http::assertNothingSent();
    }

    public static function correctionSendGates(): array
    {
        return [['integration'], ['deployment']];
    }

    #[DataProvider('correctionSubmissionStatuses')]
    public function test_correction_ksef_history_controls_pdf_status_refresh_and_delete_visibility(KsefInvoiceSubmissionStatus $status): void
    {
        [$order, , $series, $correction] = $this->correctionFixture();
        app(InvoiceFinalizationService::class)->finalize($correction);
        $this->enableKsefForSeries($series);
        $submission = $this->documentSubmission($correction, $status);
        $before = $correction->fresh()->getAttributes();

        $fragment = app(OrderSalesDocumentActionsView::class)->render($order);
        $html = $this->correctionActionsHtml($fragment, $correction);

        $this->assertStringNotContainsString('data-order-ksef-send-form', $html);
        $this->assertStringNotContainsString('data-sales-document-delete-trigger', $html);
        $this->assertStringContainsString('data-ksef-automatic-refresh="'.($status->isTerminal() ? '0' : '1').'"', $fragment);
        if ($status === KsefInvoiceSubmissionStatus::Accepted) {
            $this->assertStringContainsString('KSeF: '.$correction->number, $html);
            $this->assertStringContainsString('management-issued-invoice-ksef-reference', $html);
            $this->assertStringContainsString('data-ksef-pdf-open="1"', $html);
            $this->assertStringContainsString('data-ksef-number="'.$submission->ksef_number.'"', $html);
            $this->assertStringContainsString(route('invoices.ksef.submissions.invoice.download', [
                'invoice' => $correction, 'submission' => $submission,
            ]), $html);
            KsefSeriesSetting::query()->where('invoice_series_id', $series->id)->update(['is_enabled' => false]);
            $disabledSeriesHtml = $this->correctionActionsHtml(app(OrderSalesDocumentActionsView::class)->render($order), $correction);
            $this->assertStringContainsString('KSeF: '.$correction->number, $disabledSeriesHtml);
            $this->assertStringContainsString('data-ksef-pdf-open="1"', $disabledSeriesHtml);
        } else {
            $this->assertStringNotContainsString('data-order-ksef-invoice-pdf', $html);
            $this->assertStringContainsString($status->label(), $html);
            if ($status->isTerminal() || $status->requiresReconciliation()) {
                $this->assertStringContainsString('href="'.route('invoices.corrections.edit', [
                    'correction' => $correction, 'return_to' => 'order',
                ]).'#correction-ksef"', $html);
            } else {
                $this->assertStringContainsString('aria-disabled="true"', $html);
            }
        }
        $this->assertSame($before, $correction->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public static function correctionSubmissionStatuses(): array
    {
        return array_map(fn (KsefInvoiceSubmissionStatus $status): array => [$status], KsefInvoiceSubmissionStatus::cases());
    }

    public function test_correction_ksef_history_never_uses_another_environment_as_current_acceptance(): void
    {
        [$order, , $series, $correction] = $this->correctionFixture();
        app(InvoiceFinalizationService::class)->finalize($correction);
        $this->enableKsefForSeries($series);
        $this->documentSubmission($correction, KsefInvoiceSubmissionStatus::Accepted, KsefEnvironment::Demo);

        $html = $this->correctionActionsHtml(app(OrderSalesDocumentActionsView::class)->render($order), $correction);

        $this->assertStringContainsString('data-order-ksef-send-form', $html);
        $this->assertStringContainsString('do KSeF TEST?', $html);
        $this->assertStringNotContainsString('data-order-ksef-reference', $html);
        Http::assertNothingSent();
    }

    public function test_each_correction_has_its_own_ksef_state_without_additional_per_document_ksef_queries(): void
    {
        [$order, $invoice, $series, $first] = $this->correctionFixture();
        app(InvoiceFinalizationService::class)->finalize($first);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (preg_match('/from "ksef_(invoice_submissions|series_settings|offline_issuances)"/', $query->sql)) {
                $queries[] = $query->sql;
            }
        });
        app(OrderSalesDocumentActionsView::class)->render($order);
        $singleCount = count($queries);

        $second = $this->issueBuyerCorrection($invoice, $series, 'Drugi nabywca KSeF');
        app(InvoiceFinalizationService::class)->finalize($second);
        $third = $this->issueBuyerCorrection($invoice, $series, 'Trzeci nabywca KSeF');
        $this->enableKsefForSeries($series);
        $this->documentSubmission($first, KsefInvoiceSubmissionStatus::Accepted);
        $this->documentSubmission($second, KsefInvoiceSubmissionStatus::Processing);
        $queries = [];

        $fragment = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertSame($singleCount, count($queries));
        $this->assertStringNotContainsString('payload_xml', implode(' ', $queries));
        $firstHtml = $this->correctionActionsHtml($fragment, $first);
        $secondHtml = $this->correctionActionsHtml($fragment, $second);
        $thirdHtml = $this->correctionActionsHtml($fragment, $third);
        $this->assertStringContainsString('KSeF: '.$first->number, $firstHtml);
        $this->assertStringNotContainsString('data-order-ksef-send-form', $firstHtml);
        $this->assertStringContainsString('data-order-ksef-pending', $secondHtml);
        $this->assertStringNotContainsString('data-order-ksef-invoice-pdf', $secondHtml);
        $this->assertStringContainsString('data-order-ksef-send-form', $thirdHtml);
        $this->assertStringContainsString('data-sales-document-delete-trigger', $thirdHtml);
        $this->assertStringContainsString('data-ksef-automatic-refresh="1"', $fragment);
        Http::assertNothingSent();
    }

    /** @return array{Order, Invoice, InvoiceSeries, Invoice} */
    private function correctionFixture(): array
    {
        Http::preventStrayRequests();
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext());
        $series = $this->createDocumentSeries(InvoiceDocumentType::Correction);

        return [$order, $invoice, $series, $this->issueBuyerCorrection($invoice, $series, 'Nabywca korekty KSeF')];
    }

    private function documentSubmission(
        Invoice $document,
        KsefInvoiceSubmissionStatus $status,
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): KsefInvoiceSubmission {
        $payload = '<Faktura>ORDER CORRECTION KSEF '.$document->id.'</Faktura>';

        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $document->id, 'environment' => $environment,
            'context_nip' => '9876543210', 'seller_nip' => '9876543210',
            'attempt_number' => 1, 'status' => $status, 'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now(), 'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)), 'invoice_size' => strlen($payload),
            'ksef_number' => $status === KsefInvoiceSubmissionStatus::Accepted ? '9876543210-20260825-AAAAAAAAAA-BB' : null,
            'acquisition_date' => $status === KsefInvoiceSubmissionStatus::Accepted ? now() : null,
        ]);
    }

    private function correctionActionsHtml(string $html, Invoice $correction): string
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $node = (new DOMXPath($dom))->query('//*[@data-correction-id="'.$correction->id.'"]')->item(0);
        $this->assertNotNull($node);

        return $dom->saveHTML($node);
    }

    private function issueBuyerCorrection(Invoice $invoice, InvoiceSeries $series, string $buyerName): Invoice
    {
        $effective = app(CorrectionSourceStateService::class)->chain($invoice)
            ->effectiveSourceDocument;

        return app(CorrectionService::class)->issue(
            $invoice,
            $series,
            $effective->getKey(),
            $effective->lock_version,
            [
                'correction_series_id' => $series->getKey(),
                'reason' => CorrectionReason::BuyerDataUpdate->value,
                'other_reason' => null,
                'issue_date' => '2026-08-05',
                'sale_date' => '2026-07-20',
                'payment_method' => 'Przelew',
                'issuer_name' => 'Tester korekty',
                'additional_information' => null,
                'change_items' => false,
                'change_buyer' => true,
                'items' => [],
                'buyer' => array_merge($effective->buyer_snapshot, [
                    'name' => $buyerName,
                    'company_name' => null,
                ]),
            ],
            $this->documentContext('2026-08-05 10:00:00'),
        );
    }

    private function enableKsefForSeries(InvoiceSeries $series): void
    {
        config()->set('ksef.invoice_submission_enabled', true);
        app(KsefSettingsService::class)->get()->forceFill([
            'is_active' => true,
            'environment' => KsefEnvironment::Test,
        ])->save();
        KsefSeriesSetting::query()->updateOrCreate(
            ['invoice_series_id' => $series->getKey()],
            ['is_enabled' => true],
        );
    }
}
