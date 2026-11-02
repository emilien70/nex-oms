<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Automation\Services\AutomationOrderSources;
use Ramsey\Uuid\Uuid;

class OrderCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function name(): string
    {
        return 'order.created';
    }

    public function payload(): array
    {
        return [
            'event_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'nex-oms:'.$this->name().':'.$this->order->id)->toString(),
            'event_name' => $this->name(),
            'order_id' => $this->order->id,
            'order_source' => app(AutomationOrderSources::class)->identity($this->order),
            'source' => 'order_creation',
        ];
    }
}
