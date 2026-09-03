<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;

final readonly class KsefCertificateManagementAccessTokenProvider
{
    public function __construct(
        private KsefAccessTokenManager $accessTokens,
    ) {}

    public function getValidAccessToken(KsefEnvironment $environment): string
    {
        $credential = KsefCredential::query()
            ->where('environment', $environment->value)
            ->first();

        if ($credential === null
            || $credential->authentication_method !== KsefAuthenticationMethod::Certificate
            || ! is_string($credential->authentication_certificate)
            || $credential->authentication_certificate === ''
            || ! is_string($credential->authentication_private_key)
            || $credential->authentication_private_key === '') {
            throw new KsefApiException(
                'Sprawdzenie certyfikatów KSeF wymaga uwierzytelnienia certyfikatem Authentication/XAdES dla wybranego środowiska.',
                'certificate_management_requires_certificate_auth',
            );
        }

        return $this->accessTokens->getValidAccessToken($environment);
    }
}
