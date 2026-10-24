{{--
  Cashier page bootstrap. Rendered once per full page load by the BODY_END render hook of the Cashier page (never as
  part of a Livewire request). Everything that must exist exactly once lives here:

    Alpine.store('cart')    product_id => qty of the active context (seeded here, updated by `cart-qty-updated`)
    window.LakasirPending   tracks saves in flight (customer name, item notes) so Pay can wait for them
    window.LakasirCashier   product view mode, the Livewire handle of the page, small helpers
    Alpine.data             lkShell (order panel / dock), lkDialog (accessible dialogs), lkName (customer name)
    window listeners        bill-print, lakasir-print-failed, bill-preview, bill-saved, bill-conflict,
                            takeaway-prompt, cancel-sent-request, cart-item-removed, selling-created, camera scanner
    scanner detector        a physical barcode scanner types fast and ends with Enter
  Printing itself is `window.LakasirReceipt` (partials/receipt-print), nothing is printed from here.
--}}
@php
  use App\Models\Tenants\Profile;
  use App\Models\Tenants\Setting;

  $boot = [
      'cartQty' => (object) ($cartQty ?? []),
      'currency' => (string) Setting::get('currency', 'IDR'),
      'locale' => (string) (Profile::get()->locale ?? 'en'),
      'i18n' => [
          'Undo' => __('Undo'),
          'Item removed' => __('Item removed'),
          'Bill #:number' => __('Bill #:number'),
          'Bill #:number saved' => __('Bill #:number saved'),
          'Bill #:number (:label) saved' => __('Bill #:number (:label) saved'),
          'Bill #:number saved. NOT sent to the kitchen yet.' => __('Bill #:number saved. NOT sent to the kitchen yet.'),
          'Pay bill' => __('Pay bill'),
          'Open' => __('Open'),
          'Send to kitchen' => __('Send to kitchen'),
          'Kitchen ticket not printed. Check the printer, then try again.' => __('Kitchen ticket not printed. Check the printer, then try again.'),
          'Try again' => __('Try again'),
          'Mark as sent without printing' => __('Mark as sent without printing'),
          'Printer not configured' => __('Printer not configured'),
          'Print failed' => __('Print failed'),
          'Unsaved' => __('Unsaved'),
          'Saving...' => __('Saving...'),
          'Saved' => __('Saved'),
          'Failed to save, tap to retry' => __('Failed to save, tap to retry'),
          'You are offline' => __('You are offline'),
          'Some changes are not saved yet' => __('Some changes are not saved yet'),
          'Table or name is empty. The ticket will read TAKEAWAY #:number.' => __('Table or name is empty. The ticket will read TAKEAWAY #:number.'),
          'Remove :name? The kitchen already received :qty. The kitchen is only told when you press Send to kitchen.' => __('Remove :name? The kitchen already received :qty. The kitchen is only told when you press Send to kitchen.'),
          'Reduce :name to :qty? The kitchen already received :sent. The kitchen is only told when you press Send to kitchen.' => __('Reduce :name to :qty? The kitchen already received :sent. The kitchen is only told when you press Send to kitchen.'),
          'Wrong input' => __('Wrong input'),
          'Customer cancelled' => __('Customer cancelled'),
          'Out of stock' => __('Out of stock'),
          'Other' => __('Other'),
          'New total' => __('New total'),
      ],
  ];
@endphp
<script>
  window.LakasirBoot = @js($boot);
  window.lakasirCurrency = window.LakasirBoot.currency;
  window.lakasirLocale = window.LakasirBoot.locale;
