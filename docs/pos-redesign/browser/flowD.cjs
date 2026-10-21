// Flow D (retail, phone 375): no kitchen. Save bill -> toast [Pay bill] -> exact cash -> success.
const path = require('path');
const { launch, login, openCashier, settle, shot, overflowX, BASE, STATE } = require('./lib.cjs');

const NOISE = /ERR_TUNNEL|fonts\.bunny|unpkg\.com|408|net::ERR/;
const text = async (page, sel) => (await page.innerText(sel)).replace(/\s+/g, ' ').trim();

(async () => {
  const { browser, context, page, log } = await launch({ viewport: { width: 375, height: 812 }, touch: true, mobile: true, storageState: STATE });
  await context.addInitScript({ path: path.join(__dirname, 'printer-stub.js') });
  let ok = true;
  try {
    await page.goto(`${BASE}/member/cashier`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) { await login(page); await context.storageState({ path: STATE }); }
    await openCashier(page);
    await settle(page, 600);

    const id = await page.evaluate(() => document.querySelector('[data-testid^=product-card-]').dataset.pid);
    await page.click(`[data-testid=product-add-${id}]`);
    await settle(page, 400);
    await page.click(`[data-testid=product-inc-${id}]`);
    await settle(page, 400);
    await page.click('[data-testid=cart-dock]');
    await page.waitForSelector('[data-testid=cart-sheet]', { state: 'visible' });
    await settle(page, 400);
    console.log('kitchen button present:', await page.locator('[data-testid=send-kitchen]').count());
    console.log('overflow:', JSON.stringify(await overflowX(page)));
    await shot(page, 'D-1-retail-cart');

    await page.click('[data-testid=save-bill]');
    await page.waitForSelector('[data-testid=bill-saved-toast]', { state: 'visible', timeout: 8000 });
    console.log('toast:', await text(page, '[data-testid=bill-saved-toast]'));
    await shot(page, 'D-2-saved');
    await page.click('[data-testid=bill-saved-pay]');
    await page.waitForSelector('[data-testid=pay-sheet]', { state: 'visible', timeout: 8000 });
    await settle(page, 800);
    console.log('pay context:', await text(page, '[data-testid=pay-context]').catch(() => '?'), '| due:', await text(page, '[data-testid=pay-total-card]'));
    console.log('kitchen box:', await page.locator('[data-testid=pay-kitchen-box]').isVisible());
    await page.locator('[data-testid^=pay-method-]').filter({ hasText: 'Cash' }).first().click();
    await page.click('[data-testid=pay-quick-exact]');
    console.log('status:', await text(page, '[data-testid=pay-status]'));
    await shot(page, 'D-3-pay');
    await page.click('[data-testid=pay-submit]');
    await page.waitForSelector('[data-testid=success-sheet]', { state: 'visible', timeout: 10000 });
    await settle(page, 1000);
    console.log('success:', (await text(page, '[data-testid=success-sheet]')).slice(0, 160));
    console.log('kitchen print offered:', await page.locator('[data-testid=success-kitchen-print]').isVisible());
    await shot(page, 'D-4-success');
  } catch (e) {
    ok = false;
    console.log('FAIL', e.message.split('\n')[0]);
    await shot(page, 'D-fail');
  } finally {
    const rest = log.filter((l) => !NOISE.test(l));
    if (rest.length) { ok = false; console.log('--- log ---\n' + rest.join('\n')); }
    console.log(ok ? '--- ok ---' : '--- problems ---');
    await browser.close();
  }
})();
