{{--
  Three pieces, picked with $part:
    'strip'   the permanent kitchen strip under the order header (what the kitchen has not been told yet)
    'footer'  total + the action buttons of the order panel
    'dialogs' modal dialogs (cancel a sent item, bill conflict, takeaway). They sit OUTSIDE [data-lk-content], which is
              made inert while one is open. Opened by bootstrap.blade.php through the `lk-dialog-open` window event.
--}}
@php
  $btn = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong disabled:cursor-not-allowed disabled:opacity-50';
  $btnPrimary = $btn.' bg-lakasir-primary-strong text-white hover:opacity-90 dark:bg-lakasir-primary-soft dark:text-[#1f1209]';
  $btnSecondary = $btn.' border border-gray-300 bg-white text-gray-900 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800';
  $canBill = $billsVisible && ($billsEnabled || $openBillId !== null);
  $inBill = $openBillId !== null && ! empty($openBill);
  $pending = (int) ($openBill['pending_count'] ?? 0);
  $cancelPending = (int) ($openBill['cancel_pending_count'] ?? 0);
  $sentOnce = $inBill && ! empty($openBill['sent']);
@endphp

@if ($part === 'strip')
  @if ($kitchenEnabled && $sentOnce)
    <div data-testid="kitchen-pending-strip" role="status"
      class="mx-3 mt-2 flex items-center justify-between gap-2 rounded-lg border px-3 py-1.5 text-sm {{ $cancelPending > 0 ? 'border-danger-600 bg-danger-50 text-danger-900 dark:border-danger-500 dark:bg-gray-800 dark:text-danger-200' : ($pending > 0 ? 'border-warning-600 bg-warning-50 text-warning-900 dark:border-warning-500 dark:bg-gray-800 dark:text-warning-200' : 'border-success-600 bg-success-50 text-success-900 dark:border-success-500 dark:bg-gray-800 dark:text-success-200') }}">
      @if ($cancelPending > 0)
        <span class="min-w-0 font-semibold">{{ __(':count cancellations not yet told to the kitchen', ['count' => $cancelPending]) }}</span>
        <button type="button" wire:click="sendToKitchen" wire:loading.attr="disabled" wire:target="sendToKitchen" data-testid="strip-send"
          class="inline-flex min-h-11 shrink-0 items-center rounded-lg border border-current px-3 font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong">{{ __('Send to kitchen') }}</button>
      @elseif ($pending > 0)
        <span class="min-w-0 font-semibold">{{ __('Unsent changes') }}: {{ $pending }}</span>
        <button type="button" wire:click="sendToKitchen" wire:loading.attr="disabled" wire:target="sendToKitchen" data-testid="strip-send"
          class="inline-flex min-h-11 shrink-0 items-center rounded-lg border border-current px-3 font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong">{{ __('Send to kitchen') }}</button>
      @else
        <span class="min-w-0 font-semibold">{{ __('Sent to kitchen') }}</span>
        <button type="button" wire:click="reprintKitchen" wire:loading.attr="disabled" wire:target="reprintKitchen" data-testid="strip-reprint"
          class="inline-flex min-h-11 shrink-0 items-center rounded-lg border border-current px-3 font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong">{{ __('Reprint last kitchen ticket') }}</button>
      @endif
    </div>
  @endif
@endif

