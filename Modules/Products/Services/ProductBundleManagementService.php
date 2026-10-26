<?php

namespace Modules\Products\Services;

use App\Support\CurrencyCatalog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Products\Models\Product;
use Throwable;

class ProductBundleManagementService
{
    private const EDITABLE_FIELDS = [
        'name',
        'ean',
        'price_gross',
        'currency',
        'vat_rate',
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
        $bundleId = null;

        try {
            return DB::transaction(function () use ($data, &$storedImage, &$bundleId): Product {
                $bundle = new Product;
                $bundle->fill($this->normalizeData($data) + [
                    'item_type' => Product::TYPE_BUNDLE,
                    'stock_quantity' => 0,
                ]);
                $bundle->save();
                $bundleId = (int) $bundle->getKey();

                $this->replaceComponents($bundle, $data['components'] ?? []);

                if (($data['image'] ?? null) instanceof UploadedFile) {
                    $storedImage = $this->images->store($bundle, $data['image']);
                    $bundle->forceFill($storedImage)->save();
                }

                return $bundle->refresh()->load('bundleComponents.component');
            });
        } catch (Throwable $exception) {
            if (is_array($storedImage) && is_int($bundleId)) {
                $this->images->deleteOwnedPaths([
                    $storedImage['image_path'] ?? null,
                    $storedImage['thumbnail_path'] ?? null,
                ], $bundleId);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Product $bundle, array $data): Product
    {
        if (! $bundle->isBundle()) {
            throw ValidationException::withMessages([
                'name' => 'Wybrana pozycja katalogowa nie jest zestawem.',
            ]);
        }

        $newImage = ($data['image'] ?? null) instanceof UploadedFile
            ? $this->images->store($bundle, $data['image'])
            : null;
        $removeImage = (bool) ($data['remove_image'] ?? false);
        $oldImagePaths = [];

        try {
            $updatedBundle = DB::transaction(function () use (
                $bundle,
                $data,
                $newImage,
                $removeImage,
                &$oldImagePaths,
            ): Product {
                $managedBundle = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($bundle->getKey());

                if (! $managedBundle->isBundle()) {
                    throw ValidationException::withMessages([
                        'name' => 'Wybrana pozycja katalogowa nie jest zestawem.',
                    ]);
                }

                $oldImagePaths = [
                    $managedBundle->image_path,
                    $managedBundle->thumbnail_path,
                ];

                $managedBundle->fill($this->normalizeData($data));
                $managedBundle->stock_quantity = 0;

                if (is_array($newImage)) {
                    $managedBundle->forceFill($newImage);
                } elseif ($removeImage) {
                    $managedBundle->forceFill([
                        'image_path' => null,
                        'thumbnail_path' => null,
                        'image_mime_type' => null,
                        'image_checksum' => null,
                    ]);
                }

                $managedBundle->save();
                $this->replaceComponents($managedBundle, $data['components'] ?? []);

                return $managedBundle->refresh()->load('bundleComponents.component');
            });
        } catch (Throwable $exception) {
            if (is_array($newImage)) {
                $this->images->deleteOwnedPaths([
                    $newImage['image_path'] ?? null,
                    $newImage['thumbnail_path'] ?? null,
                ], (int) $bundle->getKey());
            }

            throw $exception;
        }

        if (is_array($newImage) || $removeImage) {
            $this->images->deleteOwnedPaths($oldImagePaths, (int) $bundle->getKey());
        }

        return $updatedBundle;
    }

    /** @param array<int, array<string, mixed>> $components */
    private function replaceComponents(Product $bundle, array $components): void
    {
        $normalized = collect($components)
            ->map(fn (array $component): array => [
                'component_product_id' => (int) $component['product_id'],
                'quantity' => (int) $component['quantity'],
            ])
            ->values();
        $componentIds = $normalized->pluck('component_product_id');

        if ($normalized->count() < 2 || $componentIds->unique()->count() !== $normalized->count()) {
            throw ValidationException::withMessages([
                'components' => 'Zestaw musi zawierać co najmniej dwa różne produkty.',
            ]);
        }

        $validProducts = Product::query()
            ->whereIn('id', $componentIds->all())
            ->where('item_type', Product::TYPE_PRODUCT)
            ->lockForUpdate()
            ->count();

        if ($validProducts !== $componentIds->count()) {
            throw ValidationException::withMessages([
                'components' => 'Skład zestawu może zawierać wyłącznie istniejące produkty.',
            ]);
        }

        $bundle->bundleComponents()->delete();
        $bundle->bundleComponents()->createMany($normalized->all());
    }

    /** @param array<string, mixed> $data */
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
        $normalized['package_weight_kg'] = $this->nullableDecimal($normalized['package_weight_kg'] ?? null, 3);
        $normalized['package_length_cm'] = $this->nullableDecimal($normalized['package_length_cm'] ?? null, 2);
        $normalized['package_width_cm'] = $this->nullableDecimal($normalized['package_width_cm'] ?? null, 2);
        $normalized['package_height_cm'] = $this->nullableDecimal($normalized['package_height_cm'] ?? null, 2);
        $normalized['is_active'] = (bool) $normalized['is_active'];

        return $normalized;
    }

    private function decimal(mixed $value, int $scale): string
    {
        return (string) BigDecimal::of((string) $value)
            ->toScale($scale, RoundingMode::Unnecessary);
    }

    private function nullableDecimal(mixed $value, int $scale): ?string
    {
        return $value === null || $value === '' ? null : $this->decimal($value, $scale);
    }
}
