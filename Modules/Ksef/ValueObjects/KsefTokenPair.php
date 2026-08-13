<?php

namespace Modules\Ksef\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class KsefTokenPair
{
    public function __construct(
        public string $accessToken,
        public CarbonImmutable $accessTokenValidUntil,
        public string $refreshToken,
        public CarbonImmutable $refreshTokenValidUntil,
        public array $systemWarnings = [],
    ) {}
}
