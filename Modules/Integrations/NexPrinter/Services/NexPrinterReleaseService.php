<?php

namespace Modules\Integrations\NexPrinter\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Integrations\NexPrinter\Enums\NexPrinterClientVersionState;
use Throwable;

class NexPrinterReleaseService
{
    public const RELEASES_DIRECTORY = 'nex-printer/releases';

    public const MANIFEST_PATH = self::RELEASES_DIRECTORY.'/latest.json';

    private const MAXIMUM_INSTALLER_SIZE = 262_144_000;

    public function current(): ?NexPrinterRelease
    {
        $disk = Storage::disk('local');

        try {
            if (! $disk->exists(self::MANIFEST_PATH)) {
                return null;
            }

            $manifestContents = $disk->get(self::MANIFEST_PATH);
            if (strlen($manifestContents) > 65_536) {
                return null;
            }

            $manifest = json_decode($manifestContents, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)) {
                return null;
            }

            $version = $manifest['version'] ?? null;
            $filename = $manifest['filename'] ?? null;
            $sha256 = $manifest['sha256'] ?? null;
            $size = $manifest['size'] ?? null;
            $publishedAt = $manifest['published_at'] ?? null;

            if (! is_string($version)
                || preg_match('/\A\d+\.\d+\.\d+\z/', $version) !== 1
                || ! is_string($filename)
                || $filename !== "NEX-Printer-Setup-{$version}-x64.exe"
                || basename($filename) !== $filename
                || ! is_string($sha256)
                || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
                || ! is_int($size)
                || $size < 1
                || $size > self::MAXIMUM_INSTALLER_SIZE
                || ($publishedAt !== null && (! is_string($publishedAt) || strlen($publishedAt) > 40))) {
                return null;
            }

            $path = self::RELEASES_DIRECTORY.'/'.$filename;
            if (! $disk->exists($path) || $disk->size($path) !== $size) {
                return null;
            }

            return new NexPrinterRelease(
                $version,
                $filename,
                $sha256,
                $size,
                $publishedAt,
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function clientVersionState(
        ?string $clientVersion,
        NexPrinterRelease $release,
    ): NexPrinterClientVersionState {
        $clientVersion = is_string($clientVersion) ? trim($clientVersion) : '';
        if (preg_match('/\A\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?\z/', $clientVersion) !== 1) {
            return NexPrinterClientVersionState::Unknown;
        }

        return match (version_compare($clientVersion, $release->version)) {
            -1 => NexPrinterClientVersionState::Outdated,
            0 => NexPrinterClientVersionState::Current,
            default => NexPrinterClientVersionState::Preview,
        };
    }

    public function checksumMatches(NexPrinterRelease $release): bool
    {
        $disk = Storage::disk('local');

        try {
            if (! $disk->exists($release->storagePath())
                || $disk->size($release->storagePath()) !== $release->size) {
                return false;
            }

            $actualHash = hash_file('sha256', $disk->path($release->storagePath()));

            return is_string($actualHash) && hash_equals($release->sha256, $actualHash);
        } catch (Throwable) {
            return false;
        }
    }
}
