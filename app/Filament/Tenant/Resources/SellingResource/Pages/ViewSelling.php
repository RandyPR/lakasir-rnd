<?php

namespace App\Filament\Tenant\Resources\SellingResource\Pages;

use App\Features\PrintSellingA5;
use App\Filament\Tenant\Resources\SellingDetailResource\RelationManagers\SellingDetailsRelationManager;
use App\Filament\Tenant\Resources\SellingResource;
use App\Filament\Tenant\Resources\SellingResource\SellingTrashActions;
use App\Models\Tenants\About;
use App\Models\Tenants\Profile;
use App\Models\Tenants\Selling;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;

class ViewSelling extends ViewRecord
{
    protected static string $resource = SellingResource::class;

    public ?About $about = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // A sale in the trash is only for people who may restore it; everyone else gets the same 404 as an unknown id.
        abort_if($this->record->trashed() && ! SellingTrashActions::allowed(), 404);

        $this->getRecord();
        $this->about = About::first();

        // Rendered only on the full page load (not on Livewire updates): zero extra queries per request.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('filament.tenant.partials.receipt-print'),
            scopes: static::class,
        );
    }

    public function getTitle(): string|Htmlable
    {
        return 'View '.$this->getRecord()->code;
    }

    /**
     * A sale in the trash says so, since when, by whom and why.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $selling = $this->record;

        if (! $selling->trashed()) {
            return null;
        }

        $when = $selling->deleted_at->copy()->setTimezone(Profile::get()->timezone ?? 'UTC')->format('d F Y H:i');
        $text = __('In the trash since :date by :user', [
            'date' => $when,
            'user' => $selling->deletedBy?->name ?? '-',
        ]);

        return $selling->delete_reason ? $text.'. '.__('Reason').': '.$selling->delete_reason : $text;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('trash')
                ->label(__('Move to trash'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Move to trash'))
                ->modalDescription(__('The stock of the items is returned. You can restore the sale from the Trash tab.'))
                ->form(SellingTrashActions::reasonForm())
                ->visible(fn (): bool => ! $this->record->trashed() && SellingTrashActions::allowed())
                ->action(function (array $data): void {
                    SellingTrashActions::trash([$this->record->getKey()], $data);

                    $this->redirect(SellingResource::getUrl('index'));
                }),
            Action::make('restore')
                ->label(__('Restore from trash'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()
                ->modalHeading(__('Restore from trash'))
                ->modalDescription(__('Restoring takes the stock of the items out again.'))
                ->visible(fn (): bool => $this->record->trashed() && SellingTrashActions::allowed())
                ->action(function (): void {
                    $result = SellingTrashActions::restore([$this->record->getKey()]);

                    // Failed: stay here, the notification says why. Done: reload so the page is no longer "in the trash".
                    if ($result['failed'] === []) {
                        $this->redirect(SellingResource::getUrl('view', ['record' => $this->record]));
                    }
                }),
            Action::make('printInvoice')
                ->label(__('Print invoice'))
                ->icon('heroicon-s-printer')
                ->color(Color::Teal)
                ->extraAttributes([
                    'id' => 'printInvoice',
                    'type' => 'button',
                    'onclick' => 'window.handlePrintInvoice && window.handlePrintInvoice()',
                ])
                ->visible(fn (): bool => ! $this->record->trashed() && can('can print selling') && feature(PrintSellingA5::class)),
            Action::make('printReceipt')
                ->label(__('Print receipt'))
                ->icon('heroicon-s-printer')
                ->extraAttributes([
                    'id' => 'printButton',
                    'type' => 'button',
                    'onclick' => 'window.handlePrintReceipt && window.handlePrintReceipt()',
                ])
                ->visible(fn (): bool => ! $this->record->trashed() && can('can print selling')),
            // A restaurant only: the kitchen ticket of the whole sale, marked as a copy, as often as needed.
            Action::make('printKitchen')
                ->label(__('Print kitchen ticket'))
                ->icon('heroicon-s-printer')
                ->color('gray')
                ->extraAttributes([
                    'id' => 'printKitchenButton',
                    'type' => 'button',
                    'onclick' => 'window.handlePrintKitchen && window.handlePrintKitchen()',
                ])
                ->visible(fn (): bool => ! $this->record->trashed() && can('can print selling') && $this->about?->business_type === 'fnb'),
        ];
    }

    public function getView(): string
    {
        return 'filament.tenant.resources.sellings.pages.view-selling';
    }

    public function getRecord(): Selling
    {
        // Idempotent: the relations are loaded (and the lines sorted) once per request.
        return $this->record->loadForReceipt();
    }

    public function getRelationManagers(): array
    {
        return [
            SellingDetailsRelationManager::make(),
        ];
    }
}
