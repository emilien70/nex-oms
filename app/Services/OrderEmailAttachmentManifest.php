<?php

namespace App\Services;

use App\Enums\OrderEmailAttachmentKind;
use App\Models\OrderEmailMessage;
use App\Models\OrderEmailMessageAttachment;
use Illuminate\Support\Collection;
use JsonException;

final class OrderEmailAttachmentManifest
{
    /** @throws JsonException */
    public function seal(OrderEmailMessage $message): void
    {
        $seal = $this->calculate($message->attachments()->get());

        $message->sealAttachments($seal['count'], $seal['hash']);
    }

    /** @throws JsonException */
    public function matches(OrderEmailMessage $message): bool
    {
        if (! is_int($message->attachment_count)
            || ! is_string($message->attachment_manifest_sha256)
            || strlen($message->attachment_manifest_sha256) !== 64) {
            return false;
        }

        $seal = $this->calculate($message->attachments);

        return $message->attachment_count === $seal['count']
            && hash_equals($message->attachment_manifest_sha256, $seal['hash']);
    }

    /**
     * @param  Collection<int, OrderEmailMessageAttachment>  $attachments
     * @return array{count: int, hash: string}
     *
     * @throws JsonException
     */
    private function calculate(Collection $attachments): array
    {
        $manifest = $attachments
            ->sortBy('position', SORT_NUMERIC)
            ->values()
            ->map(function (OrderEmailMessageAttachment $attachment): array {
                // Keep the original byte-for-byte manifest format for queued legacy copies.
                $row = [
                    'position' => (int) $attachment->position,
                    'kind' => $attachment->kind instanceof OrderEmailAttachmentKind
                        ? $attachment->kind->value
                        : (string) $attachment->kind,
                    'disk' => (string) $attachment->disk,
                    'file_path' => (string) $attachment->file_path,
                    'file_name' => (string) $attachment->file_name,
                    'mime_type' => (string) $attachment->mime_type,
                    'file_size' => (int) $attachment->file_size,
                    'checksum_sha256' => (string) $attachment->checksum_sha256,
                ];
                if ($attachment->source_mode !== null) {
                    $row['file_path'] = $attachment->file_path;
                    $row['file_size'] = $attachment->file_size;
                    $row['checksum_sha256'] = $attachment->checksum_sha256;
                    $row['source_mode'] = $attachment->source_mode->value;
                    $row['source_snapshot'] = $this->canonicalSource($attachment->source_snapshot);
                }

                return $row;
            })
            ->all();

        return [
            'count' => count($manifest),
            'hash' => hash('sha256', json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
        ];
    }

    private function canonicalSource(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalSource($item), $value);
    }
}
