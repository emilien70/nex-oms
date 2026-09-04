<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaMessageType;
use Modules\Ksef\Enums\KsefLatarniaStatus;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\ValueObjects\KsefLatarniaFailureEvent;
use Modules\Ksef\ValueObjects\KsefLatarniaMaintenanceEvent;
use Modules\Ksef\ValueObjects\KsefOfflineProcedureEligibilitySnapshot;

final class KsefOfflineProcedureEligibilityService
{
    public function __construct(
        private readonly KsefLatarniaFailureEventProjector $failures,
        private readonly KsefLatarniaMaintenanceEventProjector $maintenance,
    ) {}

    public function requireEligible(
        KsefOfflineIssuanceProcedure $procedure,
        KsefEnvironment $environment,
        CarbonImmutable $issuedAt,
        bool $lock = false,
    ): KsefOfflineProcedureEligibilitySnapshot {
        $snapshot = $this->snapshot($procedure, $environment, $issuedAt, $lock);

        if (! $snapshot->eligible) {
            throw new KsefApiException(
                $snapshot->message ?? 'Lokalne dane Latarni KSeF nie potwierdzają dostępności wybranego trybu.',
                $snapshot->errorCode ?? 'ksef_offline_procedure_not_eligible',
            );
        }

        return $snapshot;
    }

    public function snapshot(
        KsefOfflineIssuanceProcedure $procedure,
        KsefEnvironment $environment,
        CarbonImmutable $issuedAt,
        bool $lock = false,
    ): KsefOfflineProcedureEligibilitySnapshot {
        $issuedAt = $issuedAt->utc();

        if ($procedure === KsefOfflineIssuanceProcedure::Offline24) {
            return new KsefOfflineProcedureEligibilitySnapshot(
                $procedure,
                $environment,
                $this->latarniaEnvironment($environment),
                true,
                null,
                null,
            );
        }

        $latarniaEnvironment = $this->latarniaEnvironment($environment);

        if ($latarniaEnvironment === null) {
            return $this->unavailable(
                $procedure,
                $environment,
                null,
                'ksef_offline_procedure_unsupported_environment',
                'Latarnia KSeF nie obsługuje środowiska DEMO dla tej procedury.',
            );
        }

        $stateQuery = KsefLatarniaSyncState::query()
            ->where('source_environment', $latarniaEnvironment->value);
        $state = $this->locked($stateQuery, $lock)->first();

        if (! $state instanceof KsefLatarniaSyncState || ! $this->isFresh($state, $issuedAt)) {
            return $this->unavailable(
                $procedure,
                $environment,
                $latarniaEnvironment,
                'ksef_offline_procedure_latarnia_stale',
                'Odśwież dane Latarni KSeF przed wystawieniem w tym trybie.',
                $state,
            );
        }

        $expectedStatus = $procedure === KsefOfflineIssuanceProcedure::PlannedUnavailability
            ? KsefLatarniaStatus::Maintenance
            : KsefLatarniaStatus::Failure;

        if ($state->current_status !== $expectedStatus) {
            return $this->unavailable(
                $procedure,
                $environment,
                $latarniaEnvironment,
                'ksef_offline_procedure_status_mismatch',
                $procedure === KsefOfflineIssuanceProcedure::PlannedUnavailability
                    ? 'Latarnia KSeF nie potwierdza obecnie planowanej niedostępności.'
                    : 'Latarnia KSeF nie potwierdza obecnie zwykłej awarii.',
                $state,
            );
        }

        $messagesQuery = KsefLatarniaMessage::query()
            ->where('source_environment', $latarniaEnvironment->value)
            ->where('published_at', '<=', $this->databaseInstant($issuedAt))
            ->where('first_fetched_at', '<=', $this->databaseInstant($issuedAt));
        $messages = $this->locked($messagesQuery, $lock)->get();

        return match ($procedure) {
            KsefOfflineIssuanceProcedure::PlannedUnavailability => $this->plannedSnapshot(
                $environment,
                $latarniaEnvironment,
                $issuedAt,
                $state,
                $messages,
            ),
            KsefOfflineIssuanceProcedure::Failure => $this->failureSnapshot(
                $environment,
                $latarniaEnvironment,
                $issuedAt,
                $state,
                $messages,
            ),
            KsefOfflineIssuanceProcedure::Offline24 => throw new \LogicException('Offline24 handled above.'),
        };
    }

