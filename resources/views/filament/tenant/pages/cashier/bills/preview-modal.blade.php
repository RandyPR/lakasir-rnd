{{--
  The print preview ("Pratinjau cetak") of a bill: the exact text the printer would print, 32 or 48 columns
  following the saved printer. Included once by the cashier page (id receipt-preview-modal).

  Opens on the browser event `bill-preview {mode:'bill'|'kitchen', bill}` (sent by Livewire OpenBills / Cashier), and
  also on the Filament-style `open-modal {id:'receipt-preview-modal'}`. It fills <pre id="receipt-preview-text"> itself
  from LakasirReceipt.preview(), so the page bootstrap does not have to (doing it too is harmless: same text).
  Print uses LakasirReceipt.print() with the same payload and mode. A kitchen ticket that has NOT been sent yet can
  be previewed but not printed from here: printing it would not mark it as sent, and the next "Send to kitchen"
  would print it a second time. A copy of the last sent ticket (kitchen.reprint) can be printed.
--}}
@php
  $previewCurrency = (string) \App\Models\Tenants\Setting::get('currency', 'IDR');
@endphp
<script>
  (function () {
    var register = function () {
      Alpine.data('lakasirBillPreview', function () {
        var billTemplate = @js(__('Bill #:number'));
        var tableWord = @js(__('Table'));
        var currency = @js($previewCurrency);

        return {
          shown: false,
          bill: null,
          mode: 'bill',
          text: '',
          busy: false,
          layer: null,
          returnTo: null,

          init: function () {
            var self = this;
            this.$watch('shown', function (value) {
              self.$nextTick(function () {
                // One tick later than the drawer, which closes in the same event and hands the page back.
                setTimeout(function () {
                  if (value) {
                    self.activate();
                  } else {
                    self.deactivate();
                  }
                }, 0);
              });
            });
          },

          show: function (detail) {
            if (!detail || !detail.bill) {
              return;
            }

            this.bill = detail.bill;
            this.mode = detail.mode === 'kitchen' ? 'kitchen' : 'bill';
            this.text = this.render();
            this.open();
          },

          open: function () {
            if (this.shown) {
              return;
            }

            var opener = document.activeElement;
            var inDrawer = opener && opener.closest && opener.closest('[data-testid="bills-drawer"]');
            this.returnTo = opener && opener !== document.body && !inDrawer
              ? opener
              : document.querySelector('[data-testid="bills-toggle"]');
            this.shown = true;
          },

          close: function () {
            this.shown = false;
          },

          activate: function () {
            if (this.layer || !this.shown || !this.$refs.modal) {
              return;
            }

            var self = this;
            this.layer = LakasirDialog.activate(this.$refs.modal, {
              initialFocus: this.$refs.panel,
              onEscape: function () {
                self.close();
              },
            });
          },

          deactivate: function () {
            if (!this.layer) {
              return;
            }

            this.layer.deactivate(this.returnTo);
            this.layer = null;
          },

          render: function () {
            if (!window.LakasirReceipt || typeof window.LakasirReceipt.preview !== 'function') {
              return '';
            }

            try {
              return window.LakasirReceipt.preview(Alpine.raw(this.bill), {
                mode: this.mode,
                reprint: !!(this.bill.kitchen && this.bill.kitchen.reprint),
              });
            } catch (error) {
              console.error('Cannot build the print preview:', error);

              return '';
            }
          },

          get canPrint() {
            if (!this.bill || !window.LakasirReceipt || typeof window.LakasirReceipt.print !== 'function') {
              return false;
            }

            return this.mode === 'bill' || !!(this.bill.kitchen && this.bill.kitchen.reprint);
          },

          get printLabel() {
            return this.mode === 'kitchen'
              ? @js(__('Reprint last kitchen ticket'))
              : @js(__('Print provisional bill'));
          },

          get modeLabel() {
            return this.mode === 'kitchen' ? @js(__('KITCHEN')) : @js(__('Provisional bill'));
          },

          // "Bill #012 · Budi" / "Bill #012 · Table 5" (+ the date label of a bill from an earlier day).
          get identity() {
            var bill = this.bill;
            if (!bill) {
              return '';
            }

            var number = String(bill.number_label || bill.daily_order_number || '').replace(/^#/, '');
            var parts = [billTemplate.replace(':number', number)];
            var customer = String(bill.customer_name || '').trim();
            var table = bill.table && bill.table.number ? String(bill.table.number).trim() : '';

            if (customer !== '') {
              parts.push(customer);
            }
            if (table !== '') {
              parts.push(tableWord + ' ' + table);
            }
            if (bill.business_date_label) {
              parts.push(String(bill.business_date_label));
            }

            return parts.join(' · ');
          },

          get total() {
            if (!this.bill || this.bill.amount_due === undefined || this.bill.amount_due === null) {
              return '';
            }

            var amount = Number(this.bill.amount_due);
            var locale = document.documentElement.lang || undefined;
            try {
              return new Intl.NumberFormat(locale, { style: 'currency', currency: currency }).format(amount);
            } catch (error) {
              return new Intl.NumberFormat(locale).format(amount);
            }
          },

          print: async function () {
            if (this.busy || !this.canPrint) {
              return;
            }

            this.busy = true;
            try {
              var ok = await window.LakasirReceipt.print(Alpine.raw(this.bill), {
                mode: this.mode,
                reprint: !!(this.bill.kitchen && this.bill.kitchen.reprint),
                jobKey: this.bill.job_key || undefined,
              });
              if (ok) {
                this.close();
              }
            } finally {
              this.busy = false;
            }
          },
        };
      });
    };

    if (window.Alpine && typeof window.Alpine.data === 'function') {
      register();
    } else {
      document.addEventListener('alpine:init', register);
    }

    @include('filament.tenant.pages.cashier.bills.dialog-js')
  })();
</script>

<div id="receipt-preview-modal" data-testid="receipt-preview-modal" x-data="lakasirBillPreview" x-show="shown" x-cloak
  x-ref="modal" x-on:bill-preview.window="show($event.detail)"
  x-on:open-modal.window="if ($event.detail && $event.detail.id === 'receipt-preview-modal') open()"
  x-on:close-modal.window="if ($event.detail && $event.detail.id === 'receipt-preview-modal') close()"
  x-transition:enter="transition-opacity duration-150 ease-out motion-reduce:transition-none"
  x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
  x-transition:leave="transition-opacity duration-100 ease-in motion-reduce:transition-none"
  x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-40">
  <div class="absolute inset-0 bg-gray-950/60" aria-hidden="true" x-on:click="close()"></div>

  <div class="absolute inset-0 flex items-end justify-center md:items-center">
    <section x-ref="panel" role="dialog" aria-modal="true" aria-labelledby="receipt-preview-title" tabindex="-1"
      data-testid="receipt-preview-dialog"
      class="relative flex max-h-[92vh] w-full flex-col gap-3 overflow-hidden rounded-t-2xl bg-white p-4 shadow-xl focus:outline-none dark:bg-gray-900 md:max-w-lg md:rounded-2xl">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 id="receipt-preview-title" class="text-lg font-semibold text-gray-950 dark:text-white">
            {{ __('Print preview') }}
          </h2>
          <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="receipt-preview-identity">
            <span x-text="modeLabel"></span> · <span x-text="identity"></span>
          </p>
          <p class="text-sm text-gray-600 dark:text-gray-400" x-show="total !== ''" data-testid="receipt-preview-total">
            {{ __('Amount due') }}:
            <span class="font-semibold tabular-nums text-gray-950 dark:text-white" x-text="total"></span>
          </p>
        </div>
        <button type="button" x-on:click="close()" aria-label="{{ __('Back') }}"
          class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#c2410c] dark:text-gray-200 dark:hover:bg-white/10 dark:focus-visible:ring-[#fb923c]">
          <x-heroicon-o-x-mark class="h-6 w-6" />
        </button>
      </div>

      {{-- Thermal paper: always black on white, one character per column --}}
      <pre id="receipt-preview-text" data-testid="receipt-preview-text" role="region" tabindex="0"
        aria-label="{{ __('Print preview') }}" x-text="text"
        class="max-h-[55vh] min-h-24 overflow-auto whitespace-pre rounded-lg border border-gray-300 bg-white p-3 font-mono text-[11px] leading-snug text-gray-950 [tab-size:4] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#c2410c] dark:border-white/20 dark:bg-white dark:text-gray-950 sm:text-xs"></pre>

      <div class="grid gap-3" x-bind:class="canPrint ? 'grid-cols-2' : 'grid-cols-1'">
        <button type="button" x-on:click="close()" data-testid="receipt-preview-back"
          class="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-900 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#c2410c] dark:border-white/20 dark:bg-gray-800 dark:text-white dark:hover:bg-gray-700 dark:focus-visible:ring-[#fb923c]">
          {{ __('Back') }}
        </button>
        <button type="button" x-show="canPrint" x-cloak x-on:click="print()" x-bind:disabled="busy"
          x-bind:aria-busy="busy ? 'true' : 'false'" data-testid="receipt-preview-print"
          class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-[#c2410c] px-4 text-sm font-semibold text-white hover:bg-[#9a3412] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#c2410c] focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-[#fb923c] dark:text-[#1f1209] dark:hover:bg-[#fdba74] dark:focus-visible:ring-[#fb923c] dark:focus-visible:ring-offset-gray-900">
          <x-heroicon-o-printer class="h-5 w-5" />
          <span x-text="busy ? @js(__('Processing...')) : printLabel"></span>
        </button>
      </div>
    </section>
  </div>
</div>
