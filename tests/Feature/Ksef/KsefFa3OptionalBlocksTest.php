<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoicePaymentDueMode;
use Modules\Invoices\Enums\InvoicePaymentMethodSource;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3GeneratedDocument;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefFa3OptionalBlocksTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_all_supported_optional_blocks_are_mapped_from_document_snapshots_and_validate_against_xsd(): void
    {
        $this->configureOptions(array_fill_keys($this->optionKeys(), true));
        $invoice = $this->issueInvoice(
            order: [
                'external_id' => 'ORDER-3C-100',
                'billing_email' => 'buyer@example.test',
                'billing_phone' => '+48 500 000 000',
                'payment_status' => 'paid',
                'paid_amount' => '100.00',
                'paid_at' => '2026-07-21 23:30:00',
                'notes' => "Numer seryjny & <A>\nDruga linia",
            ],
            series: [
                'seller_bank_account' => 'PL61 1090 1014 0000 0712 1981 2874',
                'seller_bank_swift' => 'wbkp pl pp',
                'seller_bank_name' => 'Bank Testowy',
                'payment_due_mode' => InvoicePaymentDueMode::DaysFromIssue,
                'payment_due_days' => 14,
                'additional_information_template' => "Nagłówek\n[uwagi_sprzedawcy]",
            ],
        );
        $invoice->items()->firstOrFail()->update(['gtu_codes' => ['GTU_06']]);

        $this->assertSame(
            array_fill_keys($this->optionKeys(), true),
            data_get($invoice->tax_metadata_snapshot, 'ksef_document.options'),
        );

        $generated = $this->generate($invoice->fresh());
        $xpath = $this->xpath($generated->xml);

        $this->assertSame('buyer@example.test', $this->value($xpath, '//fa:Podmiot2/fa:DaneKontaktowe/fa:Email'));
        $this->assertSame('+48 500 000 000', $this->value($xpath, '//fa:Podmiot2/fa:DaneKontaktowe/fa:Telefon'));
        $this->assertSame('1', $this->value($xpath, '//fa:Podmiot3/fa:DaneIdentyfikacyjne/fa:BrakID'));
        $this->assertSame('Anna Nowak', $this->value($xpath, '//fa:Podmiot3/fa:DaneIdentyfikacyjne/fa:Nazwa'));
        $this->assertSame('1', $this->value($xpath, '//fa:Podmiot3/fa:RolaInna'));
        $this->assertSame('Odbiorca dostawy', $this->value($xpath, '//fa:Podmiot3/fa:OpisRoli'));
        $this->assertSame(0, $xpath->query('//fa:Podmiot3/fa:DaneKontaktowe')->length);
        $this->assertSame('GTU_06', $this->value($xpath, '//fa:FaWiersz/fa:GTU'));
        $this->assertSame('1', $this->value($xpath, '//fa:Platnosc/fa:Zaplacono'));
        $this->assertSame('2026-07-21', $this->value($xpath, '//fa:Platnosc/fa:DataZaplaty'));
        $this->assertSame('2026-08-11', $this->value($xpath, '//fa:Platnosc/fa:TerminPlatnosci/fa:Termin'));
        $this->assertSame('1', $this->value($xpath, '//fa:Platnosc/fa:PlatnoscInna'));
        $this->assertSame('Przelew', $this->value($xpath, '//fa:Platnosc/fa:OpisPlatnosci'));
        $this->assertSame('PL61109010140000071219812874', $this->value($xpath, '//fa:Platnosc/fa:RachunekBankowy/fa:NrRB'));
        $this->assertSame('WBKPPLPP', $this->value($xpath, '//fa:Platnosc/fa:RachunekBankowy/fa:SWIFT'));
        $this->assertSame('Bank Testowy', $this->value($xpath, '//fa:Platnosc/fa:RachunekBankowy/fa:NazwaBanku'));
        $this->assertSame('ORDER-3C-100', $this->value($xpath, '//fa:WarunkiTransakcji/fa:Zamowienia/fa:NrZamowienia'));
        $this->assertSame('2026-07-20', $this->value($xpath, '//fa:WarunkiTransakcji/fa:Zamowienia/fa:DataZamowienia'));
        $this->assertSame(
            ['Nagłówek', 'Numer seryjny & <A>', 'Druga linia'],
            $this->values($xpath, '//fa:DodatkowyOpis/fa:Wartosc'),
        );
        $this->assertStringContainsString('&amp;', $generated->xml);
        $this->assertStringContainsString('&lt;A&gt;', $generated->xml);
        Http::assertNothingSent();
    }

    public function test_all_include_options_false_omit_their_blocks_but_do_not_disable_payment_mapping(): void
    {
        $this->configureOptions(array_fill_keys($this->optionKeys(), false));
        $invoice = $this->issueInvoice();
        $invoice->items()->firstOrFail()->update(['gtu_codes' => ['GTU_06', 'GTU_07']]);

        $this->assertSame(
            array_fill_keys($this->optionKeys(), false),
            data_get($invoice->tax_metadata_snapshot, 'ksef_document.options'),
        );

        $xpath = $this->xpath($this->generate($invoice->fresh())->xml);

        $this->assertSame(0, $xpath->query('//fa:Podmiot3|//fa:DaneKontaktowe|//fa:GTU|//fa:DodatkowyOpis|//fa:WarunkiTransakcji|//fa:RachunekBankowy')->length);
        $this->assertSame('1', $this->value($xpath, '//fa:Platnosc/fa:PlatnoscInna'));
        $this->assertSame('Przelew', $this->value($xpath, '//fa:Platnosc/fa:OpisPlatnosci'));
    }

    public function test_legacy_invoice_without_document_snapshot_remains_core_only_and_is_not_backfilled(): void
    {
        $this->configureOptions(array_fill_keys($this->optionKeys(), true));
        $invoice = $this->issueInvoice();
        $metadata = $invoice->tax_metadata_snapshot;
        unset($metadata['ksef_document']);
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $before = $invoice->fresh()->getAttributes();

        $xpath = $this->xpath($this->generate($invoice->fresh())->xml);

        $this->assertSame(0, $xpath->query('//fa:Platnosc|//fa:RachunekBankowy|//fa:DaneKontaktowe|//fa:Podmiot3|//fa:GTU|//fa:DodatkowyOpis|//fa:WarunkiTransakcji')->length);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertArrayNotHasKey('ksef_document', $invoice->fresh()->tax_metadata_snapshot);
    }

    public function test_unknown_or_malformed_document_snapshot_is_rejected_without_guessing(): void
    {
        $invoice = $this->issueInvoice();
        $metadata = $invoice->tax_metadata_snapshot;
        $metadata['ksef_document']['version'] = 99;
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_document_snapshot_version_unsupported',
            fn () => $this->generate($invoice->fresh()),
        );

        $metadata['ksef_document'] = ['version' => 1, 'options' => ['include_gtu' => true]];
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_document_options_invalid',
            fn () => $this->generate($invoice->fresh()),
        );
    }

    public function test_payment_mapping_is_conservative_for_unmapped_cod_and_partial_payment(): void
    {
        $this->configureOptions(['include_bank_account' => false]);
        $invoice = $this->issueInvoice(order: [
            'payment_method' => '  PayNOW specjalny  ',
            'cash_on_delivery' => true,
            'payment_status' => 'unpaid',
            'paid_amount' => '50.00',
            'paid_at' => '2026-07-21 11:00:00',
        ]);
        $xpath = $this->xpath($this->generate($invoice)->xml);

        $this->assertSame('1', $this->value($xpath, '//fa:Platnosc/fa:PlatnoscInna'));
        $this->assertSame('Płatność przy odbiorze', $this->value($xpath, '//fa:Platnosc/fa:OpisPlatnosci'));
        $this->assertSame(0, $xpath->query('//fa:Platnosc/fa:FormaPlatnosci|//fa:Platnosc/fa:Zaplacono|//fa:Platnosc/fa:ZnacznikZaplatyCzesciowej|//fa:Platnosc/fa:ZaplataCzesciowa')->length);
    }

    public function test_full_payment_without_date_and_paid_status_alone_do_not_emit_paid_marker(): void
    {
        $this->configureOptions(['include_bank_account' => false]);
        $invoice = $this->issueInvoice(
            order: ['paid_amount' => '100.00', 'paid_at' => null, 'payment_status' => 'paid'],
            series: ['payment_method_source' => InvoicePaymentMethodSource::None],
        );
        $xpath = $this->xpath($this->generate($invoice)->xml);

        $this->assertSame(0, $xpath->query('//fa:Platnosc/fa:Zaplacono|//fa:Platnosc/fa:DataZaplaty')->length);
        $this->assertSame(0, $xpath->query('//fa:Platnosc')->length);
    }

    public function test_unpaid_zero_amount_does_not_emit_paid_marker_from_paid_at_or_zero_amount_due_alone(): void
    {
        $this->configureOptions(['include_bank_account' => false]);
        $invoice = $this->issueInvoice(
            order: [
                'payment_status' => 'unpaid',
                'paid_amount' => '0.00',
                'paid_at' => '2026-07-21 11:00:00',
            ],
            series: ['payment_method_source' => InvoicePaymentMethodSource::None],
        );
        $snapshot = $invoice->payment_snapshot;
        $snapshot['amount_due'] = '0.00';
        $invoice->forceFill(['payment_snapshot' => $snapshot])->saveQuietly();

        $xpath = $this->xpath($this->generate($invoice->fresh())->xml);

        $this->assertSame(0, $xpath->query('//fa:Platnosc/fa:Zaplacono|//fa:Platnosc/fa:DataZaplaty')->length);
        $this->assertSame(0, $xpath->query('//fa:Platnosc')->length);
    }

    public function test_contradictory_payment_status_and_amount_are_rejected_without_exposing_snapshot_data(): void
    {
        foreach ([
            ['paid', '50.00', '2026-07-21 11:00:00'],
            ['paid', '0.00', '2026-07-21 11:00:00'],
            ['unpaid', '100.00', '2026-07-21 11:00:00'],
            ['unpaid', '100.00', null],
            ['refunded', '100.00', '2026-07-21 11:00:00'],
        ] as [$status, $paidAmount, $paidAt]) {
            $invoice = $this->issueInvoice(order: ['billing_tax_id' => '5260250995']);
            $snapshot = $invoice->payment_snapshot;
            $snapshot['payment_status'] = $status;
            $snapshot['paid_amount'] = $paidAmount;
            $snapshot['paid_at'] = $paidAt;
            $invoice->forceFill(['payment_snapshot' => $snapshot])->saveQuietly();

            $exception = $this->expectDomainError(
                'ksef_fa3_payment_snapshot_invalid',
                fn () => $this->generate($invoice->fresh()),
            );

            $this->assertStringNotContainsString('5260250995', $exception->getMessage());
            $this->assertStringNotContainsString($paidAmount, $exception->getMessage());
        }
    }

    public function test_missing_or_unknown_payment_status_is_rejected(): void
    {
        foreach ([null, 'processing'] as $status) {
            $invoice = $this->issueInvoice(order: [
                'payment_status' => 'unpaid',
                'paid_amount' => '0.00',
                'paid_at' => null,
            ]);
            $snapshot = $invoice->payment_snapshot;
            if ($status === null) {
                unset($snapshot['payment_status']);
            } else {
                $snapshot['payment_status'] = $status;
            }
            $invoice->forceFill(['payment_snapshot' => $snapshot])->saveQuietly();

            $this->expectDomainError(
                'ksef_fa3_payment_snapshot_invalid',
                fn () => $this->generate($invoice->fresh()),
            );
        }
    }

    public function test_inconsistent_due_date_is_rejected_as_payment_snapshot_error(): void
    {
        $invoice = $this->issueInvoice(series: [
            'payment_due_mode' => InvoicePaymentDueMode::DaysFromIssue,
            'payment_due_days' => 14,
        ]);
        $invoice->forceFill(['payment_due_date' => '2026-08-12'])->saveQuietly();

        $this->expectDomainError(
            'ksef_fa3_payment_snapshot_invalid',
            fn () => $this->generate($invoice->fresh()),
        );
    }

    public function test_due_date_uses_exact_nullable_snapshot_equality(): void
    {
        $withoutDueDate = $this->issueInvoice();
        $withoutDueDateXpath = $this->xpath($this->generate($withoutDueDate)->xml);
        $this->assertSame(0, $withoutDueDateXpath->query('//fa:TerminPlatnosci')->length);

        $withDueDate = $this->issueInvoice(series: [
            'payment_due_mode' => InvoicePaymentDueMode::DaysFromIssue,
            'payment_due_days' => 14,
        ]);
        $withDueDateXpath = $this->xpath($this->generate($withDueDate)->xml);
        $this->assertSame(
            $withDueDate->payment_due_date->format('Y-m-d'),
            $this->value($withDueDateXpath, '//fa:TerminPlatnosci/fa:Termin'),
        );

        $snapshotOnly = $this->issueInvoice(series: [
            'payment_due_mode' => InvoicePaymentDueMode::DaysFromIssue,
            'payment_due_days' => 14,
        ]);
        $snapshotOnly->forceFill(['payment_due_date' => null])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_payment_snapshot_invalid',
            fn () => $this->generate($snapshotOnly->fresh()),
        );

        $invoiceOnly = $this->issueInvoice();
        $invoiceOnly->forceFill(['payment_due_date' => '2026-09-01'])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_payment_snapshot_invalid',
            fn () => $this->generate($invoiceOnly->fresh()),
        );
    }

    public function test_bank_and_contact_validation_only_apply_when_the_frozen_options_are_enabled(): void
    {
        $this->configureOptions([
            'include_bank_account' => false,
            'include_buyer_contact_data' => false,
        ]);
        $invoice = $this->issueInvoice(
            order: ['billing_email' => 'invalid-email'],
            series: ['seller_bank_account' => '123'],
        );
        $this->generate($invoice);

        $metadata = $invoice->tax_metadata_snapshot;
        $metadata['ksef_document']['options']['include_bank_account'] = true;
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->expectDomainError('ksef_fa3_bank_account_invalid', fn () => $this->generate($invoice->fresh()));

        $metadata['ksef_document']['options']['include_bank_account'] = false;
        $metadata['ksef_document']['options']['include_buyer_contact_data'] = true;
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->expectDomainError('ksef_fa3_buyer_contact_invalid', fn () => $this->generate($invoice->fresh()));
    }

    public function test_same_or_empty_recipient_is_omitted_and_incomplete_different_recipient_is_rejected(): void
    {
        $this->configureOptions(['include_recipient_data' => true]);
        $invoice = $this->issueInvoice();
        $buyer = $invoice->buyer_snapshot;
        unset($buyer['tax_id'], $buyer['tax_identity'], $buyer['subject_flags'], $buyer['email'], $buyer['phone']);
        $invoice->forceFill(['recipient_snapshot' => $buyer])->saveQuietly();
        $this->assertSame(0, $this->xpath($this->generate($invoice->fresh())->xml)->query('//fa:Podmiot3')->length);

        $invoice->forceFill(['recipient_snapshot' => []])->saveQuietly();
        $this->assertSame(0, $this->xpath($this->generate($invoice->fresh())->xml)->query('//fa:Podmiot3')->length);

        $invoice->forceFill(['recipient_snapshot' => ['name' => 'Inny odbiorca']])->saveQuietly();
        $this->expectDomainError('ksef_fa3_recipient_invalid', fn () => $this->generate($invoice->fresh()));
    }

    public function test_gtu_duplicates_map_once_while_multiple_and_invalid_codes_are_rejected(): void
    {
        $this->configureOptions(['include_gtu' => true]);
        $invoice = $this->issueInvoice();
        $item = $invoice->items()->firstOrFail();
        $item->update(['gtu_codes' => ['GTU_06', 'GTU_06']]);
        $xpath = $this->xpath($this->generate($invoice->fresh())->xml);
        $this->assertSame(['GTU_06'], $this->values($xpath, '//fa:FaWiersz/fa:GTU'));

        $item->update(['gtu_codes' => ['GTU_06', 'GTU_07']]);
        $this->expectDomainError('ksef_fa3_multiple_gtu_codes', fn () => $this->generate($invoice->fresh()));

        $item->update(['gtu_codes' => ['GTU_99']]);
        $this->expectDomainError('ksef_fa3_gtu_invalid', fn () => $this->generate($invoice->fresh()));
    }

    public function test_additional_information_is_normalized_and_chunked_without_unicode_truncation(): void
    {
        $this->configureOptions(['include_additional_information' => true]);
        $invoice = $this->issueInvoice();
        $invoice->forceFill([
            'additional_information_text' => "  Pierwsza\t  linia \r\n\r\n".str_repeat('ą', 300),
        ])->saveQuietly();
        $xpath = $this->xpath($this->generate($invoice->fresh())->xml);
        $values = $this->values($xpath, '//fa:DodatkowyOpis/fa:Wartosc');

        $this->assertSame('Pierwsza linia', $values[0]);
        $this->assertSame(256, mb_strlen($values[1], 'UTF-8'));
        $this->assertSame(44, mb_strlen($values[2], 'UTF-8'));
        $this->assertSame(
            ['Informacja dodatkowa 1', 'Informacja dodatkowa 2', 'Informacja dodatkowa 3'],
            $this->values($xpath, '//fa:DodatkowyOpis/fa:Klucz'),
        );
    }

    public function test_order_reference_uses_external_id_never_internal_database_id(): void
    {
        $this->configureOptions(['include_order_reference' => true]);
        $invoice = $this->issueInvoice(order: ['external_id' => null]);
        $xpath = $this->xpath($this->generate($invoice)->xml);

        $this->assertSame(0, $xpath->query('//fa:WarunkiTransakcji')->length);
        $this->assertStringNotContainsString((string) $invoice->order_id, $this->value($xpath, '//fa:NrZamowienia'));
    }

    public function test_generation_is_deterministic_and_ignores_live_settings_order_and_series_drift(): void
    {
        $this->configureOptions([
            'include_recipient_data' => true,
            'include_order_reference' => true,
            'include_bank_account' => true,
        ]);
        $invoice = $this->issueInvoice(series: [
            'seller_bank_account' => 'PL61109010140000071219812874',
        ]);
        $generatedAt = CarbonImmutable::parse('2026-08-14T12:00:00Z');
        $before = $this->generate($invoice, $generatedAt)->xml;

        $this->configureOptions(array_fill_keys($this->optionKeys(), false));
        $invoice->order()->update([
            'external_id' => 'LIVE-CHANGED',
            'shipping_name' => 'LIVE RECIPIENT',
        ]);
        $invoice->series()->update([
            'seller_bank_account' => 'INVALID LIVE BANK',
            'seller_bank_name' => 'LIVE BANK',
        ]);

        $this->assertSame($before, $this->generate($invoice->fresh(), $generatedAt)->xml);
    }

    private function issueInvoice(array $order = [], array $item = [], array $series = []): Invoice
    {
        $orderModel = $this->createDocumentOrder(array_merge([
            'external_id' => 'FA3-OPTIONAL-'.uniqid(),
            'billing_tax_id' => '5260250995',
            'total_gross' => '100.00',
            'delivery_cost_gross' => '0.00',
        ], $order));
        $this->createDocumentItem($orderModel, array_merge([
            'unit_price_gross' => '100.00',
            'total_price_gross' => '100.00',
        ], $item));

        return app(InvoiceIssuingService::class)->issue(
            $orderModel,
            $this->createDocumentSeries(InvoiceDocumentType::Invoice, array_merge([
                'include_shipping' => false,
            ], $series)),
            $this->documentContext(),
        )->refresh()->load('items');
    }

    /** @param array<string, bool> $overrides */
    private function configureOptions(array $overrides): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(array_replace(array_fill_keys($this->optionKeys(), false), $overrides))->save();
    }

    /** @return array<int, string> */
    private function optionKeys(): array
    {
        return [
            'include_recipient_data',
            'include_buyer_contact_data',
            'include_additional_information',
            'include_order_reference',
            'include_bank_account',
            'include_gtu',
            'include_seller_vat_prefix',
        ];
    }

    private function generate(
        Invoice $invoice,
        ?CarbonImmutable $generatedAt = null,
    ): KsefFa3GeneratedDocument {
        return app(KsefFa3DocumentGenerator::class)->generate(
            $invoice,
            $generatedAt ?? CarbonImmutable::parse('2026-08-14T12:00:00Z'),
            KsefFa3EligibilityMode::Preflight,
        );
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml, LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);

        return $xpath;
    }

    private function value(DOMXPath $xpath, string $expression): string
    {
        return trim((string) $xpath->evaluate('string('.$expression.')'));
    }

    /** @return array<int, string> */
    private function values(DOMXPath $xpath, string $expression): array
    {
        return array_map(
            static fn ($node): string => trim($node->textContent),
            iterator_to_array($xpath->query($expression)),
        );
    }

    private function expectDomainError(string $code, callable $operation): InvoiceDomainException
    {
        try {
            $operation();
            $this->fail('Oczekiwano kontrolowanego błędu domenowego '.$code.'.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());

            return $exception;
        }
    }
}
