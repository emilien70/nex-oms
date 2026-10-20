<?php

namespace Modules\Products\Models;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Integrations\Allegro\Models\AllegroProductLink;
use Modules\Integrations\Prestashop\Models\PrestashopProductLink;
use Modules\Invoices\Models\InvoiceItem;

class Product extends Model
{
    use HasFactory;

    public const TYPE_PRODUCT = 'product';

    public const TYPE_BUNDLE = 'bundle';

    protected $fillable = [
        'item_type',
        'name',
        'ean',
        'price_gross',
        'currency',
        'vat_rate',
        'stock_quantity',
        'package_weight_kg',
        'package_length_cm',
        'package_width_cm',
        'package_height_cm',
        'image_path',
        'thumbnail_path',
        'image_mime_type',
        'image_checksum',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_gross' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'stock_quantity' => 'integer',
            'package_weight_kg' => 'decimal:3',
            'package_length_cm' => 'decimal:2',
            'package_width_cm' => 'decimal:2',
            'package_height_cm' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(ProductStockMovement::class);
    }

    public function prestashopProductLinks(): HasMany
    {
        return $this->hasMany(PrestashopProductLink::class);
    }

    public function allegroProductLinks(): HasMany
    {
        return $this->hasMany(AllegroProductLink::class);
    }

    public function bundleComponents(): HasMany
    {
        return $this->hasMany(ProductBundleComponent::class, 'bundle_id');
    }

    public function containingBundles(): HasMany
    {
        return $this->hasMany(ProductBundleComponent::class, 'component_product_id');
    }

    public function isProduct(): bool
    {
        return $this->item_type === self::TYPE_PRODUCT;
    }

    public function isBundle(): bool
    {
        return $this->item_type === self::TYPE_BUNDLE;
    }

    public function availableStockQuantity(): int
    {
        if (! $this->isBundle()) {
            return (int) $this->stock_quantity;
        }

        $components = $this->relationLoaded('bundleComponents')
            ? $this->bundleComponents
            : $this->bundleComponents()->with('component')->get();

        if ($components->count() < 2) {
            return 0;
        }

        $available = null;

        foreach ($components as $bundleComponent) {
            $component = $bundleComponent->component;
            $quantity = (int) $bundleComponent->quantity;

            if ($component === null || ! $component->is_active || $quantity < 1) {
                return 0;
            }

            $componentAvailable = intdiv(max(0, (int) $component->stock_quantity), $quantity);
            $available = $available === null
                ? $componentAvailable
                : min($available, $componentAvailable);
        }

        return $available ?? 0;
    }
}
