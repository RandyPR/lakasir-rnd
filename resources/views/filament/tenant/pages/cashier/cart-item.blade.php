{{--
  One line of the order on screen: a line of the private cart (CartItem) or of the open bill (OpenBillItem).
  Needs: $item, $ctxKey ('cart' | 'bill{id}'), $fm (money), $fmQty (quantity), $kitchenEnabled, $openBill, $openBillId,
  $btn* classes (set by cashier.blade.php). Every dynamic value goes through {{ }} or @js.
--}}
@use('App\Features\Discount')
@use('App\Models\Tenants\OpenBillItem')
@use('App\Services\Tenants\OrderTotals')
@use('App\Support\Money')
@php
  $isBill = $item instanceof OpenBillItem;
  $product = $item->product;
  $name = $product?->name ?? ('#'.$item->product_id);
  $qty = (float) $item->qty;
  $qtyText = $fmQty($qty);
  $lineTotal = OrderTotals::lineTotal($item);
  $unitPrice = OrderTotals::unitPrice($item);
  $exact = OrderTotals::unitIsExact($item);
  $discount = (float) ($item->discount_price ?? 0);
  $unlimited = $product ? $product->isStockUnlimited() : true;
  $atStockLimit = ! $unlimited && $qty >= (float) ($product->stock ?? 0);
  $stale = $isBill && in_array((int) $item->getKey(), $priceStaleLines ?? [], true);

  // Kitchen status of the line: only on a bill the kitchen has received before.
  $chip = null;
  if ($isBill && $kitchenEnabled && ! empty($openBill['sent'])) {
      $sent = (float) $item->sent_qty;
      if (Money::qtyEq($sent, 0.0)) {
          $chip = ['new', __('New')];
      } elseif (Money::qty($qty) > Money::qty($sent)) {
          $chip = ['new', __('Sent :sent, new :new', ['sent' => $fmQty($sent), 'new' => $fmQty($qty - $sent)])];
      } elseif (Money::qty($qty) < Money::qty($sent)) {
          $chip = ['cancel', __('Cancelled :qty', ['qty' => $fmQty($sent - $qty)])];
      } elseif ((string) ($item->note ?? '') !== (string) ($item->sent_note ?? '')) {
          $chip = ['note', __('Note changed')];
      } else {
          $chip = ['sent', __('Sent')];
      }
  }
  $chipClass = [
      'new' => 'bg-orange-100 text-orange-900 dark:bg-orange-950 dark:text-orange-200',
      'sent' => 'bg-success-100 text-success-900 dark:bg-success-950 dark:text-success-200',
      'cancel' => 'bg-danger-100 text-danger-900 dark:bg-danger-950 dark:text-danger-200',
      'note' => 'bg-warning-100 text-warning-900 dark:bg-warning-950 dark:text-warning-200',
  ];
  $iconBtn = 'inline-flex h-11 w-11 items-center justify-center rounded-lg text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong disabled:cursor-not-allowed disabled:opacity-40 dark:text-gray-200 dark:hover:bg-gray-800';
