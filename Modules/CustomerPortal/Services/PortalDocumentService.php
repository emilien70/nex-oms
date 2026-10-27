<?php

namespace Modules\CustomerPortal\Services;

use Modules\CustomerPortal\Models\PortalSetting;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Invoices\Services\InvoicePdfService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefAcceptedOfflineInvoicePdfService;
use Modules\Ksef\Services\KsefPdfDocumentPresenter;

class PortalDocumentService
{
    public function __construct(
        private readonly InvoicePdfService $standard,
        private readonly InvoicePdfRenderer $renderer,
        private readonly KsefPdfDocumentPresenter $presenter,
        private readonly KsefAcceptedOfflineInvoicePdfService $offline,
    ) {}

    public function available(Invoice $invoice, PortalSetting $settings, bool $preview = false): bool
    {
        if (! $invoice->isIssued() || blank($invoice->number)) {
            return false;
        }
        if ($invoice->isProforma()) {
            return $settings->show_proformas && ! $invoice->isProformaSuperseded();
        }
        if ($invoice->isInvoice() && ! $settings->show_invoices) {
            return false;
        }
        if ($invoice->isCorrection() && (! $settings->show_corrections || ! $invoice->isFinalized()
            || $invoice->correctedInvoice?->order_id !== $invoice->order_id)) {
            return false;
        }
        if (! $invoice->isInvoice() && ! $invoice->isCorrection()) {
            return false;
        }
        if (! $this->usesKsef($invoice)) {
            return true;
        }
        $environment = KsefSetting::query()->where('singleton_key', KsefSetting::SINGLETON_KEY)->first()?->environment;
        if (! $environment || (! $preview && $environment !== KsefEnvironment::Production)) {
            return false;
        }
        $accepted = $invoice->ksefSubmissions()->where('environment', $environment->value)
            ->where('status', KsefInvoiceSubmissionStatus::Accepted->value)->latest('id')->first();
        if (! $accepted || blank($accepted->ksef_number)) {
            return false;
        }
        $issuances = $invoice->ksefOfflineIssuances()->get();
        if ($issuances->isNotEmpty()) {
            return $issuances->count() === 1 && $accepted->offline_issuance_id === $issuances->first()->id;
        }
        try {
            $presentation = $this->presenter->present($invoice);

            return is_array($presentation) && empty($presentation['preview_warning'])
                && ($preview || empty($presentation['test_mark']));
        } catch (InvoiceDomainException) {
            return false;
        }
    }

    public function contents(Invoice $invoice, PortalSetting $settings, bool $preview = false): string
    {
        abort_unless($this->available($invoice, $settings, $preview), 404);
        $issuance = $invoice->ksefOfflineIssuances()->first();
        if ($issuance) {
            $environment = KsefSetting::query()->where('singleton_key', KsefSetting::SINGLETON_KEY)->firstOrFail()->environment;
            $accepted = $invoice->ksefSubmissions()->where('environment', $environment->value)
                ->where('status', KsefInvoiceSubmissionStatus::Accepted->value)->latest('id')->firstOrFail();

            return $this->offline->document($invoice, $issuance, $accepted)['contents'];
        }

        return $this->usesKsef($invoice) ? $this->renderer->render($invoice) : $this->standard->contents($invoice);
    }

    private function usesKsef(Invoice $invoice): bool
    {
        $documents = [$invoice];
        if ($invoice->isCorrection() && $invoice->correctedInvoice) {
            $documents[] = $invoice->correctedInvoice;
        }
        foreach ($documents as $document) {
            if ($document->ksefSubmissions()->exists() || $document->ksefOfflineIssuances()->exists()
                || KsefSeriesSetting::query()->where('invoice_series_id', $document->invoice_series_id)->where('is_enabled', true)->exists()) {
                return true;
            }
        }

        return false;
    }
}
