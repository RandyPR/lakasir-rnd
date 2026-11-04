<?php

namespace App\Services\Tenants;

use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Net sales, cost of goods and profit of any stretch of time, for the cards on the dashboard and on the sales list.
 *
 * One definition for every card (the same as SalesTrendService, Selling::grand_total_price and the sales report):
 *   net sales (omzet) = total price - tax - discount per item - discount of the whole sale
 *   cost of goods (HPP) = the cost stored on the sale when it was made (sellings.total_cost), so a cost price that
 *                         changes later never rewrites an old sale
 *   profit (laba)      = net sales - cost of goods
 * Only paid sales count, and sales in the trash do not (toBase() applies the soft delete scope).
 * A stretch is [from, to) in UTC 'Y-m-d H:i:s' strings, compared with sellings.date (indexed).
 */
class SalesSummaryService
{
    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * @return array{omzet: float, hpp: float, laba: float, margin: ?float, transactions: int, discount: float, average: float}
     */
    public function summarize(string $fromUtc, string $toUtc, ?int $userId = null): array
    {
        $row = Selling::query()
            ->isPaid()
            ->where('date', '>=', $fromUtc)
            ->where('date', '<', $toUtc)
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->toBase()
            ->selectRaw(
                'COUNT(*) as transactions, '
                .'COALESCE(SUM(total_price), 0) as total_price, '
                .'COALESCE(SUM(tax_price), 0) as tax_price, '
                .'COALESCE(SUM(total_discount_per_item), 0) as discount_per_item, '
                .'COALESCE(SUM(discount_price), 0) as discount_price, '
                .'COALESCE(SUM(total_cost), 0) as total_cost'
            )
            ->first();

        $discount = (float) $row->discount_per_item + (float) $row->discount_price;
        $omzet = round((float) $row->total_price - (float) $row->tax_price - $discount, 2);
        $hpp = round((float) $row->total_cost, 2);
        $transactions = (int) $row->transactions;

        return [
            'omzet' => $omzet,
            'hpp' => $hpp,
            'laba' => round($omzet - $hpp, 2),
            'margin' => $omzet > 0 ? round(($omzet - $hpp) / $omzet * 100, 1) : null,
            'transactions' => $transactions,
            'discount' => round($discount, 2),
            'average' => $transactions > 0 ? round($omzet / $transactions, 2) : 0.0,
        ];
    }

    /**
     * A stretch against the one right before it, of the same length.
     *
     * While the stretch is still running (it ends in the future) the one before is cut at the same point, so
     * "today until 19:42" is compared with "yesterday until 19:42" and not with the whole of yesterday. Without
     * that, every morning looks like a collapse.
     *
     * @return array{current: array<string, mixed>, previous: array<string, mixed>, partial: bool, days: int}
     */
    public function compare(string $fromUtc, string $toUtc, ?CarbonInterface $now = null, ?int $userId = null): array
    {
        $now = ($now ? Carbon::instance($now) : Carbon::now())->utc();
        $from = Carbon::parse($fromUtc, 'UTC')->getTimestamp();
        $to = Carbon::parse($toUtc, 'UTC')->getTimestamp();

        $span = max(0, $to - $from);
        $elapsed = max(0, min($to, $now->getTimestamp()) - $from);
        $previousFrom = $from - $span;
        $previousTo = $previousFrom + $elapsed;

        return [
            'current' => $this->summarize($fromUtc, $toUtc, $userId),
            'previous' => $this->summarize(
                Carbon::createFromTimestampUTC($previousFrom)->format(self::FORMAT),
                Carbon::createFromTimestampUTC($previousTo)->format(self::FORMAT),
                $userId,
            ),
            'partial' => $now->getTimestamp() < $to,
            'days' => max(1, (int) round($span / 86400)),
        ];
    }

    /**
     * Products sold in the stretch with no cost price on the sale (cost 0). Their profit is shown too high, so the
     * cards warn about them. This is a join: the soft delete scope of Selling does not apply, so trashed sales are
     * left out by hand.
     *
     * @return array{count: int, names: list<string>}
     */
    public function productsWithoutCost(string $fromUtc, string $toUtc, ?int $userId = null, int $limit = 3): array
    {
        $names = SellingDetail::query()
            ->join('sellings', 'sellings.id', '=', 'selling_details.selling_id')
            ->join('products', 'products.id', '=', 'selling_details.product_id')
            ->where('sellings.is_paid', true)
            ->whereNull('sellings.deleted_at')
            ->where('sellings.date', '>=', $fromUtc)
            ->where('sellings.date', '<', $toUtc)
            ->when($userId, fn ($query) => $query->where('sellings.user_id', $userId))
            ->where(fn ($query) => $query->whereNull('selling_details.cost')->orWhere('selling_details.cost', 0))
            ->groupBy('products.id', 'products.name')
            ->orderBy('products.name')
            ->pluck('products.name')
            ->all();

        return ['count' => count($names), 'names' => array_slice($names, 0, $limit)];
    }

    /**
     * Paid sales of the stretch per hour of the business day (0 to 23, in the shop timezone).
     *
     * @return array<int, array{transactions: int, omzet: float}> always 24 entries, keyed by hour
     */
    public function busyHours(string $fromUtc, string $toUtc): array
    {
        $hours = array_fill(0, 24, ['transactions' => 0, 'omzet' => 0.0]);
        $timezone = new \DateTimeZone(BusinessDay::timezone());

        $rows = Selling::query()
            ->isPaid()
            ->where('date', '>=', $fromUtc)
            ->where('date', '<', $toUtc)
            ->toBase()
            ->select(['date', 'total_price', 'tax_price', 'total_discount_per_item', 'discount_price'])
            ->cursor();

        foreach ($rows as $row) {
            $hour = (int) (new \DateTimeImmutable((string) $row->date, new \DateTimeZone('UTC')))->setTimezone($timezone)->format('G');
            $hours[$hour]['transactions']++;
            $hours[$hour]['omzet'] += (float) $row->total_price - (float) $row->tax_price
                - (float) $row->total_discount_per_item - (float) $row->discount_price;
        }

        return $hours;
    }

    /**
     * Products sold in the stretch with what they earned: net sales of their lines (price - line discount), the cost
     * stored on the lines, and so the profit. The sale-wide discount and tax are not shared out over the lines.
     * The id column is the product id, so a Filament table can key its rows on it. This is a join: trashed sales are
     * left out by hand.
     *
     * @return Builder<SellingDetail>
     */
    public function profitPerProductQuery(string $fromUtc, string $toUtc): Builder
    {
        return SellingDetail::query()
            ->join('sellings', 'sellings.id', '=', 'selling_details.selling_id')
            ->join('products', 'products.id', '=', 'selling_details.product_id')
            ->where('sellings.is_paid', true)
            ->whereNull('sellings.deleted_at')
            ->where('sellings.date', '>=', $fromUtc)
            ->where('sellings.date', '<', $toUtc)
            ->groupBy('selling_details.product_id', 'products.name')
            ->select([
                'selling_details.product_id as id',
                'products.name as product_name',
                DB::raw('SUM(selling_details.qty) as total_qty'),
                DB::raw('SUM(selling_details.price - selling_details.discount_price) as omzet'),
                DB::raw('SUM(COALESCE(selling_details.cost, 0)) as cost'),
                DB::raw('SUM(selling_details.price - selling_details.discount_price - COALESCE(selling_details.cost, 0)) as profit'),
            ])
            ->orderByDesc('profit')
            ->orderBy('products.name');
    }
}
