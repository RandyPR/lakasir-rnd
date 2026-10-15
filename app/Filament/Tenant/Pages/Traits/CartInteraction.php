<?php

namespace App\Filament\Tenant\Pages\Traits;

use App\Models\Tenants\CartItem;
use App\Models\Tenants\Product;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

trait CartInteraction
{
    /** @var array<int, float>|null product_id => qty for the current cashier, loaded once per request */
    private ?array $cartQtyMap = null;

    private function cartQtyFor(int|string $productId): ?float
    {
        $this->cartQtyMap ??= CartItem::query()->cashier()->pluck('qty', 'product_id')->all();

        return $this->cartQtyMap[$productId] ?? null;
    }

    /**
     * Reload only what a cart change affects (items, vouchers, totals). Unlike mount(), this keeps the
     * customer, table and payment method the cashier already picked and skips unrelated queries.
     */
    public function refreshCart(): void
    {
        $this->cartQtyMap = null;
        $this->cartItems = CartItem::query()
            ->with('product', 'priceUnit')
            ->orderByDesc('created_at')
            ->cashier()
            ->get();
        $this->availableVoucher = \App\Models\Tenants\Voucher::query()
            ->where('minimal_buying', '<=', $this->cartItems->sum('price'))
            ->where('start_date', '<=', today()->format('Y-m-d'))
            ->where('expired', '>=', today()->format('Y-m-d'))
            ->get();
        $this->calculateTotalPrice();
        // Same as before: a cart change drops the applied voucher, the cashier re-applies it.
        $this->cartDetail['voucher'] = null;
    }

    private function validateStock(Product $product, $qty): bool
    {
        $available = $product->stock_calculate;
        if (! $product->is_non_stock && ($available < 0 || $available < $qty)) {
            Notification::make()
                ->title(__('Stock is out'))
                ->danger()
                ->send();
            $this->refreshCart();

            return false;
        }

        return true;
    }

    public function addCart(Product $product, ?array $data = null)
    {
        $auth = Filament::auth()->id();
        if (! $data) {
            $qty = (
                CartItem::whereProductId($product->getKey())
                    ->cashier()
                    ->first()
                ->qty ?? 0
            ) + 1;
        } else {
            if (!$data['amount']) {
                $this->deleteCart(CartItem::whereProductId($product->getKey())->first());
                $this->refreshCart();
                return;
            }
            $qty = $data['amount'];
        }
        if (! $this->validateStock($product, $qty)) {
            return;
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
        $this->refreshCart();
    }

    public function reduceCart(Product $product)
    {
        $cartItem = CartItem::whereProductId($product->getKey())
            ->cashier()
            ->first();
        $qty = $cartItem->qty - 1;
        if ($qty == 0) {
            $cartItem->delete();

            $this->refreshCart();

            return;
        }
        $price = $product->selling_price * ($qty);
        $cartItem->fill([
            'qty' => $qty,
            'price' => $price,
        ]);
        $cartItem->save();
        $this->refreshCart();
    }

    public function deleteCart(CartItem $cartItem)
    {
        $cartItem->delete();
        $this->refreshCart();
    }

    public function addDiscountPricePerItem(CartItem $cartItem, $value)
    {
        if (! ($value && $value > 0) && $value > $cartItem->product->selling_price) {
            return;
        }
        $cartItem->discount_price = (float) $value;

        $cartItem->save();
        $this->refreshCart();
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

        $cartItem = CartItem::whereProductId($product->getKey())
            ->cashier()
            ->first();
        if ($cartItem) {
            $stock = $cartItem->qty + 1;
        }

        $this->addCart($product, [
            'amount' => $stock,
        ]);
    }
}
