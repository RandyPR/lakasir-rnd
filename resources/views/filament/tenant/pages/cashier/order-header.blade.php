{{--
  Top of the order panel: which order this is (new order / bill), the customer name and the table.
  Needs $openBillId, $openBill, $billResumed, $draftRestored, $cartDetail, $customerNameHint, $kitchenEnabled, $cartCount.
--}}
@php
  $inBill = $openBillId !== null && ! empty($openBill);
  $billTitle = $inBill ? __('Bill #:number', ['number' => str_pad((string) $openBill['number'], 3, '0', STR_PAD_LEFT)]) : '';
  $billLabel = $inBill && $openBill['label'] !== $billTitle ? $openBill['label'] : null;
  $tableLabel = $cartDetail['table_label'] ?? null;
  $chipAction = 'inline-flex min-h-11 shrink-0 items-center rounded-lg border border-white/80 px-3 text-sm font-semibold text-white hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white dark:border-[#1f1209]/70 dark:text-[#1f1209] dark:hover:bg-black/10 dark:focus-visible:ring-[#1f1209]';
@endphp
<div class="space-y-2 px-3 pt-2">
  @if ($inBill)
    <div @if ($billResumed) data-testid="bill-resume-chip" @else data-testid="bill-chip" @endif
      class="flex items-center justify-between gap-2 rounded-lg bg-lakasir-primary-strong px-3 py-1.5 text-white dark:bg-lakasir-primary-soft dark:text-[#1f1209]">
      <div class="min-w-0">
        @if ($billResumed)
          {{-- Two lines at most: cut to one, the number and the table were the part that disappeared. --}}
          <p class="line-clamp-2 break-words text-sm font-bold">{{ __('Resuming Bill #:number (:label)', ['number' => str_pad((string) $openBill['number'], 3, '0', STR_PAD_LEFT), 'label' => $openBill['label']]) }}</p>
        @else
          <p class="truncate text-sm font-bold">{{ $billTitle }}@if ($billLabel) <span class="font-semibold"> - {{ $billLabel }}</span>@endif</p>
        @endif
        @if (! empty($openBill['business_date_label']))
          <p class="truncate text-xs font-semibold" data-testid="bill-date-label">
            {{ $openBill['business_date_label'] }} -
            {{ ! empty($openBill['stale']) ? __('From yesterday') : __('Past midnight') }}
          </p>
        @endif
      </div>
      <button type="button" wire:click="leaveBill" wire:loading.attr="disabled" wire:target="leaveBill" data-testid="leave-bill" class="{{ $chipAction }}">
        {{ __('Back to new order') }}
      </button>
    </div>
  @else
    <div data-testid="new-order-chip"
      class="flex items-center justify-between gap-2 rounded-lg border border-dashed border-gray-400 px-3 py-1.5 dark:border-gray-500">
      <p class="min-w-0 truncate text-sm font-semibold">{{ __('Unsaved order') }}</p>
      <p class="shrink-0 text-xs text-gray-700 dark:text-gray-300" data-testid="new-order-count">{{ __(':count items', ['count' => $cartCount]) }}</p>
    </div>
    @if ($draftRestored)
      <div data-testid="draft-restored" role="status"
        class="flex items-center justify-between gap-2 rounded-lg bg-gray-100 px-3 py-1 text-xs dark:bg-gray-800">
        <span>{{ __('Draft restored') }}</span>
        <button type="button" wire:click="discardDraft" class="inline-flex min-h-11 items-center px-2 font-semibold underline">{{ __('Discard draft') }}</button>
      </div>
    @endif
  @endif

  <div class="flex items-start gap-2">
    <div class="min-w-0 flex-1" x-data="lkName(@js((string) ($cartDetail['customer_name'] ?? '')))">
      <label for="customer-name-input" class="sr-only">{{ __('Customer Name') }}</label>
      <div class="relative">
        <input id="customer-name-input" x-ref="input" type="text" maxlength="240" autocomplete="off" enterkeyhint="done"
          data-testid="customer-name-input"
          placeholder="{{ __('Customer Name (Optional)') }}"
          x-model="value"
          x-on:input="onInput()"
          x-on:blur="flush()"
          x-on:keydown.enter.prevent="flush(); $el.blur()"
          class="h-11 w-full rounded-lg border border-gray-300 bg-white pl-3 pr-11 text-sm focus:border-lakasir-primary-strong focus:outline-none focus:ring-1 focus:ring-lakasir-primary-strong dark:border-gray-600 dark:bg-gray-900" />
        <button type="button" x-show="value" x-cloak aria-label="{{ __('Clear customer name') }}" x-on:click="clear()"
          class="absolute right-0 top-0 inline-flex h-11 w-11 items-center justify-center text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
          <x-heroicon-o-x-mark class="h-5 w-5" />
        </button>
      </div>
      <button type="button" x-show="state !== 'saved'" x-cloak role="status" data-testid="customer-name-status"
        x-text="statusText" x-on:click="if (state === 'failed') flush()"
        class="mt-0.5 text-xs"
        :class="state === 'failed' ? 'font-semibold text-danger-700 underline dark:text-danger-400' : 'text-gray-600 dark:text-gray-300'"></button>
      @if ($customerNameHint)
        <p data-testid="customer-name-hint" class="mt-0.5 text-xs text-warning-800 dark:text-warning-300">
          {{ __('Will be recorded as :name', ['name' => $customerNameHint]) }}
        </p>
      @endif
    </div>

    @if ($kitchenEnabled)
      <button type="button" data-testid="table-button" aria-haspopup="dialog"
        x-on:click="$dispatch('open-modal', { id: 'modal-selected-table' })"
        class="inline-flex h-11 min-w-[92px] shrink-0 items-center justify-center gap-1 rounded-lg border px-3 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong {{ filled($tableLabel) ? 'border-lakasir-primary-strong bg-orange-50 text-orange-950 dark:border-lakasir-primary-soft dark:bg-orange-950/40 dark:text-orange-100' : 'border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-900' }}">
        <x-heroicon-o-table-cells class="h-5 w-5" />
        <span class="max-w-[7rem] truncate">{{ filled($tableLabel) ? __('Table').' '.$tableLabel : __('Fill the table') }}</span>
      </button>
    @endif
  </div>
</div>
