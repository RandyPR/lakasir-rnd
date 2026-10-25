<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Widgets\Concerns\UsesSalesPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Revenue (bars) and profit (line) per day or per month, following the period filter of the dashboard.
 * Both series are rupiah on one axis; the number of sales shows in the tooltip.
 */
class SalesChart extends ChartWidget
{
    use UsesSalesPeriod;

    // Colors validated for both light and dark surfaces (orange and blue stay apart for color blind viewers).
    private const REVENUE_COLOR = '#d95926';

    private const PROFIT_COLOR = '#3987e5';

    protected static ?int $sort = 10;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return can('read revenue overview');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Sales chart');
    }

    public function getDescription(): string|Htmlable|null
    {
        return $this->period()->description();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $trend = $this->trend();
        $showCount = can('read sales overview');
        $dense = count($trend['labels']) > 14;

        $counts = $showCount ? $trend['transactions'] : null;
        $format = fn (float $value): string => $this->money($value);

        return [
            'labels' => $trend['labels'],
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => __('Net sales'),
                    'data' => $trend['revenue'],
                    'formatted' => array_map($format, $trend['revenue']),
                    'counts' => $counts,
                    'backgroundColor' => self::REVENUE_COLOR,
                    'hoverBackgroundColor' => self::REVENUE_COLOR,
                    'borderColor' => self::REVENUE_COLOR,
                    'borderWidth' => 0,
                    'borderRadius' => 4,
                    'maxBarThickness' => 36,
                    'order' => 2,
                ],
                [
                    'type' => 'line',
                    'label' => __('Profit'),
                    'data' => $trend['profit'],
                    'formatted' => array_map($format, $trend['profit']),
                    'borderColor' => self::PROFIT_COLOR,
                    'backgroundColor' => self::PROFIT_COLOR,
                    'borderWidth' => 2,
                    'pointBackgroundColor' => self::PROFIT_COLOR,
                    'pointBorderColor' => self::PROFIT_COLOR,
                    // Monotone, so the curve cannot dip below a day without sales or overshoot a peak.
                    'cubicInterpolationMode' => 'monotone',
                    // 30 daily points would be a string of beads; they appear on hover instead.
                    'pointRadius' => $dense ? 0 : 4,
                    'pointHoverRadius' => 6,
                    'pointHitRadius' => 12,
                    'order' => 1,
                ],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        $locale = $this->jsString(str_replace('_', '-', app()->getLocale()));
        $salesLabel = $this->jsString(__('Sales'));

        return RawJs::make(<<<JS
        {
            ...(window.innerWidth < 640 ? { aspectRatio: 1.3 } : {}),
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8, sort: (a, b) => a.datasetIndex - b.datasetIndex } },
                tooltip: {
                    itemSort: (a, b) => a.datasetIndex - b.datasetIndex,
                    callbacks: {
                        label: (item) => item.dataset.label + ': ' + item.dataset.formatted[item.dataIndex],
                        afterBody: (items) => {
                            const counts = items[0].chart.data.datasets[0].counts
                            return counts ? {$salesLabel} + ': ' + counts[items[0].dataIndex] : ''
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => new Intl.NumberFormat({$locale}, { notation: 'compact', maximumFractionDigits: 1 }).format(value),
                    },
                },
            },
        }
        JS);
    }

    /**
     * A single quoted JavaScript string. The options are printed inside a double quoted HTML attribute (x-data), so
     * a double quote in them would end the attribute and leave the rest of the options on the page as text.
     */
    private function jsString(string $value): string
    {
        return "'".addcslashes($value, "\\'\r\n")."'";
    }
}
