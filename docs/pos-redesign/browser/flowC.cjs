// Flow C (fnb, desktop): what goes through the page handle of the shared script (pageWire): typing a quantity in the
// stepper, Undo after a removal, "Send to kitchen" in the toast of a bill saved without the kitchen.
const path = require('path');
const { launch, login, openCashier, settle, shot, BASE, STATE } = require('./lib.cjs');

const NOISE = /ERR_TUNNEL|fonts\.bunny|unpkg\.com|408|net::ERR/;
let failures = 0;

async function cardId(page, name) {
  return page.evaluate((n) => {
    for (const el of document.querySelectorAll('[data-testid^=product-card-]')) {
      const h = el.querySelector('.pv-name');
      if (h && h.textContent.trim() === n) return el.dataset.pid;
    }
    return null;
  }, name);
}

async function add(page, name) {
  const id = await cardId(page, name);
  const btn = page.locator(`[data-testid=product-add-${id}]`);
  if (await btn.isVisible()) await btn.click(); else await page.click(`[data-testid=product-inc-${id}]`);
  await settle(page, 300);
}

async function lineId(page, name) {
  const item = page.locator('[data-testid^=cart-item-]').filter({ hasText: name }).first();
  return (await item.getAttribute('data-testid')).replace('cart-item-', '');
}

const text = async (page, sel) => (await page.innerText(sel)).replace(/\s+/g, ' ').trim();

async function step(label, fn, page, log) {
  const before = log.length;
  try {
    await fn();
    console.log(`OK   ${label}`);
  } catch (e) {
    failures++;
    console.log(`FAIL ${label}: ${e.message.split('\n')[0]}`);
    await shot(page, `C-fail-${label.replace(/\W+/g, '_')}`);
  } finally {
    const rest = log.slice(before).filter((l) => !NOISE.test(l));
    if (rest.length) console.log('     ' + rest.join('\n     '));
  }
}

(async () => {
  const { browser, context, page, log } = await launch({ viewport: { width: 1280, height: 900 }, storageState: STATE });
  await context.addInitScript({ path: path.join(__dirname, 'printer-stub.js') });
  try {
    await page.goto(`${BASE}/member/cashier`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) { await login(page); await context.storageState({ path: STATE }); }
    await openCashier(page);
    await settle(page, 600);

    await step('type 12,5 in the stepper of a line', async () => {
      await add(page, 'Air Mineral');
      const id = await lineId(page, 'Air Mineral');
      const input = page.locator(`[data-testid=cart-item-qty-${id}]`);
      await input.click();
      await input.fill('12,5');
      await input.press('Enter');
      await settle(page, 900);
      const qty = await page.locator(`[data-testid=cart-item-qty-${id}]`).inputValue();
      console.log('     qty now:', qty, ' unit:', await text(page, `[data-testid=cart-item-unit-${id}]`));
      if (qty !== '12.5') throw new Error(`qty is ${qty}`);
    }, page, log);

    await step('remove a line, then Undo brings it back with its quantity', async () => {
      await add(page, 'Kerupuk');
      const id = await lineId(page, 'Kerupuk');
      await page.click(`[data-testid=cart-item-minus-${id}]`);
      await page.waitForSelector('[data-testid=undo-snackbar]', { state: 'visible', timeout: 6000 });
      await settle(page, 400);
      const gone = (await page.locator('[data-testid^=cart-item-]').filter({ hasText: 'Kerupuk' }).count()) === 0;
      await page.click('[data-testid=undo-restore]');
      await settle(page, 1000);
      const back = await page.locator('[data-testid^=cart-item-]').filter({ hasText: 'Kerupuk' }).count();
      console.log('     removed:', gone, ' back:', back);
      if (!gone || back < 1) throw new Error('undo did not bring the line back');
    }, page, log);

    await step('a bill saved without the kitchen offers "Send to kitchen" in its toast', async () => {
      await page.fill('[data-testid=customer-name-input]', 'Dewi');
      await page.keyboard.press('Tab');
      await settle(page, 1200);
      await page.click('[data-testid=save-bill-only]');
      await page.waitForSelector('[data-testid=bill-saved-toast]', { state: 'visible', timeout: 8000 });
      console.log('     toast:', await text(page, '[data-testid=bill-saved-toast]'));
      await shot(page, 'C-1-saved-toast');
      const n = await page.evaluate(() => window.__prints.length);
      await page.click('[data-testid=bill-saved-send]');
      await settle(page, 1500);
      const m = await page.evaluate(() => window.__prints.length);
      console.log('     prints:', m, 'was', n);
      if (m !== n + 1) throw new Error('no kitchen ticket printed from the toast');
    }, page, log);
  } catch (e) {
    failures++;
    console.log('STOPPED:', e.message.split('\n')[0]);
  } finally {
    const rest = log.filter((l) => !NOISE.test(l));
    if (rest.length) console.log('--- log ---\n' + rest.join('\n'));
    console.log(failures ? `--- ${failures} failure(s) ---` : '--- all steps ok ---');
    await browser.close();
  }
})();