@endphp
<article wire:key="{{ $ctxKey }}-{{ $item->id }}" id="line-{{ $ctxKey }}-{{ $item->id }}" data-testid="cart-item-{{ $item->id }}"
  x-data="{ more: false, editing: false, hasNote: {{ filled($item->note) ? 'true' : 'false' }} }"
  x-on:cart-item-note-saved.window="if ($event.detail && $event.detail.id === {{ (int) $item->id }}) { hasNote = Boolean($event.detail.note) }"
  class="border-b border-gray-200 py-2 dark:border-gray-700">
  <div class="flex items-start justify-between gap-2">
    <h5 class="min-w-0 break-words text-sm font-semibold">{{ $name }}</h5>
    @if ($chip)
      <span data-testid="line-chip-{{ $item->id }}"
        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold {{ $chipClass[$chip[0]] }}">{{ $chip[1] }}</span>
    @endif
  </div>

  <div class="mt-0.5 flex items-baseline justify-between gap-2 text-sm">
    <span data-testid="cart-item-unit-{{ $item->id }}" class="min-w-0 break-words text-gray-600 dark:text-gray-300">{{ $fmQty($qty) }} x {{ $exact ? '' : '~' }}{{ $fm($unitPrice) }}</span>
    <span data-testid="cart-item-total-{{ $item->id }}" class="shrink-0 font-semibold">{{ $fm($lineTotal) }}</span>
  </div>

  @if ($item->priceUnit)
    <p class="text-xs text-gray-600 dark:text-gray-300">{{ $item->priceUnit->unit }}</p>
  @endif

  @if ($discount > 0)
    <p data-testid="cart-item-discount-{{ $item->id }}" class="text-sm text-gray-600 dark:text-gray-300">
      {{ __('Discount') }} ({{ $fm($discount) }})
    </p>
  @endif

  @if ($stale)
    <p data-testid="cart-item-price-stale-{{ $item->id }}" class="text-xs text-warning-800 dark:text-warning-300">
      {{ __('The price changed since the item was ordered') }}
    </p>
  @endif

  <livewire:cashier.cart-item-note
    :cart-item-id="$item->id"
    :note="$item->note"
    :context="$isBill ? 'bill' : 'cart'"
    :bill-id="$isBill ? $openBillId : null"
    :key="'note-'.$ctxKey.'-'.$item->id" />

  <div x-show="more" x-cloak id="line-opts-{{ $ctxKey }}-{{ $item->id }}" class="mt-2 space-y-2 rounded-lg bg-gray-100 p-2 dark:bg-gray-800">
    @feature(Discount::class)
      <div>
        <label for="line-discount-{{ $ctxKey }}-{{ $item->id }}" class="block text-xs font-semibold text-gray-700 dark:text-gray-300">{{ __('Discount') }}</label>
        <input id="line-discount-{{ $ctxKey }}-{{ $item->id }}" type="text" inputmode="numeric" autocomplete="off"
          data-testid="cart-item-discount-input-{{ $item->id }}"
          value="{{ $discount > 0 ? number_format($discount, 0, '.', ',') : '' }}" placeholder="{{ __('Discount') }}"
          x-mask:dynamic="$money($input)"
          x-on:change="$wire.addDiscountPricePerItem({{ (int) $item->id }}, parseFloat(($el.value || '0').replace(/,/g, '')) || 0)"
          class="mt-1 h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-right text-sm focus:border-lakasir-primary-strong focus:outline-none focus:ring-1 focus:ring-lakasir-primary-strong dark:border-gray-600 dark:bg-gray-900" />
      </div>
    @endfeature
    <livewire:price-setting :cart-item="$item" :key="'price-'.$ctxKey.'-'.$item->id" />
  </div>

  <div class="mt-1 flex items-center justify-between gap-2">
    <div class="flex items-center">
      <button type="button" data-testid="cart-item-note-toggle-{{ $item->id }}"
        x-on:click="editing = !editing"
        :aria-expanded="editing ? 'true' : 'false'"
        :aria-label="hasNote ? @js(__('Edit note for :name', ['name' => $name])) : @js(__('Add note for :name', ['name' => $name]))"
        class="{{ $iconBtn }} w-auto gap-1 px-2 text-sm font-medium">
        <x-heroicon-o-pencil-square class="h-5 w-5" />
        <span class="hidden min-[360px]:inline" x-text="hasNote ? @js(__('Edit note')) : @js(__('Add note'))"></span>
      </button>
      <button type="button" data-testid="cart-item-more-toggle-{{ $item->id }}"
        x-on:click="more = !more"
        :aria-expanded="more ? 'true' : 'false'"
        aria-controls="line-opts-{{ $ctxKey }}-{{ $item->id }}"
        aria-label="{{ __('More options') }}: {{ $name }}"
        class="{{ $iconBtn }}">
        <x-heroicon-o-ellipsis-horizontal class="h-5 w-5" />
      </button>
    </div>

    <div role="group" aria-label="{{ __('Quantity of :name', ['name' => $name]) }}"
      class="grid min-w-[116px] grid-cols-[44px_minmax(2rem,auto)_44px] items-center justify-self-end rounded-lg border border-gray-300 dark:border-gray-600">
      <button type="button" data-testid="cart-item-minus-{{ $item->id }}"
        wire:click="reduceCart({{ (int) $item->product_id }})" wire:loading.attr="disabled" wire:target="reduceCart,addCart,deleteCart"
        aria-label="{{ $qty <= 1 ? __('Remove :name', ['name' => $name]) : __('Reduce :name', ['name' => $name]) }}"
        class="{{ $iconBtn }}">
        @if ($qty <= 1)
          <x-heroicon-o-trash class="h-5 w-5 text-danger-600 dark:text-danger-400" />
        @else
          <x-heroicon-o-minus-small class="h-5 w-5" />
        @endif
      </button>
      <input type="text" inputmode="decimal" autocomplete="off" enterkeyhint="done"
        data-testid="cart-item-qty-{{ $item->id }}" data-qty="{{ $qtyText }}" value="{{ $qtyText }}"
        aria-label="{{ __('Quantity of :name', ['name' => $name]) }}"
        style="width: {{ max(2, strlen($qtyText) + 1) }}ch"
        x-on:focus="$el.select()"
        x-on:input="$el.style.width = Math.max(2, $el.value.length + 1) + 'ch'"
        x-on:keydown.enter.prevent="$el.blur()"
        x-on:change="LakasirCashier.setQty($el, {{ (int) $item->product_id }})"
        class="h-11 min-w-[2rem] max-w-[8rem] border-0 bg-transparent p-0 text-center text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-lakasir-primary-strong" />
      <button type="button" data-testid="cart-item-plus-{{ $item->id }}"
        wire:click="addCart({{ (int) $item->product_id }})" wire:loading.attr="disabled" wire:target="reduceCart,addCart,deleteCart"
        @disabled($atStockLimit)
        aria-label="{{ __('Add :name', ['name' => $name]) }}"
        class="{{ $iconBtn }}">
        <x-heroicon-o-plus-small class="h-5 w-5" />
      </button>
    </div>
  </div>
</article>
