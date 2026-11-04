<?php

namespace App\Filament\Tenant\Widgets\Concerns;

use App\Services\Tenants\SalesTrendService;
use App\Support\SalesPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * For the dashboard widgets that follow the "period" filter of the dashboard page.
 */
trait UsesSalesPeriod
{
    use FormatsMoney, InteractsWithPageFilters;

    /** @var array<string, array<string, mixed>> */
    protected array $trendCache = [];

    protected function period(): SalesPeriod
    {
        return SalesPeriod::fromFilter($this->filters['period'] ?? null);
    }

    /**
     * @return array<string, mixed> see SalesTrendService::trend()
     */
    protected function trend(): array
    {
        $period = $this->period();

        return $this->trendCache[$period->value] ??= app(SalesTrendService::class)->trend($period);
    }
}
