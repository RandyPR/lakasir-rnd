{{--
  HEAD_END of the cashier page (full page load only, registered by Cashier::registerRenderHooks()).

  1. The product view mode ('grid' | 'list') is put on <html data-product-view> BEFORE the list is painted, so the
     list never flashes in the wrong mode. Only that attribute changes between modes: there is one DOM per product.
  2. window.LakasirPV: the small helpers the product cards call (quantity map of the active context, number input,
     cart calls on the parent component). Plain JS, no Alpine required to load it.
  3. The pv-* styles. Every class here starts with pv-, and everything is scoped under .pv-browser.

  Nothing in this file may print user data.
--}}
<script>
  (function () {
    var KEY = 'lakasir.cashier.productView';
    var root = document.documentElement;
    var mode = 'grid';

    try {
      mode = window.localStorage.getItem(KEY) === 'list' ? 'list' : 'grid';
    } catch (e) {
      mode = 'grid';
    }
    root.setAttribute('data-product-view', mode);

    if (window.LakasirPV && window.LakasirPV.version >= 1) {
      return;
    }

    function getView() {
      return root.getAttribute('data-product-view') === 'list' ? 'list' : 'grid';
    }

    function setView(next) {
      next = next === 'list' ? 'list' : 'grid';
      root.setAttribute('data-product-view', next);
      try {
        window.localStorage.setItem(KEY, next);
      } catch (e) {}
    }

    // The cashier bootstrap owns LakasirCashier; this is only the fallback when it did not load yet.
    if (!window.LakasirCashier) {
      window.LakasirCashier = { getView: getView, setView: setView };
    }

    // product_id => qty of the ACTIVE context (private cart or the open bill). The Alpine store 'cart' is filled by the
    // cashier bootstrap and by the 'cart-qty-updated' event, which carries the map of the active context.
    function qty(id) {
      var store = null;
      try {
        store = window.Alpine && typeof window.Alpine.store === 'function' ? window.Alpine.store('cart') : null;
      } catch (e) {
        store = null;
      }
      if (!store) {
        return 0;
      }
      var n = typeof store.of === 'function' ? store.of(id) : (store.qty ? store.qty[id] : 0);
      n = Number(n);
      return isFinite(n) && n > 0 ? n : 0;
    }

    var formatter = null;
    var formatterLocale = null;

    function fmt(n) {
      n = Number(n) || 0;
      try {
        var locale = window.lakasirLocale || document.documentElement.lang || 'id';
        if (!formatter || formatterLocale !== locale) {
          formatter = new Intl.NumberFormat(locale, { maximumFractionDigits: 3, useGrouping: false });
          formatterLocale = locale;
        }
        return formatter.format(n);
      } catch (e) {
        return String(Math.round(n * 1000) / 1000).replace('.', ',');
      }
    }

    // '12,5' and '12.5' are both 12.5; empty is 0 (the line is removed); anything else is rejected (null).
    function parse(text) {
      var s = String(text == null ? '' : text).replace(/\s+/g, '').replace(',', '.');
      if (s === '') {
        return 0;
      }
      if (!/^\d*\.?\d*$/.test(s) || s === '.') {
        return null;
      }
      var n = Math.round(parseFloat(s) * 1000) / 1000;
      // 0.0004 is not "nothing": it would round to 0 and remove the line by accident.
      if (n === 0 && parseFloat(s) > 0) {
        return null;
      }
      return isFinite(n) && n <= 999999999 ? n : null;
    }

    // The digits get smaller when the number is long, so it never leaves the stepper (see .pv-q[data-len]).
    function fit(input) {
      input.setAttribute('data-len', String(input.value.length));
    }

    // Shows the quantity of the store in the input, but never overwrites what the cashier is typing.
    function paint(input, n) {
      if (document.activeElement !== input) {
        input.value = fmt(n);
      }
      fit(input);
    }

    function sync(input, id) {
      input.value = fmt(qty(id));
      fit(input);
    }

    // Tell the payment screen about calls that are still running (LakasirPending is optional).
    function track(promise) {
      if (!promise || typeof promise.then !== 'function') {
        return promise;
      }
      try {
        var pending = window.LakasirPending;
        if (pending && typeof pending.track === 'function') {
          pending.track(promise, 'cart');
        }
      } catch (e) {}
      return promise;
    }

    function call(wire, method, id, extra) {
      var parent = wire && wire.$parent;
      if (!parent || typeof parent[method] !== 'function') {
        return null;
      }
      return track(extra === undefined ? parent[method](id) : parent[method](id, extra));
    }

    function add(wire, id) {
      return call(wire, 'addCart', id);
    }

    function reduce(wire, id) {
      return call(wire, 'reduceCart', id);
    }

    // The number typed in a card: the quantity becomes exactly that (0 or empty removes the line).
    function commit(wire, id, input) {
      var next = parse(input.value);
      var current = qty(id);

      if (next === null || Math.abs(next - current) < 0.0005) {
        sync(input, id);
        return null;
      }

      var promise = call(wire, 'addCart', id, { amount: next });
      if (!promise) {
        sync(input, id);
        return null;
      }

      // The server may refuse (stock) and then keeps the old quantity: show what is really in the cart.
      var done = function () { sync(input, id); };
      promise.then(done, done);

      return promise;
    }

    // Short highlight of the card that was just changed ('cart-item-touched' {productId}).
    function flash(detail) {
      var id = detail && typeof detail === 'object'
        ? (detail.productId !== undefined ? detail.productId : (detail[0] && detail[0].productId))
        : detail;
      if (id === undefined || id === null || isNaN(Number(id))) {
        return;
      }
      var card = document.querySelector('[data-pv-card][data-pid="' + Number(id) + '"]');
      if (!card) {
        return;
      }
      card.classList.remove('pv-flash');
      void card.offsetWidth;
      card.classList.add('pv-flash');
      window.setTimeout(function () { card.classList.remove('pv-flash'); }, 700);
    }

    window.LakasirPV = {
      version: 1,
      view: getView,
      qty: qty,
      fmt: fmt,
      parse: parse,
      fit: fit,
      paint: paint,
      sync: sync,
      add: add,
      reduce: reduce,
      commit: commit,
      flash: flash
    };
  })();
