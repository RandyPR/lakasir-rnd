{{--
  Order summary of the payment sheet: lines grouped by category as "qty x unit = total", line discount, note,
  then the totals. Everything is rendered by the server from the lines on screen (cart or open bill), so it is
  the same data as the total. The list folds away (pay-items-toggle); the parent's paymentFlow() owns itemsOpen.
  Needs: $cartGroups, $cartCount, $totals, $priceStaleLines, $currency, $locale.
--}}
@php
  use App\Services\Tenants\OrderTotals;
  use App\Support\Money;
  use Illuminate\Support\Number;

  $payMoney = function (float|int|null $value) use ($currency, $locale): string {
    $value = (float) $value;

    return Number::currency($value, in: (string) $currency, locale: (string) $locale, precision: Money::eq($value, round($value)) ? 0 : 2);
  };

  $payQty = fn (float|int|null $qty): string => rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.');

  $payStale = array_map('intval', (array) ($priceStaleLines ?? []));
  $payTitledGroups = $cartGroups->count() >= 2;
  $payDiscountTotal = (float) $totals['line_discount'] + (float) $totals['header_discount'];
@endphp
<section class="rounded-xl border border-gray-200 dark:border-white/10" data-testid="pay-summary">
  <button type="button"
    class="flex min-h-[44px] w-full items-center justify-between gap-3 px-3 text-start text-sm font-semibold text-gray-800 dark:text-gray-100"
    data-testid="pay-items-toggle"
    aria-controls="pay-items"
    x-bind:aria-expanded="itemsOpen ? 'true' : 'false'"
    x-on:click="itemsOpen = !itemsOpen">
    <span>
      {{ __('Order items') }}
      <span class="font-normal text-gray-500 dark:text-gray-400">· {{ __(':count items', ['count' => $cartCount]) }}</span>
    </span>
    <span class="flex shrink-0 items-center gap-1 text-xs font-medium text-orange-700 dark:text-orange-400">
      <span x-show="!itemsOpen">{{ __('Show items') }}</span>
      <span x-show="itemsOpen" x-cloak>{{ __('Hide items') }}</span>
      <x-heroicon-o-chevron-down class="h-4 w-4" x-show="!itemsOpen" />
      <x-heroicon-o-chevron-up class="h-4 w-4" x-show="itemsOpen" x-cloak />
    </span>
  </button>

  <div id="pay-items" x-show="itemsOpen" x-cloak class="border-t border-gray-200 px-3 py-2 dark:border-white/10"
    data-testid="pay-items">
    @forelse ($cartGroups as $group)
      <div class="py-1" wire:key="pay-group-{{ $group['key'] }}">
        @if ($payTitledGroups)
          <p class="pt-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ $group['category'] ?: __('Uncategorized') }}
          </p>
        @endif
        <ul class="divide-y divide-gray-100 dark:divide-white/5">
          @foreach ($group['lines'] as $line)
            @php
              $payUnit = OrderTotals::unitPrice($line);
              $payLineTotal = OrderTotals::lineTotal($line);
              $payExact = OrderTotals::unitIsExact($line);
              $payLineDiscount = (float) ($line->discount_price ?? 0);
            @endphp
            <li class="py-2" data-testid="pay-line-{{ $line->getKey() }}" wire:key="pay-line-{{ $line->getKey() }}">
              <p class="break-words text-sm font-medium text-gray-900 dark:text-white">
                {{ $line->product?->name ?? '#'.$line->product_id }}
              </p>
              <div class="flex items-baseline justify-between gap-3 text-sm tabular-nums text-gray-600 dark:text-gray-300">
                <span class="min-w-0 break-words">
                  {{ $payQty($line->qty) }} x {{ $payExact ? '' : '~' }}{{ $payMoney($payUnit) }}
                </span>
                <span class="shrink-0 font-semibold text-gray-900 dark:text-white">{{ $payMoney($payLineTotal) }}</span>
              </div>
              @if ($payLineDiscount > 0)
                <p class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                  {{ __('Discount') }} (−{{ $payMoney($payLineDiscount) }})
                </p>
              @endif
              @if (filled($line->note))
                <p class="break-words text-xs text-gray-500 dark:text-gray-400">&gt; {{ $line->note }}</p>
              @endif
              @if (in_array((int) $line->getKey(), $payStale, true))
                <p class="text-xs text-amber-700 dark:text-amber-400">{{ __('The price changed since the item was ordered') }}</p>
              @endif
            </li>
          @endforeach
        </ul>
      </div>
    @empty
      <p class="py-3 text-center text-sm text-gray-500 dark:text-gray-400" data-testid="pay-no-items">{{ __('No item') }}</p>
    @endforelse

    <dl class="mt-2 space-y-1 border-t border-gray-200 pt-2 text-sm tabular-nums dark:border-white/10">
      <div class="flex justify-between gap-3">
        <dt class="text-gray-600 dark:text-gray-300">{{ __('Subtotal') }}</dt>
        <dd class="text-gray-900 dark:text-white">{{ $payMoney($totals['subtotal']) }}</dd>
      </div>
      @if ((float) $totals['tax_price'] > 0)
        <div class="flex justify-between gap-3">
          <dt class="text-gray-600 dark:text-gray-300">{{ __('Tax') }}</dt>
          <dd class="text-gray-900 dark:text-white">{{ $payMoney($totals['tax_price']) }}</dd>
        </div>
      @endif
      @if ($payDiscountTotal > 0)
        <div class="flex justify-between gap-3">
          <dt class="text-gray-600 dark:text-gray-300">{{ __('Discount') }}</dt>
          <dd class="text-gray-900 dark:text-white">−{{ $payMoney($payDiscountTotal) }}</dd>
        </div>
      @endif
      <div class="flex justify-between gap-3 border-t border-gray-200 pt-1 font-bold dark:border-white/10">
        <dt class="text-gray-900 dark:text-white">{{ __('Total') }}</dt>
        <dd class="text-gray-900 dark:text-white" data-testid="pay-summary-total">{{ $payMoney($totals['amount_due']) }}</dd>
      </div>
    </dl>
  </div>
</section>
