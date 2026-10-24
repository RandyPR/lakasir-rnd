{{--
  The product list of the cashier page (Livewire child App\Livewire\Cashier\ProductBrowser): search, view toggle,
  category chips, status line, cards and "Load more". Styles and helpers: livewire/cashier/partials/head.blade.php.

  Quantity on a card comes from the Alpine store 'cart', which follows the ACTIVE context (private cart or open bill)
  through the 'cart-qty-updated' event. Adding and reducing call the parent (Cashier) component.
  The search box and the view toggle are client state (wire:ignore); the server only learns the search text.
--}}
@php
  use App\Livewire\Cashier\ProductBrowser;
  use App\Models\Tenants\Profile;

  // One formatter per render (not per card): the locale is the one the cart uses (price_format()).
  $locale = Profile::get()->locale ?? 'en';
  $qtyFormatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
  $qtyFormatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 3);
  $moneyFormatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
  $wholeMoneyFormatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
  $wholeMoneyFormatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
  $fmtQty = static fn (float|int|string|null $qty): string => (string) $qtyFormatter->format((float) $qty);
  $fmtMoney = static fn (float $price): string => (string) (floor($price) === $price
      ? $wholeMoneyFormatter->formatCurrency($price, (string) $currency)
      : $moneyFormatter->formatCurrency($price, (string) $currency));

  $searchText = trim((string) $this->search);
  $categoryId = $this->categoryId;
  $filtering = $searchText !== '' || $categoryId !== null;
  $count = $products->count();
  $canLoadMore = $hasMore && $this->limit < ProductBrowser::MAX;
  $categoryName = $categoryId !== null ? collect($this->chips)->firstWhere('id', $categoryId)['name'] ?? null : null;
  $status = __(':count menu', ['count' => $hasMore ? $count.'+' : $count]);
  if ($categoryName !== null) {
      $status .= ' · '.$categoryName;
  }
  if ($searchText !== '') {
      $status .= ' · "'.mb_substr($searchText, 0, 40).'"';
  }
@endphp
<div
  class="pv-browser"
  data-testid="product-browser"
  x-data
  x-on:cart-item-touched.window="LakasirPV.flash($event.detail)"
  x-on:bill-saved.window.debounce.400ms="$wire.$refresh()"
  x-on:bill-voided.window.debounce.400ms="$wire.$refresh()"
