<?php

namespace App\Filament\Tenant\Resources\SellingResource\Widgets;

use App\Filament\Tenant\Widgets\Concerns\BuildsSalesCard;
use App\Filament\Tenant\Widgets\Concerns\FormatsMoney;
use App\Filament\Tenant\Widgets\Concerns\PicksBusinessDay;
use App\Services\Tenants\SalesSummaryService;
use App\Support\BusinessDay;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Today's net sales, cost of goods (HPP) and profit as one compact card, with the number of sales and the discount.
 *
 * "Today" is the business day (config pos.business_timezone, see BusinessDay) and the figures come from
 * SalesSummaryService, the same definitions as the period cards and the chart. SellingListOverview narrows the
 * card down to the filters of the sales list.
 */
class SellingOverview extends Widget
{
    use BuildsSalesCard, FormatsMoney, PicksBusinessDay;

    protected static string $view = 'filament.tenant.widgets.sales-card';

    // The figures are cheap and sit at the top of the page: no placeholder that jumps when it loads.
    protected static bool $isLazy = false;

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
        $card = $this->card();

        return ['card' => $card, 'picker' => $card && $this->hasDayPicker() ? $this->picker() : null];
    }

    protected function hasDayPicker(): bool
    {
        return true;
    }

    public function previousDay(): void
    {
        $this->day = Carbon::parse($this->pickedDay(), 'UTC')->subDay()->format('Y-m-d');
        $this->announceDay();
    }

    public function nextDay(): void
    {
        $this->day = Carbon::parse($this->pickedDay(), 'UTC')->addDay()->format('Y-m-d');
        $this->announceDay();
    }

    public function today(): void
    {
        $this->day = null;
        $this->announceDay();
    }

    /** The date field sets $day itself; the chart of that day (DayHours) is told through an event. */
    public function updatedDay(): void
    {
        $this->announceDay();
    }

    private function announceDay(): void
    {
        $this->dispatch('day-picked', day: $this->pickedDay() === BusinessDay::today() ? null : $this->pickedDay());
    }

    /**
     * @return array{day: string, max: string, is_today: bool}
     */
    protected function picker(): array
    {
        $today = BusinessDay::today();
        $day = $this->pickedDay();

        return ['day' => $day, 'max' => $today, 'is_today' => $day === $today];
    }

    /**
     * What the card adds up: [from, to) in UTC, the cashier, and the texts above the numbers.
     *
     * @return array{from: string, to: string, user_id: ?int, title: string, date: string, meta: ?string}
     */
    protected function scope(): array
    {
        $day = $this->pickedDay();
        [$start, $end] = BusinessDay::range($day);

        return [
            'from' => $start->format('Y-m-d H:i:s'),
            'to' => $end->format('Y-m-d H:i:s'),
            'user_id' => null,
            'title' => $day === BusinessDay::today() ? __('Today') : $start->copy()->setTimezone(BusinessDay::timezone())->translatedFormat('l'),
            'date' => $start->copy()->setTimezone(BusinessDay::timezone())->translatedFormat('D, j M Y'),
            'meta' => null,
        ];
    }

    /**
     * @return ?array<string, mixed> null hides the card
     */
    protected function card(): ?array
    {
        $scope = $this->scope();
        $service = app(SalesSummaryService::class);
        $compare = $service->compare($scope['from'], $scope['to'], null, $scope['user_id']);

        $vs = match (true) {
            $compare['days'] === 1 && $compare['partial'] => __('compared to the same time yesterday'),
            $compare['days'] === 1 => __('compared to the day before'),
            default => __('compared to the :days days before', ['days' => $compare['days']]),
        };

        return $this->salesCard('today', $compare['current'], $compare['previous'], [
            'title' => $scope['title'],
            'date' => $scope['date'],
            'as_of' => $compare['partial'] && $compare['days'] === 1
                ? __('as of :time', ['time' => Carbon::now(BusinessDay::timezone())->format('H.i')])
                : null,
            'vs' => $vs,
            'meta' => $scope['meta'],
            'warning' => can('read revenue overview')
                ? $service->productsWithoutCost($scope['from'], $scope['to'], $scope['user_id'])
                : null,
        ]);
    }
}
