<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\ValueObjects\KsefCurrentTokenMetadata;
use Modules\Ksef\ValueObjects\KsefCurrentTokenResolution;

final readonly class KsefCurrentTokenResolver
{
    public function __construct(
        private KsefHttpClient $http,
    ) {}

    public function resolve(KsefEnvironment $environment, string $accessToken): KsefCurrentTokenResolution
    {
        try {
            $response = $this->http->get(
                $environment,
                '/tokens',
                $accessToken,
                [
                    'status' => 'Active',
                    'pageSize' => 100,
                ],
            );
        } catch (KsefApiException $exception) {
            return new KsefCurrentTokenResolution(null, $exception->systemWarning);
        }

        $tokens = $response->data['tokens'] ?? null;
        $continuationToken = $response->data['continuationToken'] ?? null;

        if (! is_array($tokens)
            || count($tokens) !== 1
            || ($continuationToken !== null && ! is_string($continuationToken))
            || (is_string($continuationToken) && trim($continuationToken) !== '')) {
            return new KsefCurrentTokenResolution(null, $response->systemWarning);
        }

        $token = $this->metadata($tokens[0]);

        return new KsefCurrentTokenResolution($token, $response->systemWarning);
    }

    private function metadata(mixed $token): ?KsefCurrentTokenMetadata
    {
        if (! is_array($token)) {
            return null;
        }

        $status = $token['status'] ?? null;
        $requestedPermissions = $token['requestedPermissions'] ?? null;
        $authorIdentifier = $token['authorIdentifier'] ?? null;
        $contextIdentifier = $token['contextIdentifier'] ?? null;

        if ($status !== 'Active'
            || ! is_array($requestedPermissions)
            || collect($requestedPermissions)->contains(fn (mixed $permission): bool => ! is_string($permission) || $permission === '')
            || ! is_array($authorIdentifier)
            || ! is_array($contextIdentifier)
            || ! $this->isNonEmptyString($authorIdentifier['type'] ?? null)
            || ! $this->isNonEmptyString($authorIdentifier['value'] ?? null)
            || ! $this->isNonEmptyString($contextIdentifier['type'] ?? null)
            || ! $this->isNonEmptyString($contextIdentifier['value'] ?? null)) {
            return null;
        }

        return new KsefCurrentTokenMetadata(
            $status,
            array_values($requestedPermissions),
            $authorIdentifier['type'],
            $authorIdentifier['value'],
            $contextIdentifier['type'],
            $contextIdentifier['value'],
        );
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
