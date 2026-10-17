{{--
  The cashier page: product list (Livewire ProductBrowser) on the left, the order panel (cart-panel) as a bottom sheet
  on phones and a fixed column from md up. Printing is window.LakasirReceipt (partials/receipt-print), the shared
  scripts live in cashier/bootstrap (BODY_END hook). Money formatters $fm/$fmQty are defined here for all partials.
--}}
@php
  use App\Models\Tenants\Profile;
  use App\Models\Tenants\Setting;
  use Illuminate\Support\Number;

  $locale = Profile::get()->locale ?? 'en';
  $fm = fn ($v): string => Number::currency((float) $v, in: Setting::get('currency', 'IDR'), locale: $locale);
  $fmQty = fn ($v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
  $ctxKey = $openBillId !== null ? 'bill'.$openBillId : 'cart';
  $dockContext = $openBill['label'] ?? ($cartDetail['customer_name'] ?? null);
@endphp
<div>
<div data-testid="cashier-root" x-data="lkShell" x-on:keydown.escape.window="onEscape()">
  <div data-lk-content class="md:pr-80 lg:pr-[360px]">
    <div class="flex items-center justify-between gap-2 pb-2" x-data="fullscreen">
      <div class="flex items-center gap-2">
        <a href="/member/sellings"
          class="inline-flex min-h-11 items-center gap-1 rounded-lg bg-gray-100 px-3 text-gray-700 dark:bg-gray-800 dark:text-gray-200">
          <x-heroicon-o-arrow-left class="h-4 w-4" />
          <span class="hidden lg:block">{{ __('Back') }}</span>
        </a>
        @if ($billsVisible)
          <livewire:cashier.open-bills />
        @endif
      </div>
      <div class="flex items-center gap-1">
        <button type="button" x-on:click="$dispatch('open-modal', {id: 'qr-scanner-modal'})"
          class="inline-flex h-11 w-11 items-center justify-center rounded-full hover:bg-gray-100 dark:hover:bg-gray-800"
          aria-label="{{ __('Scan Barcode with Camera') }}">
          <x-heroicon-o-qr-code class="h-7 w-7 text-gray-900 dark:text-gray-300" />
        </button>
        <x-filament::dropdown placement="bottom-end">
          <x-slot name="trigger">
            <button type="button" class="inline-flex h-11 w-11 items-center justify-center rounded-full hover:bg-gray-100 dark:hover:bg-gray-800"
              aria-label="{{ __('Menu') }}">
              <x-heroicon-o-ellipsis-vertical class="h-7 w-7 text-gray-900 dark:text-gray-300" />
            </button>
          </x-slot>
          <x-filament::dropdown.list>
            <x-filament::dropdown.list.item x-on:mousedown="document.location.reload()" icon="heroicon-m-arrow-path">
              {{ __('Reload') }}
            </x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item x-on:mousedown="requestFullscreen" icon="heroicon-o-arrows-pointing-out">
              {{ __('Fullscreen') }}
            </x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item wire:confirm="{{ __('Are you sure you want to clear all of the items?') }}"
              wire:click.prevent="clearCart" icon="heroicon-o-trash">
              {{ __('Clear') }}
            </x-filament::dropdown.list.item>
          </x-filament::dropdown.list>
        </x-filament::dropdown>
      </div>
    </div>

    <livewire:cashier.product-browser />

    {{-- spacer so the last card is not hidden behind the phone dock --}}
    <div class="h-20 md:hidden" aria-hidden="true"></div>
  </div>

  {{-- phone dock: opens the order panel --}}
  @if ($cartCount > 0 || $openBillId !== null)
    <button type="button" x-ref="dock" data-testid="cart-dock" x-on:click="show()" x-show="!open" aria-haspopup="dialog"
      class="fixed inset-x-0 bottom-0 z-20 flex min-h-14 items-center justify-between gap-3 border-t border-gray-200 bg-white px-4 py-2 text-left shadow-2xl focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong dark:border-gray-700 dark:bg-gray-900 md:hidden">
      <span class="min-w-0">
        <span class="block truncate text-sm font-semibold">{{ filled($dockContext) ? $dockContext.' · ' : '' }}{{ __(':count items', ['count' => $cartCount]) }}</span>
        <span class="block text-lg font-bold">{{ $fm($totals['amount_due'] ?? 0) }}</span>
      </span>
      <span class="shrink-0 rounded-lg bg-lakasir-primary-strong px-3 py-2 text-sm font-semibold text-white">{{ __('View order') }}</span>
    </button>
  @endif

  @include('filament.tenant.pages.cashier.cart-panel')
</div>

@include('filament.tenant.pages.cashier.actions', ['part' => 'dialogs'])

  <x-filament::modal id="edit-detail" width="md">
    <x-slot name="heading">
      <span x-data="{ title: '{{ __('Edit detail') }}' }"
            x-on:open-modal.window="if ($event.detail?.id === 'edit-detail') { title = $event.detail?.title || '{{ __('Edit detail') }}'; }"
            x-text="title">
        {{ __('Edit detail') }}
      </span>
    </x-slot>
    <div x-data="{ activeField: '' }"
         x-on:open-modal.window="if ($event.detail?.id === 'edit-detail') { activeField = $event.detail?.inputId || ''; }"
         :class="activeField ? 'show-field-' + activeField : ''">
      <form wire:submit.prevent="storeCart">
        {{ $this->storeCartForm }}
        <div class="mt-4 flex justify-end">
          <x-filament::button type="submit">
            {{ __('Save') }}
          </x-filament::button>
        </div>
      </form>
    </div>
  </x-filament::modal>

  <x-filament::modal id="modal-selected-table" width="md">
    <x-slot name="heading">
      <div class="flex items-center gap-2">
        <x-heroicon-o-table-cells class="h-5 w-5 text-lakasir-primary" />
        <span>{{ __('Choose or Enter Table') }}</span>
      </div>
    </x-slot>

    <div class="space-y-4">
      {{-- Quick table selection grid --}}
      <div>
        <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2 block">
          {{ __('Quick Table Select') }}
        </label>
        <div class="grid grid-cols-3 gap-2.5 max-h-56 overflow-y-auto pr-1">
          {{-- Option: No table / Take away --}}
          <button type="button"
            wire:click="selectTable(null)"
            class="flex items-center justify-center gap-1.5 rounded-lg border px-3 py-2.5 text-sm font-medium transition-all hover:scale-[1.02] {{ empty($cartDetail['table_id']) ? 'border-lakasir-primary bg-lakasir-primary text-white shadow-sm' : 'border-dashed border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
            <x-heroicon-o-shopping-bag class="h-4 w-4" />
            <span>{{ __('Take Away') }}</span>
          </button>

          {{-- Existing registered tables --}}
          @if($tableOption)
            @foreach ($tableOption as $table)
              <button type="button"
                wire:click="selectTable({{ $table->id }})"
                class="flex items-center justify-center rounded-lg border px-3 py-2.5 text-sm font-medium transition-all hover:scale-[1.02] {{ ($cartDetail['table_id'] ?? null) == $table->id ? 'border-lakasir-primary bg-lakasir-primary text-white shadow-sm font-bold' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                {{ $table->number }}
              </button>
            @endforeach
          @endif
        </div>
      </div>

      {{-- Custom table input section --}}
      <div class="pt-3 border-t border-gray-200 dark:border-gray-700" x-data="{ customNumber: '' }">
        <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5 block">
          {{ __('Or Enter Custom Table Name') }}
        </label>
        <div class="flex gap-2">
          <input type="text"
            x-model="customNumber"
            x-on:keydown.enter.prevent="if (customNumber.trim()) { $wire.saveCustomTable(customNumber.trim()); customNumber = ''; }"
            placeholder="{{ __('e.g. VIP-01, Table 12, Bar 2...') }}"
            class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-lakasir-primary focus:ring-1 focus:ring-lakasir-primary outline-none" />
          <x-filament::button type="button"
            x-on:click="if (customNumber.trim()) { $wire.saveCustomTable(customNumber.trim()); customNumber = ''; }">
            {{ __('Save') }}
          </x-filament::button>
        </div>
      </div>
    </div>
  </x-filament::modal>

  <x-filament::modal id="qr-scanner-modal" width="lg" :close-by-clicking-away="false"
    x-on:close-modal.window="if ($event.detail.id === 'qr-scanner-modal') { window.stopScanner(); }">
    <x-slot name="heading">
      {{ __('Scan Barcode with Camera') }}
    </x-slot>

    {{-- Main container with Alpine.js state management --}}
    <div x-data="{ isLoading: false }" x-ref="scannerContainer">

      {{-- Loading spinner (hidden by default) --}}
      <div x-show="isLoading" class="flex min-h-[300px] flex-col items-center justify-center text-center">
        <svg class="h-16 w-16 animate-spin text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none"
          viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
          </circle>
          <path class="opacity-75" fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
          </path>
        </svg>
        <p class="mt-4 text-lg font-medium text-gray-600 dark:text-gray-300">
          Processing product...
        </p>
      </div>

      {{-- QR Scanner container (hidden when loading) --}}
      <div x-show="!isLoading">
        <div wire:ignore id="qr-reader" class="w-full"></div>
      </div>

    </div>

    <x-slot name="footer">
      <x-filament::button color="gray" x-on:click="$dispatch('close-modal', {id: 'qr-scanner-modal'})">
        {{ __('Close') }}
      </x-filament::button>
    </x-slot>
  </x-filament::modal>

  <style>
    /* html5-qrcode library button & control styling */
    #qr-reader__dashboard_section_csr button,
    #qr-reader__dashboard_section_swaplink {
      background-color: #f97316 !important;
      border: none !important;
      color: #fff !important;
      padding: 0.5rem 1.25rem !important;
      border-radius: 0.5rem !important;
      font-weight: 600 !important;
      font-size: 0.875rem !important;
      cursor: pointer !important;
      transition: background-color 0.2s ease !important;
      box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1) !important;
      margin: 0.25rem 0 !important;
    }

    #qr-reader__dashboard_section_csr button:hover,
    #qr-reader__dashboard_section_swaplink:hover {
      background-color: #ea580c !important;
    }

    #qr-reader__dashboard_section {
      padding: 0.75rem !important;
      margin-top: 0.5rem !important;
    }

    #qr-reader__dashboard_section_csr {
      display: flex !important;
      flex-direction: column !important;
      gap: 0.5rem !important;
      align-items: stretch !important;
    }

    #qr-reader__dashboard_section_csr select {
      width: 100% !important;
      padding: 0.5rem 0.75rem !important;
      border-radius: 0.5rem !important;
      border: 1px solid #d1d5db !important;
      background-color: #fff !important;
      color: #111827 !important;
      font-size: 0.875rem !important;
      outline: none !important;
      transition: border-color 0.2s ease !important;
    }

    #qr-reader__dashboard_section_csr select:focus {
      border-color: #f97316 !important;
      box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.25) !important;
    }

    #qr-reader__scan_region {
      min-height: 200px !important;
      border-radius: 0.5rem !important;
      overflow: hidden !important;
    }

    #qr-reader {
      border: none !important;
    }

    #qr-reader__dashboard {
      padding: 0.5rem !important;
    }

    #qr-reader__status_line {
      font-size: 0.875rem !important;
      padding: 0.25rem 0.5rem !important;
    }

    /* Dark mode support */
    .dark #qr-reader__dashboard_section_csr select {
      background-color: #111827 !important;
      color: #f3f4f6 !important;
      border-color: #374151 !important;
    }

    .dark #qr-reader__dashboard_section_csr select:focus {
      border-color: #f97316 !important;
      box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.25) !important;
    }

    .dark #qr-reader__dashboard_section {
      background-color: #1f2937 !important;
      border-color: #374151 !important;
    }

    .dark #qr-reader__dashboard {
      background-color: #111827 !important;
    }

    .dark #qr-reader__status_line {
      color: #d1d5db !important;
    }

    .dark #qr-reader img[alt="Info icon"] {
      filter: invert(1) !important;
    }

    /* Fix dark mode swap link: transparent background keeps text readable */
    .dark #qr-reader__dashboard_section_swaplink {
      background-color: transparent !important;
      color: #f97316 !important;
      text-decoration: underline !important;
      box-shadow: none !important;
      padding: 0 !important;
    }

    /* Selective field visibility in Edit Detail modal */
    /* When a show-field-* class is present, hide all field wrappers */
    div[class*="show-field-"] .fi-fo-field-wrp {
      display: none !important;
    }
    /* Then show only the field matching activeField */
    div.show-field-memberSelect .fi-fo-field-wrp[data-field="memberSelect"],
    div.show-field-memberSelect .fi-fo-field-wrp:has([data-field="memberSelect"]),
    div.show-field-customerNameInput .fi-fo-field-wrp[data-field="customerNameInput"],
    div.show-field-customerNameInput .fi-fo-field-wrp:has([data-field="customerNameInput"]),
    div.show-field-noteInput .fi-fo-field-wrp[data-field="noteInput"],
    div.show-field-noteInput .fi-fo-field-wrp:has([data-field="noteInput"]),
    div.show-field-voucherInput .fi-fo-field-wrp[data-field="voucherInput"],
    div.show-field-voucherInput .fi-fo-field-wrp:has([data-field="voucherInput"]),
    div.show-field-discountInput .fi-fo-field-wrp[data-field="discountInput"],
    div.show-field-discountInput .fi-fo-field-wrp:has([data-field="discountInput"]) {
      display: block !important;
    }
  </style>

  @include('filament.tenant.pages.cashier.payment.modal')
  @include('filament.tenant.pages.cashier.payment.success-modal')
  @include('filament.tenant.pages.cashier.bills.preview-modal')

</div>
