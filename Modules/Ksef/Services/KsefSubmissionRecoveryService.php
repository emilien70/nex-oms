<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus as Status;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\Fa3\KsefFa3IssueDateReader;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Throwable;

class KsefSubmissionRecoveryService
{
    public function __construct(
        private readonly KsefSubmissionRecoveryPolicy $policy,
        private readonly KsefSubmissionExecution $execution,
        private readonly KsefOfflineSubmissionIntegrityService $offline,
        private readonly KsefOfflineTechnicalCorrectionIntegrityService $technical,
        private readonly KsefSubmissionFollowUpPolicy $followUp,
        private readonly KsefFa3IssueDateReader $issueDates,
    ) {}

    public function inspect(int $submissionId): array
    {
        return $this->evaluate(KsefInvoiceSubmission::query()->findOrFail($submissionId)) + ['applied' => false];
    }

    public function apply(int $submissionId): array
    {
        // Only local reads and a conditional write can be retried; never transport.
        return DB::transaction(function () use ($submissionId): array {
            $submission = KsefInvoiceSubmission::query()->findOrFail($submissionId);
            $result = $this->evaluate($submission);
            if (! in_array($result['decision'], ['BEFORE_POST', 'POST_MAY_HAVE_OCCURRED'], true)) {
                return $result + ['applied' => false];
            }
            $uncertain = $result['decision'] === 'POST_MAY_HAVE_OCCURRED';
            $this->execution->write($submission, $this->execution->compare($submission), [
                'execution_owner' => null,
                'status' => $uncertain ? Status::Uncertain : Status::TechnicalFailed,
                'recovery_code' => $result['reason'],
                'recovered_at' => CarbonImmutable::now('UTC'),
                'safe_error_code' => $result['reason'],
                'safe_error_message' => $uncertain
                    ? 'Przerwano wykonanie po granicy wysyłki. Ustal wynik istniejącej próby, bez ponownego wysłania.'
                    : 'Przerwano wykonanie przed wysłaniem. Prawo poprzedniego wykonawcy zostało unieważnione.',
                'follow_up_action' => $uncertain ? KsefSubmissionFollowUpPolicy::ACTION_RECONCILE : null,
                'next_follow_up_at' => $uncertain ? $this->followUp->nextAttemptAt(0) : null,
                'follow_up_attempts' => 0,
            ]);

            return $result + ['applied' => true];
        }, 3);
    }

    private function evaluate(KsefInvoiceSubmission $submission): array
    {
        return $this->policy->classify(
            $submission,
            $submission->upo()->exists(),
            $this->integrityValid($submission),
            CarbonImmutable::now('UTC'),
        );
    }

    private function integrityValid(KsefInvoiceSubmission $submission): bool
    {
        try {
            // Completed history does not need new execution metadata or payload decryption.
            if ($submission->status->isTerminal() || $submission->status->allowsStatusRefresh()) {
                return true;
            }
            $invoice = $submission->invoice()->first();
            $payload = $submission->payload_xml;
            if ($invoice === null || (! $invoice->isInvoice() && ! $invoice->isCorrection())
                || ! $invoice->isIssued() || ! $invoice->isFinalized()
                || ! in_array($submission->environment, KsefEnvironment::cases(), true)
                || preg_match('/^\d{10}$/D', (string) $submission->context_nip) !== 1
                || preg_match('/^\d{10}$/D', (string) $submission->seller_nip) !== 1
                || ! is_string($payload) || $payload === '' || $submission->schema_id !== 'FA (3) 1-0E'
                || strlen($payload) !== $submission->invoice_size
                || base64_encode(hash('sha256', $payload, true)) !== $submission->invoice_hash) {
                return false;
            }
            if ($submission->offline_technical_correction_id !== null) {
                $this->technical->linkedArtifact($submission, $payload);
            } elseif ($submission->offline_issuance_id !== null) {
                $this->offline->linkedIssuance($submission, $payload);
            } else {
                if ($this->issueDates->read($payload) !== $invoice->issue_date?->format('Y-m-d')) {
                    return false;
                }
                // The date reader has already rejected DTDs and malformed/non-FA(3) XML.
                $document = new DOMDocument;
                $document->resolveExternals = false;
                $document->substituteEntities = false;
                $document->loadXML($payload, LIBXML_NONET | LIBXML_NOBLANKS);
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);
                foreach ([
                    '/fa:Faktura/fa:Fa/fa:P_2' => $invoice->number,
                    '/fa:Faktura/fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP' => $submission->seller_nip,
                ] as $path => $expected) {
                    $nodes = $xpath->query($path);
                    if ($nodes->length !== 1 || $nodes->item(0)->textContent !== $expected) {
                        return false;
                    }
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
