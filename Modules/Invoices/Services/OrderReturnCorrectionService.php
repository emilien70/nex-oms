<?php

namespace Modules\Invoices\Services;

use App\Models\OrderReturn;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;

class OrderReturnCorrectionService
{
    public function __construct(private readonly ProformaSourceSnapshotHasher $hasher) {}

    public function existing(Invoice $source, int $returnId): ?Invoice
    {
        $return = OrderReturn::query()->find($returnId);
        if (! $return || $return->order_id !== $source->order_id) {
            $this->fail('Zwrot nie należy do zamówienia tej Faktury.');
        }

        $existing = Invoice::query()->where('order_return_id', $returnId)->first();
        if ($existing && (! $existing->isCorrection() || $existing->corrected_invoice_id !== $source->id)) {
            $this->fail('Powiązanie zwrotu z dokumentem jest niespójne.');
        }

        return $existing;
    }

    /** @param Collection<int, array<string, mixed>> $effectiveItems */
    public function prepare(Invoice $source, int $returnId, Collection $effectiveItems, bool $lock = false): array
    {
        $query = OrderReturn::query()->with('localStatus')->whereKey($returnId);
        $return = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $return || $return->order_id !== $source->order_id || ! $source->order
            || $source->order->trashed() || $return->currency !== $source->currency
            || $return->localStatus?->code === 'rejected') {
            $this->fail('Wymagany nieodrzucony zwrot, powiązane zamówienie i zgodna waluta Faktury.');
        }
        $existing = Invoice::query()->where('order_return_id', $returnId)->first();
        if ($existing) {
            throw new InvoiceDomainException('return_correction_exists', 'Ten zwrot posiada już Korektę.', ['correction_id' => $existing->id]);
        }
        $itemsQuery = $return->items()->with('orderItem');
        $items = ($lock ? $itemsQuery->lockForUpdate() : $itemsQuery)->get();
        if ($items->isEmpty() || $items->count() > 100) {
            $this->fail('Zwrot musi posiadać od 1 do 100 jednoznacznie powiązanych pozycji.');
        }
        $base = $effectiveItems->map(fn ($item) => $this->formItem($item))->all();
        $rows = [];
        foreach ($items as $item) {
            if (! $item->order_item_id || $item->orderItem?->order_id !== $source->order_id) {
                $this->fail('Nie można jednoznacznie powiązać pozycji zwrotu z pozycją Faktury.');
            }
            $matches = array_values(array_filter($base, fn ($line) => $line['line_type'] === 'product' && $line['order_item_id'] === $item->order_item_id));
            if (count($matches) !== 1 || isset($rows[$item->order_item_id])) {
                $this->fail('Powiązanie pozycji zwrotu z Fakturą jest niejednoznaczne. Nie dopasowujemy produktów po nazwie.');
            }
            $line = $matches[0];
            $quantity = $this->integer($item->quantity);
            if ($quantity < 1 || $quantity > $line['quantity']) {
                $this->fail('Ilość zwrotu przekracza ilość pozostałą po wcześniejszych Korektach. Sprawdź dokumenty.');
            }
            $rows[$item->order_item_id] = ['source_item_id' => $line['source_item_id'], 'return_item_id' => $item->id,
                'order_item_id' => $item->order_item_id, 'maximum' => $quantity];
        }
        $snapshot = ['version' => 1, 'return_id' => $return->id, 'reference_number' => $return->reference_number,
            'order_id' => $source->order_id, 'return_version' => $return->lock_version, 'rows' => array_values($rows)];
        $hash = $this->hasher->hash(['return' => $snapshot, 'base' => $base,
            'return_items' => $items->map->getAttributes()->all()]);

