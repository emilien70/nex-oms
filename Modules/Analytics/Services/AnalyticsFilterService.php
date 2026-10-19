<?php

namespace Modules\Analytics\Services;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use App\Support\CurrencyCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Analytics\Support\StatisticsPeriodResolver;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;

class AnalyticsFilterService
{
    public const DEFAULT_TYPE = 'orders';

    /** @var array<string, string> */
    public const TYPE_OPTIONS = [
        'orders' => 'Ilość i wartość zamówień',
        'sources' => 'Źródła zamówień',
        'fulfillment' => 'Rodzaje wysyłki i płatności',
        'customers' => 'Klienci',
        'products' => 'Produkty',
    ];

    /** @var array<string, string> */
    public const INTERVAL_OPTIONS = [
        'day' => 'Dzień',
        'week' => 'Tydzień',
        'month' => 'Miesiąc',
    ];

    public function __construct(
        private readonly StatisticsPeriodResolver $periods,
        private readonly CurrencyCatalog $currencies,
    ) {}

    /** @return array<string, mixed> */
    public function resolve(array $input): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $period = $this->periods->resolve(
            $input['range'] ?? null,
            $input['date_from'] ?? null,
            $input['date_to'] ?? null,
            $today,
        );
        $sourceGroups = $this->sourceGroups();
        $sourceOptions = collect($sourceGroups)
            ->flatMap(fn (array $group): array => $group['options'])
            ->keyBy('key')
            ->all();
        $statusOptions = OrderStatusSetting::orderedStatuses();
        $currencyOptions = $this->currencyOptions();

        $selectedSources = $this->selectedValues(
            $input,
            'sources_filter',
            'sources',
            array_keys($sourceOptions),
        );
        $selectedStatuses = $this->selectedValues(
            $input,
            'statuses_filter',
            'statuses',
            array_keys($statusOptions),
        );
        $currency = strtoupper((string) ($input['currency'] ?? CurrencyCatalog::SYSTEM_CURRENCY));
        if (! array_key_exists($currency, $currencyOptions)) {
            $currency = array_key_exists(CurrencyCatalog::SYSTEM_CURRENCY, $currencyOptions)
                ? CurrencyCatalog::SYSTEM_CURRENCY
                : (array_key_first($currencyOptions) ?? CurrencyCatalog::SYSTEM_CURRENCY);
        }

        $type = array_key_exists((string) ($input['type'] ?? ''), self::TYPE_OPTIONS)
            ? (string) $input['type']
            : self::DEFAULT_TYPE;
        $interval = array_key_exists((string) ($input['interval'] ?? ''), self::INTERVAL_OPTIONS)
            ? (string) $input['interval']
            : 'day';

        $query = [
            'type' => $type,
            'range' => $period['key'],
            'date_from' => $period['from']->toDateString(),
            'date_to' => $period['to']->toDateString(),
            'interval' => $interval,
            'currency' => $currency,
            'sources_filter' => 1,
            'sources' => $selectedSources,
            'statuses_filter' => 1,
            'statuses' => $selectedStatuses,
        ];

