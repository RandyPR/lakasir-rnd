<?php

namespace App\Filament\Tenant\Pages\Traits;

use App\Exceptions\OpenBill\BillClosedException;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\Product;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillGate;
use App\Services\Tenants\OpenBillService;
use App\Services\Tenants\OrderLineSorter;
use App\Support\BusinessDay;
use App\Support\Money;
use Closure;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cart behaviour shared by the cashier pages.
 *
 * By default the lines are the current cashier's private cart (`cart_items`), which is all that `POS` and
 * `Pages/CartItem` ever use. A page that can work on an open bill overrides `activeBillId()`: then the same
 * methods read the bill's lines (`open_bill_items`) and every change goes through `OpenBillService`
 * (locked, versioned, logged), never straight to the table.
 */
trait CartInteraction
{
    /** @var array<int, float>|null product_id => qty for the current cashier, loaded once per request */
    private ?array $cartQtyMap = null;

    /**
     * The open bill the page is working on, or null for the cashier's private cart ("new order").
     */
    protected function activeBillId(): ?int
    {
        return null;
    }

    /**
     * @return class-string<CartItem|OpenBillItem>
     */
    protected function lineModel(): string
    {
        return $this->activeBillId() === null ? CartItem::class : OpenBillItem::class;
    }

    /**
     * The lines of the active context: the current cashier's cart, or the lines of the active bill.
     *
     * @return Builder<CartItem|OpenBillItem>
     */
    protected function lineQuery(): Builder
    {
        $billId = $this->activeBillId();

        if ($billId === null) {
            return CartItem::query()->cashier();
        }

        return OpenBillItem::query()->where('open_bill_id', $billId);
    }

    /**
     * Runs a read-modify-write on the lines of the active context under a lock. The private cart: one transaction
     * (the callback locks the rows it reads with lockForUpdate). A bill: OpenBillService::mutate, which locks the
     * bill row and bumps its version; the callback gets the locked bill.
     */
    protected function withLineLock(Closure $callback): mixed
    {
        $billId = $this->activeBillId();

        if ($billId !== null) {
            return app(OpenBillService::class)->mutate($billId, $callback);
        }

        return DB::transaction(fn () => $callback(null));
    }

