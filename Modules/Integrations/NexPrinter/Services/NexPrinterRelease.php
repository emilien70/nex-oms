<?php

namespace Modules\Integrations\NexPrinter\Services;

final readonly class NexPrinterRelease
{
    public function __construct(
        public string $version,
        public string $filename,
        public string $sha256,
        public int $size,
        public ?string $publishedAt,
    ) {}

    public function storagePath(): string
    {
        return NexPrinterReleaseService::RELEASES_DIRECTORY.'/'.$this->filename;
    }
}
