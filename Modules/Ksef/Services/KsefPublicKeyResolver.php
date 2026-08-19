<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefPublicKeyUsage;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\ValueObjects\KsefPublicKeyCertificate;
use Throwable;

class KsefPublicKeyResolver
{
    public function resolve(
        array $certificates,
        KsefPublicKeyUsage $usage = KsefPublicKeyUsage::TokenEncryption,
        ?CarbonImmutable $now = null,
    ): KsefPublicKeyCertificate {
        $now ??= CarbonImmutable::now('UTC');
        $eligible = [];

        foreach ($certificates as $certificate) {
            if (! is_array($certificate)
                || ! is_array($certificate['usage'] ?? null)
                || ! in_array($usage->value, $certificate['usage'], true)
                || ! is_string($certificate['certificate'] ?? null)
                || ! is_string($certificate['publicKeyId'] ?? null)
                || ! is_string($certificate['validFrom'] ?? null)
                || ! is_string($certificate['validTo'] ?? null)) {
                continue;
            }

            try {
                $validFrom = CarbonImmutable::parse($certificate['validFrom'])->utc();
                $validTo = CarbonImmutable::parse($certificate['validTo'])->utc();
            } catch (Throwable) {
                continue;
            }

            if ($validFrom->greaterThan($now) || ! $validTo->greaterThan($now)) {
                continue;
            }

            $eligible[] = [
                'certificate' => $certificate['certificate'],
                'public_key_id' => $certificate['publicKeyId'],
                'valid_from' => $validFrom,
            ];
        }

        usort($eligible, static function (array $left, array $right): int {
            $dateComparison = $right['valid_from']->getTimestamp() <=> $left['valid_from']->getTimestamp();

            return $dateComparison !== 0
                ? $dateComparison
                : strcmp($right['public_key_id'], $left['public_key_id']);
        });

        if ($eligible === []) {
            throw new KsefApiException(
                $usage === KsefPublicKeyUsage::TokenEncryption
                    ? 'Brak aktualnego klucza publicznego KSeF do szyfrowania tokenu.'
                    : 'Brak aktualnego klucza publicznego KSeF do szyfrowania sesji fakturowej.',
                $usage === KsefPublicKeyUsage::TokenEncryption
                    ? 'public_key_unavailable'
                    : 'ksef_public_key_unavailable',
            );
        }

        return new KsefPublicKeyCertificate(
            $eligible[0]['certificate'],
            $eligible[0]['public_key_id'],
        );
    }
}
