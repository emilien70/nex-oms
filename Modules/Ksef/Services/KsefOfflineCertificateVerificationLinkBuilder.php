<?php

namespace Modules\Ksef\Services;

use InvalidArgumentException;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\ValueObjects\KsefContextIdentifier;
use Modules\Ksef\ValueObjects\KsefOfflineCertificateVerificationLink;
use OpenSSLAsymmetricKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey as RsaPrivateKey;
use Throwable;

final class KsefOfflineCertificateVerificationLinkBuilder
{
    public function __construct(
        private readonly KsefEcdsaSignatureConverter $ecdsa,
    ) {}

    public function build(
        KsefEnvironment $environment,
        KsefContextIdentifier $contextIdentifier,
        string $sellerNip,
        string $certificateSerialNumber,
        string $invoiceHash,
        string $privateKeyPem,
    ): KsefOfflineCertificateVerificationLink {
        $baseUrl = config('ksef.qr_base_urls.'.$environment->value);

        if (! is_string($baseUrl) || ! str_starts_with($baseUrl, 'https://')) {
            $this->fail('Brak adresu weryfikacyjnego KSeF dla wybranego środowiska.');
        }

        if (preg_match('/^\d{10}$/D', $sellerNip) !== 1) {
            $this->fail('NIP sprzedawcy ma nieprawidłowy format.');
        }

        if (preg_match('/^[0-9A-F]{16}$/D', $certificateSerialNumber) !== 1) {
            $this->fail('Numer seryjny certyfikatu Offline ma nieprawidłowy format.');
        }

        $decodedHash = base64_decode($invoiceHash, true);

        if (! is_string($decodedHash)
            || strlen($decodedHash) !== 32
            || ! hash_equals(base64_encode($decodedHash), $invoiceHash)) {
            $this->fail('Skrót Faktury dla KODU II ma nieprawidłowy format.');
        }

        $invoiceHashBase64Url = $this->base64Url($decodedHash);
        $baseUrl = rtrim($baseUrl, '/');
        $unsignedUrl = sprintf(
            '%s/certificate/%s/%s/%s/%s/%s',
            $baseUrl,
            $contextIdentifier->type->value,
            $contextIdentifier->value,
            $sellerNip,
            $certificateSerialNumber,
            $invoiceHashBase64Url,
        );
        $preSign = substr($unsignedUrl, strlen('https://'));
        $signature = $this->sign($preSign, $privateKeyPem);
        $signatureBase64Url = $this->base64Url($signature);

        return new KsefOfflineCertificateVerificationLink(
            $unsignedUrl.'/'.$signatureBase64Url,
            $preSign,
            $invoiceHashBase64Url,
            $signatureBase64Url,
        );
    }

    private function sign(string $preSign, string $privateKeyPem): string
    {
        $privateKey = @openssl_pkey_get_private($privateKeyPem);
        $details = $privateKey instanceof OpenSSLAsymmetricKey
            ? openssl_pkey_get_details($privateKey)
            : false;

        if (! is_array($details)) {
            $this->fail('Nie udało się odczytać klucza prywatnego certyfikatu Offline.');
        }

        if (($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA && ($details['bits'] ?? null) === 2048) {
            try {
                $rsa = PublicKeyLoader::loadPrivateKey($privateKeyPem);

                if (! $rsa instanceof RsaPrivateKey) {
                    $this->fail('Klucz prywatny certyfikatu Offline nie jest kluczem RSA.');
                }

                return $rsa
                    ->withPadding(RSA::SIGNATURE_PSS)
                    ->withHash('sha256')
                    ->withMGFHash('sha256')
                    ->withSaltLength(32)
                    ->sign($preSign);
            } catch (Throwable) {
                $this->fail('Nie udało się podpisać KODU II kluczem RSA.');
            }
        }

        $curve = is_array($details['ec'] ?? null)
            ? ($details['ec']['curve_name'] ?? null)
            : null;

        if (($details['type'] ?? null) === OPENSSL_KEYTYPE_EC
            && ($details['bits'] ?? null) === 256
            && is_string($curve)
            && in_array(strtolower($curve), ['prime256v1', 'secp256r1'], true)) {
            $derSignature = '';

            if (! openssl_sign($preSign, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
                $this->fail('Nie udało się podpisać KODU II kluczem EC.');
            }

            return $this->ecdsa->derToRaw($derSignature, 32);
        }

        $this->fail('KOD II wymaga klucza RSA 2048 albo EC P-256.');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function fail(string $message): never
    {
        throw new InvalidArgumentException($message);
    }
}
