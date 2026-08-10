<?php

namespace Modules\Automation\Services;

use App\Models\OrderStatusSetting;
use App\Services\OrderStatusService;
use DomainException;
use Modules\Automation\Models\AutomationRun;

class AutomationActionExecutor
{
    public function __construct(
        private readonly OrderStatusService $orderStatusService,
        private readonly AutomationShipmentActionService $shipmentActionService,
        private readonly AutomationUrlActionService $urlActionService,
        private readonly AutomationInvoiceActionService $invoiceActionService,
    ) {}

    public function execute(AutomationRun $run, string $type, array $configuration): array
    {
        return match ($type) {
            AutomationCatalog::ACTION_CHANGE_STATUS => $this->changeStatus($run, $configuration),
            AutomationCatalog::ACTION_CREATE_SHIPMENT => $this->shipmentActionService->execute($run, $configuration),
            AutomationCatalog::ACTION_CALL_URL => $this->urlActionService->execute($run, $configuration),
            AutomationCatalog::ACTION_ISSUE_INVOICE => $this->invoiceActionService->execute($run, $configuration),
            default => throw new DomainException('Nieobslugiwany typ akcji automatycznej: '.$type),
        };
    }

    private function changeStatus(AutomationRun $run, array $configuration): array
    {
        $status = (string) ($configuration['status'] ?? '');

        if (! array_key_exists($status, OrderStatusSetting::orderedStatuses())) {
            throw new DomainException('Docelowy status zamowienia nie istnieje.');
        }

        $changed = $this->orderStatusService->change(
            $run->order,
            $status,
            'automation_run:'.$run->id,
        );

        return ['changed' => $changed, 'status' => $status];
    }
}
