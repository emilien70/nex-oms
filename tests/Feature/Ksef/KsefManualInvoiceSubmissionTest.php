<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\TestCase;

class KsefManualInvoiceSubmissionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ksef.invoice_submission_enabled', true);
        Http::preventStrayRequests();
    }

    public function test_first_manual_send_posts_invoice_exactly_once_without_status_polling(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $response = $this->post(route('invoices.ksef.submissions.store', $invoice));

        $response->assertRedirect()
            ->assertSessionHas('success', 'Faktura została przekazana do KSeF TEST. Sprawdź status, aby potwierdzić przyjęcie.');
        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertSame(1, $submission->attempt_number);
        $this->assertSame(1, $fake->openCalls);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->closeCalls);
        $this->assertSame(0, $fake->statusCalls);
    }

    public function test_second_manual_post_is_blocked_after_successful_first_attempt(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.store', $invoice))->assertSessionHas('success');
        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(0, $fake->statusCalls);
    }

    public function test_retry_safe_technical_failure_allows_a_new_attempt_and_preserves_history(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->failures['/invoices'] = [
            'status' => 400,
            'body' => ['reasonCode' => 'SYNTHETIC_DEFINITE_FAILURE'],
        ];

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');
        $this->assertSame(
            KsefInvoiceSubmissionStatus::TechnicalFailed,
            KsefInvoiceSubmission::query()->sole()->status,
        );
        $first = KsefInvoiceSubmission::query()->sole();
        $firstPayload = $first->payload_xml;
        $firstRawPayload = DB::table('ksef_invoice_submissions')
            ->where('id', $first->getKey())
            ->value('payload_xml');
        unset($fake->failures['/invoices']);

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHas('success');

        $second = KsefInvoiceSubmission::query()->orderByDesc('attempt_number')->firstOrFail();
        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        $this->assertSame(2, $second->attempt_number);
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $second->status);
        $this->assertSame($firstPayload, $first->fresh()->payload_xml);
        $this->assertNotSame(
            $firstRawPayload,
            DB::table('ksef_invoice_submissions')->where('id', $second->getKey())->value('payload_xml'),
        );
        $this->assertSame(2, $fake->sendCalls);
        $this->assertSame(0, $fake->statusCalls);
    }

    #[DataProvider('blockingStatuses')]
    public function test_any_existing_attempt_in_current_environment_blocks_manual_send(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission($invoice, $status);

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
    }

    public static function blockingStatuses(): array
    {
        return collect(KsefInvoiceSubmissionStatus::cases())
            ->reject(fn (KsefInvoiceSubmissionStatus $status): bool => $status->allowsNewAttempt())
            ->mapWithKeys(fn (KsefInvoiceSubmissionStatus $status): array => [
                $status->value => [$status],
            ])
            ->all();
    }

    #[DataProvider('retryableStatuses')]
    public function test_retryable_attempt_allows_exactly_one_new_manual_attempt(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $first = $this->createSubmission($invoice, $status);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.store', $invoice))->assertSessionHas('success');
        $this->post(route('invoices.ksef.submissions.store', $invoice))->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        $this->assertSame($status, $first->fresh()->status);
        $this->assertDatabaseHas('ksef_invoice_submissions', [
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test->value,
            'attempt_number' => 2,
            'status' => KsefInvoiceSubmissionStatus::Submitted->value,
        ]);
        $this->assertSame(1, $fake->sendCalls);
    }

    public static function retryableStatuses(): array
    {
        return [
            'rejected' => [KsefInvoiceSubmissionStatus::Rejected],
            'technical failed' => [KsefInvoiceSubmissionStatus::TechnicalFailed],
        ];
    }

    public function test_accepted_history_blocks_retry_even_when_latest_attempt_is_rejected(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Accepted);
        $latest = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Rejected);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('Historia KSeF tej Faktury nie pozwala utworzyć kolejnej próby.')
            ->assertDontSee(route('invoices.ksef.submissions.store', $invoice), false);
        $this->post(route('invoices.ksef.submissions.store', $invoice))->assertSessionHasErrors('ksef');
        $this->assertSame(KsefInvoiceSubmissionStatus::Rejected, $latest->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        Http::assertNothingSent();
    }

    public function test_attempt_in_another_environment_does_not_block_first_attempt_in_current_environment(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::Rejected,
            KsefEnvironment::Demo,
        );
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertDatabaseHas('ksef_invoice_submissions', [
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test->value,
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Submitted->value,
        ]);
    }

    public function test_manual_send_preconditions_reject_without_submission_or_http(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $invoice = app(InvoiceFinalizationService::class)->finalize($invoice);
        app(KsefSettingsService::class)->get()->forceFill(['is_active' => false])->save();
        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        app(KsefSettingsService::class)->get()->forceFill(['is_active' => true])->save();
        KsefSeriesSetting::query()
            ->where('invoice_series_id', $invoice->invoice_series_id)
            ->update(['is_enabled' => false]);
        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_deployment_gate_rejects_manual_send_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        config()->set('ksef.invoice_submission_enabled', false);

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('blockedEnvironments')]
    public function test_demo_and_production_are_blocked_without_http(KsefEnvironment $environment): void
    {
        $invoice = $this->eligibleInvoice(environment: $environment);

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public static function blockedEnvironments(): array
    {
        return [
            'demo' => [KsefEnvironment::Demo],
            'production' => [KsefEnvironment::Production],
        ];
    }

    #[DataProvider('unsupportedDocumentTypes')]
    public function test_proforma_and_correction_cannot_use_manual_send_route(
        InvoiceDocumentType $documentType,
    ): void {
        $invoice = $this->eligibleInvoice();
        $invoice->forceFill(['document_type' => $documentType])->saveQuietly();

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public static function unsupportedDocumentTypes(): array
    {
        return [
            'proforma' => [InvoiceDocumentType::Proforma],
            'correction' => [InvoiceDocumentType::Correction],
        ];
    }

    public function test_submitted_attempt_can_be_refreshed_once_to_processing(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $submission = app(KsefInvoiceSubmissionService::class)
            ->submit(app(KsefInvoiceSubmissionService::class)->prepare($invoice));

        $this->post(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertSessionHas('success', 'Status KSeF został odświeżony.');

        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->refresh()->status);
        $this->assertSame(1, $fake->statusCalls);
    }

    public function test_processing_attempt_can_be_refreshed_once_to_accepted(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-21T10:00:00Z',
            'acquisitionDate' => '2026-08-21T10:00:01Z',
            'permanentStorageDate' => '2026-08-21T10:00:02Z',
            'status' => ['code' => 200, 'description' => 'Przyjęta'],
            'ksefNumber' => $this->validKsefNumber('9876543210'),
        ];

        $this->post(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertSessionHas('success');

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame($fake->statusResponse['ksefNumber'], $submission->ksef_number);
        $this->assertSame(1, $fake->statusCalls);
    }

    #[DataProvider('nonRefreshableStatuses')]
    public function test_non_refreshable_states_are_blocked_without_http(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, $status);

        $this->post(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertSessionHasErrors('ksef');

        Http::assertNothingSent();
    }

    public static function nonRefreshableStatuses(): array
    {
        return collect(KsefInvoiceSubmissionStatus::cases())
            ->reject(fn (KsefInvoiceSubmissionStatus $status): bool => in_array($status, [
                KsefInvoiceSubmissionStatus::Submitted,
                KsefInvoiceSubmissionStatus::Processing,
            ], true))
            ->mapWithKeys(fn (KsefInvoiceSubmissionStatus $status): array => [
                $status->value => [$status],
            ])
            ->all();
    }

    public function test_cross_invoice_refresh_returns_404_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $otherInvoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($otherInvoice, KsefInvoiceSubmissionStatus::Submitted);

        $this->post(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_uncertain_attempt_can_be_reconciled_once_without_invoice_resend(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-21T10:00:00Z',
            'ordinalNumber' => 1,
            'status' => ['code' => 150, 'description' => 'Przetwarzanie'],
        ];

        $route = route('invoices.ksef.submissions.reconcile', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]);
        $this->post($route)->assertSessionHas('success', 'Wynik transmisji KSeF został sprawdzony.');
        $this->post($route)->assertSessionHasErrors('ksef');

        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->refresh()->status);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->sessionInvoicesCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_cross_invoice_reconciliation_returns_404_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $otherInvoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($otherInvoice, KsefInvoiceSubmissionStatus::Uncertain);

        $this->post(route('invoices.ksef.submissions.reconcile', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_manual_routes_do_not_accept_get_requests(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Submitted);

        $this->get(route('invoices.ksef.submissions.store', $invoice))->assertMethodNotAllowed();
        $this->get(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertMethodNotAllowed();
        $this->get(route('invoices.ksef.submissions.reconcile', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertMethodNotAllowed();
        Http::assertNothingSent();
    }

    public function test_read_only_invoice_panel_shows_manual_test_send_and_no_secrets(): void
    {
        $invoice = $this->eligibleInvoice();

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('KSeF')
            ->assertSee('TEST')
            ->assertSee('Nie wysłano')
            ->assertSee('Wyślij do KSeF TEST')
            ->assertSee(route('invoices.ksef.submissions.store', $invoice), false)
            ->assertSee('Wyślij Fakturę do KSeF TEST?')
            ->assertDontSee('FAKE_SUBMISSION_API_TOKEN');
    }

    public function test_current_status_ignores_other_environment_attempt_but_history_and_test_send_remain_visible(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::Rejected,
            KsefEnvironment::Demo,
            ['safe_error_message' => 'Bezpieczny historyczny opis DEMO.'],
        );

        $response = $this->get(route('invoices.edit', $invoice));
        $html = $response->getContent();

        $response->assertOk()
            ->assertSee('Wyślij do KSeF TEST');
        $this->assertMatchesRegularExpression(
            '/<div class="invoice-ksef-status-row">.*?data-ksef-current-status[^>]*>Nie wysłano<\/span>.*?<\/div>/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<table[^>]*data-ksef-submission-history[^>]*>.*?DEMO.*?Odrzucona.*?<\/table>/s',
            $html,
        );
    }

    public function test_accepted_panel_shows_full_number_without_send_or_refresh(): void
    {
        $invoice = $this->eligibleInvoice();
        $number = $this->validKsefNumber('9876543210');
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Accepted, attributes: [
            'ksef_number' => $number,
            'acquisition_date' => now(),
        ]);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('Przyjęta')
            ->assertSee($number)
            ->assertDontSee(route('invoices.ksef.submissions.store', $invoice), false)
            ->assertDontSee(route('invoices.ksef.submissions.refresh', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false)
            ->assertDontSee(route('invoices.ksef.submissions.reconcile', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false);
    }

    #[DataProvider('refreshableStatuses')]
    public function test_submitted_and_processing_panels_show_only_manual_refresh(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, $status);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee($status->label())
            ->assertSee('Sprawdź status')
            ->assertSee(route('invoices.ksef.submissions.refresh', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false)
            ->assertDontSee(route('invoices.ksef.submissions.reconcile', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false)
            ->assertDontSee('Wyślij do KSeF TEST');
    }

    public static function refreshableStatuses(): array
    {
        return [
            'submitted' => [KsefInvoiceSubmissionStatus::Submitted],
            'processing' => [KsefInvoiceSubmissionStatus::Processing],
        ];
    }

    #[DataProvider('failedUiStatuses')]
    public function test_rejected_and_technical_failed_panels_show_safe_error_and_controlled_new_attempt(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, $status, attributes: [
            'safe_error_message' => 'Bezpieczny komunikat dla użytkownika.',
            'invoice_reference_number' => 'HIDDEN_RAW_REFERENCE',
        ]);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee($status->label())
            ->assertSee('Bezpieczny komunikat dla użytkownika.')
            ->assertSee('Utwórz nową próbę KSeF TEST')
            ->assertSee('Poprzednia próba pozostanie w historii.')
            ->assertDontSee('HIDDEN_RAW_REFERENCE')
            ->assertSee(route('invoices.ksef.submissions.store', $invoice), false)
            ->assertDontSee(route('invoices.ksef.submissions.refresh', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false);
    }

    public static function failedUiStatuses(): array
    {
        return [
            'rejected' => [KsefInvoiceSubmissionStatus::Rejected],
            'technical_failed' => [KsefInvoiceSubmissionStatus::TechnicalFailed],
        ];
    }

    public function test_rejected_panel_and_history_show_safe_ksef_status_code_without_raw_details(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Rejected, attributes: [
            'ksef_status_code' => 415,
            'safe_error_message' => 'KSeF odrzucił Fakturę podczas weryfikacji.',
            'invoice_reference_number' => 'RAW_SYNTHETIC_REJECTION_DETAIL',
        ]);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('Odrzucona')
            ->assertSee('data-ksef-current-status-code', false)
            ->assertSee('data-ksef-history-status-code', false)
            ->assertSee('Kod KSeF: <strong>415</strong>', false)
            ->assertSee('Kod KSeF: 415')
            ->assertSee('KSeF odrzucił Fakturę podczas weryfikacji.')
            ->assertDontSee('RAW_SYNTHETIC_REJECTION_DETAIL');
    }

    public function test_uncertain_and_failed_history_exposes_only_safe_diagnostics_latest_first(): void
    {
        $invoice = $this->eligibleInvoice();
        $first = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Rejected, attributes: [
            'safe_error_message' => 'Bezpieczny opis odrzucenia.',
            'session_reference_number' => 'HIDDEN_SESSION_REFERENCE',
        ]);
        $second = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain, attributes: [
            'safe_error_message' => 'Bezpieczny opis stanu niepewnego.',
            'payload_xml' => '<Faktura>HIDDEN_XML_CONTENT</Faktura>',
            'invoice_hash' => 'HIDDEN_INVOICE_HASH',
            'context_nip' => '1234567890',
            'seller_nip' => '1234567890',
        ]);

        $response = $this->get(route('invoices.edit', $invoice));
        $html = $response->getContent();

        $response->assertOk()
            ->assertSee('Stan niepewny')
            ->assertSee('Nie wolno ponownie wysłać dokumentu przed ustaleniem wyniku poprzedniej transmisji.')
            ->assertSee('Sprawdź wynik transmisji')
            ->assertSee(route('invoices.ksef.submissions.reconcile', [
                'invoice' => $invoice,
                'submission' => $second,
            ]), false)
            ->assertSee('Bezpieczny opis stanu niepewnego.')
            ->assertSee('Bezpieczny opis odrzucenia.')
            ->assertDontSee('Wyślij do KSeF TEST')
            ->assertDontSee('Ponów')
            ->assertDontSee('HIDDEN_XML_CONTENT')
            ->assertDontSee('HIDDEN_INVOICE_HASH')
            ->assertDontSee('HIDDEN_SESSION_REFERENCE')
            ->assertDontSee('1234567890');
        $this->assertLessThan(
            strpos($html, 'data-ksef-submission-id="'.$first->getKey().'"'),
            strpos($html, 'data-ksef-submission-id="'.$second->getKey().'"'),
        );
    }

    public function test_uncertain_without_session_reference_blocks_resend_and_has_no_reconciliation_action(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain, attributes: [
            'session_reference_number' => null,
            'invoice_reference_number' => null,
        ]);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('Brak referencji sesji potrzebnej do bezpiecznego sprawdzenia wyniku.')
            ->assertDontSee(route('invoices.ksef.submissions.store', $invoice), false)
            ->assertDontSee(route('invoices.ksef.submissions.reconcile', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false);

        $this->post(route('invoices.ksef.submissions.reconcile', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertSessionHasErrors('ksef');
        $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_gate_false_keeps_history_visible_but_hides_manual_actions(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Submitted);
        config()->set('ksef.invoice_submission_enabled', false);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('Wysłana')
            ->assertSee('Wysyłka KSeF jest wyłączona na poziomie wdrożenia.')
            ->assertDontSee(route('invoices.ksef.submissions.store', $invoice), false)
            ->assertDontSee(route('invoices.ksef.submissions.refresh', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false);
    }

    public function test_automatic_submission_setting_remains_inert_even_with_enabled_gate(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);
        app(KsefSettingsService::class)->get()->forceFill(['automatic_submission' => true])->save();

        app(InvoiceFinalizationService::class)->finalize($invoice);

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    private function eligibleInvoice(
        bool $finalize = true,
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): Invoice {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'is_active' => true,
            'environment' => $environment,
            'context_nip' => '9876543210',
        ])->save();
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-MANUAL-'.uniqid(),
            'billing_tax_id' => '5260250995',
            'delivery_cost_gross' => '0.00',
            'paid_amount' => '0.00',
        ]);
        $this->createDocumentItem($order, [
            'unit_price_gross' => '123.00',
            'total_price_gross' => '123.00',
            'vat_rate' => '23.00',
        ]);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Invoice, [
            'include_shipping' => false,
            'seller_tax_id' => '9876543210',
        ]);
        KsefSeriesSetting::query()->create([
            'invoice_series_id' => $series->getKey(),
            'is_enabled' => true,
        ]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        )->refresh()->load('items');

        return $finalize
            ? app(InvoiceFinalizationService::class)->finalize($invoice)->load('items')
            : $invoice;
    }

    private function validAccessToken(): KsefCredential
    {
        return KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_MANUAL_API_TOKEN',
            'access_token' => 'FAKE_VALID_MANUAL_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_VALID_MANUAL_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ]);
    }

    private function fakeOnlineApi(): KsefOnlineSessionApiFake
    {
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(fn (Request $request) => $fake($request));

        return $fake;
    }

    private function createSubmission(
        Invoice $invoice,
        KsefInvoiceSubmissionStatus $status,
        KsefEnvironment $environment = KsefEnvironment::Test,
        array $attributes = [],
    ): KsefInvoiceSubmission {
        $attemptNumber = ((int) KsefInvoiceSubmission::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('environment', $environment->value)
            ->max('attempt_number')) + 1;
        $payload = '<Faktura>FAKE MANUAL PAYLOAD '.$attemptNumber.'</Faktura>';

        return KsefInvoiceSubmission::query()->create(array_replace([
            'invoice_id' => $invoice->getKey(),
            'environment' => $environment,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => $attemptNumber,
            'status' => $status,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now()->subMinutes(10 - $attemptNumber),
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
            'session_reference_number' => '20260821-SO-MANUAL-REFERENCE',
            'invoice_reference_number' => '20260821-INV-MANUAL-REFERENCE',
        ], $attributes));
    }

    private function validKsefNumber(string $sellerNip): string
    {
        $base = $sellerNip.'-20260821-0100001AF629';
        $checksum = 0;
        foreach (str_split($base) as $character) {
            $checksum ^= ord($character);
            for ($bit = 0; $bit < 8; $bit++) {
                $checksum = ($checksum & 0x80) !== 0
                    ? (($checksum << 1) ^ 0x07) & 0xFF
                    : ($checksum << 1) & 0xFF;
            }
        }

        return $base.'-'.strtoupper(str_pad(dechex($checksum), 2, '0', STR_PAD_LEFT));
    }
}
