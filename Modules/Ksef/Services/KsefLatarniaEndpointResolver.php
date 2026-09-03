<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;

final class KsefLatarniaEndpointResolver
{
    private const BASE_URLS = [
        KsefLatarniaEnvironment::Test->value => 'https://api-latarnia-test.ksef.mf.gov.pl',
        KsefLatarniaEnvironment::Production->value => 'https://api-latarnia.ksef.mf.gov.pl',
    ];

    public function fromKsefEnvironment(KsefEnvironment $environment): KsefLatarniaEnvironment
    {
        return match ($environment) {
            KsefEnvironment::Test => KsefLatarniaEnvironment::Test,
            KsefEnvironment::Production => KsefLatarniaEnvironment::Production,
            KsefEnvironment::Demo => throw new KsefApiException(
                'Latarnia KSeF nie udostępnia środowiska DEMO.',
                'ksef_latarnia_environment_unavailable',
            ),
        };
    }

    public function statusUrl(KsefLatarniaEnvironment $environment): string
    {
        return $this->baseUrl($environment).'/status';
    }

    public function messagesUrl(KsefLatarniaEnvironment $environment): string
    {
        return $this->baseUrl($environment).'/messages';
    }

    private function baseUrl(KsefLatarniaEnvironment $environment): string
    {
        return self::BASE_URLS[$environment->value];
    }
}
