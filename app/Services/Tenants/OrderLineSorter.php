<?php

namespace App\Services\Tenants;

use App\Models\Tenants\Category;
use App\Models\Tenants\Product;
use Illuminate\Support\Collection;

/**
 * Orders cart / bill / sold lines (CartItem, OpenBillItem, SellingDetail; duck-typed) by product category (A-Z, natural, case-insensitive; no category last),
 * then by product name. Never runs a query: relations that are not loaded count as "no category" / "no name".
 * Callers eager load `product.category` first.
 */
final class OrderLineSorter
{
    private function __construct()
    {
    }

    /**
     * Same collection class as given (Eloquent stays Eloquent), keys reset.
     *
     * @param  Collection<int, \App\Models\Tenants\CartItem|\App\Models\Tenants\OpenBillItem|\App\Models\Tenants\SellingDetail>  $lines
     */
    public static function sort(Collection $lines): Collection
    {
        return $lines
            ->sort(fn ($a, $b): int => self::compare($a, $b))
            ->values();
    }

    /**
     * Sorts first, then groups per category_id (two categories with the same name stay separate).
     *
     * @return Collection<int, array{key: string, category: ?string, category_id: ?int, lines: Collection}>
     */
    public static function group(Collection $lines): Collection
    {
        $class = get_class($lines);
        $groups = [];

        foreach (self::sort($lines) as $line) {
            $category = self::categoryOf($line);
            $categoryId = $category?->id;
            $key = $categoryId === null ? 'none' : 'c'.$categoryId;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'category' => $category?->name,
                    'category_id' => $categoryId,
                    'items' => [],
                ];
            }

            $groups[$key]['items'][] = $line;
        }

        return collect(array_values($groups))->map(fn (array $group): array => [
            'key' => $group['key'],
            'category' => $group['category'],
            'category_id' => $group['category_id'],
            'lines' => new $class($group['items']),
        ]);
    }

    /**
     * Sort key: [no category last, category name, category id, product name, line id].
     */
    public static function compare($a, $b): int
    {
        $categoryA = self::categoryOf($a);
        $categoryB = self::categoryOf($b);

        return ($categoryA === null ? 1 : 0) <=> ($categoryB === null ? 1 : 0)
            ?: strnatcasecmp((string) $categoryA?->name, (string) $categoryB?->name)
            ?: ((int) $categoryA?->id) <=> ((int) $categoryB?->id)
            ?: strnatcasecmp(self::productNameOf($a), self::productNameOf($b))
            ?: ((int) ($a->id ?? 0)) <=> ((int) ($b->id ?? 0));
    }

    private static function productOf($line): ?Product
    {
        if (! $line->relationLoaded('product')) {
            return null;
        }

        return $line->getRelation('product');
    }

    private static function categoryOf($line): ?Category
    {
        $product = self::productOf($line);

        if ($product === null || ! $product->relationLoaded('category')) {
            return null;
        }

        return $product->getRelation('category');
    }

    private static function productNameOf($line): string
    {
        return (string) self::productOf($line)?->name;
    }
}
