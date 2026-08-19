<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\File\X509;

class KsefOnlineSessionApiFake
{
    public readonly PrivateKey $privateKey;

    public array $certificates;

    public array $openResponse = [
        'referenceNumber' => '20260819-SO-TEST-REFERENCE',
        'validUntil' => '2026-08-19T22:00:00Z',
    ];

    public array $sendResponse = [
        'referenceNumber' => '20260819-INV-TEST-REFERENCE',
    ];

    public array $statusResponse = [
        'invoicingDate' => '2026-08-19T10:00:00Z',
        'ordinalNumber' => 1,
        'status' => [
            'code' => 150,
            'description' => 'Przetwarzanie',
        ],
    ];

    /** @var array<string, array{status?: int, body?: mixed, connection?: bool}> */
    public array $failures = [];

    public int $publicKeyCalls = 0;

    public int $openCalls = 0;

    public int $sendCalls = 0;

    public int $closeCalls = 0;

    public int $statusCalls = 0;

    public ?array $openPayload = null;

    public ?array $sendPayload = null;

    public function __construct()
    {
        [$this->privateKey, $certificate] = $this->testCertificate();
        $this->certificates = [
            [
                'certificate' => $certificate,
                'publicKeyId' => 'TOKEN-KEY-ID',
                'validFrom' => '2026-01-01T00:00:00Z',
                'validTo' => '2027-01-01T00:00:00Z',
                'usage' => ['KsefTokenEncryption'],
            ],
            [
                'certificate' => $certificate,
                'publicKeyId' => 'SYMMETRIC-KEY-ID',
                'validFrom' => '2026-08-01T00:00:00Z',
                'validTo' => '2027-01-01T00:00:00Z',
                'usage' => ['SymmetricKeyEncryption'],
            ],
        ];
    }

    public function __invoke(Request $request): mixed
    {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
        $failure = $this->failureFor($path);

        if ($failure !== null) {
            $this->recordFailedCall($request, $path);

            if (($failure['connection'] ?? false) === true) {
                return (Http::failedConnection('Synthetic KSeF connection failure'))($request);
            }

            return Http::response(
                $failure['body'] ?? ['reasonCode' => 'SYNTHETIC_FAILURE'],
                $failure['status'] ?? 500,
            );
        }

        if (str_ends_with($path, '/security/public-key-certificates')) {
            $this->publicKeyCalls++;

            return Http::response($this->certificates);
        }

        if (str_ends_with($path, '/sessions/online')) {
            $this->openCalls++;
            $this->openPayload = $request->data();

            return Http::response($this->openResponse, 201);
        }

        if (preg_match('#/sessions/online/[^/]+/invoices$#', $path) === 1) {
            $this->sendCalls++;
            $this->sendPayload = $request->data();

            return Http::response($this->sendResponse, 202);
        }

        if (preg_match('#/sessions/online/[^/]+/close$#', $path) === 1) {
            $this->closeCalls++;

            return Http::response(null, 204);
        }

        if (preg_match('#/sessions/[^/]+/invoices/[^/]+$#', $path) === 1) {
            $this->statusCalls++;

            return Http::response(array_replace([
                'referenceNumber' => $this->sendResponse['referenceNumber'] ?? null,
                'invoiceHash' => $this->sendPayload['invoiceHash'] ?? null,
            ], $this->statusResponse));
        }

        return Http::response(['reasonCode' => 'UNEXPECTED_FAKE_REQUEST'], 599);
    }

    /** @return array{0: PrivateKey, 1: string} */
    private function testCertificate(): array
    {
        $privateKey = RSA::createKey(2048);
        $issuer = new X509;
        $issuer->setPrivateKey($privateKey);
        $issuer->setDN(['cn' => 'NEX-OMS Online Session Test CA']);
        $subject = new X509;
        $subject->setPublicKey($privateKey->getPublicKey());
        $subject->setDN(['cn' => 'NEX-OMS Online Session Test']);
        $subject->setStartDate('-1 day');
        $subject->setEndDate('+1 year');
        $certificate = new X509;
        $signed = $certificate->sign($issuer, $subject);
        $pem = $certificate->saveX509($signed);

        return [
            $privateKey,
            preg_replace('/-----[^-]+-----|\s+/', '', $pem),
        ];
    }

    private function failureFor(string $path): ?array
    {
        foreach ($this->failures as $suffix => $failure) {
            if (str_ends_with($path, $suffix)) {
                return $failure;
            }
        }

        return null;
    }

    private function recordFailedCall(Request $request, string $path): void
    {
        if (str_ends_with($path, '/security/public-key-certificates')) {
            $this->publicKeyCalls++;
        } elseif (str_ends_with($path, '/sessions/online')) {
            $this->openCalls++;
            $this->openPayload = $request->data();
        } elseif (preg_match('#/sessions/online/[^/]+/invoices$#', $path) === 1) {
            $this->sendCalls++;
            $this->sendPayload = $request->data();
        } elseif (preg_match('#/sessions/online/[^/]+/close$#', $path) === 1) {
            $this->closeCalls++;
        } elseif (preg_match('#/sessions/[^/]+/invoices/[^/]+$#', $path) === 1) {
            $this->statusCalls++;
        }
    }
}
