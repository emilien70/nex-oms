<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Normalizer;

class PortalVerificationService
{
    private const SESSION_KEY = 'customer_portal_verifications';

    public function ready(): bool
    {
        return Schema::hasTable('customer_portal_settings') && Schema::hasColumns('customer_portal_settings', [
            'customer_domain', 'mask_personal_data', 'verify_documents', 'verification_method',
        ]);
    }

    public function verified(Request $request, array $grant): bool
    {
        $saved = $request->session()->get(self::SESSION_KEY, [])[(string) $grant['order']->id] ?? null;

        return is_array($saved) && is_int($saved['expires'] ?? null) && $saved['expires'] > now()->getTimestamp()
            && is_string($saved['fingerprint'] ?? null) && hash_equals($this->fingerprint($grant), $saved['fingerprint']);
    }

    public function blocked(Request $request, array $grant): bool
    {
        [$ip, $order] = $this->keys($request, $grant);

        return RateLimiter::tooManyAttempts($ip, 20) || RateLimiter::tooManyAttempts($order, 5);
    }

    public function verify(Request $request, array $grant, mixed $value): bool
    {
        if ($this->blocked($request, $grant)) {
            return false;
        }
        if (! $this->matches($grant, $value)) {
            foreach ($this->keys($request, $grant) as $key) {
                RateLimiter::hit($key, 60);
            }

            return false;
        }
        $saved = $request->session()->get(self::SESSION_KEY, []);
        $saved[(string) $grant['order']->id] = [
            'expires' => min(now()->addMinutes(15)->getTimestamp(), $grant['link']->expires_at->getTimestamp()),
            'fingerprint' => $this->fingerprint($grant),
        ];
        $request->session()->migrate(true);
        $request->session()->put(self::SESSION_KEY, array_slice($saved, -10, null, true));

        return true;
    }

    private function matches(array $grant, mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 1024 || mb_strlen($value) > 254
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            return false;
        }
        $order = $grant['order'];
        $method = $grant['settings']->verificationMethod();
        $candidates = [];
        if (in_array($method, ['any', 'email'], true)) {
            $candidates[] = [(string) $order->customer_email, true];
        }
        if (in_array($method, ['any', 'name_company'], true)) {
            $candidates[] = [(string) $order->billing_name, false];
            $candidates[] = [(string) $order->billing_company_name, false];
        }
        $matches = false;
        foreach ($candidates as [$expected, $email]) {
            $input = $this->normalize($value, $email);
            $expected = $this->normalize($expected, $email);
            $matches = ($expected !== '' && $input !== '' && hash_equals($this->hash($expected), $this->hash($input))) || $matches;
        }

        return $matches;
    }

    private function normalize(string $value, bool $email): string
    {
        $value = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($value === false) {
            return '';
        }
        $value = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value) ?? '';
        if (! $email) {
            $value = preg_replace('/[\s\p{Z}]+/u', ' ', $value) ?? '';
        }

        return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
    }

    private function fingerprint(array $grant): string
    {
        $order = $grant['order'];
        $link = $grant['link'];
        $settings = $grant['settings'];

        return $this->hash(json_encode([$order->id, $link->id, $link->generation, $link->token_hash, $link->identity_hash,
            $link->expires_at->toISOString(), $settings->revision, $settings->is_enabled, $settings->customer_domain,
            $settings->masksPersonalData(), $settings->verifiesDocuments(), $settings->verificationMethod(),
            $order->customer_email, $order->customer_phone, $order->shippingAddressData(), $order->billingAddressData(), $order->pickupPointData(),
        ], JSON_THROW_ON_ERROR));
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function keys(Request $request, array $grant): array
    {
        $ip = $this->hash((string) $request->ip());

        return ['portal-verify-ip:'.$ip, 'portal-verify-order:'.$ip.':'.$grant['order']->id];
    }
}
