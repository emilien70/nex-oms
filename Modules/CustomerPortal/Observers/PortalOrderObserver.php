<?php

namespace Modules\CustomerPortal\Observers;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Modules\CustomerPortal\Services\PortalAccessService;
use Throwable;

class PortalOrderObserver
{
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
