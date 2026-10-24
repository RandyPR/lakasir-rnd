{{--
  Alpine component paymentSuccess() of the success dialog (payment/success-modal.blade.php). Loaded once with the page
  by cashier/scripts.blade.php, so a Livewire request of the cashier page never sends it again.
--}}
<script>
  // paymentSuccess:begin
  function paymentSuccess() {
    var kindOf = function (method) {
      if (!method) {
        return 'cash';
      }
      if (method.is_credit) {
        return 'credit';
      }
      if (method.is_cash && !method.is_wallet && !method.is_debit) {
        return 'cash';
      }

      return 'exact';
    };

    var round2 = function (value) {
      return Math.round((Number(value) || 0) * 100) / 100;
    };

    return {
      selling: null,
      kitchenPrinted: false,
      alreadyPaid: false,
      fromBill: false,
      previewOpen: false,
      previewText: '',

      fmt: function (value) {
        var number = Number(value) || 0;
        if (typeof window.moneyFormat === 'function') {
          return window.moneyFormat(number);
        }

        return (window.lakasirCurrency || 'IDR') + ' ' + new Intl.NumberFormat('id-ID').format(number);
      },

      get kind() {
        return this.selling ? kindOf(this.selling.payment_method || this.selling.paymentMethod) : 'cash';
      },

      get change() {
        return this.selling ? round2(this.selling.money_changes) : 0;
      },

      get methodName() {
        var method = this.selling ? (this.selling.payment_method || this.selling.paymentMethod) : null;

        return method && method.name ? method.name : '';
      },

      get memberName() {
        return this.selling && this.selling.member && this.selling.member.name ? this.selling.member.name : '';
      },

      // What is still owed on a credit sale: total after the discounts, minus the down payment.
      get receivable() {
        if (!this.selling) {
          return 0;
        }
        var s = this.selling;
        var due = (Number(s.total_price) || 0) - (Number(s.discount_price) || 0) - (Number(s.total_discount_per_item) || 0);

        return round2(Math.max(0, due - (Number(s.payed_money) || 0)));
      },

      // A direct sale of a restaurant gets its kitchen ticket with one tap (a bill's ticket goes with the bill).
      get canPrintKitchen() {
        return Boolean(this.selling) && !this.fromBill && !this.kitchenPrinted;
      },

      onSubmitted: function (event) {
        this.fromBill = Boolean(event && event.detail && event.detail.bill);
      },

      onSold: function (event) {
        var detail = event && event.detail ? event.detail : {};
        if (Array.isArray(detail)) {
          detail = detail[0] || {};
        }
        var selling = detail.selling || null;
        if (!selling) {
          return;
        }

        this.selling = selling;
        this.kitchenPrinted = Boolean(detail.kitchenPrinted);
        this.alreadyPaid = Boolean(detail.alreadyPaid);
        this.previewOpen = false;
        this.previewText = '';
        window._lakasirLastSelling = selling;
      },

      printReceipt: function () {
        if (window.LakasirReceipt && window._lakasirLastSelling) {
          window.LakasirReceipt.print(window._lakasirLastSelling, { buttonId: 'printReceiptButton' });
        }
      },

      printKitchen: async function () {
        if (!window.LakasirReceipt || !this.selling) {
          return;
        }

        var ok = await window.LakasirReceipt.print(this.selling, {
          mode: 'kitchen',
          jobKey: 'kitchen-sale-' + (this.selling.id || ''),
        });
        if (ok) {
          this.kitchenPrinted = true;
        }
      },

      togglePreview: function () {
        if (!this.previewOpen && window.LakasirReceipt && this.selling) {
          try {
            this.previewText = window.LakasirReceipt.preview(this.selling, { mode: 'receipt' });
          } catch (e) {
            this.previewText = '';
          }
        }

        this.previewOpen = !this.previewOpen;
      },
    };
  }
  // paymentSuccess:end

  window.paymentSuccess = paymentSuccess;
</script>
