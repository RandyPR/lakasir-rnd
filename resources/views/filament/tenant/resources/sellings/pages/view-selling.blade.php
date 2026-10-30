@php
  use App\Models\Tenants\{Profile, Setting, About};
  use App\Services\Tenants\OrderLineSorter;

  $lineGroups = OrderLineSorter::group($record->sellingDetails);
  $showGroupTitles = $lineGroups->count() >= 2;
@endphp
<x-filament-panels::page>
  <x-filament::section id="printElement">
    <div class="flex">
      <div class="w-full print:w-1/3 md:w-1/3 px-2">
        <div>
          <p class="font-semibold text-2xl">@lang('Selling details')</p>
          <div class="details">
            <ul class="my-1">
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Code')</span><span>#{{ $record->code }}</span></li>
              @if($record->daily_order_number)
                <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Daily Order')</span><span class="font-bold text-primary-600 dark:text-primary-400">{{ $record->formatted_daily_order_number }}</span></li>
              @endif
              @if(About::first() && About::first()->business_type == 'fnb')
                <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Table')</span><span>{{ $record->table?->number ?? 'N/A' }}</span></li>
              @endif
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Cashier')</span><span>{{ $record->user->name }}</span></li>
              @if($record->customer_name)
                <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Customer')</span><span>{{ $record->customer_name }}</span></li>
              @endif
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Date')</span><span>{{ now()->parse($record->date)->setTimezone(Profile::get()->timezone ?? 'UTC')->format('d F Y H:i') }}</span></li>
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Payment method')</span><span>{{ $record->paymentMethod->name }}</span></li>
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Voucher')</span><span>{{ $record->voucher ?? 'N/A' }}</span></li>
              @if($record->note_plain)
                <li class="text-secondary text-sm mb-1"><span class="font-semibold">@lang('Order note')</span><p class="whitespace-pre-line break-words">{{ $record->note_plain }}</p></li>
              @endif
            </ul>
          </div>
        </div>
      </div>
      <div class="w-full print:w-1/3 md:w-1/3 px-2">
        <div>
          <p class="font-semibold text-2xl">@lang('Member details')</p>
          <div class="details">
            <ul class="my-1">
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Name')</span><span>{{ $record->member?->name ?? 'N/A' }}</span></li>
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Code')</span><span>{{ $record->member?->code ?? 'N/A' }}</span></li>
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Joined date')</span><span>{{ $record->member?->joined_date ? now()->parse($record->member?->joined_date)->setTimezone(Profile::get()->timezone ?? 'UTC')->format('d F Y H:i') : 'N/A' }}</span></li>
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Identity type')</span><span>{{ $record->member?->identity_type ?? 'N/A' }}</span></li>
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Identity number')</span><span>{{ $record->member?->identity_number ?? 'N/A' }}</span></li>
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Contact')</span><span>{{ $record->member?->email ?? 'N/A' }}</span></li>
              <li class="flex justify-between text-secondary text-sm mb-1"><span class="font-semibold">@lang('Address')</span><span>{{ $record->member?->address ?? 'N/A' }}</span></li>
              <!---->
            </ul>
          </div>
        </div>
      </div>
    </div>
    <div class="table w-full my-4">
      <table class="table ns-table w-full">
        <thead class="text-secondary">
          <tr>
            <th width="400" class="p-2 border">@lang('Product')</th>
            <th width="200" class="p-2 border">@lang('Unit price')</th>
            <th width="200" class="p-2 border">@lang('Quantity')</th>
            <th width="200" class="p-2 border">@lang('Discount')</th>
            <th width="200" class="p-2 border">@lang('Total price')</th>
          </tr>
        </thead>
        <tbody>
          @foreach($lineGroups as $group)
            @if($showGroupTitles)
              <tr>
                <td colspan="5" class="p-2 border bg-gray-50 dark:bg-white/5 text-xs font-semibold uppercase tracking-wide text-secondary">{{ $group['category'] ?? __('Uncategorized') }}</td>
              </tr>
            @endif
            @foreach($group['lines'] as $detail)
              <tr>
                <td class="p-2 border">
                  <h3 class="text-primary">{{ $detail->product?->name ?? '#'.$detail->product_id }}</h3>
                  @if($detail->note)
                    <span class="block text-sm text-secondary whitespace-pre-line break-words">{{ $detail->note }}</span>
                  @endif
                </td>
                <td class="p-2 border text-center text-primary">{{ Number::currency($detail->price_per_unit, Setting::get('currency', 'IDR')) }}</td>
                <td class="p-2 border text-center text-primary">{{ $detail->qty }}</td>
                <td class="p-2 border text-center text-primary">{{ Number::currency($detail->discount_price, Setting::get('currency', 'IDR')) }}</td>
                <td class="p-2 border text-center text-primary">{{ Number::currency($detail->total_price, Setting::get('currency', 'IDR')) }}</td>
              </tr>
            @endforeach
          @endforeach
        </tbody>
        <tfoot class="font-semibold">
          <tr>
            <td class="p-2 border text-center text-primary" colspan="3"></td>
            <td class="p-2 border text-primary text-left">@lang('Subtotal')</td>
            <td class="p-2 border text-right text-primary">{{ Number::currency($record->total_price, Setting::get('currency', 'IDR')) }}</td>
          </tr>
          <tr>
            <td class="p-2 border text-center text-primary" colspan="3"></td>
            <td class="p-2 border text-primary text-left">@lang('Discount')</td>
            <td class="p-2 border text-right text-primary">{{ Number::currency($record->total_discount_per_item + $record->discount_price, Setting::get('currency', 'IDR')) }}</td>
          </tr>
          <!---->
          <tr>
            <td class="p-2 border text-center text-primary" colspan="3"></td>
            <td class="p-2 border text-primary text-left">@lang('Tax')</td>
            <td class="p-2 border text-right text-primary">{{ $record->tax }}%</td>
          </tr>
          <tr>
            <td class="p-2 border text-center text-primary" colspan="3"></td>
            <td class="p-2 border text-primary text-left">@lang('Tax price')</td>
            <td class="p-2 border text-right text-primary">{{ Number::currency($record->tax_price, Setting::get('currency', 'IDR')) }}</td>
          </tr>
          <tr>
            <td class="p-2 border text-center text-primary" colspan="3"></td>
            <td class="p-2 border text-primary text-left">@lang('Total')</td>
            <td class="p-2 border text-right text-primary">{{ Number::currency($record->grand_total_price, Setting::get('currency', 'IDR')) }}</td>
          </tr>
          <tr>
            <td class="p-2 border text-center text-primary" colspan="3"></td>
            <td class="p-2 border text-primary text-left">@lang('Payed money')</td>
            <td class="p-2 border text-right text-primary">{{ Number::currency($record->payed_money, Setting::get('currency', 'IDR')) }}</td>
          </tr>
          <tr>
            <td class="p-2 border text-center text-primary" colspan="3"></td>
            <td class="p-2 border text-primary text-left">@lang('Money changes')</td>
            <td class="p-2 border text-right text-primary">{{ Number::currency($record->money_changes, Setting::get('currency', 'IDR')) }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </x-filament::section>
</x-filament-panels::page>
<script>
  window._lakasirViewSelling = @js($record);

  window.handlePrintInvoice = function() {
    if (window._lakasirHandlingInvoice) return;
    window._lakasirHandlingInvoice = true;
    try {
      const printContents = document.getElementById("printElement").innerHTML;
      document.body.innerHTML = printContents;
      window.print();
      window.location.reload();
    } finally {
      setTimeout(() => { window._lakasirHandlingInvoice = false; }, 2000);
    }
  };

  // The ESC/POS builder lives in the shared receipt-print partial (window.LakasirReceipt).
  window.handlePrintReceipt = () => window.LakasirReceipt
    ? window.LakasirReceipt.print(window._lakasirViewSelling, { buttonId: 'printButton', reprint: true })
    : Promise.resolve(false);

  // The kitchen ticket of this sale again: all its items, marked COPY. The page toasts a failed print (toast: true).
  window.handlePrintKitchen = () => window.LakasirReceipt
    ? window.LakasirReceipt.print(window._lakasirViewSelling, {
      mode: 'kitchen',
      reprint: true,
      toast: true,
      buttonId: 'printKitchenButton',
      jobKey: 'kitchen-view-' + window._lakasirViewSelling.id,
    })
    : Promise.resolve(false);

  // Ensure button binding is immediate
  const attachPrintBtn = () => {
    const btn = document.getElementById('printButton');
    if (btn) {
      btn.onclick = window.handlePrintReceipt;
    }
    const kitchenBtn = document.getElementById('printKitchenButton');
    if (kitchenBtn) {
      kitchenBtn.onclick = window.handlePrintKitchen;
    }
    const invBtn = document.getElementById('printInvoice');
    if (invBtn) {
      invBtn.onclick = window.handlePrintInvoice;
    }
  };
  attachPrintBtn();
  document.addEventListener('DOMContentLoaded', attachPrintBtn);
  document.addEventListener('livewire:navigated', attachPrintBtn);
  document.addEventListener('livewire:initialized', attachPrintBtn);
</script>
