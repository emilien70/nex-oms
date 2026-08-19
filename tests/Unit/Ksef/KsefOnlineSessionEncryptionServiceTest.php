<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Services\KsefOnlineSessionEncryptionService;
use Modules\Ksef\Services\KsefOnlineSessionRequestFactory;
use Modules\Ksef\ValueObjects\KsefPublicKeyCertificate;
use phpseclib3\Crypt\RSA;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\TestCase;

class KsefOnlineSessionEncryptionServiceTest extends TestCase
{
    public function test_it_encrypts_session_key_and_invoice_with_official_algorithms(): void
    {
        $fake = new KsefOnlineSessionApiFake;
        $certificate = new KsefPublicKeyCertificate(
            $fake->certificates[1]['certificate'],
            $fake->certificates[1]['publicKeyId'],
        );
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Faktura>Zażółć 123</Faktura>';

        $encrypted = app(KsefOnlineSessionEncryptionService::class)->encrypt($xml, $certificate);
        $decryptedKey = $fake->privateKey
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->decrypt(base64_decode($encrypted->encryptedSymmetricKey, true));
        $ciphertext = base64_decode($encrypted->encryptedInvoiceContent, true);
        $decryptedXml = openssl_decrypt(
            $ciphertext,
            'aes-256-cbc',
            $decryptedKey,
            OPENSSL_RAW_DATA,
            base64_decode($encrypted->initializationVector, true),
        );

        $this->assertSame(32, strlen($decryptedKey));
        $this->assertSame(16, strlen($encrypted->cipherIv));
        $this->assertSame($encrypted->cipherKey, $decryptedKey);
        $this->assertSame($xml, $decryptedXml);
        $this->assertSame(base64_encode(hash('sha256', $ciphertext, true)), $encrypted->encryptedInvoiceHash);
        $this->assertSame(strlen($ciphertext), $encrypted->encryptedInvoiceSize);
        $this->assertSame('SYMMETRIC-KEY-ID', $encrypted->publicKeyId);

        $openPayload = app(KsefOnlineSessionRequestFactory::class)->openSession($encrypted);
        $this->assertSame([
            'systemCode' => 'FA (3)',
            'schemaVersion' => '1-0E',
            'value' => 'FA',
        ], $openPayload['formCode']);
        $this->assertSame('SYMMETRIC-KEY-ID', data_get($openPayload, 'encryption.publicKeyId'));
        $this->assertSame($encrypted->initializationVector, data_get($openPayload, 'encryption.initializationVector'));
    }
}
