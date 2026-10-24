<?php

namespace App\Livewire\Cashier;

use App\Enums\OpenBillStatus;
use App\Filament\Tenant\Pages\Cashier;
use App\Models\Tenants\Category;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\Product;
use App\Models\Tenants\Setting;
use App\Services\Tenants\DailyNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The product list of the cashier page: search, category chips and "load more".
 *
 * It is a child of the Cashier page on purpose: typing in the search box or picking a category only re-renders
 * this component, and cart actions (add, reduce, name, note) never query the product list again.
 */
class ProductBrowser extends Component
{
    public const PAGE_SIZE = 24;

    public const MAX = 96;

    /** Longest search text sent to the database. */
    private const SEARCH_MAX = 100;

    /** Set from the browser (debounced). Never trimmed or changed here, or it would fight the input being typed in. */
    public string $search = '';

    public ?int $categoryId = null;

    public int $limit = self::PAGE_SIZE;

    /** @var list<array{id: int, name: string, count: int}> */
    public array $chips = [];

    public function mount(): void
    {
        $this->chips = $this->loadChips();
    }

    public function selectCategory(?int $categoryId = null): void
    {
        $this->categoryId = $this->knownCategoryId($categoryId);
        $this->limit = self::PAGE_SIZE;
    }

    public function loadMore(): void
    {
        $this->limit = min($this->limit + self::PAGE_SIZE, self::MAX);
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE_SIZE;
    }

    /**
     * Enter in the search box: an exact barcode or SKU goes straight to the cart, anything else stays a text search.
     */
    public function searchEnter(?string $code = null): void
    {
        $code = trim($code ?? $this->search);

        if ($code === '' || ! Product::findByBarcodeOrSku($code)) {
            return;
        }

        $this->dispatch('product-scanned', code: $code)->to(Cashier::class);

        $this->search = '';
        $this->limit = self::PAGE_SIZE;
        $this->dispatch('search-cleared');
    }

    #[On('products-refresh')]
    public function refreshProducts(): void
    {
        $this->limit = self::PAGE_SIZE;
        $this->chips = $this->loadChips();
        $this->categoryId = $this->knownCategoryId($this->categoryId);
    }

    public function render()
    {
        $limit = $this->pageLimit();
        $found = $this->loadProducts($limit);
        $products = $found->take($limit)->values();

        return view('livewire.cashier.product-browser', [
            'products' => $products,
            'committed' => $this->committedQty($products),
            'hasMore' => $found->count() > $limit,
            'lowStockThreshold' => (int) Setting::get('minimum_stock_nofication', 10),
            'currency' => Setting::get('currency', 'IDR'),
        ]);
    }

    /**
     * One query for the page: stock total as a subquery column, one extra row to know whether there is more.
     *
     * @return Collection<int, Product>
     */
    protected function loadProducts(int $limit): Collection
    {
        $term = mb_substr(trim($this->search), 0, self::SEARCH_MAX);

        return Product::query()
            ->select('products.*')
            ->withStockTotal()
            ->sellableInPos()
            ->inCategory($this->categoryId)
            ->when($term !== '', fn ($query) => $query->searchInPos($term))
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->limit($limit + 1)
            ->get();
    }

    /**
     * Quantity of each shown product that is already on OPEN bills (not paid yet, so still in stock): product_id =>
     * SUM(open_bill_items.qty). One indexed query for the products on this page. The card says "left N after open
     * bills". Without the bills table (deploy not finished) or on any database error it is simply empty.
     *
     * @param  Collection<int, Product>|\Illuminate\Support\Collection<int, Product>  $products
     * @return array<int, float>
     */
    protected function committedQty($products): array
    {
        if ($products->isEmpty() || ! DailyNumber::billsTableAvailable()) {
            return [];
        }

        try {
            return OpenBillItem::query()
                ->join('open_bills', 'open_bills.id', '=', 'open_bill_items.open_bill_id')
                ->where('open_bills.status', OpenBillStatus::Open->value)
                ->whereIn('open_bill_items.product_id', $products->pluck('id')->all())
                ->groupBy('open_bill_items.product_id')
                ->selectRaw('open_bill_items.product_id as product_id, SUM(open_bill_items.qty) as committed')
                ->pluck('committed', 'product_id')
                ->map(fn ($qty): float => (float) $qty)
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * Categories that have something to sell, A-Z (natural, case-insensitive), with a static product count.
     *
     * @return list<array{id: int, name: string, count: int}>
     */
    protected function loadChips(): array
    {
        $counts = Product::query()
            ->sellableInPos()
            ->selectRaw('products.category_id, count(*) as aggregate')
            ->groupBy('products.category_id')
            ->pluck('aggregate', 'category_id');

        if ($counts->isEmpty()) {
            return [];
        }

        return Category::query()
            ->whereIn('id', $counts->keys())
            ->get(['id', 'name'])
            ->sort(fn (Category $a, Category $b): int => strnatcasecmp((string) $a->name, (string) $b->name) ?: $a->id <=> $b->id)
            ->map(fn (Category $category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'count' => (int) $counts[$category->id],
            ])
            ->values()
            ->all();
    }

    private function knownCategoryId(?int $categoryId): ?int
    {
        if ($categoryId === null) {
            return null;
        }

        return collect($this->chips)->contains('id', $categoryId) ? $categoryId : null;
    }

    /** $limit is public state, so a tampered value cannot ask for more than MAX rows. */
    private function pageLimit(): int
    {
        return min(max($this->limit, self::PAGE_SIZE), self::MAX);
    }
}
