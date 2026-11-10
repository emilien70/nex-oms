<?php

namespace Modules\Automation\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationCustomEventInvocation extends Model
{
    public const PENDING = 'pending';

    public const CHECKED = 'checked';

    public const UNAVAILABLE = 'unavailable';

    public const FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'order_id', 'automation_custom_event_id', 'event_label', 'status', 'run_count'];

    protected $casts = ['order_id' => 'integer', 'automation_custom_event_id' => 'integer', 'run_count' => 'integer'];

    public function result(): array
    {
        return [
            'status' => $this->status,
            'run_count' => $this->run_count,
            'message' => match ($this->status) {
                self::PENDING => 'Sprawdzanie reguł...',
                self::UNAVAILABLE => 'Ta szybka akcja nie jest już dostępna dla tego zamówienia.',
                self::FAILED => 'Nie udało się dokończyć sprawdzania reguł. Sprawdź historię automatycznych akcji przed ponowieniem.',
                self::CHECKED => $this->run_count === 0
                    ? 'Żadna aktywna reguła nie pasuje do tego zamówienia.'
                    : 'Przekazano pasujące reguły do automatyzacji. Wynik sprawdzisz w historii automatycznych akcji.',
            },
        ];
    }
}
