<?php

namespace Tests\Unit\Ksef;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\KsefUserErrorPresenter;
use RuntimeException;
use Tests\TestCase;

class KsefUserErrorPresenterTest extends TestCase
{
    public function test_it_presents_missing_items_with_a_stable_code_and_actionable_detail(): void
    {
        $error = $this->presenter()->present(
            new InvoiceDomainException(
                'ksef_fa3_items_missing',
                'Faktura nie zawiera żadnych pozycji.',
                ['item_count' => 0],
            ),
            KsefUserErrorPresenter::OPERATION_SUBMIT_INVOICE,
        );

        $this->assertSame('Nie udało się przekazać Faktury do KSeF', $error['title']);
        $this->assertSame('Przygotowanie dokumentu FA(3)', $error['stage']);
        $this->assertSame('ksef_fa3_items_missing', $error['code']);
        $this->assertSame('Faktura nie zawiera żadnych pozycji.', $error['message']);
        $this->assertSame([
            'Aby przygotować dokument FA(3), Faktura musi zawierać co najmniej jedną pozycję.',
        ], $error['details']);
    }

    public function test_it_maps_only_known_seller_and_buyer_fields_to_user_labels(): void
    {
        $seller = $this->presenter()->present(
            new InvoiceDomainException(
                'ksef_fa3_seller_incomplete',
                'Snapshot sprzedawcy jest niekompletny.',
                [
                    'missing_fields' => ['seller.tax_id', 'seller.address', 'seller.secret'],
                    'invalid_fields' => ['seller.country_code'],
                ],
            ),
            KsefUserErrorPresenter::OPERATION_SUBMIT_INVOICE,
        );
        $buyer = $this->presenter()->present(
            new InvoiceDomainException(
                'ksef_fa3_buyer_incomplete',
                'Snapshot nabywcy jest niekompletny.',
                ['missing_fields' => ['buyer.name', 'buyer.address']],
            ),
            KsefUserErrorPresenter::OPERATION_SUBMIT_INVOICE,
        );

        $this->assertSame('Dane sprzedawcy', $seller['stage']);
        $this->assertSame([
            'Brakujące dane: NIP sprzedawcy',
            'Brakujące dane: adres sprzedawcy',
            'Nieprawidłowe dane: kraj sprzedawcy',
        ], $seller['details']);
        $this->assertStringNotContainsString('seller.secret', json_encode($seller, JSON_THROW_ON_ERROR));
        $this->assertSame('Dane nabywcy', $buyer['stage']);
        $this->assertSame([
            'Brakujące dane: nazwa nabywcy',
            'Brakujące dane: adres nabywcy',
        ], $buyer['details']);
    }

    public function test_it_presents_an_invoice_item_tax_problem_without_exposing_database_id(): void
    {
        $error = $this->presenter()->present(
            new InvoiceDomainException(
                'ksef_fa3_unsupported_vat_rate',
                'Faktura zawiera nieobsługiwaną stawkę VAT.',
                [
                    'invoice_item' => [
                        'id' => 9182,
                        'position' => 2,
                        'name' => 'Produkt testowy',
                    ],
                    'reason' => 'unsupported_vat_rate',
                    'vat_rate' => '17.00',
                ],
            ),
            KsefUserErrorPresenter::OPERATION_SUBMIT_INVOICE,
        );

        $this->assertSame('Weryfikacja danych podatkowych FA(3)', $error['stage']);
        $this->assertSame([
            'Problem dotyczy: Pozycja 2: Produkt testowy',
            'Powód: Stawka VAT tej pozycji nie jest obsługiwana przez aktualny profil FA(3).',
            'VAT: 17%',
        ], $error['details']);
        $this->assertStringNotContainsString('9182', json_encode($error, JSON_THROW_ON_ERROR));
    }

    public function test_it_presents_only_classified_ksef_api_diagnostics(): void
    {
        $error = $this->presenter()->present(
            new KsefApiException(
                'KSeF odrzucił Fakturę podczas weryfikacji.',
                'invoice_submit_rejected',
                400,
                '21170',
                systemWarning: 'SECRET_SYSTEM_WARNING',
            ),
            KsefUserErrorPresenter::OPERATION_SUBMIT_INVOICE,
        );
        $encoded = json_encode($error, JSON_THROW_ON_ERROR);

        $this->assertSame('Komunikacja z KSeF', $error['stage']);
        $this->assertSame('Kod NEX', $error['code_label']);
        $this->assertSame('invoice_submit_rejected', $error['code']);
        $this->assertSame(400, $error['http_status']);
        $this->assertSame('21170', $error['reason_code']);
        $this->assertStringNotContainsString('SECRET_SYSTEM_WARNING', $encoded);
    }

    public function test_it_never_exposes_an_unexpected_exception_message(): void
    {
        $error = $this->presenter()->present(
            new RuntimeException('SECRET_STACK_OR_DATABASE_DETAIL'),
            KsefUserErrorPresenter::OPERATION_SUBMIT_INVOICE,
        );
        $encoded = json_encode($error, JSON_THROW_ON_ERROR);

        $this->assertSame('ksef_operation_failed', $error['code']);
        $this->assertSame('Nie udało się wykonać operacji KSeF.', $error['message']);
        $this->assertStringNotContainsString('SECRET_STACK_OR_DATABASE_DETAIL', $encoded);
    }

    private function presenter(): KsefUserErrorPresenter
    {
        return new KsefUserErrorPresenter;
    }
}
