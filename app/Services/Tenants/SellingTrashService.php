<?php

namespace App\Services\Tenants;

use App\Events\RecalculateEvent;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Moves sales to the trash and back.
 *
 * Trashing returns the stock of every line, restoring takes it out again, so a sale in the trash never holds
 * stock. The sale keeps its row, its lines, its date, its daily order number and its code, which is why DailyNumber,
 * CustomerNameService and SellingObserver count the trash too. Nothing here creates a number, so no NumberingLock.
 *
 * Every sale is handled in its own transaction: in a bulk action one sale that cannot be restored (not enough
 * stock) does not stop the others. Lock order: the sale row first, then the product rows in ascending id order
 * (the same order StockGuard uses), so it cannot deadlock with a sale being paid.
 *
 * Deleted products and products without stock (is_non_stock) are never touched; this mirrors AssignProduct.
 * A sale cannot be deleted for good: there is no force delete anywhere.
 */
class SellingTrashService
{
    public function __construct(private StockService $stockService)
    {
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array{done:int, skipped:int, failed:array<string, string>} failed: sale code => reason
     */
    public function trash(array $ids, ?User $by = null, ?string $reason = null): array
    {
        $reason = filled($reason) ? mb_substr(trim((string) $reason), 0, 255) : null;

        return $this->each($ids, fn (int $id): string => $this->trashOne($id, $by, $reason));
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array{done:int, skipped:int, failed:array<string, string>} failed: sale code => reason
     */
    public function restore(array $ids): array
    {
        return $this->each($ids, fn (int $id): string => $this->restoreOne($id));
    }

    /**
     * @param  array<int, int|string>  $ids
     * @param  callable(int): string  $handle  returns 'done' or 'skipped', or throws
     * @return array{done:int, skipped:int, failed:array<string, string>}
     */
    private function each(array $ids, callable $handle): array
    {
        $result = ['done' => 0, 'skipped' => 0, 'failed' => []];

        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            try {
                $outcome = $handle($id);
            } catch (ValidationException $e) {
                $code = Selling::withTrashed()->whereKey($id)->value('code') ?? (string) $id;
                $result['failed'][$code] = collect($e->errors())->flatten()->implode(' ');

                continue;
            }

            $result[$outcome === 'done' ? 'done' : 'skipped']++;
        }

        return $result;
    }

    private function trashOne(int $id, ?User $by, ?string $reason): string
    {
        return DB::transaction(function () use ($id, $by, $reason): string {
            $selling = Selling::withTrashed()->with('sellingDetails')->lockForUpdate()->find($id);

            // Already in the trash (a double click, or another cashier was faster): the stock came back once.
            if (! $selling || $selling->trashed()) {
                return 'skipped';
            }

            $this->returnStock($selling);

            $selling->forceFill(['deleted_by' => $by?->getKey(), 'delete_reason' => $reason])->save();
            $selling->delete();

            Log::info('selling moved to trash', [
                'selling_id' => $selling->id,
                'code' => $selling->code,
                'user_id' => $by?->getKey(),
                'reason' => $reason,
            ]);

            return 'done';
        }, 2);
    }

    private function restoreOne(int $id): string
    {
        return DB::transaction(function () use ($id): string {
            $selling = Selling::onlyTrashed()->with('sellingDetails')->lockForUpdate()->find($id);

            if (! $selling) {
                return 'skipped';
            }

            $this->takeStock($selling);

            $selling->forceFill(['deleted_by' => null, 'delete_reason' => null]);
            $selling->restore();

            Log::info('selling restored from trash', ['selling_id' => $selling->id, 'code' => $selling->code]);

            return 'done';
        }, 2);
    }

    /**
     * Quantity per product over all lines of the sale. Lines are stored in the product's base unit (a price unit
     * line already holds unit stock x qty), so the figure can be used as is.
     *
     * @return Collection<int, float> product id => qty
     */
    private function needs(Selling $selling): Collection
    {
        return $selling->sellingDetails
            ->groupBy('product_id')
            ->map(fn (Collection $lines): float => (float) $lines->sum('qty'));
    }

    /**
     * The products that really hold stock, locked in ascending id order.
     *
     * @param  Collection<int, float>  $needs
     * @return Collection<int, Product>
     */
    private function stockedProducts(Collection $needs): Collection
    {
        if ($needs->isEmpty()) {
            return collect();
        }

        return Product::withTrashed()
            ->whereIn('id', $needs->keys()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->reject(fn (Product $product): bool => $product->trashed() || $product->is_non_stock)
            ->values();
    }

    private function returnStock(Selling $selling): void
    {
        $needs = $this->needs($selling);
        $products = $this->stockedProducts($needs);

        foreach ($products as $product) {
            $this->stockService->addStock($product, $needs[$product->id]);
        }

        $this->recalculate($products);
    }

    /**
     * @throws ValidationException when a product does not have enough stock left for the sale
     */
    private function takeStock(Selling $selling): void
    {
        $needs = $this->needs($selling);
        $products = $this->stockedProducts($needs);

        StockGuard::assert(
            $products->map(fn (Product $product): array => ['product_id' => $product->id, 'qty' => $needs[$product->id]])->all()
        );

        foreach ($products as $product) {
            $this->stockService->reduceStock($product, $needs[$product->id]);
        }

        $this->recalculate($products);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function recalculate(Collection $products): void
    {
        if ($products->isNotEmpty()) {
            RecalculateEvent::dispatch($products, []);
        }
    }
}
