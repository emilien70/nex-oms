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

            foreach ($this->columns() as $table => $column) {
                $rows = DB::table($table)
                    ->select(['id', $column])
                    ->whereNotNull($column)
                    ->orderBy('id')
                    ->get();

                foreach ($rows as $row) {
                    $raw = $row->{$column};

                    if (! is_string($raw)) {
                        throw $this->invalidLegacyTimestamp($table, (int) $row->id, $column);
                    }

                    $updates[] = [
                        'table' => $table,
                        'column' => $column,
                        'id' => (int) $row->id,
                        'legacy' => $raw,
                        'utc' => $this->utcWallClock($raw, $table, (int) $row->id, $column),
                    ];
                }
            }

            foreach ($updates as $update) {
                $changed = DB::table($update['table'])
                    ->where('id', $update['id'])
                    ->where($update['column'], $update['legacy'])
                    ->update([$update['column'] => $update['utc']]);

                if ($changed !== 1) {
                    throw new RuntimeException(
                        "Nie udało się atomowo znormalizować czasu KSeF w {$update['table']}#{$update['id']}.",
                    );
                }
            }
        });
    }

    public function down(): void
    {
        throw new LogicException(
            'Migracja UTC timestampów KSeF jest nieodwracalna bez ryzyka utraty informacji podczas zmiany DST.',
        );
    }

    /** @return array<string, string> */
    private function columns(): array
    {
        return [
            'ksef_offline_issuances' => 'issued_at',
            'ksef_invoice_submissions' => 'generated_at',
        ];
    }

    private function utcWallClock(
        string $raw,
        string $table,
        int $id,
        string $column,
    ): string {
        $utc = new DateTimeZone('UTC');
        $legacyTimezone = new DateTimeZone(self::LEGACY_TIMEZONE);
        $wallClock = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $raw, $utc);

        if ($wallClock === false || $wallClock->format(self::FORMAT) !== $raw) {
            throw $this->invalidLegacyTimestamp($table, $id, $column);
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
            throw $this->invalidLegacyTimestamp($table, $id, $column);
        }

        return reset($candidates)
            ->setTimezone($utc)
            ->format(self::FORMAT);
    }

    private function invalidLegacyTimestamp(string $table, int $id, string $column): RuntimeException
    {
        return new RuntimeException(
            "Historyczny czas KSeF {$table}#{$id}.{$column} nie wskazuje dokładnie jednego instantu Europe/Warsaw.",
        );
    }
};
