{{--
  Totals of the order on screen, from OrderTotals (the one formula the server pays with). Needs $totals, $tax, $openBill,
  $cartDetail, $fm, $fmQty. The big TOTAL is in the footer of the order panel (actions.blade.php).
--}}
@use('App\Features\SellingTax')
@php
  $taxPercent = (float) ($openBill['tax_percent'] ?? $tax ?? 0);
  $voucherCode = $cartDetail['voucher'] ?? null;
@endphp
<dl class="space-y-1 px-3 pb-2 text-sm" data-testid="order-totals">
  <div class="flex justify-between gap-2">
    <dt>{{ __('Subtotal') }} ({{ $fmQty($totals['qty'] ?? 0) }})</dt>
    <dd class="font-semibold" data-testid="cart-subtotal">{{ $fm($totals['subtotal'] ?? 0) }}</dd>
  </div>
  @if (($totals['line_discount'] ?? 0) > 0)
    <div class="flex justify-between gap-2">
      <dt>{{ __('Discount') }}</dt>
      <dd class="font-semibold">({{ $fm($totals['line_discount']) }})</dd>
    </div>
  @endif
  @feature(SellingTax::class)
    <div class="flex justify-between gap-2">
      <dt>{{ __('Tax') }} {{ rtrim(rtrim(number_format($taxPercent, 3, ',', ''), '0'), ',') }}%</dt>
      <dd class="font-semibold">{{ $fm($totals['tax_price'] ?? 0) }}</dd>
    </div>
  @endfeature
  @if (($totals['header_discount'] ?? 0) > 0)
    <div class="flex justify-between gap-2">
      <dt>{{ filled($voucherCode) ? __('Voucher').' '.$voucherCode : __('Discount price') }}</dt>
      <dd class="font-semibold">({{ $fm($totals['header_discount']) }})</dd>
    </div>
  @endif
</dl>
