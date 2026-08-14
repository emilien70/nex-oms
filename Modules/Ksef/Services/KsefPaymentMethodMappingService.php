<?php

namespace Modules\Ksef\Services;

use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ksef\Enums\KsefPaymentType;
use Modules\Ksef\Models\KsefPaymentMethodMapping;
use Modules\Ksef\Models\KsefSetting;

class KsefPaymentMethodMappingService
{
    public const CASH_ON_DELIVERY_SOURCE_KEY = '**cash_on_delivery**';

    public const CASH_ON_DELIVERY_SOURCE_LABEL = 'Płatność przy odbiorze';

    /**
     * @return Collection<int, array{source_key: string, source_label: string, target_type: ?string}>
     */
    public function methodsForConfiguration(): Collection
    {
        $persisted = KsefPaymentMethodMapping::query()
            ->orderBy('source_label')
            ->get()
            ->keyBy('source_key');

        $rows = collect([
            self::CASH_ON_DELIVERY_SOURCE_KEY => [
                'source_key' => self::CASH_ON_DELIVERY_SOURCE_KEY,
                'source_label' => self::CASH_ON_DELIVERY_SOURCE_LABEL,
                'target_type' => $persisted->get(self::CASH_ON_DELIVERY_SOURCE_KEY)?->target_type?->value,
            ],
        ]);

        $discovered = Order::query()
            ->whereNotNull('payment_method')
            ->distinct()
            ->pluck('payment_method')
            ->map(fn (mixed $method): ?array => is_string($method) ? $this->normalizeSource($method) : null)
            ->filter()
            ->sortBy('source_label', SORT_NATURAL | SORT_FLAG_CASE)
            ->keyBy('source_key');

        $persisted->each(function (KsefPaymentMethodMapping $mapping) use ($rows): void {
            if ($mapping->source_key === self::CASH_ON_DELIVERY_SOURCE_KEY) {
                return;
            }

            $rows->put($mapping->source_key, [
                'source_key' => $mapping->source_key,
                'source_label' => $mapping->source_label,
                'target_type' => $mapping->target_type->value,
            ]);
        });

        $discovered->each(function (array $method, string $sourceKey) use ($persisted, $rows): void {
            if ($sourceKey === self::CASH_ON_DELIVERY_SOURCE_KEY || $rows->has($sourceKey)) {
                return;
            }

            $rows->put($sourceKey, [
                'source_key' => $sourceKey,
                'source_label' => $method['source_label'],
                'target_type' => $persisted->get($sourceKey)?->target_type?->value,
            ]);
        });

        $cod = $rows->pull(self::CASH_ON_DELIVERY_SOURCE_KEY);

        return collect([$cod])->concat(
            $rows->sortBy('source_label', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        );
    }

    /**
     * @param  array<int, array{source_key: mixed, target_type: mixed}>  $mappings
     */
    public function update(KsefPaymentType $defaultType, array $mappings): void
    {
        DB::transaction(function () use ($defaultType, $mappings): void {
            $settings = KsefSetting::query()->firstOrCreate(
                ['singleton_key' => KsefSetting::SINGLETON_KEY],
                ['default_payment_type' => KsefPaymentType::Original],
            );
            $settings = KsefSetting::query()
                ->whereKey($settings->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $allowed = $this->methodsForConfiguration()->keyBy('source_key');
            $seen = [];

            foreach ($mappings as $mapping) {
                $sourceKey = is_string($mapping['source_key'] ?? null) ? $mapping['source_key'] : '';
                if ($sourceKey === '' || isset($seen[$sourceKey]) || ! $allowed->has($sourceKey)) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Lista form płatności ma nieprawidłowy format.',
                    ]);
                }
                $seen[$sourceKey] = true;

                $targetValue = $mapping['target_type'] ?? null;
                if ($targetValue === null || $targetValue === '') {
                    KsefPaymentMethodMapping::query()->where('source_key', $sourceKey)->delete();

                    continue;
                }

                $target = is_string($targetValue) ? KsefPaymentType::tryFrom($targetValue) : null;
                if ($target === null) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Wybierz prawidłowy typ płatności FA(3).',
                    ]);
                }

                KsefPaymentMethodMapping::query()->updateOrCreate(
                    ['source_key' => $sourceKey],
                    [
                        'source_label' => $allowed->get($sourceKey)['source_label'],
                        'target_type' => $target,
                    ],
                );
            }

            $settings->default_payment_type = $defaultType;
            $settings->save();
        });
    }

    /** @return array{version: int, source_key: ?string, source_label: ?string, type: ?string, fa3_code?: string, description?: string} */
    public function resolve(?string $paymentMethod, bool $cashOnDelivery): array
    {
        $source = $cashOnDelivery
            ? [
                'source_key' => self::CASH_ON_DELIVERY_SOURCE_KEY,
                'source_label' => self::CASH_ON_DELIVERY_SOURCE_LABEL,
            ]
            : $this->normalizeSource($paymentMethod);

        if ($source === null) {
            return [
                'version' => 1,
                'source_key' => null,
                'source_label' => null,
                'type' => null,
            ];
        }

        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->lockForUpdate()
            ->first();
        $mapping = KsefPaymentMethodMapping::query()
            ->where('source_key', $source['source_key'])
            ->lockForUpdate()
            ->first();
        $type = $mapping?->target_type;
        if (! $type instanceof KsefPaymentType) {
            $type = $settings?->default_payment_type;
        }
        if (! $type instanceof KsefPaymentType) {
            $type = KsefPaymentType::Original;
        }

        $snapshot = [
            'version' => 1,
            'source_key' => $source['source_key'],
            'source_label' => $source['source_label'],
            'type' => $type->value,
        ];

        if ($type === KsefPaymentType::Original) {
            $snapshot['description'] = $source['source_label'];
        } else {
            $snapshot['fa3_code'] = $type->fa3Code();
        }

        return $snapshot;
    }

    /** @return array{source_key: string, source_label: string}|null */
    public function normalizeSource(?string $source): ?array
    {
        if ($source === null) {
            return null;
        }

        $label = preg_replace('/\s+/u', ' ', trim($source));
        if (! is_string($label) || $label === '') {
            return null;
        }

        return [
            'source_key' => mb_strtolower($label, 'UTF-8'),
            'source_label' => $label,
        ];
    }
}
