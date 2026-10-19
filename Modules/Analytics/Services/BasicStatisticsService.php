<?php

namespace Modules\Analytics\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Symfony\Component\Intl\Countries;

class BasicStatisticsService
{
    public function __construct(private readonly AnalyticsFilterService $filters) {}

    /** @param array<string, mixed> $state */
    public function build(array $state): array
    {
        return match ($state['type']) {
            'sources' => $this->sourcesReport($state),
            'fulfillment' => $this->fulfillmentReport($state),
            'customers' => $this->customersReport($state),
            'products' => $this->productsReport($state),
            default => $this->ordersReport($state),
        };
    }

    /** @param array<string, mixed> $state */
    private function ordersReport(array $state): array
    {
        $buckets = $this->emptyTimeBuckets($state);
        $summary = [
            'orders' => 0,
            'total_cents' => 0,
            'products_cents' => 0,
            'shipping_cents' => 0,
        ];

        foreach ($this->orderRows($state) as $order) {
            $key = $this->bucketKey($this->orderDate($order), $state['interval']);
            if (! isset($buckets[$key])) {
                continue;
            }

            $total = $this->cents($order->total_gross);
            $shipping = $this->cents($order->delivery_cost_gross);
            $buckets[$key]['count']++;
            $buckets[$key]['value_cents'] += $total;
            $summary['orders']++;
            $summary['total_cents'] += $total;
            $summary['shipping_cents'] += $shipping;
            $summary['products_cents'] += $total - $shipping;
        }

        $labels = array_column($buckets, 'label');
        $counts = array_column($buckets, 'count');
        $values = array_column($buckets, 'value_cents');
        $rows = [];
        foreach ($buckets as $bucket) {
            $rows[] = [$bucket['csv_label'], $bucket['count'], $this->decimal($bucket['value_cents'])];
        }

        return [
            'title' => 'Ilość i wartość zamówień',
            'panels' => [[
                'id' => 'orders-over-time',
                'title' => 'Ilość i wartość zamówień',
                'layout' => 'wide',
                'interval_control' => true,
                'chart' => [
                    'type' => 'dual-line',
                    'labels' => $labels,
                    'datasets' => [
                        ['label' => 'Ilość zamówień', 'data' => $counts, 'axis' => 'count'],
                        ['label' => 'Wartość zamówień', 'data' => $values, 'axis' => 'money', 'money' => true],
                    ],
                ],
                'csv' => [
                    'headers' => ['Okres', 'Ilość zamówień', 'Wartość zamówień ('.$state['currency'].')'],
                    'rows' => $rows,
                ],
            ]],
            'summary' => [
                'days' => $state['period']['days'],
                'orders' => $summary['orders'],
                'currency' => $state['currency'],
                'total' => $this->decimal($summary['total_cents']),
                'products' => $this->decimal($summary['products_cents']),
                'shipping' => $this->decimal($summary['shipping_cents']),
            ],
        ];
    }

    /** @param array<string, mixed> $state */
    private function sourcesReport(array $state): array
    {
        $groups = [];
        foreach ($this->orderRows($state) as $order) {
            $source = $this->filters->sourceIdentity($order);
            $groups[$source['key']] ??= ['label' => $source['label'], 'count' => 0, 'value_cents' => 0];
            $groups[$source['key']]['count']++;
            $groups[$source['key']]['value_cents'] += $this->cents($order->total_gross);
        }

        uasort($groups, fn (array $left, array $right): int => $right['count'] <=> $left['count']);
        $labels = array_column($groups, 'label');
        $counts = array_column($groups, 'count');
        $values = array_column($groups, 'value_cents');

        return [
            'title' => 'Źródła zamówień',
            'panels' => [
                $this->panel(
                    'sources-overview',
                    'Ilość i wartość zamówień wg źródła',
                    'wide',
                    'dual-bar',
                    $labels,
                    [
                        ['label' => 'Ilość zamówień', 'data' => $counts, 'axis' => 'count'],
                        ['label' => 'Wartość zamówień', 'data' => $values, 'axis' => 'money', 'money' => true],
                    ],
                    ['Źródło', 'Ilość zamówień', 'Wartość zamówień ('.$state['currency'].')'],
                    $this->threeColumnRows($groups),
                ),
                $this->panel(
                    'sources-count-share',
                    'Ilość zamówień wg źródła',
                    'half',
                    'pie',
                    $labels,
                    [['label' => 'Ilość zamówień', 'data' => $counts]],
                    ['Źródło', 'Ilość zamówień'],
                    $this->twoColumnRows($groups, 'count'),
                ),
                $this->panel(
                    'sources-value-share',
                    'Wartość zamówień wg źródła',
                    'half',
                    'pie',
                    $labels,
                    [['label' => 'Wartość zamówień', 'data' => $values, 'money' => true]],
                    ['Źródło', 'Wartość zamówień ('.$state['currency'].')'],
                    $this->twoColumnRows($groups, 'value_cents', true),
                ),
            ],
        ];
    }

