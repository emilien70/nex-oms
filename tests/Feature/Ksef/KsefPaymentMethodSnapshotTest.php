<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoicePaymentMethodSource;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Enums\KsefPaymentType;
use Modules\Ksef\Models\KsefPaymentMethodMapping;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Modules\Ksef\Services\KsefPaymentMethodMappingService;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3GeneratedDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefPaymentMethodSnapshotTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app(KsefSettingsService::class)->get()->forceFill([
            'include_bank_account' => false,
            'default_payment_type' => KsefPaymentType::Original,
        ])->save();
    }

    public function test_specific_mapping_is_frozen_and_only_new_invoice_uses_changed_mapping(): void
    {
        $this->mapping('Allegro Finance', KsefPaymentType::Transfer);
        $invoiceA = $this->issueInvoice(['payment_method' => 'Allegro Finance']);

        $this->assertSame([
            'version' => 1,
            'source_key' => 'allegro finance',
            'source_label' => 'Allegro Finance',
            'type' => 'transfer',
            'fa3_code' => '6',
        ], $invoiceA->payment_snapshot['ksef_payment']);

        KsefPaymentMethodMapping::query()->where('source_key', 'allegro finance')->update([
            'target_type' => KsefPaymentType::Card->value,
        ]);
        $invoiceA->order()->update(['payment_method' => 'Live order drift']);

        $this->assertSame('6', $this->paymentCode($invoiceA->fresh()));

        $invoiceB = $this->issueInvoice(['payment_method' => 'Allegro Finance']);
        $this->assertSame('2', $invoiceB->payment_snapshot['ksef_payment']['fa3_code']);
        $this->assertSame('2', $this->paymentCode($invoiceB));
        Http::assertNothingSent();
    }

    public function test_default_mapping_is_frozen_and_empty_source_does_not_invent_payment_form(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['default_payment_type' => KsefPaymentType::Transfer])->save();
        $invoiceA = $this->issueInvoice(['payment_method' => 'Provider XYZ']);

        $settings->forceFill(['default_payment_type' => KsefPaymentType::Card])->save();

        $this->assertSame('6', $this->paymentCode($invoiceA));
        $this->assertSame('2', $this->paymentCode($this->issueInvoice(['payment_method' => 'Provider XYZ'])));

        $withoutSource = $this->issueInvoice(
            ['payment_method' => null],
            ['payment_method_source' => InvoicePaymentMethodSource::None],
        );
        $this->assertSame([
            'version' => 1,
            'source_key' => null,
            'source_label' => null,
            'type' => null,
        ], $withoutSource->payment_snapshot['ksef_payment']);
        $xpath = $this->xpath($this->generate($withoutSource)->xml);
        $this->assertSame(0, $xpath->query('//fa:Platnosc/fa:FormaPlatnosci|//fa:Platnosc/fa:PlatnoscInna')->length);
    }

    public function test_cod_uses_its_reserved_source_and_is_not_automatically_cash(): void
    {
        $original = $this->issueInvoice([
            'payment_method' => 'Provider COD',
            'cash_on_delivery' => true,
        ]);

        $this->assertSame(KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY, data_get(
            $original->payment_snapshot,
            'ksef_payment.source_key',
        ));
        $xpath = $this->xpath($this->generate($original)->xml);
        $this->assertSame('1', $this->value($xpath, '//fa:Platnosc/fa:PlatnoscInna'));
        $this->assertSame(
            KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_LABEL,
            $this->value($xpath, '//fa:Platnosc/fa:OpisPlatnosci'),
        );
        $this->assertSame(0, $xpath->query('//fa:Platnosc/fa:FormaPlatnosci')->length);

        KsefPaymentMethodMapping::query()->create([
            'source_key' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY,
            'source_label' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_LABEL,
            'target_type' => KsefPaymentType::Cash,
        ]);
        $cash = $this->issueInvoice([
            'payment_method' => 'Provider COD',
            'cash_on_delivery' => true,
        ]);

        $this->assertSame('1', $this->paymentCode($cash));
        $this->assertSame(0, $this->xpath($this->generate($cash)->xml)->query('//fa:Platnosc/fa:PlatnoscInna')->length);
    }

    public function test_original_override_beats_direct_default_and_preserves_display_description(): void
    {
        app(KsefSettingsService::class)->get()->forceFill([
            'default_payment_type' => KsefPaymentType::Transfer,
        ])->save();
        $this->mapping('PayU', KsefPaymentType::Original);

        $invoice = $this->issueInvoice(['payment_method' => '  PayU  ']);
        $xpath = $this->xpath($this->generate($invoice)->xml);

        $this->assertSame('original', data_get($invoice->payment_snapshot, 'ksef_payment.type'));
        $this->assertSame('PayU', data_get($invoice->payment_snapshot, 'ksef_payment.description'));
        $this->assertSame('1', $this->value($xpath, '//fa:Platnosc/fa:PlatnoscInna'));
        $this->assertSame('PayU', $this->value($xpath, '//fa:Platnosc/fa:OpisPlatnosci'));
        $this->assertSame(0, $xpath->query('//fa:Platnosc/fa:FormaPlatnosci')->length);
    }

    #[DataProvider('directPaymentTypes')]
    public function test_each_direct_payment_type_uses_its_official_fa3_code(string $type, string $code): void
    {
        app(KsefSettingsService::class)->get()->forceFill([
            'default_payment_type' => KsefPaymentType::from($type),
        ])->save();

        $invoice = $this->issueInvoice(['payment_method' => 'Provider '.$type]);
        $xpath = $this->xpath($this->generate($invoice)->xml);

        $this->assertSame($code, $this->value($xpath, '//fa:Platnosc/fa:FormaPlatnosci'));
        $this->assertSame(0, $xpath->query('//fa:Platnosc/fa:PlatnoscInna|//fa:Platnosc/fa:OpisPlatnosci')->length);
    }

    public static function directPaymentTypes(): array
    {
        return [
            'cash' => ['cash', '1'],
            'card' => ['card', '2'],
            'voucher' => ['voucher', '3'],
            'cheque' => ['cheque', '4'],
            'credit' => ['credit', '5'],
            'transfer' => ['transfer', '6'],
            'mobile' => ['mobile', '7'],
        ];
    }

    public function test_explicit_payment_method_edit_re_resolves_mapping_while_unrelated_edit_preserves_it(): void
    {
        $this->mapping('Allegro Finance', KsefPaymentType::Transfer);
        $this->mapping('PayU', KsefPaymentType::Mobile);
        $invoice = $this->issueInvoice(['payment_method' => 'Allegro Finance']);

        $invoice = app(InvoiceEditService::class)->updateDetails(
            $invoice,
            $this->detailsPayload($invoice, ['payment_method' => 'PayU']),
        );
        $frozen = $invoice->payment_snapshot['ksef_payment'];

        $this->assertSame('mobile', $frozen['type']);
        $this->assertSame('7', $frozen['fa3_code']);

        KsefPaymentMethodMapping::query()->where('source_key', 'payu')->update([
            'target_type' => KsefPaymentType::Card->value,
        ]);
        $invoice = app(InvoiceEditService::class)->updateDetails(
            $invoice,
            $this->detailsPayload($invoice, ['additional_information_text' => 'Tylko tekst']),
        );

        $this->assertSame($frozen, $invoice->payment_snapshot['ksef_payment']);
        $this->assertSame('7', $this->paymentCode($invoice));
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_versioned_payment_mapping_is_rejected_without_silent_repair(array $mapping): void
    {
        $invoice = $this->issueInvoice(['payment_method' => 'Provider XYZ']);
        $payment = $invoice->payment_snapshot;
        $payment['ksef_payment'] = $mapping;
        $invoice->forceFill(['payment_snapshot' => $payment])->saveQuietly();

        $this->expectDomainError(
            'ksef_fa3_payment_mapping_invalid',
            fn () => $this->generate($invoice->fresh()),
        );
    }

    public static function invalidSnapshots(): array
    {
        return [
            'unknown version' => [[
                'version' => 99,
                'source_key' => 'provider xyz',
                'source_label' => 'Provider XYZ',
                'type' => 'transfer',
                'fa3_code' => '6',
            ]],
            'inconsistent direct code' => [[
                'version' => 1,
                'source_key' => 'provider xyz',
                'source_label' => 'Provider XYZ',
                'type' => 'transfer',
                'fa3_code' => '2',
            ]],
            'missing original description' => [[
                'version' => 1,
                'source_key' => 'provider xyz',
                'source_label' => 'Provider XYZ',
                'type' => 'original',
            ]],
            'description over xsd limit' => [[
                'version' => 1,
                'source_key' => 'provider xyz',
                'source_label' => 'Provider XYZ',
                'type' => 'original',
                'description' => 'Provider XYZ'.str_repeat('x', 256),
            ]],
        ];
    }

    public function test_legacy_invoice_without_mapping_snapshot_keeps_existing_interpretation_without_backfill(): void
    {
        $invoice = $this->issueInvoice(['payment_method' => 'Przelew']);
        $payment = $invoice->payment_snapshot;
        unset($payment['ksef_payment']);
        $invoice->forceFill(['payment_snapshot' => $payment])->saveQuietly();
        $before = $invoice->fresh()->getAttributes();

        app(KsefSettingsService::class)->get()->forceFill([
            'default_payment_type' => KsefPaymentType::Card,
        ])->save();
        $this->mapping('Przelew', KsefPaymentType::Mobile);

        $this->assertSame('6', $this->paymentCode($invoice->fresh()));
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertArrayNotHasKey('ksef_payment', $invoice->fresh()->payment_snapshot);
    }

    private function mapping(string $label, KsefPaymentType $type): KsefPaymentMethodMapping
    {
        $source = app(KsefPaymentMethodMappingService::class)->normalizeSource($label);

        return KsefPaymentMethodMapping::query()->create([
            'source_key' => $source['source_key'],
            'source_label' => $source['source_label'],
            'target_type' => $type,
        ]);
    }

    private function issueInvoice(array $order = [], array $series = []): Invoice
    {
        $orderModel = $this->createDocumentOrder(array_merge([
            'external_id' => 'FA3-PAYMENT-'.uniqid(),
            'billing_tax_id' => '5260250995',
            'delivery_cost_gross' => '0.00',
            'payment_status' => 'unpaid',
            'paid_amount' => '0.00',
            'paid_at' => null,
        ], $order));
        $this->createDocumentItem($orderModel, [
            'unit_price_gross' => '100.00',
            'total_price_gross' => '100.00',
        ]);

        return app(InvoiceIssuingService::class)->issue(
            $orderModel,
            $this->createDocumentSeries(InvoiceDocumentType::Invoice, array_merge([
                'include_shipping' => false,
            ], $series)),
            $this->documentContext(),
        )->refresh()->load('items');
    }

    /** @return array<string, mixed> */
    private function detailsPayload(Invoice $invoice, array $changes = []): array
    {
        $invoice = $invoice->fresh();
        $seller = $invoice->seller_snapshot ?? [];
        $issuer = $invoice->issuer_snapshot ?? [];
        $payment = $invoice->payment_snapshot ?? [];

        return array_merge([
            'expected_lock_version' => $invoice->lock_version,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'sale_date' => $invoice->sale_date?->toDateString(),
            'payment_due_date' => $invoice->payment_due_date?->toDateString(),
            'payment_method' => $payment['effective_payment_method'] ?? null,
            'payment_identifier' => $payment['payment_identifier'] ?? null,
            'paid_amount' => $invoice->paid_amount,
            'place_of_issue' => $issuer['place_of_issue'] ?? null,
            'issuer_name' => $issuer['issuer_name'] ?? null,
            'additional_information_text' => $invoice->additional_information_text,
            'seller_name' => $seller['name'] ?? null,
            'seller_tax_id' => $seller['tax_id'] ?? null,
            'seller_regon' => $seller['regon'] ?? null,
            'seller_bdo' => $seller['bdo'] ?? null,
            'seller_street' => $seller['street'] ?? null,
            'seller_building_number' => $seller['building_number'] ?? null,
            'seller_apartment_number' => $seller['apartment_number'] ?? null,
            'seller_postal_code' => $seller['postal_code'] ?? null,
            'seller_city' => $seller['city'] ?? null,
            'seller_province' => $seller['province'] ?? null,
            'seller_country_code' => $seller['country_code'] ?? null,
            'seller_email' => $seller['email'] ?? null,
            'seller_phone' => $seller['phone'] ?? null,
            'seller_bank_name' => $seller['bank_name'] ?? null,
            'seller_bank_account' => $seller['bank_account'] ?? null,
            'seller_bank_swift' => $seller['bank_swift'] ?? null,
        ], $changes);
    }

    private function paymentCode(Invoice $invoice): string
    {
        return $this->value($this->xpath($this->generate($invoice)->xml), '//fa:Platnosc/fa:FormaPlatnosci');
    }

    private function generate(Invoice $invoice): KsefFa3GeneratedDocument
    {
        return app(KsefFa3DocumentGenerator::class)->generate(
            $invoice,
            CarbonImmutable::parse('2026-08-14T12:00:00Z'),
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
