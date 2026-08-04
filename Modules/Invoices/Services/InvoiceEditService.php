<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use App\Support\CountryCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Models\InvoiceSeries;

class InvoiceEditService
{
    public function __construct(
        private readonly InvoiceEditabilityPolicy $policy,
        private readonly InvoicePdfStorage $pdfStorage,
        private readonly InvoiceTotalsCalculator $totals,
        private readonly InvoiceDecimalCalculator $decimals,
        private readonly InvoiceEditableItemsService $editableItems,
        private readonly InvoiceEditCurrencyConversionService $currencyConversion,
        private readonly InvoiceNumberingPeriodResolver $periods,
        private readonly InvoiceNumberFormatter $numbers,
        private readonly CountryCatalog $countries,
    ) {}

    /** @param array<string, mixed> $data */
    public function updateBuyer(Invoice $invoice, array $data): Invoice
    {
        return $this->mutate($invoice, (int) $data['expected_lock_version'], function (Invoice $managed) use ($data): bool {
            $snapshot = $this->addressSnapshot($data);
            $changed = $this->snapshotsDiffer($snapshot, $managed->buyer_snapshot ?? []);
            if ($changed) {
                $managed->buyer_snapshot = $snapshot;
                $managed->buyer_name_snapshot = $snapshot['company_name'] ?: $snapshot['name'];
                $managed->buyer_tax_id_snapshot = $snapshot['tax_id'];
                $managed->save();
            }

            return $changed;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateRecipient(Invoice $invoice, array $data): Invoice
    {
        return $this->mutate($invoice, (int) $data['expected_lock_version'], function (Invoice $managed) use ($data): bool {
            $snapshot = $this->addressSnapshot($data, false);
            $changed = $this->snapshotsDiffer($snapshot, $managed->recipient_snapshot ?? []);
            if ($changed) {
                $managed->recipient_snapshot = $snapshot;
                $managed->recipient_name_snapshot = $snapshot['company_name'] ?: $snapshot['name'];
                $managed->save();
            }

            return $changed;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDetails(Invoice $invoice, array $data): Invoice
    {
        $issueDate = (string) $data['issue_date'];
        $saleDate = (string) $data['sale_date'];
        $preparedRate = $this->currencyConversion->referenceDateChanges($invoice, $issueDate, $saleDate)
            ? $this->currencyConversion->prepareDateChange($invoice, $issueDate, $saleDate)
            : null;

        return $this->mutate($invoice, (int) $data['expected_lock_version'], function (Invoice $managed) use ($data, $preparedRate): bool {
            $this->assertNumberingDates($managed, (string) $data['issue_date']);
            $this->currencyConversion->assertSnapshotUsableForAnyEdit($managed);

            $seller = $this->sellerSnapshot($data);
            $issuer = [
                'place_of_issue' => $this->nullable($data['place_of_issue'] ?? null),
                'issuer_name' => $this->nullable($data['issuer_name'] ?? null),
            ];
            $payment = $managed->payment_snapshot ?? [];
            $payment['effective_payment_method'] = $this->nullable($data['payment_method'] ?? null);
            $payment['payment_identifier'] = $this->nullable($data['payment_identifier'] ?? null);
            $payment['payment_due_date'] = $this->nullable($data['payment_due_date'] ?? null);
            $paid = $this->decimals->normalize((string) $data['paid_amount'], 2);
            if ($paid !== (string) $managed->paid_amount) {
                $this->currencyConversion->assertMoneyChangeAllowed($managed);
            }
            $totals = $this->totals->calculateEditedDocument($this->itemAttributeRows($managed), $paid);
            $payment['paid_amount'] = $totals['paid_amount'];
            $payment['amount_due'] = $totals['amount_due'];

            $candidate = [
                'issue_date' => (string) $data['issue_date'],
                'sale_date' => (string) $data['sale_date'],
                'payment_due_date' => $this->nullable($data['payment_due_date'] ?? null),
                'seller_snapshot' => $seller,
                'seller_name_snapshot' => $seller['name'],
                'seller_tax_id_snapshot' => $seller['tax_id'],
                'issuer_snapshot' => $issuer,
                'payment_snapshot' => $payment,
                'additional_information_text' => $this->nullable($data['additional_information_text'] ?? null),
                'paid_amount' => $totals['paid_amount'],
                'amount_due' => $totals['amount_due'],
            ];

            if ($preparedRate !== null) {
                $candidate['tax_metadata_snapshot'] = $this->currencyConversion->applyPreparedDateChange(
                    $managed,
                    $managed->tax_summary_snapshot ?? [],
                    $preparedRate,
                );
            }

            $changed = $this->attributesDiffer($managed, $candidate);
            if ($changed) {
                $managed->fill($candidate)->save();
            }

            return $changed;
        });
    }

    /** @param array<string, mixed> $data */
    public function addItem(Invoice $invoice, array $data): Invoice
    {
        return $this->mutate($invoice, (int) $data['expected_lock_version'], function (Invoice $managed) use ($data): bool {
            $managed->items()->create($this->editableItems->manualAttributes($data));
            $this->recalculate($managed);

            return true;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateItem(Invoice $invoice, InvoiceItem $item, array $data): Invoice
    {
        return $this->mutate($invoice, (int) $data['expected_lock_version'], function (Invoice $managed) use ($item, $data): bool {
            $managedItem = InvoiceItem::query()->lockForUpdate()->where('invoice_id', $managed->getKey())->findOrFail($item->getKey());
            $attributes = $this->editableItems->manualAttributes($data, $managedItem);
            if (! $this->attributesDiffer($managedItem, $attributes)) {
                return false;
            }
            $managedItem->fill($attributes)->save();
            $this->recalculate($managed);

            return true;
        });
    }

    public function deleteItem(Invoice $invoice, InvoiceItem $item, int $expectedLockVersion): Invoice
    {
        return $this->mutate($invoice, $expectedLockVersion, function (Invoice $managed) use ($item): bool {
            if ($managed->items()->count() <= 1) {
                throw new InvoiceDomainException('invoice_last_item', 'Nie można usunąć ostatniej pozycji Faktury.');
            }
            InvoiceItem::query()->lockForUpdate()->where('invoice_id', $managed->getKey())->findOrFail($item->getKey())->delete();
            $this->recalculate($managed);

            return true;
        });
    }

    public function copyItemsFromOrder(Invoice $invoice, int $expectedLockVersion): Invoice
    {
        return DB::transaction(function () use ($invoice, $expectedLockVersion): Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($invoice->order_id);
            $managed = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->assertMutationAllowed($managed, $expectedLockVersion);
            $candidate = $this->editableItems->fromOrder($managed, $order);
            if ($this->canonicalItems($this->itemAttributeRows($managed)) === $this->canonicalItems($candidate)) {
                return $managed->load('items');
            }

            $managed->items()->delete();
            $managed->items()->createMany($candidate);
            $this->recalculate($managed);
            $this->finishChange($managed);

            return $managed->refresh()->load('items');
        }, 3);
    }

    /** @param callable(Invoice): bool $operation */
    private function mutate(Invoice $invoice, int $expectedLockVersion, callable $operation): Invoice
    {
        return DB::transaction(function () use ($invoice, $expectedLockVersion, $operation): Invoice {
            Order::query()->lockForUpdate()->findOrFail($invoice->order_id);
            $managed = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->assertMutationAllowed($managed, $expectedLockVersion);

            if (! $operation($managed)) {
                return $managed->refresh()->load('items');
            }

            $this->finishChange($managed);

            return $managed->refresh()->load('items');
        }, 3);
    }

    private function assertMutationAllowed(Invoice $invoice, int $expectedLockVersion): void
    {
        $this->policy->assertEditable($invoice);
        $this->currencyConversion->assertSnapshotUsableForAnyEdit($invoice);
        if ($invoice->lock_version !== $expectedLockVersion) {
            throw new InvoiceDomainException(
                'invoice_edit_conflict',
                'Faktura została w międzyczasie zmieniona. Odśwież formularz i ponownie sprawdź dane.',
            );
        }
    }

    private function finishChange(Invoice $invoice): void
    {
        $invoice->lock_version++;
        $invoice->save();
        DB::afterCommit(fn () => $this->pdfStorage->delete($invoice));
    }

    private function recalculate(Invoice $invoice): void
    {
        $invoice->unsetRelation('items');
        $items = $this->itemAttributeRows($invoice);
        $totals = $this->totals->calculateEditedDocument($items, (string) $invoice->paid_amount);
        $totals['tax_metadata_snapshot'] = $this->currencyConversion->forMoneyChange($invoice, $totals['tax_summary_snapshot']);
        $payment = $invoice->payment_snapshot ?? [];
        $payment['paid_amount'] = $totals['paid_amount'];
        $payment['amount_due'] = $totals['amount_due'];
        $totals['payment_snapshot'] = $payment;
        $invoice->fill($totals)->save();
    }

    private function assertNumberingDates(Invoice $invoice, string $issueDate): void
    {
        $settings = $invoice->series_settings_snapshot;
        if (! is_array($settings)) {
            throw new InvoiceDomainException('invoice_edit_numbering_mismatch', 'Nie można zmienić daty wystawienia z powodu niekompletnego snapshotu numeracji.');
        }
        $series = new InvoiceSeries;
        $series->setRawAttributes([
            'reset_period' => $settings['reset_period'] ?? null,
            'fiscal_year_start_month' => $settings['fiscal_year_start_month'] ?? 1,
        ], true);
        $date = CarbonImmutable::parse($issueDate, config('app.timezone'));
        $period = $this->periods->resolve($series, $date);
        $number = $this->numbers->format((string) $invoice->number_format_snapshot, (int) $invoice->sequence_number, $date);
        if ($period !== $invoice->numbering_period_key || $number !== $invoice->number) {
            throw new InvoiceDomainException(
                'invoice_edit_numbering_mismatch',
                'Nie można zmienić daty wystawienia, ponieważ zmieniłaby numer lub okres numeracji Faktury.',
            );
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function addressSnapshot(array $data, bool $withTaxId = true): array
    {
        $countryCode = $this->countries->normalize($data['country_code'] ?? null);
        $snapshot = [
            'name' => $this->nullable($data['name'] ?? null),
            'company_name' => $this->nullable($data['company_name'] ?? null),
            'street' => $this->nullable($data['street'] ?? null),
            'building_number' => $this->nullable($data['building_number'] ?? null),
            'apartment_number' => $this->nullable($data['apartment_number'] ?? null),
            'postal_code' => $this->nullable($data['postal_code'] ?? null),
            'city' => $this->nullable($data['city'] ?? null),
            'province' => $this->nullable($data['province'] ?? null),
            'country_code' => $countryCode,
            'country_name' => $this->countries->name($countryCode),
            'email' => $this->nullable($data['email'] ?? null),
            'phone' => $this->nullable($data['phone'] ?? null),
        ];
        if ($withTaxId) {
            $snapshot['tax_id'] = $this->nullable($data['tax_id'] ?? null);
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sellerSnapshot(array $data): array
    {
        $countryCode = $this->countries->normalize($data['seller_country_code'] ?? null);

        return [
            'name' => $this->nullable($data['seller_name'] ?? null),
            'tax_id' => $this->nullable($data['seller_tax_id'] ?? null),
            'regon' => $this->nullable($data['seller_regon'] ?? null),
            'bdo' => $this->nullable($data['seller_bdo'] ?? null),
            'street' => $this->nullable($data['seller_street'] ?? null),
            'building_number' => $this->nullable($data['seller_building_number'] ?? null),
            'apartment_number' => $this->nullable($data['seller_apartment_number'] ?? null),
            'postal_code' => $this->nullable($data['seller_postal_code'] ?? null),
            'city' => $this->nullable($data['seller_city'] ?? null),
            'province' => $this->nullable($data['seller_province'] ?? null),
            'country_code' => $countryCode,
            'email' => $this->nullable($data['seller_email'] ?? null),
            'phone' => $this->nullable($data['seller_phone'] ?? null),
            'bank_name' => $this->nullable($data['seller_bank_name'] ?? null),
            'bank_account' => $this->nullable($data['seller_bank_account'] ?? null),
            'bank_swift' => $this->nullable($data['seller_bank_swift'] ?? null),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function itemAttributeRows(Invoice $invoice): array
    {
        $invoice->unsetRelation('items');

        return $invoice->items()->orderBy('position')->orderBy('id')->get()->map(function (InvoiceItem $item): array {
            $attributes = $item->only([
                'order_item_id', 'product_id', 'source_invoice_item_id', 'line_type', 'position', 'name', 'description', 'unit_name', 'quantity', 'unit_price_net', 'unit_price_gross', 'total_net', 'total_vat', 'total_gross', 'vat_rate', 'vat_code', 'gtu_codes', 'product_snapshot', 'metadata',
            ]);
            $attributes['line_type'] = $item->line_type?->value;

            return $attributes;
        })->all();
    }

    /** @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function canonicalItems(array $items): array
    {
        return array_map(static fn (array $item): array => array_intersect_key($item, array_flip([
            'line_type', 'position', 'name', 'description', 'unit_name', 'quantity', 'unit_price_net', 'unit_price_gross', 'total_net', 'total_vat', 'total_gross', 'vat_rate', 'vat_code',
        ])), $items);
    }

    /** @param array<string, mixed> $attributes */
    private function attributesDiffer(object $model, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            $current = $model->{$key};
            if ($current instanceof \BackedEnum) {
                $current = $current->value;
            }
            if ($current instanceof \DateTimeInterface) {
                $current = $current->format('Y-m-d');
            }
            if ($current !== $value) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $left
     * @param  array<string, mixed>  $right
     */
    private function snapshotsDiffer(array $left, array $right): bool
    {
        ksort($left);
        ksort($right);

        return $left !== $right;
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