    /** @param array<string, mixed> $state */
    private function fulfillmentReport(array $state): array
    {
        $shipping = [];
        $payments = [];
        $paymentKind = [
            'prepaid' => ['label' => 'Przedpłata', 'count' => 0],
            'cod' => ['label' => 'Pobranie', 'count' => 0],
        ];
        $shippingPrices = [];

        foreach ($this->orderRows($state) as $order) {
            $shippingLabel = $this->fallbackLabel($order->shipping_method, 'Nie podano');
            $paymentLabel = $this->fallbackLabel($order->payment_method, 'Nie podano');
            $shipping[$shippingLabel] = ($shipping[$shippingLabel] ?? 0) + 1;
            $payments[$paymentLabel] = ($payments[$paymentLabel] ?? 0) + 1;
            $paymentKind[$order->cash_on_delivery ? 'cod' : 'prepaid']['count']++;
            $price = $this->cents($order->delivery_cost_gross);
            $priceLabel = $this->decimal($price).' '.$state['currency'];
            $shippingPrices[$priceLabel] = ($shippingPrices[$priceLabel] ?? 0) + 1;
        }

        arsort($shipping);
        arsort($payments);
        arsort($shippingPrices);

        return [
            'title' => 'Rodzaje wysyłki i płatności',
            'panels' => [
                $this->simpleCountPanel('shipping-methods', 'Rodzaje wysyłki', 'wide', 'bar', $shipping),
                $this->simpleCountPanel('payment-methods', 'Metody płatności', 'wide', 'bar', $payments),
                $this->simpleCountPanel(
                    'payment-kind',
                    'Pobrania',
                    'half',
                    'pie',
                    collect($paymentKind)->mapWithKeys(fn (array $item): array => [$item['label'] => $item['count']])->all(),
                ),
                $this->simpleCountPanel('shipping-prices', 'Ceny wysyłek', 'half', 'pie', $shippingPrices),
            ],
        ];
    }

