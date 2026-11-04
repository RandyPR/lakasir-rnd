<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Widgets\Concerns\PicksBusinessDay;
use App\Services\Tenants\SalesSummaryService;
use App\Support\BusinessDay;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;

/**
 * The sales of one day per hour: the day chosen with the day picker of the "Hari ini" card (the card tells this
 * chart through the day-picked event), today until a day is chosen. It does not follow the Periode toggle.
 */
class DayHours extends BusyHours
{
    use PicksBusinessDay;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '200px';

    #[On('day-picked')]
    public function setDay(?string $day): void
    {
        $this->day = $day;
        $this->hoursCache = null;
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Sales per hour');
    }

    public function getDescription(): string|Htmlable|null
    {
        $day = $this->pickedDay();
        $label = $day === BusinessDay::today()
            ? __('Today')
            : BusinessDay::range($day)[0]->copy()->setTimezone(BusinessDay::timezone())->translatedFormat('l, j M Y');

        return $this->describe($label);
    }

    protected function hours(): array
    {
        [$from, $to] = BusinessDay::range($this->pickedDay());

        return $this->hoursCache ??= app(SalesSummaryService::class)
            ->busyHours($from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'));
    }
}
