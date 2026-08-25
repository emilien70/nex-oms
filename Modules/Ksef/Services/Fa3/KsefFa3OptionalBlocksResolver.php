<?php

namespace Modules\Ksef\Services\Fa3;

use App\Support\CountryCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Ksef\Enums\KsefPaymentType;

class KsefFa3OptionalBlocksResolver
{
    private const VERSION_1_OPTION_KEYS = [
        'include_recipient_data',
        'include_buyer_contact_data',
        'include_additional_information',
        'include_order_reference',
        'include_bank_account',
        'include_gtu',
    ];

    public const OPTION_KEYS = [
        ...self::VERSION_1_OPTION_KEYS,
        'include_seller_vat_prefix',
    ];

    private const GTU_CODES = [
        'GTU_01', 'GTU_02', 'GTU_03', 'GTU_04', 'GTU_05', 'GTU_06', 'GTU_07',
        'GTU_08', 'GTU_09', 'GTU_10', 'GTU_11', 'GTU_12', 'GTU_13',
    ];

    private const LEGACY_PAYMENT_METHODS = [
        'cash' => '1',
        'gotowka' => '1',
        'gotówka' => '1',
        'card' => '2',
        'karta' => '2',
        'bon' => '3',
        'czek' => '4',
        'kredyt' => '5',
        'bank_transfer' => '6',
        'przelew' => '6',
        'transfer' => '6',
        'mobile' => '7',
        'mobilna' => '7',
    ];

