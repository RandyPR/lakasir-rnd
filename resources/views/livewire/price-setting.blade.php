{{--
  Unit price picker of one order line (CartItem or OpenBillItem; the component checks the cashier may touch it).
  The modal opens from inside the order sheet, so no ancestor may use transform / filter / contain: they would turn
  the fixed modal into a box the size of the sheet.
--}}
<div>
  @if ($cartItem?->product?->priceUnits?->count() > 0)
    <button
      type="button"
      data-testid="price-unit-button-{{ $cartItem->id }}"
      class="inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-900 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong disabled:opacity-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800"
      wire:loading.attr="disabled"
      x-on:click="$dispatch('open-modal', {id: 'price-setting-{{ $cartItem->id }}', data: { id: {{ $cartItem->id }} } })"
    >
      <x-heroicon-o-wrench-screwdriver class="h-5 w-5" />
      <span>@lang('Price')</span>
    </button>
    <x-filament::modal id="price-setting-{{ $cartItem->id }}">
      <x-slot name="heading">
        <p>{{ __('Choose the unit price') }}</p>
      </x-slot>
      <fieldset class="space-y-1">
        <legend class="sr-only">{{ __('Choose the unit price') }}</legend>
        @foreach ($cartItem->product->priceUnits as $priceUnit)
          <label for="unit-{{ $cartItem->id }}-{{ $priceUnit->id }}" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2 hover:bg-gray-100 dark:hover:bg-gray-800">
            <input type="radio" id="unit-{{ $cartItem->id }}-{{ $priceUnit->id }}" wire:model="unit" value="{{ $priceUnit->id }}"
              class="h-5 w-5 border-gray-400 text-lakasir-primary-strong focus:ring-lakasir-primary-strong">
            <span>{{ $priceUnit->unit }} - {{ price_format($priceUnit->selling_price) }}</span>
          </label>
        @endforeach
      </fieldset>
      <div class="mt-5 grid w-full grid-cols-2 gap-x-2">
        <button type="button" wire:click="changeThePrice"
          class="inline-flex min-h-11 items-center justify-center rounded-lg bg-lakasir-primary-strong px-3 text-sm font-semibold text-white hover:bg-orange-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong dark:bg-lakasir-primary-soft dark:text-[#1f1209] dark:hover:bg-orange-300">
          @lang('Changes')
        </button>
        <button type="button" wire:click="removeThePrice"
          class="inline-flex min-h-11 items-center justify-center gap-1 rounded-lg border border-danger-600 px-3 text-sm font-semibold text-danger-700 hover:bg-danger-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger-600 dark:border-danger-400 dark:text-danger-300 dark:hover:bg-gray-800">
          <x-heroicon-o-trash class="h-4 w-4" /> <span>@lang('Remove')</span>
        </button>
      </div>
    </x-filament::modal>
  @endif
</div>