        return $this->view($snapshot, $base, $hash);
    }

    public function forEdit(Invoice $correction): ?array
    {
        $snapshot = data_get($correction->order_snapshot, 'return_correction');
        if (! is_array($snapshot)) {
            if ($correction->order_return_id !== null) {
                $this->fail('Brakuje historycznego powiązania Korekty ze zwrotem.');
            }

            return null;
        }
        if (($correction->order_return_id !== null && $correction->order_return_id !== ($snapshot['return_id'] ?? null))
            || $correction->order_id !== ($snapshot['order_id'] ?? null) || ! $correction->isCorrection()) {
            $this->fail('Powiązanie Korekty ze zwrotem jest niespójne.');
        }
        $base = $correction->items()->get()->map(function ($item): array {
            $line = $this->formItem(['source_item_id' => $item->id, 'source_item' => $item, 'snapshot' => $item->correction_before_snapshot]);
            $line['context_key'] = $item->source_invoice_item_id;

            return $line;
        })->all();

        return $this->view($snapshot, $base);
    }

    public function apply(array $prepared, array $data): array
    {
        $snapshot = $prepared['snapshot'];
        $quantities = $data['return_quantities'] ?? [];
        if (! is_array($quantities) || array_diff(array_keys($quantities), array_column($snapshot['rows'], 'source_item_id')) !== []) {
            $this->fail('Nieprawidłowy wybór pozycji zwrotu.');
        }
        $selected = [];
        foreach ($snapshot['rows'] as $row) {
            $quantity = $this->integer($quantities[$row['source_item_id']] ?? null);
            if ($quantity > $row['maximum']) {
                $this->fail('Ilość korygowana nie może przekraczać liczby sztuk na karcie zwrotu.');
            }
            $selected[$row['source_item_id']] = $quantity;
        }
        $includeShipping = filter_var($data['return_shipping'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $shipping = '0.00';
        if ($includeShipping) {
            $shipping = $this->money($data['return_shipping_amount'] ?? null);
            if (! $prepared['shipping'] || BigDecimal::of($shipping)->isLessThanOrEqualTo('0')
                || BigDecimal::of($shipping)->isGreaterThan($prepared['shipping']['maximum'])) {
                $this->fail('Kwota korekty dostawy przekracza pozostałą wartość dostawy na dokumencie.');
            }
        }
        if (array_sum($selected) === 0 && $shipping === '0.00') {
            $this->fail('Wybierz co najmniej jedną sztukę do korekty lub dodatnią kwotę dostawy.');
        }
        $lines = [];
        foreach ($prepared['base'] as $line) {
            $key = $line['context_key'] ?? $line['source_item_id'];
            if (isset($selected[$key])) {
                $line['quantity'] -= $selected[$key];
            }
            if ($includeShipping && $line['source_item_id'] === $prepared['shipping']['source_item_id']) {
                $remaining = BigDecimal::of($prepared['shipping']['maximum'])->minus($shipping)->toScale(2);
                $line['quantity'] = $remaining->isZero() ? 0 : 1;
                if (! $remaining->isZero()) {
                    $line['unit_price_gross'] = (string) $remaining;
                }
            }
            unset($line['context_key'], $line['total_gross']);
            $lines[] = $line;
        }
        $snapshot['selected_quantities'] = $selected;
        $snapshot['shipping_amount'] = $shipping;

        return ['items' => $lines, 'change_items' => true, 'snapshot' => $snapshot];
    }

    public function forIssue(Invoice $source, Collection $effectiveItems, array $data): ?array
    {
        if (empty($data['order_return_id'])) {
            return null;
        }
        $prepared = $this->prepare($source, (int) $data['order_return_id'], $effectiveItems, true);
        if (! is_string($data['return_context_hash'] ?? null) || ! hash_equals($prepared['hash'], $data['return_context_hash'])) {
            $this->fail('Zwrot lub dokument zmienił się. Otwórz ponownie formularz i sprawdź dane.');
        }

        return $this->apply($prepared, $data);
    }

    public function forUpdate(Invoice $correction, array $data): ?array
    {
        $prepared = $this->forEdit($correction);
        if (! $prepared) {
            if (! empty($data['order_return_id'])) {
                $this->fail('Nie można przypisać istniejącej Korekty do innego zwrotu.');
            }

            return null;
        }
        if (isset($data['order_return_id']) && (int) $data['order_return_id'] !== $prepared['snapshot']['return_id']) {
            $this->fail('Nie można zmienić powiązanego zwrotu.');
        }

        return $this->apply($prepared, $data);
    }

    public function event(Invoice $correction, string $type): void
    {
        $id = data_get($correction->order_snapshot, 'return_correction.return_id');
        if (! $id) {
            return;
        }
        $return = OrderReturn::query()->whereKey($id)->where('order_id', $correction->order_id)->first();
        $return?->events()->create(['type' => $type, 'status' => mb_substr((string) $correction->number, 0, 100), 'observed_at' => now(),
            'data' => ['correction_id' => $correction->id, 'correction_number' => $correction->number]]);
    }

    public function card(OrderReturn $return): array
    {
        $source = $return->order?->invoices->first(fn ($invoice) => $invoice->isInvoice() && $invoice->isIssued());
        $linked = Invoice::query()->where('order_return_id', $return->id)->first();
        $blocked = ! $source ? 'Brak wystawionej Faktury VAT do tego zamówienia.' : null;
        if (! $return->order || $return->order->trashed() || $return->localStatus?->code === 'rejected') {
            $blocked = 'Wymagany nieodrzucony zwrot i istniejące zamówienie.';
        }

        return ['source' => $source, 'linked' => $linked, 'blocked' => $blocked];
    }

    private function view(array $snapshot, array $base, ?string $hash = null): array
    {
        if (($snapshot['version'] ?? null) !== 1 || empty($snapshot['rows']) || empty($snapshot['return_id'])) {
            $this->fail('Historyczne dane powiązania zwrotu są niekompletne.');
        }
        $rows = [];
        foreach ($snapshot['rows'] as $row) {
            $matches = array_values(array_filter($base, fn ($line) => ($line['context_key'] ?? $line['source_item_id']) === $row['source_item_id']));
            if (count($matches) !== 1 || $matches[0]['order_item_id'] !== $row['order_item_id'] || $row['maximum'] > $matches[0]['quantity']) {
                $this->fail('Historyczne pozycje Korekty nie odpowiadają powiązanemu zwrotowi.');
            }
            $rows[] = [...$row, 'name' => $matches[0]['name'], 'before' => $matches[0]['quantity'],
                'unit_price_gross' => $matches[0]['unit_price_gross'],
                'selected' => $snapshot['selected_quantities'][$row['source_item_id']] ?? $row['maximum']];
        }
        $shippingLines = array_values(array_filter($base, fn ($line) => $line['line_type'] === 'shipping'));
        $shipping = count($shippingLines) === 1 && $shippingLines[0]['quantity'] === 1
            && BigDecimal::of($shippingLines[0]['total_gross'])->isGreaterThan('0')
            ? ['source_item_id' => $shippingLines[0]['source_item_id'], 'maximum' => $shippingLines[0]['total_gross']] : null;

        $returnUrl = OrderReturn::query()->whereKey($snapshot['return_id'])->where('order_id', $snapshot['order_id'])->exists()
            ? route('returns.show', $snapshot['return_id']) : null;

        return compact('snapshot', 'base', 'rows', 'hash', 'shipping', 'returnUrl');
    }

    private function formItem(array $item): array
    {
        $snapshot = $item['snapshot'];
        if (! is_array($snapshot)) {
            $this->fail('Brakuje historycznych pozycji dokumentu.');
        }
        if (! BigDecimal::of((string) $snapshot['unit_price_gross'])->multipliedBy((string) $snapshot['quantity'])
            ->toScale(2, RoundingMode::HALF_UP)->isEqualTo((string) $snapshot['total_gross'])) {
            $this->fail('Historyczne kwoty pozycji wymagają ręcznego sprawdzenia przed powiązaniem zwrotu.');
        }

        return ['source_item_id' => $item['source_item_id'], 'order_item_id' => $item['source_item']->order_item_id,
            'line_type' => $snapshot['line_type'], 'position' => (int) $snapshot['position'],
            'name' => $snapshot['name'], 'description' => $snapshot['description'] ?? null,
            'unit_name' => $snapshot['unit_name'], 'quantity' => $this->integer($snapshot['quantity']),
            'unit_price_gross' => $this->money($snapshot['unit_price_gross']), 'vat_rate' => $snapshot['vat_rate'],
            'vat_code' => $snapshot['vat_code'] ?? null, 'total_gross' => $this->money($snapshot['total_gross'])];
    }

    private function integer(mixed $value): int
    {
        try {
            $quantity = BigDecimal::of((string) $value)->toScale(0, RoundingMode::UNNECESSARY)->toInt();
            if ($quantity < 0 || $quantity > 1000000) {
                $this->fail('Nieprawidłowa ilość pozycji zwrotu.');
            }

            return $quantity;
        } catch (MathException) {
            $this->fail('Ilość musi być nieujemną liczbą całkowitą.');
        }
    }

    private function money(mixed $value): string
    {
        try {
            $amount = BigDecimal::of(str_replace(',', '.', (string) $value))->toScale(2, RoundingMode::UNNECESSARY);
            if ($amount->isNegative()) {
                $this->fail('Kwota nie może być ujemna.');
            }

            return (string) $amount;
        } catch (MathException) {
            $this->fail('Kwota wymaga dokładności do dwóch miejsc po przecinku.');
        }
    }

    private function fail(string $message): never
    {
        throw new InvoiceDomainException('return_correction_invalid', $message);
    }
}
