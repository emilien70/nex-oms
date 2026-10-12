<?php

namespace Modules\Integrations\NexPrinter\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\NexPrinter\Exceptions\NexPrinterException;
use Modules\Integrations\NexPrinter\Models\NexPrinterAgent;
use Modules\Integrations\NexPrinter\Models\NexPrinterPairingCode;

class NexPrinterPairingService
{
    /** @return array{code: string, expires_at: Carbon} */
    public function createCode(): array
    {
        $plainCode = strtoupper(Str::random(10));
        $expiresAt = now()->addMinutes(10);

        NexPrinterPairingCode::query()->create([
            'code_hash' => $this->hashCode($plainCode),
            'expires_at' => $expiresAt,
        ]);

        return [
            'code' => $plainCode,
            'expires_at' => $expiresAt,
        ];
    }

    /** @return array{agent: NexPrinterAgent, token: string} */
    public function pair(
        string $plainCode,
        string $name,
        string $platform,
        ?string $clientVersion,
    ): array {
        return DB::transaction(function () use ($plainCode, $name, $platform, $clientVersion): array {
            $pairingCode = NexPrinterPairingCode::query()
                ->where('code_hash', $this->hashCode($plainCode))
                ->lockForUpdate()
                ->first();

            if (
                $pairingCode === null
                || $pairingCode->consumed_at !== null
                || $pairingCode->expires_at->isPast()
            ) {
                throw new NexPrinterException(
                    'invalid_pairing_code',
                    'Kod połączenia jest nieprawidłowy albo wygasł.',
                );
            }

            $plainToken = bin2hex(random_bytes(32));
            $now = now();
            $agent = NexPrinterAgent::query()->create([
                'public_id' => (string) Str::uuid(),
                'name' => $name,
                'platform' => $platform,
                'client_version' => $clientVersion,
                'token_hash' => hash('sha256', $plainToken),
                'is_active' => true,
                'paired_at' => $now,
                'last_seen_at' => $now,
            ]);

            $pairingCode->forceFill(['consumed_at' => $now])->save();

            return ['agent' => $agent, 'token' => $plainToken];
        });
    }

    private function hashCode(string $plainCode): string
    {
        return hash('sha256', strtoupper(trim($plainCode)));
    }
}
