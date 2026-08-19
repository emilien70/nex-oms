<?php

namespace Modules\Ksef\Services;

use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ksef\Enums\KsefPaymentSourceKind;
use Modules\Ksef\Enums\KsefPaymentType;
use Modules\Ksef\Models\KsefPaymentMethodMapping;
use Modules\Ksef\Models\KsefSetting;

class KsefPaymentMethodMappingService
{
    public const CASH_ON_DELIVERY_SOURCE_KEY = '**cash_on_delivery**';

    public const CASH_ON_DELIVERY_SOURCE_LABEL = 'Płatność przy odbiorze';

    /**
     * @return Collection<int, array{source_kind: string, source_key: string, source_label: string, target_type: ?string}>
     */
    public function methodsForConfiguration(): Collection
    {
        $persisted = KsefPaymentMethodMapping::query()
            ->orderBy('source_label')
            ->get()
            ->keyBy(fn (KsefPaymentMethodMapping $mapping): string => $this->identity(
                $mapping->source_kind,
                $mapping->source_key,
            ));
        $codKind = KsefPaymentSourceKind::CashOnDelivery->value;
        $codIdentity = $this->identity($codKind, self::CASH_ON_DELIVERY_SOURCE_KEY);

        $rows = collect([
            $codIdentity => [
                'source_kind' => $codKind,
                'source_key' => self::CASH_ON_DELIVERY_SOURCE_KEY,
                'source_label' => self::CASH_ON_DELIVERY_SOURCE_LABEL,
                'target_type' => $persisted->get($codIdentity)?->target_type?->value,
            ],
        ]);

        $discovered = Order::query()
            ->whereNotNull('payment_method')
            ->distinct()
            ->pluck('payment_method')
            ->map(fn (mixed $method): ?array => is_string($method) ? $this->normalizeSource($method) : null)
            ->filter()
            ->sortBy('source_label', SORT_NATURAL | SORT_FLAG_CASE)
            ->keyBy(fn (array $method): string => $this->identity(
                $method['source_kind'],
                $method['source_key'],
            ));

        $persisted->each(function (KsefPaymentMethodMapping $mapping, string $identity) use ($codIdentity, $rows): void {
            if ($identity === $codIdentity) {
                return;
            }

            $rows->put($identity, [
                'source_kind' => $mapping->source_kind->value,
                'source_key' => $mapping->source_key,
                'source_label' => $mapping->source_label,
                'target_type' => $mapping->target_type->value,
            ]);
        });

        $discovered->each(function (array $method, string $identity) use ($persisted, $rows): void {
            if ($rows->has($identity)) {
                return;
            }

            $rows->put($identity, [
                'source_kind' => $method['source_kind'],
                'source_key' => $method['source_key'],
                'source_label' => $method['source_label'],
                'target_type' => $persisted->get($identity)?->target_type?->value,
            ]);
        });

        $cod = $rows->pull($codIdentity);

        return collect([$cod])->concat(
            $rows->sortBy('source_label', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        );
    }

    /**
     * @param  array<int, array{source_kind: mixed, source_key: mixed, target_type: mixed}>  $mappings
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

            $allowed = $this->methodsForConfiguration()->keyBy(fn (array $mapping): string => $this->identity(
                $mapping['source_kind'],
                $mapping['source_key'],
            ));
            $seen = [];

            foreach ($mappings as $mapping) {
                $sourceKindValue = is_string($mapping['source_kind'] ?? null) ? $mapping['source_kind'] : '';
                $sourceKind = KsefPaymentSourceKind::tryFrom($sourceKindValue);
                $sourceKey = is_string($mapping['source_key'] ?? null) ? $mapping['source_key'] : '';
                $identity = $sourceKind !== null ? $this->identity($sourceKind, $sourceKey) : '';
                if ($sourceKind === null || $sourceKey === '' || isset($seen[$identity]) || ! $allowed->has($identity)) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Lista form płatności ma nieprawidłowy format.',
                    ]);
                }
                $seen[$identity] = true;

                $targetValue = $mapping['target_type'] ?? null;
                if ($targetValue === null || $targetValue === '') {
                    KsefPaymentMethodMapping::query()
                        ->where('source_kind', $sourceKind->value)
                        ->where('source_key', $sourceKey)
                        ->delete();

                    continue;
                }

                $target = is_string($targetValue) ? KsefPaymentType::tryFrom($targetValue) : null;
                if ($target === null) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Wybierz prawidłowy typ płatności FA(3).',
                    ]);
                }

                KsefPaymentMethodMapping::query()->updateOrCreate(
                    [
                        'source_kind' => $sourceKind->value,
                        'source_key' => $sourceKey,
                    ],
                    [
                        'source_label' => $allowed->get($identity)['source_label'],
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
                'source_kind' => KsefPaymentSourceKind::CashOnDelivery->value,
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
            ->where('source_kind', $source['source_kind'])
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

    /** @return array{source_kind: string, source_key: string, source_label: string}|null */
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
            'source_kind' => KsefPaymentSourceKind::PaymentMethod->value,
            'source_key' => mb_strtolower($label, 'UTF-8'),
            'source_label' => $label,
        ];
    }

    private function identity(KsefPaymentSourceKind|string $sourceKind, string $sourceKey): string
    {
        $kind = $sourceKind instanceof KsefPaymentSourceKind ? $sourceKind->value : $sourceKind;

        return $kind."\0".$sourceKey;
    }
}