    /** @param Collection<int, KsefLatarniaMessage> $messages */
    private function plannedSnapshot(
        KsefEnvironment $environment,
        KsefLatarniaEnvironment $latarniaEnvironment,
        CarbonImmutable $issuedAt,
        KsefLatarniaSyncState $state,
        Collection $messages,
    ): KsefOfflineProcedureEligibilitySnapshot {
        $projection = $this->maintenance->project($messages, $latarniaEnvironment);
        $active = collect($projection->events)
            ->filter(fn (KsefLatarniaMaintenanceEvent $event): bool => ! $event->publishedAt->greaterThan($issuedAt)
                && ! $event->firstFetchedAt->greaterThan($issuedAt)
                && ! $event->startAt->greaterThan($issuedAt)
                && ! $event->endAt->lessThan($issuedAt))
            ->values();

        if ($projection->isAmbiguous() || $active->count() !== 1) {
            $ambiguous = $projection->isAmbiguous() || $active->count() > 1;

            return $this->unavailable(
                KsefOfflineIssuanceProcedure::PlannedUnavailability,
                $environment,
                $latarniaEnvironment,
                $ambiguous
                    ? 'ksef_offline_procedure_latarnia_ambiguous'
                    : 'ksef_offline_procedure_event_missing',
                $ambiguous
                    ? 'Dane Latarni KSeF są niejednoznaczne. Nie wystawiono Faktury Offline.'
                    : 'Brak jednoznacznego aktywnego komunikatu o niedostępności KSeF.',
                $state,
            );
        }

        /** @var KsefLatarniaMaintenanceEvent $event */
        $event = $active->first();

        return $this->eligible(
            KsefOfflineIssuanceProcedure::PlannedUnavailability,
            $environment,
            $latarniaEnvironment,
            $state,
            $event->eventId,
            $event->messageId,
            $event->messageVersion,
            KsefLatarniaMessageCategory::Maintenance,
            $event->startAt,
            $event->endAt,
            $event->publishedAt,
        );
    }

    /** @param Collection<int, KsefLatarniaMessage> $messages */
    private function failureSnapshot(
        KsefEnvironment $environment,
        KsefLatarniaEnvironment $latarniaEnvironment,
        CarbonImmutable $issuedAt,
        KsefLatarniaSyncState $state,
        Collection $messages,
    ): KsefOfflineProcedureEligibilitySnapshot {
        $projection = $this->failures->project($messages, $latarniaEnvironment);
        $active = collect($projection->events)
            ->filter(fn (KsefLatarniaFailureEvent $event): bool => $event->category === KsefLatarniaMessageCategory::Failure
                && ! $event->publishedAt->greaterThan($issuedAt)
                && ! $event->startAt->greaterThan($issuedAt)
                && ($event->endAt === null || ! $event->endAt->lessThan($issuedAt)))
            ->values();

        if ($projection->isAmbiguous() || $active->count() !== 1) {
            $ambiguous = $projection->isAmbiguous() || $active->count() > 1;

            return $this->unavailable(
                KsefOfflineIssuanceProcedure::Failure,
                $environment,
                $latarniaEnvironment,
                $ambiguous
                    ? 'ksef_offline_procedure_latarnia_ambiguous'
                    : 'ksef_offline_procedure_event_missing',
                $ambiguous
                    ? 'Dane Latarni KSeF są niejednoznaczne. Nie wystawiono Faktury Offline.'
                    : 'Brak jednoznacznego aktywnego komunikatu o awarii KSeF.',
                $state,
            );
        }

        /** @var KsefLatarniaFailureEvent $event */
        $event = $active->first();
        $trigger = $messages
            ->filter(fn (KsefLatarniaMessage $message): bool => $message->event_id === $event->eventId
                && $message->category === KsefLatarniaMessageCategory::Failure
                && $message->type === KsefLatarniaMessageType::FailureStart)
            ->groupBy('external_message_id')
            ->map(fn (Collection $versions): KsefLatarniaMessage => $versions
                ->sortByDesc(fn (KsefLatarniaMessage $message): int => $message->version)
                ->first())
            ->values()
            ->sole();

        return $this->eligible(
            KsefOfflineIssuanceProcedure::Failure,
            $environment,
            $latarniaEnvironment,
            $state,
            $event->eventId,
            $trigger->external_message_id,
            $trigger->version,
            KsefLatarniaMessageCategory::Failure,
            $event->startAt,
            $event->endAt,
            $event->publishedAt,
        );
    }

