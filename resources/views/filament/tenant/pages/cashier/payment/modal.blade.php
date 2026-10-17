{{--
  Payment sheet (modal id "proceed-the-payment"): a full-screen sheet on phones, a 5xl dialog on tablets and desktops.
  Included by cashier.blade.php. All state lives in the Alpine component paymentFlow() (payment/script.blade.php);
  the server renders only what it knows: the summary, the member list and the problems of the last attempt.

  Opening the sheet runs $wire.syncCartForPayment() (unless the opener already did), closing it runs
  $wire.paymentClosed(); both are done by paymentFlow().

  Needs: $cartGroups, $cartCount, $totals, $canOverrideStock, $priceStaleLines, $members, $openBill, $openBillId,
  $kitchenEnabled, $kitchenPreview, $cartDetail, $cartItems, $currency, $locale, $errors.
--}}
@include('filament.tenant.pages.cashier.payment.script')
@php
  use App\Support\Money;
  use Illuminate\Support\Number;

  $payBill = is_array($openBill ?? null) ? $openBill : null;

  if ($payBill) {
    $payNumberText = __('Bill #:number', ['number' => str_pad((string) ($payBill['number'] ?? 0), 3, '0', STR_PAD_LEFT)]);
    $payLabelText = (string) ($payBill['label'] ?? '');
    $payContext = $payLabelText === '' || $payLabelText === $payNumberText ? $payNumberText : $payNumberText.' · '.$payLabelText;
    if (filled($payBill['business_date_label'] ?? null)) {
      $payContext .= ' · '.$payBill['business_date_label'];
    }
  } else {
    $payContext = filled($cartDetail['customer_name'] ?? null) ? (string) $cartDetail['customer_name'] : __('New order');
    if (filled($cartDetail['table_label'] ?? null)) {
      $payContext .= ' · '.__('Table').' '.$cartDetail['table_label'];
    }
  }

  $payDiscountTotal = (float) $totals['line_discount'] + (float) $totals['header_discount'];
  $payMeta = __(':count items', ['count' => $cartCount]);
  if ($payDiscountTotal > 0) {
    $payMeta .= ' · '.__('Discount').' −'.Number::currency(
      $payDiscountTotal,
      in: (string) $currency,
      locale: (string) $locale,
      precision: Money::eq($payDiscountTotal, round($payDiscountTotal)) ? 0 : 2,
    );
  }

  // Problems of the last attempt, next to the fields they belong to. (Other forms of the page are left alone.)
  $payErrorKeys = ['payed_money', 'member_id', 'due_date', 'voucher', 'cash_drawer', 'payment_method_id'];
  $payErrors = collect($errors->getMessages())
    ->filter(fn ($messages, $key): bool => in_array($key, $payErrorKeys, true) || str_starts_with((string) $key, 'products.'))
    ->flatten()
    ->unique()
    ->values();

  // A stock refusal of a line the kitchen already served can be overridden by whoever may (owner / update selling).
  // The server names a line by its position among the bill lines ordered by id (products.N.qty).
  $payOverride = false;
  if ($payBill && ($canOverrideStock ?? false)) {
    $payBillLines = $cartItems->sortBy('id')->values();
    foreach (array_keys($errors->getMessages()) as $payKey) {
      if (preg_match('/^products\.(\d+)\.qty$/', (string) $payKey, $payMatch)) {
        $payLine = $payBillLines->get((int) $payMatch[1]);
        if ($payLine && Money::qty((float) ($payLine->sent_qty ?? 0)) > 0) {
          $payOverride = true;
        }
      }
    }
  }

  $payPending = $kitchenEnabled && $payBill ? (int) ($kitchenPreview['pending'] ?? 0) : 0;

  $payStaleNames = collect((array) ($priceStaleLines ?? []))->isEmpty()
    ? collect()
    : $cartItems
      ->filter(fn ($line): bool => in_array((int) $line->getKey(), array_map('intval', (array) $priceStaleLines), true))
      ->map(fn ($line): string => (string) ($line->product?->name ?? '#'.$line->product_id))
      ->values();
@endphp

