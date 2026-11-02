// Flow F (phone 360 wide): where the toasts sit. "Item removed / Undo" used to open at the top and cover the search
// box and the category chips; on a phone it now sits at the bottom, just above the order dock. With the order sheet
// open it stays on top (the bottom is the sheet), and on a desktop it stays on top.
const { launch, login, openCashier, settle, shot, BASE, STATE } = require('./lib.cjs');

const NOISE = /ERR_TUNNEL|fonts\.bunny|unpkg\.com|408|net::ERR/;
let failures = 0;

const box = (page, sel) => page.$eval(sel, (el) => {
  const r = el.getBoundingClientRect();
  return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right) };
}).catch(() => null);
const overlap = (a, b) => Boolean(a && b && a.top < b.bottom && a.bottom > b.top && a.left < b.right && a.right > b.left);

async function step(label, fn, page, log) {
  const before = log.length;
  try {
    await fn();
    console.log(`OK   ${label}`);
  } catch (e) {
    failures++;
    console.log(`FAIL ${label}: ${e.message.split('\n')[0]}`);
    await shot(page, `F-fail-${label.replace(/\W+/g, '_')}`);
  } finally {
    const rest = log.slice(before).filter((l) => !NOISE.test(l));
    if (rest.length) console.log('     ' + rest.join('\n     '));
  }
}

async function runPhone() {
  const { browser, context, page, log } = await launch({ viewport: { width: 360, height: 780 }, touch: true, mobile: true, storageState: STATE });
  try {
    await page.goto(`${BASE}/member/cashier`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) { await login(page); await context.storageState({ path: STATE }); }
    await openCashier(page);
    await settle(page, 600);

    const ids = await page.$$eval('[data-pv-card]', (els) => els.slice(0, 2).map((e) => e.dataset.pid));
    for (const id of ids) {
      await page.tap(`[data-testid=product-add-${id}]`);
      await page.waitForSelector(`[data-testid=product-qty-${id}]`, { state: 'visible' });
      await settle(page, 300);
    }

    await step('remove a line: the toast is above the dock and clear of the search box and chips', async () => {
      await page.tap(`[data-testid=product-dec-${ids[0]}]`);
      await page.waitForSelector('[data-testid=undo-snackbar]', { state: 'visible', timeout: 6000 });
      await settle(page, 250);
      const toast = await box(page, '[data-testid=undo-snackbar]');
      const search = await box(page, '[data-testid=product-search]');
      const chips = await box(page, '[data-testid=category-chips]');
      const dock = await box(page, '[data-testid=cart-dock]');
      await shot(page, 'F-1-toast-above-dock');
      console.log('     toast', JSON.stringify(toast), 'dock', JSON.stringify(dock));
      if (overlap(toast, search)) throw new Error('the toast covers the search box');
      if (overlap(toast, chips)) throw new Error('the toast covers the category chips');
      if (!dock || toast.bottom > dock.top) throw new Error('the toast is not above the dock');
    }, page, log);

    await step('Undo still works from the new place', async () => {
      await page.tap('[data-testid=undo-restore]');
      await page.waitForFunction((id) => document.querySelector(`[data-testid=product-qty-${id}]`)?.value === '1', ids[0], { timeout: 8000 });
      await settle(page, 300);
    }, page, log);

    await step('remove the last lines: no dock, the toast stays low and clear of the search box', async () => {
      for (const id of ids) {
        await page.tap(`[data-testid=product-dec-${id}]`);
        await settle(page, 500);
      }
      await page.waitForFunction(() => document.querySelector('[data-testid=cart-dock]') === null, null, { timeout: 8000 });
      await page.waitForTimeout(300);
      const toast = await box(page, '[data-testid=undo-snackbar]');
      const search = await box(page, '[data-testid=product-search]');
      const vh = page.viewportSize().height;
      await shot(page, 'F-2-toast-no-dock');
      if (!toast || overlap(toast, search) || vh - toast.bottom > 40) throw new Error(`toast ${JSON.stringify(toast)}`);
      await page.tap('[data-testid=undo-restore]');
      await settle(page, 600);
    }, page, log);

    await step('order sheet open: the toast stays on top', async () => {
      await page.tap('[data-testid=cart-dock]');
      await page.waitForSelector('[data-testid=cart-sheet]', { state: 'visible' });
      await page.waitForTimeout(300);
      await page.evaluate(() => window.LakasirToast.show({ id: 'probe', title: 'probe', duration: 0 }));
      await page.waitForTimeout(150);
      const toast = await box(page, '[data-toast-id=probe]');
      await shot(page, 'F-3-toast-over-sheet');
      if (!toast || toast.top > 60) throw new Error(`toast ${JSON.stringify(toast)}`);
    }, page, log);
  } catch (e) {
    failures++;
    console.log('STOPPED:', e.message.split('\n')[0]);
  } finally {
    const rest = log.filter((l) => !NOISE.test(l));
    if (rest.length) console.log('--- log ---\n' + rest.join('\n'));
    await browser.close();
  }
}

(async () => {
  await runPhone();
  console.log(failures ? `--- ${failures} failure(s) ---` : '--- all steps ok ---');
  process.exit(failures ? 1 : 0);
})();
