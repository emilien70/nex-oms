<?php

namespace Modules\Automation\Services;

use App\Models\Order;
use App\Models\OrderEmailMessage;
use App\Models\OrderStatusSetting;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\IntegrationApiLog;

class OrderAutomationHistory
{
    public function __construct(
        private readonly AutomationActivityFeed $feed,
        private readonly AutomationCatalog $catalog,
    ) {}

    public function forOrder(Order $order, int $page = 1): LengthAwarePaginator
    {
        return $order->automationRuns()
            ->with('steps')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'automation_page', max(1, $page))
            ->through(fn (AutomationRun $run): array => $this->present($run));
    }

    private function present(AutomationRun $run): array
    {
        $activity = $this->feed->present($run);
        $storedSteps = $run->steps->keyBy('position');
        $actions = $run->rule_snapshot['actions'] ?? [];

        return [
            'id' => $run->id,
            'actor' => 'Automat',
            'occurred_at' => $run->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i:s'),
            'trigger' => $this->catalog->triggerLabel($run->event_name),
            'rule_name' => $activity['title'],
            'error' => $activity['details'],
            'steps' => collect($activity['steps'])->map(function (array $step) use ($run, $storedSteps, $actions): array {
                $position = $step['position'] - 1;
                $stored = $storedSteps->get($position);
                $configuration = $stored?->configuration ?? ($actions[$position]['configuration'] ?? []);
                $configuration = is_array($configuration) ? $configuration : [];
                $type = $stored?->action_type ?? $step['action_type'];

                return [
                    'action' => $this->catalog->actionLabel($type),
                    'target' => $this->target($type, $configuration, $stored, (int) $run->order_id),
                    'status' => $step['status'],
                    'status_label' => $this->statusLabel($step['status'], $type, $step['status_label']),
                    'error' => $step['details'],
                ];
            })->all(),
        ];
    }

    private function target(string $type, array $configuration, ?AutomationRunStep $step, int $orderId): string
    {
        $output = $step?->output ?? [];

        return match ($type) {
            AutomationCatalog::ACTION_CHANGE_STATUS => OrderStatusSetting::labelFor(
                (string) ($output['status'] ?? $configuration['status'] ?? ''),
            ) ?? (string) ($configuration['status'] ?? '...'),
            AutomationCatalog::ACTION_CREATE_SHIPMENT => $this->shipmentTarget($configuration, $output),
            AutomationCatalog::ACTION_DELAY => max(1, (int) ($configuration['minutes'] ?? 1)).' min',
            AutomationCatalog::ACTION_CALL_URL => $this->urlTarget($configuration, $output, $step, $orderId),
            AutomationCatalog::ACTION_ISSUE_INVOICE => (string) ($output['invoice_number']
                ?? ('Seria #'.($configuration['invoice_series_id'] ?? '...'))),
            AutomationCatalog::ACTION_SEND_EMAIL => $this->emailTarget($configuration, $output, $step),
            AutomationCatalog::ACTION_PRINT => $this->printTarget($configuration, $output),
            AutomationCatalog::ACTION_DECREASE_STOCK => 'Produkty z Faktury VAT',
            default => '...',
        };
    }

    private function urlTarget(array $configuration, array $output, ?AutomationRunStep $step, int $orderId): string
    {
        $log = $step && is_string($output['request_id'] ?? null)
            ? IntegrationApiLog::query()
                ->where('integration', 'automation_url')
                ->where('operation', 'call_url')
                ->where('order_id', $orderId)
                ->where('request_id', $output['request_id'])
                ->where('request_payload->automation_run_id', $step->automation_run_id)
                ->first(['url', 'duration_ms'])
            : null;

        if (! $log) {
            return (string) ($configuration['url'] ?? '...');
        }

        return $log->url.($log->duration_ms !== null ? ' ('.number_format($log->duration_ms / 1000, 3, '.', '').'s)' : '');
    }

    private function shipmentTarget(array $configuration, array $output): string
    {
        $provider = (string) ($output['provider'] ?? $configuration['provider'] ?? CourierAccount::PROVIDER_INPOST_LOCKERS);
        $label = $this->catalog->shipmentProviderLabels()[$provider] ?? $provider;

        if ($provider === CourierAccount::PROVIDER_INPOST_LOCKERS) {
            $label .= ', '.($this->catalog->parcelTemplates()[$configuration['parcel_template'] ?? 'medium'] ?? '...');
        }

        return $label;
    }

    private function emailTarget(array $configuration, array $output, ?AutomationRunStep $step): string
    {
        $templateName = $step && isset($output['message_id'])
            ? OrderEmailMessage::query()
                ->where('automation_run_id', $step->automation_run_id)
                ->whereKey($output['message_id'])
                ->value('template_name_snapshot')
            : null;

        return $templateName ?: 'Szablon #'.($configuration['email_template_id'] ?? '...');
    }

    private function printTarget(array $configuration, array $output): string
    {
        $document = $this->catalog->printDocumentTypes()[$configuration['document_type'] ?? ''] ?? 'Dokument';
        $printer = $output['printer_name'] ?? null;

        return $printer ? $document.' - '.$printer : $document;
    }

    private function statusLabel(string $status, string $type, string $fallback): string
    {
        if ($status !== 'completed') {
            return $fallback;
        }

        return html_entity_decode(match ($type) {
            AutomationCatalog::ACTION_SEND_EMAIL => 'Przekazano do kolejki e-mail. Wynik wysy&#322;ki jest w wymianie wiadomo&#347;ci.',
            AutomationCatalog::ACTION_CREATE_SHIPMENT => 'Zlecono utworzenie przesy&#322;ki. Wynik jest w sekcji przesy&#322;ek.',
            AutomationCatalog::ACTION_PRINT => 'Dodano do kolejki NEX Printer. Nie oznacza potwierdzenia wydruku.',
            default => $fallback,
        }, ENT_QUOTES, 'UTF-8');
    }
}
