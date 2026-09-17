<?php

namespace Modules\Invoices\Services;

use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefFa3BuyerIdentityResolver;
use Modules\Ksef\Services\KsefNumberValidator;
use UnexpectedValueException;

/** Reads only local acceptance metadata; never loads encrypted payloads or credentials. */
final class SalesRegisterKsefNumberReader
{
    public function __construct(
        private readonly KsefNumberValidator $numbers,
        private readonly KsefFa3BuyerIdentityResolver $identities,
        private readonly SalesRegisterValues $values,
    ) {}

    public function read(ModelCollection $documents): array
    {
        $ids = $documents->modelKeys();
        $production = KsefEnvironment::Production->value;
        $submissions = DB::table('ksef_invoice_submissions')
            ->whereIn('invoice_id', $ids)->where('environment', $production)
            ->where('status', KsefInvoiceSubmissionStatus::Accepted->value)
            ->get(['id', 'invoice_id', 'environment', 'offline_issuance_id', 'offline_technical_correction_id',
                'seller_nip', 'context_nip', 'schema_id', 'invoice_hash', 'invoice_size', 'invoicing_mode', 'ksef_number', 'acquisition_date']);
        $issuances = DB::table('ksef_offline_issuances')->whereIn('id', $submissions->pluck('offline_issuance_id')->filter())
            ->get(['id', 'invoice_id', 'environment', 'issue_date', 'seller_nip', 'context_identifier_value', 'schema_id', 'invoice_hash', 'invoice_size'])->keyBy('id');
        $technical = DB::table('ksef_offline_technical_corrections')->whereIn('id', $submissions->pluck('offline_technical_correction_id')->filter())
            ->get(['id', 'invoice_id', 'environment', 'offline_issuance_id', 'rejected_submission_id', 'seller_nip', 'context_nip', 'schema_id', 'invoice_hash', 'invoice_size', 'hash_of_corrected_invoice'])->keyBy('id');
        $rejected = DB::table('ksef_invoice_submissions')->whereIn('id', $technical->pluck('rejected_submission_id'))
            ->get(['id', 'invoice_id', 'environment', 'offline_issuance_id', 'status', 'invoice_hash'])->keyBy('id');
        $outside = DB::table('ksef_invoice_provenances')->whereIn('invoice_id', $ids)->where('environment', $production)->pluck('invoice_id');
        $byDocument = $submissions->groupBy('invoice_id');
        $result = [];
        foreach ($documents as $document) {
            $candidates = $byDocument->get($document->id, collect());
            $numbers = [];
            $invalid = $candidates->isNotEmpty() && $outside->contains($document->id);
            foreach ($candidates as $submission) {
                if (! $this->valid($document, $submission, $issuances, $technical, $rejected)) {
                    $invalid = true;
                } else {
                    $numbers[] = $submission->ksef_number;
                }
            }
            $numbers = array_values(array_unique($numbers));
            $code = $invalid ? 'ksef_link_invalid' : (count($numbers) > 1 ? 'ksef_number_ambiguous' : null);
            $number = $code === null && count($numbers) === 1 ? $numbers[0] : null;
            [$date, $dateCode] = $number !== null ? $this->authorizationDate($candidates) : [null, null];
            $result[$document->id] = [
                'number' => $number, 'authorization_date' => $date,
                'warnings' => array_map(fn (string $warning) => [
                    'code' => 'sales_register_'.$warning, 'document_id' => (int) $document->id, 'section' => 'ksef',
                ], array_values(array_filter([$code, $dateCode]))),
            ];
        }

        return $result;
    }

