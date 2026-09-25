<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderVariableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Tests\TestCase;

class SettingsVariablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_variables_page_documents_order_variables_and_is_linked_in_settings(): void
    {
        $this->get(route('settings.variables.index'))
            ->assertOk()
            ->assertSee('Zmienne')
            ->assertSee('[uwagi_sprzedawcy]')
            ->assertSee('[data_zamowienia]')
            ->assertSee('[lista_przedmiotow]')
            ->assertSee('[linki_sledzenia]')
            ->assertSee('[numer_faktury]')
            ->assertSee('w temacie i tre&#347;ci wiadomo&#347;ci e-mail', false)
            ->assertSee(route('settings.variables.index'), false);
    }

    public function test_variables_can_be_rendered_as_plain_text_for_future_templates(): void
    {
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => 158.5,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'customer_email' => 'klient@example.com',
            'notes' => 'SN001',
        ]);

        $rendered = app(OrderVariableService::class)->render(
            'Zamowienie [id_zamowienia], [email_kupujacego], [uwagi_sprzedawcy]',
            $order,
        );

        $this->assertSame(
            'Zamowienie '.$order->id.', klient@example.com, SN001',
            $rendered,
        );
    }

    public function test_variables_render_email_ready_order_payment_delivery_and_buyer_data(): void
    {
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_PENDING,
            'currency' => 'PLN',
            'total_gross' => '158.50',
            'paid_amount' => '100.00',
            'delivery_cost_gross' => '12.99',
            'payment_status' => 'unpaid',
            'payment_method' => 'Przelew',
            'billing_name' => 'Jan Kowalski',
            'billing_company_name' => 'Kowalski Handel',
            'billing_street' => 'Fakturowa',
            'billing_building_number' => '5',
            'billing_apartment_number' => '2',
            'billing_postal_code' => '00-001',
            'billing_city' => 'Warszawa',
            'billing_country_code' => 'PL',
            'billing_email' => 'faktury@example.com',
            'shipping_country_code' => 'DE',
            'pickup_point_name' => 'Punkt BER01',
            'pickup_point_address' => 'Teststrasse 1',
            'pickup_point_postal_code' => '10115',
            'pickup_point_city' => 'Berlin',
        ]);

        $rendered = app(OrderVariableService::class)->render(
            implode('|', [
                '[imie_i_nazwisko_nabywcy]',
                '[firma_nabywcy]',
                '[adres_nabywcy]',
                '[kod_i_miasto_nabywcy]',
                '[kraj_nabywcy]',
                '[email_nabywcy]',
                '[kraj_dostawy]',
                '[punkt_odbioru]',
                '[status_platnosci]',
                '[sposob_platnosci]',
                '[kwota_zamowienia]',
                '[kwota_zaplacona]',
                '[kwota_do_zaplaty]',
                '[koszt_dostawy]',
            ]),
            $order,
        );

        $this->assertSame(
            'Jan Kowalski|Kowalski Handel|Fakturowa 5/2|00-001 Warszawa|Polska|faktury@example.com|Niemcy|Punkt BER01, Teststrasse 1, 10115 Berlin|Nieopłacone|Przelew|158.50|100.00|58.50|12.99',
            $rendered,
        );
    }

    public function test_variables_render_items_shipments_and_document_numbers(): void
    {
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_SHIPPED,
            'currency' => 'PLN',
            'total_gross' => '250.00',
            'paid_amount' => '250.00',
            'payment_status' => 'paid',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_name' => 'Produkt A',
            'quantity' => 2,
            'unit_price_gross' => '100.00',
            'total_price_gross' => '200.00',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_name' => 'Produkt B',
            'quantity' => 1,
            'unit_price_gross' => '50.00',
            'total_price_gross' => '50.00',
        ]);

        Shipment::query()->create([
            'order_id' => $order->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'tracking_number' => 'TRACK123',
            'service' => Shipment::SERVICE_INPOST_LOCKER_STANDARD,
            'status' => Shipment::STATUS_CONFIRMED,
            'oms_status' => Shipment::OMS_STATUS_DISPATCHED,
            'request_uuid' => (string) Str::uuid(),
        ]);

        $invoiceSeries = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Invoice->value)
            ->firstOrFail();
        $proformaSeries = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Proforma->value)
            ->firstOrFail();
        $correctionSeries = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction->value)
            ->firstOrFail();

        Invoice::query()->create([
            'order_id' => $order->id,
            'invoice_series_id' => $invoiceSeries->id,
            'document_type' => InvoiceDocumentType::Invoice,
            'status' => InvoiceDocumentStatus::Draft,
            'number' => 'ROBOCZA 1/2026',
        ]);
        Invoice::query()->create([
            'order_id' => $order->id,
            'invoice_series_id' => $invoiceSeries->id,
            'document_type' => InvoiceDocumentType::Invoice,
            'status' => InvoiceDocumentStatus::Issued,
            'number' => 'BL 7/2026',
        ]);
        Invoice::query()->create([
            'order_id' => $order->id,
            'invoice_series_id' => $proformaSeries->id,
            'document_type' => InvoiceDocumentType::Proforma,
            'status' => InvoiceDocumentStatus::Issued,
            'number' => 'BLPF 7/2026',
        ]);
        Invoice::query()->create([
            'order_id' => $order->id,
            'invoice_series_id' => $correctionSeries->id,
            'document_type' => InvoiceDocumentType::Correction,
            'status' => InvoiceDocumentStatus::Issued,
            'number' => 'BLK 1/2026',
        ]);

        $rendered = app(OrderVariableService::class)->render(
            '[lista_przedmiotow]|[liczba_przedmiotow]|[numery_przesylek]|[linki_sledzenia]|[statusy_przesylek]|[numer_faktury]|[numer_proformy]|[numery_korekt]',
            $order,
        );

        $this->assertSame(
            "2 x Produkt A\n1 x Produkt B|3|TRACK123|https://inpost.pl/sledzenie-przesylek?number=TRACK123|Przesyłka utworzona|BL 7/2026|BLPF 7/2026|BLK 1/2026",
            $rendered,
        );
    }

    public function test_missing_optional_email_data_is_replaced_with_empty_values(): void
    {
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '0.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
        ]);

        $rendered = app(OrderVariableService::class)->render(
            '[numer_faktury]|[linki_sledzenia]|[lista_przedmiotow]|[email_nabywcy]',
            $order,
        );

        $this->assertSame('|||', $rendered);
        $this->assertSame([], app(OrderVariableService::class)->unknownVariables(
            '[numer_faktury] [linki_sledzenia] [lista_przedmiotow]',
        ));
    }
}
