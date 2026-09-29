<?php

namespace App\Services;

use App\Enums\EmailAccountEncryption;
use App\Models\EmailAccount;
use JsonException;
use RuntimeException;

final class SmtpAccountFingerprint
{
    /** @throws JsonException */
    public function make(EmailAccount $account): string
    {
        $password = $account->smtp_password;

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('SMTP account password is unavailable.');
        }

        $payload = [
            'email' => (string) $account->email,
            'smtp_host' => (string) $account->smtp_host,
            'smtp_port' => (int) $account->smtp_port,
            'smtp_username' => (string) $account->smtp_username,
            'encryption' => $account->encryption instanceof EmailAccountEncryption
                ? $account->encryption->value
                : (string) $account->encryption,
            'smtp_password' => $password,
        ];

        return hash_hmac('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ), $this->key());
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        if ($key === '') {
            throw new RuntimeException('Application key is unavailable.');
        }

        return $key;
    }
}
