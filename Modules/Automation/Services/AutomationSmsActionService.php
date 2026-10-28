<?php

namespace Modules\Automation\Services;

use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;
use Modules\Integrations\MacroDroid\Models\SmsTemplate;
use Modules\Integrations\MacroDroid\Services\SmsPreparationService;
use Ramsey\Uuid\Uuid;

class AutomationSmsActionService
{
    public function __construct(private readonly SmsPreparationService $preparation) {}

    public function execute(AutomationRun $run, AutomationRunStep $step, array $configuration): array
    {
        $template = SmsTemplate::query()->findOrFail($configuration['sms_template_id'] ?? null);
        $messages = $this->preparation->prepare($run->order, $template, [
            'recipient_customer' => $configuration['recipient_customer'] ?? true,
            'recipient_phones' => $configuration['recipient_phones'] ?? [],
            'source' => 'automation', 'actor_name' => 'Automatyczna akcja', 'automation_run_id' => $run->id,
            'idempotency_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, "nex-oms:sms:run:{$run->id}:position:{$step->position}")->toString(),
        ]);

        return ['message_ids' => array_map(fn ($message) => $message->id, $messages),
            'sms_template_id' => $template->id, 'template_name' => $template->name,
            'recipient_count' => count($messages), 'status' => 'queued'];
    }
}
