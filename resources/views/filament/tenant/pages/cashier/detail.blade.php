{{--
  Tiles under the lines: member, order note, discount, voucher. Each opens the edit-detail modal on its own field
  (the forms and the totals logic stay on the server). Needs $cartDetail, $availableVoucher, $totals, $fm.
--}}
@use('App\Features\Discount')
@use('App\Features\Member')
@use('App\Features\Voucher')
@php
  $tile = 'flex min-h-11 w-full flex-col items-start justify-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-left hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong dark:border-gray-600 dark:bg-gray-900 dark:hover:bg-gray-800';
  $voucherCode = $cartDetail['voucher'] ?? null;
  $orderNote = trim((string) ($cartDetail['note'] ?? ''));
@endphp
<div class="grid grid-cols-2 gap-2 px-3 py-2" data-testid="order-tiles">
  @feature(Member::class)
    <button type="button" class="{{ $tile }}" data-testid="tile-member"
      x-on:click="$dispatch('open-modal', { id: 'edit-detail', inputId: 'memberSelect', index: 0, title: @js(__('Search member')) })">
      <span class="text-xs text-gray-600 dark:text-gray-300">{{ __('Member') }}</span>
      <span class="w-full truncate text-sm font-semibold">{{ $cartDetail['member_label'] ?? '-' }}</span>
    </button>
  @endfeature

  <button type="button" class="{{ $tile }}" data-testid="tile-note"
    x-on:click="$dispatch('open-modal', { id: 'edit-detail', inputId: 'noteInput', index: 2, title: @js(__('Order note')) })">
    <span class="text-xs text-gray-600 dark:text-gray-300">{{ __('Order note') }}</span>
    <span class="w-full truncate text-sm font-semibold">{{ $orderNote !== '' ? $orderNote : '-' }}</span>
  </button>

  @feature(Discount::class)
    <button type="button" class="{{ $tile }}" data-testid="tile-discount"
      x-on:click="$dispatch('open-modal', { id: 'edit-detail', inputId: 'discountInput', index: 4, title: @js(__('Discount')) })">
      <span class="text-xs text-gray-600 dark:text-gray-300">{{ __('Discount') }}</span>
      <span class="w-full truncate text-sm font-semibold">{{ ($totals['header_discount'] ?? 0) > 0 && blank($voucherCode) ? $fm($totals['header_discount']) : '-' }}</span>
    </button>
  @endfeature

  @feature(Voucher::class)
    <div class="col-span-2 space-y-1" data-testid="tile-voucher">
      <div class="flex items-center justify-between gap-2">
        <span class="text-xs text-gray-600 dark:text-gray-300">{{ __('Voucher') }}</span>
        @if (filled($voucherCode))
          <span class="flex items-center gap-1">
            <span class="text-sm font-semibold">{{ $voucherCode }}</span>
            <button type="button" wire:click="removeVoucher" aria-label="{{ __('Remove :name', ['name' => $voucherCode]) }}"
              class="inline-flex h-11 w-11 items-center justify-center rounded-lg text-danger-700 hover:bg-danger-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger-600 dark:text-danger-300 dark:hover:bg-gray-800">
              <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
          </span>
        @else
          <span class="text-sm">-</span>
        @endif
      </div>
      @if (blank($voucherCode) && $availableVoucher->isNotEmpty())
        <div class="flex flex-wrap gap-2">
          @foreach ($availableVoucher as $voucher)
            <button type="button" wire:key="voucher-{{ $voucher->id }}" wire:click="assignVoucher(@js($voucher->code))"
              class="inline-flex min-h-11 items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 text-sm font-semibold hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong dark:border-gray-600 dark:bg-gray-900 dark:hover:bg-gray-800">
              <x-heroicon-s-ticket class="h-5 w-5" />
              <span>{{ $voucher->code }}</span>
            </button>
          @endforeach
        </div>
      @endif
    </div>
  @endfeature
</div>