        return [
            'type' => $type,
            'type_options' => self::TYPE_OPTIONS,
            'period' => $period,
            'range_options' => $this->periods->options($today),
            'interval' => $interval,
            'interval_options' => self::INTERVAL_OPTIONS,
            'currency' => $currency,
            'currency_options' => $currencyOptions,
            'source_groups' => $sourceGroups,
            'source_options' => $sourceOptions,
            'selected_sources' => $selectedSources,
            'status_options' => $statusOptions,
            'selected_statuses' => $selectedStatuses,
            'query' => $query,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function orders(array $filters): Builder
    {
        $query = Order::query();

        $this->applyOrderConstraints($query, $filters);

        return $query;
    }

    /** @param array<string, mixed> $filters */
    public function applyOrderConstraints(Builder $query, array $filters): Builder
    {
        $from = $filters['period']['from']->startOfDay();
        $to = $filters['period']['to']->endOfDay();

        $query
            ->where('currency', $filters['currency'])
            ->where(function (Builder $dates) use ($from, $to): void {
                $dates->whereBetween('purchased_at', [$from, $to])
                    ->orWhere(function (Builder $fallback) use ($from, $to): void {
                        $fallback->whereNull('purchased_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            });

        $statuses = $filters['selected_statuses'];
        if ($statuses === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('status', $statuses);
        }

        $sources = $filters['selected_sources'];
        if ($sources === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $sourceQuery) use ($sources): void {
            foreach ($sources as $source) {
                $sourceQuery->orWhere(function (Builder $single) use ($source): void {
                    if (str_starts_with($source, 'prestashop:')) {
                        $accountId = (int) substr($source, strlen('prestashop:'));
                        $single->where('source', 'prestashop')
                            ->whereHas('prestashopOrderLinks', fn (Builder $links) => $links->where('prestashop_account_id', $accountId));

                        return;
                    }

                    $sourceCode = str_starts_with($source, 'source:')
                        ? substr($source, strlen('source:'))
                        : $source;
                    $single->where('source', $sourceCode);

                    if ($sourceCode === 'prestashop') {
                        $single->whereDoesntHave('prestashopOrderLinks');
                    }
                });
            }
        });
    }

    /** @param array<string, mixed> $filters */
    public function shiftedQuery(array $filters, int $direction): array
    {
        $shifted = $this->periods->shifted($filters['period'], $direction);

        return array_replace($filters['query'], [
            'range' => 'custom',
            'date_from' => $shifted['from'],
            'date_to' => $shifted['to'],
        ]);
    }

    /** @return array{key: string, label: string, group: string} */
    public function sourceIdentity(Order $order): array
    {
        if ($order->source === 'prestashop') {
            $link = $order->prestashopOrderLinks->first();
            if ($link?->account) {
                return [
                    'key' => 'prestashop:'.$link->account->getKey(),
                    'label' => $link->account->name.' (PrestaShop)',
                    'group' => 'Sklep internetowy',
                ];
            }

            return [
                'key' => 'source:prestashop',
                'label' => 'PrestaShop (niepowiązane)',
                'group' => 'Sklep internetowy',
            ];
        }

        return match ($order->source) {
            'allegro' => ['key' => 'source:allegro', 'label' => 'Allegro', 'group' => 'Allegro'],
            'manual' => ['key' => 'source:manual', 'label' => 'Ręczne (Pozostałe)', 'group' => 'Pozostałe'],
            default => [
                'key' => 'source:'.$order->source,
                'label' => Str::headline((string) $order->source),
                'group' => 'Pozostałe',
            ],
        };
    }

    /** @return list<array{name: string, options: list<array{key: string, label: string, chart_label: string}>}> */
    private function sourceGroups(): array
    {
        $groups = [
            'Allegro' => [[
                'key' => 'source:allegro',
                'label' => 'Allegro',
                'chart_label' => 'Allegro',
            ]],
            'Sklep internetowy' => [],
            'Pozostałe' => [[
                'key' => 'source:manual',
                'label' => 'Ręczne',
                'chart_label' => 'Ręczne (Pozostałe)',
            ]],
        ];

        foreach (PrestashopAccount::query()->orderBy('name')->orderBy('id')->get(['id', 'name']) as $account) {
            $groups['Sklep internetowy'][] = [
                'key' => 'prestashop:'.$account->id,
                'label' => $account->name,
                'chart_label' => $account->name.' (PrestaShop)',
            ];
        }

        if (Order::query()->where('source', 'prestashop')->whereDoesntHave('prestashopOrderLinks')->exists()) {
            $groups['Sklep internetowy'][] = [
                'key' => 'source:prestashop',
                'label' => 'PrestaShop (niepowiązane)',
                'chart_label' => 'PrestaShop (niepowiązane)',
            ];
        }

        $knownSources = ['allegro', 'prestashop', 'manual'];
        foreach (Order::query()->whereNotIn('source', $knownSources)->distinct()->orderBy('source')->pluck('source') as $source) {
            $groups['Pozostałe'][] = [
                'key' => 'source:'.$source,
                'label' => Str::headline((string) $source),
                'chart_label' => Str::headline((string) $source).' (Pozostałe)',
            ];
        }

        return collect($groups)
            ->filter(fn (array $options): bool => $options !== [])
            ->map(fn (array $options, string $name): array => ['name' => $name, 'options' => array_values($options)])
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private function currencyOptions(): array
    {
        $options = $this->currencies->all();

        if ($options === []) {
            return [CurrencyCatalog::SYSTEM_CURRENCY => CurrencyCatalog::SYSTEM_CURRENCY];
        }

        return $options;
    }

    /** @return list<string> */
    private function selectedValues(array $input, string $marker, string $field, array $allowed): array
    {
        if (! array_key_exists($marker, $input)) {
            return array_values($allowed);
        }

        $selected = is_array($input[$field] ?? null) ? $input[$field] : [];

        return array_values(array_intersect($allowed, array_map('strval', $selected)));
    }
}
