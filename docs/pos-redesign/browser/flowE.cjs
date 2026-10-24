// Flow E (fnb, desktop): order note tile (edit-detail modal) and a line discount through "more options".
const path = require('path');
const { launch, login, openCashier, settle, shot, BASE, STATE } = require('./lib.cjs');

const NOISE = /ERR_TUNNEL|fonts\.bunny|unpkg\.com|408|net::ERR/;
const text = async (page, sel) => (await page.innerText(sel)).replace(/\s+/g, ' ').trim();

(async () => {
  const { browser, context, page, log } = await launch({ viewport: { width: 1280, height: 900 }, storageState: STATE });
  let ok = true;
  try {
    await page.goto(`${BASE}/member/cashier`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) { await login(page); await context.storageState({ path: STATE }); }
    await openCashier(page);
    await settle(page, 600);
    const id = await page.evaluate(() => document.querySelector('[data-testid^=product-card-]').dataset.pid);
    const addBtn = page.locator(`[data-testid=product-add-${id}]`);
    if (await addBtn.isVisible()) await addBtn.click(); else await page.click(`[data-testid=product-inc-${id}]`);
    await settle(page, 400);
    console.log('more-options buttons:', await page.locator('[data-testid^=cart-item-more-toggle-]').count());

    // Order note through its tile.
    await page.click('[data-testid=tile-note]');
    await settle(page, 600);
    const modal = page.locator('.fi-modal-window:visible').first();
    const visibleFields = await modal.locator('.fi-fo-field-wrp:visible').count();
    console.log('edit-detail fields visible:', visibleFields);
    const note = modal.locator('textarea:visible, input:visible').first();
    await note.fill('Bungkus terpisah');
    await modal.locator('button[type=submit]').click();
    await settle(page, 900);
    console.log('note tile:', await text(page, '[data-testid=tile-note]'));
    if (!/Bungkus terpisah/.test(await text(page, '[data-testid=tile-note]'))) { ok = false; console.log('FAIL note not shown on the tile'); }

    // A discount on the line.
    const line = page.locator('[data-testid^=cart-item-]').first();
    const lineId = (await line.getAttribute('data-testid')).replace('cart-item-', '');
    await page.click(`[data-testid=cart-item-more-toggle-${lineId}]`);
    await settle(page, 300);
    const input = page.locator(`[data-testid=cart-item-discount-input-${lineId}]`);
    await input.fill('1000');
    await input.press('Tab');
    await settle(page, 1000);
    console.log('discount line:', await text(page, `[data-testid=cart-item-discount-${lineId}]`).catch(() => '(none)'));
    console.log('total:', await text(page, '[data-testid=cart-total]'));
    await shot(page, 'E-1-note-discount');
  } catch (e) {
    ok = false;
    console.log('FAIL', e.message.split('\n')[0]);
    await shot(page, 'E-fail');
  } finally {
    const rest = log.filter((l) => !NOISE.test(l));
    if (rest.length) { ok = false; console.log('--- log ---\n' + rest.join('\n')); }
    console.log(ok ? '--- ok ---' : '--- problems ---');
    await browser.close();
  }
})();
