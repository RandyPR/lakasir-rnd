// Flow B (fnb, desktop): takeaway prompt "Fill the table", kitchen print failure + retry, provisional bill, bills
// drawer search and filters, void, two tabs on one bill (conflict, then QRIS), credit with a down payment.
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
  if (!id) throw new Error(`no card ${name}`);
  const btn = page.locator(`[data-testid=product-add-${id}]`);
  if (await btn.isVisible()) await btn.click(); else await page.click(`[data-testid=product-inc-${id}]`);
  await settle(page, 300);
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
    await shot(page, `B-fail-${label.replace(/\W+/g, '_')}`);
  } finally {
    const rest = log.slice(before).filter((l) => !NOISE.test(l));
    if (rest.length) console.log('     ' + rest.join('\n     '));
  }
}

async function openBillFromDrawer(page, match) {
  await page.click('[data-testid=bills-toggle]');
  await page.waitForSelector('[data-testid=bills-drawer]', { state: 'visible' });
  await settle(page, 600);
  const row = page.locator('[data-testid^=bill-row-]').filter({ hasText: match }).first();
  const id = (await row.getAttribute('data-testid')).replace('bill-row-', '');
  const open = page.locator(`[data-testid=bill-open-${id}]`);
  if (await open.isVisible()) await open.click(); else { await page.click(`[data-testid=bill-menu-${id}]`); await page.click(`[data-testid=bill-open-alt-${id}]`); }
  await settle(page, 800);
  await page.waitForSelector('[data-testid=bill-chip],[data-testid=bill-resume-chip]', { timeout: 8000 });
  return id;
}

