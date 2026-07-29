<?php

namespace Modules\Invoices\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Modules\Invoices\Enums\InvoiceOperationSource;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;

class InvoiceOperationContextFactory
{
    public function manual(Request $request): InvoiceOperationContext
    {
        $user = $request->user();

        return new InvoiceOperationContext(
            source: InvoiceOperationSource::Manual,
            actorSnapshot: $user === null ? null : array_filter([
                'type' => 'user',
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name ?? null,
                'email' => $user->email ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            occurredAt: CarbonImmutable::now(config('app.timezone')),
        );
    }
}
