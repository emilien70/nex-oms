<?php

namespace Tests\Feature\Invoices;

use App\Models\OrderReturn;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Events\CorrectionFinalized;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\InvoiceDeletionService;
use Modules\Invoices\Services\InvoiceEditabilityPolicy;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\ManualCorrectionFinalizationPolicy;
use Modules\Invoices\Services\ManualCorrectionFinalizationService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionSourceReferenceResolver;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefOfflineIssuanceService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\Support\Ksef\UsesAutocommitDatabase;
use Tests\TestCase;

class ManualCorrectionFinalizationTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use UsesAutocommitDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_button_is_only_on_an_eligible_edit_card_and_confirmation_is_separate_from_edit(): void
    {
        [$root, $correction] = $this->scenario();
        $html = $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertSee('data-correction-finalize-trigger', false)
            ->assertSee('action="'.route('invoices.corrections.finalize', $correction).'"', false)
            ->assertSee('Czy na pewno zamknąć korektę? Po zamknięciu nie będzie można jej edytować ani usunąć. Operacji nie można cofnąć.')
            ->assertSee('name="confirm_outside_ksef" value="1"', false)
            ->assertSee('name="expected_ksef_environment" value="demo"', false)
            ->assertSee('name="_token"', false)->getContent();
        $this->assertStringNotContainsString('name="confirm_outside_ksef" value="1" checked', $html);
        $this->assertSame(1, substr_count($html, ' data-correction-finalize-form>'));
        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseMissing('ksef_invoice_provenances', ['invoice_id' => $correction->id]);

        $this->close($correction);
        $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertDontSee('data-correction-finalize-trigger', false)
            ->assertDontSee('data-correction-finalize-form', false);
        $this->get(route('invoices.corrections.create', $root))->assertOk()
            ->assertDontSee('data-correction-finalize-trigger', false)
            ->assertDontSee('data-correction-finalize-form', false);
    }

    #[DataProvider('environments')]
    public function test_manual_close_preserves_document_and_records_its_own_outside_decision(KsefEnvironment $environment): void
    {
        [$root, $correction] = $this->scenario($environment);
        $before = $correction->getAttributes();
        $rootBefore = $root->fresh()->getAttributes();
        $items = $correction->items->map->getAttributes()->all();
        $counters = InvoiceNumberCounter::query()->get()->toArray();
        $events = $root->order->events()->count();
        $dispatched = 0;
        Event::listen(CorrectionFinalized::class, function () use (&$dispatched): void {
            $dispatched++;
        });
        $payload = $this->closePayload($correction, $environment);
        $context = ['return_to' => 'corrections', 'return_query' => 'page=2&sort=buyer'];
        $url = route('invoices.corrections.edit', ['correction' => $correction, ...$context]);

        $this->post(route('invoices.corrections.finalize', $correction), [...$payload, ...$context])
            ->assertRedirect($url)->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Korekta została zamknięta. Można teraz wystawić kolejną korektę do tej samej faktury.');
        $closed = $correction->fresh();
        $this->assertTrue($closed->isFinalized());
        $this->assertSame(Arr::except($before, ['finalized_at', 'updated_at']), Arr::except($closed->getAttributes(), ['finalized_at', 'updated_at']));
        $this->assertSame($rootBefore, $root->fresh()->getAttributes());
        $this->assertSame($items, $closed->items->map->getAttributes()->all());
        $this->assertSame($counters, InvoiceNumberCounter::query()->get()->toArray());
        $this->assertSame($events, $root->order->events()->count());
        $this->assertDatabaseMissing('order_document_slots', ['invoice_id' => $correction->id]);
        $this->assertDatabaseHas('ksef_invoice_provenances', ['invoice_id' => $correction->id,
            'environment' => $environment->value, 'provenance' => 'outside_ksef']);
        $this->assertSame(1, $dispatched);
        $closedAttributes = $closed->getAttributes();

        $this->post(route('invoices.corrections.finalize', $correction), [...$payload, ...$context])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame(1, $dispatched);
        $this->assertSame($closedAttributes, $correction->fresh()->getAttributes());
        $this->assertSame(1, $correction->ksefProvenances()->count());
    }

    public static function environments(): array
    {
        return array_map(fn ($environment) => [$environment], KsefEnvironment::cases());
    }

    public function test_chain_is_blocked_before_close_and_next_uses_after_items_and_buyer(): void
    {
        [$root, $first] = $this->scenario();
        $this->expectDomainError('correction_already_exists', fn () => $this->issueKsefFinancialCorrection($root, 3));
        $this->assertDatabaseCount('invoices', 2);
        $this->get(route('invoices.corrections.create', $root))->assertRedirect(route('invoices.corrections.edit', $first));
        $closed = $this->close($first);
        $second = $this->issueKsefBuyerCorrection($root, ['name' => 'Buyer after second correction']);
        $this->assertSame($first->id, $second->previous_correction_id);
        $this->assertSame($root->id, $second->corrected_invoice_id);
        $product = $second->items()->where('line_type', 'product')->sole();
        $this->assertSame('2.0000', $product->correction_before_snapshot['quantity']);
        $this->assertSame($first->buyer_snapshot, data_get($second->order_snapshot, 'correction.buyer_before'));
        $this->assertSame($closed->finalized_at->toISOString(), $first->fresh()->finalized_at->toISOString());

        $this->close($first);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $second->id]);
        $this->close($second);
        $third = $this->issueKsefFinancialCorrection($root, 3);
        $this->assertSame($second->id, $third->previous_correction_id);
        $this->assertSame('Buyer after second correction', data_get($third->order_snapshot, 'correction.buyer_before.name'));
    }

    public function test_closed_correction_cannot_be_edited_or_deleted(): void
    {
        [, $correction] = $this->scenario();
        $this->close($correction);
        $this->expectDomainError('correction_finalized', fn () => app(CorrectionService::class)->update($correction, ['expected_lock_version' => 1]));
        $this->expectDomainError('correction_finalized', fn () => app(InvoiceDeletionService::class)->delete($correction, 1, $this->documentContext()));
        $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertSee('data-finalized-correction-notice', false)
            ->assertDontSee('data-bs-target="#correctionDeleteModal"', false);
        $this->assertDatabaseHas('invoices', ['id' => $correction->id]);
    }

    public function test_predecessor_requires_its_own_explicit_outside_evidence(): void
    {
        [$root, $first] = $this->scenario();
        app(InvoiceFinalizationService::class)->finalize($first);
        $second = $this->issueKsefFinancialCorrection($root, 3);
        $this->expectDomainError('correction_manual_finalization_outside_ksef_unconfirmed', fn () => $this->close($second));
        $this->assertNull($second->fresh()->finalized_at);
        $this->assertDatabaseMissing('ksef_invoice_provenances', ['invoice_id' => $first->id]);
    }

    #[DataProvider('blockedScenarios')]
    public function test_policy_fails_closed_on_missing_evidence_enabled_series_and_invalid_chain(string $reason): void
    {
        [$root, $correction] = $this->scenario();
        match ($reason) {
            'root_not_finalized' => $root->fresh()->forceFill(['finalized_at' => null])->saveQuietly(),
            'root_series' => $this->enableSeries($root),
            'correction_series' => $this->enableSeries($correction),
            'inactive_integration' => $this->enableSeriesWithInactiveIntegration($correction),
            'missing_slot' => OrderDocumentSlot::query()->where('invoice_id', $correction->id)->delete(),
            'wrong_slot' => OrderDocumentSlot::query()->where('invoice_id', $correction->id)->update(['invoice_id' => $root->id]),
            'cycle' => $correction->forceFill(['previous_correction_id' => $correction->id])->saveQuietly(),
            'draft' => $correction->forceFill(['status' => InvoiceDocumentStatus::Draft])->saveQuietly(),
            'wrong_type' => $correction->forceFill(['document_type' => InvoiceDocumentType::Proforma])->saveQuietly(),
            'missing_number' => $correction->forceFill(['number' => ''])->saveQuietly(),
        };
        $this->assertNull(app(ManualCorrectionFinalizationPolicy::class)->availableEnvironment($correction->fresh()));
        $this->post(route('invoices.corrections.finalize', $correction), $this->closePayload($correction))
            ->assertRedirect()->assertSessionHasErrors('correction');
        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseMissing('ksef_invoice_provenances', ['invoice_id' => $correction->id]);
    }

    public static function blockedScenarios(): array
    {
        return array_map(fn ($reason) => [$reason], ['root_not_finalized',
            'root_series', 'correction_series', 'inactive_integration', 'missing_slot', 'wrong_slot', 'cycle', 'draft', 'wrong_type', 'missing_number']);
    }

    #[DataProvider('historyScenarios')]
    public function test_any_online_history_on_root_target_or_predecessor_blocks_close(string $role, KsefInvoiceSubmissionStatus $status, KsefEnvironment $environment): void
    {
        [$root, $correction] = $this->scenario();
        $document = $role === 'root' ? $root : $correction;
        if ($role === 'previous') {
            $this->close($correction);
            $document = $correction;
            $correction = $this->issueKsefFinancialCorrection($root, 3);
        }
        $this->acceptKsefDocument($document, $environment)->forceFill(['status' => $status])->save();
        $this->expectDomainError('correction_manual_finalization_ksef_history_exists', fn () => $this->close($correction));
        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $correction->id]);
    }

    public static function historyScenarios(): array
    {
        $cases = [];
        foreach (KsefInvoiceSubmissionStatus::cases() as $status) {
            $cases[] = ['target', $status, KsefEnvironment::Demo];
        }
        foreach (['root', 'previous', 'target'] as $role) {
            $cases[] = [$role, KsefInvoiceSubmissionStatus::Accepted, KsefEnvironment::Production];
        }

        return $cases;
    }

    #[DataProvider('offlineRoles')]
    public function test_offline_history_anywhere_in_chain_blocks_close(string $role): void
    {
        [$root, $correction] = $this->scenario();
        $document = $role === 'root' ? $root : $correction;
        if ($role === 'previous') {
            $this->close($correction);
            $document = $correction;
            $correction = $this->issueKsefFinancialCorrection($root, 3);
        }
        $this->offline($document);
        $this->expectDomainError('correction_manual_finalization_offline_history_exists', fn () => $this->close($correction));
        $this->assertNull($correction->fresh()->finalized_at);
    }

    public static function offlineRoles(): array
    {
        return [['root'], ['previous'], ['target']];
    }

    public function test_own_confirmation_is_mandatory_and_lock_version_must_match(): void
    {
        [, $correction] = $this->scenario();
        $url = route('invoices.corrections.finalize', $correction);
        $payload = $this->closePayload($correction);
        $this->post($url, Arr::except($payload, ['confirm_outside_ksef']))->assertSessionHasErrors('confirm_outside_ksef');
        $this->post($url, [...$payload, 'confirm_outside_ksef' => '0'])->assertSessionHasErrors('confirm_outside_ksef');
        $this->post($url, Arr::except($payload, ['expected_lock_version']))->assertSessionHasErrors('expected_lock_version');
        $this->post($url, [...$payload, 'expected_lock_version' => 0])->assertSessionHasErrors('expected_lock_version');
        $this->expectDomainError('correction_manual_finalization_confirmation_required', fn () => app(ManualCorrectionFinalizationService::class)->finalize($correction, 1, KsefEnvironment::Demo, false));
        $this->expectDomainError('correction_manual_finalization_conflict', fn () => app(ManualCorrectionFinalizationService::class)->finalize($correction, 2, KsefEnvironment::Demo, true));
        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $correction->id]);
    }

    public function test_csrf_and_route_method_match_existing_web_protection(): void
    {
        [, $correction] = $this->scenario();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post(route('invoices.corrections.finalize', $correction), $this->closePayload($correction))->assertStatus(419);
        $this->get(route('invoices.corrections.finalize', $correction))->assertStatus(405);
        $this->assertNull($correction->fresh()->finalized_at);
    }

    public function test_backend_rechecks_series_after_the_confirmation_form_was_opened(): void
    {
        [, $correction] = $this->scenario();
        $this->get(route('invoices.corrections.edit', $correction))->assertOk()->assertSee('data-correction-finalize-trigger', false);
        $this->enableSeries($correction);
        $this->post(route('invoices.corrections.finalize', $correction), $this->closePayload($correction))
            ->assertSessionHasErrors('correction');
        $this->assertNull($correction->fresh()->finalized_at);
        $this->get(route('invoices.corrections.edit', $correction))->assertOk()->assertDontSee('data-correction-finalize-trigger', false);
    }

    public function test_actual_edit_after_opening_form_causes_conflict_and_preserves_new_content(): void
    {
        [, $correction] = $this->scenario();
        $payload = $this->ksefCorrectionPayload();
        $payload['change_buyer'] = true;
        $payload['buyer'] = array_replace($correction->buyer_snapshot, ['name' => 'Buyer edited after opening confirmation']);
        $payload['expected_lock_version'] = $correction->lock_version;
        $updated = app(CorrectionService::class)->update($correction, $payload);
        $before = $updated->getAttributes();
        $this->expectDomainError('correction_manual_finalization_conflict', fn () => $this->close($correction));
        $this->assertSame($before, $updated->fresh()->getAttributes());
    }

    public function test_environment_change_requires_a_new_confirmation(): void
    {
        [$root, $correction] = $this->scenario();
        $this->markKsefOutside($root, KsefEnvironment::Test);
        $this->ksefSettings(KsefEnvironment::Test);
        $this->expectDomainError('correction_manual_finalization_environment_changed', fn () => $this->close($correction));
        $this->assertNull($correction->fresh()->finalized_at);
    }

    public function test_interleaved_duplicate_requests_reload_locked_state_and_dispatch_once(): void
    {
        [, $correction] = $this->scenario();
        $dispatched = 0;
        Event::listen(CorrectionFinalized::class, function () use (&$dispatched): void {
            $dispatched++;
        });
        $armed = true;
        $winner = null;
        DB::listen(function ($query) use (&$armed, &$winner, $correction): void {
            if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "orders"')) {
                $armed = false;
                $winner = $this->close($correction);
            }
        });
        $second = $this->close($correction);
        $this->assertNotNull($winner);
        $this->assertSame($winner->finalized_at->toISOString(), $second->finalized_at->toISOString());
        $this->assertSame(1, $dispatched);
        $this->assertSame(1, $correction->ksefProvenances()->count());
        $this->assertDatabaseMissing('order_document_slots', ['invoice_id' => $correction->id]);
    }

    public function test_provenance_failure_rolls_back_finalization_slot_and_after_commit_event(): void
    {
        [, $correction] = $this->scenario();
        $dispatched = 0;
        Event::listen(CorrectionFinalized::class, function () use (&$dispatched): void {
            $dispatched++;
        });
        KsefInvoiceProvenance::creating(function ($provenance) use ($correction): void {
            if ($provenance->invoice_id === $correction->id) {
                throw new RuntimeException('Simulated provenance storage failure');
            }
        });
        try {
            $this->close($correction);
            $this->fail('Storage failure must roll back closure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated provenance storage failure', $exception->getMessage());
        }
        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $correction->id]);
        $this->assertDatabaseMissing('ksef_invoice_provenances', ['invoice_id' => $correction->id]);
        $this->assertSame(0, $dispatched);
    }

    public function test_return_link_context_and_finalization_history_are_preserved_once(): void
    {
        [, $correction] = $this->scenario();
        $return = OrderReturn::query()->create(['order_id' => $correction->order_id, 'source' => 'manual',
            'creation_method' => 'manual', 'currency' => 'PLN', 'total_value' => '123.00', 'submitted_at' => now()]);
        $correction->forceFill(['order_return_id' => $return->id,
            'order_snapshot' => array_replace($correction->order_snapshot, ['return_correction' => ['return_id' => $return->id]])])->saveQuietly();
        $context = ['return_to' => 'order_return', 'return_query' => 'order_return_id='.$return->id];
        $this->post(route('invoices.corrections.finalize', $correction), [...$this->closePayload($correction), ...$context])
            ->assertRedirect(route('invoices.corrections.edit', ['correction' => $correction, ...$context]))->assertSessionHasNoErrors();
        $this->close($correction);
        $this->assertSame(1, $return->events()->where('type', 'correction_finalized')->count());
        $this->assertSame($return->id, $correction->fresh()->order_return_id);
    }

    public function test_mixed_chain_keeps_conservative_previous_ksef_reference_block(): void
    {
        [$root, $first] = $this->scenario();
        $this->close($first);
        $second = $this->issueKsefFinancialCorrection($root, 3);
        $this->enableSeries($second);
        $this->expectDomainError('correction_manual_finalization_ksef_series_enabled', fn () => $this->close($second));
        $this->expectDomainError('ksef_fa3_correction_previous_ksef_not_accepted', fn () => app(KsefFa3CorrectionSourceReferenceResolver::class)->resolve($second, KsefEnvironment::Demo));
        $this->expectDomainError('ksef_fa3_correction_previous_ksef_not_accepted', fn () => app(InvoiceFinalizationService::class)->finalize($second));
        $this->assertNull($second->fresh()->finalized_at);
        $this->assertSame($first->id, $second->previous_correction_id);
    }

    public function test_own_outside_marker_blocks_later_online_send_and_both_send_controls(): void
    {
        [, $correction] = $this->scenario();
        $closed = $this->close($correction);
        $this->enableSeries($correction);
        config()->set('ksef.invoice_submission_enabled', true);
        try {
            app(KsefInvoiceSubmissionService::class)->prepareCorrection($closed, KsefEnvironment::Demo);
            $this->fail('Explicit OutsideKsef correction cannot be transmitted.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_submission_blocked_by_outside_ksef_provenance', $exception->safeCode);
        }
        try {
            app(KsefOfflineIssuanceService::class)->issueCorrectionOffline24($closed);
            $this->fail('Explicit OutsideKsef correction cannot be issued Offline.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_offline24_outside_ksef_provenance_exists', $exception->safeCode);
        }
        $this->get(route('invoices.corrections.edit', $closed))->assertOk()
            ->assertViewHas('ksefCanCreateAttempt', false)
            ->assertDontSee(' data-ksef-correction-send-form', false)
            ->assertDontSee('data-correction-finalize-trigger', false);
        $this->get(route('orders.show', $closed->order_id))->assertOk()
            ->assertDontSee('action="'.route('invoices.ksef.submissions.first-attempt', $closed).'"', false);
        $this->assertDatabaseMissing('ksef_invoice_submissions', ['invoice_id' => $closed->id]);
    }

    public function test_unclassified_root_shows_two_independent_confirmations_without_changing_documents(): void
    {
        [$root, $correction] = $this->unclassifiedScenario();
        $before = [$root->getAttributes(), $correction->getAttributes()];
        $response = $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertSee('data-correction-finalize-trigger', false)
            ->assertSee('name="confirm_source_outside_ksef" value="1"', false)
            ->assertSee('name="confirm_outside_ksef" value="1"', false)
            ->assertSee('name="expected_source_invoice_id" value="'.$root->id.'"', false)
            ->assertSee('name="expected_source_invoice_lock_version" value="'.$root->lock_version.'"', false)
            ->assertSee('Faktura pierwotna '.$root->number.' zostanie również trwale zamknięta.');
        $this->assertTrue($response->viewData('manualFinalization')['rootConfirmationRequired']);
        $this->assertSame($before, [$root->fresh()->getAttributes(), $correction->fresh()->getAttributes()]);
        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
        $this->get(route('invoices.edit', $root))->assertOk()->assertSee('Jeśli chcesz edytować tę fakturę, usuń fakturę korygującą');
        $this->expectDomainError('correction_manual_finalization_outside_ksef_unconfirmed', fn () => app(ManualCorrectionFinalizationPolicy::class)->assertAllowed($correction));
    }

    public function test_root_with_current_outside_evidence_needs_no_repeat_source_confirmation(): void
    {
        [$root, $correction] = $this->scenario();
        $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertDontSee('name="confirm_source_outside_ksef"', false)
            ->assertSee('name="confirm_outside_ksef"', false);
        $before = $root->fresh()->getAttributes();
        $this->post(route('invoices.corrections.finalize', $correction), $this->closePayload($correction))->assertSessionHasNoErrors();
        $this->assertSame($before, $root->fresh()->getAttributes());
    }

    #[DataProvider('pairScenarios')]
    public function test_both_documents_close_atomically_only_after_separate_declarations(string $state, KsefEnvironment $environment): void
    {
        [$root, $correction] = $this->unclassifiedScenario($environment);
        if ($state === 'closed') {
            app(InvoiceFinalizationService::class)->finalize($root);
        } elseif ($state === 'other_environment') {
            $this->markKsefOutside($root, $environment === KsefEnvironment::Test ? KsefEnvironment::Demo : KsefEnvironment::Test);
        }
        $rootBefore = $root->fresh()->getAttributes();
        $correctionBefore = $correction->getAttributes();
        $rootItems = $root->items()->get()->map->getAttributes()->all();
        $correctionItems = $correction->items()->get()->map->getAttributes()->all();
        $counters = InvoiceNumberCounter::query()->get()->toArray();
        $eventCount = $root->order->events()->count();
        $dispatched = 0;
        Event::listen(CorrectionFinalized::class, function ($event) use (&$dispatched, $root, $environment): void {
            $dispatched++;
            $this->assertTrue($root->fresh()->isFinalized());
            $this->assertDatabaseHas('ksef_invoice_provenances', ['invoice_id' => $root->id, 'environment' => $environment->value]);
            $this->assertDatabaseHas('ksef_invoice_provenances', ['invoice_id' => $event->invoice->id, 'environment' => $environment->value]);
        });
        $payload = [...$this->closePayload($correction, $environment), 'confirm_source_outside_ksef' => '1', 'return_to' => 'order'];
        $url = route('invoices.corrections.edit', ['correction' => $correction, 'return_to' => 'order']);
        $this->post(route('invoices.corrections.finalize', $correction), $payload)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertTrue($root->fresh()->isFinalized());
        $this->assertTrue($correction->fresh()->isFinalized());
        $this->assertSame(Arr::except($rootBefore, ['finalized_at', 'updated_at']), Arr::except($root->fresh()->getAttributes(), ['finalized_at', 'updated_at']));
        $this->assertSame(Arr::except($correctionBefore, ['finalized_at', 'updated_at']), Arr::except($correction->fresh()->getAttributes(), ['finalized_at', 'updated_at']));
        if ($rootBefore['finalized_at'] !== null) {
            $this->assertSame($rootBefore['finalized_at'], $root->fresh()->getAttributes()['finalized_at']);
        }
        $this->assertSame($rootItems, $root->items()->get()->map->getAttributes()->all());
        $this->assertSame($correctionItems, $correction->items()->get()->map->getAttributes()->all());
        $this->assertSame($counters, InvoiceNumberCounter::query()->get()->toArray());
        $this->assertSame($eventCount, $root->order->events()->count());
        $this->assertDatabaseHas('order_document_slots', ['document_type' => 'invoice', 'invoice_id' => $root->id]);
        $this->assertDatabaseMissing('order_document_slots', ['document_type' => 'correction', 'invoice_id' => $correction->id]);
        foreach ([$root, $correction] as $document) {
            $this->assertDatabaseHas('ksef_invoice_provenances', ['invoice_id' => $document->id, 'environment' => $environment->value, 'provenance' => 'outside_ksef']);
        }
        $receipt = [$root->fresh()->getAttributes(), $correction->fresh()->getAttributes(), KsefInvoiceProvenance::query()->get()->toArray()];
        $this->post(route('invoices.corrections.finalize', $correction), $payload)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame($receipt, [$root->fresh()->getAttributes(), $correction->fresh()->getAttributes(), KsefInvoiceProvenance::query()->get()->toArray()]);
        $this->assertSame(1, $dispatched);
    }

    public static function pairScenarios(): array
    {
        $cases = [];
        foreach (KsefEnvironment::cases() as $environment) {
            foreach (['open', 'closed', 'other_environment'] as $state) {
                $cases[] = [$state, $environment];
            }
        }

        return $cases;
    }

    #[DataProvider('missingPairFields')]
    public function test_missing_declarations_and_root_context_never_change_either_document(string $field): void
    {
        [$root, $correction] = $this->unclassifiedScenario();
        $payload = [...$this->closePayload($correction), 'confirm_source_outside_ksef' => '1'];
        unset($payload[$field]);
        $this->post(route('invoices.corrections.finalize', $correction), $payload)->assertSessionHasErrors();
        $this->assertNull($root->fresh()->finalized_at);
        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $correction->id]);
    }

    public static function missingPairFields(): array
    {
        return array_map(fn ($field) => [$field], ['confirm_source_outside_ksef', 'confirm_outside_ksef', 'expected_source_invoice_id', 'expected_source_invoice_lock_version']);
    }

    public function test_source_version_and_identity_are_checked_even_with_both_confirmations(): void
    {
        [$root, $correction] = $this->unclassifiedScenario();
        $service = app(ManualCorrectionFinalizationService::class);
        $this->expectDomainError('correction_manual_finalization_source_conflict', fn () => $service->finalize($correction, 1, KsefEnvironment::Demo, true, $root->id + 100, 1, true));
        $this->expectDomainError('correction_manual_finalization_source_conflict', fn () => $service->finalize($correction, 1, KsefEnvironment::Demo, true, $root->id, 2, true));
        $this->expectDomainError('correction_manual_finalization_source_conflict', fn () => $service->finalize($correction, 1, KsefEnvironment::Demo, true, confirmedSourceOutsideKsef: true));
        $payload = [...$this->closePayload($correction), 'confirm_source_outside_ksef' => '1'];
        $root->increment('lock_version');
        $this->post(route('invoices.corrections.finalize', $correction), $payload)->assertSessionHasErrors('correction');
        $this->assertNull($root->fresh()->finalized_at);
        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
    }

    public function test_closing_pair_removes_delete_hint_and_next_correction_uses_previous_after(): void
    {
        [$root, $first] = $this->unclassifiedScenario();
        $payload = [...$this->closePayload($first), 'confirm_source_outside_ksef' => '1'];
        $this->post(route('invoices.corrections.finalize', $first), $payload)->assertSessionHasNoErrors();
        $this->get(route('invoices.edit', $root))->assertOk()->assertSee('Dokument został zamknięty i nie może być edytowany.')
            ->assertDontSee('Jeśli chcesz edytować tę fakturę, usuń fakturę korygującą')->assertSee('Wystaw kolejną Korektę');
        $this->expectDomainError('invoice_finalized', fn () => app(InvoiceEditService::class)->updateBuyer($root->fresh(), ['expected_lock_version' => 1]));
        $this->expectDomainError('invoice_delete_blocked_by_ksef_provenance', fn () => app(InvoiceDeletionService::class)->delete($root, 1, $this->documentContext()));
        $second = $this->issueKsefFinancialCorrection($root, 3);
        $this->assertSame($first->id, $second->previous_correction_id);
        $this->assertSame('2.0000', $second->items()->where('line_type', 'product')->sole()->correction_before_snapshot['quantity']);
        $this->post(route('invoices.corrections.finalize', $first), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $second->id]);
    }

    public function test_deleting_an_open_correction_still_unlocks_unfinalized_root_without_outside_marker(): void
    {
        [$root, $correction] = $this->unclassifiedScenario();
        app(InvoiceDeletionService::class)->delete($correction, 1, $this->documentContext());
        app(InvoiceEditabilityPolicy::class)->assertEditable($root->fresh());
        $this->assertNull($root->fresh()->finalized_at);
        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
        $this->assertDatabaseMissing('invoices', ['id' => $correction->id]);
    }

    #[DataProvider('pairStorageFailures')]
    public function test_pair_storage_failure_rolls_back_both_finalizations_and_both_provenances(string $failure): void
    {
        [$root, $correction] = $this->unclassifiedScenario();
        $before = [$root->getAttributes(), $correction->getAttributes()];
        $dispatched = 0;
        Event::listen(CorrectionFinalized::class, function () use (&$dispatched): void {
            $dispatched++;
        });
        KsefInvoiceProvenance::creating(function ($provenance) use ($root, $correction, $failure): void {
            if ($provenance->invoice_id === ($failure === 'root' ? $root->id : $correction->id)) {
                throw new RuntimeException('Simulated pair provenance failure');
            }
        });
        try {
            app(ManualCorrectionFinalizationService::class)->finalize($correction, 1, KsefEnvironment::Demo, true, $root->id, 1, true);
            $this->fail('Both documents must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated pair provenance failure', $exception->getMessage());
        }
        $this->assertSame($before, [$root->fresh()->getAttributes(), $correction->fresh()->getAttributes()]);
        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $correction->id]);
        $this->assertSame(0, $dispatched);
    }

    public static function pairStorageFailures(): array
    {
        return [['root'], ['correction']];
    }

    #[DataProvider('latePairBlocks')]
    public function test_pair_rechecks_configuration_and_histories_before_touching_root(string $case): void
    {
        [$root, $correction] = $this->unclassifiedScenario();
        $payload = [...$this->closePayload($correction), 'confirm_source_outside_ksef' => '1'];
        match ($case) {
            'root_series' => $this->enableSeries($root),
            'correction_series' => $this->enableSeries($correction),
            'environment' => $this->ksefSettings(KsefEnvironment::Test),
            'root_online' => $this->acceptKsefDocument($root, KsefEnvironment::Production),
            'correction_online' => $this->acceptKsefDocument($correction, KsefEnvironment::Production),
            'root_offline' => $this->offline($root),
            'correction_offline' => $this->offline($correction),
        };
        $this->post(route('invoices.corrections.finalize', $correction), $payload)->assertSessionHasErrors('correction');
        $this->assertNull($root->fresh()->finalized_at);
        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
    }

    public static function latePairBlocks(): array
    {
        return array_map(fn ($case) => [$case], ['root_series', 'correction_series', 'environment', 'root_online', 'correction_online', 'root_offline', 'correction_offline']);
    }

    public function test_interleaved_pair_requests_close_root_and_correction_only_once(): void
    {
        [$root, $correction] = $this->unclassifiedScenario();
        $service = app(ManualCorrectionFinalizationService::class);
        $close = fn () => $service->finalize($correction, 1, KsefEnvironment::Demo, true, $root->id, 1, true);
        $dispatched = 0;
        Event::listen(CorrectionFinalized::class, function () use (&$dispatched): void {
            $dispatched++;
        });
        $armed = true;
        $winner = null;
        DB::listen(function ($query) use (&$armed, &$winner, $close): void {
            if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "orders"')) {
                $armed = false;
                $winner = $close();
            }
        });
        $result = $close();
        $this->assertNotNull($winner);
        $this->assertSame($winner->finalized_at->toISOString(), $result->finalized_at->toISOString());
        $this->assertTrue($root->fresh()->isFinalized());
        $this->assertSame(1, $dispatched);
        $this->assertDatabaseCount('ksef_invoice_provenances', 2);
    }

    private function unclassifiedScenario(KsefEnvironment $environment = KsefEnvironment::Demo): array
    {
        $this->ksefSettings($environment)->forceFill(['is_active' => true, 'context_nip' => '9876543210'])->save();
        $root = $this->issueKsefRoot();

        return [$root, $this->issueKsefFinancialCorrection($root)];
    }

    private function scenario(KsefEnvironment $environment = KsefEnvironment::Demo): array
    {
        $this->ksefSettings($environment)->forceFill(['is_active' => true, 'context_nip' => '9876543210'])->save();
        $root = $this->issueKsefRoot();
        $this->markKsefOutside($root, $environment);

        return [$root, $this->issueKsefFinancialCorrection($root)];
    }

    private function close(Invoice $correction): Invoice
    {
        return app(ManualCorrectionFinalizationService::class)->finalize($correction, $correction->lock_version, KsefEnvironment::Demo, true);
    }

    private function closePayload(Invoice $correction, KsefEnvironment $environment = KsefEnvironment::Demo): array
    {
        $root = $correction->correctedInvoice()->firstOrFail();

        return ['expected_lock_version' => $correction->lock_version, 'expected_ksef_environment' => $environment->value, 'confirm_outside_ksef' => '1',
            'expected_source_invoice_id' => $root->id, 'expected_source_invoice_lock_version' => $root->lock_version];
    }

    private function enableSeries(Invoice $document): void
    {
        KsefSeriesSetting::query()->updateOrCreate(['invoice_series_id' => $document->invoice_series_id], ['is_enabled' => true]);
    }

    private function enableSeriesWithInactiveIntegration(Invoice $document): void
    {
        $this->enableSeries($document);
        $this->ksefSettings(KsefEnvironment::Demo)->forceFill(['is_active' => false])->save();
    }

    private function expectDomainError(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }
    }

    private function offline(Invoice $document): void
    {
        KsefOfflineIssuance::query()->create([
            'invoice_id' => $document->id, 'environment' => KsefEnvironment::Production,
            'procedure' => KsefOfflineIssuanceProcedure::Offline24, 'issue_date' => '2026-08-21', 'issued_at' => now('UTC'),
            'seller_nip' => '9876543210', 'context_identifier_type' => 'Nip', 'context_identifier_value' => '9876543210',
            'schema_id' => 'FA (3) 1-0E', 'payload_xml' => '<Faktura/>', 'invoice_hash' => base64_encode(hash('sha256', '<Faktura/>', true)),
            'invoice_size' => 10, 'certificate_serial_number' => 'TEST', 'certificate_fingerprint_sha256' => str_repeat('A', 64),
            'certificate_valid_from' => '2026-01-01', 'certificate_valid_until' => '2027-01-01', 'certificate_remote_status' => 'Active',
            'certificate_remote_valid_from' => '2026-01-01', 'certificate_remote_valid_until' => '2027-01-01', 'certificate_remote_verified_at' => now('UTC'),
            'invoice_verification_url' => 'https://example.test/invoice', 'certificate_verification_url' => 'https://example.test/certificate',
        ]);
    }
}
