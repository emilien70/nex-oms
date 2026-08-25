<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\ValueObjects\KsefRawApiResponse;

class KsefInvoiceSourceService
{
    public function __construct(
        private readonly KsefAccessTokenManager $accessTokens,
        private readonly KsefOnlineSessionClient $onlineSession,
        private readonly KsefOperationalEnvironmentPolicy $environments,
    ) {}

    public function fetch(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
    ): KsefRawApiResponse {
        $this->assertOwnership($invoice, $submission);
        $this->assertInvoice($invoice);
        $this->assertTransportEnabled();
        $this->assertIntegrationActive();

        $invoice = Invoice::query()->findOrFail($invoice->getKey());
        $submission = KsefInvoiceSubmission::query()->findOrFail($submission->getKey());
        $this->assertOwnership($invoice, $submission);
        $this->assertEligible($invoice, $submission);

        $accessToken = $this->accessTokens->getValidAccessToken(
            $submission->environment,
            $submission->context_nip,
        );

        try {
            $response = $this->onlineSession->invoice(
                $submission->environment,
                $accessToken,
                $submission->ksef_number,
            );
        } catch (KsefApiException $exception) {
            if ($exception->httpStatus === 404 || in_array($exception->reasonCode, ['21164', '21165'], true)) {
                throw new KsefApiException(
                    'Faktura nie jest jeszcze dostępna do pobrania z KSeF.',
                    'ksef_invoice_not_available',
                    $exception->httpStatus,
                    $exception->reasonCode,
                    $exception->retryAfterSeconds,
                    $exception->systemWarning,
                );
            }

            throw $exception;
        }

        $this->assertResponseIntegrity($response, $submission);

        return $response;
    }

    private function assertOwnership(Invoice $invoice, KsefInvoiceSubmission $submission): void
    {
        if ($submission->invoice_id !== $invoice->getKey()) {
            throw new KsefApiException(
                'Próba KSeF nie należy do wskazanej Faktury.',
                'ksef_invoice_submission_mismatch',
            );
        }
    }

    private function assertInvoice(Invoice $invoice): void
    {
        if (! $invoice->isInvoice()) {
            throw new KsefApiException(
                'Dokument z KSeF można pobrać wyłącznie dla Faktury VAT.',
                'ksef_invoice_document_type_invalid',
            );
        }
    }

    private function assertEligible(Invoice $invoice, KsefInvoiceSubmission $submission): void
    {
        $this->assertInvoice($invoice);

        if ($submission->status !== KsefInvoiceSubmissionStatus::Accepted) {
            throw new KsefApiException(
                'Dokument z KSeF można pobrać wyłącznie dla zaakceptowanej Faktury.',
                'ksef_invoice_submission_not_accepted',
            );
        }

        $this->environments->assertAllowed($submission->environment);
        $this->requiredReference(
            $submission->ksef_number,
            'Zaakceptowana próba nie posiada numeru KSeF.',
            'ksef_invoice_number_missing',
        );
        $this->assertNip($submission->context_nip, 'ksef_invoice_context_missing');
        $this->assertNip($submission->seller_nip, 'ksef_invoice_seller_missing');
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

    private function assertIntegrationActive(): void
    {
        $active = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->where('is_active', true)
            ->exists();

        if (! $active) {
            throw new KsefApiException(
                'Integracja KSeF nie jest aktywna.',
                'ksef_submission_configuration_inactive',
            );
        }
    }

    private function assertResponseIntegrity(
        KsefRawApiResponse $response,
        KsefInvoiceSubmission $submission,
    ): void {
        $header = is_string($response->contentHash) ? trim($response->contentHash) : '';
        $decoded = base64_decode($header, true);

        if ($header === '' || $decoded === false || strlen($decoded) !== 32) {
            throw new KsefApiException(
                'Odpowiedź Faktury KSeF nie zawiera prawidłowego skrótu integralności.',
                'ksef_invoice_hash_missing',
            );
        }

        $calculated = $this->hash($response->body);
        if (! hash_equals($header, $calculated)) {
            throw new KsefApiException(
                'Skrót odpowiedzi Faktury KSeF jest niezgodny z odebranym dokumentem.',
                'ksef_invoice_hash_mismatch',
            );
        }

        if (! is_string($submission->invoice_hash)
            || ! hash_equals($submission->invoice_hash, $header)) {
            throw new KsefApiException(
                'Faktura pobrana z KSeF jest niezgodna z zamrożonym dokumentem wysłanym przez NEX-OMS.',
                'ksef_invoice_source_mismatch',
            );
        }
    }

    private function assertSubmissionPayloadIntegrity(KsefInvoiceSubmission $submission): void
    {
        if ($submission->schema_id !== 'FA (3) 1-0E'
            || ! is_string($submission->payload_xml)
            || ! is_string($submission->invoice_hash)
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

    private function hash(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }
}
