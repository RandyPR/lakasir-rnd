{{--
  The order panel: a bottom sheet on phones (opened from the dock), a fixed column from md up.
  No transform / filter / contain / will-change / backdrop-filter on the sheet or anything above it: they would make
  position:fixed children (the price modal inside a line) relative to the sheet. It opens with opacity only.
  z-index: dock 20, scrim 30, sheet 30, Filament modals 40, dialogs 50, toasts 60.
--}}
@use('Filament\Facades\Filament')
{{-- scrim (phones only, while the sheet is open) --}}
<div x-show="open && !desk" x-cloak x-transition.opacity.duration.150ms x-on:click="hide()" aria-hidden="true"
  class="fixed inset-0 z-30 bg-black/50 md:hidden"></div>

<aside id="cashier-order-panel" x-ref="sheet" data-testid="cart-sheet"
  x-show="open || desk" x-cloak
  x-transition.opacity.duration.150ms
  x-on:keydown="trap($event)"
  :role="desk ? null : 'dialog'"
  :aria-modal="(!desk && open) ? 'true' : null"
  aria-label="{{ __('Current Orders') }}"
  :style="desk ? '' : 'height:min(90dvh, calc(var(--vvh, 100dvh) * .9))'"
  class="fixed inset-x-0 bottom-0 z-30 flex flex-col rounded-t-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900 md:inset-y-0 md:left-auto md:right-0 md:w-80 md:rounded-none md:border-y-0 md:border-r-0 md:shadow-none lg:w-[360px]">

  <header class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-2 dark:border-gray-700">
    <div class="min-w-0">
      <h2 class="truncate text-lg font-bold">{{ __('Current Orders') }}</h2>
      <p class="truncate text-xs text-gray-600 dark:text-gray-300">{{ Filament::auth()->user()?->cashier_name }}</p>
    </div>
    <button type="button" data-sheet-close x-on:click="hide()" aria-label="{{ __('Close order') }}"
      class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong dark:text-gray-200 dark:hover:bg-gray-800 md:hidden">
      <x-heroicon-o-chevron-down class="h-6 w-6" />
    </button>
  </header>

  @if ($openBillId !== null && ! $paymentPinned)
    {{-- Other devices may change the bill: look every 20 s, and only while this tab is visible. --}}
    <div wire:poll.20s.visible="syncBill" class="hidden" aria-hidden="true"></div>
  @endif

  <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain pb-2" data-testid="cart-scroll"
    wire:loading.class="opacity-60" wire:target="addCart,reduceCart,deleteCart,addDiscountPricePerItem,addCartUsingScanner,restoreCartItem,leaveBill,openBill">
    @include('filament.tenant.pages.cashier.order-header')
    @include('filament.tenant.pages.cashier.actions', ['part' => 'strip'])

    <div class="mt-2">
      @include('filament.tenant.pages.cashier.items')
    </div>

    @include('filament.tenant.pages.cashier.detail')
    @include('filament.tenant.pages.cashier.total')
  </div>

  @include('filament.tenant.pages.cashier.actions', ['part' => 'footer'])
</aside>
