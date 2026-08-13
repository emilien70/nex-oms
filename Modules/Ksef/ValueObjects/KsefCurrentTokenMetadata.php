<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefCurrentTokenMetadata
{
    public function __construct(
        public string $status,
        public array $requestedPermissions,
        public string $authorIdentifierType,
        public string $authorIdentifierValue,
        public string $contextIdentifierType,
        public string $contextIdentifierValue,
    ) {}

    public function requestsPermission(string $permission): bool
    {
        return in_array($permission, $this->requestedPermissions, true);
    }

    public function isStrictNipOwner(string $contextNip): bool
    {
        return $this->status === 'Active'
            && $this->authorIdentifierType === 'Nip'
            && $this->contextIdentifierType === 'Nip'
            && $this->authorIdentifierValue === $this->contextIdentifierValue
            && $this->authorIdentifierValue === $contextNip
            && $this->contextIdentifierValue === $contextNip;
    }
}
