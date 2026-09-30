<?php

namespace App\View\Components;

use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class OrderEmailExchange extends Component
{
    public function __construct(public readonly Order $order) {}

    public function render(): View
    {
        $emailAccounts = EmailAccount::query()
            ->whereNotNull('smtp_password')
            ->orderBy('email')
            ->orderBy('name')
            ->get();
        $emailTemplates = EmailTemplate::query()
            ->where('is_hidden', false)
            ->orderBy('name')
            ->get();
        $emailMessages = OrderEmailMessage::query()
            ->with('attachments')
            ->where('order_id', $this->order->getKey())
            ->latest('id')
            ->limit(25)
            ->get();

        return view('components.order-email-exchange', [
            'emailAccounts' => $emailAccounts,
            'emailTemplates' => $emailTemplates,
            'emailMessages' => $emailMessages,
            'defaultEmailAccountId' => $emailAccounts->first()?->getKey(),
        ]);
    }
}
