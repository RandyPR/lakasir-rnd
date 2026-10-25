<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Widgets\Concerns\UsesSalesPeriod;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Totals of the period chosen on the dashboard, each against the period right before it.
 * Revenue, profit and the average sale are money (permission "read revenue overview"); the number of sales
 * follows "read sales overview", the same split as Today total revenue / Sales today.
 */
class SalesPeriodOverview extends BaseWidget
{
    use UsesSalesPeriod;

    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return can('read revenue overview') || can('read sales overview');
    }

    /**
     * Two cards a row: a full rupiah amount in a card a quarter of the page wide runs out of its card.
     */
    protected function getColumns(): int
    {
        return 2;
    }

    protected function getHeading(): ?string
    {
        return $this->period()->description();
    }

    protected function getStats(): array
    {
        $trend = $this->trend();
        $totals = $trend['totals'];
        $previous = $trend['previous'];
        $stats = [];

        if (can('read revenue overview')) {
            $stats[] = $this->stat(__('Net sales'), $this->money($totals['revenue']), $totals['revenue'], $previous['revenue'], $trend['revenue']);
            $stats[] = $this->stat(__('Profit'), $this->money($totals['profit']), $totals['profit'], $previous['profit'], $trend['profit']);
        }

        if (can('read sales overview')) {
            $stats[] = $this->stat(__('Sales'), Number::format($totals['transactions'], locale: app()->getLocale()), $totals['transactions'], $previous['transactions'], $trend['transactions']);
        }

        if (can('read revenue overview')) {
            $stats[] = $this->stat(__('Average per sale'), $this->money($totals['average']), $totals['average'], $previous['average']);
        }

        return $stats;
    }

    /**
     * @param  list<int|float>|null  $series
     */
    private function stat(string $label, string $value, float|int $current, float|int $previous, ?array $series = null): Stat
    {
        $stat = Stat::make($label, $value);

        if ($series !== null) {
            $stat->chart($series);
        }

        if ($previous == 0 && $current == 0) {
            return $stat->description(__('No sales in :period', ['period' => $this->period()->previousDescription()]))
                ->color('gray');
        }

        if ($previous == 0) {
            // A percentage of nothing says nothing.
            return $stat->description(__('No sales in :period', ['period' => $this->period()->previousDescription()]))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success');
        }

        $percent = (int) round(abs($current - $previous) / abs($previous) * 100);

        [$trend, $icon, $color] = match (true) {
            $current > $previous => [__('increase'), 'heroicon-m-arrow-trending-up', 'success'],
            $current < $previous => [__('decrease'), 'heroicon-m-arrow-trending-down', 'danger'],
            default => [__('sideway'), 'heroicon-m-minus', 'warning'],
        };

        return $stat
            ->description(__(':percent% :trend compared to :period', [
                'percent' => $percent,
                'trend' => $trend,
                'period' => $this->period()->previousDescription(),
            ]))
            ->descriptionIcon($icon)
            ->color($color);
    }
}