(async () => {
  const { browser, context, page, log } = await launch({ viewport: { width: 1280, height: 900 }, storageState: STATE });
  await context.addInitScript({ path: path.join(__dirname, 'printer-stub.js') });
  try {
    await page.goto(`${BASE}/member/cashier`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) { await login(page); await context.storageState({ path: STATE }); }
    await openCashier(page);
    await settle(page, 600);

    await step('takeaway prompt -> Fill the table -> table 3', async () => {
      await add(page, 'Soto Ayam');
      await add(page, 'Es Teh Manis');
      await page.click('[data-testid=send-kitchen]');
      await page.waitForSelector('[data-testid=takeaway-prompt]', { state: 'visible', timeout: 8000 });
      await page.click('[data-testid=takeaway-fill]');
      await settle(page, 500);
      const modal = page.locator('.fi-modal-window:visible').filter({ hasText: 'Meja' }).first();
      await modal.waitFor({ state: 'visible', timeout: 5000 });
      await modal.locator('button', { hasText: /^\s*3\s*$/ }).first().click();
      await settle(page, 800);
      console.log('     table button:', await text(page, '[data-testid=table-button]'));
      if (!/3/.test(await text(page, '[data-testid=table-button]'))) throw new Error('table 3 not set');
    }, page, log);

    await step('send to kitchen with the printer failing -> toast, then retry', async () => {
      await page.evaluate(() => { window.__printFail = true; });
      const n = await page.evaluate(() => window.__prints.length);
      await page.click('[data-testid=send-kitchen]');
      await page.waitForSelector('[data-testid=kitchen-print-failed]', { state: 'visible', timeout: 10000 });
      console.log('     toast:', await text(page, '[data-testid=kitchen-print-failed]'));
      await shot(page, 'B-1-print-failed');
      await settle(page, 400);
      console.log('     bills-count:', await text(page, '[data-testid=bills-count]'));
      await page.evaluate(() => { window.__printFail = false; });
      await page.click('[data-testid=kitchen-print-retry]');
      await page.waitForSelector('[data-testid=kitchen-print-failed]', { state: 'detached', timeout: 10000 });
      await settle(page, 800);
      const m = await page.evaluate(() => window.__prints.length);
      console.log('     prints:', m, 'was', n);
      if (m !== n + 1) throw new Error('retry did not print once');
    }, page, log);

    await step('the bill is sent after the retry', async () => {
      await openBillFromDrawer(page, 'Meja 3');
      const strip = await text(page, '[data-testid=kitchen-pending-strip]');
      const chips = await page.locator('[data-testid^=line-chip-]').allInnerTexts();
      console.log('     strip:', strip, ' chips:', JSON.stringify(chips));
      if (!/Sudah ke dapur/.test(strip) || chips.some((c) => !/Terkirim/.test(c))) throw new Error('not marked as sent');
    }, page, log);

    await step('provisional bill prints the bill; the drawer preview shows it', async () => {
      const n = await page.evaluate(() => window.__prints.length);
      await page.click('[data-testid=provisional-bill]');
      await settle(page, 1200);
      const printed = await page.evaluate(() => window.__prints[window.__prints.length - 1] || '');
      console.log('     prints:', await page.evaluate(() => window.__prints.length), 'was', n);
      console.log('     bill:\n' + printed.replace(/[\x00-\x09\x0b-\x1f]/g, '·').split('\n').map((l) => '       |' + l).join('\n'));
      await page.click('[data-testid=bills-toggle]');
      await page.waitForSelector('[data-testid=bills-drawer]', { state: 'visible' });
      await settle(page, 600);
      const row = page.locator('[data-testid^=bill-row-]').filter({ hasText: 'Meja 3' }).first();
      const id = (await row.getAttribute('data-testid')).replace('bill-row-', '');
      const preview = page.locator(`[data-testid=bill-preview-${id}]`);
      if (!(await preview.isVisible())) await page.click(`[data-testid=bill-menu-${id}]`);
      await page.click(`[data-testid=bill-preview-${id}]`);
      await page.waitForSelector('[data-testid=receipt-preview-dialog]', { state: 'visible', timeout: 8000 });
      await settle(page, 400);
      const t = await page.innerText('[data-testid=receipt-preview-text]');
      console.log('     preview:\n' + t.split('\n').map((l) => '       |' + l).join('\n'));
      await shot(page, 'B-2-preview');
      await page.click('[data-testid=receipt-preview-back]');
      await settle(page, 300);
      if (await page.locator('[data-testid=bills-drawer]').isVisible()) { await page.keyboard.press('Escape'); await settle(page, 300); }
    }, page, log);

    await step('save a second bill (name Rina) without the kitchen', async () => {
      await page.click('[data-testid=leave-bill]');
      await settle(page, 600);
      await add(page, 'Kopi Susu');
      await page.fill('[data-testid=customer-name-input]', 'Rina');
      await page.keyboard.press('Tab');
      await settle(page, 1200);
      await page.click('[data-testid=save-bill-only]');
      await page.waitForSelector('[data-testid=bill-saved-toast]', { state: 'visible', timeout: 8000 });
      console.log('     toast:', await text(page, '[data-testid=bill-saved-toast]'));
      await settle(page, 600);
    }, page, log);

    await step('bills drawer: search, filters', async () => {
      await page.click('[data-testid=bills-toggle]');
      await page.waitForSelector('[data-testid=bills-drawer]', { state: 'visible' });
      await settle(page, 700);
      const rows = async () => page.locator('[data-testid^=bill-row-]').count();
      console.log('     summary:', await text(page, '[data-testid=bills-summary]'), 'rows:', await rows());
      await page.fill('[data-testid=bills-search]', 'rina');
      await settle(page, 900);
      console.log('     search rina rows:', await rows());
      if (await rows() !== 1) throw new Error('search did not narrow to 1');
      await page.fill('[data-testid=bills-search]', '');
      await settle(page, 900);
      for (const f of ['unsent', 'mine', 'stale', 'all']) {
        await page.click(`[data-testid=bills-filter-${f}]`);
        await settle(page, 700);
        console.log(`     filter ${f}: rows=${await rows()}`);
      }
      await shot(page, 'B-3-drawer');
    }, page, log);

    await step('void the Rina bill', async () => {
      const row = page.locator('[data-testid^=bill-row-]').filter({ hasText: 'Rina' }).first();
      const id = (await row.getAttribute('data-testid')).replace('bill-row-', '');
      await page.click(`[data-testid=bill-menu-${id}]`);
      await page.click(`[data-testid=bill-void-${id}]`);
      await page.waitForSelector('[data-testid=bill-void-dialog]', { state: 'visible', timeout: 5000 });
      await page.click('[data-testid=bill-void-reason-customer]');
      await shot(page, 'B-4-void');
      await page.click('[data-testid=bill-void-confirm]');
      await settle(page, 1200);
      console.log('     rows after void:', await page.locator('[data-testid^=bill-row-]').count(), 'count:', await text(page, '[data-testid=bills-count]'));
      if (await page.locator(`[data-testid=bill-row-${id}]`).count()) throw new Error('the voided bill is still listed');
      await page.keyboard.press('Escape');
      await settle(page, 300);
    }, page, log);

    // Two tabs on the bill of table 3: A adds, B (stale) pays.
    const pageB = await context.newPage();
    pageB.on('pageerror', (e) => log.push(`[pageB error] ${e.message}`));
    await step('two tabs: B has the payment open, A adds a line -> B gets the conflict, then pays with QRIS', async () => {
      await openBillFromDrawer(page, 'Meja 3');
      await pageB.goto(`${BASE}/member/cashier`, { waitUntil: 'networkidle' });
      await pageB.waitForSelector('[data-testid=cashier-root]');
      await settle(pageB, 600);
      console.log('     B chip:', await text(pageB, '[data-testid=bill-chip],[data-testid=bill-resume-chip]'));
      await pageB.click('[data-testid=cart-pay]');
      await pageB.waitForSelector('[data-testid=pay-sheet]', { state: 'visible', timeout: 8000 });
      await settle(pageB, 800);
      console.log('     B due in dialog:', await text(pageB, '[data-testid=pay-total-card]'));
      await add(page, 'Es Jeruk');
      console.log('     A total after add:', await text(page, '[data-testid=cart-total]'));
      await pageB.locator('[data-testid^=pay-method-]').filter({ hasText: 'QRIS' }).first().click();
      await pageB.click('[data-testid=pay-exact]');
      await pageB.click('[data-testid=pay-submit]');
      await settle(pageB, 1500);
      const conflict = pageB.locator('[data-testid=pay-conflict]');
      console.log('     conflict visible:', await conflict.isVisible(), ' success visible:', await pageB.locator('[data-testid=success-sheet]').isVisible());
      await shot(pageB, 'B-5-conflict');
      if (!(await conflict.isVisible())) throw new Error('no conflict shown');
      console.log('     conflict:', await text(pageB, '[data-testid=pay-conflict]'));
      await pageB.click('[data-testid=pay-conflict-reload]');
      await settle(pageB, 1200);
      console.log('     B due after reload:', await text(pageB, '[data-testid=pay-total-card]'));
      const exact = pageB.locator('[data-testid=pay-exact]');
      if ((await exact.getAttribute('aria-checked')) !== 'true') await exact.click();
      await pageB.click('[data-testid=pay-submit]');
      await pageB.waitForSelector('[data-testid=success-sheet]', { state: 'visible', timeout: 10000 });
      await settle(pageB, 1200);
      console.log('     success:', (await text(pageB, '[data-testid=success-sheet]')).slice(0, 200));
      await shot(pageB, 'B-6-qris-success');
    }, pageB, log);

    await step('tab A still shows the paid bill: Pay closes the dialog and says it was paid', async () => {
      await page.click('[data-testid=cart-pay]');
      await settle(page, 1800);
      const open = await page.locator('[data-testid=pay-sheet]').isVisible();
      const toast = await page.locator('.fi-no-notification').allInnerTexts().catch(() => []);
      console.log('     dialog open:', open, ' toasts:', JSON.stringify(toast.map((t) => t.replace(/\s+/g, ' ').trim())));
      console.log('     A chip:', await page.locator('[data-testid=bill-chip],[data-testid=bill-resume-chip]').count() ? await text(page, '[data-testid=bill-chip],[data-testid=bill-resume-chip]') : '(new order)');
      await shot(page, 'B-7-tabA-after');
      if (open) throw new Error('the payment dialog stayed open on a paid bill');
    }, page, log);
    await pageB.close();

    await step('credit with a down payment', async () => {
      await page.goto(`${BASE}/member/cashier`, { waitUntil: 'networkidle' });
      await page.waitForSelector('[data-testid=cashier-root]');
      await settle(page, 600);
      if (await page.locator('[data-testid=leave-bill]').isVisible()) { await page.click('[data-testid=leave-bill]'); await settle(page, 600); }
      await add(page, 'Mie Goreng');
      await add(page, 'Mie Goreng');
      await page.click('[data-testid=cart-pay]');
      await page.waitForSelector('[data-testid=pay-sheet]', { state: 'visible', timeout: 8000 });
      await settle(page, 600);
      await page.locator('[data-testid^=pay-method-]').filter({ hasText: 'Kredit' }).first().click();
      console.log('     status before:', await text(page, '[data-testid=pay-status]'));
      await page.selectOption('[data-testid=pay-member]', { index: 1 });
      const due = await page.evaluate(() => { const d = new Date(Date.now() + 7 * 864e5); return d.toISOString().slice(0, 10); });
      await page.fill('[data-testid=pay-due]', due);
      for (const k of ['1', '0', '0', '0', '0']) await page.click(`[data-testid=pay-numpad-${k}]`);
      console.log('     receivable:', await text(page, '[data-testid=pay-receivable]'));
      console.log('     status:', await text(page, '[data-testid=pay-status]'));
      await shot(page, 'B-8-credit');
      await page.click('[data-testid=pay-submit]');
      await page.waitForSelector('[data-testid=success-sheet]', { state: 'visible', timeout: 10000 });
      await settle(page, 1200);
      console.log('     success:', (await text(page, '[data-testid=success-sheet]')).slice(0, 200));
      await shot(page, 'B-9-credit-success');
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
