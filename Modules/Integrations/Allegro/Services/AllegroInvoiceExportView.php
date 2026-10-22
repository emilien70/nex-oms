<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\Order;
use Modules\Integrations\Allegro\Models\AllegroInvoiceExport;
use Modules\Invoices\Models\Invoice;

class AllegroInvoiceExportView
{
    public function __construct(
        private readonly AllegroInvoiceDocumentService $documents,
        private readonly AllegroInvoiceExportService $exports,
    ) {}

    public function data(Order $order, ?Invoice $invoice): ?array
    {
        if ($order->source !== 'allegro') {
            return null;
        }
        $link = $order->allegroOrderLink()->with('account')->first();
        $export = $invoice ? AllegroInvoiceExport::query()->where('order_id', $order->id)->where('invoice_id', $invoice->id)->latest('id')->first() : null;
        $historical = ! $invoice?->isCorrection() ? AllegroInvoiceExport::query()->where('order_id', $order->id)
            ->where('document_type', 'invoice')->whereNull('invoice_id')->whereNotNull('upload_started_at')->latest('id')->first() : null;
        $connected = $link?->account?->isOperational() && $link->environment === $link->account->environment && $link->seller_user_id === $link->account->allegro_user_id;
        $canSend = $connected && $invoice && $this->exports->eligible($invoice, $link);
        $changed = $export?->document_hash && $invoice && $export->document_hash !== $this->documents->hash($invoice);
        $sameIdentity = $export && $link && $export->allegro_account_id === $link->allegro_account_id
            && $export->environment === $link->environment && $export->seller_user_id === $link->seller_user_id;

        return ['export' => $export, 'historical' => $historical, 'changed' => (bool) $changed,
            'sourceWarning' => $invoice?->isCorrection() && $link && ! $this->exports->sourceConfirmed($invoice, $link),
            'canSend' => $canSend && ! $changed && ! $export,
            'canRetry' => $connected && $sameIdentity
                && in_array($export->state, ['waiting', 'failed', 'skipped', 'uncertain', 'verifying'], true)
                && ($export->upload_started_at !== null || ($canSend && ! $changed))];
    }
}
