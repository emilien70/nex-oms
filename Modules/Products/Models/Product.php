<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
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
}
