<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Tenant\Resources\SellingResource\Widgets\SellingOverview;
use App\Filament\Tenant\Widgets\BestSellingProduct;
use App\Filament\Tenant\Widgets\ExpiredProduct;
use App\Filament\Tenant\Widgets\SalesChart;
use App\Filament\Tenant\Widgets\SalesPeriodOverview;
use App\Support\SalesPeriod;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            ToggleButtons::make('period')
                ->label(__('Period'))
                ->options(SalesPeriod::options())
                ->default(SalesPeriod::Week->value)
                ->inline()
                ->grouped(),
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            SellingOverview::class,
        ];
    }

    public function getWidgets(): array
    {
        // The page prints its filters between the header widgets and these, so everything that follows the period
        // filter belongs here and what does not (today's numbers) stays in the header.
        return [
            SalesPeriodOverview::class,
            SalesChart::class,
            BestSellingProduct::class,
            ExpiredProduct::class,
        ];
    }
}
