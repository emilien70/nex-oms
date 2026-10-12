<?php

namespace Modules\Integrations\PayNow\ValueObjects;

use Modules\Integrations\PayNow\Models\PayNowPayment;

final readonly class PayNowPaymentLinkResult
{
    public function __construct(
        public PayNowPayment $payment,
        public bool $reused,
    ) {}
}
