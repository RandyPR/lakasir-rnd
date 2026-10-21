// Responsive pass: each width in light and dark. Page, cart, payment dialog and bills drawer: no horizontal overflow,
// no console error. Screenshots: W-<width>-<theme>-<step>.png in the OUT folder (see lib.cjs).
const path = require('path');
const { launch, login, openCashier, settle, shot, overflowX, STATE } = require('./lib.cjs');

const WIDTHS = (process.env.WIDTHS || '320,375,414,768,1024,1280').split(',').map((w) => parseInt(w, 10));
const THEMES = (process.env.THEMES || 'light,dark').split(',');
const HEIGHT = { 320: 640, 375: 812, 414: 896, 768: 1024, 1024: 768, 1280: 800 };
let signedIn = false;
const NOISE = /ERR_TUNNEL|fonts\.bunny|unpkg\.com|408|net::ERR/;

async function visible(page, sel, timeout = 8000) {
  await page.waitForSelector(sel, { state: 'visible', timeout });
}

(async () => {
  const problems = [];
  for (const width of WIDTHS) {
    for (const theme of THEMES) {
      const phone = width < 768;
      const tag = `W-${width}-${theme}`;
      const { browser, context, page, log } = await launch({
        viewport: { width, height: HEIGHT[width] || 800 }, dark: theme === 'dark', touch: width <= 1024, mobile: phone,
        storageState: signedIn ? STATE : undefined,
      });
      await context.addInitScript({ path: path.join(__dirname, 'printer-stub.js') });
      const report = (step, data) => {
        const bad = data.scrollWidth > data.clientWidth;
        console.log(`${bad ? 'OVER' : 'ok  '} ${tag} ${step} scroll=${data.scrollWidth} client=${data.clientWidth}${data.wide.length ? ' wide=' + JSON.stringify(data.wide) : ''}`);
        if (bad) problems.push(`${tag} ${step}: ${JSON.stringify(data.wide)}`);
      };
      try {
        if (!signedIn) {
          await login(page);
          await context.storageState({ path: STATE });
          signedIn = true;
        }
        await openCashier(page);
        await settle(page, 600);
        const dark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
        if ((theme === 'dark') !== dark) problems.push(`${tag}: dark class is ${dark}`);
        report('page', await overflowX(page));
        await shot(page, `${tag}-1-page`);

        const ids = await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid^=product-card-]')).slice(0, 3).map((el) => el.dataset.pid));
        for (const id of ids) {
          // A product already in the cart shows its stepper instead of "Add".
          const add = page.locator(`[data-testid=product-add-${id}]`);
          if (await add.isVisible()) await add.click(); else await page.click(`[data-testid=product-inc-${id}]`);
          await settle(page, 300);
        }
        report('after add', await overflowX(page));

        if (phone) {
          await visible(page, '[data-testid=cart-dock]');
          await shot(page, `${tag}-2-dock`);
          await page.click('[data-testid=cart-dock]');
          await visible(page, '[data-testid=cart-sheet]');
          await settle(page, 400);
          report('cart sheet', await overflowX(page));
        }
        await shot(page, `${tag}-3-cart`);

        await page.click('[data-testid=cart-pay]');
        await visible(page, '[data-testid=pay-sheet]');
        await settle(page, 600);
        report('payment', await overflowX(page));
        await shot(page, `${tag}-4-pay`);
        await page.keyboard.press('Escape');
        await settle(page, 400);

        // On a phone the sheet may still cover the header: close it first.
        if (phone && await page.locator('[data-testid=cart-sheet]').isVisible()) {
          await page.keyboard.press('Escape');
          await settle(page, 300);
        }
        if (await page.locator('[data-testid=bills-toggle]').count()) {
          await page.click('[data-testid=bills-toggle]');
          await visible(page, '[data-testid=bills-drawer]');
          await settle(page, 600);
          report('bills', await overflowX(page));
          await shot(page, `${tag}-5-bills`);
          await page.keyboard.press('Escape');
          await settle(page, 300);
        }
      } catch (e) {
        problems.push(`${tag}: ${e.message.split('\n')[0]}`);
        console.log(`FAIL ${tag}: ${e.message.split('\n')[0]}`);
        await shot(page, `${tag}-fail`);
      } finally {
        const rest = log.filter((l) => !NOISE.test(l));
        if (rest.length) {
          console.log('     ' + rest.join('\n     '));
          problems.push(...rest.map((l) => `${tag} ${l}`));
        }
        await browser.close();
      }
    }
  }
  console.log(problems.length ? `--- ${problems.length} problem(s) ---\n${problems.join('\n')}` : '--- clean ---');
})();
