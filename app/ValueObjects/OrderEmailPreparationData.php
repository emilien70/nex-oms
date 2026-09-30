<?php

namespace App\ValueObjects;

use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailMessageSource;
use App\Models\EmailAccount;
use App\Models\OrderEmailMessage;
use App\Models\User;
use Modules\Automation\Models\AutomationRun;

final readonly class OrderEmailPreparationData
{
    public function __construct(
        public string $toEmail,
        public ?string $toName,
        public OrderEmailMessageSource $source,
        public string $idempotencyKey,
        public ?User $user = null,
        public ?AutomationRun $automationRun = null,
        public ?OrderEmailMessage $retryOf = null,
        public ?string $subjectTemplate = null,
        public ?string $bodyTemplate = null,
        public ?EmailTemplateFormat $format = null,
        public ?EmailAccount $emailAccount = null,
        public ?string $actorName = null,
        public bool $contentIsRendered = false,
    ) {}
}
