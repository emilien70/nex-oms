<?php

namespace Modules\Integrations\Allegro\Services;

use Illuminate\Support\Arr;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefAcceptedOfflineInvoicePdfService;
use Modules\Ksef\Services\KsefPdfDocumentPresenter;

class AllegroInvoiceDocumentService
{
    public const MAX_FILE_BYTES = 3_000_000;

    public function __construct(
        private readonly InvoicePdfRenderer $pdfs,
        private readonly KsefPdfDocumentPresenter $presenter,
        private readonly KsefAcceptedOfflineInvoicePdfService $offlinePdfs,
    ) {}

    public function waitingReason(Invoice $invoice, AllegroAccount $account): ?string
    {
        $setting = KsefSetting::query()->where('singleton_key', KsefSetting::SINGLETON_KEY)->first();
        $hasKsef = $invoice->ksefSubmissions()->exists() || $invoice->ksefOfflineIssuances()->exists()
            || KsefSeriesSetting::query()->where('invoice_series_id', $invoice->invoice_series_id)->where('is_enabled', true)->exists();
        if ($invoice->isCorrection() && ($root = $invoice->correctedInvoice()->first())) {
            $hasKsef = $hasKsef || $root->ksefSubmissions()->exists() || $root->ksefOfflineIssuances()->exists()
                || KsefSeriesSetting::query()->where('invoice_series_id', $root->invoice_series_id)->where('is_enabled', true)->exists();
        }
        if (! $hasKsef) {
            return null;
        }
        if (! $setting || ($account->environment === 'production' && $setting->environment !== KsefEnvironment::Production)) {
            return 'Oczekuje na produkcyjny dokument KSeF';
        }
        $submission = $this->accepted($invoice, $setting->environment);
        if (! $submission) {
            return 'Oczekuje na przyjęcie faktury w KSeF';
        }
        if (trim((string) $submission->ksef_number) === '') {
            return 'Oczekuje na kompletny dokument KSeF';
        }

        return null;
    }

    public function contents(Invoice $invoice, AllegroAccount $account): string
    {
        if ((! $invoice->isInvoice() && ! ($invoice->isCorrection() && $invoice->isFinalized()))
            || ! $invoice->isIssued() || trim((string) $invoice->number) === '') {
            throw new AllegroApiException('Przekazać można tylko wystawioną Fakturę VAT albo zamkniętą Korektę z numerem.', 422);
        }
        if ($reason = $this->waitingReason($invoice, $account)) {
            throw new AllegroApiException($reason, 422);
        }
        $issuance = KsefOfflineIssuance::query()->where('invoice_id', $invoice->id)->first();
        if ($issuance) {
            $environment = KsefSetting::query()->where('singleton_key', KsefSetting::SINGLETON_KEY)->firstOrFail()->environment;
            $submission = $this->accepted($invoice, $environment);
            if (! $submission || $submission->offline_issuance_id !== $issuance->id) {
                throw new AllegroApiException('Brak finalnej wizualizacji tej Faktury Offline.', 422);
            }
            $contents = $this->offlinePdfs->document($invoice, $issuance, $submission)['contents'];
        } else {
            $presentation = $this->presenter->present($invoice);
            if (is_array($presentation) && (! empty($presentation['preview_warning'])
                || ($account->environment === 'production' && ! empty($presentation['test_mark'])))) {
                throw new AllegroApiException('Podgląd lub dokument testowy KSeF nie może zostać przekazany do tego zamówienia.', 422);
            }
            // Render the current final presentation; never reuse a cached pre-acceptance preview.
            $contents = $this->pdfs->render($invoice);
        }
        if (! str_starts_with($contents, '%PDF-') || strlen($contents) > self::MAX_FILE_BYTES) {
            throw new AllegroApiException('Faktura musi być poprawnym plikiem PDF o rozmiarze nie większym niż 3 MB.', 422);
        }

        return $contents;
    }

    public function hash(Invoice $invoice): string
    {
        $excluded = ['created_at', 'updated_at', 'lock_version', 'finalized_at'];
        $data = Arr::except($invoice->getAttributes(), $excluded);
        ksort($data);
        $items = $invoice->items()->orderBy('position')->orderBy('id')->get()->map(function ($item) use ($excluded): array {
            $attributes = Arr::except($item->getAttributes(), $excluded);
            ksort($attributes);

            return $attributes;
        })->all();

        return hash('sha256', json_encode([$data, $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function accepted(Invoice $invoice, KsefEnvironment $environment): ?KsefInvoiceSubmission
    {
        return $invoice->ksefSubmissions()->where('environment', $environment->value)
            ->where('status', KsefInvoiceSubmissionStatus::Accepted->value)->latest('id')->first();
    }
}
