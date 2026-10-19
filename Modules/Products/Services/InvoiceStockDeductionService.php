<?php

namespace Modules\Products\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;
use Modules\Invoices\Enums\InvoiceItemType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Products\Models\Product;
use Modules\Products\Models\OrderItemBundleComponent;
use Modules\Products\Models\ProductStockMovement;

class InvoiceStockDeductionService
{
    private const MIN_STOCK = -2147483648;

    private const MAX_STOCK = 2147483647;

    public function deduct(
        Invoice $invoice,
        AutomationRun $run,
        AutomationRunStep $step,
    ): array {
        return DB::transaction(function () use ($invoice, $run, $step): array {
            $managedInvoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());

            if (! $managedInvoice->isInvoice() || ! $managedInvoice->isIssued()) {
                throw new DomainException('Stan magazynowy można zmniejszyć wyłącznie dla wystawionej Faktury VAT.');
            }

            $items = InvoiceItem::query()
                ->where('invoice_id', $managedInvoice->getKey())
                ->where('line_type', InvoiceItemType::Product->value)
                ->orderBy('position')
                ->orderBy('id')
                ->get();
            [$deductions, $ignoredLines] = $this->deductions($managedInvoice, $items);
            $alreadyApplied = $this->existingMovements($deductions);
            $pending = $deductions->reject(
                fn (array $deduction, string $key): bool => $alreadyApplied->has($key),
            );
            $products = $this->lockedProducts($pending);
            $movementIds = [];
            $deductedQuantity = 0;

            foreach ($pending as $key => $deduction) {
                $existing = ProductStockMovement::query()
                    ->where('idempotency_key', $key)
                    ->first();

                if ($existing !== null) {
                    $alreadyApplied->put($key, $existing);

                    continue;
                }

                /** @var Product|null $product */
                $product = $products->get($deduction['product_id']);
                if ($product === null) {
                    throw new DomainException(
                        'Produkt #'.$deduction['product_id'].' powiązany z pozycją faktury już nie istnieje.',
                    );
                }

                $before = (int) $product->stock_quantity;
                $after = $before - $deduction['quantity'];
                if ($after < self::MIN_STOCK || $after > self::MAX_STOCK) {
                    throw new DomainException(
                        'Zmiana stanu produktu #'.$product->getKey().' przekracza obsługiwany zakres.',
                    );
                }

                $product->update(['stock_quantity' => $after]);
                $movement = ProductStockMovement::query()->create([
                    'product_id' => $product->getKey(),
                    'order_id' => $managedInvoice->order_id,
                    'order_item_id' => $deduction['order_item_id'],
                    'invoice_id' => $managedInvoice->getKey(),
                    'invoice_item_id' => $deduction['invoice_item_id'],
                    'automation_run_id' => $run->getKey(),
                    'automation_run_step_id' => $step->getKey(),
                    'movement_type' => ProductStockMovement::TYPE_INVOICE_ISSUED,
                    'quantity_delta' => -$deduction['quantity'],
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'idempotency_key' => $key,
                    'product_snapshot' => [
                        'id' => $product->getKey(),
                        'name' => $product->name,
                        'ean' => $product->ean,
                    ],
                    'context' => [
                        'invoice_number' => $managedInvoice->number,
                        'invoice_item_ids' => $deduction['invoice_item_ids'],
                        'automation_rule_id' => $run->automation_rule_id,
                        'bundle_product_id' => $deduction['bundle_product_id'] ?? null,
                        'bundle_product_name' => $deduction['bundle_product_name'] ?? null,
                        'quantity_per_bundle' => $deduction['quantity_per_bundle'] ?? null,
                    ],
                    'occurred_at' => now(),
                ]);
                $movementIds[] = $movement->getKey();
                $deductedQuantity += $deduction['quantity'];
            }

            if ($movementIds !== []) {
                $managedInvoice->order->events()->create([
                    'event_type' => 'stock_decreased',
                    'title' => 'Zmniejszono stan magazynowy',
                    'description' => 'Pomniejszono stan magazynowy po wystawieniu Faktury VAT '.$managedInvoice->number.'.',
                    'payload' => [
                        'invoice_id' => $managedInvoice->getKey(),
                        'invoice_number' => $managedInvoice->number,
                        'automation_run_id' => $run->getKey(),
                        'movement_ids' => $movementIds,
                        'deducted_quantity' => $deductedQuantity,
                    ],
                ]);
            }

            return [
                'invoice_id' => $managedInvoice->getKey(),
                'invoice_number' => $managedInvoice->number,
                'movement_count' => count($movementIds),
                'movement_ids' => $movementIds,
                'deducted_quantity' => $deductedQuantity,
                'already_applied_count' => $alreadyApplied->count(),
                'ignored_line_count' => $ignoredLines,
            ];
        }, 3);
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @return array{0: Collection<string, array<string, mixed>>, 1: int}
     */
    private function deductions(Invoice $invoice, Collection $items): array
    {
        $deductions = collect();
        $ignoredLines = 0;
        $orderItemIds = $items->pluck('order_item_id')->filter()->unique()->values();
        $bundleComponents = $orderItemIds->isEmpty()
            ? collect()
            : OrderItemBundleComponent::query()
                ->whereIn('order_item_id', $orderItemIds->all())
                ->orderBy('id')
                ->get()
                ->groupBy('order_item_id');
        $productTypes = Product::query()
            ->whereIn('id', $items->pluck('product_id')->filter()->unique()->values()->all())
            ->pluck('item_type', 'id');

        foreach ($items as $item) {
            if ($item->product_id === null || $item->order_item_id === null) {
                $ignoredLines++;

                continue;
            }

            $quantityInteger = $this->integerQuantity($item);
            $snapshots = $bundleComponents->get((int) $item->order_item_id, collect());
            $isBundle = $productTypes->get((int) $item->product_id) === Product::TYPE_BUNDLE
                || $snapshots->isNotEmpty();

            if ($isBundle && $snapshots->isEmpty()) {
                throw new DomainException(
                    'Zestaw z pozycji '.$item->position.' faktury nie posiada historycznego składu.',
                );
            }

            if (! $isBundle) {
                $this->addDeduction(
                    $deductions,
                    $this->idempotencyKey($invoice, $item),
                    $item,
                    (int) $item->product_id,
                    $quantityInteger,
                );

                continue;
            }

            foreach ($snapshots as $snapshot) {
                if ($snapshot->component_product_id === null) {
                    throw new DomainException(
                        'Składnik zestawu „'.$snapshot->component_product_name.'” już nie istnieje.',
                    );
                }

                $componentQuantity = $quantityInteger * (int) $snapshot->quantity_per_bundle;
                if ($componentQuantity > self::MAX_STOCK) {
                    throw new DomainException(
                        'Ilość składnika „'.$snapshot->component_product_name.'” przekracza obsługiwany zakres.',
                    );
                }

                $this->addDeduction(
                    $deductions,
                    $this->idempotencyKey($invoice, $item, (int) $snapshot->component_product_id),
                    $item,
                    (int) $snapshot->component_product_id,
                    $componentQuantity,
                    [
                        'bundle_product_id' => (int) $item->product_id,
                        'bundle_product_name' => $item->name,
                        'quantity_per_bundle' => (int) $snapshot->quantity_per_bundle,
                    ],
                );
            }
        }

        return [$deductions, $ignoredLines];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $deductions
     * @return Collection<string, ProductStockMovement>
     */
    private function existingMovements(Collection $deductions): Collection
    {
        if ($deductions->isEmpty()) {
            return collect();
        }

        return ProductStockMovement::query()
            ->whereIn('idempotency_key', $deductions->keys()->all())
            ->get()
            ->keyBy('idempotency_key');
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $pending
     * @return Collection<int, Product>
     */
    private function lockedProducts(Collection $pending): Collection
    {
        if ($pending->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $pending->pluck('product_id')->unique()->values()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function integerQuantity(InvoiceItem $item): int
    {
        try {
            $quantity = BigDecimal::of((string) $item->quantity);
            if ($quantity->isLessThanOrEqualTo(0) || $quantity->hasNonZeroFractionalPart()) {
                throw new DomainException(
                    'Pozycja '.$item->position.' faktury ma ilość, której nie można odjąć od całkowitego stanu magazynowego.',
                );
            }

            return $quantity->toInt();
        } catch (MathException $exception) {
            throw new DomainException(
                'Pozycja '.$item->position.' faktury ma nieprawidłową ilość magazynową.',
                previous: $exception,
            );
        }
    }

    /**
     * @param Collection<string, array<string, mixed>> $deductions
     * @param array<string, mixed> $context
     */
    private function addDeduction(
        Collection $deductions,
        string $key,
        InvoiceItem $item,
        int $productId,
        int $quantity,
        array $context = [],
    ): void {
        $current = $deductions->get($key);
        if ($current !== null && $current['product_id'] !== $productId) {
            throw new DomainException('Jedna pozycja zamówienia wskazuje różne produkty magazynowe.');
        }
        $totalQuantity = ($current['quantity'] ?? 0) + $quantity;
        if ($totalQuantity > self::MAX_STOCK) {
            throw new DomainException('Łączna ilość do odjęcia przekracza obsługiwany zakres.');
        }

        $deductions->put($key, array_merge($context, [
            'product_id' => $productId,
            'order_item_id' => (int) $item->order_item_id,
            'invoice_item_id' => $current['invoice_item_id'] ?? $item->getKey(),
            'invoice_item_ids' => [
                ...($current['invoice_item_ids'] ?? []),
                $item->getKey(),
            ],
            'quantity' => $totalQuantity,
        ]));
    }

    private function idempotencyKey(
        Invoice $invoice,
        InvoiceItem $item,
        ?int $componentProductId = null,
    ): string
    {
        $key = 'invoice_issued:order:'.$invoice->order_id.':order_item:'.$item->order_item_id;

        return $componentProductId === null
            ? $key
            : $key.':component:'.$componentProductId;
    }
}
