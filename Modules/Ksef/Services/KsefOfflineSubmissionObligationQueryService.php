<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\ValueObjects\KsefOfflineSubmissionObligation;

final class KsefOfflineSubmissionObligationQueryService
{
    public function __construct(
        private readonly KsefLatarniaEvidenceService $evidence,
        private readonly KsefOfflineSubmissionObligationEngine $engine,
    ) {}

    /**
     * @param  iterable<Invoice>  $invoices
     * @return Collection<int, Collection<int, array{environment: KsefEnvironment, obligation: KsefOfflineSubmissionObligation}>>
     */
    public function forInvoices(iterable $invoices, CarbonImmutable $asOf): Collection
    {
        $invoiceIds = collect($invoices)->map(fn (Invoice $invoice): int => (int) $invoice->getKey())->all();

        if ($invoiceIds === []) {
            return collect();
        }

        $issuances = KsefOfflineIssuance::query()
            ->whereIntegerInRaw('invoice_id', $invoiceIds)
            ->get();

        if ($issuances->isEmpty()) {
            return collect();
        }

        $supportedEnvironmentValues = $issuances
            ->pluck('environment')
            ->filter(fn (KsefEnvironment $environment): bool => $environment !== KsefEnvironment::Demo)
            ->map(fn (KsefEnvironment $environment): string => $environment->value)
            ->unique()
            ->values();

        $states = $supportedEnvironmentValues->isEmpty()
            ? collect()
            : KsefLatarniaSyncState::query()
                ->whereIn('source_environment', $supportedEnvironmentValues->all())
                ->get()
                ->keyBy(fn (KsefLatarniaSyncState $state): string => $state->source_environment->value);

        $messages = $supportedEnvironmentValues->isEmpty()
            ? collect()
            : KsefLatarniaMessage::query()
                ->whereIn('source_environment', $supportedEnvironmentValues->all())
                ->where('published_at', '<=', $this->databaseInstant($asOf))
                ->where('first_fetched_at', '<=', $this->databaseInstant($asOf))
                ->get([
                    'id',
                    'source_environment',
                    'external_message_id',
                    'event_id',
                    'version',
                    'category',
                    'type',
                    'title',
                    'text',
                    'start_at',
                    'end_at',
                    'published_at',
                    'first_fetched_at',
                    'last_seen_at',
                ])
                ->groupBy(fn (KsefLatarniaMessage $message): string => $message->source_environment->value);

        $submissions = KsefInvoiceSubmission::query()
            ->whereIntegerInRaw('offline_issuance_id', $issuances->modelKeys())
            ->get([
                'id',
                'invoice_id',
                'offline_issuance_id',
                'environment',
                'attempt_number',
                'status',
                'invoicing_mode',
                'invoice_reference_number',
            ])
            ->groupBy('offline_issuance_id');

        return $issuances
            ->map(function (KsefOfflineIssuance $issuance) use ($states, $messages, $submissions, $asOf): array {
                $environment = $issuance->environment->value;
                $snapshot = $this->evidence->snapshot($issuance, $states->get($environment), $asOf);
                $evidenceMessages = $snapshot->latarniaEnvironment === null
                    ? collect()
                    : $messages->get($environment, collect())
                        ->filter(fn (KsefLatarniaMessage $message): bool => ! $message->published_at->greaterThan($snapshot->evaluationAsOf)
                            && ! $message->first_fetched_at->greaterThan($snapshot->evaluationAsOf));

                return [
                    'invoice_id' => (int) $issuance->invoice_id,
                    'environment' => $issuance->environment,
                    'obligation' => $this->engine->evaluate(
                        $issuance,
                        $evidenceMessages,
                        $submissions->get($issuance->getKey(), collect()),
                        $snapshot->evaluationAsOf,
                        $snapshot->coverage,
                    ),
                ];
            })
            ->groupBy('invoice_id')
            ->map(fn (Collection $rows): Collection => $rows->map(fn (array $row): array => [
                'environment' => $row['environment'],
                'obligation' => $row['obligation'],
            ])->values());
    }

    private function databaseInstant(CarbonImmutable $instant): string
    {
        return $instant->utc()->format((new KsefLatarniaMessage)->getDateFormat());
    }
}
