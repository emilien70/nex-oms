<?php

namespace Modules\Products\Services;

use App\Support\CurrencyCatalog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Throwable;

class ProductManagementService
{
    private const EDITABLE_FIELDS = [
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
        'is_active',
    ];

    public function __construct(
        private readonly CurrencyCatalog $currencies,
        private readonly ProductImageStorage $images,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Product
    {
        $storedImage = null;
        $productId = null;

        try {
            return DB::transaction(function () use ($data, &$storedImage, &$productId): Product {
                $product = new Product;
                $product->fill($this->normalizeData($data) + [
                    'item_type' => Product::TYPE_PRODUCT,
                ]);
                $product->save();
                $productId = (int) $product->getKey();

                if (($data['image'] ?? null) instanceof UploadedFile) {
                    $storedImage = $this->images->store($product, $data['image']);
                    $product->forceFill($storedImage)->save();
                }

                return $product->refresh();
            });
        } catch (Throwable $exception) {
            if (is_array($storedImage) && is_int($productId)) {
                $this->images->deleteOwnedPaths([
                    $storedImage['image_path'] ?? null,
                    $storedImage['thumbnail_path'] ?? null,
                ], $productId);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, array $data): Product
    {
        $newImage = ($data['image'] ?? null) instanceof UploadedFile
            ? $this->images->store($product, $data['image'])
            : null;
        $removeImage = (bool) ($data['remove_image'] ?? false);
        $oldImagePaths = [];

        try {
            $updatedProduct = DB::transaction(function () use (
                $product,
                $data,
                $newImage,
                $removeImage,
                &$oldImagePaths,
            ): Product {
                $managedProduct = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($product->getKey());

                $oldImagePaths = [
                    $managedProduct->image_path,
                    $managedProduct->thumbnail_path,
                ];

                $managedProduct->fill($this->normalizeData($data));

                if (is_array($newImage)) {
                    $managedProduct->forceFill($newImage);
                } elseif ($removeImage) {
                    $managedProduct->forceFill([
                        'image_path' => null,
                        'thumbnail_path' => null,
                        'image_mime_type' => null,
                        'image_checksum' => null,
                    ]);
                }

                $managedProduct->save();

                return $managedProduct->refresh();
            });
        } catch (Throwable $exception) {
            if (is_array($newImage)) {
                $this->images->deleteOwnedPaths([
                    $newImage['image_path'] ?? null,
                    $newImage['thumbnail_path'] ?? null,
                ], (int) $product->getKey());
            }

            throw $exception;
        }

        if (is_array($newImage) || $removeImage) {
            $this->images->deleteOwnedPaths($oldImagePaths, (int) $product->getKey());
        }

        return $updatedProduct;
    }

    public function setActive(Product $product, bool $isActive): Product
    {
        return DB::transaction(function () use ($product, $isActive): Product {
            $managedProduct = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->getKey());

            $managedProduct->is_active = $isActive;
            $managedProduct->save();

            return $managedProduct->refresh();
        });
    }

    public function setStockQuantity(Product $product, int $stockQuantity): Product
    {
        if (! $product->isProduct()) {
            throw new \DomainException('Stan zestawu wynika ze stanów jego składników.');
        }

        return DB::transaction(function () use ($product, $stockQuantity): Product {
            $managedProduct = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->getKey());

            $managedProduct->stock_quantity = $stockQuantity;
            $managedProduct->save();

            return $managedProduct->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeData(array $data): array
    {
        $normalized = Arr::only($data, self::EDITABLE_FIELDS);
        $normalized['name'] = trim((string) $normalized['name']);
        $normalized['ean'] = filled($normalized['ean'] ?? null)
            ? trim((string) $normalized['ean'])
            : null;
        $normalized['price_gross'] = $this->decimal($normalized['price_gross'], 2);
        $normalized['currency'] = $this->currencies->require($normalized['currency'] ?? null);
        $normalized['vat_rate'] = $this->decimal($normalized['vat_rate'], 2);
        $normalized['stock_quantity'] = (int) $normalized['stock_quantity'];
        $normalized['package_weight_kg'] = $this->nullableDecimal($normalized['package_weight_kg'] ?? null, 3);
        $normalized['package_length_cm'] = $this->nullableDecimal($normalized['package_length_cm'] ?? null, 2);
        $normalized['package_width_cm'] = $this->nullableDecimal($normalized['package_width_cm'] ?? null, 2);
        $normalized['package_height_cm'] = $this->nullableDecimal($normalized['package_height_cm'] ?? null, 2);
        $normalized['is_active'] = (bool) $normalized['is_active'];

        return $normalized;
    }

    private function nullableDecimal(mixed $value, int $scale): ?string
    {
        return $value === null || $value === '' ? null : $this->decimal($value, $scale);
    }

    private function decimal(mixed $value, int $scale): string
    {
        return (string) BigDecimal::of((string) $value)
            ->toScale($scale, RoundingMode::Unnecessary);
    }
}
