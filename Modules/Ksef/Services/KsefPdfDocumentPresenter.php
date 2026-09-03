<?php

namespace Modules\Ksef\Services;

use Illuminate\Contracts\Foundation\Application;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;

class KsefPdfDocumentPresenter
{
    public function __construct(
        private readonly Application $app,
        private readonly KsefInvoiceVerificationLinkBuilder $verificationLinks,
        private readonly KsefNumberValidator $ksefNumbers,
    ) {}

    /**
     * @return array{
     *     environment: string,
     *     number: ?string,
     *     processed_at: ?string,
     *     status: ?string,
     *     verification_url: ?string,
     *     test_mark: ?string,
     *     preview_warning: ?string
     * }|null
     */
    public function present(Invoice $document): ?array
    {
        if ($document->isProforma()) {
            return null;
        }

        $environment = $this->environment();

        if ($environment === null) {
            return null;
        }

        $accepted = $this->submission($document, $environment, acceptedOnly: true);

        if ($accepted !== null) {
            return $this->accepted($document, $accepted, $environment);
        }

        $latest = $this->submission($document, $environment);

        if ($latest === null && ! $this->seriesUsesKsef($document)) {
            return null;
        }

        return [
            'environment' => $environment->value,
            'number' => null,
            'processed_at' => null,
            'status' => $latest?->status->label(),
            'verification_url' => null,
            'test_mark' => null,
            'preview_warning' => $this->previewWarning($latest?->status),
        ];
    }

    private function environment(): ?KsefEnvironment
    {
        if ($this->app->environment('production')) {
            return KsefEnvironment::Production;
        }

        return KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->first(['environment'])
            ?->environment;
    }

    private function submission(
        Invoice $document,
        KsefEnvironment $environment,
        bool $acceptedOnly = false,
    ): ?KsefInvoiceSubmission {
        return $document->ksefSubmissions()
            ->where('environment', $environment->value)
            ->when(
                $acceptedOnly,
                fn ($query) => $query->where('status', KsefInvoiceSubmissionStatus::Accepted->value),
            )
            ->latest('id')
            ->first();
    }

    /**
     * @return array{
     *     environment: string,
     *     number: string,
     *     processed_at: string,
     *     status: string,
     *     verification_url: string,
     *     test_mark: ?string,
     *     preview_warning: null
     * }
     */
    private function accepted(
        Invoice $document,
        KsefInvoiceSubmission $submission,
        KsefEnvironment $environment,
    ): array {
        if ($submission->invoicing_mode === KsefInvoicingMode::Offline) {
            throw new InvoiceDomainException(
                'invoice_pdf_ksef_unexpected_offline_mode',
                'KSeF zakwalifikował dokument jako Offline. Finalna wizualizacja wymaga obsługi trybu Offline i nie może zostać wygenerowana przez ścieżkę Online.',
            );
        }

        $number = trim((string) $submission->ksef_number);
        $sellerNip = trim((string) $submission->seller_nip);
        $verificationUrl = $document->issue_date !== null
            ? $this->verificationLinks->build($submission, $document->issue_date)
            : null;

        if ($submission->status !== KsefInvoiceSubmissionStatus::Accepted
            || $submission->environment !== $environment
            || ! $this->ksefNumbers->isValid($number)
            || preg_match('/^\d{10}$/', $sellerNip) !== 1
            || ! str_starts_with($number, $sellerNip.'-')
            || $submission->acquisition_date === null
            || ! is_string($verificationUrl)
            || $verificationUrl === '') {
            throw new InvoiceDomainException(
                'invoice_pdf_ksef_verification_invalid',
                'Nie można wygenerować finalnego PDF, ponieważ dane weryfikacyjne KSeF są niekompletne lub niespójne.',
            );
        }

        return [
            'environment' => $environment->value,
            'number' => $number,
            'processed_at' => $submission->acquisition_date->format('d.m.Y H:i:s'),
            'status' => $submission->status->label(),
            'verification_url' => $verificationUrl,
            'test_mark' => match ($environment) {
                KsefEnvironment::Test => 'KSeF TEST — DOKUMENT TESTOWY',
                KsefEnvironment::Demo => 'KSeF DEMO — DOKUMENT TESTOWY',
                KsefEnvironment::Production => null,
            },
            'preview_warning' => null,
        ];
    }

    private function seriesUsesKsef(Invoice $document): bool
    {
        return $document->invoice_series_id !== null
            && KsefSeriesSetting::query()
                ->where('invoice_series_id', $document->invoice_series_id)
                ->where('is_enabled', true)
                ->exists();
    }

    private function previewWarning(?KsefInvoiceSubmissionStatus $status): string
    {
        return match ($status) {
            KsefInvoiceSubmissionStatus::Uncertain => 'Wynik transmisji dokumentu do KSeF pozostaje nierozstrzygnięty. Nie przekazywać nabywcy.',
            KsefInvoiceSubmissionStatus::Rejected => 'Dokument został odrzucony przez KSeF. Nie przekazywać nabywcy.',
            KsefInvoiceSubmissionStatus::TechnicalFailed => 'Wystąpił błąd transmisji dokumentu do KSeF. Nie przekazywać nabywcy.',
            default => 'Dokument oczekuje na przyjęcie do KSeF. Nie przekazywać nabywcy.',
        };
    }
}