    /** @param array<string, mixed> $state */
    private function customersReport(array $state): array
    {
        $countries = [];
        $customers = [];

        foreach ($this->orderRows($state) as $order) {
            $countryCode = strtoupper(trim((string) ($order->shipping_country_code ?: $order->billing_country_code)));
            if ($countryCode !== '') {
                $countryName = Countries::exists($countryCode) ? Countries::getName($countryCode, 'pl') : $countryCode;
                $countries[$countryCode] ??= ['label' => $countryName, 'count' => 0];
                $countries[$countryCode]['count']++;
            }

            $identity = $this->customerIdentity($order);
            $customers[$identity] ??= [
                'label' => $this->customerLabel($order),
                'count' => 0,
                'value_cents' => 0,
            ];
            $customers[$identity]['count']++;
            $customers[$identity]['value_cents'] += $this->cents($order->total_gross);
        }

        $repeat = [
            '1 zamówienie' => 0,
            '2 zamówienia' => 0,
            '3-5 zamówień' => 0,
            '> 5 zamówień' => 0,
        ];
        foreach ($customers as $customer) {
            $bucket = match (true) {
                $customer['count'] === 1 => '1 zamówienie',
                $customer['count'] === 2 => '2 zamówienia',
                $customer['count'] <= 5 => '3-5 zamówień',
                default => '> 5 zamówień',
            };
            $repeat[$bucket]++;
        }

        $topCount = collect($customers)->sortByDesc('count')->take(10)->values()->all();
        $topValue = collect($customers)->sortByDesc('value_cents')->take(10)->values()->all();
        uasort($countries, fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        return [
            'title' => 'Klienci',
            'panels' => [
                [
                    'id' => 'customer-countries',
                    'title' => 'Kraje',
                    'layout' => 'half',
                    'chart' => [
                        'type' => 'geo',
                        'values' => collect($countries)->map(fn (array $row, string $code): array => [
                            'code' => $code,
                            'label' => $row['label'],
                            'map_name' => Countries::exists($code) ? Countries::getName($code, 'en') : $row['label'],
                            'value' => $row['count'],
                        ])->values()->all(),
                    ],
                    'csv' => [
                        'headers' => ['Kraj', 'Kod kraju', 'Ilość zamówień'],
                        'rows' => collect($countries)->map(fn (array $row, string $code): array => [$row['label'], $code, $row['count']])->values()->all(),
                    ],
                ],
                $this->simpleCountPanel('repeat-customers', 'Stali klienci', 'half', 'pie', $repeat, 'Liczba klientów'),
                $this->rankedCustomerPanel('top-customers-count', 'Top 10 - ilość zamówień', $topCount, 'count', false, $state['currency']),
                $this->rankedCustomerPanel('top-customers-value', 'Top 10 - wartość zamówień', $topValue, 'value_cents', true, $state['currency']),
            ],
        ];
    }

    /** @param array<string, mixed> $state */
    private function productsReport(array $state): array
    {
        $perOrder = [];
        $priceBuckets = [
            '< 10 '.$state['currency'] => 0,
            '10 - 49 '.$state['currency'] => 0,
            '50 - 99 '.$state['currency'] => 0,
            '100 - 499 '.$state['currency'] => 0,
            '500 - 999 '.$state['currency'] => 0,
            '≥ 1000 '.$state['currency'] => 0,
        ];
        $products = [];

        $items = OrderItem::query()
            ->select(['id', 'order_id', 'product_id', 'product_name', 'quantity', 'unit_price_gross', 'total_price_gross'])
            ->with('product:id,name,item_type')
            ->whereHas('order', fn ($query) => $this->filters->applyOrderConstraints($query, $state))
            ->lazyById(500);

        foreach ($items as $item) {
            $quantity = max(0, (int) $item->quantity);
            $perOrder[$item->order_id] = ($perOrder[$item->order_id] ?? 0) + $quantity;
            $priceBuckets[$this->priceBucket($this->cents($item->unit_price_gross), $state['currency'])] += $quantity;

            $key = $item->product_id ? 'product:'.$item->product_id : 'snapshot:'.mb_strtolower(trim($item->product_name));
            $label = trim((string) ($item->product?->name ?: $item->product_name));
            $products[$key] ??= ['label' => $label !== '' ? $label : 'Produkt bez nazwy', 'quantity' => 0, 'value_cents' => 0];
            $products[$key]['quantity'] += $quantity;
            $products[$key]['value_cents'] += $this->cents($item->total_price_gross);
        }

        $productsPerOrder = [
            '1 produkt' => 0,
            '2 produkty' => 0,
            '3-5 produktów' => 0,
            '> 5 produktów' => 0,
        ];
        foreach ($perOrder as $count) {
            $bucket = match (true) {
                $count <= 1 => '1 produkt',
                $count === 2 => '2 produkty',
                $count <= 5 => '3-5 produktów',
                default => '> 5 produktów',
            };
            $productsPerOrder[$bucket]++;
        }

        $topQuantity = collect($products)->sortByDesc('quantity')->take(10)->values()->all();
        $topValue = collect($products)->sortByDesc('value_cents')->take(10)->values()->all();

        return [
            'title' => 'Produkty',
            'panels' => [
                $this->simpleCountPanel('products-per-order', 'Produkty w zamówieniach', 'half', 'pie', $productsPerOrder, 'Liczba zamówień'),
                $this->simpleCountPanel('product-prices', 'Ceny produktów', 'half', 'pie', $priceBuckets, 'Liczba sprzedanych sztuk'),
                $this->rankedProductPanel('top-products-quantity', 'Top 10 - ilość sprzedanych sztuk', $topQuantity, 'quantity', false, $state['currency']),
                $this->rankedProductPanel('top-products-value', 'Top 10 - wartość sprzedanych sztuk', $topValue, 'value_cents', true, $state['currency']),
            ],
        ];
    }

    /** @param array<string, mixed> $state */
    private function orderRows(array $state): iterable
    {
        return $this->filters->orders($state)
            ->select([
                'id', 'source', 'status', 'currency', 'total_gross', 'delivery_cost_gross',
                'shipping_method', 'cash_on_delivery', 'payment_method', 'purchased_at', 'created_at',
                'customer_login', 'customer_email', 'customer_phone', 'shipping_name', 'billing_name',
                'shipping_email', 'billing_email', 'shipping_phone', 'billing_phone',
                'shipping_country_code', 'billing_country_code',
            ])
            ->with('prestashopOrderLinks.account:id,name')
            ->lazyById(500);
    }

    /** @param array<string, mixed> $state */
    private function emptyTimeBuckets(array $state): array
    {
        $buckets = [];
        $cursor = $state['period']['from'];
        $end = $state['period']['to'];

        while ($cursor->lte($end)) {
            $key = $this->bucketKey($cursor, $state['interval']);
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'label' => $this->bucketLabel($cursor, $state['interval']),
                    'csv_label' => $this->bucketCsvLabel($cursor, $state['interval']),
                    'count' => 0,
                    'value_cents' => 0,
                ];
            }

            $cursor = $cursor->addDay();
        }

