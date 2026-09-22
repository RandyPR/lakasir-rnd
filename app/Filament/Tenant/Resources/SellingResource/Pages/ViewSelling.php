<?php

namespace App\Filament\Tenant\Resources\SellingResource\Pages;

use App\Features\PrintSellingA5;
use App\Filament\Tenant\Resources\SellingDetailResource\RelationManagers\SellingDetailsRelationManager;
use App\Filament\Tenant\Resources\SellingResource;
use App\Models\Tenants\About;
use App\Models\Tenants\Selling;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Colors\Color;
use Illuminate\Contracts\Support\Htmlable;

class ViewSelling extends ViewRecord
{
    protected static string $resource = SellingResource::class;

    public ?About $about = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->loadMissing('sellingDetails.product', 'paymentMethod', 'user', 'member', 'table');
        $this->about = About::first();
    }

    public function getTitle(): string|Htmlable
    {
        return 'View '.$this->getRecord()->code;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printInvoice')
                ->label(__('Print invoice'))
                ->icon('heroicon-s-printer')
                ->color(Color::Teal)
                ->extraAttributes([
                    'id' => 'printInvoice',
                    'type' => 'button',
                    'onclick' => 'window.handlePrintInvoice && window.handlePrintInvoice()',
                ])
                ->visible(can('can print selling') && feature(PrintSellingA5::class)),
            Action::make('printReceipt')
                ->label(__('Print receipt'))
                ->icon('heroicon-s-printer')
                ->extraAttributes([
                    'id' => 'printButton',
                    'type' => 'button',
                    'onclick' => 'window.handlePrintReceipt && window.handlePrintReceipt()',
                ])
                ->visible(can('can print selling')),
        ];
    }

    public function getView(): string
    {
        return 'filament.tenant.resources.sellings.pages.view-selling';
    }

    public function getRecord(): Selling
    {
        return $this->record->load('sellingDetails.product', 'paymentMethod', 'user', 'member', 'table');
    }

    public function getRelationManagers(): array
    {
        return [
            SellingDetailsRelationManager::make(),
        ];
    }
}
