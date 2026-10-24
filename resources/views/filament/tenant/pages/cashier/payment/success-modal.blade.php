{{--
  Success dialog (modal id "success-modal") shown after a payment, or again for a payment the server already
  recorded (selling-created with alreadyPaid). Printing goes through window.LakasirReceipt only (receipt-print partial):
  the receipt button keeps its id printReceiptButton and prints window._lakasirLastSelling.

  Data comes from the browser event selling-created {selling, kitchenPrinted, alreadyPaid}; the event
  lakasir-payment-submit {bill, kind} (sent by the payment sheet) tells whether a bill was paid. The Alpine component
  paymentSuccess() is in payment/success-script.blade.php, loaded once with the page by cashier/scripts.blade.php.
--}}
<x-filament::modal
  id="success-modal"
  width="xl"
  aria-labelledby="pay-success-title"
  :close-button="false"
  :close-by-clicking-away="false"
>
  {{--
    The Alpine component wraps the content and the buttons, not <x-filament::modal>: attributes given to the modal land
    on its x-ref="modalContainer" element, and an x-data there would hide that ref from the modal's own open()/close().
    The buttons are part of the content (not the footer slot) so they share this component.
  --}}
  <div class="flex flex-col gap-6"
    x-data="paymentSuccess()"
    x-on:selling-created.window="onSold($event)"
    x-on:lakasir-payment-submit.window="onSubmitted($event)">
    <div class="flex flex-col items-center gap-3 text-center" data-testid="success-sheet">
      <x-heroicon-o-check-circle class="h-16 w-16 text-green-600" aria-hidden="true" />
      <h2 id="pay-success-title" class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Success') }}</h2>

      <p class="text-sm font-medium text-gray-600 dark:text-gray-300" role="status" data-testid="success-already-paid"
        x-show="alreadyPaid" x-cloak>{{ __('Payment already recorded') }}</p>

      {{-- Cash / exact: the change; credit: what is still owed --}}
      <div class="w-full rounded-xl bg-green-50 p-4 dark:bg-green-500/10" x-show="kind !== 'credit'" data-testid="success-change-box">
        <p class="text-xs font-semibold uppercase tracking-wide text-green-800 dark:text-green-300">{{ __('Change') }}</p>
        <p class="mt-1 break-words text-3xl font-bold tabular-nums text-gray-900 dark:text-white">
          <span id="changes" data-testid="success-change" x-text="fmt(change)"></span>
        </p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-show="methodName !== ''" x-text="methodName"></p>
      </div>
      <div class="w-full rounded-xl bg-amber-50 p-4 dark:bg-amber-500/10" x-show="kind === 'credit'" x-cloak data-testid="success-receivable-box">
        <p class="text-xs font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-300">{{ __('Receivable remaining') }}</p>
        <p class="mt-1 break-words text-3xl font-bold tabular-nums text-gray-900 dark:text-white" x-text="fmt(receivable)"></p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-show="memberName !== ''" x-text="memberName"></p>
      </div>

      <p class="inline-flex items-center gap-1 text-sm font-medium text-green-700 dark:text-green-400" role="status"
        data-testid="success-kitchen-printed" x-show="kitchenPrinted" x-cloak>
        <x-heroicon-o-check class="h-4 w-4" aria-hidden="true" />
        {{ __('Kitchen ticket printed too') }}
      </p>

      <pre class="max-h-64 w-full overflow-auto rounded-lg border border-gray-200 bg-white p-3 text-start font-mono text-[11px] leading-snug text-gray-900 dark:border-white/10"
        data-testid="success-preview" x-show="previewOpen" x-cloak x-text="previewText"></pre>
    </div>

    <div class="grid gap-2" data-testid="success-actions">
      <div class="grid grid-cols-2 gap-2">
        <x-filament::button icon="heroicon-m-printer" id="printReceiptButton" type="button" size="lg"
          x-on:click.prevent.stop="printReceipt()">
          {{ __('Print') }}
        </x-filament::button>
        <x-filament::button color="gray" type="button" size="lg" data-testid="success-preview-toggle"
          x-bind:aria-expanded="previewOpen ? 'true' : 'false'"
          x-on:click.prevent.stop="togglePreview()">
          {{ __('Preview') }}
        </x-filament::button>
      </div>
      @if ($kitchenEnabled)
        <x-filament::button color="gray" type="button" size="lg" data-testid="success-kitchen-print"
          x-show="canPrintKitchen" x-cloak
          x-on:click.prevent.stop="printKitchen()">
          {{ __('Print kitchen ticket') }}
        </x-filament::button>
      @endif
      <x-filament::button color="gray" type="button" size="lg" data-testid="success-close"
        x-on:click="$dispatch('close-modal', { id: 'success-modal' })">
        {{ __('Close') }}
      </x-filament::button>
    </div>
  </div>
</x-filament::modal>