</script>
<style>
  /* ------------------------------------------------------------------------------------------------------------
     Product list of the cashier. Light tokens first, dark ones under Filament's .dark class.
     --pv-sticky-top: the page can push the sticky toolbar below its own header (default 0).
     ------------------------------------------------------------------------------------------------------------ */
  .pv-browser {
    --pv-surface: #ffffff;
    --pv-bg: #f3f4f6;
    --pv-ink: #111827;
    --pv-muted: #4b5563;
    --pv-line: #e5e7eb;
    --pv-img: #f1f5f9;
    --pv-primary: #c2410c;
    --pv-on-primary: #ffffff;
    --pv-soft: #ffedd5;
    --pv-on-soft: #9a3412;
    --pv-warn: #92400e;
    --pv-warn-soft: #fef3c7;
    --pv-danger: #b91c1c;
    --pv-focus: #2563eb;
    display: block;
    min-width: 0;
    max-width: 100%;
    color: var(--pv-ink);
  }

  .dark .pv-browser {
    --pv-surface: #111827;
    --pv-bg: #1f2937;
    --pv-ink: #f3f4f6;
    --pv-muted: #9ca3af;
    --pv-line: #374151;
    --pv-img: #1f2937;
    --pv-primary: #fb923c;
    --pv-on-primary: #1f1209;
    --pv-soft: #3b2412;
    --pv-on-soft: #fdba74;
    --pv-warn: #fbbf24;
    --pv-warn-soft: #3a2d0c;
    --pv-danger: #f87171;
    --pv-focus: #93c5fd;
  }

  .pv-browser *,
  .pv-browser *::before,
  .pv-browser *::after { box-sizing: border-box; }
  .pv-browser [x-cloak] { display: none !important; }
  .pv-browser button { font: inherit; color: inherit; background: none; border: 0; padding: 0; margin: 0; cursor: pointer; text-align: inherit; -webkit-tap-highlight-color: transparent; }
  .pv-browser button:disabled { cursor: not-allowed; }
  .pv-browser :focus-visible { outline: 3px solid var(--pv-focus); outline-offset: 2px; }
  .pv-browser .pv-sprite { position: absolute; width: 0; height: 0; overflow: hidden; }
  .pv-browser .pv-i { width: 20px; height: 20px; flex: none; display: block; }
  .pv-browser .pv-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

  /* ---- toolbar: search, view toggle, scan, category chips ---- */
  .pv-browser .pv-toolbar {
    position: sticky;
    top: var(--pv-sticky-top, 0px);
    z-index: 5;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 8px;
    min-width: 0;
    padding: 8px 12px 0;
    background: var(--pv-surface);
    border-bottom: 1px solid var(--pv-line);
  }
  .pv-browser .pv-bar { display: flex; align-items: center; gap: 8px; min-width: 0; }
  .pv-browser .pv-search {
    flex: 1 1 0;
    min-width: 0;
    height: 44px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-left: 12px;
    border: 1px solid var(--pv-line);
    border-radius: 12px;
    background: var(--pv-bg);
    color: var(--pv-muted);
  }
  .pv-browser .pv-search:focus-within { outline: 3px solid var(--pv-focus); outline-offset: 2px; }
  .pv-browser input.pv-sin {
    flex: 1 1 0;
    min-width: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    outline: none;
    color: var(--pv-ink);
    font-size: 1rem;
    -webkit-appearance: none;
    appearance: none;
  }
  .pv-browser input.pv-sin:focus { box-shadow: none; outline: none; border: 0; }
  .pv-browser input.pv-sin::placeholder { color: var(--pv-muted); opacity: 1; }
  .pv-browser input.pv-sin::-webkit-search-cancel-button,
  .pv-browser input.pv-sin::-webkit-search-decoration { -webkit-appearance: none; display: none; }
  .pv-browser .pv-ib { width: 44px; height: 44px; flex: none; display: grid; place-items: center; border-radius: 12px; color: var(--pv-muted); }
  .pv-browser .pv-scan { border: 1px solid var(--pv-line); background: var(--pv-surface); color: var(--pv-ink); }
  .pv-browser .pv-seg { display: flex; flex: none; height: 44px; border: 1px solid var(--pv-line); border-radius: 12px; background: var(--pv-surface); overflow: hidden; }
  .pv-browser .pv-seg button { width: 44px; height: 100%; display: grid; place-items: center; color: var(--pv-muted); border-radius: 0; }
  .pv-browser .pv-seg button[aria-pressed="true"] { background: var(--pv-soft); color: var(--pv-on-soft); }

  .pv-browser .pv-chips {
    display: flex;
    gap: 8px;
    min-width: 0;
    margin: 0 -12px;
    padding: 2px 12px 8px;
    overflow-x: auto;
    overscroll-behavior-x: contain;
    scroll-snap-type: x proximity;
    scrollbar-width: none;
  }
  .pv-browser .pv-chips::-webkit-scrollbar { display: none; }
  .pv-browser .pv-chip {
    flex: none;
    max-width: 80%;
    height: 44px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 6px 0 14px;
    border: 1px solid var(--pv-line);
    border-radius: 999px;
    background: var(--pv-surface);
    font-size: .86rem;
    font-weight: 600;
    white-space: nowrap;
    scroll-snap-align: start;
  }
  .pv-browser .pv-chip.pv-chip-plain { padding-right: 14px; }
  .pv-browser .pv-chip-l { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
  .pv-browser .pv-chip-n {
    min-width: 24px;
    height: 24px;
    display: grid;
    place-items: center;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--pv-bg);
    color: var(--pv-muted);
    font-size: .75rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }
  .pv-browser .pv-chip[aria-pressed="true"] { background: var(--pv-primary); border-color: var(--pv-primary); color: var(--pv-on-primary); }
  .pv-browser .pv-chip[aria-pressed="true"] .pv-chip-n { background: var(--pv-on-primary); color: var(--pv-primary); }

  /* ---- status line, empty state, load more ---- */
  .pv-browser .pv-stat {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-height: 34px;
    margin: 0;
    padding: 6px 12px 0;
    color: var(--pv-muted);
    font-size: .78rem;
  }
  .pv-browser .pv-stat-l { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .pv-browser .pv-reset { flex: none; min-height: 44px; margin-block: -6px; padding: 0 8px; color: var(--pv-primary); font-weight: 700; text-decoration: underline; }
  .pv-browser .pv-empty { display: grid; justify-items: center; gap: 4px; padding: 28px 12px; color: var(--pv-muted); font-size: .86rem; text-align: center; }
  .pv-browser .pv-empty b { color: var(--pv-ink); font-size: .95rem; overflow-wrap: anywhere; }
  .pv-browser .pv-more {
    display: block;
    width: calc(100% - 24px);
    min-height: 44px;
    margin: 0 12px 16px;
    border: 1px solid var(--pv-line);
    border-radius: 12px;
    background: var(--pv-surface);
    font-size: .86rem;
    font-weight: 700;
    text-align: center;
  }
  .pv-browser .pv-more:disabled { opacity: .6; }

  /* ---- the list: ONE DOM per product, the mode only changes these rules ---- */
  .pv-browser .pv-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 140px), 1fr));
    gap: 10px;
    align-content: start;
    min-width: 0;
    padding: 6px 12px 16px;
    transition: opacity .15s;
  }
  .pv-browser .pv-list.pv-busy { opacity: .55; }
  @media (min-width: 1280px) {
    .pv-browser .pv-list { grid-template-columns: repeat(auto-fill, minmax(min(100%, 168px), 1fr)); }
  }
  @media (max-width: 359px) {
    .pv-browser .pv-list { gap: 8px; padding-inline: 8px; }
  }

  .pv-browser .pv-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
    padding: 8px;
    overflow: hidden;
    border: 1px solid var(--pv-line);
    border-radius: 14px;
    background: var(--pv-surface);
  }
  .pv-browser .pv-card.pv-in { border-color: var(--pv-primary); box-shadow: 0 0 0 1px var(--pv-primary) inset; }
  .pv-browser .pv-card.pv-flash { animation: pv-pop .5s ease-out; }
  @keyframes pv-pop {
    0% { box-shadow: 0 0 0 0 var(--pv-primary); }
    100% { box-shadow: 0 0 0 8px transparent; }
  }
  @media (prefers-reduced-motion: reduce) {
    .pv-browser .pv-card.pv-flash { animation: none; }
    .pv-browser .pv-list { transition: none; }
  }

  .pv-browser .pv-media { position: relative; min-width: 0; aspect-ratio: 4 / 3; overflow: hidden; border-radius: 10px; background: var(--pv-img); }
  .pv-browser .pv-media img { position: absolute; inset: 0; z-index: 1; display: block; width: 100%; height: 100%; object-fit: cover; }
  .pv-browser .pv-ph { position: absolute; inset: 0; margin: auto; width: 36%; height: 36%; max-width: 48px; max-height: 48px; color: var(--pv-muted); opacity: .5; }
  .pv-browser .pv-name {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    min-width: 0;
    min-height: 2.5em;
    margin: 0;
    overflow: hidden;
    overflow-wrap: anywhere;
    font-size: .86rem;
    font-weight: 600;
    line-height: 1.25;
  }
  .pv-browser .pv-meta { display: flex; flex-wrap: wrap; align-items: baseline; gap: 2px 8px; min-width: 0; }
  .pv-browser .pv-price { font-size: .9rem; font-weight: 700; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
  .pv-browser .pv-badge {
    position: absolute;
    left: 14px;
    top: 14px;
    z-index: 2;
    max-width: calc(100% - 28px);
    padding: 2px 7px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--pv-surface);
    color: var(--pv-muted);
    font-size: .75rem;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .pv-browser .pv-badge.pv-low { background: var(--pv-warn-soft); color: var(--pv-warn); }
  .pv-browser .pv-badge.pv-nil { background: var(--pv-ink); color: var(--pv-surface); }
  /* "Left N after open bills": a warning only, the product can still be added */
  .pv-browser .pv-hold {
    max-width: 100%;
    padding: 2px 7px;
    border-radius: 8px;
    background: var(--pv-warn-soft);
    color: var(--pv-warn);
    font-size: .75rem;
    font-weight: 600;
    line-height: 1.3;
    overflow-wrap: anywhere;
  }
  .pv-browser .pv-act { min-width: 0; margin-top: auto; }
  .pv-browser .pv-card.pv-out .pv-media img { filter: grayscale(1); opacity: .55; }
  .pv-browser .pv-card.pv-out .pv-name { color: var(--pv-muted); }

  .pv-browser .pv-add {
    width: 100%;
    min-width: 0;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border-radius: 12px;
    background: var(--pv-primary);
    color: var(--pv-on-primary);
    font-size: .9rem;
    font-weight: 700;
  }
  .pv-browser .pv-add:disabled { border: 1px dashed var(--pv-line); background: var(--pv-bg); color: var(--pv-muted); }
  .pv-browser .pv-add:not(:disabled):active { filter: brightness(.92); }

  /* quantity stepper: [-] [number] [+]; the number is an input and may shrink, the buttons never */
  .pv-browser .pv-stepper {
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr) 44px;
    width: 100%;
    min-width: 0;
    height: 44px;
    overflow: hidden;
    border-radius: 12px;
    background: var(--pv-soft);
    color: var(--pv-on-soft);
  }
  .pv-browser .pv-step { display: grid; place-items: center; height: 100%; border-radius: 12px; }
  .pv-browser .pv-step:disabled { opacity: .35; }
  .pv-browser input.pv-q {
    display: block;
    width: 100%;
    min-width: 0;
    height: 100%;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    outline: none;
    color: var(--pv-ink);
    font-size: 1rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    text-align: center;
    -webkit-appearance: none;
    appearance: none;
  }
  .pv-browser input.pv-q:focus { outline: 2px solid var(--pv-focus); outline-offset: -3px; background: var(--pv-surface); box-shadow: none; border: 0; }
  .pv-browser input.pv-q[data-len="5"] { font-size: .85rem; }
  .pv-browser input.pv-q[data-len="6"],
  .pv-browser input.pv-q[data-len="7"] { font-size: .72rem; }
  .pv-browser input.pv-q[data-len="8"],
  .pv-browser input.pv-q[data-len="9"],
  .pv-browser input.pv-q[data-len="10"],
  .pv-browser input.pv-q[data-len="11"],
  .pv-browser input.pv-q[data-len="12"] { font-size: .6rem; }

  /* ---- compact list mode: a 60 px row, thumbnail 48 px (40 px under 360) ---- */
  html[data-product-view="list"] .pv-browser .pv-list {
    grid-template-columns: minmax(0, 1fr);
    gap: 6px;
  }
  @media (min-width: 1024px) {
    html[data-product-view="list"] .pv-browser .pv-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  html[data-product-view="list"] .pv-browser .pv-card {
    display: grid;
    grid-template-columns: 48px minmax(0, 1fr) auto;
    grid-template-areas: "m n a" "m p a";
    align-items: center;
    column-gap: 10px;
    row-gap: 1px;
    min-height: 60px;
    padding: 6px 8px 6px 6px;
    border-radius: 12px;
  }
  html[data-product-view="list"] .pv-browser .pv-media { grid-area: m; width: 48px; height: 48px; aspect-ratio: 1 / 1; border-radius: 10px; }
  html[data-product-view="list"] .pv-browser .pv-name { grid-area: n; min-height: 0; }
  html[data-product-view="list"] .pv-browser .pv-meta { grid-area: p; }
  html[data-product-view="list"] .pv-browser .pv-act { grid-area: a; margin: 0; }
  html[data-product-view="list"] .pv-browser .pv-price { font-size: .82rem; }
  html[data-product-view="list"] .pv-browser .pv-badge {
    position: static;
    max-width: 100%;
    padding: 0;
    background: none;
    font-weight: 600;
  }
  html[data-product-view="list"] .pv-browser .pv-badge.pv-low { background: none; }
  html[data-product-view="list"] .pv-browser .pv-badge.pv-nil { background: none; color: var(--pv-danger); }
  html[data-product-view="list"] .pv-browser .pv-add { width: 44px; padding: 0; }
  html[data-product-view="list"] .pv-browser .pv-lbl { display: none; }
  /* a fixed width: the input has an intrinsic width that would otherwise stretch the auto column */
  html[data-product-view="list"] .pv-browser .pv-stepper { width: 132px; grid-template-columns: 44px minmax(0, 1fr) 44px; }
  @media (max-width: 359px) {
    html[data-product-view="list"] .pv-browser .pv-card { grid-template-columns: 40px minmax(0, 1fr) auto; column-gap: 8px; padding: 6px; }
    html[data-product-view="list"] .pv-browser .pv-media { width: 40px; height: 40px; }
  }
</style>
