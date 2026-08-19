<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\ValueObjects\KsefOnlineSessionEncryptionData;
use Modules\Ksef\ValueObjects\KsefPublicKeyCertificate;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PublicKey;
use Throwable;

class KsefOnlineSessionEncryptionService
{
    public function encrypt(
        string $xml,
        KsefPublicKeyCertificate $certificate,
    ): KsefOnlineSessionEncryptionData {
        try {
            $symmetricKey = random_bytes(32);
            $iv = random_bytes(16);
            $encryptedInvoice = openssl_encrypt(
                $xml,
                'aes-256-cbc',
                $symmetricKey,
                OPENSSL_RAW_DATA,
                $iv,
            );

            if (! is_string($encryptedInvoice)) {
                throw new KsefApiException(
                    'Nie udało się zaszyfrować Faktury dla sesji KSeF.',
                    'ksef_invoice_encryption_failed',
                );
            }

            $publicKey = $this->publicKey($certificate->certificate);
            $encryptedSymmetricKey = $publicKey
                ->withPadding(RSA::ENCRYPTION_OAEP)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->encrypt($symmetricKey);

            return new KsefOnlineSessionEncryptionData(
                encryptedSymmetricKey: base64_encode($encryptedSymmetricKey),
                initializationVector: base64_encode($iv),
                publicKeyId: $certificate->publicKeyId,
                encryptedInvoiceContent: base64_encode($encryptedInvoice),
                encryptedInvoiceHash: $this->hash($encryptedInvoice),
                encryptedInvoiceSize: strlen($encryptedInvoice),
                cipherKey: $symmetricKey,
                cipherIv: $iv,
            );
        } catch (KsefApiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new KsefApiException(
                'Nie udało się przygotować szyfrowania sesji KSeF.',
                'ksef_session_encryption_failed',
            );
        }
    }

    private function publicKey(string $base64DerCertificate): PublicKey
    {
        $der = base64_decode($base64DerCertificate, true);

        if ($der === false) {
            throw new KsefApiException(
                'KSeF zwrócił nieprawidłowy certyfikat klucza publicznego.',
                'ksef_public_key_invalid',
            );
        }

        $pem = "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END CERTIFICATE-----\n";
        $publicKey = PublicKeyLoader::load($pem);

        if (! $publicKey instanceof PublicKey) {
            throw new KsefApiException(
                'KSeF zwrócił klucz publiczny w nieobsługiwanym formacie.',
                'ksef_public_key_not_rsa',
            );
        }

        return $publicKey;
    }

    private function hash(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }
}
