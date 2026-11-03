<?php

namespace Modules\Automation\Services;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ReturnStatusSetting;
use App\Services\OrderReturnService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;

class AutomationReturnStatusService
{
    public function __construct(private readonly AutomationReturnContext $context, private readonly AutomationConditionEvaluator $conditions, private readonly OrderReturnService $returns) {}

    public function execute(AutomationRun $run, AutomationRunStep $step, array $configuration): array
    {
        return DB::transaction(function () use ($run, $step, $configuration): array {
            $managedRun = AutomationRun::query()->lockForUpdate()->findOrFail($run->id);
            $managedStep = $managedRun->steps()->lockForUpdate()->findOrFail($step->id);
            if (data_get($managedStep->output, 'return_status_change') === true) {
                return $managedStep->output;
            }
            if ($managedRun->status !== AutomationRun::STATUS_RUNNING || $managedRun->execution_token === null
                || $managedRun->execution_token !== $run->execution_token || ! $managedRun->execution_expires_at?->isFuture()
                || $managedStep->status !== 'running' || $managedRun->event_name !== 'return.parcel_status_changed') {
                throw new DomainException('Wykonanie akcji zwrotu nie jest już aktywne.');
            }
            ReturnStatusSetting::query()->where('code', 'new')->lockForUpdate()->firstOrFail();
            $target = ReturnStatusSetting::query()->lockForUpdate()->find($configuration['return_status_id'] ?? 0);
            $record = OrderReturn::query()->lockForUpdate()->find($managedRun->order_return_id);
            $order = Order::query()->lockForUpdate()->findOrFail($managedRun->order_id);
            $parcel = $this->context->parcel($order, $managedRun->event_payload);
            if (! $target || ! $record || ! $parcel || $parcel->order_return_id !== $record->id
                || ! in_array($configuration['parcel_scope'] ?? 'all', ['all', 'any'], true)
                || ! $this->conditions->matches($order, $managedRun->event_payload, $managedRun->rule_snapshot['conditions'] ?? [])) {
                throw new DomainException('Dane lub warunki zwrotu zmieniły się. Akcja nie została wykonana.');
            }
            $allRequired = ($configuration['parcel_scope'] ?? 'all') === 'all';
            $skipped = ($allRequired && ! $this->context->allDelivered($record))
                || ($record->localStatus?->code !== $target->code && in_array($record->localStatus?->code, ['completed', 'rejected'], true));
            $changed = ! $skipped && $record->local_status_id !== $target->id;
            if ($changed) {
                $this->returns->changeStatuses([$record->id => $record->lock_version], $target->id);
            }
            $output = ['return_status_change' => true, 'return_id' => $record->id, 'changed' => $changed,
                'skipped' => $skipped, 'return_status_label' => $target->name, 'parcel_scope' => $configuration['parcel_scope'] ?? 'all'];
            $managedStep->update(['output' => $output]);

            return $output;
        }, 3);
    }
}
