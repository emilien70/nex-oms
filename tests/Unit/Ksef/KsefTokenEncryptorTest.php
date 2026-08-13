<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Services\KsefTokenEncryptor;
use phpseclib3\Crypt\RSA;
use Tests\Support\KsefApiFake;
use Tests\TestCase;

class KsefTokenEncryptorTest extends TestCase
{
    public function test_it_encrypts_exact_token_and_mf_timestamp_with_oaep_sha256(): void
    {
        $fake = new KsefApiFake;
        $encrypted = app(KsefTokenEncryptor::class)->encrypt(
            'TEST_KSEF_TOKEN',
            1752236636015,
            $fake->certificate,
        );

        $plaintext = $fake->privateKey
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->decrypt(base64_decode($encrypted, true));

        $this->assertSame('TEST_KSEF_TOKEN|1752236636015', $plaintext);
    }
}
