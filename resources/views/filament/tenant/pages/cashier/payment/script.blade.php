{{--
  Payment flow of the cashier (Alpine component paymentFlow()), used by payment/modal.blade.php.

  - The kind of a method (cash | exact | credit) comes from the method's flags, never from its name. It mirrors
    PaymentMethod::kind(): credit wins, then pure cash, everything else (wallet, debit, ...) is paid exactly.
  - Cash and credit amounts are typed with the on-screen numpad (the read-only display never opens the system
    keyboard) or a physical keyboard. Nothing here is trusted by the server: it re-plans the payment itself.
  - The block between "paymentFlow:begin" and "paymentFlow:end" is plain JavaScript without Blade, so tests can
    load it on its own (Node vm with a fake $wire).
--}}
@php
  $paymentLabels = [
    'pay' => __('Pay it'),
    'payAmount' => __('Pay :amount'),
    'processing' => __('Processing...'),
    'enterReceived' => __('Enter the received amount'),
    'shortBy' => __('Short by'),
    'confirmReceived' => __('Confirm payment received'),
    'memberRequired' => __('Member is required for credit'),
    'dueRequired' => __('Due date is required for credit'),
    'noItem' => __('No item'),
    'noMethod' => __('Payment method not found'),
    'change' => __('Change'),
    'exactPayment' => __('Exact payment'),
    'receivable' => __('Receivable remaining'),
    'conflict' => __('The bill changed while paying. New total :amount.'),
    'unsaved' => __('Some changes are not saved yet'),
    'failed' => __('Payment failed'),
  ];