    private function isFresh(KsefLatarniaSyncState $state, CarbonImmutable $issuedAt): bool
    {
        $freshSince = $issuedAt->subMinutes(max(1, (int) config('ksef.latarnia.freshness_minutes', 15)));
        $coverageFrom = $state->messages_coverage_from_at;
        $coverageThrough = $state->messages_coverage_through_at;

        if ($coverageFrom === null
            || $coverageThrough === null
            || $coverageFrom->greaterThan($coverageThrough)
            || $coverageFrom->greaterThan($issuedAt)
            || $coverageThrough->greaterThan($issuedAt)) {
            return false;
        }

        $instants = [
            $state->status_last_success_at,
            $state->messages_last_success_at,
            $coverageThrough,
        ];

        return collect($instants)->every(fn ($instant): bool => $instant !== null
                && ! $instant->lessThan($freshSince)
                && ! $instant->greaterThan($issuedAt));
    }

    private function eligible(
        KsefOfflineIssuanceProcedure $procedure,
        KsefEnvironment $environment,
        KsefLatarniaEnvironment $latarniaEnvironment,
        KsefLatarniaSyncState $state,
        int $eventId,
        string $messageId,
        int $messageVersion,
        KsefLatarniaMessageCategory $category,
        CarbonImmutable $startAt,
        ?CarbonImmutable $endAt,
        CarbonImmutable $publishedAt,
    ): KsefOfflineProcedureEligibilitySnapshot {
        return new KsefOfflineProcedureEligibilitySnapshot(
            $procedure,
            $environment,
            $latarniaEnvironment,
            true,
            null,
            null,
            $state->current_status,
            $eventId,
            $messageId,
            $messageVersion,
            $category,
            $startAt,
            $endAt,
            $publishedAt,
            $this->evidenceAsOf($state),
            $state->messages_coverage_from_at,
            $state->messages_coverage_through_at,
        );
    }

    private function unavailable(
        KsefOfflineIssuanceProcedure $procedure,
        KsefEnvironment $environment,
        ?KsefLatarniaEnvironment $latarniaEnvironment,
        string $errorCode,
        string $message,
        ?KsefLatarniaSyncState $state = null,
    ): KsefOfflineProcedureEligibilitySnapshot {
        return new KsefOfflineProcedureEligibilitySnapshot(
            $procedure,
            $environment,
            $latarniaEnvironment,
            false,
            $errorCode,
            $message,
            $state?->current_status,
            evidenceAsOf: $state === null ? null : $this->evidenceAsOf($state),
            coverageFrom: $state?->messages_coverage_from_at,
            coverageThrough: $state?->messages_coverage_through_at,
        );
    }

    private function evidenceAsOf(KsefLatarniaSyncState $state): ?CarbonImmutable
    {
        return collect([
            $state->status_last_success_at,
            $state->messages_last_success_at,
            $state->messages_coverage_through_at,
        ])->filter()->sortBy(fn (CarbonImmutable $instant): int => $instant->getTimestamp())->first();
    }

    private function latarniaEnvironment(KsefEnvironment $environment): ?KsefLatarniaEnvironment
    {
        return match ($environment) {
            KsefEnvironment::Test => KsefLatarniaEnvironment::Test,
            KsefEnvironment::Production => KsefLatarniaEnvironment::Production,
            KsefEnvironment::Demo => null,
        };
    }

    private function databaseInstant(CarbonImmutable $instant): string
    {
        return $instant->utc()->format((new KsefLatarniaMessage)->getDateFormat());
    }

    /** @return Builder<KsefLatarniaSyncState>|Builder<KsefLatarniaMessage> */
    private function locked(Builder $query, bool $lock): Builder
    {
        return $lock ? $query->lockForUpdate() : $query;
    }
}
