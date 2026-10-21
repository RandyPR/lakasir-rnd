// Shared helpers for driving the demo cashier in Chromium.
// PLAYWRIGHT_MODULE: where playwright is installed when it is not resolvable from here (e.g. a global install).
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8000';
// Screenshots and the saved sign-in go outside the repository.
const OUT = process.env.OUT || path.join(require('os').tmpdir(), 'lakasir-browser');
const STATE = path.join(OUT, 'state.json');
fs.mkdirSync(OUT, { recursive: true });

async function launch(opts = {}) {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: opts.viewport || { width: 1280, height: 800 },
    colorScheme: opts.dark ? 'dark' : 'light',
    deviceScaleFactor: 1,
    hasTouch: !!opts.touch,
    isMobile: !!opts.mobile,
    // Reusing a signed-in session avoids the login rate limit (5 a minute) when a script opens many browsers.
    storageState: opts.storageState && fs.existsSync(opts.storageState) ? opts.storageState : undefined,
  });
  const page = await context.newPage();
  const log = [];
  page.on('console', (m) => {
    if (['error', 'warning'].includes(m.type())) log.push(`[console.${m.type()}] ${m.text()}`);
  });
  page.on('pageerror', (e) => log.push(`[pageerror] ${e.message}`));
  page.on('response', async (r) => {
    if (r.status() >= 400) {
      let body = '';
      try { body = (await r.text()).slice(0, 600); } catch (e) {}
      log.push(`[http ${r.status()}] ${r.request().method()} ${r.url()} ${body.replace(/\s+/g, ' ')}`);
    }
  });
  page.on('dialog', async (d) => { log.push(`[dialog] ${d.type()} ${d.message()}`); await d.accept(); });
  return { browser, context, page, log };
}

async function login(page, email = 'owner@demo.test', password = 'password') {
  await page.goto(`${BASE}/member/login`, { waitUntil: 'networkidle' });
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', password);
  await Promise.all([
    page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }),
    page.click('button[type=submit]'),
  ]);
}

async function openCashier(page) {
  await page.goto(`${BASE}/member/cashier`, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-testid=cashier-root]', { timeout: 20000 });
}

// Wait until Livewire has no request in flight.
async function settle(page, ms = 250) {
  await page.waitForTimeout(ms);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(100);
}

async function shot(page, name) {
  const file = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  return file;
}

async function overflowX(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const wide = [];
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width > 0 && r.right > doc.clientWidth + 1 && getComputedStyle(el).position !== 'fixed') {
        const cs = getComputedStyle(el);
        if (cs.visibility !== 'hidden' && cs.display !== 'none') wide.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}.${(el.className && el.className.baseVal === undefined ? el.className : '').toString().split(' ').slice(0, 3).join('.')} right=${Math.round(r.right)}`);
      }
    }
    return { scrollWidth: doc.scrollWidth, clientWidth: doc.clientWidth, wide: wide.slice(0, 8) };
  });
}

module.exports = { launch, login, openCashier, settle, shot, overflowX, BASE, OUT, STATE };
