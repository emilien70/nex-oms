<?php

namespace Modules\Automation\Services;

use App\Exceptions\GusRegonException;
use App\Models\Order;
use App\Services\GusRegonService;
use App\Support\PolishNip;
use Illuminate\Support\Facades\Cache;

final class AutomationGusNipCheck
{
    public const POSITIVE = 'gus_found';

    public const NEGATIVE = 'gus_not_confirmed';

    public function __construct(private readonly GusRegonService $gus) {}

    public static function isCondition(array $condition): bool
    {
        return ($condition['field'] ?? null) === 'billing_tax_id'
            && ($condition['operator'] ?? null) === 'equals'
            && in_array($condition['value'] ?? null, [self::POSITIVE, self::NEGATIVE], true);
    }

    /** @return array{fingerprint: string, result: string}|null */
    public function check(Order $order): ?array
    {
        $nip = self::eligibleNip($order);
        if ($nip === null) {
            return null;
        }

        $fingerprint = self::fingerprint($nip);
        $key = 'automation:gus-nip:'.$fingerprint;
        $result = Cache::get($key);
        if (! in_array($result, ['found', 'not_found'], true)) {
            try {
                $result = $this->gus->findCompaniesByNip($nip, requestTimeout: 2) === [] ? 'not_found' : 'found';
                Cache::put($key, $result, $result === 'found' ? now()->addDay() : now()->addHour());
            } catch (GusRegonException) {
                $result = 'unavailable';
            }
        }

        return ['fingerprint' => $fingerprint, 'result' => $result];
    }

    public static function matches(Order $order, string $expected, ?array $check): bool
    {
        $nip = self::eligibleNip($order);
        if ($nip === null || ($check['fingerprint'] ?? null) !== self::fingerprint($nip)) {
            return false;
        }

        $result = $check['result'] ?? null;

        return $expected === self::POSITIVE ? $result === 'found' : in_array($result, ['not_found', 'unavailable'], true);
    }

    private static function eligibleNip(Order $order): ?string
    {
        $country = strtoupper(trim((string) $order->billing_country_code));
        $nip = strtoupper(PolishNip::withoutSeparators((string) $order->billing_tax_id));
        $hasPolishPrefix = str_starts_with($nip, 'PL');
        if ($country !== 'PL' && ! ($country === '' && $hasPolishPrefix)) {
            return null;
        }
        if ($hasPolishPrefix) {
            $nip = substr($nip, 2);
        }

        return PolishNip::isValid($nip) ? $nip : null;
    }

    private static function fingerprint(string $nip): string
    {
        return hash_hmac('sha256', 'automation:gus-nip:'.config('services.gus.url').':'.$nip, (string) config('app.key'));
    }
}
