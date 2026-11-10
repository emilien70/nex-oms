<?php

namespace Modules\Automation\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;

class AutomationCustomEvent extends Model
{
    public const TRIGGER_PREFIX = 'custom_event.';

    public const MAX_POSITIONS = 200;

    protected $fillable = [
        'name', 'description', 'sort_order', 'button_enabled', 'button_label', 'icon', 'color',
        'confirmation_required', 'order_statuses', 'button_position',
    ];

    protected $casts = [
        'button_enabled' => 'boolean',
        'confirmation_required' => 'boolean',
        'order_statuses' => 'array',
        'button_position' => 'integer',
        'sort_order' => 'integer',
    ];

    public function triggerKey(): string
    {
        return self::TRIGGER_PREFIX.$this->getKey();
    }

    public function buttonLabel(): string
    {
        return $this->button_label ?: $this->name;
    }

    public function availableFor(Order $order): bool
    {
        return $this->button_enabled && ! $order->trashed()
            && (empty($this->order_statuses) || in_array($order->status, $this->order_statuses, true));
    }
}