        return $buckets;
    }

    private function bucketKey(CarbonImmutable $date, string $interval): string
    {
        return match ($interval) {
            'week' => $date->startOfWeek()->toDateString(),
            'month' => $date->startOfMonth()->toDateString(),
            default => $date->toDateString(),
        };
    }

    private function bucketLabel(CarbonImmutable $date, string $interval): string|array
    {
        return match ($interval) {
            'week' => $date->startOfWeek()->format('d.m').' - '.$date->endOfWeek()->format('d.m'),
            'month' => $this->monthName($date).' '.$date->year,
            default => [$date->format('d.m'), (string) $date->year],
        };
    }

    private function bucketCsvLabel(CarbonImmutable $date, string $interval): string
    {
        return match ($interval) {
            'week' => $date->startOfWeek()->toDateString().' - '.$date->endOfWeek()->toDateString(),
            'month' => $date->format('Y-m'),
            default => $date->toDateString(),
        };
    }

    private function monthName(CarbonImmutable $date): string
    {
        return [1 => 'Sty', 2 => 'Lut', 3 => 'Mar', 4 => 'Kwi', 5 => 'Maj', 6 => 'Cze', 7 => 'Lip', 8 => 'Sie', 9 => 'Wrz', 10 => 'Paź', 11 => 'Lis', 12 => 'Gru'][$date->month];
    }

    private function orderDate(Order $order): CarbonImmutable
    {
        $date = $order->purchased_at ?: $order->created_at;

        return CarbonImmutable::instance($date)->setTimezone(config('app.timezone'));
    }

    private function cents(mixed $value): int
    {
        return BigDecimal::of((string) ($value ?? '0'))
            ->withPointMovedRight(2)
            ->toScale(0, RoundingMode::HALF_UP)
            ->toInt();
    }

    private function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).','.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function fallbackLabel(mixed $value, string $fallback): string
    {
        $label = trim((string) $value);

        return $label !== '' ? $label : $fallback;
    }

    private function customerIdentity(Order $order): string
    {
        $email = mb_strtolower(trim((string) ($order->customer_email ?: $order->shipping_email ?: $order->billing_email)));
        if ($email !== '') {
            return 'email:'.$email;
        }

        $login = mb_strtolower(trim((string) $order->customer_login));
        if ($login !== '') {
            return 'login:'.$login;
        }

        $phone = preg_replace('/\D+/', '', (string) ($order->customer_phone ?: $order->shipping_phone ?: $order->billing_phone));

        return $phone !== '' ? 'phone:'.$phone : 'order:'.$order->id;
    }

    private function customerLabel(Order $order): string
    {
        $name = trim((string) ($order->shipping_name ?: $order->billing_name));
        $identity = trim((string) ($order->customer_email ?: $order->customer_login ?: $order->customer_phone));

        if ($name !== '' && $identity !== '') {
            return $name.' ('.$identity.')';
        }

        return $name !== '' ? $name : ($identity !== '' ? $identity : 'Klient zamówienia #'.$order->id);
    }

    private function priceBucket(int $cents, string $currency): string
    {
        return match (true) {
            $cents < 1000 => '< 10 '.$currency,
            $cents < 5000 => '10 - 49 '.$currency,
            $cents < 10000 => '50 - 99 '.$currency,
            $cents < 50000 => '100 - 499 '.$currency,
            $cents < 100000 => '500 - 999 '.$currency,
            default => '≥ 1000 '.$currency,
        };
    }

    private function panel(
        string $id,
        string $title,
        string $layout,
        string $type,
        array $labels,
        array $datasets,
        array $headers,
        array $rows,
        bool $horizontal = false,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'layout' => $layout,
            'chart' => [
                'type' => $type,
                'labels' => array_values($labels),
                'datasets' => $datasets,
                'horizontal' => $horizontal,
            ],
            'csv' => ['headers' => $headers, 'rows' => $rows],
        ];
    }

    private function simpleCountPanel(
        string $id,
        string $title,
        string $layout,
        string $type,
        array $values,
        string $valueLabel = 'Ilość zamówień',
    ): array {
        return $this->panel(
            $id,
            $title,
            $layout,
            $type,
            array_keys($values),
            [['label' => $valueLabel, 'data' => array_values($values)]],
            ['Wartość', $valueLabel],
            collect($values)->map(fn (int $value, string $label): array => [$label, $value])->values()->all(),
        );
    }

    private function rankedCustomerPanel(string $id, string $title, array $rows, string $field, bool $money, string $currency): array
    {
        return $this->rankedPanel($id, $title, $rows, $field, $money, $currency, 'Klient');
    }

    private function rankedProductPanel(string $id, string $title, array $rows, string $field, bool $money, string $currency): array
    {
        return $this->rankedPanel($id, $title, $rows, $field, $money, $currency, 'Produkt');
    }

    private function rankedPanel(string $id, string $title, array $rows, string $field, bool $money, string $currency, string $entity): array
    {
        $values = array_column($rows, $field);

        return $this->panel(
            $id,
            $title,
            'wide',
            'bar',
            array_column($rows, 'label'),
            [[
                'label' => $money ? 'Wartość sprzedaży' : ($entity === 'Produkt' ? 'Ilość sprzedanych sztuk' : 'Ilość zamówień'),
                'data' => $values,
                'money' => $money,
            ]],
            [$entity, $money ? 'Wartość ('.$currency.')' : ($entity === 'Produkt' ? 'Ilość sprzedanych sztuk' : 'Ilość zamówień')],
            array_map(fn (array $row): array => [$row['label'], $money ? $this->decimal($row[$field]) : $row[$field]], $rows),
            true,
        );
    }

    private function threeColumnRows(array $groups): array
    {
        return collect($groups)->map(fn (array $row): array => [
            $row['label'],
            $row['count'],
            $this->decimal($row['value_cents']),
        ])->values()->all();
    }

    private function twoColumnRows(array $groups, string $field, bool $money = false): array
    {
        return collect($groups)->map(fn (array $row): array => [
            $row['label'],
            $money ? $this->decimal($row[$field]) : $row[$field],
        ])->values()->all();
    }
}
