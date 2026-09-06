<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefInvoiceUpo;
use Modules\Ksef\Models\KsefSetting;

class KsefInvoiceUpoService
{
    public function __construct(
        private readonly KsefAccessTokenManager $accessTokens,
        private readonly KsefOnlineSessionClient $onlineSession,
        private readonly KsefUpoValidator $validator,
        private readonly KsefOperationalEnvironmentPolicy $environments,
    ) {}

    public function fetch(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
    ): KsefInvoiceUpo {
        $this->assertOwnership($invoice, $submission);
        $this->assertSupportedDocument($invoice);
        $this->assertEligible($invoice, $submission);

        $existing = $this->findExisting($submission);
        if ($existing !== null) {
            return $existing;
        }

        $this->assertTransportEnabled();
        $settings = $this->activeSettings();

        $invoice = Invoice::query()->findOrFail($invoice->getKey());
        $submission = KsefInvoiceSubmission::query()->findOrFail($submission->getKey());
        $this->assertOwnership($invoice, $submission);
        $this->assertEligible($invoice, $submission);
        $this->assertCurrentOfflineContext($submission, $settings);
        $identity = $this->identity($submission);
        $accessToken = $this->accessTokens->getValidAccessToken(
            $submission->environment,
            $submission->context_nip,
        );

        try {
            $response = $this->onlineSession->invoiceUpo(
                $submission->environment,
                $accessToken,
                $submission->session_reference_number,
                $submission->ksef_number,
            );
        } catch (KsefApiException $exception) {
            if ($exception->httpStatus === 404 || $exception->reasonCode === '21178') {
                throw new KsefApiException(
                    'UPO tej Faktury nie jest jeszcze dostępne w KSeF.',
                    'ksef_upo_not_available',
                    $exception->httpStatus,
                    $exception->reasonCode,
                    $exception->retryAfterSeconds,
                    $exception->systemWarning,
                );
            }

            throw $exception;
        }

        $hash = $this->validatedResponseHash($response->contentHash, $response->body);
        $this->validator->validate($response->body, $submission, $invoice);

        try {
            return DB::transaction(function () use ($submission, $invoice, $identity, $response, $hash): KsefInvoiceUpo {
                $managed = KsefInvoiceSubmission::query()
                    ->lockForUpdate()
                    ->findOrFail($submission->getKey());
                $this->assertOwnership($invoice, $managed);
                $this->assertEligible($invoice, $managed);

                if ($identity !== $this->identity($managed)) {
                    throw new KsefApiException(
                        'Dane zaakceptowanej próby KSeF zmieniły się podczas pobierania UPO.',
                        'ksef_upo_submission_changed',
                    );
                }

                $existing = $managed->upo()->first();
                if ($existing !== null) {
                    return $this->assertStoredIntegrity($existing);
                }

                return KsefInvoiceUpo::query()->create([
                    'ksef_invoice_submission_id' => $managed->getKey(),
                    'schema_id' => KsefUpoValidator::SCHEMA_ID,
                    'payload_xml' => $response->body,
                    'payload_hash' => $hash,
                    'payload_size' => strlen($response->body),
                    'fetched_at' => CarbonImmutable::now('UTC')->setTimezone(config('app.timezone')),
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->findExisting($submission);
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function stored(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
    ): ?KsefInvoiceUpo {
        $this->assertOwnership($invoice, $submission);
        $this->assertSupportedDocument($invoice);
        $this->assertEligible($invoice, $submission);

        return $this->findExisting($submission);
    }

    private function findExisting(KsefInvoiceSubmission $submission): ?KsefInvoiceUpo
    {
        $upo = KsefInvoiceUpo::query()
            ->where('ksef_invoice_submission_id', $submission->getKey())
            ->first();

        return $upo === null ? null : $this->assertStoredIntegrity($upo);
    }

    private function assertOwnership(Invoice $invoice, KsefInvoiceSubmission $submission): void
    {
        if ($submission->invoice_id !== $invoice->getKey()) {
            throw new KsefApiException(
                'Próba KSeF nie należy do wskazanej Faktury.',
                'ksef_upo_submission_mismatch',
            );
        }
    }

    private function assertSupportedDocument(Invoice $invoice): void
    {
        if (! $invoice->isInvoice() && ! $invoice->isCorrection()) {
            throw new KsefApiException(
                'UPO KSeF jest dostępne wyłącznie dla Faktury VAT albo Korekty.',
                'ksef_upo_document_type_invalid',
            );
        }
    }

    private function assertEligible(Invoice $invoice, KsefInvoiceSubmission $submission): void
    {
        $this->assertSupportedDocument($invoice);

        if ($submission->status !== KsefInvoiceSubmissionStatus::Accepted) {
            throw new KsefApiException(
                'UPO można pobrać wyłącznie dla Faktury przyjętej przez KSeF.',
                'ksef_upo_submission_not_accepted',
            );
        }

        if (! $submission->hasExpectedInvoicingMode()) {
            throw new KsefApiException(
                'UPO jest zablokowane, ponieważ tryb wystawienia zwrócony przez KSeF nie odpowiada tej próbie.',
                'ksef_upo_invoicing_mode_mismatch',
            );
        }

        $this->environments->assertAllowed($submission->environment);

        $this->requiredReference(
            $submission->session_reference_number,
            'Zaakceptowana próba nie posiada numeru referencyjnego sesji.',
            'ksef_upo_session_reference_missing',
        );
        $this->requiredReference(
            $submission->invoice_reference_number,
            'Zaakceptowana próba nie posiada numeru referencyjnego Faktury.',
            'ksef_upo_invoice_reference_missing',
        );
        $this->requiredReference(
            $submission->ksef_number,
            'Zaakceptowana próba nie posiada numeru KSeF.',
            'ksef_upo_ksef_number_missing',
        );
        $this->assertNip($submission->context_nip, 'ksef_upo_context_missing');
        $this->assertNip($submission->seller_nip, 'ksef_upo_seller_missing');
        $this->assertSubmissionPayloadIntegrity($submission);
    }

    private function assertTransportEnabled(): void
    {
        if (config('ksef.invoice_submission_enabled') !== true) {
            throw new KsefApiException(
                'Transport Faktur do KSeF jest wyłączony w konfiguracji wdrożenia.',
                'ksef_submission_disabled',
            );
        }
    }

    private function activeSettings(): KsefSetting
    {
        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->first();

        if ($settings === null || ! $settings->is_active) {
            throw new KsefApiException(
                'Integracja KSeF nie jest aktywna.',
                'ksef_submission_configuration_inactive',
            );
        }

        return $settings;
    }

    private function assertCurrentOfflineContext(
        KsefInvoiceSubmission $submission,
        KsefSetting $settings,
    ): void {
        if ($submission->offline_issuance_id === null) {
            return;
        }

        if (! is_string($settings->context_nip)
            || ! hash_equals((string) $submission->context_nip, $settings->context_nip)) {
            throw new KsefApiException(
                'Aby pobrać UPO historycznej Faktury Offline, aktywny kontekst NIP KSeF musi odpowiadać kontekstowi zamrożonemu przy wystawieniu.',
                'ksef_offline_submission_context_not_current',
            );
        }
    }

    private function validatedResponseHash(?string $header, string $body): string
    {
        $header = is_string($header) ? trim($header) : '';
        $decoded = base64_decode($header, true);
        if ($header === '' || $decoded === false || strlen($decoded) !== 32) {
            throw new KsefApiException(
                'Odpowiedź UPO nie zawiera prawidłowego skrótu integralności.',
                'ksef_upo_hash_missing',
            );
        }

        $calculated = $this->hash($body);
        if (! hash_equals($header, $calculated)) {
            throw new KsefApiException(
                'Skrót odpowiedzi UPO jest niezgodny z odebranym dokumentem.',
                'ksef_upo_hash_mismatch',
            );
        }

        return $calculated;
    }

    private function assertStoredIntegrity(KsefInvoiceUpo $upo): KsefInvoiceUpo
    {
        if ($upo->schema_id !== KsefUpoValidator::SCHEMA_ID
            || $upo->payload_size !== strlen($upo->payload_xml)
            || ! hash_equals($upo->payload_hash, $this->hash($upo->payload_xml))) {
            throw new KsefApiException(
                'Zapisany dokument UPO jest niespójny.',
                'ksef_upo_stored_payload_inconsistent',
            );
        }

        return $upo;
    }

    private function assertSubmissionPayloadIntegrity(KsefInvoiceSubmission $submission): void
    {
        if ($submission->schema_id !== 'FA (3) 1-0E'
            || $submission->invoice_size !== strlen($submission->payload_xml)
            || ! hash_equals($submission->invoice_hash, $this->hash($submission->payload_xml))) {
            throw new KsefApiException(
                'Zamrożony payload Faktury KSeF jest niespójny.',
                'ksef_submission_payload_inconsistent',
            );
        }
    }

    private function assertNip(mixed $nip, string $safeCode): void
    {
        if (! is_string($nip) || preg_match('/^\d{10}$/', $nip) !== 1) {
            throw new KsefApiException(
                'Zaakceptowana próba nie posiada prawidłowej zamrożonej tożsamości KSeF.',
                $safeCode,
            );
        }
    }

    private function requiredReference(mixed $value, string $message, string $safeCode): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new KsefApiException($message, $safeCode);
        }

        return trim($value);
    }

    /** @return array<string, int|string> */
    private function identity(KsefInvoiceSubmission $submission): array
    {
        return [
            'invoice_id' => $submission->invoice_id,
            'offline_issuance_id' => (int) ($submission->offline_issuance_id ?? 0),
            'offline_technical_correction_id' => (int) ($submission->offline_technical_correction_id ?? 0),
            'environment' => $submission->environment->value,
            'status' => $submission->status->value,
            'context_nip' => (string) $submission->context_nip,
            'seller_nip' => (string) $submission->seller_nip,
            'schema_id' => $submission->schema_id,
            'invoice_hash' => $submission->invoice_hash,
            'invoice_size' => $submission->invoice_size,
            'session_reference_number' => (string) $submission->session_reference_number,
            'invoice_reference_number' => (string) $submission->invoice_reference_number,
            'ksef_number' => (string) $submission->ksef_number,
        ];
    }

    private function hash(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }
}
