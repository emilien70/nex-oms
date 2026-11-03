<?php

namespace App\Models;

use App\Enums\OrderEmailAttachmentKind;
use App\Enums\OrderEmailAttachmentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OrderEmailMessageAttachment extends Model
{
    protected $fillable = [
        'position',
        'kind',
        'disk',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'checksum_sha256',
        'source_snapshot',
        'source_mode',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => OrderEmailAttachmentKind::class,
            'source_mode' => OrderEmailAttachmentSource::class,
            'file_size' => 'integer',
            'source_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Sent email attachment snapshots cannot be changed.');
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(OrderEmailMessage::class, 'order_email_message_id');
    }
}
