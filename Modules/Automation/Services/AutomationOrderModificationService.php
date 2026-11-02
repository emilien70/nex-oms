<?php

namespace Modules\Automation\Services;

use App\Models\Order;
use App\Services\OrderVariableService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;

class AutomationOrderModificationService
{
    public function __construct(
        private readonly AutomationOrderFields $fields,
        private readonly OrderVariableService $variables,
    ) {}

    public function execute(AutomationRun $run, AutomationRunStep $step, array $configuration): array
    {
        $this->fields->validateConfiguration($configuration);

        return DB::transaction(function () use ($run, $step, $configuration): array {
            $managedRun = AutomationRun::query()->lockForUpdate()->findOrFail($run->id);
            $managedStep = $managedRun->steps()->lockForUpdate()->findOrFail($step->id);
            if (data_get($managedStep->output, 'order_modification') === true) {
                return $managedStep->output;
            }
            if ($managedRun->status !== AutomationRun::STATUS_RUNNING
                || $managedRun->execution_token === null || $managedRun->execution_token !== $run->execution_token
                || ! $managedRun->execution_expires_at?->isFuture() || $managedStep->status !== 'running') {
                throw new DomainException('Wykonanie automatycznej akcji nie jest już aktywne.');
            }

            $order = Order::query()->lockForUpdate()->findOrFail($managedRun->order_id);
            $order->assertPurchaseReady();
            $field = $configuration['field'];
            $value = $this->fields->normalize($field, $this->variables->render((string) ($configuration['value'] ?? ''), $order));
            $before = $order->getAttribute($field);
            $order->setAttribute($field, $value);
            $changed = $order->isDirty($field);
            $definition = $this->fields->definitions()[$field];
            $output = [
                'order_modification' => true, 'field' => $field,
                'field_label' => $definition['group'].' - '.$definition['label'],
                'changed' => $changed, 'before' => $before, 'after' => $value,
            ];
            if ($changed) {
                $order->save();
                $order->events()->create([
                    'event_type' => 'automation_order_modified',
                    'title' => 'Automatycznie zmodyfikowano zamówienie',
                    'description' => $output['field_label'],
                    'payload' => $output + ['automation_run_id' => $run->id, 'automation_run_step_id' => $step->id],
                ]);
            }

            // Commit the local mutation and its deduplication receipt together.
            $managedStep->update(['output' => $output]);

            return $output;
        }, 3);
    }
}
