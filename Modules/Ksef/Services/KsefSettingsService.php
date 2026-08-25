<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefPaymentType;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\ValueObjects\KsefCertificateMaterial;

class KsefSettingsService
{
    public function __construct(
        private readonly KsefCertificateMaterialService $certificateMaterialService,
    ) {}

    private const RUNTIME_AUTH_AND_TEST_STATE = [
        'access_token' => null,
        'access_token_valid_until' => null,
        'refresh_token' => null,
        'refresh_token_valid_until' => null,
        'last_tested_at' => null,
        'last_test_status' => null,
        'last_test_message' => null,
        'last_test_invoice_write' => null,
        'last_system_warning' => null,
    ];

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
        'include_seller_vat_prefix',
        'zero_vat_classification',
        'default_split_payment',
    ];

    public function get(): KsefSetting
    {
        return KsefSetting::query()->firstOrCreate(
            ['singleton_key' => KsefSetting::SINGLETON_KEY],
            $this->defaults(),
        );
    }

    public function getExisting(): KsefSetting
    {
        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->first();

        if ($settings === null) {
            throw new InvoiceDomainException(
                'ksef_configuration_missing',
                'Konfiguracja KSeF nie istnieje.',
            );
        }

        return $settings;
    }

    public function update(array $data, ?KsefCertificateMaterial $certificateMaterial = null): KsefSetting
    {
        return DB::transaction(function () use ($data, $certificateMaterial): KsefSetting {
            $this->get();

            $settings = KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $contextNipChanged = $settings->context_nip !== $data['context_nip'];
            $settings->fill(collect($data)->only(self::SETTINGS_FIELDS)->all());
            $settings->save();

            if ($contextNipChanged) {
                KsefCredential::query()->update(self::RUNTIME_AUTH_AND_TEST_STATE);
            }

            $environment = KsefEnvironment::from($data['environment']);
            $credential = KsefCredential::query()->firstOrNew([
                'environment' => $environment->value,
            ]);
            $authenticationMethod = KsefAuthenticationMethod::from($data['authentication_method']);
            $authenticationMethodChanged = $credential->exists
                && $credential->authentication_method !== $authenticationMethod;
            $credential->authentication_method = $authenticationMethod;
            $runtimeMustBeInvalidated = $authenticationMethodChanged;

            if (filled($data['api_token'] ?? null)) {
                $credential->api_token = $data['api_token'];
                $runtimeMustBeInvalidated = true;
            }

            if ($certificateMaterial !== null) {
                $credential->authentication_certificate = $certificateMaterial->certificatePem;
                $credential->authentication_private_key = $certificateMaterial->privateKeyPem;
                $runtimeMustBeInvalidated = true;
            }

            if ($runtimeMustBeInvalidated) {
                $credential->forceFill(self::RUNTIME_AUTH_AND_TEST_STATE);
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
            ->map(fn (KsefEnvironment $environment): string => $environment->value)
            ->all();

        return collect(KsefEnvironment::cases())
            ->mapWithKeys(fn (KsefEnvironment $environment): array => [
                $environment->value => in_array($environment->value, $configured, true),
            ])
            ->all();
    }

    public function certificateConfiguredByEnvironment(): array
    {
        $configured = KsefCredential::query()
            ->whereNotNull('authentication_certificate')
            ->whereNotNull('authentication_private_key')
            ->pluck('environment')
            ->map(fn (KsefEnvironment $environment): string => $environment->value)
            ->all();

        return collect(KsefEnvironment::cases())
            ->mapWithKeys(fn (KsefEnvironment $environment): array => [
                $environment->value => in_array($environment->value, $configured, true),
            ])
            ->all();
    }

    public function authenticationMethodByEnvironment(): array
    {
        $methods = KsefCredential::query()
            ->get(['environment', 'authentication_method'])
            ->mapWithKeys(fn (KsefCredential $credential): array => [
                $credential->environment->value => $credential->authentication_method->value,
            ]);

        return collect(KsefEnvironment::cases())
            ->mapWithKeys(fn (KsefEnvironment $environment): array => [
                $environment->value => $methods->get(
                    $environment->value,
                    KsefAuthenticationMethod::Token->value,
                ),
            ])
            ->all();
    }

    public function certificateMetadataByEnvironment(): array
    {
        $metadata = KsefCredential::query()
            ->whereNotNull('authentication_certificate')
            ->get(['environment', 'authentication_certificate'])
            ->mapWithKeys(function (KsefCredential $credential): array {
                $certificate = $credential->authentication_certificate;

                return [$credential->environment->value => is_string($certificate)
                    ? $this->certificateMaterialService->metadata($certificate)
                    : null];
            });

        return collect(KsefEnvironment::cases())
            ->mapWithKeys(fn (KsefEnvironment $environment): array => [
                $environment->value => $metadata->get($environment->value),
            ])
            ->all();
    }

    public function connectionStatusByEnvironment(): array
    {
        $statuses = KsefCredential::query()
            ->get([
                'environment',
                'last_tested_at',
                'last_test_status',
                'last_test_message',
                'last_system_warning',
            ])
            ->keyBy(fn (KsefCredential $credential): string => $credential->environment->value);

        return collect(KsefEnvironment::cases())
            ->mapWithKeys(function (KsefEnvironment $environment) use ($statuses): array {
                /** @var KsefCredential|null $credential */
                $credential = $statuses->get($environment->value);

                return [$environment->value => [
                    'tested_at' => $credential?->last_tested_at?->format('d.m.Y H:i'),
                    'status' => $credential?->last_test_status?->value,
                    'message' => $credential?->last_test_message,
                    'system_warning' => $credential?->last_system_warning,
                ]];
            })
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
            'include_seller_vat_prefix' => false,
            'zero_vat_classification' => KsefZeroVatClassification::Wdt,
            'default_split_payment' => false,
            'default_payment_type' => KsefPaymentType::Original,
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