</script>
@verbatim
<script>
  (() => {
    'use strict';

    const w = window;
    const B = w.LakasirBoot || {};

    // ---------------------------------------------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------------------------------------------

    /** Translated text; ":name" style placeholders are filled with plain text (never HTML). */
    const t = (key, repl) => {
      let s = (B.i18n && B.i18n[key]) || key;
      if (repl) {
        Object.keys(repl).sort((a, b) => b.length - a.length).forEach((k) => {
          s = s.split(':' + k).join(String(repl[k] === null || repl[k] === undefined ? '' : repl[k]));
        });
      }
      return s;
    };

    /** Livewire browser events carry named parameters as an object (or a one-element array for positional ones). */
    const detailOf = (e) => {
      const d = e && e.detail;
      if (Array.isArray(d)) return d[0] && typeof d[0] === 'object' ? d[0] : {};
      return d && typeof d === 'object' ? d : {};
    };

    const on = (name, fn) => w.addEventListener(name, (e) => fn(detailOf(e), e));

    const pad3 = (n) => (n === null || n === undefined || n === '' ? '---' : String(n).padStart(3, '0'));

    const money = (n) => (typeof w.moneyFormat === 'function' ? w.moneyFormat(Number(n) || 0) : String(Number(n) || 0));

    const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

    const focusables = (root) => Array.from(root.querySelectorAll(FOCUSABLE))
      .filter((el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden');

    /** Keeps Tab inside `root` (dialogs and the mobile order sheet are modal). */
    const trapTab = (e, root) => {
      if (e.key !== 'Tab' || !root) return;
      const items = focusables(root);
      if (items.length === 0) {
        e.preventDefault();
        return;
      }
      const first = items[0];
      const last = items[items.length - 1];
      if (e.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && (document.activeElement === last || !root.contains(document.activeElement))) {
        e.preventDefault();
        first.focus();
      }
    };

    /** A Filament modal or one of our dialogs is open. */
    const anyModalOpen = () => Boolean(document.querySelector('.fi-modal-open') || document.querySelector('[data-lk-dialog-open="1"]'));

    /** The Livewire handle of the cashier page, found in the DOM so it is never a stale reference. */
    const pageWire = () => {
      const root = document.querySelector('[data-testid="cashier-root"]');
      // wire:id sits on the component's own root, the wrapper around cashier-root: look upwards from it.
      const host = root && root.closest('[wire\\:id]');
      const id = host && host.getAttribute('wire:id');
      if (id && w.Livewire && typeof w.Livewire.find === 'function') {
        return w.Livewire.find(id);
      }
      return (w.LakasirCashier && w.LakasirCashier.wire) || null;
    };

    // ---------------------------------------------------------------------------------------------------------
    // toasts (role=status / alert, optional action buttons, permanent when duration is 0)
    // ---------------------------------------------------------------------------------------------------------

    const Toast = (() => {
      const tones = {
        info: 'border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100',
        success: 'border-success-600 bg-success-50 text-success-900 dark:border-success-500 dark:bg-gray-800 dark:text-gray-100',
        warning: 'border-warning-600 bg-warning-50 text-warning-900 dark:border-warning-500 dark:bg-gray-800 dark:text-gray-100',
        danger: 'border-danger-600 bg-danger-50 text-danger-900 dark:border-danger-500 dark:bg-gray-800 dark:text-gray-100',
      };
      const actionCls = 'inline-flex min-h-11 items-center justify-center rounded-lg border border-current px-3 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong';
      const primaryCls = 'inline-flex min-h-11 items-center justify-center rounded-lg bg-lakasir-primary-strong px-3 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong dark:bg-lakasir-primary-soft dark:text-[#1f1209]';
      const timers = new Map();
      let root = null;

      const host = () => {
        if (root && document.body.contains(root)) return root;
        root = document.createElement('div');
        root.id = 'lk-toasts';
        root.className = 'pointer-events-none fixed inset-x-0 top-3 z-[60] flex flex-col items-center gap-2 px-3';
        document.body.appendChild(root);
        return root;
      };

      const dismiss = (id) => {
        const el = document.querySelector('[data-toast-id="' + id + '"]');
        if (timers.has(id)) {
          clearTimeout(timers.get(id));
          timers.delete(id);
        }
        if (el) el.remove();
      };

      /**
       * @param {{id:string,title:string,body?:string,tone?:string,duration?:number,testid?:string,
       *          actions?:Array<{label:string,testid?:string,primary?:boolean,onClick:Function}>}} o
       */
      const show = (o) => {
        const id = o.id || 'toast-' + Math.random().toString(36).slice(2);
        dismiss(id);
        const tone = o.tone || 'info';
        const el = document.createElement('div');
        el.setAttribute('data-toast-id', id);
        if (o.testid) el.setAttribute('data-testid', o.testid);
        el.setAttribute('role', tone === 'danger' || tone === 'warning' ? 'alert' : 'status');
        el.className = 'pointer-events-auto w-full max-w-md rounded-xl border-2 p-3 shadow-lg ' + (tones[tone] || tones.info);

        const title = document.createElement('p');
        title.className = 'text-sm font-semibold';
        title.textContent = o.title;
        el.appendChild(title);

        if (o.body) {
          const body = document.createElement('p');
          body.className = 'mt-0.5 text-sm';
          body.textContent = o.body;
          el.appendChild(body);
        }

        if (o.actions && o.actions.length) {
          const row = document.createElement('div');
          row.className = 'mt-2 flex flex-wrap gap-2';
          o.actions.forEach((a) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = a.primary ? primaryCls : actionCls;
            if (a.testid) b.setAttribute('data-testid', a.testid);
            b.textContent = a.label;
            b.addEventListener('click', () => {
              Promise.resolve(a.onClick && a.onClick(el, b)).catch(() => {});
            });
            row.appendChild(b);
          });
          el.appendChild(row);
        }

        host().appendChild(el);

        if (o.duration && o.duration > 0) {
          timers.set(id, setTimeout(() => dismiss(id), o.duration));
        }

        return el;
      };

      return { show, dismiss };
    })();

    w.LakasirToast = Toast;

    // ---------------------------------------------------------------------------------------------------------
    // LakasirPending: saves in flight. Pay waits for them (settle) and refuses when one failed.
    // ---------------------------------------------------------------------------------------------------------

    if (!w.LakasirPending) {
      const inflight = new Set();
      const flushers = new Set();
      const failed = new Set();

      w.LakasirPending = {
        /** Remember a promise (a Livewire call). A rejected one marks `label` as failed until it succeeds later. */
        track(p, label) {
          const name = label || '';
          const wrapped = Promise.resolve(p).then(
            () => { failed.delete(name); },
            () => { failed.add(name); }
          ).finally(() => { inflight.delete(wrapped); });
          inflight.add(wrapped);
          return p;
        },
        /** Register a function that forces a pending (debounced) save now. Returns the unregister function. */
        register(fn) {
          flushers.add(fn);
          return () => { flushers.delete(fn); };
        },
        /** Flush everything, wait for it, report. */
        async settle() {
          for (const fn of Array.from(flushers)) {
            try {
              await fn();
            } catch (e) {
              failed.add('flush');
            }
          }
          while (inflight.size > 0) {
            await Promise.all(Array.from(inflight));
          }
          return { ok: failed.size === 0, failed: Array.from(failed) };
        },
      };
    }

    // ---------------------------------------------------------------------------------------------------------
    // LakasirCashier
    // ---------------------------------------------------------------------------------------------------------

    const VIEW_KEY = 'lakasir.cashier.productView';

    w.LakasirCashier = Object.assign(w.LakasirCashier || {}, {
      wire: (w.LakasirCashier && w.LakasirCashier.wire) || null,

      getView() {
        let v = null;
        try { v = w.localStorage.getItem(VIEW_KEY); } catch (e) { v = null; }
        return v === 'list' ? 'list' : 'grid';
      },

      setView(v) {
        const view = v === 'list' ? 'list' : 'grid';
        document.documentElement.setAttribute('data-product-view', view);
        try { w.localStorage.setItem(VIEW_KEY, view); } catch (e) { /* private mode: the choice just is not remembered */ }
      },

      anyModalOpen,

      /** Opens the open-bills drawer (used when the drawer's own toggle is not on the page). */
      openBills() {
        const list = w.Livewire && typeof w.Livewire.getByName === 'function' ? w.Livewire.getByName('cashier.open-bills') : [];
        if (list && list[0]) list[0].setOpen(true);
      },

      /** Type a quantity into the stepper input: "12,5" and "12.5" both mean 12.5; 0 removes the line. */
      async setQty(el, productId) {
        const prev = String(el.getAttribute('data-qty') || '0').replace(',', '.');
        const raw = String(el.value).trim().replace(/\s/g, '').replace(',', '.');
        const n = Number(raw);
        if (raw === '' || !isFinite(n) || n < 0 || Math.abs(n - Number(prev)) < 0.0005) {
          el.value = el.getAttribute('data-qty') || '';
          return;
        }
        const wire = pageWire();
        if (!wire) return;
        try {
          await wire.addCart(productId, { amount: n });
        } finally {
          if (el.isConnected) el.value = el.getAttribute('data-qty') || '';
        }
      },

      requestFullscreen() {
        if (!document.fullscreenElement) {
          if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen();
        } else if (document.exitFullscreen) {
          document.exitFullscreen();
        }
      },
    });

    if (!document.documentElement.getAttribute('data-product-view')) {
      document.documentElement.setAttribute('data-product-view', w.LakasirCashier.getView());
    }

    // ---------------------------------------------------------------------------------------------------------
    // Alpine: store + components
    // ---------------------------------------------------------------------------------------------------------

    const whenAlpine = (fn) => {
      if (w.Alpine) fn();
      else document.addEventListener('alpine:init', fn, { once: true });
    };

    const cartStore = (seed) => ({
      qty: Array.isArray(seed) ? {} : (seed || {}),
      of(id) { return Number(this.qty[id] || 0); },
      set(map) { this.qty = Array.isArray(map) ? {} : (map || {}); },
    });

    // The seed changes on every full page load, so the store is rebuilt each time this script runs.
    whenAlpine(() => {
      w.Alpine.store('cart', cartStore(B.cartQty));
    });

    if (!w._lakasirBootstrapBound) {
      w._lakasirBootstrapBound = true;

      whenAlpine(() => {
        const A = w.Alpine;

        // --- the order panel: a bottom sheet on phones, a fixed column from md up -----------------------------
        A.data('lkShell', () => ({
          open: false,
          desk: false,
          kb: false,
          mq: null,
          offs: [],

          init() {
            this.mq = w.matchMedia('(min-width: 768px)');
            this.desk = this.mq.matches;
            const sync = () => {
              this.desk = this.mq.matches;
              if (this.desk) this.open = false;
            };
            this.mq.addEventListener('change', sync);
            this.offs.push(() => this.mq.removeEventListener('change', sync));

            const vv = w.visualViewport;
            if (vv) {
              const apply = () => {
                document.documentElement.style.setProperty('--vvh', vv.height + 'px');
                this.kb = !this.desk && vv.height < w.innerHeight * 0.75;
              };
              vv.addEventListener('resize', apply);
              vv.addEventListener('scroll', apply);
              this.offs.push(() => {
                vv.removeEventListener('resize', apply);
                vv.removeEventListener('scroll', apply);
              });
              apply();
            }

            const focusIn = (e) => {
              const el = e.target;
              if (!this.open || !el || !/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) return;
              setTimeout(() => {
                try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (err) { /* old browser */ }
              }, 250);
            };
            this.$el.addEventListener('focusin', focusIn);
            this.offs.push(() => this.$el.removeEventListener('focusin', focusIn));

            const close = () => { this.open = false; };
            const opened = () => { if (!this.desk) this.open = true; };
            w.addEventListener('bill-saved', close);
            w.addEventListener('selling-created', close);
            w.addEventListener('open-bill-changed', opened);
            this.offs.push(() => {
              w.removeEventListener('bill-saved', close);
              w.removeEventListener('selling-created', close);
              w.removeEventListener('open-bill-changed', opened);
            });
          },

          destroy() {
            this.offs.forEach((fn) => fn());
            this.offs = [];
          },

          show() {
            this.open = true;
            this.$nextTick(() => {
              const first = this.$refs.sheet && this.$refs.sheet.querySelector('[data-sheet-close]');
              if (first) first.focus();
            });
          },

          hide() {
            this.open = false;
            this.$nextTick(() => {
              if (this.$refs.dock && this.$refs.dock.getClientRects().length) this.$refs.dock.focus();
            });
          },

          onEscape() {
            if (!this.open || this.desk || anyModalOpen()) return;
            this.hide();
          },

          trap(e) {
            if (this.desk || !this.open) return;
            trapTab(e, this.$refs.sheet);
          },
        }));

        // --- accessible dialog: role=dialog, aria-modal, inert page, Tab trap, Esc, focus returns --------------
        A.data('lkDialog', (name) => ({
          open: false,
          data: {},
          returnTo: null,
          offs: [],
          busy: false,
          choice: '',
          text: '',

          init() {
            const openIt = (e) => {
              const d = e.detail || {};
              if (d.name === name) this.show(d.data || {});
            };
            const closeIt = (e) => {
              const d = e.detail || {};
              if (!d.name || d.name === name) this.hide(false);
            };
            w.addEventListener('lk-dialog-open', openIt);
            w.addEventListener('lk-dialog-close', closeIt);
            this.offs.push(() => {
              w.removeEventListener('lk-dialog-open', openIt);
              w.removeEventListener('lk-dialog-close', closeIt);
            });
          },

          destroy() {
            this.offs.forEach((fn) => fn());
            this.offs = [];
            if (this.open) this.setInert(false);
          },

          setInert(on) {
            w._lkInertCount = Math.max(0, (w._lkInertCount || 0) + (on ? 1 : -1));
            document.querySelectorAll('[data-lk-content]').forEach((el) => {
              if (w._lkInertCount > 0) {
                el.setAttribute('inert', '');
                el.setAttribute('aria-hidden', 'true');
              } else {
                el.removeAttribute('inert');
                el.removeAttribute('aria-hidden');
              }
            });
          },

          show(data) {
            this.data = data;
            this.choice = '';
            this.text = '';
            this.busy = false;
            this.returnTo = document.activeElement;
            this.open = true;
            this.setInert(true);
            this.$nextTick(() => {
              const target = this.$refs.initial || focusables(this.$refs.panel)[0];
              if (target) target.focus();
            });
          },

          hide(restore = true) {
            if (!this.open) return;
            this.open = false;
            this.setInert(false);
            if (restore && this.returnTo && this.returnTo.isConnected && typeof this.returnTo.focus === 'function') {
              this.returnTo.focus();
            }
          },

          /** Runs an action behind the dialog's button: the buttons stay disabled until it is done. */
          async run(fn) {
            this.busy = true;
            try {
              await fn();
            } finally {
              this.busy = false;
              this.hide(false);
            }
          },

          /** The cancel reason: a quick choice (translated) or the free text of "Other". */
          get reason() {
            if (this.choice === 'Other') return String(this.text || '').trim();
            return this.choice ? t(this.choice) : '';
          },

          onKey(e) {
            if (!this.open) return;
            if (e.key === 'Escape') {
              e.stopPropagation();
              this.hide();
            } else {
              trapTab(e, this.$refs.panel);
            }
          },
        }));

        // --- customer name input: saves itself, tells you whether it did ------------------------------------
        A.data('lkName', (initial) => ({
          value: initial || '',
          committed: initial || '',
          state: 'saved', // saved | unsaved | saving | failed
          timer: null,
          unreg: null,

          init() {
            this.unreg = w.LakasirPending.register(() => this.flush());
            this.$wire.$watch('cartDetail.customer_name', (v) => {
              // A name that is being typed, or is not saved yet, is never overwritten by the server.
              if (this.state !== 'saved' || document.activeElement === this.$refs.input) return;
              this.value = v || '';
              this.committed = v || '';
            });
          },

          destroy() {
            if (this.unreg) this.unreg();
            clearTimeout(this.timer);
          },

          onInput() {
            this.state = 'unsaved';
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.flush(), 800);
          },

          async flush() {
            clearTimeout(this.timer);
            const next = String(this.value || '').trim();
            if (next === String(this.committed || '').trim()) {
              this.state = 'saved';
              return true;
            }
            this.state = 'saving';
            try {
              const p = this.$wire.commitCustomerName(next);
              w.LakasirPending.track(p, 'name');
              await p;
              this.committed = next;
              this.state = String(this.value || '').trim() !== next ? 'unsaved' : 'saved';
              return true;
            } catch (e) {
              this.state = 'failed';
              return false;
            }
          },

          clear() {
            this.value = '';
            this.flush();
            if (this.$refs.input) this.$refs.input.focus();
          },

          get statusText() {
            if (!w.navigator.onLine && this.state !== 'saved') return t('You are offline');
            return { unsaved: t('Unsaved'), saving: t('Saving...'), saved: t('Saved'), failed: t('Failed to save, tap to retry') }[this.state];
          },
        }));

        A.data('fullscreen', () => ({
          requestFullscreen() { w.LakasirCashier.requestFullscreen(); },
        }));
      });

      // A morph (poll, button, another field) must never overwrite the input the cashier is typing in.
      const keepFocusedInputs = () => {
        w.Livewire.hook('morph.updating', ({ el, skip }) => {
          if (el === document.activeElement
            && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')
            && el.closest('[data-testid="cashier-root"]')) {
            skip();
          }
        });
      };
      if (w.Livewire && typeof w.Livewire.hook === 'function') keepFocusedInputs();
      else document.addEventListener('livewire:init', keepFocusedInputs, { once: true });

      // -------------------------------------------------------------------------------------------------------
      // events from the server
      // -------------------------------------------------------------------------------------------------------

      const openDialog = (name, data) => {
        w.dispatchEvent(new CustomEvent('lk-dialog-open', { detail: { name, data } }));
      };

      on('cart-qty-updated', (d) => {
        if (w.Alpine && w.Alpine.store('cart')) w.Alpine.store('cart').set(d.qty);
      });

      on('selling-created', (d) => {
        w._lakasirLastSelling = d.selling || null;
        w._lakasirLastSale = { selling: d.selling || null, kitchenPrinted: Boolean(d.kitchenPrinted), alreadyPaid: Boolean(d.alreadyPaid) };
      });

      /** Print one job through the shared queue; a kitchen ticket is acknowledged only after it really printed. */
      const runPrint = async (d) => {
        const R = w.LakasirReceipt;
        if (!R || typeof R.print !== 'function') {
          Toast.show({ id: 'print-missing', title: t('Print failed'), tone: 'danger', duration: 6000 });
          return false;
        }
        const opts = { mode: d.mode, reprint: Boolean(d.reprint), jobKey: d.job_key || undefined };
        if (d.ack) opts.ack = d.ack;
        const ok = await R.print(d.bill, opts);
        if (ok && d.ack && w.Livewire) {
          w.Livewire.dispatch('kitchen-ack', { ack: d.ack, mode: 'printed' });
        }
        return ok;
      };

      on('bill-print', (d) => { runPrint(d); });

      // A kitchen ticket that did not print stays unsent. The cashier decides: try again, or mark it as sent.
      on('lakasir-print-failed', (d) => {
        if (d.mode !== 'kitchen') return; // receipt / bill failures are already toasted by the print script
        const toastId = 'kitchen-failed-' + (d.jobKey || 'x');
        const actions = [{
          label: t('Try again'),
          testid: 'kitchen-print-retry',
          primary: true,
          onClick: async () => {
            const ok = await runPrint({ mode: 'kitchen', bill: d.payload, reprint: d.reprint, ack: d.ack, job_key: d.jobKey });
            if (ok) Toast.dismiss(toastId);
          },
        }];
        if (d.ack) {
          actions.push({
            label: t('Mark as sent without printing'),
            testid: 'kitchen-mark-sent',
            onClick: () => {
              if (w.Livewire) w.Livewire.dispatch('kitchen-ack', { ack: d.ack, mode: 'manual' });
              Toast.dismiss(toastId);
            },
          });
        }
        Toast.show({
          id: toastId,
          testid: 'kitchen-print-failed',
          tone: 'danger',
          title: t('Kitchen ticket not printed. Check the printer, then try again.'),
          body: d.reason === 'no_printer' ? t('Printer not configured') : '',
          duration: 0,
          actions,
        });
      });

      on('bill-preview', (d) => {
        const R = w.LakasirReceipt;
        const pre = document.getElementById('receipt-preview-text');
        w._lakasirPreview = { mode: d.mode, bill: d.bill };
        let text = '';
        try {
          text = R && typeof R.preview === 'function'
            ? R.preview(d.bill, { mode: d.mode, reprint: Boolean(d.bill && d.bill.kitchen && d.bill.kitchen.reprint) })
            : '';
        } catch (e) {
          text = String((e && e.message) || e);
        }
        if (pre) pre.textContent = text;
        w.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'receipt-preview-modal' } }));
      });

      on('bill-saved', (d) => {
        const wire = pageWire();
        const number = pad3(d.number);
        const actions = [
          {
            label: t('Pay bill'),
            testid: 'bill-saved-pay',
            primary: true,
            onClick: () => { Toast.dismiss('bill-saved'); w.Livewire.dispatch('open-bill-pay', { id: d.id }); },
          },
          {
            label: t('Open'),
            testid: 'bill-saved-open',
            onClick: () => { Toast.dismiss('bill-saved'); w.Livewire.dispatch('open-bill-selected', { id: d.id }); },
          },
        ];
        if (!d.sent && d.kitchen && wire) {
          actions.push({
            label: t('Send to kitchen'),
            testid: 'bill-saved-send',
            onClick: () => { Toast.dismiss('bill-saved'); wire.sendToKitchen(d.id); },
          });
        }
        // A bill without a name or table is labelled "Bill #012" itself: do not repeat the number.
        const label = d.label && d.label !== t('Bill #:number', { number }) && d.label !== 'Bill #' + number ? d.label : '';
        // Sent to the kitchen, or a shop without one: a plain confirmation. Only a kitchen shop is warned.
        const done = d.sent || !d.kitchen;
        Toast.show({
          id: 'bill-saved',
          testid: 'bill-saved-toast',
          tone: done ? 'success' : 'warning',
          title: done
            ? (label ? t('Bill #:number (:label) saved', { number, label }) : t('Bill #:number saved', { number }))
            : t('Bill #:number saved. NOT sent to the kitchen yet.', { number }),
          body: done ? '' : label,
          duration: 9000,
          actions,
        });
      });

      // The bill moved on while this screen held an old version. While the payment dialog is open it shows the same.
      on('bill-conflict', (d) => {
        const wire = pageWire();
        if (wire && wire.paymentPinned) return;
        openDialog('bill-conflict', { id: d.id, label: d.label || '', total: money(d.amount_due) });
      });

      on('takeaway-prompt', (d) => {
        openDialog('takeaway', { message: t('Table or name is empty. The ticket will read TAKEAWAY #:number.', { number: pad3(d.number) }) });
      });

      on('cancel-sent-request', (d) => {
        const remove = Number(d.newQty) <= 0;
        const message = remove
          ? t('Remove :name? The kitchen already received :qty. The kitchen is only told when you press Send to kitchen.', { name: d.name, qty: d.sentQty })
          : t('Reduce :name to :qty? The kitchen already received :sent. The kitchen is only told when you press Send to kitchen.', { name: d.name, qty: d.newQty, sent: d.sentQty });
        openDialog('cancel-sent', { itemId: d.itemId, newQty: d.newQty, message });
      });

      on('cart-item-removed', (d) => {
        if (!d.undoable) return;
        Toast.show({
          id: 'undo',
          testid: 'undo-snackbar',
          title: t('Item removed'),
          duration: 6000,
          actions: [{
            label: t('Undo'),
            testid: 'undo-restore',
            primary: true,
            onClick: async () => {
              Toast.dismiss('undo');
              const wire = pageWire();
              if (wire) {
                await wire.restoreCartItem(d.productId, d.qty, d.note === undefined ? null : d.note, d.discountPrice === undefined ? null : d.discountPrice, d.priceUnitId === undefined ? null : d.priceUnitId);
              }
            },
          }],
        });
      });

      // ---------------------------------------------------------------------------------------------------------
      // modals: camera scanner and focusing the field a tile asked for
      // ---------------------------------------------------------------------------------------------------------

      let camera = null;
      let cameraReady = true;

      const onCameraScan = (text) => {
        if (!cameraReady || !text) return;
        cameraReady = false;
        if (w.Livewire) w.Livewire.dispatch('product-scanned', { code: String(text) });
        Toast.show({ id: 'camera-scan', title: String(text), duration: 1500 });
        setTimeout(() => { cameraReady = true; }, 1000);
      };

      w.stopScanner = () => {
        if (camera && typeof w.Html5QrcodeScannerState !== 'undefined' && camera.getState() === w.Html5QrcodeScannerState.SCANNING) {
          camera.clear().catch(() => { /* closing quickly is fine */ });
        }
      };

      on('open-modal', (d) => {
        // Paying makes the "Bill saved" toast stale: its Pay bill and Open buttons would sit over the dialog.
        if (d.id === 'proceed-the-payment') Toast.dismiss('bill-saved');

        if (d.id === 'qr-scanner-modal' && typeof w.Html5QrcodeScanner !== 'undefined') {
          if (!camera) {
            camera = new w.Html5QrcodeScanner('qr-reader', { fps: 10, qrbox: { width: 300, height: 200 }, rememberLastUsedCamera: true }, false);
          }
          camera.render(onCameraScan, () => { /* a frame without a code is not an error */ });
        }

        if (d.inputId !== undefined) {
          const focusField = () => {
            const box = document.querySelector('[data-field="' + d.inputId + '"]');
            const el = box && (box.querySelector('input, textarea, select, [contenteditable]') || box);
            if (el && typeof el.focus === 'function') el.focus();
          };
          focusField();
          setTimeout(focusField, 100);
          setTimeout(focusField, 300);
        }
      });

      // ---------------------------------------------------------------------------------------------------------
      // physical barcode scanner: characters arrive < 50 ms apart and end with Enter
      // ---------------------------------------------------------------------------------------------------------

      if (!w._lakasirScannerBound) {
        w._lakasirScannerBound = true;

        const GAP = 50;
        const MIN_LENGTH = 4;
        let buffer = '';
        let last = 0;

        document.addEventListener('keydown', (e) => {
          if (e.isComposing || e.ctrlKey || e.metaKey || e.altKey) return;
          if (anyModalOpen()) {
            buffer = '';
            return;
          }

          const now = e.timeStamp || Date.now();

          if (e.key === 'Enter') {
            const code = buffer;
            buffer = '';
            if (code.length >= MIN_LENGTH) {
              e.preventDefault();
              e.stopImmediatePropagation();
              const el = document.activeElement;
              if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') && typeof el.value === 'string' && el.value.endsWith(code)) {
                el.value = el.value.slice(0, el.value.length - code.length);
                el.dispatchEvent(new Event('input', { bubbles: true }));
              }
              if (w.Livewire) w.Livewire.dispatch('product-scanned', { code });
            }
            return;
          }

          if (e.key.length !== 1) return;

          // A longer pause starts a new candidate, so only a burst of characters can ever reach MIN_LENGTH.
          if (buffer === '' || now - last > GAP) {
            buffer = e.key;
          } else {
            buffer += e.key;
          }
          last = now;
        }, true);
      }
    }
  })();
</script>
@endverbatim