@if ($part === 'footer')
  <div data-lk-footer x-show="!kb" class="border-t border-gray-200 bg-white px-3 pb-3 pt-2 dark:border-gray-700 dark:bg-gray-900">
    <div class="mb-2 flex items-baseline justify-between gap-2">
      <span class="text-base font-bold">{{ __('Total') }}</span>
      <span class="text-xl font-bold" data-testid="cart-total">{{ $fm($totals['amount_due'] ?? 0) }}</span>
    </div>

    @if ($canBill)
      @if ($kitchenEnabled)
        <div class="grid grid-cols-[7fr_5fr] gap-2">
          <button type="button" wire:click="sendToKitchen" wire:loading.attr="disabled" wire:target="sendToKitchen,saveBill,printProvisional"
            @disabled($cartCount === 0) data-testid="send-kitchen"
            class="{{ $btnPrimary }} relative flex-col gap-0 py-1 leading-tight">
            <span class="flex items-center gap-1.5">
              <x-heroicon-o-fire class="h-5 w-5" />
              <span>{{ __('Send to kitchen') }}</span>
              @if ($cartCount > 0 && ($inBill ? $pending : $cartCount) > 0)
                <span aria-hidden="true" class="rounded-full bg-white/90 px-1.5 text-xs font-bold text-lakasir-primary-strong dark:bg-[#1f1209]/90 dark:text-lakasir-primary-soft">{{ $inBill ? $pending : $cartCount }}</span>
              @endif
            </span>
            <span class="text-xs font-normal opacity-90">{{ $sentOnce && $pending === 0 && $cancelPending === 0 ? __('Nothing new to send') : __('Save and print ticket') }}</span>
          </button>
          <button type="button" wire:click="saveBill" wire:loading.attr="disabled" wire:target="sendToKitchen,saveBill,printProvisional"
            @disabled($cartCount === 0 && ! $inBill) data-testid="save-bill-only"
            class="{{ $btnSecondary }}">
            {{ __('Save only') }}
          </button>
        </div>
        <div class="mt-2 grid grid-cols-[5fr_7fr] gap-2">
          <button type="button" wire:click="printProvisional" wire:loading.attr="disabled" wire:target="sendToKitchen,saveBill,printProvisional"
            @disabled($cartCount === 0) data-testid="provisional-bill"
            class="{{ $btnSecondary }}">
            <x-heroicon-o-printer class="h-5 w-5" />
            <span>{{ __('Provisional bill') }}</span>
          </button>
          <button type="button" x-on:click="$dispatch('open-modal', { id: 'proceed-the-payment' })"
            @disabled($cartCount === 0) data-testid="cart-pay"
            class="{{ $btnPrimary }} min-h-12 text-base">
            {{ __('Pay :amount', ['amount' => $fm($totals['amount_due'] ?? 0)]) }}
          </button>
        </div>
      @else
        <div class="grid grid-cols-2 gap-2">
          <button type="button" wire:click="saveBill" wire:loading.attr="disabled" wire:target="saveBill,printProvisional"
            @disabled($cartCount === 0 && ! $inBill) data-testid="save-bill"
            class="{{ $btnSecondary }}">
            <x-heroicon-o-bookmark class="h-5 w-5" />
            <span>{{ __('Save bill') }}</span>
          </button>
          <button type="button" wire:click="printProvisional" wire:loading.attr="disabled" wire:target="saveBill,printProvisional"
            @disabled($cartCount === 0) data-testid="provisional-bill"
            class="{{ $btnSecondary }}">
            <x-heroicon-o-printer class="h-5 w-5" />
            <span>{{ __('Provisional bill') }}</span>
          </button>
        </div>
        <button type="button" x-on:click="$dispatch('open-modal', { id: 'proceed-the-payment' })"
          @disabled($cartCount === 0) data-testid="cart-pay"
          class="{{ $btnPrimary }} mt-2 min-h-12 w-full text-base">
          {{ __('Pay :amount', ['amount' => $fm($totals['amount_due'] ?? 0)]) }}
        </button>
      @endif
    @else
      <button type="button" x-on:click="$dispatch('open-modal', { id: 'proceed-the-payment' })"
        @disabled($cartCount === 0) data-testid="cart-pay"
        class="{{ $btnPrimary }} min-h-12 w-full text-base">
        {{ __('Pay :amount', ['amount' => $fm($totals['amount_due'] ?? 0)]) }}
      </button>
    @endif
  </div>
@endif