    /**
     * The line of this product in the active context, optionally locked for update.
     */
    protected function findLine(int|string $productId, bool $lock = false): CartItem|OpenBillItem|null
    {
        $query = $this->lineQuery()->where('product_id', $productId);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /**
     * The current cashier's cart lines, or the lines of the active bill: product + category eager loaded,
     * ordered by category then product name.
     *
     * @return Collection<int, CartItem|OpenBillItem>
     */
    protected function loadCartItems(): Collection
    {
        return OrderLineSorter::sort(
            $this->lineQuery()
                ->with(['product.category', 'priceUnit'])
                ->get()
        );
    }

    /**
     * product_id => qty of the lines of the active context. One query per request, reset by refreshCart().
     *
     * @return array<int, float>
     */
    protected function cartQtyMap(): array
    {
        return $this->cartQtyMap ??= array_map(
            'floatval',
            $this->lineQuery()->pluck('qty', 'product_id')->all()
        );
    }

    /**
     * Hook called by refreshCart() BEFORE the lines are reloaded. A bill page reads the bill version here: the
     * version first, the lines after, so lines can only be newer than the version we hold, never older.
     */
    protected function beforeCartReload(): void
    {
    }

    /**
     * Hook called at the end of refreshCart(), after the cart was reloaded. Pages that need to react override it.
     */
    protected function onCartRefreshed(): void
    {
    }

    /**
     * A bill call failed in a way the cashier should hear about. Pages that can leave the bill override this.
     */
    protected function onBillClosed(): void
    {
    }

    /**
     * Reload only what a cart change affects (items, vouchers, totals). Unlike mount(), this keeps the
     * customer, table and payment method the cashier already picked and skips unrelated queries.
     */
    public function refreshCart(): void
    {
        $this->cartQtyMap = null;
        $this->beforeCartReload();
        $this->cartItems = $this->loadCartItems();
        $today = BusinessDay::today();
        $this->availableVoucher = \App\Models\Tenants\Voucher::query()
            ->where('minimal_buying', '<=', $this->cartItems->sum('price'))
            ->where('start_date', '<=', $today)
            ->where('expired', '>=', $today)
            ->get();
        // Same as before: a cart change drops the applied voucher, the cashier re-applies it.
        $this->cartDetail['voucher'] = null;
        $this->calculateTotalPrice();
        $this->onCartRefreshed();
    }

    private function validateStock(Product $product, $qty): bool
    {
        if ($product->isStockUnlimited()) {
            return true;
        }

        // A quick, friendly check only. The authoritative one runs inside the sale transaction (StockGuard).
        $available = $product->stock_calculate;
        if ($available < 0 || $available < $qty) {
            Notification::make()
                ->title(__('Stock is out'))
                ->danger()
                ->send();
            $this->refreshCart();

            return false;
        }

        return true;
    }

    /**
     * Quantity typed by the cashier ("12,5" and "12.5" are both fine). Empty means 0 (remove the line);
     * anything that is not a non-negative number is rejected with null.
     */
    private function parseCartAmount(mixed $amount): ?float
    {
        if (is_string($amount)) {
            $amount = str_replace(',', '.', trim($amount));
        }

        if ($amount === null || $amount === '') {
            return 0.0;
        }

        if (! is_numeric($amount) || ! is_finite((float) $amount) || (float) $amount < 0) {
            return null;
        }

        return (float) $amount;
    }

    /**
     * Tell the browser what is about to be deleted so it can offer "undo". Must run before the delete.
     * `undoable` is true for every line, also one the kitchen already received: restoring it nets out against
     * the pending cancellation, so the kitchen never hears about a minus followed by a plus.
     */
    private function dispatchCartItemRemoved(CartItem|OpenBillItem $cartItem): void
    {
        $this->dispatch(
            'cart-item-removed',
            productId: (int) $cartItem->product_id,
            qty: (float) $cartItem->qty,
            note: $cartItem->note,
            discountPrice: $cartItem->discount_price !== null ? (float) $cartItem->discount_price : null,
            priceUnitId: $cartItem->price_unit_id !== null ? (int) $cartItem->price_unit_id : null,
            undoable: true,
            sent: $cartItem instanceof OpenBillItem && (float) $cartItem->sent_qty > 0,
        );
    }

    /**
     * The kitchen already received (part of) this line: reducing it below what was sent, or removing it, is not a
     * plain tap. The browser opens the cancel dialog (reason required) and calls cancelSent().
     */
    private function requestCancelSent(OpenBillItem $item, float $newQty): void
    {
        $this->dispatch(
            'cancel-sent-request',
            itemId: (int) $item->getKey(),
            productId: (int) $item->product_id,
            name: (string) ($item->product?->name ?? ''),
            sentQty: (float) $item->sent_qty,
            qty: (float) $item->qty,
            newQty: max(0.0, $newQty),
        );
    }

    /**
     * The signed-in cashier, allowed to use bills at all (403 otherwise).
     */
    protected function billUser(): User
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User && OpenBillGate::canUse($user), 403);