    private const PAYMENT_STATUSES = [
        'unpaid',
        'paid',
        'refunded',
    ];

    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly CountryCatalog $countries,
    ) {}

    /**
     * @return array{
     *     legacy: bool,
     *     buyer_contacts: array<string, string>|null,
     *     recipient: array<string, mixed>|null,
     *     payment: array<string, mixed>|null,
     *     additional_descriptions: array<int, array{key: string, value: string}>,
     *     transaction_terms: array<string, string>|null,
     *     gtu_by_item_id: array<int, string>,
     *     include_seller_vat_prefix: bool|null
     * }
     */
    public function resolve(Invoice $invoice): array
    {
        $options = $this->options($invoice);
        if ($options === null) {
            return [
                'legacy' => true,
                'buyer_contacts' => null,
                'recipient' => null,
                'payment' => null,
                'additional_descriptions' => [],
                'transaction_terms' => null,
                'gtu_by_item_id' => [],
                'include_seller_vat_prefix' => null,
            ];
        }

        $items = $invoice->items()->orderBy('position')->orderBy('id')->get();

        return [
            'legacy' => false,
            'buyer_contacts' => $options['include_buyer_contact_data']
                ? $this->buyerContacts($invoice->buyer_snapshot ?? [])
                : null,
            'recipient' => $options['include_recipient_data']
                ? $this->recipient($invoice->recipient_snapshot ?? [], $invoice->buyer_snapshot ?? [])
                : null,
            'payment' => $this->payment($invoice, $options['include_bank_account']),
            'additional_descriptions' => $options['include_additional_information']
                ? $this->additionalDescriptions($invoice->additional_information_text)
                : [],
            'transaction_terms' => $options['include_order_reference']
                ? $this->transactionTerms($invoice->order_snapshot ?? [])
                : null,
            'gtu_by_item_id' => $options['include_gtu'] ? $this->gtuByItem($items) : [],
            'include_seller_vat_prefix' => $options['include_seller_vat_prefix'] ?? null,
        ];
    }

    /** @return array<string, bool>|null */
    private function options(Invoice $invoice): ?array
    {
        $metadata = $invoice->tax_metadata_snapshot ?? [];
        if (! array_key_exists('ksef_document', $metadata)) {
            return null;
        }

        $snapshot = $metadata['ksef_document'];
        if (! is_array($snapshot)) {
            throw $this->error(
                'ksef_fa3_document_options_invalid',
                'Snapshot opcji dokumentu KSeF jest niekompletny.',
            );
        }
        $version = $snapshot['version'] ?? null;
        if (! in_array($version, [1, 2], true)) {
            throw $this->error(
                'ksef_fa3_document_snapshot_version_unsupported',
                'Wersja snapshotu opcji dokumentu KSeF nie jest obsługiwana.',
            );
        }

        $options = $snapshot['options'] ?? null;
        if (! is_array($options)) {
            throw $this->error(
                'ksef_fa3_document_options_invalid',
                'Snapshot opcji dokumentu KSeF jest niekompletny.',
            );
        }

        $keys = array_keys($options);
        sort($keys, SORT_STRING);
        $expectedKeys = $version === 1 ? self::VERSION_1_OPTION_KEYS : self::OPTION_KEYS;
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw $this->error(
                'ksef_fa3_document_options_invalid',
                'Snapshot opcji dokumentu KSeF jest niekompletny.',
            );
        }

        foreach ($expectedKeys as $key) {
            if (! is_bool($options[$key])) {
                throw $this->error(
                    'ksef_fa3_document_options_invalid',
                    'Snapshot opcji dokumentu KSeF jest niekompletny.',
                );
            }
        }

        return $options;
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, string>|null
     */
    private function buyerContacts(array $snapshot): ?array
    {
        $email = $this->snapshotString($snapshot['email'] ?? null, 'ksef_fa3_buyer_contact_invalid');
        $phone = $this->snapshotString($snapshot['phone'] ?? null, 'ksef_fa3_buyer_contact_invalid');
        if ($email === null && $phone === null) {
            return null;
        }

        if ($email !== null
            && ($this->length($email) < 3 || $this->length($email) > 255 || preg_match('/^.+@.+$/u', $email) !== 1)) {
            throw $this->error(
                'ksef_fa3_buyer_contact_invalid',
                'Dane kontaktowe nabywcy są niezgodne z wymaganiami FA(3).',
            );
        }
        if ($phone !== null && $this->length($phone) > 16) {
            throw $this->error(
                'ksef_fa3_buyer_contact_invalid',
                'Dane kontaktowe nabywcy są niezgodne z wymaganiami FA(3).',
            );
        }

        return array_filter([
            'email' => $email,
            'phone' => $phone,
        ], static fn (?string $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $recipient
     * @param  array<string, mixed>  $buyer
     * @return array<string, mixed>|null
     */
    private function recipient(array $recipient, array $buyer): ?array
    {
        if (! $this->partyHasMeaningfulData($recipient) || $this->sameParty($recipient, $buyer)) {
            return null;
        }

        $name = $this->snapshotString($recipient['company_name'] ?? null, 'ksef_fa3_recipient_invalid')
            ?? $this->snapshotString($recipient['name'] ?? null, 'ksef_fa3_recipient_invalid');
        $country = $this->countries->normalize(
            $this->snapshotString($recipient['country_code'] ?? null, 'ksef_fa3_recipient_invalid'),
        );
        $address = $this->address($recipient);
        if ($name === null || $this->length($name) > 512
            || ! $this->countries->exists($country) || $address === null) {
            throw $this->error(
                'ksef_fa3_recipient_invalid',
                'Snapshot odbiorcy nie zawiera danych wymaganych dla Podmiot3 FA(3).',
            );
        }

        $address['country_code'] = $country;

        return [
            'name' => $name,
            'address' => $address,
            'role_description' => 'Odbiorca dostawy',
        ];
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function payment(Invoice $invoice, bool $includeBankAccount): ?array
    {
        $snapshot = $invoice->payment_snapshot;
        if (! is_array($snapshot)) {
            throw $this->paymentError();
        }

        $snapshotDueDate = $this->snapshotString(
            $snapshot['payment_due_date'] ?? null,
            'ksef_fa3_payment_snapshot_invalid',
        );
        if ($snapshotDueDate !== null && ! $this->isDate($snapshotDueDate)) {
            throw $this->paymentError();
        }
        $invoiceDueDate = $invoice->payment_due_date?->format('Y-m-d');
        if ($snapshotDueDate !== $invoiceDueDate) {
            throw $this->paymentError();
        }

        $paymentStatus = $this->snapshotString(
            $snapshot['payment_status'] ?? null,
            'ksef_fa3_payment_snapshot_invalid',
        );
        if (! in_array($paymentStatus, self::PAYMENT_STATUSES, true)) {
            throw $this->paymentError();
        }

        $paidAmount = $snapshot['paid_amount'] ?? null;
        if (! is_string($paidAmount) && ! is_int($paidAmount)) {
            throw $this->paymentError();
        }
        try {
            $paidAmount = $this->decimal->normalize($paidAmount, 2);
            $amountComparison = $this->decimal->compare($paidAmount, (string) $invoice->total_gross);
            $zeroComparison = $this->decimal->compare($paidAmount, '0.00');
        } catch (InvoiceDomainException $exception) {
            throw $this->paymentError($exception);
        }
        if ($zeroComparison < 0 || $amountComparison > 0) {
            throw $this->paymentError();
        }

        $paidAt = $this->snapshotString(
            $snapshot['paid_at'] ?? null,
            'ksef_fa3_payment_snapshot_invalid',
        );
        $paidDateCandidate = $paidAt !== null
            ? $this->calendarDate($paidAt, 'ksef_fa3_payment_snapshot_invalid')
            : null;

        $paidDate = null;
        if ($paymentStatus === 'paid') {
            if ($amountComparison !== 0) {
                throw $this->paymentError();
            }

            $paidDate = $paidDateCandidate;
        } elseif ($amountComparison === 0 && $zeroComparison > 0) {
            throw $this->paymentError();
        }

        [$methodCode, $methodDescription] = $this->paymentMethod($snapshot);

        $bank = $includeBankAccount ? $this->bankAccount($invoice->seller_snapshot ?? []) : null;
        if ($paidDate === null && $snapshotDueDate === null && $methodCode === null
            && $methodDescription === null && $bank === null) {
            return null;
        }

        return array_filter([
            'paid_date' => $paidDate,
            'due_date' => $snapshotDueDate,
            'method_code' => $methodCode,
            'method_description' => $methodDescription,
            'bank_account' => $bank,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $paymentSnapshot
     * @return array{0: ?string, 1: ?string}
     */
    private function paymentMethod(array $paymentSnapshot): array
    {
        if (! array_key_exists('ksef_payment', $paymentSnapshot)) {
            return $this->legacyPaymentMethod($paymentSnapshot);
        }

        $mapping = $paymentSnapshot['ksef_payment'];
        if (! is_array($mapping) || ($mapping['version'] ?? null) !== 1) {
            throw $this->paymentMappingError();
        }

        $sourceKey = $this->mappingString($mapping['source_key'] ?? null);
        $sourceLabel = $this->mappingString($mapping['source_label'] ?? null);
        $typeValue = $mapping['type'] ?? null;

        if ($typeValue === null) {
            if ($sourceKey !== null || $sourceLabel !== null
                || $this->mappingString($mapping['fa3_code'] ?? null) !== null
                || $this->mappingString($mapping['description'] ?? null) !== null) {
                throw $this->paymentMappingError();
            }

            return [null, null];
        }

        if (! is_string($typeValue) || $sourceKey === null || $sourceLabel === null) {
            throw $this->paymentMappingError();
        }

        $type = KsefPaymentType::tryFrom($typeValue);
        if ($type === null) {
            throw $this->paymentMappingError();
        }

        $code = $this->mappingString($mapping['fa3_code'] ?? null);
        $description = $this->mappingString($mapping['description'] ?? null);

        if ($type === KsefPaymentType::Original) {
            if ($code !== null || $description === null || $description !== $sourceLabel
                || $this->length($description) > 256) {
                throw $this->paymentMappingError();
            }

            return [null, $description];
        }

        if ($description !== null || $code !== $type->fa3Code()) {
            throw $this->paymentMappingError();
        }

        return [$code, null];
    }

    /**
     * @param  array<string, mixed>  $paymentSnapshot
     * @return array{0: ?string, 1: ?string}
     */
    private function legacyPaymentMethod(array $paymentSnapshot): array
    {
        $method = $this->snapshotString(
            $paymentSnapshot['effective_payment_method'] ?? null,
            'ksef_fa3_payment_method_invalid',
        );
        if ($method === null) {
            return [null, null];
        }

        $code = self::LEGACY_PAYMENT_METHODS[mb_strtolower($method, 'UTF-8')] ?? null;
        if ($code !== null) {
            return [$code, null];
        }
        if ($this->length($method) > 256) {
            throw $this->error(
                'ksef_fa3_payment_method_invalid',
                'Forma płatności nie mieści się w kontrakcie FA(3).',
            );
        }

        return [null, $method];
    }

    private function mappingString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            throw $this->paymentMappingError();
        }

        return trim($value);
    }

    /** @param array<string, mixed> $seller
     * @return array<string, string>|null
     */
    private function bankAccount(array $seller): ?array
    {
        $account = $this->snapshotString($seller['bank_account'] ?? null, 'ksef_fa3_bank_account_invalid');
        if ($account === null) {
            return null;
        }
        $account = preg_replace('/\s+/u', '', $account);
        if (! is_string($account) || $this->length($account) < 10 || $this->length($account) > 34) {
            throw $this->bankError();
        }

        $swift = $this->snapshotString($seller['bank_swift'] ?? null, 'ksef_fa3_bank_account_invalid');
        if ($swift !== null) {
            $swift = strtoupper((string) preg_replace('/\s+/u', '', $swift));
            if (preg_match('/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/', $swift) !== 1) {
                throw $this->bankError();
            }
        }
        $name = $this->snapshotString($seller['bank_name'] ?? null, 'ksef_fa3_bank_account_invalid');
        if ($name !== null && $this->length($name) > 256) {
            throw $this->bankError();
        }

        return array_filter([
            'number' => $account,
            'swift' => $swift,
            'name' => $name,
        ], static fn (?string $value): bool => $value !== null);
    }

    /** @return array<int, array{key: string, value: string}> */
    private function additionalDescriptions(mixed $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        if (! is_string($text)) {
            throw $this->error(
                'ksef_fa3_additional_information_invalid',
                'Informacje dodatkowe Faktury mają nieprawidłowy format.',
            );
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $values = [];
        foreach (explode("\n", $normalized) as $line) {
            $line = trim($line);
            $line = preg_replace('/\s+/u', ' ', $line);
            if (! is_string($line)) {
                throw $this->error(
                    'ksef_fa3_additional_information_invalid',
                    'Informacje dodatkowe Faktury mają nieprawidłowy format.',
                );
            }
            if ($line === '') {
                continue;
            }

            foreach (mb_str_split($line, 256, 'UTF-8') as $chunk) {
                $values[] = $chunk;
                if (count($values) > 10000) {
                    throw $this->error(
                        'ksef_fa3_additional_information_too_long',
                        'Informacje dodatkowe przekraczają limit elementów FA(3).',
                    );
                }
            }
        }

        return array_map(
            static fn (string $value, int $index): array => [
                'key' => 'Informacja dodatkowa '.($index + 1),
                'value' => $value,
            ],
            $values,
            array_keys($values),
        );
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, string>|null
     */
    private function transactionTerms(array $snapshot): ?array
    {
        $number = $this->snapshotString($snapshot['external_id'] ?? null, 'ksef_fa3_order_reference_invalid');
        if ($number === null) {
            return null;
        }
        if ($this->length($number) > 256) {
            throw $this->error(
                'ksef_fa3_order_reference_invalid',
                'Numer zamówienia nie mieści się w kontrakcie FA(3).',
            );
        }

        $purchasedAt = $this->snapshotString(
            $snapshot['purchased_at'] ?? null,
            'ksef_fa3_order_reference_invalid',
        );
        $date = $purchasedAt !== null
            ? $this->calendarDate($purchasedAt, 'ksef_fa3_order_reference_invalid')
            : null;

        return array_filter([
            'date' => $date,
            'number' => $number,
        ], static fn (?string $value): bool => $value !== null);
    }

    /** @param Collection<int, InvoiceItem> $items
     * @return array<int, string>
     */
    private function gtuByItem(Collection $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $codes = $item->gtu_codes;
            if ($codes === null) {
                $codes = [];
            }
            if (! is_array($codes)) {
                throw $this->gtuError();
            }

            $unique = [];
            foreach ($codes as $code) {
                if (! is_string($code) || ! in_array($code, self::GTU_CODES, true)) {
                    throw $this->gtuError();
                }
                $unique[$code] = true;
            }
            if (count($unique) > 1) {
                throw $this->error(
                    'ksef_fa3_multiple_gtu_codes',
                    'Pozycja Faktury zawiera więcej niż jedno oznaczenie GTU i nie może zostać odwzorowana w FA(3).',
                );
            }
            if ($unique !== []) {
                $result[(int) $item->getKey()] = (string) array_key_first($unique);
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $party */
    private function partyHasMeaningfulData(array $party): bool
    {
        foreach (['name', 'company_name', 'country_code', 'street', 'building_number', 'apartment_number', 'postal_code', 'city'] as $key) {
            if ($this->plainOptionalString($party[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $left
     * @param  array<string, mixed>  $right
     */
    private function sameParty(array $left, array $right): bool
    {
        return $this->comparableParty($left) === $this->comparableParty($right);
    }

    /** @param array<string, mixed> $party
     * @return array<int, ?string>
     */
    private function comparableParty(array $party): array
    {
        return [
            $this->plainOptionalString($party['company_name'] ?? null)
                ?? $this->plainOptionalString($party['name'] ?? null),
            $this->countries->normalize($this->plainOptionalString($party['country_code'] ?? null)),
            $this->plainOptionalString($party['street'] ?? null),
            $this->plainOptionalString($party['building_number'] ?? null),
            $this->plainOptionalString($party['apartment_number'] ?? null),
            $this->plainOptionalString($party['postal_code'] ?? null),
            $this->plainOptionalString($party['city'] ?? null),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, string>|null
     */
    private function address(array $snapshot): ?array
    {
        $street = $this->snapshotString($snapshot['street'] ?? null, 'ksef_fa3_recipient_invalid');
        $building = $this->snapshotString($snapshot['building_number'] ?? null, 'ksef_fa3_recipient_invalid');
        $apartment = $this->snapshotString($snapshot['apartment_number'] ?? null, 'ksef_fa3_recipient_invalid');
        $number = $building;
        if ($apartment !== null) {
            $number = $number !== null ? $number.'/'.$apartment : $apartment;
        }

        $line1 = trim(implode(' ', array_filter(
            [$street, $number],
            static fn (?string $value): bool => $value !== null,
        )));
        if ($line1 === '') {
            return null;
        }
        $line2 = trim(implode(' ', array_filter([
            $this->snapshotString($snapshot['postal_code'] ?? null, 'ksef_fa3_recipient_invalid'),
            $this->snapshotString($snapshot['city'] ?? null, 'ksef_fa3_recipient_invalid'),
        ], static fn (?string $value): bool => $value !== null)));

        if ($this->length($line1) > 512 || ($line2 !== '' && $this->length($line2) > 512)) {
            throw $this->error(
                'ksef_fa3_recipient_invalid',
                'Snapshot odbiorcy nie zawiera danych wymaganych dla Podmiot3 FA(3).',
            );
        }

        return array_filter([
            'line_1' => $line1,
            'line_2' => $line2 !== '' ? $line2 : null,
        ], static fn (?string $value): bool => $value !== null);
    }

    private function snapshotString(mixed $value, string $errorCode): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw $this->error($errorCode, 'Snapshot dokumentu zawiera nieprawidłową wartość tekstową.');
        }

        return $this->plainOptionalString($value);
    }

    private function plainOptionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function calendarDate(string $value, string $errorCode): string
    {
        try {
            return CarbonImmutable::createFromFormat(DATE_ATOM, $value)
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d');
        } catch (\Throwable $exception) {
            throw $this->error($errorCode, 'Snapshot dokumentu zawiera nieprawidłową datę.', $exception);
        }
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    private function paymentError(?\Throwable $previous = null): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_payment_snapshot_invalid',
            'Snapshot płatności Faktury jest niekompletny lub niespójny.',
            $previous,
        );
    }

    private function paymentMappingError(): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_payment_mapping_invalid',
            'Snapshot formy płatności Faktury jest niekompletny lub niespójny.',
        );
    }

    private function bankError(): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_bank_account_invalid',
            'Rachunek bankowy w snapshotcie sprzedawcy jest niezgodny z wymaganiami FA(3).',
        );
    }

    private function gtuError(): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_gtu_invalid',
            'Pozycja Faktury zawiera nieprawidłowe oznaczenie GTU.',
        );
    }

    private function error(
        string $code,
        string $message,
        ?\Throwable $previous = null,
    ): InvoiceDomainException {
        return new InvoiceDomainException($code, $message, [], $previous);
    }
}