<style>
  div.pay-modal > .fi-modal-window { max-height: calc(100dvh - 2rem); overflow: hidden; }
  div.pay-modal .fi-modal-content { display: flex; flex: 1 1 auto; flex-direction: column; gap: 0; min-height: 0; padding: 0; }
  div.pay-modal [data-pay-display] { caret-color: transparent; }
  div.pay-modal .pay-key { touch-action: manipulation; -webkit-tap-highlight-color: transparent; user-select: none; }
  @media (max-width: 639.98px) {
    div.pay-modal { grid-template-rows: 1fr; padding: 0; }
    div.pay-modal > .fi-modal-window {
      border-radius: 0;
      grid-row-start: 1;
      height: 100dvh;
      margin: 0;
      max-height: 100dvh;
      max-width: none;
    }
  }
</style>

<x-filament::modal
  id="proceed-the-payment"
  width="5xl"
  class="pay-modal"
  aria-labelledby="pay-title"
  :close-button="false"
  :close-by-clicking-away="false"
  :close-by-escaping="false"
  x-data="paymentFlow()"
  x-on:open-modal.window="onOpenModal($event)"
  x-on:close-modal.window="onCloseModal($event)"
  x-on:bill-conflict.window="onConflict($event)"
  x-on:keydown.window="onKey($event)"
