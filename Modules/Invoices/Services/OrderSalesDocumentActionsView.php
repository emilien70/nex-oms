<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Illuminate\Support\Collection;
use Modules\Integrations\Allegro\Services\AllegroInvoiceExportView;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefAutomaticInvoiceSubmissionPolicy;
use Modules\Ksef\Services\KsefInvoiceVerificationLinkBuilder;
use Modules\Ksef\Services\KsefOperationalEnvironmentPolicy;

class OrderSalesDocumentActionsView
{
    public function __construct(
        private readonly KsefOperationalEnvironmentPolicy $ksefEnvironments,
        private readonly KsefInvoiceVerificationLinkBuilder $ksefVerificationLinks,
        private readonly KsefAutomaticInvoiceSubmissionPolicy $automaticSubmissions,
    ) {}

    /**
     * @return array{
     *     issuedInvoice: ?Invoice,
     *     issuedProforma: ?Invoice,
     *     issuedCorrection: ?Invoice,
     *     finalizedCorrections: Collection<int, Invoice>,
     *     correctionKsefStates: Collection<int, array<string, mixed>>,
     *     proformaLocked: bool,
     *     invoiceSeries: Collection<int, InvoiceSeries>,
     *     proformaSeries: Collection<int, InvoiceSeries>,
     *     ksefSeriesEnabled: bool,
     *     ksefHasSubmission: bool,
     *     ksefCanSend: bool,
     *     ksefAutomaticRefreshPending: bool,
     *     ksefSubmission: ?KsefInvoiceSubmission,
     *     ksefPdfDownloadAvailable: bool,
     *     ksefVerificationUrl: ?string,
     *     ksefPdfFilename: ?string
     * }
     */
    public function data(Order $order): array
    {
        $documents = Invoice::query()
            ->where('order_id', $order->getKey())
            ->where('status', InvoiceDocumentStatus::Issued)
            ->whereIn('document_type', [
                InvoiceDocumentType::Invoice,
                InvoiceDocumentType::Proforma,
                InvoiceDocumentType::Correction,
            ])
            ->with('series')
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Invoice $invoice): string => $invoice->document_type->value);
        $corrections = $documents->get(InvoiceDocumentType::Correction->value, collect());
        $issuedInvoice = $documents->get(InvoiceDocumentType::Invoice->value, collect())->first();
        $issuedProforma = $documents->get(InvoiceDocumentType::Proforma->value, collect())->first();
        $ksefDocuments = collect([$issuedInvoice])->filter()->merge($corrections);
        $settings = $ksefDocuments->isEmpty() ? null : KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->first();
        $enabledSeriesIds = $ksefDocuments->isEmpty() ? collect() : KsefSeriesSetting::query()
            ->whereIn('invoice_series_id', $ksefDocuments->pluck('invoice_series_id'))
            ->where('is_enabled', true)
            ->pluck('invoice_series_id');
        $submissions = $ksefDocuments->isEmpty() ? collect() : KsefInvoiceSubmission::query()
            ->whereIn('invoice_id', $ksefDocuments->pluck('id'))
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->get(['id', 'invoice_id', 'environment', 'attempt_number', 'status',
                'ksef_number', 'acquisition_date', 'seller_nip', 'invoice_hash'])
            ->groupBy('invoice_id');
        $offlineCorrectionIds = $settings === null || $corrections->isEmpty() ? collect() : KsefOfflineIssuance::query()
            ->whereIn('invoice_id', $corrections->modelKeys())
            ->where('environment', $settings->environment->value)
            ->pluck('invoice_id');
        $ksefState = $this->ksefState(
            $issuedInvoice, $settings, $enabledSeriesIds,
            $submissions->get($issuedInvoice?->id, collect()),
        );
        $correctionKsefStates = $corrections->mapWithKeys(fn (Invoice $correction): array => [
            $correction->id => $this->ksefState(
                $correction, $settings, $enabledSeriesIds,
                $submissions->get($correction->id, collect()),
                $offlineCorrectionIds->contains($correction->id),
            ),
        ]);

        $series = InvoiceSeries::query()
            ->where('is_active', true)
            ->whereIn('document_type', [
                InvoiceDocumentType::Invoice,
                InvoiceDocumentType::Proforma,
            ])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (InvoiceSeries $item): string => $item->document_type->value);

        return [
            'issuedInvoice' => $issuedInvoice,
            'allegroInvoice' => app(AllegroInvoiceExportView::class)->data($order, $issuedInvoice),
            'allegroCorrections' => $order->source === 'allegro' ? $corrections
                ->filter(fn (Invoice $correction): bool => $correction->isFinalized())
                ->mapWithKeys(fn (Invoice $correction): array => [$correction->id => app(AllegroInvoiceExportView::class)->data($order, $correction)])
                ->all() : [],
            'issuedProforma' => $issuedProforma,
            'issuedCorrection' => $corrections->first(
                static fn (Invoice $correction): bool => ! $correction->isFinalized(),
            ),
            'correctionKsefStates' => $correctionKsefStates,
            'finalizedCorrections' => $corrections
                ->filter(static fn (Invoice $correction): bool => $correction->isFinalized())
                ->sortByDesc(fn (Invoice $correction): string => sprintf(
                    '%020d:%020d',
                    $correction->issued_at?->getTimestamp() ?? 0,
                    $correction->getKey(),
                ))
                ->values(),
            'proformaLocked' => (bool) $issuedProforma?->isProformaSuperseded(),
            'invoiceSeries' => $series->get(InvoiceDocumentType::Invoice->value, collect()),
            'proformaSeries' => $series->get(InvoiceDocumentType::Proforma->value, collect()),
            'ksefSeriesEnabled' => $ksefState['seriesEnabled'],
            'ksefHasSubmission' => $ksefState['hasSubmission'],
            'ksefCanSend' => $ksefState['canSend'],
            'ksefAutomaticRefreshPending' => ($issuedInvoice !== null
                && ($this->automaticSubmissions->snapshotFor($issuedInvoice) !== null
                    || ($ksefState['submission'] !== null
                        && ! $ksefState['submission']->status->isTerminal())))
                || $correctionKsefStates->contains(fn (array $state): bool => $state['submission'] !== null
                    && ! $state['submission']->status->isTerminal()),
            'ksefSubmission' => $ksefState['submission'],
            'ksefPdfDownloadAvailable' => $ksefState['pdfDownloadAvailable'],
            'ksefVerificationUrl' => $ksefState['verificationUrl'],
            'ksefPdfFilename' => $ksefState['pdfFilename'],
        ];
    }

    public function render(Order $order): string
    {
        return view('orders.partials.sales-document-actions', [
            'order' => $order,
            'salesDocumentActions' => $this->data($order),
        ])->render();
    }

    /** @return array<string, mixed> */
    private function ksefState(
        ?Invoice $invoice,
        ?KsefSetting $settings,
        Collection $enabledSeriesIds,
        Collection $submissions,
        bool $hasOfflineIssuance = false,
    ): array {
        $state = [
            'seriesEnabled' => false,
            'hasSubmission' => false,
            'canSend' => false,
            'submission' => null,
            'pdfDownloadAvailable' => false,
            'verificationUrl' => null,
            'pdfFilename' => null,
            'environment' => $settings?->environment,
            'hasHistory' => $submissions->isNotEmpty(),
            'hasOfflineIssuance' => $hasOfflineIssuance,
        ];

        if ($invoice === null || $invoice->series === null) {
            return $state;
        }

        if ($settings !== null) {
            $state['submission'] = $submissions->first(
                fn (KsefInvoiceSubmission $submission): bool => $submission->environment === $settings->environment,
            );
            $state['hasSubmission'] = $state['submission'] !== null;
        }

        $state['seriesEnabled'] = $enabledSeriesIds->contains($invoice->invoice_series_id);

        $state['canSend'] = $settings !== null
            && $state['seriesEnabled']
            && ! $state['hasSubmission']
            && ($invoice->isInvoice() || $invoice->isCorrection())
            && ! $hasOfflineIssuance
            && $invoice->isIssued()
            && is_string($invoice->number)
            && trim($invoice->number) !== ''
            && $invoice->sequence_number !== null
            && is_string($invoice->numbering_period_key)
            && trim($invoice->numbering_period_key) !== ''
            && $settings->is_active
            && config('ksef.invoice_submission_enabled') === true
            && $this->ksefEnvironments->allows($settings->environment);

        $submission = $state['submission'];
        $state['pdfDownloadAvailable'] = $submission?->status === KsefInvoiceSubmissionStatus::Accepted
            && is_string($submission->ksef_number)
            && trim($submission->ksef_number) !== '';

        if ($state['pdfDownloadAvailable']) {
            if ($invoice->issue_date !== null) {
                $state['verificationUrl'] = $this->ksefVerificationLinks->build(
                    $submission,
                    $invoice->issue_date,
                );
            }

            $number = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $invoice->number);
            $state['pdfFilename'] = 'KSeF_'.trim((string) $number, '_').'.pdf';
        }

        return $state;
    }
}
