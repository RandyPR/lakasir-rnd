<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Widgets\Concerns\BuildsSalesCard;
use App\Filament\Tenant\Widgets\Concerns\UsesSalesPeriod;
use Filament\Widgets\Widget;

/**
 * Net sales, cost of goods (HPP) and profit of the period chosen on the dashboard, as one compact card, each
 * against the period right before it, with the number of sales and the average sale.
 * Money is "read revenue overview"; the number of sales follows "read sales overview", the same split as the card
 * of today.
 */
class SalesPeriodOverview extends Widget
{
    use BuildsSalesCard, UsesSalesPeriod;

    protected static string $view = 'filament.tenant.widgets.sales-card';

    // The figures are cheap and sit at the top of the page: no placeholder that jumps when it loads.
    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return can('read revenue overview') || can('read sales overview');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['card' => $this->card()];
    }

    /**
     * @return array<string, mixed>
     */
    protected function card(): array
    {
        $trend = $this->trend();
        $period = $this->period();

        return $this->salesCard('period', $this->asSummary($trend['totals']), $this->asSummary($trend['previous']), [
            'title' => $period->description(),
            'meta' => __('compared to :period', ['period' => $period->previousDescription()]),
        ]);
    }

    /**
     * @param  array{revenue: float, cost: float, profit: float, transactions: int, average: float}  $totals
     * @return array{omzet: float, hpp: float, laba: float, margin: ?float, transactions: int, discount: ?float, average: float}
     */
    private function asSummary(array $totals): array
    {
        return [
            'omzet' => $totals['revenue'],
            'hpp' => $totals['cost'],
            'laba' => $totals['profit'],
            'margin' => $totals['revenue'] > 0 ? round($totals['profit'] / $totals['revenue'] * 100, 1) : null,
            'transactions' => $totals['transactions'],
            'discount' => null,
            'average' => $totals['average'],
        ];
    }
}
