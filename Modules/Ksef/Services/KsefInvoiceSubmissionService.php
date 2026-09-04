<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Enums\KsefPublicKeyUsage;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionDocumentGenerator;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\Services\Fa3\KsefFa3IssueDateReader;
use Modules\Ksef\ValueObjects\KsefOnlineSessionEncryptionData;
use Throwable;

class KsefInvoiceSubmissionService
{
    private const PROCESSING_STATUS_CODES = [100, 150];

    private const REJECTED_STATUS_CODES = [405, 410, 415, 430, 435, 440, 450, 500, 550];

    public function __construct(
        private readonly KsefFa3DocumentGenerator $generator,
        private readonly KsefFa3CorrectionDocumentGenerator $correctionGenerator,
        private readonly KsefAccessTokenManager $accessTokens,
        private readonly KsefOnlineSessionClient $onlineSession,
        private readonly KsefPublicKeyResolver $publicKeys,
        private readonly KsefOnlineSessionEncryptionService $encryption,
        private readonly KsefOnlineSessionRequestFactory $requests,
        private readonly KsefFa3IssueDateReader $issueDates,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
        private readonly KsefNumberValidator $ksefNumbers,
        private readonly KsefInvoiceSubmissionLifecyclePolicy $lifecycle,
        private readonly KsefOperationalEnvironmentPolicy $environments,
        private readonly KsefSubmissionFollowUpPolicy $followUp,
        private readonly KsefOfflineSubmissionIntegrityService $offlineIntegrity,
    ) {}

    public function prepare(
        Invoice $invoice,
        ?KsefEnvironment $expectedEnvironment = null,
        bool $firstAttemptOnly = false,
        ?string $expectedContextNip = null,
    ): KsefInvoiceSubmission {
        return $this->prepareDocument(
            $invoice,
            $expectedEnvironment,
            $firstAttemptOnly,
            $expectedContextNip,
            correction: false,
        );
    }

    public function prepareCorrection(
        Invoice $correction,
        ?KsefEnvironment $expectedEnvironment = null,
        bool $firstAttemptOnly = false,
        ?string $expectedContextNip = null,
    ): KsefInvoiceSubmission {
        return $this->prepareDocument(
            $correction,
            $expectedEnvironment,
            $firstAttemptOnly,
            $expectedContextNip,
            correction: true,
        );
    }

