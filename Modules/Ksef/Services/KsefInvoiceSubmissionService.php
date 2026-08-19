<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefPublicKeyUsage;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\ValueObjects\KsefOnlineSessionEncryptionData;
use Throwable;

class KsefInvoiceSubmissionService
{
    private const PROCESSING_STATUS_CODES = [100, 150];

    private const REJECTED_STATUS_CODES = [405, 410, 415, 430, 435, 440, 450, 500, 550];

    public function __construct(
        private readonly KsefFa3DocumentGenerator $generator,
        private readonly KsefAccessTokenManager $accessTokens,
        private readonly KsefOnlineSessionClient $onlineSession,
        private readonly KsefPublicKeyResolver $publicKeys,
        private readonly KsefOnlineSessionEncryptionService $encryption,
        private readonly KsefOnlineSessionRequestFactory $requests,
    ) {}

    public function prepare(Invoice $invoice): KsefInvoiceSubmission
    {
        $this->assertTransportEnabled();

        return DB::transaction(function () use ($invoice): KsefInvoiceSubmission {
            $managed = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());

            if (! $managed->isInvoice()) {
                throw new KsefApiException(
                    'Do KSeF można przekazać wyłącznie Fakturę VAT.',
                    'ksef_submission_document_type_invalid',
                );
            }

            $settings = KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();

            if ($settings === null || ! $settings->is_active) {
                throw new KsefApiException(
                    'Integracja KSeF nie jest aktywna.',
                    'ksef_submission_configuration_inactive',
                );
            }

            $environment = $settings->environment;
            $this->assertEnvironmentAllowed($environment);

            $seriesEnabled = KsefSeriesSetting::query()
                ->where('invoice_series_id', $managed->invoice_series_id)
                ->where('is_enabled', true)
                ->lockForUpdate()
                ->exists();

            if (! $seriesEnabled) {
                throw new KsefApiException(
                    'Seria numeracji Faktury nie jest włączona do KSeF.',
                    'ksef_submission_series_disabled',
                );
            }

            $activeStatuses = collect(KsefInvoiceSubmissionStatus::cases())
                ->filter(static fn (KsefInvoiceSubmissionStatus $status): bool => $status->blocksNewAttempt())
                ->map(static fn (KsefInvoiceSubmissionStatus $status): string => $status->value)
                ->all();
            $activeExists = KsefInvoiceSubmission::query()
                ->where('invoice_id', $managed->getKey())
                ->where('environment', $environment->value)
                ->whereIn('status', $activeStatuses)
                ->lockForUpdate()
                ->exists();

            if ($activeExists) {
                throw new KsefApiException(
                    'Dla tej Faktury istnieje już aktywna lub zakończona sukcesem próba KSeF.',
                    'ksef_submission_already_exists',
                );
            }

            $attemptNumber = ((int) KsefInvoiceSubmission::query()
                ->where('invoice_id', $managed->getKey())
                ->where('environment', $environment->value)
                ->max('attempt_number')) + 1;
            $generatedAt = CarbonImmutable::now('UTC');
            $generated = $this->generator->generate(
                $managed,
                $generatedAt,
                KsefFa3EligibilityMode::Authoritative,
            );

            return KsefInvoiceSubmission::query()->create([
                'invoice_id' => $managed->getKey(),
                'environment' => $environment,
                'attempt_number' => $attemptNumber,
                'status' => KsefInvoiceSubmissionStatus::Preparing,
                'schema_id' => $generated->schemaId,
                'generated_at' => $this->forStorage(CarbonImmutable::parse($generated->generatedAt)->utc()),
                'payload_xml' => $generated->xml,
                'invoice_hash' => $this->hash($generated->xml),
                'invoice_size' => strlen($generated->xml),
            ]);
        }, 3);
    }

    public function submit(KsefInvoiceSubmission $submission): KsefInvoiceSubmission
    {
        $this->assertTransportEnabled();
        $submission = KsefInvoiceSubmission::query()->findOrFail($submission->getKey());
        $this->assertEnvironmentAllowed($submission->environment);
        $this->assertStatus($submission, [KsefInvoiceSubmissionStatus::Preparing]);

        try {
            $this->assertPayloadIntegrity($submission);
            $accessToken = $this->accessTokens->getValidAccessToken($submission->environment);
            $certificates = $this->onlineSession->publicKeyCertificates($submission->environment);
            $certificate = $this->publicKeys->resolve(
                $certificates,
                KsefPublicKeyUsage::SymmetricKeyEncryption,
            );
            $encryption = $this->encryption->encrypt($submission->payload_xml, $certificate);
            $submission = $this->storeEncryptionMetadata($submission, $encryption);
            $open = $this->onlineSession->openSession(
                $submission->environment,
                $accessToken,
                $this->requests->openSession($encryption),
            );
            $submission = $this->transition(
                $submission,
                [KsefInvoiceSubmissionStatus::Preparing],
                KsefInvoiceSubmissionStatus::SessionOpened,
                [
                    'session_reference_number' => $open->referenceNumber,
                    'session_valid_until' => $this->forStorage($open->validUntil),
                    'safe_error_code' => null,
                    'safe_error_message' => null,
                ],
            );
        } catch (KsefApiException $exception) {
            $this->markTechnicalFailure($submission, $exception);

            throw $exception;
        } catch (Throwable) {
            $exception = new KsefApiException(
                'Nie udało się rozpocząć sesji fakturowej KSeF.',
                'ksef_submission_pre_send_failed',
            );
            $this->markTechnicalFailure($submission, $exception);

            throw $exception;
        }

        try {
            $invoiceReference = $this->onlineSession->sendInvoice(
                $submission->environment,
                $accessToken,
                $submission->session_reference_number,
                $this->requests->sendInvoice($submission, $encryption),
            );
        } catch (KsefApiException $exception) {
            if ($this->isUncertainSendFailure($exception)) {
                $this->markUncertain($submission, $exception);
            } else {
                $this->markTechnicalFailure($submission, $exception);
            }

            throw $exception;
        } catch (Throwable) {
            $exception = new KsefApiException(
                'Nie można potwierdzić wyniku wysłania Faktury do KSeF.',
                'ksef_invoice_delivery_uncertain',
            );
            $this->markUncertain($submission, $exception);

            throw $exception;
        }

        $submission = $this->transition(
            $submission,
            [KsefInvoiceSubmissionStatus::SessionOpened],
            KsefInvoiceSubmissionStatus::Submitted,
            [
                'invoice_reference_number' => $invoiceReference,
                'safe_error_code' => null,
                'safe_error_message' => null,
            ],
        );

        try {
            $this->onlineSession->closeSession(
                $submission->environment,
                $accessToken,
                $submission->session_reference_number,
            );

            return $this->updateWithoutTransition($submission, [
                'session_closed_at' => $this->forStorage(CarbonImmutable::now('UTC')),
                'session_close_error_code' => null,
                'session_close_error_message' => null,
            ]);
        } catch (KsefApiException $exception) {
            return $this->updateWithoutTransition($submission, [
                'session_close_error_code' => $this->safeErrorCode($exception),
                'session_close_error_message' => $this->safeMessage($exception),
            ]);
        } catch (Throwable) {
            return $this->updateWithoutTransition($submission, [
                'session_close_error_code' => 'ksef_session_close_failed',
                'session_close_error_message' => 'Nie udało się zamknąć sesji KSeF.',
            ]);
        }
    }

    public function refreshStatus(KsefInvoiceSubmission $submission): KsefInvoiceSubmission
    {
        $this->assertTransportEnabled();
        $submission = KsefInvoiceSubmission::query()->findOrFail($submission->getKey());
        $this->assertEnvironmentAllowed($submission->environment);
        $this->assertStatus($submission, [
            KsefInvoiceSubmissionStatus::Submitted,
            KsefInvoiceSubmissionStatus::Processing,
        ]);

        try {
            $accessToken = $this->accessTokens->getValidAccessToken($submission->environment);
            $data = $this->onlineSession->invoiceStatus(
                $submission->environment,
                $accessToken,
                $submission->session_reference_number,
                $submission->invoice_reference_number,
            );
        } catch (KsefApiException $exception) {
            $this->updateWithoutTransition($submission, [
                'last_checked_at' => $this->forStorage(CarbonImmutable::now('UTC')),
                'safe_error_code' => $this->safeErrorCode($exception),
                'safe_error_message' => $this->safeMessage($exception),
            ]);

            throw $exception;
        }

        return $this->applyInvoiceStatus($submission, $data);
    }

    private function applyInvoiceStatus(KsefInvoiceSubmission $submission, array $data): KsefInvoiceSubmission
    {
        $codeValue = data_get($data, 'status.code');
        $code = is_int($codeValue)
            ? $codeValue
            : (is_string($codeValue) && preg_match('/^\d+$/', $codeValue) === 1 ? (int) $codeValue : null);
        $attributes = [
            'last_checked_at' => $this->forStorage(CarbonImmutable::now('UTC')),
            'ksef_status_code' => $code,
            'safe_error_code' => null,
            'safe_error_message' => null,
        ];

        if ($code !== null && in_array($code, self::PROCESSING_STATUS_CODES, true)) {
            return $this->transition(
                $submission,
                [KsefInvoiceSubmissionStatus::Submitted, KsefInvoiceSubmissionStatus::Processing],
                KsefInvoiceSubmissionStatus::Processing,
                $attributes,
            );
        }

        if ($code === 200) {
            $ksefNumber = data_get($data, 'ksefNumber');

            if (! is_string($ksefNumber) || ! $this->isValidKsefNumber($ksefNumber)) {
                return $this->markStatusUncertain(
                    $submission,
                    $attributes,
                    'ksef_invoice_status_number_missing',
                    'KSeF zwrócił status sukcesu bez prawidłowego numeru KSeF.',
                );
            }

            try {
                $attributes += [
                    'ksef_number' => $ksefNumber,
                    'acquisition_date' => $this->optionalDate($data, 'acquisitionDate'),
                    'invoicing_date' => $this->optionalDate($data, 'invoicingDate'),
                    'permanent_storage_date' => $this->optionalDate($data, 'permanentStorageDate'),
                ];
            } catch (KsefApiException $exception) {
                return $this->markStatusUncertain(
                    $submission,
                    $attributes,
                    $exception->safeCode,
                    $exception->getMessage(),
                );
            }

            return $this->transition(
                $submission,
                [KsefInvoiceSubmissionStatus::Submitted, KsefInvoiceSubmissionStatus::Processing],
                KsefInvoiceSubmissionStatus::Accepted,
                $attributes,
            );
        }

        if ($code !== null && in_array($code, self::REJECTED_STATUS_CODES, true)) {
            return $this->transition(
                $submission,
                [KsefInvoiceSubmissionStatus::Submitted, KsefInvoiceSubmissionStatus::Processing],
                KsefInvoiceSubmissionStatus::Rejected,
                $attributes + [
                    'safe_error_code' => 'ksef_invoice_rejected',
                    'safe_error_message' => 'KSeF odrzucił Fakturę podczas weryfikacji.',
                ],
            );
        }

        return $this->markStatusUncertain(
            $submission,
            $attributes,
            'ksef_invoice_status_unknown',
            'KSeF zwrócił nieznany lub niekompletny status Faktury.',
        );
    }

    private function storeEncryptionMetadata(
        KsefInvoiceSubmission $submission,
        KsefOnlineSessionEncryptionData $encryption,
    ): KsefInvoiceSubmission {
        return $this->updateWithoutTransition($submission, [
            'public_key_id' => $encryption->publicKeyId,
            'encrypted_invoice_hash' => $encryption->encryptedInvoiceHash,
            'encrypted_invoice_size' => $encryption->encryptedInvoiceSize,
        ]);
    }

    private function markTechnicalFailure(
        KsefInvoiceSubmission $submission,
        KsefApiException $exception,
    ): KsefInvoiceSubmission {
        return $this->transition(
            $submission,
            [KsefInvoiceSubmissionStatus::Preparing, KsefInvoiceSubmissionStatus::SessionOpened],
            KsefInvoiceSubmissionStatus::TechnicalFailed,
            [
                'safe_error_code' => $this->safeErrorCode($exception),
                'safe_error_message' => $this->safeMessage($exception),
            ],
        );
    }

    private function markUncertain(
        KsefInvoiceSubmission $submission,
        KsefApiException $exception,
    ): KsefInvoiceSubmission {
        return $this->transition(
            $submission,
            [KsefInvoiceSubmissionStatus::SessionOpened],
            KsefInvoiceSubmissionStatus::Uncertain,
            [
                'safe_error_code' => $this->safeErrorCode($exception),
                'safe_error_message' => $this->safeMessage($exception),
            ],
        );
    }

    private function markStatusUncertain(
        KsefInvoiceSubmission $submission,
        array $attributes,
        string $safeCode,
        string $safeMessage,
    ): KsefInvoiceSubmission {
        return $this->transition(
            $submission,
            [KsefInvoiceSubmissionStatus::Submitted, KsefInvoiceSubmissionStatus::Processing],
            KsefInvoiceSubmissionStatus::Uncertain,
            $attributes + [
                'safe_error_code' => $safeCode,
                'safe_error_message' => $safeMessage,
            ],
        );
    }

    private function transition(
        KsefInvoiceSubmission $submission,
        array $allowedFrom,
        KsefInvoiceSubmissionStatus $to,
        array $attributes = [],
    ): KsefInvoiceSubmission {
        return DB::transaction(function () use ($submission, $allowedFrom, $to, $attributes): KsefInvoiceSubmission {
            $managed = KsefInvoiceSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());

            if (! in_array($managed->status, $allowedFrom, true)) {
                throw new KsefApiException(
                    'Stan próby wysyłki KSeF nie pozwala na tę operację.',
                    'ksef_submission_state_invalid',
                );
            }

            $managed->forceFill($attributes + ['status' => $to])->save();

            return $managed->refresh();
        }, 3);
    }

    private function updateWithoutTransition(
        KsefInvoiceSubmission $submission,
        array $attributes,
    ): KsefInvoiceSubmission {
        return DB::transaction(function () use ($submission, $attributes): KsefInvoiceSubmission {
            $managed = KsefInvoiceSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());
            $managed->forceFill($attributes)->save();

            return $managed->refresh();
        }, 3);
    }

    private function assertStatus(KsefInvoiceSubmission $submission, array $allowed): void
    {
        if (! in_array($submission->status, $allowed, true)) {
            throw new KsefApiException(
                'Stan próby wysyłki KSeF nie pozwala na tę operację.',
                'ksef_submission_state_invalid',
            );
        }
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

    private function assertEnvironmentAllowed(KsefEnvironment $environment): void
    {
        if ($environment !== KsefEnvironment::Test) {
            throw new KsefApiException(
                'Transport Faktur jest w tym etapie dozwolony wyłącznie dla środowiska TEST.',
                'ksef_submission_environment_blocked',
            );
        }
    }

    private function isUncertainSendFailure(KsefApiException $exception): bool
    {
        return in_array($exception->safeCode, [
            'network_error',
            'malformed_response',
            'ksef_invoice_send_response_incomplete',
        ], true) || ($exception->httpStatus !== null && $exception->httpStatus >= 500);
    }

    private function assertPayloadIntegrity(KsefInvoiceSubmission $submission): void
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

    private function optionalDate(array $data, string $path): ?CarbonImmutable
    {
        $value = data_get($data, $path);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new KsefApiException(
                'KSeF zwrócił nieprawidłową datę statusu Faktury.',
                'ksef_invoice_status_date_invalid',
            );
        }

        try {
            return $this->forStorage(CarbonImmutable::parse($value)->utc());
        } catch (Throwable) {
            throw new KsefApiException(
                'KSeF zwrócił nieprawidłową datę statusu Faktury.',
                'ksef_invoice_status_date_invalid',
            );
        }
    }

    private function isValidKsefNumber(string $number): bool
    {
        if (preg_match('/^\d{10}-\d{8}-[0-9A-F]{12}-[0-9A-F]{2}$/', $number) !== 1) {
            return false;
        }

        $checksum = 0;
        foreach (str_split(substr($number, 0, 32)) as $character) {
            $checksum ^= ord($character);
            for ($bit = 0; $bit < 8; $bit++) {
                $checksum = ($checksum & 0x80) !== 0
                    ? (($checksum << 1) ^ 0x07) & 0xFF
                    : ($checksum << 1) & 0xFF;
            }
        }

        return strtoupper(substr($number, -2)) === strtoupper(str_pad(dechex($checksum), 2, '0', STR_PAD_LEFT));
    }

    private function hash(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }

    private function forStorage(CarbonImmutable $date): CarbonImmutable
    {
        return $date->setTimezone(config('app.timezone'));
    }

    private function safeMessage(KsefApiException $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 1000);
    }

    private function safeErrorCode(KsefApiException $exception): string
    {
        return $exception->reasonCode ?? $exception->safeCode;
    }
}
