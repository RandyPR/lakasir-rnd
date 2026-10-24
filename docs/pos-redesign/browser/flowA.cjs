// Flow A (fnb, desktop): new order -> send to kitchen (takeaway prompt) -> open the bill -> add -> send the difference
// -> cancel a sent item -> pay cash with the +20.000/+50.000 buttons -> success.
const path = require('path');
const { launch, login, openCashier, settle, shot } = require('./lib.cjs');

const W = parseInt(process.env.W || '1280', 10);

async function cardId(page, name) {
  return page.evaluate((n) => {
    for (const el of document.querySelectorAll('[data-testid^=product-card-]')) {
      const h = el.querySelector('.pv-name');
      if (h && h.textContent.trim() === n) return el.dataset.pid;
    }
    return null;
  }, name);
}

async function step(label, fn, page, log) {
  const before = log.length;
  try {
    await fn();
    console.log(`OK   ${label}`);
  } catch (e) {
    console.log(`FAIL ${label}: ${e.message.split('\n')[0]}`);
    await shot(page, `A-fail-${label.replace(/\W+/g, '_')}`);
    throw e;
  } finally {
    if (log.length > before) console.log('     ' + log.slice(before).filter((l) => !/ERR_TUNNEL|fonts\.bunny|unpkg\.com|408/.test(l)).join('\n     '));
  }
}

