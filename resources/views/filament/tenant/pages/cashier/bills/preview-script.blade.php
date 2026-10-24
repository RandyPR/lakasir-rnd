{{--
  Alpine component lakasirBillPreview of the print preview (bills/preview-modal.blade.php) and window.LakasirDialog.
  Loaded once with the page by cashier/scripts.blade.php, so a Livewire request of the cashier page never sends it
  again.
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