        return $user;
    }

    /**
     * A bill action was refused (closed, not allowed, reason missing, ...). The exceptions of the bill layer carry
     * a translated message. The lines are reloaded so the screen shows the truth.
     */
    protected function reportBillFailure(\Throwable $e): void
    {
        if ($e instanceof ModelNotFoundException) {
            // The line (or the bill) is gone: another device removed it. Showing the truth is the whole answer.
            $this->refreshCart();

            return;
        }

        $message = $e instanceof ValidationException
            ? (collect($e->errors())->flatten()->first() ?? $e->getMessage())
            : $e->getMessage();

        Notification::make()
            ->title($message !== '' ? $message : __('You are not allowed to do this'))
            ->danger()
            ->send();

        if ($e instanceof BillClosedException) {
            $this->onBillClosed();

            return;
        }

        $this->refreshCart();
    }

    public function addCart(Product $product, ?array $data = null)
    {
        if ($this->activeBillId() !== null) {
            $this->addToBill($product, $data);

            return;
        }

        $auth = Filament::auth()->id();

        $outcome = $this->withLineLock(function () use ($product, $data, $auth): array {
            $existing = $this->findLine($product->getKey(), lock: true);

            if (! $data) {
                $qty = ($existing?->qty ?? 0) + 1;
            } else {
                $qty = $this->parseCartAmount($data['amount'] ?? null);

                if ($qty === null) {
                    // Negative or not a number: ignore it and resync the browser with what is really in the cart.
                    return ['invalid', null];
                }

                if ($qty == 0) {
                    return ['zero', $existing];
                }
            }

            if (! $this->validateStock($product, $qty)) {
                return ['stock', null];
            }

            CartItem::query()
                ->updateOrCreate(
                    [
                        'product_id' => $product->getKey(),
                        'user_id' => $auth,
                    ],
                    [
                        'qty' => $qty,
                        'price' => $product->selling_price * $qty,
                        'user_id' => $auth,
                        'product_id' => $product->getKey(),
                    ]
                );

            return ['saved', null];
        });

        [$result, $existing] = $outcome;

        if ($result === 'stock') {
            return;
        }

        if ($result === 'invalid') {
            $this->refreshCart();

            return;
        }

        if ($result === 'zero') {
            if ($existing) {
                $this->deleteCart($existing);
            } else {
                $this->refreshCart();
            }

            return;
        }

        $this->refreshCart();
        $this->dispatch('cart-item-touched', productId: (int) $product->getKey());
    }

    /**
     * addCart() on a bill: the typed or implied quantity is a target; the bill only learns the difference.
     * Adding goes to OpenBillService::addLine (priced at today's price for the added part only); taking away
     * goes to setLineQty, and below what the kitchen already received it is refused here (cancelSent()).
     *
     * @param  array{amount?: mixed}|null  $data
     */
    private function addToBill(Product $product, ?array $data): void
    {
        $user = $this->billUser();
        $billId = (int) $this->activeBillId();

        /** @var OpenBillItem|null $existing */
        $existing = $this->findLine($product->getKey());
        $current = (float) ($existing?->qty ?? 0);

        if (! $data) {
            $qty = $current + 1;
        } else {
            $qty = $this->parseCartAmount($data['amount'] ?? null);

            if ($qty === null) {
                $this->refreshCart();

                return;
            }
        }

        $qty = Money::qty($qty);

        if (Money::qtyEq($qty, $current)) {
            $this->refreshCart();

            return;
        }

        if ($qty > $current) {
            // Quick check of the new total only; the authoritative stock check runs when the bill is paid.
            if (! $this->validateStock($product, $qty)) {
                return;
            }
        } elseif ($existing && $qty < Money::qty((float) $existing->sent_qty)) {
            $this->requestCancelSent($existing, $qty);

            return;
        }

        $service = app(OpenBillService::class);

        try {
            if ($qty > $current) {
                $service->addLine($billId, $product, Money::qty($qty - $current), null, null, $user);
            } elseif ($qty <= 0) {
                $this->dispatchCartItemRemoved($existing);
                $service->setLineQty($billId, (int) $existing->getKey(), 0.0, $user);
            } else {
                $service->setLineQty($billId, (int) $existing->getKey(), $qty, $user);
            }
        } catch (DomainException|ValidationException|ModelNotFoundException $e) {
            $this->reportBillFailure($e);

            return;
        }

        $this->refreshCart();
        $this->dispatch('cart-item-touched', productId: (int) $product->getKey());
    }

    public function reduceCart(Product $product)
    {
        if ($this->activeBillId() !== null) {
            $this->reduceBillLine($product);

            return;
        }

        $removed = $this->withLineLock(function () use ($product): ?bool {
            $cartItem = $this->findLine($product->getKey(), lock: true);

            if (! $cartItem) {
                return null;
            }

            $qty = $cartItem->qty - 1;
            if ($qty <= 0) {
                $this->dispatchCartItemRemoved($cartItem);
                $cartItem->delete();

                return true;
            }

            $cartItem->fill([
                'qty' => $qty,
                'price' => $product->selling_price * ($qty),
            ]);
            $cartItem->save();

            return false;
        });

        $this->refreshCart();

        if ($removed === false) {
            $this->dispatch('cart-item-touched', productId: (int) $product->getKey());
        }
    }

    /**
     * The "-" stepper on a bill line. Down to what the kitchen already received it is a plain change; going below
     * that (or removing a line the kitchen received) needs a reason, so it opens the cancel dialog instead.
     */
    private function reduceBillLine(Product $product): void
    {
        $user = $this->billUser();
        $billId = (int) $this->activeBillId();

        /** @var OpenBillItem|null $item */
        $item = $this->findLine($product->getKey());

        if (! $item) {
            $this->refreshCart();

            return;
        }

        $newQty = Money::qty((float) $item->qty - 1);

        if ($newQty < Money::qty((float) $item->sent_qty)) {
            $this->requestCancelSent($item, $newQty);

            return;
        }

        try {
            if ($newQty <= 0) {
                $this->dispatchCartItemRemoved($item);
                app(OpenBillService::class)->setLineQty($billId, (int) $item->getKey(), 0.0, $user);
            } else {
                app(OpenBillService::class)->setLineQty($billId, (int) $item->getKey(), $newQty, $user);
            }
        } catch (DomainException|ValidationException|ModelNotFoundException $e) {
            $this->reportBillFailure($e);

            return;
        }

        $this->refreshCart();
        $this->dispatch('cart-item-touched', productId: (int) $product->getKey());
    }

    /**
     * Accepts a line or its id. A line that is already gone (double tap, another tab) is ignored.
     */
    public function deleteCart(CartItem|OpenBillItem|int|string|null $cartItem = null)
    {
        if ($this->activeBillId() !== null) {
            $this->deleteBillLine($cartItem);

            return;
        }

        if ($cartItem !== null && ! $cartItem instanceof CartItem) {
            $cartItem = CartItem::query()->cashier()->find($cartItem);
        }

        // Only the current cashier's own lines can be removed.
        if ($cartItem && $cartItem->user_id == Filament::auth()->id()) {
            $this->dispatchCartItemRemoved($cartItem);
            $cartItem->delete();
        }

        $this->refreshCart();
    }

    /**
     * Removing a bill line. A line the kitchen never received goes away at once (with Undo); one it received is
     * not removed here: the cancel dialog asks for a reason first (cancelSent()).
     */
    private function deleteBillLine(CartItem|OpenBillItem|int|string|null $line): void
    {
        $user = $this->billUser();
        $billId = (int) $this->activeBillId();

        $itemId = $line instanceof OpenBillItem ? $line->getKey() : (is_object($line) ? null : $line);

        /** @var OpenBillItem|null $item */
        $item = $itemId === null ? null : $this->lineQuery()->with('product')->find($itemId);

        if (! $item) {
            $this->refreshCart();

            return;
        }

        if ((float) $item->sent_qty > 0) {
            $this->requestCancelSent($item, 0.0);

            return;
        }

        try {
            $this->dispatchCartItemRemoved($item);
            app(OpenBillService::class)->setLineQty($billId, (int) $item->getKey(), 0.0, $user);
        } catch (DomainException|ValidationException|ModelNotFoundException $e) {
            $this->reportBillFailure($e);

            return;
        }

        $this->refreshCart();
    }

    /**
     * @param  CartItem|OpenBillItem|array<string, mixed>|int|string  $cartItem  a line, its id or a serialized line
     */
    public function addDiscountPricePerItem(CartItem|OpenBillItem|array|int|string $cartItem, $value)
    {
        $line = $this->resolveOwnLine($cartItem);

        if (! $line) {
            $this->refreshCart();

            return;
        }

        $discount = is_numeric($value) ? (float) $value : 0.0;

        // A negative or non-finite discount would raise the price. Empty or 0 simply clears the discount.
        if (! is_finite($discount) || $discount < 0) {
            $this->refreshCart();

            return;
        }

        if ($line instanceof OpenBillItem) {
            try {
                app(OpenBillService::class)->setLineDiscount(
                    (int) $this->activeBillId(),
                    (int) $line->getKey(),
                    $discount > 0 ? $discount : null,
                    $this->billUser(),
                );
            } catch (DomainException|ValidationException|ModelNotFoundException $e) {
                $this->reportBillFailure($e);

                return;
            }

            $this->refreshCart();

            return;
        }

        $line->discount_price = $discount;
        $line->save();
        $this->refreshCart();
    }

    /**
     * Finds the line behind whatever the browser sent, always inside the active context (so a cashier can only
     * touch their own cart, or the lines of the bill that is open on this page).
     */
    private function resolveOwnLine(CartItem|OpenBillItem|array|int|string $line): CartItem|OpenBillItem|null
    {
        if (is_array($line)) {
            $line = $line['id'] ?? null;
        } elseif ($line instanceof CartItem || $line instanceof OpenBillItem) {
            $line = $line->getKey();
        }

        if ($line === null || $line === '') {
            return null;
        }

        return $this->lineQuery()->find($line);
    }

    public function updateCart(CartItem $cartItem, $value)
    {
        if ((int) $value == 0) {
            $cartItem->delete();
            $this->refreshCart();

            return;
        }
        $value = $value != '' ? $value : 0;
        if ($cartItem->product->qty <= $value) {
            Notification::make()
                ->title(__('Stock is out'))
                ->danger()
                ->send();
            $this->refreshCart();

            return;
        }
        $price = $cartItem->product->selling_price * ((int) $value);
        $cartItem->fill([
            'qty' => $value,
            'price' => $price,
        ]);
        $cartItem->save();
        $this->refreshCart();
    }

    public function clearCart()
    {
        CartItem::query()
            ->cashier()
            ->delete();

        Notification::make()
            ->title(__('Cart has been cleared'))
            ->success()
            ->send();
        $this->refreshCart();
    }

    public function addCartUsingScanner(string $value)
    {
        $product = Product::findByBarcodeOrSku($value);

        if (! $product) {
            Notification::make()
                ->title(__('Product not found'))
                ->warning()
                ->send();

            return;
        }

        $stock = 1;

        $cartItem = $this->findLine($product->getKey());
        if ($cartItem) {
            $stock = $cartItem->qty + 1;
        }

        $this->addCart($product, [
            'amount' => $stock,
        ]);
    }
}