    private function prepareDocument(
        Invoice $document,
        ?KsefEnvironment $expectedEnvironment,
        bool $firstAttemptOnly,
        ?string $expectedContextNip,
        bool $correction,
    ): KsefInvoiceSubmission {
        $this->assertTransportEnabled();

        return DB::transaction(function () use (
            $document,
            $expectedEnvironment,
            $firstAttemptOnly,
            $expectedContextNip,
            $correction,
        ): KsefInvoiceSubmission {
            $managed = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($document->getKey());

            if (($correction && ! $managed->isCorrection())
                || (! $correction && ! $managed->isInvoice())) {
                throw new KsefApiException(
                    $correction
                        ? 'Za pomocą tej operacji można przekazać do KSeF wyłącznie Korektę.'
                        : 'Do KSeF można przekazać wyłącznie Fakturę VAT.',
                    'ksef_submission_document_type_invalid',
                );
            }

            if ($correction && ! $managed->isIssued()) {
                throw new KsefApiException(
                    'Do przygotowania Korekty FA(3) kwalifikuje się wyłącznie wystawiona Korekta.',
                    'ksef_fa3_correction_document_not_supported',
                );
            }

            if ($correction && ! $managed->isFinalized()) {
                throw new KsefApiException(
                    'Korekta musi zostać zamknięta przed utworzeniem autorytatywnego dokumentu FA(3).',
                    'ksef_fa3_correction_document_not_finalized',
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
            if ($expectedEnvironment !== null && $environment !== $expectedEnvironment) {
                throw new KsefApiException(
                    'Środowisko KSeF zmieniło się podczas operacji. Faktura nie została wysłana.',
                    'ksef_submission_environment_changed',
                );
            }
            $this->environments->assertAllowed($environment);
            $contextNip = $this->configuredContextNip($settings->context_nip);
            if ($expectedContextNip !== null && $contextNip !== $expectedContextNip) {
                throw new KsefApiException(
                    'Kontekst NIP KSeF zmienił się podczas operacji. Faktura nie została wysłana.',
                    'ksef_submission_context_changed',
                );
            }

            if (KsefOfflineIssuance::query()
                ->where('invoice_id', $managed->getKey())
                ->where('environment', $environment->value)
                ->lockForUpdate()
                ->exists()) {
                throw new KsefApiException(
                    'Dokument został wystawiony w trybie Offline24 i nie może zostać przygotowany zwykłą ścieżką Online.',
                    'ksef_submission_blocked_by_offline_issuance',
                );
            }

            if (! $correction) {
                $outsideProvenance = KsefInvoiceProvenance::query()
                    ->where('invoice_id', $managed->getKey())
                    ->where('environment', $environment->value)
                    ->where('provenance', KsefInvoiceProvenanceType::OutsideKsef->value)
                    ->lockForUpdate()
                    ->first(['id']);
                if ($outsideProvenance !== null) {
                    throw new KsefApiException(
                        'Faktura została jawnie oznaczona jako wystawiona poza KSeF w aktywnym środowisku i nie może zostać przekazana do KSeF.',
                        'ksef_submission_blocked_by_outside_ksef_provenance',
                    );
                }
            }

            $seriesEnabled = KsefSeriesSetting::query()
                ->where('invoice_series_id', $managed->invoice_series_id)
                ->where('is_enabled', true)
                ->lockForUpdate()
                ->exists();

            if (! $seriesEnabled) {
                throw new KsefApiException(
                    $correction
                        ? 'Seria numeracji Korekty nie jest włączona do KSeF.'
                        : 'Seria numeracji Faktury nie jest włączona do KSeF.',
                    'ksef_submission_series_disabled',
                );
            }

            $history = KsefInvoiceSubmission::query()
                ->where('invoice_id', $managed->getKey())
                ->where('environment', $environment->value)
                ->lockForUpdate()
                ->get(['id', 'status']);
            if ($firstAttemptOnly && $history->isNotEmpty()) {
                throw new KsefApiException(
                    $correction
                        ? 'Korekta posiada już próbę przekazania do KSeF w aktywnym środowisku.'
                        : 'Faktura posiada już próbę przekazania do KSeF w aktywnym środowisku.',
                    'ksef_submission_first_attempt_already_exists',
                );
            }
            $this->lifecycle->assertNewAttemptAllowed($history);

            $attemptNumber = ((int) KsefInvoiceSubmission::query()
                ->where('invoice_id', $managed->getKey())
                ->where('environment', $environment->value)
                ->max('attempt_number')) + 1;
            $generatedAt = CarbonImmutable::now('UTC');
            $generated = $correction
                ? $this->correctionGenerator->generate(
                    $managed,
                    $generatedAt,
                    KsefFa3EligibilityMode::Authoritative,
                )
                : $this->generator->generate(
                    $managed,
                    $generatedAt,
                    KsefFa3EligibilityMode::Authoritative,
                );
            $sellerNip = $this->frozenSellerNip($managed);

            return KsefInvoiceSubmission::query()->create([
                'invoice_id' => $managed->getKey(),
                'environment' => $environment,
                'context_nip' => $contextNip,
                'seller_nip' => $sellerNip,
                'attempt_number' => $attemptNumber,
                'status' => KsefInvoiceSubmissionStatus::Preparing,
                'schema_id' => $generated->schemaId,
                'generated_at' => CarbonImmutable::parse($generated->generatedAt)->utc(),
                'payload_xml' => $generated->xml,
                'invoice_hash' => $this->hash($generated->xml),
                'invoice_size' => strlen($generated->xml),
            ]);
        }, 3);
    }

    public function submit(KsefInvoiceSubmission $submission): KsefInvoiceSubmission
    {
        return $this->submitUsingMode($submission, false);
    }

    public function submitOffline(KsefInvoiceSubmission $submission): KsefInvoiceSubmission
    {
        return $this->submitUsingMode($submission, true);
    }

    private function submitUsingMode(
        KsefInvoiceSubmission $submission,
        bool $offline,
    ): KsefInvoiceSubmission {
        $this->assertTransportEnabled();
        $submission = KsefInvoiceSubmission::query()->findOrFail($submission->getKey());
        $this->environments->assertAllowed($submission->environment);
        $this->assertStatus($submission, [KsefInvoiceSubmissionStatus::Preparing]);

        try {
            $contextNip = $this->submissionContextNip($submission);
            $this->submissionSellerNip($submission);
            $this->assertPayloadIntegrity($submission);
            $plaintext = $submission->payload_xml;

            if ($offline) {
                $this->assertCurrentOfflineContext($submission);
                $this->offlineIntegrity->linkedIssuance($submission, $plaintext);
            } else {
                $this->assertOnlineSubmission($submission);
                $this->assertOnlineIssueDateIsToday($this->issueDates->read($plaintext));
            }

            $accessToken = $this->accessTokens->getValidAccessToken(
                $submission->environment,
                $contextNip,
            );
            $certificates = $this->onlineSession->publicKeyCertificates($submission->environment);
            $certificate = $this->publicKeys->resolve(
                $certificates,
                KsefPublicKeyUsage::SymmetricKeyEncryption,
            );
            $encryption = $this->encryption->encrypt($plaintext, $certificate);
            $submission = $this->storeEncryptionMetadata($submission, $encryption);
            $open = $this->onlineSession->openSession(
                $submission->environment,
                $accessToken,
                $this->requests->openSession($encryption),
            );
            $submission = $this->transition(
                $submission,
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
            if ($offline) {
                $submission = KsefInvoiceSubmission::query()->findOrFail($submission->getKey());
                $this->offlineIntegrity->linkedIssuance($submission, $plaintext);
            } else {
                $this->assertOnlineIssueDateIsToday($this->issueDates->read($plaintext));
            }
        } catch (KsefApiException $exception) {
            $submission = $this->closeSessionBestEffort($submission, $accessToken);
            $this->markTechnicalFailure($submission, $exception);

            throw $exception;
        }

        try {
            $invoiceReference = $this->onlineSession->sendInvoice(
                $submission->environment,
                $accessToken,
                $submission->session_reference_number,
                $offline
                    ? $this->requests->sendOfflineInvoice($submission, $encryption)
                    : $this->requests->sendInvoice($submission, $encryption),
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
            KsefInvoiceSubmissionStatus::Submitted,
            [
                'invoice_reference_number' => $invoiceReference,
                'next_follow_up_at' => $this->followUp->nextAttemptAt(0),
                'follow_up_attempts' => 0,
                'last_follow_up_at' => null,
                'last_follow_up_error_code' => null,
                'last_follow_up_error_message' => null,
                'safe_error_code' => null,
                'safe_error_message' => null,
            ],
        );

        return $this->closeSessionBestEffort($submission, $accessToken);
    }

    public function refreshStatus(KsefInvoiceSubmission $submission): KsefInvoiceSubmission
    {
        $this->assertTransportEnabled();
        $submission = KsefInvoiceSubmission::query()->findOrFail($submission->getKey());
        $this->environments->assertAllowed($submission->environment);
        if (! $submission->status->allowsStatusRefresh()) {
            throw $this->invalidState();
        }

        try {
            $contextNip = $this->submissionContextNip($submission);
            $this->submissionSellerNip($submission);
            $this->assertCurrentOfflineContext($submission);
            $accessToken = $this->accessTokens->getValidAccessToken(
                $submission->environment,
                $contextNip,
            );
            $data = $this->onlineSession->invoiceStatus(
                $submission->environment,
                $accessToken,
                $submission->session_reference_number,
                $submission->invoice_reference_number,
            );
        } catch (KsefApiException $exception) {
            $this->recordLookupFailure($submission, $exception, reconciliation: false);

            throw $exception;
        }

        return $this->applyInvoiceStatus($submission, $data);
    }

    public function reconcile(KsefInvoiceSubmission $submission): KsefInvoiceSubmission
    {
        $this->assertTransportEnabled();
        $submission = KsefInvoiceSubmission::query()->findOrFail($submission->getKey());
        $this->environments->assertAllowed($submission->environment);

        if (! $submission->status->allowsReconciliation()) {
            throw $this->invalidState();
        }

        try {
            $contextNip = $this->submissionContextNip($submission);
            $this->submissionSellerNip($submission);
            $this->assertPayloadIntegrity($submission);
            $this->assertCurrentOfflineContext($submission);
            $sessionReference = $this->requiredSubmissionReference(
                $submission->session_reference_number,
                'Próba nie posiada referencji sesji potrzebnej do bezpiecznego ustalenia wyniku transmisji.',
                'ksef_submission_reconciliation_unavailable',
            );
            $accessToken = $this->accessTokens->getValidAccessToken(
                $submission->environment,
                $contextNip,
            );

            if (is_string($submission->invoice_reference_number)
                && trim($submission->invoice_reference_number) !== '') {
                $data = $this->onlineSession->invoiceStatus(
                    $submission->environment,
                    $accessToken,
                    $sessionReference,
                    $submission->invoice_reference_number,
                );
            } else {
                [$submission, $data] = $this->recoverInvoiceReference(
                    $submission,
                    $this->onlineSession->sessionInvoices(
                        $submission->environment,
                        $accessToken,
                        $sessionReference,
                    ),
                );
            }
        } catch (KsefApiException $exception) {
            $this->recordLookupFailure($submission, $exception, reconciliation: true);

            throw $exception;
        }

        return $this->applyInvoiceStatus($submission, $data);
    }

    /** @return array{0: KsefInvoiceSubmission, 1: array<string, mixed>} */
    private function recoverInvoiceReference(
        KsefInvoiceSubmission $submission,
        array $data,
    ): array {
        $invoices = $data['invoices'] ?? null;
        $continuationToken = $data['continuationToken'] ?? null;

        if (! is_array($invoices)
            || ($continuationToken !== null && ! is_string($continuationToken))) {
            throw new KsefApiException(
                'KSeF zwrócił niekompletną odpowiedź podczas ustalania wyniku transmisji.',
                'ksef_reconciliation_response_incomplete',
            );
        }

        if (is_string($continuationToken) && trim($continuationToken) !== '') {
            throw new KsefApiException(
                'Nie można jednoznacznie ustalić wyniku transmisji KSeF.',
                'ksef_reconciliation_result_ambiguous',
            );
        }

        $matches = collect($invoices)
            ->filter(function (mixed $invoice) use ($submission): bool {
                if (! is_array($invoice)) {
                    return false;
                }

                $hash = $invoice['invoiceHash'] ?? null;

                return is_string($hash) && hash_equals($submission->invoice_hash, $hash);
            })
            ->values();

        if ($matches->count() !== 1) {
            throw new KsefApiException(
                'Nie udało się jednoznacznie odnaleźć Faktury w istniejącej sesji KSeF. Dokument nie został wysłany ponownie.',
                $matches->isEmpty()
                    ? 'ksef_reconciliation_result_unresolved'
                    : 'ksef_reconciliation_result_ambiguous',
            );
        }

        /** @var array<string, mixed> $match */
        $match = $matches->first();
        $invoiceReference = $this->requiredSubmissionReference(
            $match['referenceNumber'] ?? null,
            'KSeF zwrócił wynik transmisji bez referencji Faktury.',
            'ksef_reconciliation_response_incomplete',
        );
        $submission = DB::transaction(function () use ($submission, $invoiceReference): KsefInvoiceSubmission {
            $managed = KsefInvoiceSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());

            if (! $managed->status->allowsReconciliation()) {
                throw $this->invalidState();
            }

            if (is_string($managed->invoice_reference_number)
                && $managed->invoice_reference_number !== $invoiceReference) {
                throw new KsefApiException(
                    'Nie można jednoznacznie ustalić referencji Faktury KSeF.',
                    'ksef_reconciliation_result_ambiguous',
                );
            }

            $managed->forceFill(['invoice_reference_number' => $invoiceReference])->save();

            return $managed->refresh();
        }, 3);

        return [$submission, $match];
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
        $identityError = $this->statusIdentityError($submission, $data);

        if ($identityError !== null) {
            return $this->markStatusUncertain(
                $submission,
                $attributes,
                $identityError['code'],
                $identityError['message'],
            );
        }

        if ($code !== null && in_array($code, self::PROCESSING_STATUS_CODES, true)) {
            [$invoicingMode, $modeError] = $this->invoicingMode($data);

            if ($modeError !== null) {
                return $this->markStatusUncertain(
                    $submission,
                    $attributes,
                    $modeError['code'],
                    $modeError['message'],
                );
            }

            if ($invoicingMode !== null) {
                $attributes['invoicing_mode'] = $invoicingMode;
            }

            return $this->transition(
                $submission,
                KsefInvoiceSubmissionStatus::Processing,
                $attributes,
            );
        }

        if ($code === 200) {
            $ksefNumber = data_get($data, 'ksefNumber');

            if (! is_string($ksefNumber) || ! $this->ksefNumbers->isValid($ksefNumber)) {
                return $this->markStatusUncertain(
                    $submission,
                    $attributes,
                    'ksef_invoice_status_number_missing',
                    'KSeF zwrócił status sukcesu bez prawidłowego numeru KSeF.',
                );
            }

            $sellerNip = $submission->seller_nip;
            if (! is_string($sellerNip) || preg_match('/^\d{10}$/', $sellerNip) !== 1) {
                return $this->markStatusUncertain(
                    $submission,
                    $attributes,
                    'ksef_invoice_status_seller_identity_missing',
                    'Brak zamrożonego identyfikatora sprzedawcy potrzebnego do potwierdzenia numeru KSeF.',
                );
            }

            if (! hash_equals($sellerNip, substr($ksefNumber, 0, 10))) {
                return $this->markStatusUncertain(
                    $submission,
                    $attributes,
                    'ksef_invoice_status_seller_mismatch',
                    'KSeF zwrócił numer niezgodny ze sprzedawcą wysłanej Faktury.',
                );
            }

            [$invoicingMode, $modeError] = $this->invoicingMode($data);

            if ($modeError !== null || $invoicingMode === null) {
                return $this->markStatusUncertain(
                    $submission,
                    $attributes,
                    $modeError['code'] ?? 'ksef_invoice_status_invoicing_mode_missing',
                    $modeError['message'] ?? 'KSeF zwrócił status sukcesu bez trybu wystawienia Faktury.',
                );
            }

            try {
                $attributes += [
                    'invoicing_mode' => $invoicingMode,
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

            if (! $submission->hasExpectedInvoicingMode($invoicingMode)) {
                if ($submission->expectedInvoicingMode() === KsefInvoicingMode::Offline) {
                    $attributes['safe_error_code'] = 'ksef_invoice_unexpected_online_mode';
                    $attributes['safe_error_message'] = 'KSeF przyjął Fakturę Offline24, ale zwrócił tryb Online. Wydanie dokumentu i UPO pozostają zablokowane.';
                } else {
                    $attributes['safe_error_code'] = 'ksef_invoice_unexpected_offline_mode';
                    $attributes['safe_error_message'] = 'KSeF przyjął dokument, ale zakwalifikował go jako Offline. Obsługa trybu Offline wymaga osobnej ścieżki.';
                }
            }

            return $this->transition(
                $submission,
                KsefInvoiceSubmissionStatus::Accepted,
                $attributes,
            );
        }

        if ($code !== null && in_array($code, self::REJECTED_STATUS_CODES, true)) {
            return $this->transition(
                $submission,
                KsefInvoiceSubmissionStatus::Rejected,
                array_merge($attributes, [
                    'safe_error_code' => 'ksef_invoice_rejected',
                    'safe_error_message' => 'KSeF odrzucił Fakturę podczas weryfikacji.',
                ]),
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
            KsefInvoiceSubmissionStatus::TechnicalFailed,
            [
                'next_follow_up_at' => null,
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
            KsefInvoiceSubmissionStatus::Uncertain,
            [
                'next_follow_up_at' => $this->followUp->nextAttemptAt(0),
                'follow_up_attempts' => 0,
                'last_follow_up_at' => null,
                'last_follow_up_error_code' => null,
                'last_follow_up_error_message' => null,
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
            KsefInvoiceSubmissionStatus::Uncertain,
            array_merge($attributes, [
                'safe_error_code' => $safeCode,
                'safe_error_message' => $safeMessage,
            ]),
        );
    }

    private function transition(
        KsefInvoiceSubmission $submission,
        KsefInvoiceSubmissionStatus $to,
        array $attributes = [],
    ): KsefInvoiceSubmission {
        return DB::transaction(function () use ($submission, $to, $attributes): KsefInvoiceSubmission {
            $managed = KsefInvoiceSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());

            if (! $managed->status->canTransitionTo($to)) {
                throw $this->invalidState();
            }

            $invoicingMode = $attributes['invoicing_mode'] ?? $managed->invoicing_mode;
            $nextAction = $this->followUp->actionForStatus(
                $to,
                $managed->upo()->exists(),
                $invoicingMode,
                $managed->expectedInvoicingMode(),
            );
            if ($managed->follow_up_action !== $nextAction) {
                $attributes['follow_up_attempts'] = 0;
                $attributes['next_follow_up_at'] = $nextAction === null
                    ? null
                    : $this->followUp->nextAttemptAt(0);
            }
            $attributes['follow_up_action'] = $nextAction;

            if ($nextAction === null) {
                $attributes['next_follow_up_at'] = null;
            }

            $managed->forceFill($attributes + ['status' => $to])->save();
            $managed->refresh();

            if ($to === KsefInvoiceSubmissionStatus::Accepted
                && $managed->hasExpectedInvoicingMode()
                && $managed->invoice()->first()?->isInvoice() === true) {
                KsefInvoiceAccepted::dispatch($managed);
            }

            return $managed;
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

    private function recordLookupFailure(
        KsefInvoiceSubmission $submission,
        KsefApiException $exception,
        bool $reconciliation,
    ): void {
        DB::transaction(function () use ($submission, $exception, $reconciliation): void {
            $managed = KsefInvoiceSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());
            $canRecord = $reconciliation
                ? $managed->status->allowsReconciliation()
                : $managed->status->allowsStatusRefresh();

            if (! $canRecord) {
                return;
            }

            $managed->forceFill([
                'last_checked_at' => $this->forStorage(CarbonImmutable::now('UTC')),
                'safe_error_code' => $this->safeErrorCode($exception),
                'safe_error_message' => $this->safeMessage($exception),
            ])->save();
        }, 3);
    }

    private function assertStatus(KsefInvoiceSubmission $submission, array $allowed): void
    {
        if (! in_array($submission->status, $allowed, true)) {
            throw $this->invalidState();
        }
    }

    private function invalidState(): KsefApiException
    {
        return new KsefApiException(
            'Stan próby wysyłki KSeF nie pozwala na tę operację.',
            'ksef_submission_state_invalid',
        );
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

    private function assertOnlineSubmission(KsefInvoiceSubmission $submission): void
    {
        if ($submission->offline_issuance_id !== null) {
            throw new KsefApiException(
                'Próba Offline24 nie może zostać wysłana zwykłą ścieżką Online.',
                'ksef_submission_mode_invalid',
            );
        }
    }

    private function assertCurrentOfflineContext(KsefInvoiceSubmission $submission): void
    {
        if ($submission->offline_issuance_id === null) {
            return;
        }

        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->first();

        if ($settings === null || ! $settings->is_active) {
            throw new KsefApiException(
                'Integracja KSeF nie jest aktywna.',
                'ksef_submission_configuration_inactive',
            );
        }

        if (! is_string($settings->context_nip)
            || ! hash_equals((string) $submission->context_nip, $settings->context_nip)) {
            throw new KsefApiException(
                'Aby przekazać tę historyczną Fakturę Offline24, aktywny kontekst NIP KSeF musi odpowiadać kontekstowi zamrożonemu przy wystawieniu.',
                'ksef_offline_submission_context_not_current',
            );
        }
    }

    private function assertOnlineIssueDateIsToday(string $issueDate): void
    {
        if ($issueDate !== CarbonImmutable::now('Europe/Warsaw')->toDateString()) {
            throw new KsefApiException(
                'Data wystawienia P_1 zamrożonego dokumentu musi być dzisiejszą datą w Polsce dla wysyłki Online.',
                'ksef_online_submission_issue_date_not_today',
            );
        }
    }

    private function closeSessionBestEffort(
        KsefInvoiceSubmission $submission,
        string $accessToken,
    ): KsefInvoiceSubmission {
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

    /** @return array{0: ?KsefInvoicingMode, 1: array{code: string, message: string}|null} */
    private function invoicingMode(array $data): array
    {
        $value = data_get($data, 'invoicingMode');

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return [null, null];
        }

        if (! is_string($value) || KsefInvoicingMode::tryFrom($value) === null) {
            return [null, [
                'code' => 'ksef_invoice_status_invoicing_mode_unknown',
                'message' => 'KSeF zwrócił nierozpoznany tryb wystawienia Faktury.',
            ]];
        }

        return [KsefInvoicingMode::from($value), null];
    }

    private function configuredContextNip(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^\d{10}$/', $value) !== 1) {
            throw new KsefApiException(
                'Konfiguracja nie zawiera prawidłowego NIP-u kontekstu KSeF.',
                'ksef_submission_context_missing',
            );
        }

        return $value;
    }

    private function frozenSellerNip(Invoice $invoice): string
    {
        $sellerNip = $this->buyerIdentity->normalizePolishNip(
            data_get($invoice->seller_snapshot, 'tax_id'),
        );

        if ($sellerNip === null) {
            throw new KsefApiException(
                'Snapshot Faktury nie zawiera prawidłowego NIP-u sprzedawcy.',
                'ksef_submission_seller_identity_missing',
            );
        }

        return $sellerNip;
    }

    private function submissionContextNip(KsefInvoiceSubmission $submission): string
    {
        $contextNip = $submission->context_nip;

        if (! is_string($contextNip) || preg_match('/^\d{10}$/', $contextNip) !== 1) {
            throw new KsefApiException(
                'Próba wysyłki nie posiada zamrożonego kontekstu KSeF.',
                'ksef_submission_context_missing',
            );
        }

        return $contextNip;
    }

    private function submissionSellerNip(KsefInvoiceSubmission $submission): string
    {
        $sellerNip = $submission->seller_nip;

        if (! is_string($sellerNip) || preg_match('/^\d{10}$/', $sellerNip) !== 1) {
            throw new KsefApiException(
                'Próba wysyłki nie posiada zamrożonego identyfikatora sprzedawcy.',
                'ksef_submission_seller_identity_missing',
            );
        }

        return $sellerNip;
    }

    private function requiredSubmissionReference(
        mixed $value,
        string $message,
        string $safeCode,
    ): string {
        if (! is_string($value) || trim($value) === '') {
            throw new KsefApiException($message, $safeCode);
        }

        return trim($value);
    }

    /** @return array{code: string, message: string}|null */
    private function statusIdentityError(KsefInvoiceSubmission $submission, array $data): ?array
    {
        $referenceNumber = data_get($data, 'referenceNumber');
        $invoiceHash = data_get($data, 'invoiceHash');

        if (! is_string($referenceNumber) || $referenceNumber === ''
            || ! is_string($invoiceHash) || $invoiceHash === '') {
            return [
                'code' => 'ksef_invoice_status_identity_missing',
                'message' => 'KSeF zwrócił status bez identyfikatorów wysłanej Faktury.',
            ];
        }

        if ($referenceNumber !== $submission->invoice_reference_number
            || ! hash_equals($submission->invoice_hash, $invoiceHash)) {
            return [
                'code' => 'ksef_invoice_status_identity_mismatch',
                'message' => 'KSeF zwrócił status niezgodny z identyfikatorem wysłanej Faktury.',
            ];
        }

        return null;
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
