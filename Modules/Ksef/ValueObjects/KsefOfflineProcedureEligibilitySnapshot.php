<?php

namespace Modules\Ksef\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaStatus;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;

final readonly class KsefOfflineProcedureEligibilitySnapshot
{
    public function __construct(
        public KsefOfflineIssuanceProcedure $procedure,
        public KsefEnvironment $environment,
        public ?KsefLatarniaEnvironment $latarniaEnvironment,
        public bool $eligible,
        public ?string $errorCode,
        public ?string $message,
        public ?KsefLatarniaStatus $currentStatus = null,
        public ?int $eventId = null,
        public ?string $messageId = null,
        public ?int $messageVersion = null,
        public ?KsefLatarniaMessageCategory $category = null,
        public ?CarbonImmutable $startAt = null,
        public ?CarbonImmutable $endAt = null,
        public ?CarbonImmutable $publishedAt = null,
        public ?CarbonImmutable $evidenceAsOf = null,
        public ?CarbonImmutable $coverageFrom = null,
        public ?CarbonImmutable $coverageThrough = null,
    ) {}

    /** @return array<string, mixed> */
    public function provenanceAttributes(): array
    {
        if ($this->procedure === KsefOfflineIssuanceProcedure::Offline24) {
            return [
                'latarnia_source_environment' => null,
                'latarnia_trigger_event_id' => null,
                'latarnia_trigger_message_id' => null,
                'latarnia_trigger_message_version' => null,
                'latarnia_trigger_category' => null,
                'latarnia_trigger_start_at' => null,
                'latarnia_trigger_end_at' => null,
                'latarnia_trigger_published_at' => null,
                'latarnia_evidence_as_of_at' => null,
                'latarnia_evidence_from_at' => null,
                'latarnia_evidence_through_at' => null,
            ];
        }

        return [
            'latarnia_source_environment' => $this->latarniaEnvironment,
            'latarnia_trigger_event_id' => $this->eventId,
            'latarnia_trigger_message_id' => $this->messageId,
            'latarnia_trigger_message_version' => $this->messageVersion,
            'latarnia_trigger_category' => $this->category,
            'latarnia_trigger_start_at' => $this->startAt,
            'latarnia_trigger_end_at' => $this->endAt,
            'latarnia_trigger_published_at' => $this->publishedAt,
            'latarnia_evidence_as_of_at' => $this->evidenceAsOf,
            'latarnia_evidence_from_at' => $this->coverageFrom,
            'latarnia_evidence_through_at' => $this->coverageThrough,
        ];
    }

    public function evidenceFingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->procedure->value,
            $this->environment->value,
            $this->latarniaEnvironment?->value,
            $this->eligible,
            $this->currentStatus?->value,
            $this->eventId,
            $this->messageId,
            $this->messageVersion,
            $this->category?->value,
            $this->startAt?->format('Y-m-d\TH:i:s.u\Z'),
            $this->endAt?->format('Y-m-d\TH:i:s.u\Z'),
            $this->publishedAt?->format('Y-m-d\TH:i:s.u\Z'),
            $this->evidenceAsOf?->format('Y-m-d\TH:i:s.u\Z'),
            $this->coverageFrom?->format('Y-m-d\TH:i:s.u\Z'),
            $this->coverageThrough?->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR));
    }
}
