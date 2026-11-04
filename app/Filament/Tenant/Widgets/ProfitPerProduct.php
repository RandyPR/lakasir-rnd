<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Widgets\Concerns\UsesSalesPeriod;
use App\Services\Tenants\SalesSummaryService;
use App\Services\Tenants\SalesTrendService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * The products that earned the most in the period chosen on the dashboard. The best seller by quantity is not always
 * the one that earns most, so this sits next to the best sellers. A thin margin is marked (below LOW_MARGIN percent;
 * red when the product sold at a loss).
 */
class ProfitPerProduct extends BaseWidget
{
    use UsesSalesPeriod;

    /** Margins under this percentage are marked as low. */
    public const LOW_MARGIN = 20.0;

    protected static ?int $sort = 16;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return can('read revenue overview');
    }

    public function table(Table $table): Table
    {
        [$from, $to] = app(SalesTrendService::class)->currentRange($this->period());

        return $table
            ->heading(__('Most profitable products').' · '.$this->period()->description())
            ->query(app(SalesSummaryService::class)->profitPerProductQuery($from, $to)->limit(5))
            ->columns([
                TextColumn::make('product_name')
                    ->label(__('Product'))
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 6rem']),
                TextColumn::make('profit')
                    ->label(__('Profit'))
                    ->formatStateUsing(fn ($state): string => $this->money((float) $state))
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->alignEnd(),
                TextColumn::make('margin')
                    ->label(__('Margin'))
                    ->state(fn ($record): ?float => (float) $record->omzet > 0 ? round((float) $record->profit / (float) $record->omzet * 100, 1) : null)
                    ->formatStateUsing(fn ($state): string => $state === null ? '-' : number_format((float) $state, 1, app()->getLocale() === 'id' ? ',' : '.', '').'%')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'gray',
                        (float) $state <= 0 => 'danger',
                        (float) $state < self::LOW_MARGIN => 'warning',
                        default => 'gray',
                    })
                    ->alignEnd(),
            ])
            ->emptyStateHeading(__('No sales in this period'))
            ->paginated(false);
    }
}