    private function authorizationDate(Collection $candidates): array
    {
        $instants = [];
        foreach ($candidates as $submission) {
            try {
                // Reuse the submission's strict UTC cast, without loading payloads or relations.
                $instant = (new KsefInvoiceSubmission)->newFromBuilder([
                    'acquisition_date' => $submission->acquisition_date,
                ])->acquisition_date;
            } catch (UnexpectedValueException) {
                return [null, 'ksef_authorization_date_unavailable'];
            }
            if ($instant === null) {
                return [null, 'ksef_authorization_date_unavailable'];
            }
            $instants[$instant->format('Y-m-d H:i:s.u')] = $instant;
        }
        if (count($instants) !== 1) {
            return [null, 'ksef_authorization_date_conflict'];
        }

        return [reset($instants)->setTimezone(config('app.timezone'))->format('Y-m-d'), null];
    }

    private function valid(Invoice $document, object $submission, Collection $issuances, Collection $technical, Collection $rejected): bool
    {
        $seller = $document->seller_snapshot;
        $scalarTaxId = $document->seller_tax_id_snapshot;
        $taxId = is_array($seller) && array_key_exists('tax_id', $seller) ? $seller['tax_id'] : $scalarTaxId;
        if (! is_string($taxId) || ($scalarTaxId !== null && ! is_string($scalarTaxId))) {
            return false;
        }
        $nip = $this->identities->normalizePolishNip($taxId);
        $scalarNip = $scalarTaxId !== null ? $this->identities->normalizePolishNip($scalarTaxId) : null;
        if ($this->values->text($scalarTaxId) !== null && $scalarNip === null) {
            return false;
        }
        $hash = base64_decode((string) $submission->invoice_hash, true);
        if ($nip === null || ($scalarNip !== null && $nip !== $scalarNip)
            || $submission->seller_nip !== $nip || $submission->context_nip !== $nip
            || ! is_string($submission->ksef_number) || ! $this->numbers->isValid($submission->ksef_number)
            || ! str_starts_with($submission->ksef_number, $nip.'-')
            || $this->values->text($submission->schema_id) === null
            || filter_var($submission->invoice_size, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || $hash === false || strlen($hash) !== 32 || base64_encode($hash) !== $submission->invoice_hash) {
            return false;
        }
        if ($submission->offline_issuance_id === null) {
            return $submission->offline_technical_correction_id === null
                && in_array($submission->invoicing_mode, [null, KsefInvoicingMode::Online->value], true);
        }
        $issuance = $issuances->get($submission->offline_issuance_id);
        if ($issuance === null || $submission->invoicing_mode !== KsefInvoicingMode::Offline->value
            || $issuance->invoice_id !== $document->id || $issuance->environment !== $submission->environment
            || $this->values->date($issuance->issue_date) === null
            || $this->values->date($issuance->issue_date) !== $this->values->date($document->getRawOriginal('issue_date'))
            || $issuance->seller_nip !== $nip || $issuance->context_identifier_value !== $nip
            || $issuance->schema_id !== $submission->schema_id) {
            return false;
        }
        if ($submission->offline_technical_correction_id === null) {
            return $issuance->invoice_hash === $submission->invoice_hash && $issuance->invoice_size === $submission->invoice_size;
        }
        $artifact = $technical->get($submission->offline_technical_correction_id);
        $source = $artifact !== null ? $rejected->get($artifact->rejected_submission_id) : null;

        return $artifact !== null && $source !== null
            && $artifact->invoice_id === $document->id && $artifact->environment === $submission->environment
            && $artifact->offline_issuance_id === $issuance->id
            && $artifact->seller_nip === $nip && $artifact->context_nip === $nip
            && $artifact->schema_id === $submission->schema_id
            && $artifact->invoice_hash === $submission->invoice_hash && $artifact->invoice_size === $submission->invoice_size
            && $artifact->hash_of_corrected_invoice === $issuance->invoice_hash
            && $source->invoice_id === $document->id && $source->environment === $submission->environment
            && $source->offline_issuance_id === $issuance->id && $source->invoice_hash === $issuance->invoice_hash
            && $source->status === KsefInvoiceSubmissionStatus::Rejected->value;
    }
}
