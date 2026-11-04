<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Widgets\Concerns\UsesSalesPeriod;
use App\Services\Tenants\SalesSummaryService;
use App\Services\Tenants\SalesTrendService;
use App\Support\HourRange;
use Filament\Widgets\Widget;

/**
 * Number of sales per day (per month for the year) and hour, as a grid whose cells get darker the busier they are,
 * over the period chosen on the dashboard. It shows whether the busy hours move from day to day or week to week.
 * Hours are shop time (WIB); only the hours around the sales are drawn.
 */
class BusyHoursHeatmap extends Widget
{
    use UsesSalesPeriod;

    protected static string $view = 'filament.tenant.widgets.busy-hours-heatmap';

    protected static ?int $sort = 17;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return can('read sales overview') || can('read revenue overview');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $period = $this->period();
        $trend = app(SalesTrendService::class);
        [$from, $to] = $trend->currentRange($period);
        $grid = app(SalesSummaryService::class)->salesByDayAndHour($from, $to, $period->isMonthly());

        $perHour = array_fill(0, 24, 0);
        foreach ($grid as $hours) {
            foreach ($hours as $hour => $count) {
                $perHour[$hour] += $count;
            }
        }

        $hours = HourRange::around($perHour);
        $max = max(1, ...array_values(array_map(fn (array $row): int => max($row), $grid ?: [[0]])));
        $rows = [];

        foreach ($trend->points($period) as $point) {
            $counts = $grid[$point['key']] ?? array_fill(0, 24, 0);
            $rows[] = [
                'label' => $point['label'],
                'total' => array_sum($counts),
                'cells' => array_map(fn (int $hour): array => ['count' => $counts[$hour], 'level' => $counts[$hour] / $max], $hours),
            ];
        }

        return [
            'hours' => $hours,
            'rows' => $rows,
            'max' => $max,
            'description' => $period->description(),
            'empty' => $hours === [],
            'unit' => $period->isMonthly() ? __('Sales in the month') : __('Sales'),
        ];
    }
}