@if ($part === 'dialogs')
  @php
    $dialogShell = 'fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4';
    $dialogPanel = 'relative max-h-[92dvh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-4 shadow-2xl sm:rounded-2xl dark:bg-gray-900';
  @endphp

  <div wire:ignore data-static-part="dialogs">
  @if ($renderStaticParts)
  {{-- Reduce or remove a line the kitchen already received: reason required, right checked again by the server --}}
  <div x-data="lkDialog('cancel-sent')" x-cloak x-show="open" x-on:keydown="onKey($event)"
    :data-lk-dialog-open="open ? '1' : '0'" class="{{ $dialogShell }}">
    <div class="absolute inset-0 bg-black/60" x-on:click="hide()" aria-hidden="true"></div>
    <div x-ref="panel" role="dialog" aria-modal="true" aria-labelledby="cancel-sent-title" aria-describedby="cancel-sent-message"
      data-testid="cancel-sent-dialog" class="{{ $dialogPanel }}">
      <h2 id="cancel-sent-title" class="text-lg font-bold">{{ __('Cancel sent item') }}</h2>
      <p id="cancel-sent-message" class="mt-1 text-sm" x-text="data.message"></p>

      <p x-show="! ($wire.openBill && $wire.openBill.can_cancel_sent)" x-cloak role="alert"
        class="mt-2 rounded-lg bg-danger-50 p-2 text-sm font-semibold text-danger-900 dark:bg-gray-800 dark:text-danger-300">
        {{ __('Only the owner or a manager can cancel items already sent to the kitchen') }}
      </p>

      <fieldset class="mt-3">
        <legend class="mb-1 text-sm font-semibold">{{ __('Void reason') }}</legend>
        <div class="flex flex-wrap gap-2">
          @foreach (['Wrong input' => __('Wrong input'), 'Customer cancelled' => __('Customer cancelled'), 'Out of stock' => __('Out of stock'), 'Other' => __('Other')] as $reasonKey => $reasonLabel)
            <button type="button" x-ref="{{ $loop->first ? 'initial' : 'r'.$loop->index }}" data-testid="cancel-reason-chip-{{ $loop->index }}"
              x-on:click="choice = @js($reasonKey)"
              :aria-pressed="choice === @js($reasonKey) ? 'true' : 'false'"
              :class="choice === @js($reasonKey) ? 'border-lakasir-primary-strong bg-lakasir-primary-strong text-white dark:border-lakasir-primary-soft dark:bg-lakasir-primary-soft dark:text-[#1f1209]' : 'border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-900'"
              class="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong">{{ $reasonLabel }}</button>
          @endforeach
        </div>
        <div x-show="choice === 'Other'" x-cloak class="mt-2">
          <label for="cancel-reason-text" class="sr-only">{{ __('Write the reason') }}</label>
          <textarea id="cancel-reason-text" rows="2" maxlength="255" data-testid="cancel-reason" x-model="text"
            placeholder="{{ __('Write the reason') }}"
            class="w-full rounded-lg border border-gray-300 bg-white p-2 text-sm focus:border-lakasir-primary-strong focus:outline-none focus:ring-1 focus:ring-lakasir-primary-strong dark:border-gray-600 dark:bg-gray-900"></textarea>
        </div>
      </fieldset>

      <div class="mt-4 grid grid-cols-2 gap-2">
        <button type="button" x-on:click="hide()" :disabled="busy" data-testid="cancel-back" class="{{ $btnSecondary }}">{{ __('Back') }}</button>
        <button type="button" data-testid="cancel-confirm"
          :disabled="busy || reason.length < 3 || ! ($wire.openBill && $wire.openBill.can_cancel_sent)"
          x-on:click="run(() => $wire.cancelSent(data.itemId, data.newQty, reason))"
          class="{{ $btn }} bg-danger-700 text-white hover:bg-danger-800 dark:bg-danger-500 dark:text-[#2a0a0a] dark:hover:bg-danger-400">
          <span x-text="Number(data.newQty) > 0 ? @js(__('Reduce')) : @js(__('Remove'))"></span>
        </button>
      </div>
    </div>
  </div>

  {{-- The bill moved on while this screen held an old version: only Reload (never "save as a new bill") --}}
  <div x-data="lkDialog('bill-conflict')" x-cloak x-show="open" x-on:keydown="onKey($event)"
    :data-lk-dialog-open="open ? '1' : '0'" class="{{ $dialogShell }}">
    <div class="absolute inset-0 bg-black/60" aria-hidden="true"></div>
    <div x-ref="panel" role="alertdialog" aria-modal="true" aria-labelledby="bill-conflict-title" aria-describedby="bill-conflict-message"
      data-testid="bill-conflict" class="{{ $dialogPanel }}">
      <h2 id="bill-conflict-title" class="text-lg font-bold">{{ __('Bill was changed on another device') }}</h2>
      <p id="bill-conflict-message" class="mt-2 text-sm">
        <span class="block font-semibold" x-text="data.label"></span>
        <span class="block">{{ __('New total') }}: <strong x-text="data.total"></strong></span>
      </p>
      <div class="mt-4">
        <button type="button" x-ref="initial" data-testid="bill-conflict-reload" :disabled="busy"
          x-on:click="run(() => $wire.openBill(data.id))"
          class="{{ $btnPrimary }} w-full">{{ __('Reload bill') }}</button>
      </div>
    </div>
  </div>

  {{-- No table and no name: the ticket will read TAKEAWAY. Confirm, or fill the table first. --}}
  <div x-data="lkDialog('takeaway')" x-cloak x-show="open" x-on:keydown="onKey($event)"
    :data-lk-dialog-open="open ? '1' : '0'" class="{{ $dialogShell }}">
    <div class="absolute inset-0 bg-black/60" x-on:click="hide()" aria-hidden="true"></div>
    <div x-ref="panel" role="dialog" aria-modal="true" aria-labelledby="takeaway-title"
      data-testid="takeaway-prompt" class="{{ $dialogPanel }}">
      <h2 id="takeaway-title" class="text-base font-bold" x-text="data.message"></h2>
      <div class="mt-4 grid grid-cols-2 gap-2">
        <button type="button" x-ref="initial" data-testid="takeaway-fill" :disabled="busy"
          x-on:click="hide(false); $dispatch('open-modal', { id: 'modal-selected-table' })"
          class="{{ $btnSecondary }}">{{ __('Fill the table') }}</button>
        <button type="button" data-testid="takeaway-confirm" :disabled="busy"
          x-on:click="run(() => $wire.sendToKitchen(null, true))"
          class="{{ $btnPrimary }}">{{ __('Send as takeaway') }}</button>
      </div>
    </div>
  </div>
  @endif
  </div>
@endif
