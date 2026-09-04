<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY_TIMEZONE = 'Europe/Warsaw';

    private const FORMAT = 'Y-m-d H:i:s';

    public function up(): void
    {
        DB::transaction(function (): void {
            $updates = [];

            foreach ($this->columns() as $column) {
                $rows = DB::table('ksef_invoice_submissions')
                    ->select(['id', $column])
                    ->whereNotNull($column)
                    ->orderBy('id')
                    ->get();

                foreach ($rows as $row) {
                    $raw = $row->{$column};

                    if (! is_string($raw)) {
                        throw $this->invalidLegacyTimestamp((int) $row->id, $column);
                    }

                    $updates[] = [
                        'column' => $column,
                        'id' => (int) $row->id,
                        'legacy' => $raw,
                        'utc' => $this->utcWallClock($raw, (int) $row->id, $column),
                    ];
                }
            }

            foreach ($updates as $update) {
                $changed = DB::table('ksef_invoice_submissions')
                    ->where('id', $update['id'])
                    ->where($update['column'], $update['legacy'])
                    ->update([$update['column'] => $update['utc']]);

                if ($changed !== 1) {
                    throw new RuntimeException(
                        "Nie udało się atomowo znormalizować czasu KSeF w ksef_invoice_submissions#{$update['id']}.",
                    );
                }
            }
        });
    }

    public function down(): void
    {
        throw new LogicException(
            'Migracja UTC timestampów submissionów KSeF jest nieodwracalna bez ryzyka utraty informacji podczas zmiany DST.',
        );
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'session_valid_until',
            'session_closed_at',
            'acquisition_date',
            'invoicing_date',
            'permanent_storage_date',
            'last_checked_at',
            'next_follow_up_at',
            'last_follow_up_at',
        ];
    }

    private function utcWallClock(string $raw, int $id, string $column): string
    {
        $utc = new DateTimeZone('UTC');
        $legacyTimezone = new DateTimeZone(self::LEGACY_TIMEZONE);
        $wallClock = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $raw, $utc);

        if ($wallClock === false || $wallClock->format(self::FORMAT) !== $raw) {
            throw $this->invalidLegacyTimestamp($id, $column);
        }

        $offsets = [];
        foreach ($legacyTimezone->getTransitions(
            $wallClock->getTimestamp() - 172800,
            $wallClock->getTimestamp() + 172800,
        ) as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }

        $candidates = [];
        foreach (array_keys($offsets) as $offset) {
            $candidate = (new DateTimeImmutable('@'.($wallClock->getTimestamp() - $offset)))
                ->setTimezone($legacyTimezone);

            if ($candidate->format(self::FORMAT) === $raw) {
                $candidates[$candidate->getTimestamp()] = $candidate;
            }
        }

        if (count($candidates) !== 1) {
            throw $this->invalidLegacyTimestamp($id, $column);
        }

        return reset($candidates)
            ->setTimezone($utc)
            ->format(self::FORMAT);
    }

    private function invalidLegacyTimestamp(int $id, string $column): RuntimeException
    {
        return new RuntimeException(
            "Historyczny czas KSeF ksef_invoice_submissions#{$id}.{$column} nie wskazuje dokładnie jednego instantu Europe/Warsaw.",
        );
    }
};
