<?php

namespace Modules\Ksef\Services;

use phpseclib3\File\ASN1\Element;
use phpseclib3\File\X509;

final class KsefCertificateOwnerResolver
{
    public function isStrictNipOwner(string $certificatePem, string $contextNip): ?bool
    {
        $x509 = new X509;

        if ($x509->loadX509($certificatePem) === false) {
            return null;
        }

        $subject = $x509->getSubjectDN(X509::DN_ARRAY);

        if (! is_array($subject) || ! is_array($subject['rdnSequence'] ?? null)) {
            return null;
        }

        $nips = [];

        foreach ($subject['rdnSequence'] as $relativeName) {
            foreach (is_array($relativeName) ? $relativeName : [] as $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }

                $type = $attribute['type'] ?? null;
                $value = $this->asn1String($attribute['value'] ?? null);

                if (! is_string($type) || $value === null) {
                    continue;
                }

                $pattern = match ($type) {
                    'id-at-serialNumber', '2.5.4.5' => '/^(?:NIP|TINPL)[\s:\/-]*(\d{10})$/i',
                    'id-at-organizationIdentifier', '2.5.4.97' => '/^VATPL[\s:\/-]*(\d{10})$/i',
                    default => null,
                };

                if ($pattern !== null && preg_match($pattern, trim($value), $matches) === 1) {
                    $nips[] = $matches[1];
                }
            }
        }

        $nips = array_values(array_unique($nips));

        return count($nips) === 1 ? hash_equals($contextNip, $nips[0]) : null;
    }

    private function asn1String(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Element || ! is_array($value) || count($value) !== 1) {
            return null;
        }

        return $this->asn1String(array_values($value)[0]);
    }
}
