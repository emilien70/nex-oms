<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Enums\AllegroInvoiceSyncMode;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\ExportAllegroInvoiceJob;
use Modules\Integrations\Allegro\Models\AllegroInvoiceExport;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Throwable;

class AllegroInvoiceExportService
{
    public function __construct(
        private readonly AllegroClient $client,
        private readonly AllegroInvoiceDocumentService $documents,
        private readonly CorrectionSourceStateService $corrections,
    ) {}

    public function enqueue(int $invoiceId, bool $manual = false, bool $fromStatusChange = false): ?AllegroInvoiceExport
    {
        return DB::transaction(function () use ($invoiceId, $manual, $fromStatusChange): ?AllegroInvoiceExport {
            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);
            $link = $invoice ? AllegroOrderLink::query()->with(['account', 'order'])->where('order_id', $invoice->order_id)->first() : null;
            if (! $invoice || ! $link || ! $this->eligible($invoice, $link)) {
                return null;
            }
            $account = $link->account;
            $type = $invoice->document_type->value;
            $prefix = $type.'_sync_';
            if (! $manual && ($account->{$prefix.'mode'} === AllegroInvoiceSyncMode::Disabled
                || $account->{$prefix.'identity'} !== $account->orderImportIdentity()
                || ($invoice->isInvoice() && $account->invoice_sync_only_required && ! $link->invoice_required))) {
                return null;
            }
            $fingerprint = hash('sha256', implode('|', [$link->environment, $link->seller_user_id, $link->external_order_id, $invoiceId]));
            $export = AllegroInvoiceExport::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
            if ($export) {
                if ($fromStatusChange && ! $manual && $export->state === 'skipped' && $export->upload_started_at === null
                    && $export->allegro_account_id === $account->id && $export->allegro_order_link_id === $link->id
                    && (! $export->document_hash || $export->document_hash === $this->documents->hash($invoice))) {
                    $export->update(['manual' => false, 'configuration_revision' => $account->{$prefix.'revision'}, 'state' => 'waiting']);
                    $this->activate($export);
                }

                return $export;
            }
            $export = AllegroInvoiceExport::query()->firstOrCreate(['fingerprint' => $fingerprint], [
                'allegro_account_id' => $account->id, 'allegro_order_link_id' => $link->id,
                'order_id' => $invoice->order_id, 'invoice_id' => $invoiceId,
                'document_type' => $type, 'source_invoice_number' => $invoice->isCorrection() ? $invoice->correctedInvoice?->number : null,
                'environment' => $link->environment, 'seller_user_id' => $link->seller_user_id, 'external_order_id' => $link->external_order_id,
                'invoice_number' => $invoice->number, 'fingerprint' => $fingerprint, 'manual' => $manual,
                'configuration_revision' => $account->{$prefix.'revision'}, 'attempt_token' => (string) Str::uuid(), 'state' => 'waiting',
            ]);
            if ($export->wasRecentlyCreated) {
                $this->activate($export);
            }

            return $export->refresh();
        }, 3);
    }

    public function statusChanged(int $orderId): void
    {
        $link = AllegroOrderLink::query()->with('account')->where('order_id', $orderId)->first();
        foreach (['invoice', 'correction'] as $type) {
            $prefix = $type.'_sync_';
            if ($link?->account?->{$prefix.'mode'} !== AllegroInvoiceSyncMode::Status) {
                continue;
            }
            $status = OrderStatusSetting::query()->find($link->account->{$prefix.'status_setting_id'});
            if ($status && $status->status === $link->order?->status) {
                Invoice::query()->where('order_id', $orderId)->where('document_type', $type)->where('status', 'issued')
                    ->when($type === 'correction', fn ($query) => $query->whereNotNull('finalized_at'))
                    ->each(fn ($invoice) => $this->enqueue($invoice->id, false, true));
            }
        }
        $this->wakeWaiting($orderId);
    }

    public function wakeWaiting(int $orderId): void
    {
        AllegroInvoiceExport::query()->where('order_id', $orderId)->where('state', 'waiting')->get()->each(function ($export): void {
            DB::transaction(function () use ($export): void {
                $locked = AllegroInvoiceExport::query()->lockForUpdate()->find($export->id);
                if ($locked?->state === 'waiting') {
                    $this->activate($locked);
                }
            }, 3);
        });
    }

    public function retry(int $exportId): bool
    {
        return DB::transaction(function () use ($exportId): bool {
            $export = AllegroInvoiceExport::query()->lockForUpdate()->find($exportId);
            if (! $export || ! in_array($export->state, ['waiting', 'failed', 'uncertain', 'verifying', 'skipped'], true) || ! $this->accountMatches($export)) {
                return false;
            }
            if ($export->upload_started_at === null) {
                if (! $export->invoice || ! $export->link || ! $this->eligible($export->invoice, $export->link)
                    || ($export->document_hash && $export->document_hash !== $this->documents->hash($export->invoice))) {
                    return false;
                }
                $export->manual = true;
                $export->configuration_revision = $export->account->{$export->document_type.'_sync_revision'};
                $export->state = 'waiting';
                $export->save();
                $this->activate($export);
            } else {
                // Retrying an ambiguous or acknowledged upload is always read-only.
                $export->verification_attempts = 0;
                $this->dispatch($export);
            }

            return true;
        }, 3);
    }

    public function recover(): void
    {
        AllegroInvoiceExport::query()->where(function ($query): void {
            $query->where('state', 'waiting')
                ->orWhere(fn ($q) => $q->whereIn('state', ['pending', 'uploading'])->where('updated_at', '<=', now()->subMinutes(15)))
                ->orWhere(fn ($q) => $q->whereIn('state', ['verifying', 'uncertain'])->whereNotNull('next_check_at')->where('next_check_at', '<=', now()));
        })->orderBy('updated_at')->orderBy('id')->limit(100)->get()->each(function ($candidate): void {
            DB::transaction(function () use ($candidate): void {
                $export = AllegroInvoiceExport::query()->lockForUpdate()->find($candidate->id);
                if (! $export) {
                    return;
                }
                if ($export->state === 'waiting') {
                    $this->activate($export);
                    $export->touch();
                } elseif ($this->accountMatches($export)
                    && ((in_array($export->state, ['pending', 'uploading'], true) && $export->updated_at->lte(now()->subMinutes(15)))
                        || (in_array($export->state, ['verifying', 'uncertain'], true) && $export->next_check_at?->lte(now())))) {
                    $this->dispatch($export);
                }
            }, 3);
        });
    }

    public function sync(ExportAllegroInvoiceJob $job): void
    {
        $export = $this->context($job);
        if (! $export) {
            return;
        }
        if ($export->upload_started_at !== null) {
            $this->reconcile($job, $export);

            return;
        }
        $raw = $this->client->order($export->account, $export->external_order_id);
        $isCorrection = $export->document_type === 'correction';
        if (($raw['id'] ?? null) !== $export->external_order_id
            || ! in_array($raw['status'] ?? null, $isCorrection ? ['READY_FOR_PROCESSING', 'CANCELLED'] : ['READY_FOR_PROCESSING'], true)
            || data_get($raw, 'fulfillment.provider.id') !== 'SELLER'
            || (! $isCorrection && data_get($raw, 'fulfillment.status') === 'CANCELLED')) {
            throw new AllegroApiException('Zamówienie Allegro nie jest gotowe do przekazania faktury.', 422);
        }
        $remote = $this->remoteInvoices($export);
        if (! $isCorrection && $remote !== []) {
            throw new AllegroApiException('Do zamówienia Allegro dodano już dokument. Sprawdź go przed przekazaniem faktury z OMS.', 422);
        }
        $export = $this->context($job);
        if (! $export) {
            return;
        }
        $contents = $this->freezePdf($export);
        $export = $this->context($job);
        if (! $export) {
            return;
        }
        // Persist intent before HTTP. A killed worker can subsequently only reconcile, never upload blindly.
        $export->update(['state' => 'uploading', 'upload_started_at' => now()]);
        try {
            $response = $this->client->uploadOrderInvoice($export->account, $export->external_order_id, $export->invoice_number, $export->file_name, $contents);
        } catch (AllegroApiException $exception) {
            if ($exception->statusCode !== null && in_array($exception->statusCode, [400, 401, 403, 404, 413, 422, 429], true)) {
                AllegroInvoiceExport::query()->whereKey($export->id)->where('attempt_token', $job->token)->update(['upload_started_at' => null]);
                throw $exception;
            }
            $this->unresolved($job, 'Nie potwierdzono wyniku wysyłki. OMS sprawdzi Allegro bez ponownego przesyłania pliku.');

            return;
        }
        if (! is_string($response['id'] ?? null) || ! Str::isUuid($response['id'])) {
            $this->unresolved($job, 'Allegro nie zwróciło jednoznacznego identyfikatora przesłanej faktury.');

            return;
        }
        DB::transaction(function () use ($job, $response): void {
            $current = AllegroInvoiceExport::query()->lockForUpdate()->find($job->exportId);
            if (! $current || $current->attempt_token !== $job->token) {
                return;
            }
            $current->update(['remote_id' => $response['id'], 'state' => 'verifying', 'error' => null,
                'uploaded_at' => now(), 'next_check_at' => now()->addMinute()]);
            $this->event($current, 'allegro_invoice_uploaded', 'Faktura przesłana do Allegro', 'Przesłano '.$current->invoice_number.'. Trwa weryfikacja pliku.');
        }, 3);
    }

    public function failure(ExportAllegroInvoiceJob $job, Throwable $exception): void
    {
        DB::transaction(function () use ($job, $exception): void {
            $export = AllegroInvoiceExport::query()->lockForUpdate()->find($job->exportId);
            if (! $export || $export->attempt_token !== $job->token || ! in_array($export->state, ['pending', 'uploading'], true)) {
                return;
            }
            if ($export->upload_started_at !== null) {
                $this->unresolved($job, 'Nie potwierdzono wyniku operacji. Wymagane jest sprawdzenie dokumentu w Allegro.');

                return;
            }
            $message = $exception instanceof AllegroApiException ? mb_substr($exception->getMessage(), 0, 900)
                : 'Nie udało się przygotować lub przekazać faktury. Sprawdź dokument i spróbuj ponownie.';
            $export->update(['state' => 'failed', 'error' => $message, 'next_check_at' => null]);
            $this->event($export, 'allegro_invoice_export_failed', 'Błąd przekazania faktury do Allegro', $message);
        }, 3);
    }

    private function reconcile(ExportAllegroInvoiceJob $job, AllegroInvoiceExport $export): void
    {
        $invoices = $this->remoteInvoices($export);
        if (blank($export->file_name) || blank($export->pdf_checksum) || blank($export->document_hash)) {
            $this->unresolved($job, 'Brakuje tożsamości zapisanej kopii PDF. Wymagane jest ręczne sprawdzenie w Allegro.');

            return;
        }
        $matches = array_values(array_filter($invoices, fn ($row) => $export->remote_id
            ? $row['id'] === $export->remote_id
            : (data_get($row, 'file.name') === $export->file_name && ($row['invoiceNumber'] ?? null) === $export->invoice_number)));
        if (count($matches) !== 1) {
            $this->unresolved($job, 'Nie znaleziono jednoznacznego potwierdzenia tej faktury w Allegro. Nie wysłano kolejnej kopii.');

            return;
        }
        $remote = $matches[0];
        if (data_get($remote, 'file.name') !== $export->file_name || ($remote['invoiceNumber'] ?? null) !== $export->invoice_number
            || ! is_string(data_get($remote, 'file.uploadedAt')) || trim(data_get($remote, 'file.uploadedAt')) === '') {
            $this->unresolved($job, 'Dane pliku w Allegro nie odpowiadają zgłoszeniu OMS. Wymagane jest sprawdzenie.');

            return;
        }
        $security = data_get($remote, 'file.securityVerification.status');
        DB::transaction(function () use ($job, $remote, $security): void {
            $current = AllegroInvoiceExport::query()->lockForUpdate()->find($job->exportId);
            if (! $current || $current->attempt_token !== $job->token) {
                return;
            }
            $values = ['remote_id' => $remote['id'], 'uploaded_at' => $current->uploaded_at ?? now(),
                'verification_attempts' => $current->verification_attempts + 1];
            if ($security === 'ACCEPTED') {
                $current->update($values + ['state' => 'synced', 'error' => null, 'confirmed_at' => now(), 'next_check_at' => null]);
                $this->event($current, 'allegro_invoice_confirmed', 'Faktura potwierdzona w Allegro', 'Potwierdzono '.$current->invoice_number.' i pozytywną weryfikację pliku.');
                if ($current->document_type === 'invoice' && $current->order_id) {
                    $this->wakeWaiting($current->order_id);
                }
            } elseif ($security === 'REJECTED') {
                $message = 'Allegro odrzuciło plik podczas weryfikacji bezpieczeństwa. Nie wysłano kolejnej kopii.';
                $current->update($values + ['state' => 'failed', 'error' => $message, 'next_check_at' => null]);
                $this->event($current, 'allegro_invoice_export_failed', 'Allegro odrzuciło plik faktury', $message);
            } elseif ($security === 'WAITING') {
                $current->update($values + ['state' => 'verifying', 'error' => null,
                    'next_check_at' => $values['verification_attempts'] < 10 ? now()->addMinutes(2) : null]);
            } else {
                $this->unresolved($job, 'Allegro nie potwierdziło jeszcze wyniku weryfikacji bezpieczeństwa pliku.');
            }
        }, 3);
    }

    private function remoteInvoices(AllegroInvoiceExport $export): array
    {
        $response = $this->client->orderInvoices($export->account, $export->external_order_id);
        if (! is_array($response['invoices'] ?? null) || ! array_is_list($response['invoices'])) {
            throw new AllegroApiException('Allegro zwróciło nieprawidłową listę dokumentów.', 502);
        }
        foreach ($response['invoices'] as $invoice) {
            if (! is_array($invoice) || ! is_string($invoice['id'] ?? null) || ! Str::isUuid($invoice['id'])) {
                throw new AllegroApiException('Nie można bezpiecznie sprawdzić dokumentów przypisanych w Allegro.', 502);
            }
        }

        return $response['invoices'];
    }

    private function freezePdf(AllegroInvoiceExport $export): string
    {
        if ($export->pdf_path !== null) {
            $contents = Storage::disk('local')->get($export->pdf_path);
            if (! is_string($contents) || hash('sha256', $contents) !== $export->pdf_checksum) {
                throw new AllegroApiException('Nie można potwierdzić integralności zapisanej kopii PDF.', 422);
            }

            return $contents;
        }
        $hash = $this->documents->hash($export->invoice);
        $contents = $this->documents->contents($export->invoice, $export->account);
        if ($hash !== $this->documents->hash($export->invoice->fresh())) {
            throw new AllegroApiException('Faktura zmieniła się podczas przygotowywania PDF. Spróbuj ponownie.', 422);
        }
        $checksum = hash('sha256', $contents);
        $path = 'allegro/invoice-exports/'.$export->fingerprint.'.pdf';
        if (! Storage::disk('local')->put($path, $contents)) {
            throw new AllegroApiException('Nie udało się zapisać prywatnej kopii faktury.', 422);
        }
        $export->update(['document_hash' => $hash, 'pdf_checksum' => $checksum, 'pdf_path' => $path,
            'file_name' => 'nex-oms-'.Str::uuid().'-'.substr($checksum, 0, 16).'.pdf', 'file_size' => strlen($contents)]);

        return $contents;
    }

    private function context(ExportAllegroInvoiceJob $job): ?AllegroInvoiceExport
    {
        $export = AllegroInvoiceExport::query()->find($job->exportId);
        if (! $export || $export->attempt_token !== $job->token || ! in_array($export->state, ['pending', 'uploading'], true)) {
            return null;
        }
        if (! $this->accountMatches($export)) {
            $export->update(['state' => $export->upload_started_at ? 'uncertain' : 'skipped', 'next_check_at' => null]);

            return null;
        }
        if ($export->upload_started_at !== null) {
            return $export;
        }
        if (! $this->validSource($export)) {
            $export->update(['state' => 'skipped', 'error' => null]);

            return null;
        }
        if ($export->document_hash && $export->document_hash !== $this->documents->hash($export->invoice)) {
            throw new AllegroApiException('Faktura zmieniła się po przygotowaniu kopii. Nie przekazano nieaktualnego pliku.', 422);
        }
        if ($reason = $this->waitingReason($export)) {
            $export->update(['state' => 'waiting', 'error' => $reason]);

            return null;
        }

        return $export;
    }

    public function eligible(Invoice $invoice, AllegroOrderLink $link): bool
    {
        if (! $invoice->isIssued() || trim((string) $invoice->number) === ''
            || ! $link->account?->isOperational() || ! $link->order || $link->order->trashed()
            || $link->order->source !== 'allegro' || $invoice->order_id !== $link->order_id
            || $link->environment !== $link->account->environment || $link->seller_user_id !== $link->account->allegro_user_id
            || $link->replaced_by_link_id !== null) {
            return false;
        }
        if ($invoice->isCorrection()) {
            if (! $invoice->isFinalized()
                || ! ($link->isReady() || ($link->import_state === 'cancelled' && $link->completed_at !== null))
                || ! in_array($link->external_status, ['READY_FOR_PROCESSING', 'CANCELLED'], true)) {
                return false;
            }
            $root = $invoice->correctedInvoice()->first();
            if (! $root || $root->order_id !== $link->order_id) {
                return false;
            }
            try {
                $this->corrections->assertSourceInvoice($root);

                return $this->corrections->chain($root)->contains($invoice);
            } catch (InvoiceDomainException) {
                return false;
            }
        }

        return $invoice->isInvoice() && $link->order->status !== Order::STATUS_CANCELLED
            && $link->isReady() && $link->external_status === 'READY_FOR_PROCESSING' && $link->fulfillment_status !== 'CANCELLED';
    }

    private function accountMatches(AllegroInvoiceExport $export): bool
    {
        return $export->account && $export->account->isOperational()
            && $export->environment === $export->account->environment && $export->seller_user_id === $export->account->allegro_user_id;
    }

    private function validSource(AllegroInvoiceExport $export): bool
    {
        if (! in_array($export->document_type, ['invoice', 'correction'], true)) {
            return false;
        }
        $prefix = $export->document_type.'_sync_';

        return $export->invoice && $export->link && $this->eligible($export->invoice, $export->link)
            && $export->invoice->document_type->value === $export->document_type
            && $export->link->allegro_account_id === $export->allegro_account_id && $export->link->order_id === $export->order_id
            && $export->link->external_order_id === $export->external_order_id && $export->invoice->number === $export->invoice_number
            && $export->configuration_revision === $export->account->{$prefix.'revision'}
            && ($export->manual || ($export->account->{$prefix.'mode'} !== AllegroInvoiceSyncMode::Disabled
                && $export->account->{$prefix.'identity'} === $export->account->orderImportIdentity()
                && ($export->document_type === 'correction' || ! $export->account->invoice_sync_only_required || $export->link->invoice_required)));
    }

    private function waitingReason(AllegroInvoiceExport $export): ?string
    {
        $prefix = $export->document_type.'_sync_';
        if (! $export->manual && $export->account->{$prefix.'mode'} === AllegroInvoiceSyncMode::Status) {
            $status = OrderStatusSetting::query()->find($export->account->{$prefix.'status_setting_id'});
            if (! $status || $status->status !== $export->order->status) {
                return 'Oczekuje na wybrany status OMS';
            }
        }
        if (! $export->manual && $export->document_type === 'correction' && ! $this->sourceConfirmed($export->invoice, $export->link)) {
            return 'Brak potwierdzenia faktury źródłowej w Allegro. Sprawdź dokument i potwierdź ręczne przekazanie korekty.';
        }

        return $this->documents->waitingReason($export->invoice, $export->account);
    }

    public function sourceConfirmed(Invoice $correction, AllegroOrderLink $link): bool
    {
        return AllegroInvoiceExport::query()->where('invoice_id', $correction->corrected_invoice_id)
            ->where('document_type', 'invoice')->where('allegro_order_link_id', $link->id)
            ->where('allegro_account_id', $link->allegro_account_id)->where('environment', $link->environment)
            ->where('seller_user_id', $link->seller_user_id)->where('external_order_id', $link->external_order_id)
            ->where('state', 'synced')->whereNotNull('confirmed_at')->exists();
    }

    private function activate(AllegroInvoiceExport $export): void
    {
        if (! $this->accountMatches($export) || ! $this->validSource($export)) {
            $export->update(['state' => 'skipped', 'error' => null]);
        } elseif ($reason = $this->waitingReason($export)) {
            $export->update(['state' => 'waiting', 'error' => $reason]);
        } else {
            $this->dispatch($export);
        }
    }

    private function dispatch(AllegroInvoiceExport $export): void
    {
        $export->forceFill(['attempt_token' => (string) Str::uuid(), 'state' => 'pending', 'error' => null, 'next_check_at' => null])->save();
        $id = $export->id;
        $token = $export->attempt_token;
        DB::afterCommit(fn () => ExportAllegroInvoiceJob::dispatch($id, $token)->afterCommit());
    }

    private function unresolved(ExportAllegroInvoiceJob $job, string $message): void
    {
        $export = AllegroInvoiceExport::query()->whereKey($job->exportId)->where('attempt_token', $job->token)->first();
        if (! $export) {
            return;
        }
        $first = $export->state !== 'uncertain' && $export->verification_attempts === 0;
        $attempts = $export->verification_attempts + 1;
        $export->update(['state' => 'uncertain', 'error' => $message, 'verification_attempts' => $attempts,
            'next_check_at' => $attempts < 10 ? now()->addMinutes(2) : null]);
        if ($first) {
            $this->event($export, 'allegro_invoice_export_uncertain', 'Wynik przekazania faktury wymaga sprawdzenia', $message);
        }
    }

    private function event(AllegroInvoiceExport $export, string $type, string $title, string $description): void
    {
        if ($export->document_type === 'correction') {
            $type = str_replace('allegro_invoice_', 'allegro_correction_', $type);
            $title = str_replace(['Faktura', 'faktury'], ['Korekta', 'korekty'], $title);
        }
        $export->order?->events()->create(['event_type' => $type, 'title' => $title, 'description' => $description,
            'payload' => ['allegro_invoice_export_id' => $export->id, 'invoice_number' => $export->invoice_number,
                'document_type' => $export->document_type, 'source_invoice_number' => $export->source_invoice_number, 'remote_id' => $export->remote_id]]);
    }
}
