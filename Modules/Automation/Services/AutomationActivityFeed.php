<?php

namespace Modules\Automation\Services;

use App\Models\OrderStatusSetting;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;

class AutomationActivityFeed
{
    private const RECENT_SECONDS = 60;

    private const MAX_ITEMS = 12;

    public function recent(): array
    {
        $recentSince = now()->subSeconds(self::RECENT_SECONDS);

        return AutomationRun::query()
            ->with('steps')
            ->where(function ($query) use ($recentSince): void {
                $query->whereIn('status', [
                    AutomationRun::STATUS_QUEUED,
                    AutomationRun::STATUS_RUNNING,
                ])->orWhere(function ($recentQuery) use ($recentSince): void {
                    $recentQuery
                        ->whereIn('status', [
                            AutomationRun::STATUS_WAITING,
                            AutomationRun::STATUS_COMPLETED,
                            AutomationRun::STATUS_COMPLETED_WITH_ERRORS,
                            AutomationRun::STATUS_FAILED,
                        ])
                        ->where('updated_at', '>=', $recentSince);
                });
            })
            ->latest('updated_at')
            ->limit(self::MAX_ITEMS)
            ->get()
            ->map(fn (AutomationRun $run): array => $this->present($run))
            ->all();
    }

    private function present(AutomationRun $run): array
    {
        $actions = collect($run->rule_snapshot['actions'] ?? []);
        $currentStep = $this->currentStep($run);
        $position = $currentStep?->position ?? 0;
        $action = $actions->get($position, []);
        $actionType = $currentStep?->action_type ?? (string) ($action['type'] ?? '');
        $configuration = $currentStep?->configuration ?? ($action['configuration'] ?? []);
        $totalSteps = $actions->count();
        $completedSteps = $run->steps->where('status', 'completed')->count();
        $failedStep = $run->steps->firstWhere('status', 'failed');
        $isError = in_array($run->status, [
            AutomationRun::STATUS_COMPLETED_WITH_ERRORS,
            AutomationRun::STATUS_FAILED,
        ], true);

        return [
            'id' => $run->id,
            'order_id' => $run->order_id,
            'order_label' => $this->decode('Zam&oacute;wienie').' '.$run->order_id,
            'order_url' => route('orders.show', $run->order_id),
            'title' => trim((string) data_get($run->rule_snapshot, 'name'))
                ?: 'Automatyczna akcja #'.$run->id,
            'status' => $run->status,
            'status_label' => $this->statusLabel($run->status),
            'tone' => $this->tone($run->status),
            'message' => $this->message($run, $actionType, is_array($configuration) ? $configuration : []),
            'details' => $isError ? ($run->error_message ?: $failedStep?->error_message) : null,
            'is_terminal' => $run->isFinished(),
            'can_dismiss' => ! in_array($run->status, [
                AutomationRun::STATUS_QUEUED,
                AutomationRun::STATUS_RUNNING,
            ], true),
            'progress' => [
                'completed' => $completedSteps,
                'current' => $totalSteps > 0 ? min($position + 1, $totalSteps) : 0,
                'total' => $totalSteps,
            ],
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

    private function currentStep(AutomationRun $run): ?AutomationRunStep
    {
        if ($run->status === AutomationRun::STATUS_RUNNING) {
            return $run->steps->firstWhere('status', 'running')
                ?? $run->steps->firstWhere('status', 'queued')
                ?? $run->steps->last();
        }

        if ($run->status === AutomationRun::STATUS_FAILED) {
            return $run->steps->firstWhere('status', 'failed') ?? $run->steps->last();
        }

        return $run->steps->last();
    }

    private function message(AutomationRun $run, string $actionType, array $configuration): string
    {
        return match ($run->status) {
            AutomationRun::STATUS_QUEUED => 'Oczekuje na wykonanie',
            AutomationRun::STATUS_RUNNING => $this->actionMessage($actionType, $configuration),
            AutomationRun::STATUS_WAITING => $this->waitingMessage($configuration),
            AutomationRun::STATUS_COMPLETED => $this->decode('Automatyczna akcja zosta&#322;a wykonana.'),
            AutomationRun::STATUS_COMPLETED_WITH_ERRORS => $this->decode('Automatyczna akcja zosta&#322;a wykonana z b&#322;&#281;dami.'),
            AutomationRun::STATUS_FAILED => $this->decode('Automatyczna akcja nie zosta&#322;a wykonana.'),
            default => 'Aktualizowanie automatycznej akcji',
        };
    }

    private function actionMessage(string $actionType, array $configuration): string
    {
        return match ($actionType) {
            AutomationCatalog::ACTION_CHANGE_STATUS => $this->decode('Zmieniam status zam&oacute;wienia na').' '
                .(OrderStatusSetting::labelFor((string) ($configuration['status'] ?? '')) ?? '...'),
            AutomationCatalog::ACTION_CREATE_SHIPMENT => $this->decode('Zlecam utworzenie przesy&#322;ki').' ('
                .$this->providerLabel((string) ($configuration['provider'] ?? 'inpost_lockers')).')',
            AutomationCatalog::ACTION_DELAY => $this->waitingMessage($configuration),
            AutomationCatalog::ACTION_CALL_URL => $this->decode('Wywo&#322;uj&#281; URL metod&#261; GET'),
            default => $this->decode('Wykonuj&#281; automatyczn&#261; akcj&#281;'),
        };
    }

    private function waitingMessage(array $configuration): string
    {
        $minutes = max(1, (int) ($configuration['minutes'] ?? 1));

        return $this->decode('Oczekuje na kontynuacj&#281;').' ('.$minutes.' min)';
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'inpost_lockers' => 'InPost Paczkomaty',
            'inpost_courier' => 'InPost Kurier',
            'dpd' => 'DPD',
            default => $provider,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            AutomationRun::STATUS_QUEUED => 'W kolejce',
            AutomationRun::STATUS_RUNNING => 'Wykonywanie',
            AutomationRun::STATUS_WAITING => 'Oczekiwanie',
            AutomationRun::STATUS_COMPLETED => 'Gotowe',
            AutomationRun::STATUS_COMPLETED_WITH_ERRORS => $this->decode('Zako&#324;czono z b&#322;&#281;dami'),
            AutomationRun::STATUS_FAILED => $this->decode('B&#322;&#261;d'),
            default => $status,
        };
    }

    private function tone(string $status): string
    {
        return match ($status) {
            AutomationRun::STATUS_COMPLETED => 'success',
            AutomationRun::STATUS_COMPLETED_WITH_ERRORS, AutomationRun::STATUS_FAILED => 'error',
            AutomationRun::STATUS_WAITING => 'waiting',
            default => 'progress',
        };
    }

    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }
}
