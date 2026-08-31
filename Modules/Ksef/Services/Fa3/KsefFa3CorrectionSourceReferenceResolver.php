<?php

namespace Modules\Ksef\Services\Fa3;

use Illuminate\Support\Collection;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefFa3CorrectionRootReferenceType;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefFa3BuyerIdentityResolver;
use Modules\Ksef\Services\KsefNumberValidator;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionSourceReference;

final class KsefFa3CorrectionSourceReferenceResolver
{
    public function __construct(
        private readonly CorrectionSourceStateService $sourceState,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
        private readonly KsefNumberValidator $ksefNumbers,
    ) {}

    public function resolve(
        Invoice $correction,
        KsefEnvironment $environment,
    ): KsefFa3CorrectionSourceReference {
        if ($correction->document_type !== InvoiceDocumentType::Correction
            || $correction->status !== InvoiceDocumentStatus::Issued) {
            throw $this->sourceInvalid($correction);
        }

        $root = $correction->correctedInvoice()->first();
        if (! $root instanceof Invoice) {
            throw $this->sourceInvalid($correction);
        }

        try {
            $chain = $this->sourceState->chain($root);
        } catch (InvoiceDomainException $exception) {
            throw $this->sourceInvalid($correction, $exception);
        }

        $targetIndex = $chain->corrections->search(
            static fn (Invoice $candidate): bool => $candidate->is($correction),
        );
        if (! is_int($targetIndex)) {
            throw $this->sourceInvalid($correction);
        }

        $rootNumber = is_string($root->number) ? trim($root->number) : '';
        $rootIssueDate = $root->issue_date?->toDateString();
        $rootSellerNip = $this->buyerIdentity->normalizePolishNip(
            data_get($root->seller_snapshot, 'tax_id'),
        );
        if ($rootNumber === '' || $rootIssueDate === null || $rootSellerNip === null) {
            throw $this->sourceInvalid($correction);
        }

        $rootReference = $this->rootReference(
            $root,
            $environment,
            $rootSellerNip,
        );

        $precedingCorrections = $chain->corrections
            ->take($targetIndex)
            ->map(function (Invoice $preceding) use ($environment, $rootSellerNip): array {
                $submission = $this->acceptedSubmission(
                    $preceding,
                    $environment,
                    'previous',
                    $rootSellerNip,
                );

                return [
                    'correction_id' => (int) $preceding->getKey(),
                    'submission_id' => (int) $submission->getKey(),
                    'ksef_number' => trim((string) $submission->ksef_number),
                ];
            })
            ->values()
            ->all();

        if ($rootReference['type'] === KsefFa3CorrectionRootReferenceType::OutsideKsef) {
            return KsefFa3CorrectionSourceReference::outsideKsef(
                environment: $environment,
                rootInvoiceId: (int) $root->getKey(),
                rootInvoiceNumber: $rootNumber,
                correctedInvoiceIssueDate: $rootIssueDate,
                rootProvenanceId: (int) $rootReference['provenance']->getKey(),
                precedingCorrections: $precedingCorrections,
            );
        }

        return KsefFa3CorrectionSourceReference::ksef(
            environment: $environment,
            rootInvoiceId: (int) $root->getKey(),
            rootInvoiceNumber: $rootNumber,
            correctedInvoiceIssueDate: $rootIssueDate,
            rootSubmissionId: (int) $rootReference['submission']->getKey(),
            rootKsefNumber: trim((string) $rootReference['submission']->ksef_number),
            precedingCorrections: $precedingCorrections,
        );
    }

    /**
     * @return array{
     *     type: KsefFa3CorrectionRootReferenceType,
     *     submission: KsefInvoiceSubmission|null,
     *     provenance: KsefInvoiceProvenance|null
     * }
     */
    private function rootReference(
        Invoice $root,
        KsefEnvironment $environment,
        string $rootSellerNip,
    ): array {
        $provenances = KsefInvoiceProvenance::query()
            ->where('invoice_id', $root->getKey())
            ->where('environment', $environment->value)
            ->orderBy('id')
            ->get();
        $submissions = KsefInvoiceSubmission::query()
            ->where('invoice_id', $root->getKey())
            ->where('environment', $environment->value)
            ->orderBy('id')
            ->get(['id', 'environment', 'status']);

        if ($provenances->isNotEmpty()) {
            if ($provenances->count() !== 1
                || $provenances->first()->provenance !== KsefInvoiceProvenanceType::OutsideKsef
                || $submissions->isNotEmpty()) {
                throw $this->provenanceConflict($root, $environment, $submissions);
            }

            return [
                'type' => KsefFa3CorrectionRootReferenceType::OutsideKsef,
                'submission' => null,
                'provenance' => $provenances->first(),
            ];
        }

        return [
            'type' => KsefFa3CorrectionRootReferenceType::Ksef,
            'submission' => $this->acceptedSubmission(
                $root,
                $environment,
                'root',
                $rootSellerNip,
            ),
            'provenance' => null,
        ];
    }

