<?php

namespace App\Filament\Tenant\Resources\SellingResource\Pages;

use App\Filament\Tenant\Resources\SellingResource;
use App\Filament\Tenant\Resources\SellingResource\SellingTrashActions;
use App\Models\Tenants\Selling;
use Filament\Forms\Form;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListSellings extends ListRecords
{
    protected static string $resource = SellingResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            SellingResource\Widgets\SellingOverview::class,
        ];
    }

    protected function configureEditAction(Tables\Actions\EditAction $action): void
    {
        $resource = static::getResource();

        $action
            ->authorize(fn (Model $record): bool => $resource::canEdit($record))
            ->form(fn (Form $form): Form => $this->form($form->columns(2)));

        if ($resource::hasPage('view')) {
            $action->url(fn (Model $record): string => $resource::getUrl('view', ['record' => $record]));
        }
    }

    /**
     * Sales and the Trash tab. The tab is only offered to people who may restore, and the list below ignores
     * ?activeTab=trash for everyone else, so the trash never shows up by accident.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        if (! SellingTrashActions::allowed()) {
            return [];
        }

        $trashed = Selling::onlyTrashed()->isPaid()->count();

        return [
            'selling' => Tab::make(__('Selling History')),
            'trash' => Tab::make(__('Trash'))
                ->icon('heroicon-o-trash')
                ->badge($trashed > 0 ? $trashed : null),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'selling';
    }

    protected function getTableQuery(): ?Builder
    {
        $query = $this->getResource()::getEloquentQuery()->isPaid();

        return SellingResource::showsTrash($this) ? $query->onlyTrashed() : $query->withoutTrashed();
    }
}
