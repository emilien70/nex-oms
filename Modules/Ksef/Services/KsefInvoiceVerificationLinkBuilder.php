<?php

namespace Modules\Ksef\Services;

use DateTimeInterface;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class KsefInvoiceVerificationLinkBuilder
{
    public function build(KsefInvoiceSubmission $submission, DateTimeInterface $issueDate): ?string
    {
        $baseUrl = config('ksef.qr_base_urls.'.$submission->environment->value);
        $sellerNip = trim((string) $submission->seller_nip);
        $invoiceHash = (string) $submission->invoice_hash;
        $decodedHash = base64_decode($invoiceHash, true);

        if (! is_string($baseUrl)
            || ! str_starts_with($baseUrl, 'https://')
            || preg_match('/^\d{10}$/', $sellerNip) !== 1
            || $decodedHash === false
            || strlen($decodedHash) !== 32
            || ! hash_equals(base64_encode($decodedHash), $invoiceHash)) {
            return null;
        }

        $base64UrlHash = rtrim(strtr($invoiceHash, '+/', '-_'), '=');

        return sprintf(
            '%s/invoice/%s/%s/%s',
            rtrim($baseUrl, '/'),
            $sellerNip,
            $issueDate->format('d-m-Y'),
            $base64UrlHash,
        );
    }
}