@endphp
<script>
  window.lakasirPaymentLabels = @js($paymentLabels);

  // paymentFlow:begin
  function paymentFlow() {
    var MODAL_ID = 'proceed-the-payment';
    var MEMORY_KEY = 'lakasir.cashier.lastPaymentMethod';
    var MAX_DIGITS = 11;
    var DUE_DAYS = 14;
    var STEPS = [5000, 10000, 20000, 50000, 100000, 200000, 500000, 1000000];

    var text = function (key, fallback, replace) {
      var labels = window.lakasirPaymentLabels || {};
      var value = labels[key] || fallback;
      Object.keys(replace || {}).forEach(function (name) {
        value = value.split(':' + name).join(String(replace[name]));
      });

      return value;
    };

    var round2 = function (value) {
      return Math.round((Number(value) || 0) * 100) / 100;
    };

    var cents = function (value) {
      return Math.round((Number(value) || 0) * 100);
    };

    var fmtNum = function (value) {
      return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(Number(value) || 0);
    };

    var fmtMoney = function (value) {
      var number = Number(value) || 0;
      if (typeof window.moneyFormat === 'function') {
        return window.moneyFormat(number);
      }

      return (window.lakasirCurrency || 'IDR') + ' ' + fmtNum(number);
    };

    // The same rule as PaymentMethod::kind(); a 'kind' sent by the server wins.
    var kindOf = function (method) {
      if (!method) {
        return 'cash';
      }
      if (method.kind === 'credit' || method.kind === 'cash' || method.kind === 'exact') {
        return method.kind;
      }
      if (method.is_credit) {
        return 'credit';
      }
      if (method.is_cash && !method.is_wallet && !method.is_debit) {
        return 'cash';
      }

      return 'exact';
    };

    // Up to three amounts above the total, ascending and unique: the next 5.000 / 10.000 / 20.000 ... note.
    // Steps far smaller than the total are skipped (no 105.000 for 100.000), and the list is padded to three.
    var suggestionsFor = function (total) {
      var base = Math.ceil(Number(total) || 0);
      if (base <= 0) {
        return [10000, 20000, 50000];
      }

      var found = [];
      STEPS.forEach(function (step) {
        if (step * 10 < base) {
          return;
        }
        var next = (Math.floor(base / step) + 1) * step;
        if (next > base && found.indexOf(next) === -1) {
          found.push(next);
        }
      });
      found.sort(function (a, b) {
        return a - b;
      });
      while (found.length < 3) {
        var last = found.length ? found[found.length - 1] : base;
        found.push(last + (last >= 100000 ? 100000 : 50000));
      }

      return found.slice(0, 3);
    };

    // Numpad arithmetic on a string of digits: no leading zero, at most MAX_DIGITS digits.
    var nextValue = function (current, key) {
      var value = String(current || '');
      var pressed = String(key);

      if (pressed === 'clear') {
        return '';
      }
      if (pressed === 'backspace' || pressed === 'bk') {
        return value.slice(0, -1);
      }
      if (pressed === '000') {
        return value === '' || value.length + 3 > MAX_DIGITS ? value : value + '000';
      }
      if (/^\d$/.test(pressed)) {
        if (value === '' && pressed === '0') {
          return '';
        }

        return value.length >= MAX_DIGITS ? value : value + pressed;
      }

      return value;
    };

    var isoDate = function (offsetDays) {
      var d = new Date();
      d.setDate(d.getDate() + offsetDays);
      var two = function (n) {
        return String(n).padStart(2, '0');
      };

      return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate());
    };

    var recall = function () {
      try {
        return window.localStorage.getItem(MEMORY_KEY);
      } catch (e) {
        return null;
      }
    };

    var remember = function (id) {
      try {
        window.localStorage.setItem(MEMORY_KEY, String(id));
      } catch (e) {
        // Private mode or blocked storage: the choice is simply not remembered.
      }
    };

    return {
      sheetOpen: false,
      ready: false,
      submitting: false,
      reloading: false,
      methodId: null,
      received: '',
      down: '',
      downLimit: false,
      exactConfirmed: false,
      memberId: '',
      dueDate: '',
      today: '',
      itemsOpen: false,
      printKitchen: true,
      stockOverride: false,
      error: '',
      conflict: null,
      staleErrors: false,
      closedByServer: false,

      // helpers for the template (and for tests)
      kindOf: kindOf,
      fmtNum: fmtNum,
      fmt: fmtMoney,
      suggestionsFor: suggestionsFor,
      nextValue: nextValue,

      get wire() {
        return this.$wire || {};
      },

      get total() {
        return round2(this.wire.total_price);
      },

      get methods() {
        var list = this.wire.paymentMethods;

        return Array.isArray(list) ? list : [];
      },

      get method() {
        var id = String(this.methodId);

        return this.methods.find(function (m) {
          return String(m.id) === id;
        }) || null;
      },

      get kind() {
        return kindOf(this.method);
      },

      get receivedNum() {
        return parseInt(this.received || '0', 10) || 0;
      },

      get downNum() {
        return parseInt(this.down || '0', 10) || 0;
      },

      get exactAmount() {
        return Math.ceil(this.total);
      },

      get suggestions() {
        return suggestionsFor(this.total);
      },

      // Received money must reach the total at cent precision (Money::gte on the server).
      get cashOk() {
        return (this.receivedNum > 0 || this.total <= 0) && cents(this.receivedNum) >= cents(this.total);
      },

      get change() {
        return this.cashOk ? round2(Math.max(0, this.receivedNum - this.total)) : 0;
      },

      get short() {
        return this.receivedNum > 0 && !this.cashOk ? round2(this.total - this.receivedNum) : 0;
      },

      get remaining() {
        return round2(Math.max(0, this.total - this.downNum));
      },

      get hasItems() {
        var items = this.wire.cartItems;
        if (Array.isArray(items)) {
          return items.length > 0;
        }
        if (items && typeof items === 'object') {
          return Object.keys(items).length > 0;
        }

        return true;
      },

      // Why the pay button is disabled ('' = it is enabled). Shown next to the button.
      get reason() {
        if (this.submitting || !this.ready) {
          return text('processing', 'Processing...');
        }
        if (this.conflict) {
          return text('conflict', 'The bill changed while paying. New total :amount.', {
            amount: fmtMoney(this.conflict.amount),
          });
        }
        if (!this.hasItems) {
          return text('noItem', 'No item');
        }
        if (!this.method) {
          return text('noMethod', 'Payment method not found');
        }

        if (this.kind === 'cash') {
          if (this.cashOk) {
            return '';
          }

          return this.receivedNum > 0
            ? text('shortBy', 'Short by') + ' ' + fmtMoney(this.short)
            : text('enterReceived', 'Enter the received amount');
        }

        if (this.kind === 'exact') {
          return this.exactConfirmed ? '' : text('confirmReceived', 'Confirm payment received');
        }

        if (!this.memberId) {
          return text('memberRequired', 'Member is required for credit');
        }
        if (!this.dueDate || this.dueDate < this.today) {
          return text('dueRequired', 'Due date is required for credit');
        }

        return '';
      },

      get canSubmit() {
        return this.reason === '';
      },

      // The line under the cash display: what to give back, or how much is still missing.
      get changeLine() {
        if (this.receivedNum <= 0 && this.total > 0) {
          return { text: '', tone: 'muted' };
        }
        if (this.cashOk) {
          return { text: text('change', 'Change') + ' ' + fmtMoney(this.change), tone: 'good' };
        }

        return { text: text('shortBy', 'Short by') + ' ' + fmtMoney(this.short), tone: 'bad' };
      },

      get conflictText() {
        return this.conflict
          ? text('conflict', 'The bill changed while paying. New total :amount.', { amount: fmtMoney(this.conflict.amount) })
          : '';
      },

      // The status line of the footer: {text, tone: good | bad | muted | busy}
      get status() {
        var reason = this.reason;

        if (this.submitting || !this.ready) {
          return { text: reason, tone: 'busy' };
        }
        if (reason !== '') {
          var bad = this.conflict !== null || (this.kind === 'cash' && this.receivedNum > 0 && !this.cashOk);

          return { text: reason, tone: bad ? 'bad' : 'muted' };
        }
        if (this.kind === 'cash') {
          return this.change > 0
            ? { text: text('change', 'Change') + ' ' + fmtMoney(this.change), tone: 'good' }
            : { text: text('exactPayment', 'Exact payment'), tone: 'good' };
        }
        if (this.kind === 'credit') {
          return { text: text('receivable', 'Receivable remaining') + ' ' + fmtMoney(this.remaining), tone: 'muted' };
        }

        return { text: text('exactPayment', 'Exact payment'), tone: 'good' };
      },

      get submitLabel() {
        if (this.submitting) {
          return text('processing', 'Processing...');
        }
        if (this.kind === 'credit') {
          return text('pay', 'Pay it');
        }

        return text('payAmount', 'Pay :amount', { amount: fmtMoney(this.total) });
      },

      // ---- opening / closing -------------------------------------------------------------

      onOpenModal: function (event) {
        if (!event || !event.detail || event.detail.id !== MODAL_ID) {
          return;
        }

        this.reset();
        this.sheetOpen = true;
        this.begin();
      },

      onCloseModal: function (event) {
        if (!event || !event.detail || event.detail.id !== MODAL_ID || !this.sheetOpen) {
          return;
        }

        this.sheetOpen = false;
        this.closedByServer = true;
        this.$wire.paymentClosed();
      },

      closePayment: function () {
        if (this.submitting) {
          return;
        }

        this.$dispatch('close-modal', { id: MODAL_ID });
      },

      reset: function () {
        this.ready = false;
        this.submitting = false;
        this.reloading = false;
        this.received = '';
        this.down = '';
        this.downLimit = false;
        this.exactConfirmed = false;
        this.printKitchen = true;
        this.stockOverride = false;
        this.error = '';
        this.conflict = null;
        this.staleErrors = false;
        this.closedByServer = false;
        this.today = isoDate(0);
        this.dueDate = isoDate(DUE_DAYS);
        this.itemsOpen = (window.innerWidth || 0) >= 720;
      },

      // The payment starts from the server's newest lines and total (and a fresh payment token),
      // unless whoever opened the dialog already did that (the server then says the payment is pinned).
      begin: async function () {
        try {
          if (!this.wire.paymentPinned) {
            await this.$wire.syncCartForPayment();
          }
        } catch (e) {
          this.error = text('failed', 'Payment failed');
        }

        this.chooseInitialMethod();
        this.ready = true;

        var self = this;
        this.$nextTick(function () {
          var group = self.$refs.methods;
          var tile = group ? group.querySelector('[aria-pressed="true"]') : null;
          if (tile) {
            tile.focus({ preventScroll: true });
          }
        });
      },

      chooseInitialMethod: function () {
        var ids = this.methods.map(function (m) {
          return String(m.id);
        });
        var remembered = recall();
        var detail = this.wire.cartDetail || {};
        var preset = detail.payment_method_id;

        if (remembered !== null && ids.indexOf(String(remembered)) !== -1) {
          this.methodId = remembered;
        } else if (preset !== null && preset !== undefined && ids.indexOf(String(preset)) !== -1) {
          this.methodId = preset;
        } else {
          this.methodId = ids.length ? this.methods[0].id : null;
        }

        this.memberId = detail.member_id ? String(detail.member_id) : '';
      },

      // ---- input -------------------------------------------------------------------------

      selectMethod: function (method) {
        if (this.submitting) {
          return;
        }

        this.methodId = method.id;
        this.exactConfirmed = false;
        this.downLimit = false;
        this.staleErrors = true;
      },

      // Cash types into "received", credit into the down payment.
      press: function (key) {
        if (this.submitting) {
          return;
        }

        var field = this.kind === 'credit' ? 'down' : 'received';
        var next = nextValue(this[field], key);

        if (field === 'down' && cents(parseInt(next || '0', 10)) > cents(this.total)) {
          this.downLimit = true;

          return;
        }

        this.downLimit = false;
        this[field] = next;
        this.staleErrors = true;
      },

      quick: function (amount) {
        if (this.submitting) {
          return;
        }

        this.received = String(Math.max(0, Math.round(Number(amount) || 0)));
        this.staleErrors = true;
      },

      toggleExact: function () {
        if (!this.submitting) {
          this.exactConfirmed = !this.exactConfirmed;
          this.staleErrors = true;
        }
      },

      onKey: function (event) {
        if (!this.sheetOpen || event.ctrlKey || event.metaKey || event.altKey) {
          return;
        }

        var target = event.target || {};
        var tag = String(target.tagName || '').toLowerCase();
        var display = target.dataset && target.dataset.payDisplay !== undefined;
        if (!display && (tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable)) {
          return;
        }

        if (event.key === 'Escape') {
          if (!this.submitting) {
            event.preventDefault();
            this.closePayment();
          }

          return;
        }

        if (this.submitting) {
          return;
        }

        if (this.kind === 'cash' || this.kind === 'credit') {
          if (/^\d$/.test(event.key)) {
            event.preventDefault();
            this.press(event.key);

            return;
          }
          if (event.key === 'Backspace') {
            event.preventDefault();
            this.press('backspace');

            return;
          }
          if (event.key === 'Delete') {
            event.preventDefault();
            this.press('clear');

            return;
          }
        }

        // On a focused button Enter already clicks it.
        if (event.key === 'Enter' && tag !== 'button' && tag !== 'a' && this.canSubmit) {
          event.preventDefault();
          this.submitPayment();
        }
      },

      // ---- server ------------------------------------------------------------------------

      onConflict: function (event) {
        var detail = event && event.detail ? event.detail : {};
        if (Array.isArray(detail)) {
          detail = detail[0] || {};
        }
        if (!this.sheetOpen || String(detail.id) !== String(this.wire.openBillId)) {
          return;
        }

        this.conflict = { amount: Number(detail.amount_due) || 0, version: detail.serverVersion };
        this.submitting = false;
      },

      // "Reload bill": take the newest lines and total again (a new pinned version and payment token).
      reloadBill: async function () {
        if (this.reloading) {
          return;
        }

        this.reloading = true;
        try {
          await this.$wire.syncCartForPayment();
          this.conflict = null;
          this.error = '';
          this.received = '';
          this.down = '';
          this.exactConfirmed = false;
          this.stockOverride = false;
          this.staleErrors = true;
        } catch (e) {
          this.error = text('failed', 'Payment failed');
        } finally {
          this.reloading = false;
        }
      },

      submitPayment: async function () {
        if (!this.canSubmit) {
          return;
        }

        this.submitting = true;
        this.error = '';
        this.staleErrors = false;
        this.closedByServer = false;

        try {
          // Names and item notes still being saved must reach the server first.
          var pending = window.LakasirPending;
          if (pending && typeof pending.settle === 'function') {
            var settled = await pending.settle();
            if (settled && settled.ok === false) {
              this.error = text('unsaved', 'Some changes are not saved yet');

              return;
            }
          }

          var wire = this.$wire;
          var kind = this.kind;
          var credit = kind === 'credit';
          var payed = null;
          var changes = 0;

          if (kind === 'cash') {
            payed = this.receivedNum;
            changes = this.change;
          } else if (credit) {
            payed = this.downNum;
          }
          // exact: no client amount at all, the server uses its own amount due.

          remember(this.methodId);

          wire.set('cartDetail.payment_method_id', Number(this.methodId), false);
          wire.set('cartDetail.payed_money', payed, false);
          wire.set('cartDetail.money_changes', changes, false);
          wire.set('cartDetail.due_date', credit ? this.dueDate : null, false);
          if (credit) {
            wire.set('cartDetail.member_id', this.memberId ? Number(this.memberId) : null, false);
          }
          wire.set('printKitchenOnPay', Boolean(this.printKitchen), false);
          // The override only counts while its panel is on screen (the server asked for it).
          wire.set('stockOverride', Boolean(this.stockOverride && this.$refs.overridePanel), false);

          window.dispatchEvent(new CustomEvent('lakasir-payment-submit', {
            detail: { bill: wire.openBillId !== null && wire.openBillId !== undefined, kind: kind },
          }));

          await wire.proceedThePayment();

          var self = this;
          this.$nextTick(function () {
            // Still open and no field message on screen: the server only sent a notification.
            if (self.sheetOpen && !self.closedByServer && !self.$refs.serverErrors && !self.conflict && !self.error) {
              self.error = text('failed', 'Payment failed');
            }
          });
        } catch (e) {
          // Lost connection: the same payment token is kept, so asking again can never double charge.
          this.error = text('failed', 'Payment failed');
        } finally {
          this.submitting = false;
        }
      },
    };
  }
  // paymentFlow:end

  window.paymentFlow = paymentFlow;
</script>