(async () => {
  const { browser, context, page, log } = await launch({ viewport: { width: W, height: 900 } });
  await context.addInitScript({ path: path.join(__dirname, 'printer-stub.js') });
  try {
    await login(page);
    await openCashier(page);
    await settle(page, 600);

    const nasi = await cardId(page, 'Nasi Goreng Spesial');
    const teh = await cardId(page, 'Es Teh Manis');
    const soto = await cardId(page, 'Soto Ayam');

    await step('add 2 nasi + 1 teh', async () => {
      await page.click(`[data-testid=product-add-${nasi}]`);
      await settle(page);
      await page.click(`[data-testid=product-inc-${nasi}]`);
      await settle(page);
      await page.click(`[data-testid=product-add-${teh}]`);
      await settle(page);
      const lines = await page.locator('[data-testid^=cart-item-unit-]').allInnerTexts();
      console.log('     lines:', JSON.stringify(lines));
      const total = await page.innerText('[data-testid=cart-total]');
      console.log('     total:', total);
      if (!/55\.000/.test(total)) throw new Error('total is not 55.000');
    }, page, log);

    await step('note on nasi', async () => {
      const item = page.locator('[data-testid^=cart-item-]').filter({ hasText: 'Nasi Goreng Spesial' }).first();
      const itemId = (await item.getAttribute('data-testid')).replace('cart-item-', '');
      await page.click(`[data-testid=cart-item-note-toggle-${itemId}]`);
      const input = page.locator(`[data-testid=cart-item-note-${itemId}]`);
      await input.fill('pedas, tanpa telur');
      await input.press('Enter');
      await settle(page, 600);
      console.log('     note view:', await page.locator(`[data-testid=cart-item-note-view-${itemId}]`).innerText().catch(() => '(none)'));
    }, page, log);

    await shot(page, 'A-1-cart');

    await step('send to kitchen -> takeaway prompt', async () => {
      await page.click('[data-testid=send-kitchen]');
      await page.waitForSelector('[data-testid=takeaway-prompt]', { state: 'visible', timeout: 8000 });
      console.log('     prompt:', (await page.innerText('[data-testid=takeaway-prompt]')).replace(/\s+/g, ' '));
      await shot(page, 'A-2-takeaway');
      await page.click('[data-testid=takeaway-confirm]');
      await settle(page, 1500);
      const prints = await page.evaluate(() => window.__prints.length);
      console.log('     prints:', prints);
      const text = await page.evaluate(() => window.__prints[window.__prints.length - 1] || '');
      console.log('     ticket:\n' + text.replace(/[\x00-\x09\x0b-\x1f]/g, '·').split('\n').map((l) => '       |' + l).join('\n'));
      if (prints < 1) throw new Error('no kitchen ticket printed');
    }, page, log);

    await shot(page, 'A-3-after-send');

    await step('bills toggle shows 1, open the bill', async () => {
      await settle(page, 500);
      console.log('     bills-count:', await page.innerText('[data-testid=bills-count]'));
      await page.click('[data-testid=bills-toggle]');
      await page.waitForSelector('[data-testid=bills-drawer]', { state: 'visible' });
      await settle(page, 800);
      await shot(page, 'A-4-bills');
      const row = page.locator('[data-testid^=bill-row-]').first();
      const id = (await row.getAttribute('data-testid')).replace('bill-row-', '');
      console.log('     row:', (await row.innerText()).replace(/\s+/g, ' '));
      const open = page.locator(`[data-testid=bill-open-${id}]`);
      if (await open.count()) {
        await open.click();
      } else {
        await page.click(`[data-testid=bill-menu-${id}]`);
        await page.click(`[data-testid=bill-open-alt-${id}]`);
      }
      await settle(page, 800);
      await page.waitForSelector('[data-testid=bill-chip],[data-testid=bill-resume-chip]', { timeout: 8000 });
      console.log('     chip:', (await page.locator('[data-testid=bill-chip],[data-testid=bill-resume-chip]').first().innerText()).replace(/\s+/g, ' '));
    }, page, log);

    await step('add soto to the bill, send only the difference', async () => {
      await page.click(`[data-testid=product-add-${soto}]`);
      await settle(page, 600);
      console.log('     strip:', (await page.innerText('[data-testid=kitchen-pending-strip]')).replace(/\s+/g, ' '));
      const chips = await page.locator('[data-testid^=line-chip-]').allInnerTexts();
      console.log('     chips:', JSON.stringify(chips));
      await shot(page, 'A-5-bill-added');
      const n = await page.evaluate(() => window.__prints.length);
      await page.click('[data-testid=send-kitchen]');
      await settle(page, 1500);
      const text = await page.evaluate(() => window.__prints[window.__prints.length - 1] || '');
      console.log('     prints:', await page.evaluate(() => window.__prints.length), 'was', n);
      console.log('     ticket:\n' + text.replace(/[\x00-\x09\x0b-\x1f]/g, '·').split('\n').map((l) => '       |' + l).join('\n'));
    }, page, log);

    await shot(page, 'A-6-after-second-send');

    await step('reopen the bill and cancel one sent nasi', async () => {
      await page.click('[data-testid=bills-toggle]');
      await settle(page, 800);
      const row = page.locator('[data-testid^=bill-row-]').first();
      const id = (await row.getAttribute('data-testid')).replace('bill-row-', '');
      const open = page.locator(`[data-testid=bill-open-${id}]`);
      if (await open.count()) await open.click(); else { await page.click(`[data-testid=bill-menu-${id}]`); await page.click(`[data-testid=bill-open-alt-${id}]`); }
      await settle(page, 800);
      const item = page.locator('[data-testid^=cart-item-]').filter({ hasText: 'Nasi Goreng Spesial' }).first();
      const itemId = (await item.getAttribute('data-testid')).replace('cart-item-', '');
      await page.click(`[data-testid=cart-item-minus-${itemId}]`);
      await page.waitForSelector('[data-testid=cancel-sent-dialog]', { state: 'visible', timeout: 8000 });
      console.log('     dialog:', (await page.innerText('[data-testid=cancel-sent-dialog]')).replace(/\s+/g, ' ').slice(0, 300));
      await shot(page, 'A-7-cancel-dialog');
      await page.click('[data-testid=cancel-reason-chip-1]');
      await page.click('[data-testid=cancel-confirm]');
      await settle(page, 1000);
      console.log('     chips:', JSON.stringify(await page.locator('[data-testid^=line-chip-]').allInnerTexts()));
      console.log('     strip:', (await page.innerText('[data-testid=kitchen-pending-strip]')).replace(/\s+/g, ' '));
    }, page, log);

    await step('pay cash with +20.000 +50.000', async () => {
      await page.click('[data-testid=cart-pay]');
      await page.waitForSelector('[data-testid=pay-sheet]', { state: 'visible', timeout: 8000 });
      await settle(page, 800);
      console.log('     due:', (await page.innerText('[data-testid=pay-total-card]')).replace(/\s+/g, ' '));
      await shot(page, 'A-8-pay-open');
      const methods = await page.locator('[data-testid^=pay-method-]').allInnerTexts();
      console.log('     methods:', JSON.stringify(methods.map((m) => m.replace(/\s+/g, ' '))));
      await page.locator('[data-testid^=pay-method-]').filter({ hasText: 'Cash' }).first().click();
      await page.click('[data-testid=pay-add-20000]');
      await page.click('[data-testid=pay-add-50000]');
      console.log('     received:', await page.innerText('[data-testid=pay-received]'));
      console.log('     change:', (await page.innerText('[data-testid=pay-change]')).replace(/\s+/g, ' '));
      await shot(page, 'A-9-pay-cash');
      const n = await page.evaluate(() => window.__prints.length);
      await page.click('[data-testid=pay-submit]');
      await page.waitForSelector('[data-testid=success-sheet]', { state: 'visible', timeout: 10000 });
      await settle(page, 2500);
      console.log('     success:', (await page.innerText('[data-testid=success-sheet]')).replace(/\s+/g, ' ').slice(0, 300));
      console.log('     prints after pay:', await page.evaluate(() => window.__prints.length), 'was', n);
      await shot(page, 'A-10-success');
    }, page, log);
  } catch (e) {
    console.log('STOPPED:', e.message.split('\n')[0]);
  } finally {
    const rest = log.filter((l) => !/ERR_TUNNEL|fonts\.bunny|unpkg\.com|408/.test(l));
    if (rest.length) console.log('--- log ---\n' + rest.join('\n'));
    await browser.close();
  }
})();
