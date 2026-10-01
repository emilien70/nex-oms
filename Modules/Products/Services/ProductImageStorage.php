<?php

namespace Modules\Products\Services;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Products\Models\Product;
use Throwable;

class ProductImageStorage
{
    private const THUMBNAIL_SIZE = 320;

    /**
     * @return array{image_path: string, thumbnail_path: string, image_mime_type: string, image_checksum: string}
     */
    public function store(Product $product, UploadedFile $image): array
    {
        $sourcePath = $image->getRealPath();
        $mimeType = $image->getMimeType();

        if (! is_string($sourcePath) || $sourcePath === '' || ! is_string($mimeType)) {
            throw new DomainException('Nie udało się odczytać zdjęcia produktu.');
        }

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new DomainException('Format zdjęcia produktu nie jest obsługiwany.'),
        };

        $checksum = hash_file('sha256', $sourcePath);

        if (! is_string($checksum) || $checksum === '') {
            throw new DomainException('Nie udało się obliczyć sumy kontrolnej zdjęcia produktu.');
        }

        $directory = "products/{$product->getKey()}";
        $identifier = Str::uuid()->toString();
        $originalPath = null;
        $thumbnailPath = null;

        try {
            $storedOriginal = $image->storeAs(
                $directory,
                "original-{$identifier}.{$extension}",
                'local',
            );

            if (! is_string($storedOriginal) || $storedOriginal === '') {
                throw new DomainException('Nie udało się zapisać zdjęcia produktu.');
            }

            $originalPath = $storedOriginal;
            $thumbnailPath = "{$directory}/thumbnail-{$identifier}.jpg";

            if (! Storage::disk('local')->put($thumbnailPath, $this->thumbnailBytes($sourcePath))) {
                throw new DomainException('Nie udało się zapisać miniatury produktu.');
            }

            return [
                'image_path' => $originalPath,
                'thumbnail_path' => $thumbnailPath,
                'image_mime_type' => $mimeType,
                'image_checksum' => $checksum,
            ];
        } catch (Throwable $exception) {
            $this->deleteOwnedPaths(
                array_values(array_filter([$originalPath, $thumbnailPath], 'is_string')),
                (int) $product->getKey(),
            );

            throw $exception;
        }
    }

    /** @param list<string|null> $paths */
    public function deleteOwnedPaths(array $paths, int $productId): void
    {
        $prefix = "products/{$productId}/";
        $ownedPaths = array_values(array_filter(
            $paths,
            static fn (?string $path): bool => is_string($path)
                && $path !== ''
                && str_starts_with($path, $prefix),
        ));

        if ($ownedPaths !== []) {
            Storage::disk('local')->delete($ownedPaths);
        }
    }

    private function thumbnailBytes(string $sourcePath): string
    {
        if (! extension_loaded('gd')) {
            throw new DomainException('Rozszerzenie GD jest wymagane do utworzenia miniatury produktu.');
        }

        $contents = file_get_contents($sourcePath);
        $source = is_string($contents) ? imagecreatefromstring($contents) : false;

        if ($source === false) {
            throw new DomainException('Nie udało się przetworzyć zdjęcia produktu.');
        }

        $thumbnail = imagecreatetruecolor(self::THUMBNAIL_SIZE, self::THUMBNAIL_SIZE);

        if ($thumbnail === false) {
            imagedestroy($source);

            throw new DomainException('Nie udało się przygotować miniatury produktu.');
        }

        try {
            $white = imagecolorallocate($thumbnail, 255, 255, 255);
            imagefill($thumbnail, 0, 0, $white);

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $cropSize = min($sourceWidth, $sourceHeight);
            $sourceX = (int) floor(($sourceWidth - $cropSize) / 2);
            $sourceY = (int) floor(($sourceHeight - $cropSize) / 2);

            if (! imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                $sourceX,
                $sourceY,
                self::THUMBNAIL_SIZE,
                self::THUMBNAIL_SIZE,
                $cropSize,
                $cropSize,
            )) {
                throw new DomainException('Nie udało się przygotować miniatury produktu.');
            }

            ob_start();
            $encoded = imagejpeg($thumbnail, null, 88);
            $bytes = ob_get_clean();

            if (! $encoded || ! is_string($bytes) || $bytes === '') {
                throw new DomainException('Nie udało się zakodować miniatury produktu.');
            }

            return $bytes;
        } finally {
            imagedestroy($thumbnail);
            imagedestroy($source);
        }
    }
}
