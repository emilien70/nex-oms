<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\File\X509;

final class KsefApiFake
{
    public const ACCESS_TOKEN = 'SECRET_ACCESS_TOKEN_789';

    public const API_TOKEN = 'SECRET_API_TOKEN_123';

    public const AUTHENTICATION_TOKEN = 'SECRET_AUTH_TOKEN_456';

    public const REFRESH_TOKEN = 'SECRET_REFRESH_TOKEN_987';

    public array $statusCodes = [200];

    public array $permissions = [['permissionScope' => 'InvoiceWrite']];

    public array $tokens = [[
        'referenceNumber' => 'TEST-REFERENCE',
        'authorIdentifier' => [
            'type' => 'Nip',
            'value' => '1234567890',
        ],
        'contextIdentifier' => [
            'type' => 'Nip',
            'value' => '1234567890',
        ],
        'requestedPermissions' => [
            'InvoiceWrite',
            'InvoiceRead',
            'Introspection',
        ],
        'status' => 'Active',
        'statusDetails' => [],
    ]];

    public ?string $tokenContinuationToken = null;

    public array $warnings = [];

    public array $failures = [];

    public int $statusCalls = 0;

    public int $redeemCalls = 0;

    public int $refreshCalls = 0;

    public int $tokenQueryCalls = 0;

    public bool $isTokenRedeemed = false;

    public bool $echoEncryptedTokenInWarning = false;

    public ?string $lastEncryptedToken = null;

    public readonly PrivateKey $privateKey;

    public readonly string $certificate;

    public function __construct()
    {
        [$this->privateKey, $this->certificate] = self::testCertificate();
    }

    public function __invoke(Request $request): mixed
    {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

        foreach ($this->failures as $suffix => $failure) {
            if (str_ends_with($path, $suffix)) {
                return Http::response(
                    $failure['body'] ?? [],
                    $failure['status'] ?? 500,
                    $failure['headers'] ?? [],
                );
            }
        }

        $headers = $this->warningHeaders($path);

        if (str_ends_with($path, '/auth/challenge')) {
            return Http::response([
                'challenge' => 'CHALLENGE-123',
                'timestamp' => now()->toIso8601String(),
                'timestampMs' => 1752236636015,
                'clientIp' => '127.0.0.1',
            ], 200, $headers);
        }

        if (str_ends_with($path, '/security/public-key-certificates')) {
            return Http::response([[
                'certificate' => $this->certificate,
                'certificateId' => 'CERTIFICATE-ID',
                'publicKeyId' => 'PUBLIC-KEY-ID',
                'validFrom' => now()->subDay()->toIso8601String(),
                'validTo' => now()->addYear()->toIso8601String(),
                'usage' => ['KsefTokenEncryption'],
            ]], 200, $headers);
        }

        if (str_ends_with($path, '/auth/ksef-token')) {
            if ($this->echoEncryptedTokenInWarning) {
                $encryptedToken = $request->data()['encryptedToken'] ?? null;
                $this->lastEncryptedToken = is_string($encryptedToken) ? $encryptedToken : null;
                $headers['X-System-Warning'] = 'diagnostic '.$this->lastEncryptedToken.' code=ABC';
            }

            return Http::response([
                'referenceNumber' => 'AUTH-REFERENCE',
                'authenticationToken' => [
                    'token' => self::AUTHENTICATION_TOKEN,
                    'validUntil' => now()->addMinutes(10)->toIso8601String(),
                ],
            ], 202, $headers);
        }

        if (str_ends_with($path, '/auth/AUTH-REFERENCE')) {
            $statusCode = $this->statusCodes[min($this->statusCalls, count($this->statusCodes) - 1)];
            $this->statusCalls++;

            return Http::response([
                'status' => [
                    'code' => $statusCode,
                    'description' => $statusCode === 100
                        ? 'Uwierzytelnianie w toku'
                        : 'Uwierzytelnianie zakończone',
                ],
                'isTokenRedeemed' => $this->isTokenRedeemed,
            ], 200, $headers);
        }

        if (str_ends_with($path, '/auth/token/redeem')) {
            $this->redeemCalls++;

            return Http::response([
                'accessToken' => [
                    'token' => self::ACCESS_TOKEN,
                    'validUntil' => now()->addMinutes(15)->toIso8601String(),
                ],
                'refreshToken' => [
                    'token' => self::REFRESH_TOKEN,
                    'validUntil' => now()->addDays(7)->toIso8601String(),
                ],
            ], 200, $headers);
        }

        if (str_ends_with($path, '/auth/token/refresh')) {
            $this->refreshCalls++;

            return Http::response([
                'accessToken' => [
                    'token' => 'NEW_'.self::ACCESS_TOKEN,
                    'validUntil' => now()->addMinutes(15)->toIso8601String(),
                ],
            ], 200, $headers);
        }

        if (str_ends_with($path, '/permissions/query/personal/grants')) {
            return Http::response([
                'permissions' => $this->permissions,
                'hasMore' => false,
            ], 200, $headers);
        }

        if (str_ends_with($path, '/tokens')) {
            $this->tokenQueryCalls++;
            $body = ['tokens' => $this->tokens];

            if ($this->tokenContinuationToken !== null) {
                $body['continuationToken'] = $this->tokenContinuationToken;
            }

            return Http::response($body, 200, $headers);
        }

        return Http::response(['title' => 'Unexpected fake request'], 599);
    }

    private static function testCertificate(): array
    {
        $privateKey = RSA::createKey(2048);
        $issuer = new X509;
        $issuer->setPrivateKey($privateKey);
        $issuer->setDN(['cn' => 'NEX-OMS Test CA']);
        $subject = new X509;
        $subject->setPublicKey($privateKey->getPublicKey());
        $subject->setDN(['cn' => 'NEX-OMS Test KSeF']);
        $subject->setStartDate('-1 day');
        $subject->setEndDate('+1 year');
        $certificate = new X509;
        $signed = $certificate->sign($issuer, $subject);
        $pem = $certificate->saveX509($signed);
        $base64Der = preg_replace('/-----[^-]+-----|\s+/', '', $pem);

        return [$privateKey, $base64Der];
    }

    private function warningHeaders(string $path): array
    {
        foreach ($this->warnings as $suffix => $warning) {
            if (str_ends_with($path, $suffix)) {
                return ['X-System-Warning' => $warning];
            }
        }

        return [];
    }
}
