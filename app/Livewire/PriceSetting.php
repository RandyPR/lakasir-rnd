<?php

namespace App\Livewire;

use App\Filament\Tenant\Resources\Traits\RefreshThePage;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\OpenBillEvent;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\PriceUnit;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillGate;
use App\Services\Tenants\OpenBillService;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Component;

/**
 * Picks the unit price of one order line. The line is a CartItem (private cart) or an OpenBillItem (open bill);
 * every action first checks that the signed-in cashier may touch that line.
 */
class PriceSetting extends Component
{
    use RefreshThePage;

    public Model $cartItem;

    public $unit;

    /**
     * A cart line must be the signed-in cashier's own. A bill line needs the right to use bills; whether the bill
     * is still open is decided under the bill lock when the change is written.
     */
    protected function authorizeLine(): void
    {
        $user = Filament::auth()->user();

        if ($this->cartItem instanceof OpenBillItem) {
            abort_unless($user instanceof User && OpenBillGate::canUse($user), 403);

            return;
        }

        abort_unless($user && (int) $this->cartItem->user_id === (int) $user->getKey(), 403);
    }

    public function changeThePrice(): void
    {
        $this->authorizeLine();

        $unitId = filled($this->unit) ? (int) $this->unit : null;

        // Only a unit price of this very product can be chosen.
        if ($unitId !== null && ! PriceUnit::query()
            ->whereKey($unitId)
            ->where('product_id', $this->cartItem->product_id)
            ->exists()) {
            return;
        }

        $this->applyUnit($unitId);
    }

    public function removeThePrice(): void
    {
        $this->authorizeLine();

        $this->applyUnit(null);
    }

    private function applyUnit(?int $unitId): void
    {
        if ($this->cartItem instanceof OpenBillItem) {
            $this->applyUnitOnBill($unitId);
        } else {
            $this->cartItem->update([
                'price_unit_id' => $unitId,
            ]);

            $this->refreshPage();
        }

        $this->dispatch('close-modal', id : "price-setting-{$this->cartItem->id}");
    }

    /**
     * On a bill the change is a locked, versioned, logged mutation like any other. The parent is told with
     * `open-bill-changed` so it reloads the lines and the version together.
     */
    private function applyUnitOnBill(?int $unitId): void
    {
        $user = Filament::auth()->user();
        $billId = (int) $this->cartItem->open_bill_id;
        $itemId = (int) $this->cartItem->getKey();
        $service = app(OpenBillService::class);

        try {
            $service->mutate($billId, function (OpenBill $bill) use ($itemId, $unitId, $user, $service): void {
                /** @var OpenBillItem $item */
                $item = OpenBillItem::query()
                    ->with('product')
                    ->where('open_bill_id', $bill->getKey())
                    ->lockForUpdate()
                    ->findOrFail($itemId);

                $from = $item->price_unit_id;
                $item->price_unit_id = $unitId;
                $item->save();

                $service->log($bill, OpenBillEvent::LINE_QTY, $user, [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    'qty_from' => (float) $item->qty,
                    'qty_to' => (float) $item->qty,
                    'detail' => ['price_unit_id' => ['from' => $from, 'to' => $unitId]],
                ]);
            });
        } catch (ModelNotFoundException) {
            // The line is gone: the parent reloads and shows the truth.
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }

        try {
            $this->cartItem->refresh();
        } catch (ModelNotFoundException) {
            // Removed meanwhile; nothing to show.
        }

        $bill =OpenBill::query()->whereKey($billId)->first(['id', 'number', 'version']);
        $this->dispatch(
            'open-bill-changed',
            id: $billId,
            number: $bill ? (int) $bill->number : null,
            version: $bill ? (int) $bill->version : null,
        );
    }

    public function render()
    {
        return view('livewire.price-setting');
    }
}
