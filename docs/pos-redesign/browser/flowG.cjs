// Flow G (phone 360 wide): the speed of the cashier page.
//  1. The pieces that never change (QR scanner, success dialog, print preview, the three action dialogs) are sent by the
//     page load only. A cart tap must not send them again, and they must keep working after many cart taps. A tab from
//     before the release (it sends no X-Lakasir-Static-Parts header) still gets them, or an answer would delete them.
//  2. A tap on a product card shows the new quantity at once, while the server call is still running (the server is
//     slowed down by 1.5 s here), and the card falls back to the truth when the call fails or the server refuses.
const path = require('path');
const { launch, login, openCashier, settle, shot, BASE, STATE } = require('./lib.cjs');

const NOISE = /ERR_TUNNEL|fonts\.bunny|unpkg\.com|408|net::ERR|ui-avatars|Service Worker registration blocked|reading 'scope'/;
const DELAY = 1500;
let failures = 0;

async function step(label, fn, page, log) {
  const before = log.length;
  try {
    await fn();
    console.log(`OK   ${label}`);
  } catch (e) {
    failures++;
    console.log(`FAIL ${label}: ${e.message.split('\n')[0]}`);
    await shot(page, `G-fail-${label.replace(/\W+/g, '_')}`);
  } finally {
    const rest = log.slice(before).filter((l) => !NOISE.test(l));
    if (rest.length) console.log('     ' + rest.join('\n     '));
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const same = (a, b, what) => { if (a !== b) throw new Error(`${what}: expected ${JSON.stringify(b)}, got ${JSON.stringify(a)}`); };

(async () => {
  const { browser, context, page, log } = await launch({ viewport: { width: 360, height: 780 }, touch: true, mobile: true, serviceWorkers: 'block', storageState: STATE });
  await context.addInitScript({ path: path.join(__dirname, 'printer-stub.js') });

  // The text of every Livewire answer, to see what the server sends.
  const answers = [];
  page.on('response', async (r) => {
    if (r.url().includes('/livewire/update')) {
      try { answers.push(await r.text()); } catch (e) { /* the page went away */ }
    }
  });
  // Livewire calls the test itself holds back: counted from the moment the browser sends one until its answer is back.
  let inFlight = 0;
  const slowServer = (ms, fail = false) => page.route('**/livewire/update', async (route) => {
    inFlight++;
    try {
      await sleep(ms);
      if (fail) { await route.abort('failed'); return; }
      await route.fulfill({ response: await route.fetch() });
    } finally {
      inFlight--;
    }
  });

  try {
    await page.goto(`${BASE}/member/cashier`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) { await login(page); await context.storageState({ path: STATE }); }
    await openCashier(page);
    await settle(page, 600);

    // Cards that are not in the cart yet and can be bought.
    const ids = await page.$$eval('[data-pv-card]', (els) => els
      .filter((e) => !e.classList.contains('pv-out') && e.dataset.unlimited !== undefined)
      .filter((e) => getComputedStyle(e.querySelector('.pv-add')).display !== 'none')
      .slice(0, 4).map((e) => e.dataset.pid));
    if (ids.length < 4) throw new Error('the demo data needs at least 4 free products');
    const qtyOf = (id) => page.$eval(`[data-testid=product-qty-${id}]`, (el) => ({ shown: el.offsetParent !== null, value: el.value }));

    // -------------------------------------------------------------------------------------------------------
    await step('the page load brings the static parts (4 shells with content)', async () => {
      const parts = await page.$$eval('[data-static-part]', (els) => els.map((e) => `${e.dataset.staticPart}:${e.children.length > 0}`));
      same(parts.sort().join(','), 'dialogs:true,qr-scanner:true,receipt-preview:true,success:true', 'static parts');
      await page.evaluate(() => {
        for (const sel of ['[data-testid=takeaway-prompt]', '#qr-reader', '[data-testid=success-sheet]', '[data-testid=receipt-preview-dialog]']) {
          document.querySelector(sel).__kept = true;
        }
      });
    }, page, log);

    await step('three cart taps: the answers leave the static parts out and the parts stay as they were', async () => {
      const first = answers.length;
      await page.tap(`[data-testid=product-add-${ids[0]}]`);
      await page.waitForSelector(`[data-testid=product-qty-${ids[0]}]`, { state: 'visible' });
      await settle(page, 300);
      await page.tap(`[data-testid=product-inc-${ids[0]}]`);
      await settle(page, 300);
      await page.tap(`[data-testid=product-add-${ids[1]}]`);
      await settle(page, 400);
      if (answers.length <= first) throw new Error('no Livewire answer seen');
      for (const body of answers.slice(first)) {
        for (const marker of ['takeaway-prompt', 'id=\\"qr-reader\\"', 'success-sheet', 'receipt-preview-dialog']) {
          if (body.includes(marker)) throw new Error(`an answer still carries ${marker}`);
        }
      }
      const kept = await page.evaluate(() => ['[data-testid=takeaway-prompt]', '#qr-reader', '[data-testid=success-sheet]', '[data-testid=receipt-preview-dialog]']
        .map((sel) => Boolean(document.querySelector(sel) && document.querySelector(sel).__kept)));
      same(kept.join(','), 'true,true,true,true', 'the nodes put there by the page load');
    }, page, log);

    await step('a call without the page header (a tab from before the release) still gets every piece', async () => {
      const first = answers.length;
      await page.route('**/livewire/update', async (route) => {
        const headers = { ...route.request().headers() };
        delete headers['x-lakasir-static-parts'];
        await route.continue({ headers });
      });
      await page.tap(`[data-testid=product-inc-${ids[0]}]`);
      await settle(page, 600);
      await page.unroute('**/livewire/update');
      const body = answers.slice(first).find((b) => b.includes('cart-pay'));
      if (!body) throw new Error('no answer of the cashier component seen');
      for (const marker of ['takeaway-prompt', 'qr-reader', 'success-sheet', 'receipt-preview-dialog']) {
        if (!body.includes(marker)) throw new Error(`the answer lacks ${marker}`);
      }
      // the shells of this page ignore the answer: the nodes put there by the page load are still the same
      const kept = await page.evaluate(() => Boolean(document.querySelector('[data-testid=takeaway-prompt]').__kept));
      same(String(kept), 'true', 'the dialog node of the page load');
    }, page, log);

    await step('the QR scanner dialog still opens and closes', async () => {
      // #qr-reader itself stays empty without a camera, so the window around it is what is looked at.
      const shown = (want) => page.waitForFunction((w) => {
        const win = document.querySelector('#qr-reader').closest('.fi-modal-window');
        return (getComputedStyle(win).display !== 'none') === w;
      }, want, { timeout: 5000 });
      await page.tap('[data-testid=product-scan]');
      await shown(true);
      await shot(page, 'G-1-qr');
      await page.evaluate(() => window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'qr-scanner-modal' } })));
      await shown(false);
    }, page, log);

    await step('the three action dialogs still open (cancel sent, bill changed, takeaway)', async () => {
      for (const [name, testid] of [['cancel-sent', 'cancel-sent-dialog'], ['bill-conflict', 'bill-conflict'], ['takeaway', 'takeaway-prompt']]) {
        await page.evaluate((n) => window.dispatchEvent(new CustomEvent('lk-dialog-open', { detail: { name: n, data: { itemId: 1, newQty: 0, id: 1, total: '0' } } })), name);
        await page.waitForSelector(`[data-testid=${testid}]`, { state: 'visible', timeout: 3000 });
        await page.evaluate((n) => window.dispatchEvent(new CustomEvent('lk-dialog-close', { detail: { name: n } })), name);
        await page.waitForSelector(`[data-testid=${testid}]`, { state: 'hidden', timeout: 3000 });
      }
    }, page, log);

    await step('the print preview still opens', async () => {
      await page.evaluate(() => window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'receipt-preview-modal' } })));
      await page.waitForSelector('[data-testid=receipt-preview-dialog]', { state: 'visible', timeout: 3000 });
      await page.evaluate(() => window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'receipt-preview-modal' } })));
      await page.waitForSelector('[data-testid=receipt-preview-dialog]', { state: 'hidden', timeout: 3000 });
    }, page, log);

    await step('pay in cash after the taps: the success dialog is there', async () => {
      await page.tap('[data-testid=cart-dock]');
      await page.waitForSelector('[data-testid=cart-sheet]', { state: 'visible' });
      await settle(page, 300);
      await page.tap('[data-testid=cart-pay]');
      await page.waitForSelector('[data-testid=pay-sheet]', { state: 'visible', timeout: 8000 });
      await settle(page, 600);
      const cash = page.locator('[data-testid^=pay-method-]').filter({ hasText: /^\s*(Cash|Tunai)\s*$/i }).first();
      if (await cash.count()) await cash.click();
      await page.click('[data-testid=pay-quick-exact]');
      await page.click('[data-testid=pay-submit]');
      await page.waitForSelector('[data-testid=success-sheet]', { state: 'visible', timeout: 10000 });
      await shot(page, 'G-2-success');
      await page.tap('[data-testid=success-close]');
      await page.waitForSelector('[data-testid=success-sheet]', { state: 'hidden', timeout: 5000 });
      await settle(page, 500);
    }, page, log);

    // -------------------------------------------------------------------------------------------------------
    // The server is slow from here on: whatever the card shows within 600 ms did not wait for it.
    await slowServer(DELAY);

    await step('add: the stepper shows 1 before the server has answered, and 1 stays', async () => {
      await page.tap(`[data-testid=product-add-${ids[2]}]`);
      await page.waitForFunction((id) => {
        const el = document.querySelector(`[data-testid=product-qty-${id}]`);
        return el && el.offsetParent !== null && el.value === '1';
      }, ids[2], { timeout: 700 });
      await page.waitForTimeout(150); // Livewire sends the call a moment after the tap
      if (inFlight < 1) throw new Error('the server had already answered, so this proves nothing');
      await settle(page, DELAY + 600);
      const q = await qtyOf(ids[2]);
      same(q.value, '1', 'quantity after the answer');
    }, page, log);

    await step('three quick taps on + show 4 at once and the server ends on 4', async () => {
      for (let i = 0; i < 3; i++) await page.tap(`[data-testid=product-inc-${ids[2]}]`);
      await page.waitForFunction((id) => document.querySelector(`[data-testid=product-qty-${id}]`).value === '4', ids[2], { timeout: 700 });
      await page.waitForTimeout(150); // Livewire sends the call a moment after the tap
      if (inFlight < 1) throw new Error('the server had already answered, so this proves nothing');
      await page.waitForTimeout(DELAY * 3 + 800);
      await settle(page, 300);
      same((await qtyOf(ids[2])).value, '4', 'quantity after the answers');
    }, page, log);

    await step('taking the last one away shows "Add" at once (and Undo comes after the answer)', async () => {
      await page.tap(`[data-testid=product-add-${ids[3]}]`);
      await settle(page, DELAY + 600);
      await page.tap(`[data-testid=product-dec-${ids[3]}]`);
      await page.waitForFunction((id) => {
        const add = document.querySelector(`[data-testid=product-add-${id}]`);
        return add && add.offsetParent !== null;
      }, ids[3], { timeout: 700 });
      await page.waitForSelector('[data-testid=undo-snackbar]', { state: 'visible', timeout: DELAY + 3000 });
      await settle(page, 300);
    }, page, log);

    await step('a call that fails: the card goes back to the cart it really has', async () => {
      await page.unroute('**/livewire/update');
      await slowServer(500, true);
      const before = await page.$eval(`[data-testid=product-add-${ids[3]}]`, (el) => el.offsetParent !== null);
      if (!before) throw new Error('the card should start without a quantity');
      await page.tap(`[data-testid=product-add-${ids[3]}]`);
      await page.waitForFunction((id) => document.querySelector(`[data-testid=product-qty-${id}]`).offsetParent !== null, ids[3], { timeout: 400 });
      await page.waitForFunction((id) => {
        const add = document.querySelector(`[data-testid=product-add-${id}]`);
        return add && add.offsetParent !== null;
      }, ids[3], { timeout: 8000 });
      await page.unroute('**/livewire/update');
      await settle(page, 300);
    }, page, log);

    await step('the rules of the helper (no server): stock limit and sent bills', async () => {
      const r = await page.evaluate(async () => {
        const never = () => new Promise(() => {});
        const store = Alpine.store('cart');
        const held = (id) => Boolean(store.hold[id]);
        const out = {};
        // unlimited: ahead; limited with room: ahead; limited without room: not ahead
        LakasirPV.add({ $parent: { addCart: never } }, 990001, null); out.unlimited = store.of(990001);
        LakasirPV.add({ $parent: { addCart: never } }, 990002, 5); out.room = store.of(990002);
        LakasirPV.add({ $parent: { addCart: never } }, 990003, 0); out.noRoom = store.of(990003);
        // reduce: private cart ahead; bill not sent ahead; bill sent or unknown not ahead
        store.set({ 990004: 3, 990005: 3, 990006: 3, 990007: 3 });
        LakasirPV.reduce({ $parent: { reduceCart: never, openBillId: null, openBill: null } }, 990004); out.cart = store.of(990004);
        LakasirPV.reduce({ $parent: { reduceCart: never, openBillId: 7, openBill: { sent: false } } }, 990005); out.billNotSent = store.of(990005);
        LakasirPV.reduce({ $parent: { reduceCart: never, openBillId: 7, openBill: { sent: true } } }, 990006); out.billSent = store.of(990006);
        LakasirPV.reduce({ $parent: { reduceCart: never, openBillId: 7, openBill: null } }, 990007); out.billUnknown = store.of(990007);
        for (const id of [990001, 990002, 990003, 990004, 990005, 990006, 990007]) while (held(id)) store.end(id);
        store.set({});
        return out;
      });
      same(JSON.stringify(r), JSON.stringify({ unlimited: 1, room: 1, noRoom: 0, cart: 2, billNotSent: 2, billSent: 3, billUnknown: 3 }), 'helper rules');
    }, page, log);
  } catch (e) {
    failures++;
    console.log('STOPPED:', e.message.split('\n')[0]);
    await shot(page, 'G-stopped');
  } finally {
    const rest = log.filter((l) => !NOISE.test(l));
    if (rest.length) console.log('--- log ---\n' + rest.join('\n'));
    await browser.close();
  }
  console.log(failures ? `--- ${failures} failure(s) ---` : '--- all steps ok ---');
  process.exit(failures ? 1 : 0);
})();
