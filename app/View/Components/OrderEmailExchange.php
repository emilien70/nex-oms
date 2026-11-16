<?php

namespace App\View\Components;

use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\Component;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use Modules\Integrations\MacroDroid\Models\SmsMessage;
use Modules\Integrations\MacroDroid\Models\SmsTemplate;

class OrderEmailExchange extends Component
{
    public function __construct(public readonly Order $order) {}

    public function render(): View
    {
        $archived = $this->order->isArchived();
        $emailAccounts = $archived ? collect() : EmailAccount::query()
            ->whereNotNull('smtp_password')
            ->orderBy('email')
            ->orderBy('name')
            ->get();
        $emailTemplates = $archived ? collect() : EmailTemplate::query()
            ->where('is_hidden', false)
            ->orderBy('name')
            ->get();
        $emailMessages = OrderEmailMessage::query()
            ->with('attachments')
            ->where('order_id', $this->order->getKey())
            ->latest('id')
            ->when(! $archived, fn ($query) => $query->limit(25))
            ->get();

        $smsDevice = $archived ? null : SmsDevice::configured();

        return view('components.order-email-exchange', [
            'emailAccounts' => $emailAccounts,
            'archived' => $archived,
            'emailTemplates' => $emailTemplates,
            'emailMessages' => $emailMessages,
            'smsReady' => ! $archived && Schema::hasTable('sms_templates'),
            'smsEnabled' => $smsDevice?->enabled ?? false,
            'smsSenderPhone' => $smsDevice?->sender_phone,
            'smsTemplates' => ! $archived && Schema::hasTable('sms_templates')
                ? SmsTemplate::query()->orderBy('name')->get() : collect(),
            'smsMessages' => Schema::hasTable('order_sms_messages')
                ? SmsMessage::query()->where('order_id', $this->order->id)->latest('id')->when(! $archived, fn ($query) => $query->limit(25))->get() : collect(),
            'defaultEmailAccountId' => $emailAccounts->first()?->getKey(),
        ]);
    }
}
