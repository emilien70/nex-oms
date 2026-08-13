<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Exceptions\KsefApiException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PublicKey;
use Throwable;

class KsefTokenEncryptor
{
    public function encrypt(string $apiToken, int $timestampMs, string $base64DerCertificate): string
    {
        try {
            $der = base64_decode($base64DerCertificate, true);

            if ($der === false) {
                throw new KsefApiException(
                    'KSeF zwrócił nieprawidłowy certyfikat klucza publicznego.',
                    'public_key_invalid',
                );
            }

            $pem = "-----BEGIN CERTIFICATE-----\n"
                .chunk_split(base64_encode($der), 64, "\n")
                ."-----END CERTIFICATE-----\n";
            $publicKey = PublicKeyLoader::load($pem);

            if (! $publicKey instanceof PublicKey) {
                throw new KsefApiException(
                    'KSeF zwrócił klucz publiczny w nieobsługiwanym formacie.',
                    'public_key_not_rsa',
                );
            }

            $ciphertext = $publicKey
                ->withPadding(RSA::ENCRYPTION_OAEP)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->encrypt($apiToken.'|'.$timestampMs);

            return base64_encode($ciphertext);
        } catch (KsefApiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new KsefApiException(
                'Nie udało się przygotować Tokena KSeF do bezpiecznego uwierzytelnienia.',
                'token_encryption_failed',
            );
        }
    }
}