>
  <div class="flex min-h-0 flex-1 flex-col" data-testid="pay-sheet">
    {{-- Header --}}
    <header class="flex flex-none items-center gap-3 border-b border-gray-200 px-3 py-2 dark:border-white/10">
      <button type="button"
        class="inline-flex h-11 w-11 flex-none items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 disabled:opacity-40 dark:text-gray-300 dark:hover:bg-white/10"
        data-testid="pay-close"
        aria-label="{{ __('Back') }}"
        x-bind:disabled="submitting"
        x-on:click="closePayment()">
        <x-heroicon-o-arrow-left class="h-6 w-6" />
      </button>
      <div class="min-w-0 flex-1">
        <h2 id="pay-title" class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Payment') }}</h2>
        <p class="truncate text-xs text-gray-500 dark:text-gray-400" data-testid="pay-context">{{ $payContext }}</p>
      </div>
    </header>

    {{-- Body --}}
    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4">
      <div class="grid gap-4 md:grid-cols-[5fr_6fr] md:grid-rows-[auto_1fr] md:gap-x-6">
        {{-- Left: total, method tiles, kitchen option --}}
        <div class="space-y-4 md:col-start-1 md:row-start-1">
          <section class="rounded-xl bg-orange-50 p-4 dark:bg-orange-500/10" data-testid="pay-total-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-orange-800 dark:text-orange-300">{{ __('Amount due') }}</p>
            <p class="mt-1 break-words text-[1.6rem] font-bold tabular-nums leading-tight text-gray-900 dark:text-white min-[360px]:text-[1.9rem] md:text-[2.1rem]"
              data-testid="pay-total" x-text="fmt(total)"></p>
            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300" data-testid="pay-total-meta">{{ $payMeta }}</p>
          </section>

          <fieldset class="min-w-0">
            <legend class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Payment method') }}</legend>
            <div class="mt-2 grid grid-cols-2 gap-2 min-[360px]:grid-cols-4 md:grid-cols-2" role="group" x-ref="methods">
              <template x-for="m in methods" x-bind:key="m.id">
                <button type="button"
                  class="flex min-h-[56px] flex-col items-center justify-center gap-1 rounded-xl border px-2 py-2 text-center text-xs font-semibold leading-tight transition-colors disabled:opacity-60 min-[360px]:text-sm"
                  x-bind:class="String(m.id) === String(methodId)
                    ? 'border-orange-600 bg-orange-50 text-orange-900 ring-2 ring-orange-600 dark:bg-orange-500/15 dark:text-orange-100'
                    : 'border-gray-300 bg-white text-gray-800 hover:bg-gray-50 dark:border-white/15 dark:bg-white/5 dark:text-gray-100 dark:hover:bg-white/10'"
                  x-bind:data-testid="'pay-method-' + m.id"
                  x-bind:data-kind="kindOf(m)"
                  x-bind:aria-pressed="String(m.id) === String(methodId) ? 'true' : 'false'"
                  x-bind:disabled="submitting"
                  x-on:click="selectMethod(m)">
                  <x-heroicon-o-banknotes class="h-5 w-5 flex-none" x-show="kindOf(m) === 'cash'" />
                  <x-heroicon-o-qr-code class="h-5 w-5 flex-none" x-show="kindOf(m) === 'exact' && !m.is_debit" x-cloak />
                  <x-heroicon-o-credit-card class="h-5 w-5 flex-none" x-show="kindOf(m) === 'exact' && m.is_debit" x-cloak />
                  <x-heroicon-o-document-text class="h-5 w-5 flex-none" x-show="kindOf(m) === 'credit'" x-cloak />
                  <span class="max-w-full break-words" x-text="m.name"></span>
                </button>
              </template>
            </div>
          </fieldset>

          @if ($payPending > 0)
            <div class="space-y-2" data-testid="pay-kitchen-box">
              <button type="button" role="switch"
                class="flex min-h-[44px] w-full items-center gap-3 rounded-xl border border-gray-300 p-3 text-start disabled:opacity-60 dark:border-white/15"
                data-testid="pay-kitchen-switch"
                x-bind:aria-checked="printKitchen ? 'true' : 'false'"
                x-bind:disabled="submitting"
                x-on:click="printKitchen = !printKitchen">
                <span class="relative inline-flex h-6 w-11 flex-none items-center rounded-full transition-colors"
                  x-bind:class="printKitchen ? 'bg-orange-700' : 'bg-gray-300 dark:bg-gray-600'" aria-hidden="true">
                  <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform"
                    x-bind:class="printKitchen ? 'translate-x-5' : 'translate-x-0.5'"></span>
                </span>
                <span class="text-sm font-medium text-gray-900 dark:text-white">
                  {{ __('Print kitchen ticket now (:n unsent changes)', ['n' => $payPending]) }}
                </span>
              </button>
              <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
                role="status" data-testid="pay-kitchen-warning" x-show="!printKitchen" x-cloak>
                {{ __(':n changes will NOT be told to the kitchen', ['n' => $payPending]) }}
              </p>
            </div>
          @endif

          @if ($payStaleNames->isNotEmpty())
            <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
              role="status" data-testid="pay-price-stale">
              {{ __('The price changed since the item was ordered') }}: {{ $payStaleNames->join(', ') }}
            </p>
          @endif
        </div>

        {{-- Right: the panel of the chosen method --}}
        <div class="space-y-4 md:col-start-2 md:row-span-2 md:row-start-1" data-testid="pay-panel">
          {{-- Cash --}}
          <div class="space-y-3" x-show="kind === 'cash'" data-testid="pay-cash-panel">
            <div>
              <label for="pay-received" class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Cash received') }}</label>
              <div class="relative mt-1">
                <input id="pay-received" type="text" readonly inputmode="none" autocomplete="off" placeholder="0"
                  class="block h-16 w-full rounded-xl border-2 border-gray-300 bg-white pe-12 ps-3 text-end text-[1.7rem] font-bold tabular-nums text-gray-900 focus:border-orange-600 focus:ring-0 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                  data-testid="pay-received" data-pay-display
                  x-bind:value="received === '' ? '' : fmtNum(receivedNum)"
                  x-bind:disabled="submitting">
                <button type="button"
                  class="pay-key absolute end-1 top-1/2 inline-flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-40 dark:hover:bg-white/10"
                  aria-label="{{ __('Clear') }}" data-testid="pay-numpad-clear"
                  x-show="received !== ''" x-cloak
                  x-bind:disabled="submitting"
                  x-on:mousedown.prevent
                  x-on:click="press('clear')">
                  <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
              </div>
              <p class="mt-1 min-h-[1.5rem] text-sm font-semibold tabular-nums" data-testid="pay-change"
                x-bind:data-tone="changeLine.tone"
                x-bind:class="changeLine.tone === 'good' ? 'text-green-700 dark:text-green-400' : (changeLine.tone === 'bad' ? 'text-red-700 dark:text-red-400' : 'text-gray-500')"
                x-text="changeLine.text"></p>
            </div>

            <div class="space-y-1.5" role="group" aria-label="{{ __('Cash received') }}">
              {{-- Fixed notes: every tap ADDS to the amount received (20.000 + 50.000 = 70.000) --}}
              <div class="grid grid-cols-4 gap-1.5">
                <button type="button"
                  class="pay-key h-11 min-w-0 truncate rounded-lg border border-orange-300 bg-orange-50 px-1 text-[13px] font-bold text-orange-900 active:bg-orange-100 disabled:opacity-50 min-[360px]:text-sm dark:border-orange-500/40 dark:bg-orange-500/10 dark:text-orange-200"
                  data-testid="pay-quick-exact"
                  x-bind:disabled="submitting"
                  x-on:mousedown.prevent
                  x-on:click="quick(exactAmount)">{{ __('Exact amount') }}</button>
                <template x-for="amount in denominations" x-bind:key="'d' + amount">
                  <button type="button"
                    class="pay-key h-11 min-w-0 truncate rounded-lg border px-1 text-[12px] font-semibold tabular-nums tracking-tight active:bg-gray-200 disabled:opacity-50 min-[360px]:text-sm border-gray-300 bg-gray-50 text-gray-900 dark:border-white/15 dark:bg-white/5 dark:text-white"
                    x-bind:data-testid="'pay-add-' + amount"
                    x-bind:disabled="submitting"
                    x-on:mousedown.prevent
                    x-on:click="add(amount)"
                    x-text="'+' + fmtNum(amount)"></button>
                </template>
              </div>
              {{-- Smart suggestions (the next note above the total) set the amount; Clear empties it --}}
              <div class="grid grid-cols-3 gap-1.5">
                <template x-for="amount in suggestions" x-bind:key="'s' + amount">
                  <button type="button"
                    class="pay-key h-11 min-w-0 truncate rounded-lg border px-1 text-[12px] font-semibold tabular-nums tracking-tight active:bg-gray-200 disabled:opacity-50 min-[360px]:text-sm border-gray-300 bg-white text-gray-900 dark:border-white/15 dark:bg-transparent dark:text-white"
                    x-bind:data-testid="'pay-quick-' + amount"
                    x-bind:disabled="submitting"
                    x-on:mousedown.prevent
                    x-on:click="quick(amount)"
                    x-text="fmtNum(amount)"></button>
                </template>
                <button type="button"
                  class="pay-key h-11 min-w-0 truncate rounded-lg border px-1 text-[12px] font-semibold tabular-nums tracking-tight active:bg-gray-200 disabled:opacity-50 min-[360px]:text-sm border-gray-300 bg-white text-gray-900 dark:border-white/15 dark:bg-transparent dark:text-white"
                  data-testid="pay-quick-clear"
                  x-bind:disabled="submitting || received === ''"
                  x-on:mousedown.prevent
                  x-on:click="press('clear')">{{ __('Clear') }}</button>
              </div>
            </div>
          </div>

          {{-- Exact (wallet, debit, ...): no amount from the client --}}
          <div class="space-y-3" x-show="kind === 'exact'" x-cloak data-testid="pay-exact-panel">
            <div class="rounded-xl border border-dashed border-gray-300 p-5 text-center dark:border-white/20">
              <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Exact payment') }}</p>
              <p class="mt-1 break-words text-2xl font-bold tabular-nums text-gray-900 dark:text-white" x-text="fmt(total)"></p>
              <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-text="method ? method.name : ''"></p>
            </div>
            <button type="button" role="checkbox"
              class="flex min-h-[56px] w-full items-center gap-3 rounded-xl border p-3 text-start disabled:opacity-60"
              x-bind:class="exactConfirmed ? 'border-green-600 bg-green-50 dark:bg-green-500/10' : 'border-gray-300 dark:border-white/15'"
              data-testid="pay-exact"
              x-bind:aria-checked="exactConfirmed ? 'true' : 'false'"
              x-bind:disabled="submitting"
              x-on:click="toggleExact()">
              <span class="inline-flex h-7 w-7 flex-none items-center justify-center rounded-md border-2"
                x-bind:class="exactConfirmed ? 'border-green-600 bg-green-600 text-white' : 'border-gray-400 text-transparent'" aria-hidden="true">
                <x-heroicon-o-check class="h-5 w-5" />
              </span>
              <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Confirm payment received') }}</span>
            </button>
          </div>

          {{-- Credit: member, due date, down payment --}}
          <div class="space-y-3" x-show="kind === 'credit'" x-cloak data-testid="pay-credit-panel">
            <div>
              <label for="pay-member" class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Member') }}</label>
              <select id="pay-member" class="mt-1 block h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-orange-600 focus:ring-orange-600 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                data-testid="pay-member" x-model="memberId" x-bind:disabled="submitting">
                <option value="">{{ __('Member') }}</option>
                @foreach ($members as $memberId => $memberName)
                  <option value="{{ $memberId }}">{{ $memberName }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="pay-due" class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Due date') }}</label>
              <input id="pay-due" type="date" class="mt-1 block h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-orange-600 focus:ring-orange-600 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                data-testid="pay-due" x-model="dueDate" x-bind:min="today" x-bind:disabled="submitting">
            </div>
            <div>
              <label for="pay-down" class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Down payment') }}</label>
              <div class="relative mt-1">
                <input id="pay-down" type="text" readonly inputmode="none" autocomplete="off" placeholder="0"
                  class="block h-14 w-full rounded-xl border-2 border-gray-300 bg-white pe-12 ps-3 text-end text-2xl font-bold tabular-nums text-gray-900 focus:border-orange-600 focus:ring-0 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                  data-testid="pay-down" data-pay-display
                  x-bind:value="down === '' ? '' : fmtNum(downNum)"
                  x-bind:disabled="submitting">
                <button type="button"
                  class="pay-key absolute end-1 top-1/2 inline-flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-40 dark:hover:bg-white/10"
                  aria-label="{{ __('Clear') }}" data-testid="pay-down-clear"
                  x-show="down !== ''" x-cloak
                  x-bind:disabled="submitting"
                  x-on:mousedown.prevent
                  x-on:click="press('clear')">
                  <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
              </div>
              <p class="mt-1 text-xs font-medium text-red-700 dark:text-red-400" role="status" data-testid="pay-down-limit"
                x-show="downLimit" x-cloak>{{ __('Down payment cannot exceed the total') }}</p>
            </div>
            <p class="flex items-baseline justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5" data-testid="pay-receivable">
              <span class="text-gray-600 dark:text-gray-300">{{ __('Receivable remaining') }}</span>
              <span class="font-bold tabular-nums text-gray-900 dark:text-white" x-text="fmt(remaining)"></span>
            </p>
          </div>

          {{-- Numpad for cash and for the down payment: no system keyboard --}}
          <div class="grid grid-cols-3 gap-2" role="group" aria-label="{{ __('Cash received') }}"
            x-show="kind === 'cash' || kind === 'credit'" data-testid="pay-numpad">
            @foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', '000', '0'] as $payKeyName)
              <button type="button"
                class="pay-key h-12 rounded-xl bg-gray-100 text-xl font-semibold tabular-nums text-gray-900 active:bg-gray-200 disabled:opacity-50 md:h-14 dark:bg-white/10 dark:text-white dark:active:bg-white/20"
                data-testid="pay-numpad-{{ $payKeyName }}"
                x-bind:disabled="submitting"
                x-on:mousedown.prevent
                x-on:click="press('{{ $payKeyName }}')">{{ $payKeyName }}</button>
            @endforeach
            <button type="button"
              class="pay-key inline-flex h-12 items-center justify-center rounded-xl bg-gray-100 text-gray-800 active:bg-gray-200 disabled:opacity-50 md:h-14 dark:bg-white/10 dark:text-gray-100 dark:active:bg-white/20"
              aria-label="{{ __('Clear') }}" data-testid="pay-numpad-backspace"
              x-bind:disabled="submitting"
              x-on:mousedown.prevent
              x-on:click="press('backspace')">
              <x-heroicon-o-backspace class="h-6 w-6" />
            </button>
          </div>
        </div>

        {{-- Left, below: what is being paid --}}
        <div class="md:col-start-1 md:row-start-2">
          @include('filament.tenant.pages.cashier.payment.summary')
        </div>
      </div>
    </div>

    {{-- Footer: problems, status, pay button --}}
    <footer class="flex-none space-y-2 border-t border-gray-200 bg-white px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 dark:border-white/10 dark:bg-gray-900">
      <div class="flex flex-col gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
        role="alert" data-testid="pay-conflict" x-show="conflict" x-cloak>
        <p class="font-medium" x-text="conflictText"></p>
        <button type="button"
          class="inline-flex h-11 flex-none items-center justify-center rounded-lg bg-orange-700 px-4 text-sm font-bold text-white hover:bg-orange-800 disabled:opacity-60"
          data-testid="pay-conflict-reload"
          x-bind:disabled="reloading"
          x-on:click="reloadBill()">{{ __('Reload bill') }}</button>
      </div>

      <div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-800 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200"
        role="alert" data-testid="pay-error"
        x-show="error !== ''@if ($payErrors->isNotEmpty()) || !staleErrors @endif" x-cloak>
        @if ($payErrors->isNotEmpty())
          <ul class="space-y-1" x-ref="serverErrors">
            @foreach ($payErrors as $payMessage)
              <li>{{ $payMessage }}</li>
            @endforeach
          </ul>
        @endif
        <p x-show="error !== ''" x-cloak x-text="error"></p>
      </div>

      @if ($payOverride)
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-1 dark:border-amber-500/40 dark:bg-amber-500/10" x-ref="overridePanel" data-testid="pay-stock-override-box">
          <button type="button" role="switch"
            class="flex min-h-[44px] w-full items-center gap-3 rounded-md p-2 text-start disabled:opacity-60"
            data-testid="pay-stock-override"
            x-bind:aria-checked="stockOverride ? 'true' : 'false'"
            x-bind:disabled="submitting"
            x-on:click="stockOverride = !stockOverride">
            <span class="relative inline-flex h-6 w-11 flex-none items-center rounded-full transition-colors"
              x-bind:class="stockOverride ? 'bg-orange-700' : 'bg-gray-300 dark:bg-gray-600'" aria-hidden="true">
              <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform"
                x-bind:class="stockOverride ? 'translate-x-5' : 'translate-x-0.5'"></span>
            </span>
            <span class="text-sm font-medium text-amber-900 dark:text-amber-100">
              {{ __('Continue even though stock is short (stock goes negative)') }}
            </span>
          </button>
        </div>
      @endif

      <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between md:gap-4">
        <p class="min-h-[1.25rem] text-sm font-semibold tabular-nums md:flex-1" role="status" aria-live="polite"
          data-testid="pay-status"
          x-bind:data-tone="status.tone"
          x-bind:class="{
            'text-green-700 dark:text-green-400': status.tone === 'good',
            'text-red-700 dark:text-red-400': status.tone === 'bad',
            'text-gray-600 dark:text-gray-300': status.tone === 'muted' || status.tone === 'busy',
          }"
          x-text="status.text"></p>
        <button type="button"
          class="inline-flex h-14 w-full items-center justify-center gap-2 rounded-xl px-4 text-base font-bold transition-colors md:w-72"
          x-bind:class="canSubmit ? 'bg-orange-700 text-white hover:bg-orange-800 active:bg-orange-900' : 'cursor-not-allowed bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400'"
          data-testid="pay-submit"
          x-bind:disabled="!canSubmit"
          x-bind:aria-busy="submitting ? 'true' : 'false'"
          x-on:click="submitPayment()">
          <x-filament::loading-indicator class="h-5 w-5" x-show="submitting" x-cloak />
          <span x-text="submitLabel"></span>
        </button>
      </div>
    </footer>
  </div>
</x-filament::modal>