    private function acceptedSubmission(
        Invoice $document,
        KsefEnvironment $environment,
        string $role,
        string $rootSellerNip,
    ): KsefInvoiceSubmission {
        $submissions = KsefInvoiceSubmission::query()
            ->where('invoice_id', $document->getKey())
            ->where('environment', $environment->value)
            ->orderBy('id')
            ->get(['id', 'environment', 'status', 'seller_nip', 'ksef_number']);
        $accepted = $submissions->filter(
            static fn (KsefInvoiceSubmission $submission): bool => $submission->status === KsefInvoiceSubmissionStatus::Accepted,
        )->values();

        if ($accepted->count() > 1) {
            throw $this->referenceError(
                $role,
                'ambiguous',
                $document,
                $environment,
            );
        }

        if ($accepted->isEmpty()) {
            if ($submissions->isEmpty()) {
                $acceptedEnvironments = $this->acceptedEnvironments($document, $environment);
                if ($acceptedEnvironments !== []) {
                    throw $this->referenceError(
                        $role,
                        'environment_mismatch',
                        $document,
                        $environment,
                        ['accepted_environments' => $acceptedEnvironments],
                    );
                }
            }

            throw $this->referenceError(
                $role,
                $submissions->isEmpty() ? 'unresolved' : 'not_accepted',
                $document,
                $environment,
                ['submission_statuses' => $this->submissionStatuses($submissions)],
            );
        }

        /** @var KsefInvoiceSubmission $submission */
        $submission = $accepted->first();
        $sellerNip = $submission->seller_nip;
        $ksefNumber = is_string($submission->ksef_number) ? $submission->ksef_number : '';

        if (! is_string($sellerNip)
            || preg_match('/^\d{10}$/', $sellerNip) !== 1
            || ! hash_equals($rootSellerNip, $sellerNip)
            || $ksefNumber === ''
            || $ksefNumber !== trim($ksefNumber)
            || ! $this->ksefNumbers->isValid($ksefNumber)
            || ! hash_equals($sellerNip, substr($ksefNumber, 0, 10))) {
            throw $this->referenceError(
                $role,
                'reference_invalid',
                $document,
                $environment,
            );
        }

        return $submission;
    }

    /** @return list<string> */
    private function acceptedEnvironments(Invoice $document, KsefEnvironment $environment): array
    {
        return KsefInvoiceSubmission::query()
            ->where('invoice_id', $document->getKey())
            ->where('status', KsefInvoiceSubmissionStatus::Accepted->value)
            ->where('environment', '!=', $environment->value)
            ->get(['environment'])
            ->map(static fn (KsefInvoiceSubmission $submission): string => $submission->environment->value)
            ->uniqueStrict()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, KsefInvoiceSubmission>  $submissions
     * @return list<string>
     */
    private function submissionStatuses(Collection $submissions): array
    {
        return $submissions
            ->map(static fn (KsefInvoiceSubmission $submission): string => $submission->status->value)
            ->uniqueStrict()
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $metadata */
    private function referenceError(
        string $role,
        string $reason,
        Invoice $document,
        KsefEnvironment $environment,
        array $metadata = [],
    ): InvoiceDomainException {
        $prefix = $role === 'root'
            ? 'ksef_fa3_correction_source_ksef_'
            : 'ksef_fa3_correction_previous_ksef_';
        $code = $prefix.($role === 'previous' && $reason === 'unresolved' ? 'not_accepted' : $reason);
        $message = match ([$role, $reason]) {
            ['root', 'unresolved'] => 'Nie można potwierdzić statusu KSeF Faktury pierwotnej dla tej Korekty.',
            ['root', 'not_accepted'] => 'Faktura pierwotna nie została zaakceptowana w wymaganym środowisku KSeF.',
            ['root', 'environment_mismatch'] => 'Faktura pierwotna została zaakceptowana wyłącznie w innym środowisku KSeF.',
            ['root', 'ambiguous'] => 'Faktura pierwotna posiada niejednoznaczną historię zaakceptowanych transmisji KSeF.',
            ['root', 'reference_invalid'] => 'Zaakceptowana referencja KSeF Faktury pierwotnej jest nieprawidłowa.',
            ['previous', 'unresolved'], ['previous', 'not_accepted'] => 'Poprzednia Korekta nie została zaakceptowana w wymaganym środowisku KSeF.',
            ['previous', 'environment_mismatch'] => 'Poprzednia Korekta została zaakceptowana wyłącznie w innym środowisku KSeF.',
            ['previous', 'ambiguous'] => 'Poprzednia Korekta posiada niejednoznaczną historię zaakceptowanych transmisji KSeF.',
            ['previous', 'reference_invalid'] => 'Zaakceptowana referencja KSeF poprzedniej Korekty jest nieprawidłowa.',
            default => 'Nie można ustalić referencji KSeF dla Korekty.',
        };

        return new InvoiceDomainException(
            $code,
            $message,
            [
                'environment' => $environment->value,
                'role' => $role,
                ...($role === 'root'
                    ? ['root_invoice_id' => (int) $document->getKey()]
                    : [
                        'previous_correction_id' => (int) $document->getKey(),
                        'previous_correction_number' => $document->number,
                    ]),
                ...$metadata,
            ],
        );
    }

    /** @param Collection<int, KsefInvoiceSubmission> $submissions */
    private function provenanceConflict(
        Invoice $root,
        KsefEnvironment $environment,
        Collection $submissions,
    ): InvoiceDomainException {
        return new InvoiceDomainException(
            'ksef_fa3_correction_source_provenance_conflict',
            'Jawne pochodzenie Faktury pierwotnej jest sprzeczne z jej historią KSeF.',
            [
                'environment' => $environment->value,
                'root_invoice_id' => (int) $root->getKey(),
                'reason' => $submissions->isNotEmpty()
                    ? 'submission_history_exists'
                    : 'provenance_invalid',
                'submission_statuses' => $this->submissionStatuses($submissions),
            ],
        );
    }

    private function sourceInvalid(
        Invoice $correction,
        ?InvoiceDomainException $previous = null,
    ): InvoiceDomainException {
        return new InvoiceDomainException(
            'ksef_fa3_correction_source_invalid',
            'Nie można ustalić Faktury pierwotnej ani łańcucha dla tej Korekty.',
            ['correction_id' => $correction->getKey()],
            $previous,
        );
    }
}
