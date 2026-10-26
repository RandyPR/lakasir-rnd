<?php

namespace App\Services\Tenants;

use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Support\BusinessDay;
use App\Support\SalesPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales per day or per month for the dashboard chart, with the period right before it for comparison.
 *
 * Days and months follow the business timezone (config pos.business_timezone, see BusinessDay), so a sale at
 * 23:30 WIB lands on its own WIB day although it is stored in UTC. The rows are bucketed in PHP on purpose: the
 * database only does a range scan on sellings.date (indexed), so the same code gives the same numbers on
 * SQLite, MySQL and MariaDB without CONVERT_TZ, time zone tables or driver specific date functions.
 *
 * Revenue is the same figure as Selling::grand_total_price and the sales report ("total net"): the price
 * without tax, minus the discount per item and the discount of the whole sale. Profit is revenue minus cost.
 * Only paid sales count, like the sales list.
 */
class SalesTrendService
{
    /**
     * @return array{
     *     period: string,
     *     labels: list<string>,
     *     revenue: list<float>,
     *     profit: list<float>,
     *     transactions: list<int>,
     *     totals: array{revenue: float, profit: float, transactions: int, average: float},
     *     previous: array{revenue: float, profit: float, transactions: int, average: float},
     * }
     */
    public function trend(SalesPeriod $period, ?CarbonInterface $now = null): array
    {
        $localNow = $this->localNow($now);
        $length = $period->length();

        // 2 * length points (the period before, then the period itself) need 2 * length + 1 boundaries.
        $starts = [];
        for ($offset = -(2 * $length - 1); $offset <= 1; $offset++) {
            $starts[] = $period->pointStart($localNow, $offset);
        }
        $boundaries = array_map(fn (Carbon $start): string => $start->copy()->utc()->format('Y-m-d H:i:s'), $starts);

        $buckets = array_fill(0, 2 * $length, ['revenue' => 0.0, 'profit' => 0.0, 'transactions' => 0]);

        $rows = Selling::query()
            ->isPaid()
            ->where('date', '>=', $boundaries[0])
            ->where('date', '<', $boundaries[2 * $length])
            ->toBase()
            ->select(['date', 'total_price', 'tax_price', 'total_discount_per_item', 'discount_price', 'total_cost'])
            ->cursor();

        foreach ($rows as $row) {
            $index = $this->bucketOf($boundaries, (string) $row->date);
            if ($index === null) {
                continue;
            }

            $revenue = (float) $row->total_price
                - (float) $row->tax_price
                - (float) $row->total_discount_per_item
                - (float) $row->discount_price;

            $buckets[$index]['revenue'] += $revenue;
            $buckets[$index]['profit'] += $revenue - (float) $row->total_cost;
            $buckets[$index]['transactions']++;
        }

        $previous = array_slice($buckets, 0, $length);
        $current = array_slice($buckets, $length);

        return [
            'period' => $period->value,
            'labels' => array_map(
                fn (Carbon $start): string => $period->pointLabel($start),
                array_slice($starts, $length, $length)
            ),
            'revenue' => array_map(fn (array $bucket): float => round($bucket['revenue'], 2), $current),
            'profit' => array_map(fn (array $bucket): float => round($bucket['profit'], 2), $current),
            'transactions' => array_map(fn (array $bucket): int => $bucket['transactions'], $current),
            'totals' => $this->totals($current),
            'previous' => $this->totals($previous),
        ];
    }

    /**
     * The best selling products of the period (same window as trend(), without the period before it).
     *
     * The id column is the product id, so Filament can key the rows of a table widget on it.
     * This is a join, so the soft delete scope of Selling does not apply: sales in the trash are left out by hand.
     *
     * @return Builder<SellingDetail>
     */
    public function bestSellingQuery(SalesPeriod $period, ?CarbonInterface $now = null): Builder
    {
        [$start, $end] = $this->currentRange($period, $now);

        return SellingDetail::query()
            ->join('sellings', 'sellings.id', '=', 'selling_details.selling_id')
            ->join('products', 'products.id', '=', 'selling_details.product_id')
            ->where('sellings.is_paid', true)
            ->whereNull('sellings.deleted_at')
            ->where('sellings.date', '>=', $start)
            ->where('sellings.date', '<', $end)
            ->groupBy('selling_details.product_id', 'products.name')
            ->select([
                'selling_details.product_id as id',
                'products.name as product_name',
                DB::raw('SUM(selling_details.qty) as total_qty'),
            ])
            ->orderByDesc('total_qty')
            ->orderBy('products.name');
    }

    /**
     * [start, end) of the period's current window as UTC 'Y-m-d H:i:s' strings.
     *
     * @return array{0: string, 1: string}
     */
    public function currentRange(SalesPeriod $period, ?CarbonInterface $now = null): array
    {
        $localNow = $this->localNow($now);

        return [
            $period->pointStart($localNow, -($period->length() - 1))->utc()->format('Y-m-d H:i:s'),
            $period->pointStart($localNow, 1)->utc()->format('Y-m-d H:i:s'),
        ];
    }

    private function localNow(?CarbonInterface $now): Carbon
    {
        return ($now ? Carbon::instance($now) : Carbon::now())->setTimezone(BusinessDay::timezone());
    }

    /**
     * Index of the bucket [boundaries[i], boundaries[i + 1]) that holds $date, or null outside all of them.
     *
     * @param  list<string>  $boundaries  ascending 'Y-m-d H:i:s'
     */
    private function bucketOf(array $boundaries, string $date): ?int
    {
        $low = 0;
        $high = count($boundaries) - 2;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if (strcmp($date, $boundaries[$middle]) < 0) {
                $high = $middle - 1;
            } elseif (strcmp($date, $boundaries[$middle + 1]) >= 0) {
                $low = $middle + 1;
            } else {
                return $middle;
            }
        }

        return null;
    }

    /**
     * @param  list<array{revenue: float, profit: float, transactions: int}>  $buckets
     * @return array{revenue: float, profit: float, transactions: int, average: float}
     */
    private function totals(array $buckets): array
    {
        $revenue = round(array_sum(array_column($buckets, 'revenue')), 2);
        $transactions = (int) array_sum(array_column($buckets, 'transactions'));

        return [
            'revenue' => $revenue,
            'profit' => round(array_sum(array_column($buckets, 'profit')), 2),
            'transactions' => $transactions,
            'average' => $transactions > 0 ? round($revenue / $transactions, 2) : 0.0,
        ];
    }
}
