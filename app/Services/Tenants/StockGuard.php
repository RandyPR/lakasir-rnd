<?php

namespace App\Services\Tenants;

use App\Models\Tenants\PriceUnit;
use App\Models\Tenants\Product;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative stock check, run INSIDE the sale transaction (lock order R1: step 5, after the sales lock,
 * bill and cart rows). It locks the product rows, so two concurrent sales of the last unit cannot both pass.
 * The rule matches App\Rules\CheckProductStock: `product.stock < needed` fails.
 */
final class StockGuard
{
    private function __construct()
    {
    }

    /**
     * @param  array<int|string, array{product_id:int,qty:float|int|string,price_unit_id?:int|null}>  $lines
     * @param  array{override?:int[]}  $opts  `override`: product ids that may go negative (official override)
     *
     * @throws ValidationException key products.{index}.qty (same key as the old API validation)
     */
    public static function assert(array $lines, array $opts = []): void
    {
        $override = array_map('intval', $opts['override'] ?? []);

        $unitIds = collect($lines)->pluck('price_unit_id')->filter()->unique()->values()->all();
        $units = $unitIds === [] ? collect() : PriceUnit::query()->whereIn('id', $unitIds)->get()->keyBy('id');

        // Need per product (a price-unit line needs qty x the unit's stock), blamed on the first line of the product.
        $need = [];
        $firstIndex = [];
        foreach ($lines as $index => $line) {
            $productId = (int) $line['product_id'];
            $perUnit = 1.0;
            if (! empty($line['price_unit_id'])) {
                $unit = $units->get((int) $line['price_unit_id']);
                $perUnit = $unit ? (float) $unit->stock : 1.0;
            }
            $need[$productId] = ($need[$productId] ?? 0.0) + ((float) $line['qty']) * $perUnit;
            $firstIndex[$productId] ??= $index;
        }

        if ($need === []) {
            return;
        }

        $ids = array_keys($need);
        sort($ids);

        // Ascending id order keeps the lock order stable between concurrent sales.
        $products = Product::withTrashed()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $errors = [];
        foreach ($ids as $productId) {
            $key = 'products.'.$firstIndex[$productId].'.qty';
            /** @var Product|null $product */
            $product = $products->get($productId);

            if (! $product) {
                $errors[$key] = __('The product is not found.');

                continue;
            }

            if ($product->trashed()) {
                $errors[$key] = __('Product :name was deleted', ['name' => $product->name]);

                continue;
            }

            if ($product->isStockUnlimited() || in_array($productId, $override, true)) {
                continue;
            }

            $needed = Money::qty($need[$productId]);
            if (Money::qty((float) $product->stock) < $needed) {
                $errors[$key] = __('Stock of :name is not enough (left :left)', [
                    'name' => $product->name,
                    'left' => Money::qty((float) $product->stock),
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
