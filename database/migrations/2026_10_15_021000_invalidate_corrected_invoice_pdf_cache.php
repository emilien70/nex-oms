<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $disk = Storage::disk('local');

        DB::table('invoices')
            ->whereIn('document_type', ['invoice', 'proforma'])
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use ($disk): void {
                foreach ($invoices as $invoice) {
                    $payment = $this->snapshot($invoice->payment_snapshot);

                    if (($payment['payment_identifier_source'] ?? null) !== 'provider_transaction_repair_v1') {
                        continue;
                    }

                    $paths = array_values(array_filter(
                        $disk->files('invoices/'.$invoice->id),
                        static fn (string $path): bool => preg_match(
                            '/^(invoice|proforma)-v[^\\/]+\.pdf$/',
                            basename($path),
                        ) === 1,
                    ));

                    if ($paths !== []) {
                        $disk->delete($paths);
                    }
                }
            });
    }

    public function down(): void
    {
        // Generated PDF cache is recreated on demand.
    }

    /** @return array<string, mixed> */
    private function snapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
