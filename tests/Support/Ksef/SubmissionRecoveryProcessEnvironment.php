<?php

namespace Tests\Support\Ksef;

use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Crypt;
use Modules\Ksef\Models\KsefInvoiceSubmission;

final class SubmissionRecoveryProcessEnvironment
{
    public const KEY = 'base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE=';

    public const CIPHER = 'AES-256-CBC';

    public static function variables(string $directory): array
    {
        return [
            'APP_ENV' => 'testing', 'APP_KEY' => self::KEY, 'APP_PREVIOUS_KEYS' => '',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/isolated.sqlite', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
            'LOG_CHANNEL' => 'null', 'APP_CONFIG_CACHE' => $directory.'/absent-config.php',
            'LARAVEL_STORAGE_PATH' => $directory.'/storage',
        ];
    }

    public static function configureEncryption(Application $app): void
    {
        $app->make('config')->set(['app.key' => self::KEY, 'app.cipher' => self::CIPHER, 'app.previous_keys' => []]);
        $app->instance('encrypter', self::encrypter());
        Crypt::clearResolvedInstance('encrypter');
        KsefInvoiceSubmission::encryptUsing(null);
    }

    public static function encrypter(): Encrypter
    {
        return new Encrypter(base64_decode(substr(self::KEY, 7)), self::CIPHER);
    }
}
