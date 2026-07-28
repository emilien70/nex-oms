<?php

namespace Modules\Invoices\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoices\Enums\InvoiceOperationSource;

class InvoiceRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id',
        'revision_number',
        'document_snapshot',
        'items_snapshot',
        'source_snapshot_hash',
        'source',
        'actor_snapshot',
        'created_at',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'document_snapshot' => 'array',
        'items_snapshot' => 'array',
        'actor_snapshot' => 'array',
        'source' => InvoiceOperationSource::class,
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('Historia wersji Pro formy jest niezmienna.');
        });

        static::deleting(function (): never {
            throw new DomainException('Historia wersji Pro formy jest niezmienna.');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
