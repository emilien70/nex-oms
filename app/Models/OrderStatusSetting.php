<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class OrderStatusSetting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'status',
        'sort_order',
        'color',
        'short_name',
        'full_name',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public static function defaults(): array
    {
        return [
            Order::STATUS_NEW => [
                'status' => Order::STATUS_NEW,
                'name' => Order::statuses()[Order::STATUS_NEW],
                'full_name' => html_entity_decode('Przyj&#281;to nowe zam&oacute;wienie', ENT_QUOTES, 'UTF-8'),
                'color' => '#f4ad42',
                'sort_order' => 1,
            ],
            Order::STATUS_PENDING => [
                'status' => Order::STATUS_PENDING,
                'name' => Order::statuses()[Order::STATUS_PENDING],
                'full_name' => html_entity_decode('Zam&oacute;wienie oczekuje na obs&#322;ug&#281;', ENT_QUOTES, 'UTF-8'),
                'color' => '#f4ad42',
                'sort_order' => 2,
            ],
            Order::STATUS_SHIPPED => [
                'status' => Order::STATUS_SHIPPED,
                'name' => Order::statuses()[Order::STATUS_SHIPPED],
                'full_name' => html_entity_decode('Produkty zosta&#322;y wys&#322;ane', ENT_QUOTES, 'UTF-8'),
                'color' => '#f6d743',
                'sort_order' => 3,
            ],
            Order::STATUS_CANCELLED => [
                'status' => Order::STATUS_CANCELLED,
                'name' => Order::statuses()[Order::STATUS_CANCELLED],
                'full_name' => html_entity_decode('Zam&oacute;wienie zosta&#322;o anulowane', ENT_QUOTES, 'UTF-8'),
                'color' => '#111827',
                'sort_order' => 4,
            ],
        ];
    }

    public static function syncDefaults(): void
    {
        if (! self::tableReady()) {
            return;
        }

        foreach (self::defaults() as $default) {
            $status = self::withTrashed()
                ->where('status', $default['status'])
                ->first();

            if (! $status) {
                self::query()->create([
                    'status' => $default['status'],
                    'sort_order' => $default['sort_order'],
                    'color' => $default['color'],
                    'short_name' => $default['name'],
                    'full_name' => $default['full_name'],
                ]);
            }
        }
    }

    public static function orderedSettings(): Collection
    {
        if (! self::tableReady()) {
            return collect(self::defaults())
                ->sortBy('sort_order')
                ->values();
        }

        self::syncDefaults();

        $defaults = self::defaults();

        return self::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (self $setting): array => [
                'id' => $setting->id,
                'code' => $setting->status,
                'name' => $setting->short_name ?: ($defaults[$setting->status]['name'] ?? $setting->status),
                'full_name' => $setting->full_name ?: ($defaults[$setting->status]['full_name'] ?? $setting->status),
                'color' => $setting->color ?: ($defaults[$setting->status]['color'] ?? '#64748b'),
                'text_color' => self::readableTextColor($setting->color ?: ($defaults[$setting->status]['color'] ?? '#64748b')),
            ]);
    }

    public static function orderedStatuses(): array
    {
        return self::orderedSettings()
            ->mapWithKeys(fn (array $status): array => [$status['code'] => $status['name']])
            ->all();
    }

    public static function labelFor(string $status): ?string
    {
        return self::orderedStatuses()[$status] ?? null;
    }

    public static function colorFor(string $status): string
    {
        if (! self::tableReady()) {
            return self::defaults()[$status]['color'] ?? '#64748b';
        }

        self::syncDefaults();

        $setting = self::query()
            ->where('status', $status)
            ->first();

        return $setting?->color ?: (self::defaults()[$status]['color'] ?? '#64748b');
    }

    public static function textColorFor(string $status): string
    {
        return self::readableTextColor(self::colorFor($status));
    }

    private static function readableTextColor(string $color): string
    {
        $color = ltrim($color, '#');

        if (strlen($color) !== 6) {
            return '#ffffff';
        }

        $red = hexdec(substr($color, 0, 2));
        $green = hexdec(substr($color, 2, 2));
        $blue = hexdec(substr($color, 4, 2));
        $brightness = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

        return $brightness > 160 ? '#111827' : '#ffffff';
    }

    private static function tableReady(): bool
    {
        $table = (new self())->getTable();

        return Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at');
    }
}
