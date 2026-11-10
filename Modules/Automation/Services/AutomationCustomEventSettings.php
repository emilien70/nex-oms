<?php

namespace Modules\Automation\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Automation\Models\AutomationCustomEvent;
use Modules\Automation\Models\AutomationRule;

class AutomationCustomEventSettings
{
    public const MAX_COLUMNS = 6;

    private const LAYOUT_SETTINGS_ID = 1;

    public function maxColumns(): int
    {
        return (int) (DB::table('automation_quick_action_settings')->where('id', self::LAYOUT_SETTINGS_ID)->value('max_columns') ?? 4);
    }

    public function save(?AutomationCustomEvent $event, array $data): void
    {
        DB::transaction(function () use ($event, $data): void {
            $events = $this->lockedEvents();
            $record = $event ? $events->find($event->id) : new AutomationCustomEvent;
            abort_if($record === null, 404);

            if (! $record->exists) {
                $record->sort_order = ((int) $events->max('sort_order')) + 1;
            }

            if (! $data['button_enabled']) {
                $record->button_position = null;
            } elseif ($record->button_position === null) {
                $used = $events->pluck('button_position')->filter()->all();
                $next = ((int) max([0, ...$used])) + 1;
                if ($next > AutomationCustomEvent::MAX_POSITIONS) {
                    $next = array_values(array_diff(range(1, AutomationCustomEvent::MAX_POSITIONS), $used))[0] ?? null;
                }
                if ($next === null) {
                    throw ValidationException::withMessages(['button_enabled' => 'Brak wolnych pól w siatce szybkich akcji.']);
                }
                $record->button_position = $next;
            }

            $record->fill($data)->save();
        }, attempts: 3);
    }

    public function delete(AutomationCustomEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $record = AutomationCustomEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (AutomationRule::query()->where('trigger', $record->triggerKey())->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['event' => 'Zdarzenie jest używane przez reguły. Najpierw zmień ich zdarzenie lub wyłącz przycisk.']);
            }
            $record->delete();
        }, attempts: 3);
    }

    public function reorder(array $ids, array $previousIds): void
    {
        if (count($ids) !== count($previousIds) || array_diff($ids, $previousIds) !== []) {
            throw ValidationException::withMessages(['event_ids' => 'Lista zdarzeń jest niekompletna lub nieprawidłowa.']);
        }

        DB::transaction(function () use ($ids, $previousIds): void {
            $events = $this->lockedEvents();
            $current = $events->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values()->modelKeys();
            abort_if($current !== $previousIds, 409, 'Lista zdarzeń zmieniła się. Odśwież stronę i spróbuj ponownie.');

            $events = $events->keyBy('id');
            foreach ($ids as $index => $id) {
                $event = $events->get($id);
                if ($event->sort_order !== $index + 1) {
                    $event->update(['sort_order' => $index + 1]);
                }
            }
        }, attempts: 3);
    }

    public function saveLayout(array $layout, array $previous, ?int $maxColumns = null, ?int $previousMaxColumns = null): void
    {
        DB::transaction(function () use ($layout, $previous, $maxColumns, $previousMaxColumns): void {
            $events = $this->lockedEvents()->where('button_enabled', true)->keyBy('id');
            $current = $events->pluck('button_position', 'id')->all();
            ksort($current);
            ksort($layout);
            ksort($previous);

            abort_if($current !== $previous, 409, 'Układ szybkich akcji zmienił się. Odśwież stronę i spróbuj ponownie.');

            if (array_keys($layout) !== array_keys($current)) {
                throw ValidationException::withMessages(['layout' => 'Układ musi obejmować wszystkie włączone przyciski.']);
            }

            if ($maxColumns !== null) {
                $settings = DB::table('automation_quick_action_settings')->where('id', self::LAYOUT_SETTINGS_ID);
                $currentMaxColumns = (int) $settings->lockForUpdate()->value('max_columns');
                abort_if($currentMaxColumns !== $previousMaxColumns, 409, 'Limit szybkich akcji zmienił się. Odśwież stronę i spróbuj ponownie.');

                if ($currentMaxColumns !== $maxColumns) {
                    $settings->update(['max_columns' => $maxColumns]);
                }
            }

            foreach ($layout as $id => $position) {
                $event = $events->get($id);
                if ($event->button_position !== $position) {
                    $event->update(['button_position' => $position]);
                }
            }
        }, attempts: 3);
    }

    private function lockedEvents(): Collection
    {
        return AutomationCustomEvent::query()->orderBy('id')->lockForUpdate()->get();
    }
}
