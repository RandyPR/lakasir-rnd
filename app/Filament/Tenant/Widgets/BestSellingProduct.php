<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Widgets\Concerns\UsesSalesPeriod;
use App\Services\Tenants\SalesTrendService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * The five best selling products of the period chosen on the dashboard (by quantity).
 */
class BestSellingProduct extends BaseWidget
{
    use UsesSalesPeriod;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Best selling products').' · '.$this->period()->description())
            ->query(app(SalesTrendService::class)->bestSellingQuery($this->period())->limit(5))
            ->columns([
                TextColumn::make('product_name')
                    ->label(__('Product')),
                TextColumn::make('total_qty')
                    ->label(__('Qty'))
                    ->numeric()
                    ->alignEnd(),
            ])
            ->emptyStateHeading(__('No sales in this period'))
            ->paginated(false);
    }
}
