<?php

namespace Modules\CustomerPortal\Observers;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Modules\CustomerPortal\Services\PortalAccessService;
use Throwable;

class PortalOrderObserver
{
    public function updated(Order $order): void
    {
        $before = (new Order)->setRawAttributes($order->getRawOriginal(), true);
        $access = app(PortalAccessService::class);
        if (! hash_equals($access->verificationIdentity($before), $access->verificationIdentity($order))) {
            $access->invalidateVerification((int) $order->id);
        }
    }

    public function deleted(Order $order): void
    {
        app(PortalAccessService::class)->invalidateVerification((int) $order->id);
    }

    public function restored(Order $order): void
    {
        app(PortalAccessService::class)->invalidateVerification((int) $order->id);
    }

    public function created(Order $order): void
    {
        $id = (int) $order->id;
        $order->getConnection()->afterCommit(static function () use ($id): void {
            try {
                app(PortalAccessService::class)->provision($id);
            } catch (Throwable $exception) {
                // Portal provisioning must not turn an already committed import into a failed order.
                Log::error('Customer portal link provisioning failed.', ['order_id' => $id, 'exception_class' => $exception::class]);
            }
        });
    }
}
