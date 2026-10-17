<?php

namespace App\Livewire\Cashier;

use App\Models\Tenants\CartItem;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillGate;
use App\Services\Tenants\OpenBillService;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The note of one order line ("no sugar"). Each line has its own instance, so saving a note never re-renders the
 * cart and the textarea being typed in is never replaced by a parent re-render.
 *
 * The line is either a line of the cashier's private cart (`context` = 'cart') or a line of an open bill
 * (`context` = 'bill', with `billId`). On a bill the note goes through OpenBillService::setLineNote (locked,
 * versioned, logged) and the parent is told with `open-bill-changed` so it reloads the lines AND the version.
 */
class CartItemNote extends Component
{
    #[Locked]
    public int $cartItemId;

    /** 'cart' = private cart line, 'bill' = line of the open bill $billId. */
    #[Locked]
    public string $context = 'cart';

    #[Locked]
    public ?int $billId = null;

    /**
     * Nullable only because Livewire copies the `note` mount parameter into this property before mount() runs,
     * and a line without a note passes null. mount() and save() always leave it a string.
     */
    public ?string $note = '';

    public function mount(int $cartItemId, ?string $note = null, string $context = 'cart', ?int $billId = null): void
    {
        $this->cartItemId = $cartItemId;
        $this->context = $context === 'bill' && $billId !== null ? 'bill' : 'cart';
        $this->billId = $this->context === 'bill' ? $billId : null;
        $this->note = (string) $note;
    }

    /**
     * Saves what the browser sends (the model normalizes it: control characters out, one line, 255 characters).
     * A line that is not this cashier's, or is already gone, is ignored.
     */
    public function save(?string $note = null): void
    {
        if ($this->context === 'bill') {
            $this->saveOnBill($note);

            return;
        }

        $cartItem = CartItem::query()->cashier()->find($this->cartItemId);

        if (! $cartItem) {
            return;
        }

        $cartItem->note = $note;
        $cartItem->save();

        $this->note = (string) $cartItem->note;

        $this->dispatch('cart-item-note-saved', id: $cartItem->id, note: $this->note);
    }

    private function saveOnBill(?string $note): void
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User && OpenBillGate::canUse($user), 403);

        $billId = (int) $this->billId;
        $normalized = OpenBillItem::normalizeNote($note);

        try {
            app(OpenBillService::class)->setLineNote($billId, $this->cartItemId, $normalized, $user);
        } catch (ModelNotFoundException) {
            // The line (or bill) is gone: the parent reloads and shows the truth.
            $this->dispatch('open-bill-changed', id: $billId);

            return;
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $this->dispatch('open-bill-changed', id: $billId);

            return;
        }

        $this->note = (string) $normalized;

        $this->dispatch('cart-item-note-saved', id: $this->cartItemId, note: $this->note);

        $bill = OpenBill::query()->whereKey($billId)->first(['id', 'number', 'version']);
        $this->dispatch(
            'open-bill-changed',
            id: $billId,
            number: $bill ? (int) $bill->number : null,
            version: $bill ? (int) $bill->version : null,
        );
    }

    public function render()
    {
        return view('livewire.cashier.cart-item-note');
    }
}
