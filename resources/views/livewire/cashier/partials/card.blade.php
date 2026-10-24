{{--
  One product. The same DOM is a card in grid mode and a row in list mode (html[data-product-view], see head.blade.php).

  Needs: $product (with stock_total), $committed (product_id => qty on open bills), $lowStock (int), $currency, $locale,
  $fmtQty (Closure) and $fmtMoney (Closure). Does not use $this, so it can be included anywhere.

  The quantity shown comes ONLY from the Alpine store 'cart' (the active context: private cart or open bill) through n.
  The card is keyed with its stock, so a stock change builds a new card; Alpine state is never stale.
--}}
@php
  $id = (int) $product->getKey();
  $name = (string) $product->name;
  $unlimited = $product->isStockUnlimited();
  $stock = $unlimited ? 0.0 : (float) ($product->stock_total ?? 0);
  $out = ! $unlimited && $stock <= 0;
  $low = ! $unlimited && ! $out && $stock <= $lowStock;
  $held = $unlimited ? 0.0 : (float) ($committed[$id] ?? 0);
  $left = max($stock - $held, 0.0);
  $showHold = ! $unlimited && $held > 0 && ($stock - $held) <= $lowStock;
  $image = $product->hero_images_url[0] ?? null;
@endphp
<article
  wire:key="pv-{{ $id }}-{{ $unlimited ? 'u' : (string) $stock }}"
  class="pv-card{{ $out ? ' pv-out' : '' }}"
  data-testid="product-card-{{ $id }}"
  data-pv-card
  data-pid="{{ $id }}"
  data-stock="{{ $unlimited ? 0 : $stock }}"
  data-unlimited="{{ $unlimited ? 1 : 0 }}"
  data-committed="{{ $held }}"
  x-data="{ id: @js($id), stock: @js($stock), unl: @js($unlimited), get n() { return LakasirPV.qty(this.id) } }"
  x-bind:class="{ 'pv-in': n > 0 }"
>
  <div class="pv-media">
    <svg class="pv-ph pv-i" aria-hidden="true" focusable="false"><use href="#pv-i-image" /></svg>
    @if ($image)
      <img src="{{ $image }}" alt="" width="320" height="240" loading="lazy" decoding="async" onerror="this.onerror=null;this.style.display='none'">
    @endif
  </div>

  <h3 class="pv-name" title="{{ $name }}">{{ $name }}</h3>

  <div class="pv-meta">
    <span class="pv-price" data-testid="product-price-{{ $id }}">{{ $fmtMoney((float) $product->selling_price) }}</span>
    @if ($out)
      <span class="pv-badge pv-nil" data-testid="product-stock-{{ $id }}">{{ __('Out of stock') }}</span>
    @elseif (! $unlimited)
      <span class="pv-badge{{ $low ? ' pv-low' : '' }}" data-testid="product-stock-{{ $id }}">{{ $low ? __('Remaining :count', ['count' => $fmtQty($stock)]) : __('Stock :count', ['count' => $fmtQty($stock)]) }}</span>
    @endif
    @if ($showHold)
      <span class="pv-hold" data-testid="product-hold-{{ $id }}" data-left="{{ $left }}">{{ __('Left :left after open bills', ['left' => $fmtQty($left)]) }}</span>
    @endif
  </div>

  <div class="pv-act">
    <button
      type="button"
      class="pv-add"
      data-testid="product-add-{{ $id }}"
      aria-label="{{ $out ? __('Out of stock') : __('Add :name', ['name' => $name]) }}"
      @disabled($out)
      x-show="n <= 0"
      x-on:click="LakasirPV.add($wire, id)"
    >
      <svg class="pv-i" aria-hidden="true" focusable="false"><use href="#pv-i-plus" /></svg>
      <span class="pv-lbl">{{ $out ? __('Out of stock') : __('Add') }}</span>
    </button>

    <div class="pv-stepper" role="group" aria-label="{{ __('Quantity of :name', ['name' => $name]) }}" x-cloak x-show="n > 0">
      <button
        type="button"
        class="pv-step"
        data-testid="product-dec-{{ $id }}"
        data-l-remove="{{ __('Remove :name', ['name' => $name]) }}"
        data-l-reduce="{{ __('Reduce :name', ['name' => $name]) }}"
        x-bind:aria-label="n <= 1 ? $el.dataset.lRemove : $el.dataset.lReduce"
        x-on:click="LakasirPV.reduce($wire, id)"
      >
        <svg class="pv-i" aria-hidden="true" focusable="false" x-show="n > 1"><use href="#pv-i-minus" /></svg>
        <svg class="pv-i" aria-hidden="true" focusable="false" x-cloak x-show="n <= 1"><use href="#pv-i-trash" /></svg>
      </button>
      <input
        type="text"
        class="pv-q"
        data-testid="product-qty-{{ $id }}"
        inputmode="decimal"
        autocomplete="off"
        enterkeyhint="done"
        aria-label="{{ __('Quantity of :name', ['name' => $name]) }}"
        x-effect="LakasirPV.paint($el, n)"
        x-on:focus="$el.select()"
        x-on:input="LakasirPV.fit($el)"
        x-on:change="LakasirPV.commit($wire, id, $el)"
        x-on:keydown.enter.prevent="$el.blur()"
        x-on:keydown.escape.prevent="LakasirPV.sync($el, id); $el.blur()"
      >
      <button
        type="button"
        class="pv-step"
        data-testid="product-inc-{{ $id }}"
        aria-label="{{ __('Add :name', ['name' => $name]) }}"
        x-bind:disabled="! unl && n >= stock"
        x-on:click="LakasirPV.add($wire, id)"
      >
        <svg class="pv-i" aria-hidden="true" focusable="false"><use href="#pv-i-plus" /></svg>
      </button>
    </div>
  </div>
</article>
