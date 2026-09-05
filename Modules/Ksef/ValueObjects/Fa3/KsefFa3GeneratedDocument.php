<?php

namespace Modules\Ksef\ValueObjects\Fa3;

final readonly class KsefFa3GeneratedDocument
{
    public function __construct(
        public string $xml,
        public string $generatedAt,
        public string $schemaId,
        public ?array $integrityEvidence = null,
    ) {}
}