>
  @include('livewire.cashier.partials.sprite')

  <div class="pv-toolbar" data-testid="product-toolbar">
    <div
      class="pv-bar"
      wire:ignore
      x-data="{ q: @js((string) $this->search), view: LakasirPV.view() }"
      x-on:search-cleared.window="q = ''; $refs.q.value = ''"
    >
      <label class="pv-search" for="pv-search-input">
        <svg class="pv-i" aria-hidden="true" focusable="false"><use href="#pv-i-search" /></svg>
        <span class="pv-sr">{{ __('Search (SKU, name, barcode)') }}</span>
        <input
          id="pv-search-input"
          x-ref="q"
          x-model="q"
          class="pv-sin"
          type="search"
          data-testid="product-search"
          placeholder="{{ __('Search (SKU, name, barcode)') }}"
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          enterkeyhint="search"
          x-on:input.debounce.300ms="$wire.set('search', $el.value)"
          x-on:keydown.enter.prevent="$wire.set('search', $el.value, false); $wire.searchEnter($el.value)"
          x-on:keydown.escape="if (q !== '') { q = ''; $el.value = ''; $wire.set('search', '') }"
        >
        <button
          type="button"
          class="pv-ib"
          data-testid="product-search-clear"
          aria-label="{{ __('Clear search') }}"
          x-cloak
          x-show="q !== ''"
          x-on:click="q = ''; $refs.q.value = ''; $wire.set('search', ''); $refs.q.focus()"
        >
          <svg class="pv-i" aria-hidden="true" focusable="false"><use href="#pv-i-x" /></svg>
        </button>
      </label>

      <div class="pv-seg" role="group" aria-label="{{ __('Menu view') }}">
        <button
          type="button"
          data-testid="view-toggle-grid"
          aria-label="{{ __('Grid view') }}"
          title="{{ __('Grid view') }}"
          x-bind:aria-pressed="view === 'grid'"
          x-on:click="view = 'grid'; window.LakasirCashier.setView('grid')"
        >
          <svg class="pv-i" aria-hidden="true" focusable="false"><use href="#pv-i-grid" /></svg>
        </button>
        <button
          type="button"
          data-testid="view-toggle-list"
          aria-label="{{ __('Compact list') }}"
          title="{{ __('Compact list') }}"
          x-bind:aria-pressed="view === 'list'"
          x-on:click="view = 'list'; window.LakasirCashier.setView('list')"
        >
          <svg class="pv-i" aria-hidden="true" focusable="false"><use href="#pv-i-list" /></svg>
        </button>
      </div>

      <button
        type="button"
        class="pv-ib pv-scan"
        data-testid="product-scan"
        aria-label="{{ __('Scan barcode') }}"
        title="{{ __('Scan barcode') }}"
        x-on:click="$dispatch('open-modal', { id: 'qr-scanner-modal' })"
      >
        <svg class="pv-i" aria-hidden="true" focusable="false"><use href="#pv-i-scan" /></svg>
      </button>
    </div>

    @if (count($this->chips) > 0)
      <div class="pv-chips" role="group" aria-label="{{ __('Category') }}" data-testid="category-chips">
        <button
          type="button"
          wire:key="pv-chip-all"
          class="pv-chip pv-chip-plain"
          data-testid="category-chip-all"
          aria-pressed="{{ $categoryId === null ? 'true' : 'false' }}"
          wire:click="selectCategory(null)"
        >
          <span class="pv-chip-l">{{ __('All') }}</span>
        </button>
        @foreach ($this->chips as $chip)
          <button
            type="button"
            wire:key="pv-chip-{{ $chip['id'] }}"
            class="pv-chip"
            data-testid="category-chip-{{ $chip['id'] }}"
            aria-pressed="{{ $categoryId === $chip['id'] ? 'true' : 'false' }}"
            wire:click="selectCategory({{ $chip['id'] }})"
          >
            <span class="pv-chip-l">{{ $chip['name'] }}</span>
            <span class="pv-chip-n">{{ $chip['count'] }}</span>
          </button>
        @endforeach
      </div>
    @endif
  </div>

  <p class="pv-stat" role="status" aria-live="polite">
    <span class="pv-stat-l" data-testid="product-status">{{ $status }}</span>
    @if ($filtering)
      <button
        type="button"
        class="pv-reset"
        data-testid="product-reset"
        x-on:click="$dispatch('search-cleared'); $wire.set('search', '', false); $wire.selectCategory(null)"
      >{{ __('Reset filters') }}</button>
    @endif
  </p>

  @if ($products->isEmpty())
    <div class="pv-empty" data-testid="product-empty">
      <b>
        @if ($searchText !== '')
          {{ __('No products match') }}
        @elseif ($categoryId !== null)
          {{ __('No products in this category') }}
        @else
          {{ __('No products found') }}
        @endif
      </b>
    </div>
  @else
    <div
      class="pv-list"
      data-testid="product-list"
      data-pv-list
      wire:loading.class="pv-busy"
      wire:target="search,selectCategory,loadMore,searchEnter"
    >
      @foreach ($products as $product)
        @include('livewire.cashier.partials.card', [
          'product' => $product,
          'committed' => $committed,
          'lowStock' => $lowStockThreshold,
          'fmtQty' => $fmtQty,
          'fmtMoney' => $fmtMoney,
        ])
      @endforeach
    </div>
  @endif

  @if ($canLoadMore)
    <button
      type="button"
      class="pv-more"
      data-testid="product-load-more"
      wire:click="loadMore"
      wire:loading.attr="disabled"
      wire:target="loadMore"
    >{{ __('Load more') }}</button>
  @endif
</div>
