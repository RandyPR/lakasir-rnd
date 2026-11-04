<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Widgets\Concerns\UsesSalesPeriod;
use App\Services\Tenants\SalesSummaryService;
use App\Services\Tenants\SalesTrendService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The number of sales per hour of the day over the period chosen on the dashboard, with the busiest hour named.
 * The hours are shop time (WIB). Only hours around the sales are drawn, so a shop open 17.00 to 23.00 does not get
 * a chart that is mostly empty.
 */
class BusyHours extends ChartWidget
{
    use UsesSalesPeriod;

    private const COLOR = '#d95926';

    protected static ?int $sort = 15;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return can('read sales overview') || can('read revenue overview');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Busy hours');
    }

    public function getDescription(): string|Htmlable|null
    {
        $hours = $this->hours();
        $total = array_sum(array_column($hours, 'transactions'));

        if ($total === 0) {
            return $this->period()->description();
        }

        $peak = collect($hours)->sortByDesc('transactions')->keys()->first();
        $share = round($hours[$peak]['transactions'] / $total * 100);

        return __('Busiest :from–:to, :share% of sales', [
            'from' => sprintf('%02d.00', $peak),
            'to' => sprintf('%02d.00', ($peak + 1) % 24),
            'share' => $share,
        ]).' · '.$this->period()->description();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $hours = $this->hours();
        $active = array_keys(array_filter($hours, fn (array $hour): bool => $hour['transactions'] > 0));

        if ($active === []) {
            return ['labels' => [], 'datasets' => [['data' => []]]];
        }

        // One hour of air around the sales, at least eight hours wide.
        $from = max(0, min($active) - 1);
        $to = min(23, max($active) + 1);
        while ($to - $from < 7) {
            if ($from > 0) {
                $from--;
            }
            if ($to - $from < 7 && $to < 23) {
                $to++;
            }
        }
        $range = range($from, $to);
        $showMoney = can('read revenue overview');
        $peak = collect($hours)->sortByDesc('transactions')->keys()->first();

        return [
            'labels' => array_map(fn (int $hour): string => sprintf('%02d', $hour), $range),
            'datasets' => [[
                'label' => __('Sales'),
                'data' => array_map(fn (int $hour): int => $hours[$hour]['transactions'], $range),
                'formatted' => array_map(fn (int $hour): string => $showMoney ? $this->money($hours[$hour]['omzet']) : '', $range),
                'backgroundColor' => array_map(fn (int $hour): string => $hour === $peak ? self::COLOR : self::COLOR.'66', $range),
                'borderWidth' => 0,
                'borderRadius' => 4,
                'maxBarThickness' => 28,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items) => items[0].label + '.00',
                        afterLabel: (item) => item.dataset.formatted[item.dataIndex] || '',
                    },
                },
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 } },
            },
        }
        JS);
    }

    /**
     * @return array<int, array{transactions: int, omzet: float}>
     */
    private function hours(): array
    {
        [$from, $to] = app(SalesTrendService::class)->currentRange($this->period());

        return $this->hoursCache ??= app(SalesSummaryService::class)->busyHours($from, $to);
    }

    /** @var ?array<int, array{transactions: int, omzet: float}> */
    private ?array $hoursCache = null;
}
