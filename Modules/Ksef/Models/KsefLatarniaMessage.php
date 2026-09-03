<?php

namespace Modules\Ksef\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaMessageType;

class KsefLatarniaMessage extends Model
{
    protected $fillable = [
        'source_environment',
        'external_message_id',
        'event_id',
        'version',
        'category',
        'type',
        'title',
        'text',
        'start_at',
        'end_at',
        'published_at',
        'payload_json',
        'payload_hash',
        'first_fetched_at',
        'last_seen_at',
    ];

    protected $casts = [
        'source_environment' => KsefLatarniaEnvironment::class,
        'event_id' => 'integer',
        'version' => 'integer',
        'category' => KsefLatarniaMessageCategory::class,
        'type' => KsefLatarniaMessageType::class,
        'start_at' => 'immutable_datetime',
        'end_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'first_fetched_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $message): void {
            $changed = array_diff(array_keys($message->getDirty()), ['last_seen_at', 'updated_at']);

            if ($changed !== []) {
                throw new DomainException('Historia komunikatów Latarni KSeF jest niezmienna.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Historia komunikatów Latarni KSeF jest niezmienna.');
        });
    }
}
