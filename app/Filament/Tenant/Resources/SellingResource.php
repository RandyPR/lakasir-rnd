<?php

namespace App\Filament\Tenant\Resources;

use App\Features\ProductInitialPrice;
use App\Filament\Tenant\Resources\SellingResource\Pages;
use App\Filament\Tenant\Resources\SellingResource\SellingTrashActions;
use App\Models\Tenants\Profile;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use App\Traits\HasTranslatableResource;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;

class SellingResource extends Resource
{
    use HasTranslatableResource;

    protected static ?string $model = Selling::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Selling History';

    public static function getBreadcrumb(): string
    {
        return __('Selling History');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('daily_order_number')
                    ->label(__('Order #'))
                    ->formatStateUsing(fn ($state) => $state ? 'Order #' . str_pad((string) $state, 3, '0', STR_PAD_LEFT) : '-')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('Cashier'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $query) use ($search) {
                            return $query
                                ->where('email', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('customer_name')
                    ->label(__('Customer'))
                    ->state(function (Selling $record): string {
                        if ($record->member?->name) {
                            return $record->member->name . ' (Member)';
                        }

                        return $record->customer_name ?: '-';
                    })
                    ->description(fn (Selling $record): ?string => $record->customer_number ?: null)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search) {
                            $query->where('customer_name', 'like', "%{$search}%")
                                ->orWhere('customer_number', 'like', "%{$search}%")
                                ->orWhereHas('member', function (Builder $query) use ($search) {
                                    $query->where('name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%")
                                        ->orWhere('code', 'like', "%{$search}%");
                                });
                        });
                    }),
                TextColumn::make('date')
                    ->dateTime(timezone: Profile::get()->timezone)
                    ->translateLabel(),
                TextColumn::make('grand_total_price')
                    ->translateLabel()
                    ->sortable()
                    ->money(Setting::get('currency', 'IDR')),
                TextColumn::make('total_price')
                    ->translateLabel()
                    ->sortable()
                    ->money(Setting::get('currency', 'IDR')),
                TextColumn::make('tax_price')
                    ->translateLabel()
                    ->sortable()
                    ->visible(feature(ProductInitialPrice::class))
                    ->money(Setting::get('currency', 'IDR')),
                TextColumn::make('total_cost')
                    ->translateLabel()
                    ->sortable()
                    ->visible(feature(ProductInitialPrice::class))
                    ->money(Setting::get('currency', 'IDR')),
                TextColumn::make('deleted_at')
                    ->label(__('Moved to trash'))
                    ->dateTime(timezone: Profile::get()->timezone)
                    ->visible(fn ($livewire): bool => static::showsTrash($livewire)),
                TextColumn::make('deletedBy.name')
                    ->label(__('Moved by'))
                    ->placeholder('-')
                    ->visible(fn ($livewire): bool => static::showsTrash($livewire)),
                TextColumn::make('delete_reason')
                    ->label(__('Reason'))
                    ->wrap()
                    ->placeholder('-')
                    ->visible(fn ($livewire): bool => static::showsTrash($livewire)),
            ])
            ->searchPlaceholder('Search (Code, User, Customer, Customer Number)')
            ->header(view('filament.tenant.resources.sellings.headers.overview', [
                'start_date' => request()->input('tableFilters.date.start_date'),
                'end_date' => request()->input('tableFilters.date.end_date'),
            ]))
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('Cashier'))
                    ->options(User::all()->mapWithKeys(fn (User $user) => [$user->id => $user->cashier_name])),
                Filter::make('date')
                    ->form([
                        DatePicker::make('start_date')
                            ->native(false)
                            ->format('Y-m-d')
                            ->timezone(Profile::get()->timezone)
                            ->date()
                            ->closeOnDateSelection(),
                        DatePicker::make('end_date')
                            ->native(false)
                            ->format('Y-m-d')
                            ->timezone(Profile::get()->timezone)
                            ->date()
                            ->closeOnDateSelection(),
                    ])
                    ->indicateUsing(function (array $data): ?string {
                        if (! ($data['start_date'] && $data['end_date'])) {
                            return null;
                        }

                        return Carbon::parse($data['start_date'])->toFormattedDateString().' s/d '.Carbon::parse($data['end_date'])->toFormattedDateString();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $startDate = $data['start_date'];
                        $endDate = $data['end_date'];
                        if ($timezone = Profile::get()->timezone) {
                            if (! $startDate && ! $endDate) {
                                return $query;
                            }
                            $startDate = Carbon::parse($startDate, $timezone)->setTimezone('UTC');
                            $endDate = Carbon::parse($endDate, $timezone)->addDay()->setTimezone('UTC');
                        }

                        return $query
                            ->when($startDate && $endDate, fn (Builder $builder) => $builder->whereBetween('date', [$startDate, $endDate]));
                    }),
            ])
            ->deferFilters()
            ->bulkActions([
                BulkAction::make('trash')
                    ->label(__('Move to trash'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Move to trash'))
                    ->modalDescription(__('The stock of the items is returned. You can restore the sales from the Trash tab.'))
                    ->form(SellingTrashActions::reasonForm())
                    ->visible(fn ($livewire): bool => ! static::showsTrash($livewire) && SellingTrashActions::allowed())
                    ->action(fn (Collection $records, array $data) => SellingTrashActions::trash($records->modelKeys(), $data))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('restore')
                    ->label(__('Restore from trash'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->modalHeading(__('Restore from trash'))
                    ->modalDescription(__('Restoring takes the stock of the items out again.'))
                    ->visible(fn ($livewire): bool => static::showsTrash($livewire) && SellingTrashActions::allowed())
                    ->action(fn (Collection $records) => SellingTrashActions::restore($records->modelKeys()))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /**
     * Whether the list is on its Trash tab (only people who may restore see that tab).
     */
    public static function showsTrash(mixed $livewire): bool
    {
        return ($livewire->activeTab ?? null) === 'trash' && SellingTrashActions::allowed();
    }

    /**
     * The scope that hides trashed sales is lifted here so a trashed sale can still be opened from the Trash tab.
     * ListSellings puts it back (withoutTrashed) on every list.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['member', 'user']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSellings::route('/'),
            'view' => Pages\ViewSelling::route('/{record}'),
        ];
    }
}
