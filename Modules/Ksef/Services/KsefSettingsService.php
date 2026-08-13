<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;

class KsefSettingsService
{
    private const SETTINGS_FIELDS = [
        'name',
        'environment',
        'context_nip',
        'is_active',
        'automatic_submission',
        'send_without_buyer_nip',
        'include_recipient_data',
        'include_buyer_contact_data',
        'include_additional_information',
        'include_order_reference',
        'include_bank_account',
        'include_gtu',
        'zero_vat_classification',
    ];

    public function get(): KsefSetting
    {
        return KsefSetting::query()->firstOrCreate(
            ['singleton_key' => KsefSetting::SINGLETON_KEY],
            $this->defaults(),
        );
    }

    public function update(array $data): KsefSetting
    {
        return DB::transaction(function () use ($data): KsefSetting {
            $this->get();

            $settings = KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $settings->fill(collect($data)->only(self::SETTINGS_FIELDS)->all());
            $settings->save();

            $environment = KsefEnvironment::from($data['environment']);
            $credential = KsefCredential::query()->firstOrNew([
                'environment' => $environment->value,
            ]);
            $credential->authentication_method = KsefAuthenticationMethod::Token;

            if (filled($data['api_token'] ?? null)) {
                $credential->api_token = $data['api_token'];
            }

            $credential->save();

            return $settings->refresh();
        });
    }

    public function tokenConfiguredByEnvironment(): array
    {
        $configured = KsefCredential::query()
            ->whereNotNull('api_token')
            ->pluck('environment')
            ->all();

        return collect(KsefEnvironment::cases())
            ->mapWithKeys(fn (KsefEnvironment $environment): array => [
                $environment->value => in_array($environment->value, $configured, true),
            ])
            ->all();
    }

    public function seriesForConfiguration(): Collection
    {
        $enabledIds = KsefSeriesSetting::query()
            ->where('is_enabled', true)
            ->pluck('invoice_series_id')
            ->all();

        return InvoiceSeries::query()
            ->where('is_active', true)
            ->whereIn('document_type', $this->supportedDocumentTypes())
            ->orderByRaw("CASE document_type WHEN 'invoice' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'document_type'])
            ->map(fn (InvoiceSeries $series): array => [
                'id' => $series->getKey(),
                'name' => $series->name,
                'document_type' => $series->document_type,
                'document_type_label' => $series->document_type === InvoiceDocumentType::Invoice
                    ? 'Faktura VAT'
                    : 'Korekta',
                'is_enabled' => in_array($series->getKey(), $enabledIds, true),
            ]);
    }

    public function updateSeries(array $seriesIds): void
    {
        DB::transaction(function () use ($seriesIds): void {
            $ids = collect($seriesIds)->map(function (mixed $id): int {
                if (! is_int($id) && ! is_string($id)) {
                    $this->throwInvalidSeriesSelection();
                }

                $normalized = filter_var($id, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);

                if ($normalized === false) {
                    $this->throwInvalidSeriesSelection();
                }

                return $normalized;
            });

            if ($ids->uniqueStrict()->count() !== $ids->count()) {
                $this->throwInvalidSeriesSelection();
            }

            $selected = InvoiceSeries::query()
                ->whereIntegerInRaw('id', $ids->all())
                ->lockForUpdate()
                ->get(['id', 'document_type', 'is_active']);

            if ($selected->count() !== $ids->count()
                || $selected->contains(fn (InvoiceSeries $series): bool => ! $series->is_active
                    || ! in_array($series->document_type->value, $this->supportedDocumentTypes(), true))) {
                throw ValidationException::withMessages([
                    'series_ids' => 'Do KSeF można przypisać wyłącznie aktywną serię Faktur VAT albo Korekt.',
                ]);
            }

            $eligibleIds = InvoiceSeries::query()
                ->whereIn('document_type', $this->supportedDocumentTypes())
                ->lockForUpdate()
                ->pluck('id');
            $selectedLookup = $ids->flip();
            $timestamp = now();

            $rows = $eligibleIds->map(fn (int $seriesId): array => [
                'invoice_series_id' => $seriesId,
                'is_enabled' => $selectedLookup->has($seriesId),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->all();

            if ($rows !== []) {
                KsefSeriesSetting::query()->upsert(
                    $rows,
                    ['invoice_series_id'],
                    ['is_enabled', 'updated_at'],
                );
            }

            KsefSeriesSetting::query()
                ->whereNotIn('invoice_series_id', $eligibleIds->all())
                ->delete();
        });
    }

    private function defaults(): array
    {
        return [
            'name' => 'KSeF',
            'environment' => KsefEnvironment::Test,
            'context_nip' => null,
            'is_active' => false,
            'automatic_submission' => false,
            'send_without_buyer_nip' => false,
            'include_recipient_data' => false,
            'include_buyer_contact_data' => false,
            'include_additional_information' => false,
            'include_order_reference' => true,
            'include_bank_account' => true,
            'include_gtu' => true,
            'zero_vat_classification' => KsefZeroVatClassification::Wdt,
        ];
    }

    private function supportedDocumentTypes(): array
    {
        return [
            InvoiceDocumentType::Invoice->value,
            InvoiceDocumentType::Correction->value,
        ];
    }

    private function throwInvalidSeriesSelection(): never
    {
        throw ValidationException::withMessages([
            'series_ids' => 'Ustawienia serii mają nieprawidłowy format.',
        ]);
    }
}
