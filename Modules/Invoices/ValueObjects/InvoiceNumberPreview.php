<?php

namespace Modules\Invoices\ValueObjects;

final readonly class InvoiceNumberPreview
{
    public function __construct(
        public string $numberingPeriodKey,
        public int $currentLastSequenceNumber,
        public int $protectedFloorSequenceNumber,
        public int $currentNextSequenceNumber,
        public int $previewSequenceNumber,
        public string $formattedNumber,
        public bool $counterExists,
    ) {}

    /**
     * @return array<string, bool|int|string>
     */
    public function toArray(): array
    {
        return [
            'numbering_period_key' => $this->numberingPeriodKey,
            'current_last_sequence_number' => $this->currentLastSequenceNumber,
            'protected_floor_sequence_number' => $this->protectedFloorSequenceNumber,
            'current_next_sequence_number' => $this->currentNextSequenceNumber,
            'preview_sequence_number' => $this->previewSequenceNumber,
            'formatted_number' => $this->formattedNumber,
            'counter_exists' => $this->counterExists,
        ];
    }
}
