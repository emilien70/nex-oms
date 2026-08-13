<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\ValueObjects\KsefTokenPair;

final readonly class KsefAuthenticationService
{
    public function __construct(
        private KsefTokenAuthenticationService $token,
        private KsefCertificateAuthenticationService $certificate,
    ) {}

    public function authenticate(KsefCredential $credential, string $contextNip): KsefTokenPair
    {
        return match ($credential->authentication_method) {
            KsefAuthenticationMethod::Token => $this->token->authenticate($credential, $contextNip),
            KsefAuthenticationMethod::Certificate => $this->certificate->authenticate($credential, $contextNip),
        };
    }
}
