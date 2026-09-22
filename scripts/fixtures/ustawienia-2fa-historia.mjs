import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';

const base = 'http://127.0.0.1:18776';
const browser = await chromium.launch({ headless: true, ignoreDefaultArgs: ['--disable-back-forward-cache'] });
const report = { browser: browser.version(), cases: [] };
const codes = '/ustawienia/2fa/kody-zapasowe';
try {
  for (const scenario of ['refresh', 'back', 'logout-back', 'reopen']) {
    execFileSync('php', ['scripts/fixtures/ustawienia-2fa.php'], { stdio: 'pipe' });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    let page = await context.newPage();
    let requests = 0;
    const sources = [];
    const attachNetwork = async p => {
      const cdp = await context.newCDPSession(p);
      await cdp.send('Network.enable');
      cdp.on('Network.requestWillBeSent', event => {
        if (event.redirectResponse && new URL(event.redirectResponse.url).pathname === codes) sources.push({ status: event.redirectResponse.status, diskCache: event.redirectResponse.fromDiskCache === true, serviceWorker: event.redirectResponse.fromServiceWorker === true });
      });
      cdp.on('Network.responseReceived', event => {
        if (new URL(event.response.url).pathname === codes) sources.push({ status: event.response.status, diskCache: event.response.fromDiskCache === true, serviceWorker: event.response.fromServiceWorker === true });
      });
    };
    await attachNetwork(page);
    page.on('request', r => { if (new URL(r.url()).pathname === codes && r.method() === 'GET') requests++; });
    await page.addInitScript(() => {
      window.__historyRestored = false;
      window.addEventListener('pageshow', event => { window.__historyRestored = event.persisted; });
    });
    await page.goto(base + '/login');
    await page.locator('input[name=login]').fill('pomiar2fa@example.test');
    await page.locator('input[name=password]').fill('haslo-lokalnego-pomiaru');
    await page.locator('form').filter({ has: page.locator('input[name=login]') }).locator('button[type=submit]').click();
    await page.waitForURL('**/logowanie/kod');
    await page.getByText('Nie mam dostępu do telefonu', { exact: true }).click();
    await page.locator('input[name=backup_code]').fill('TEST-TEST');
    await page.locator('form').filter({ has: page.locator('input[name=backup_code]') }).locator('button[type=submit]').click();
    await page.waitForURL('**/home');
    await page.goto(base + '/ustawienia/2fa');
    // Odkrywamy adres z formularza zawierającego przycisk regeneracji.
    const regenerate = page.locator('form').filter({ has: page.getByRole('button', { name: 'Wygeneruj nowe kody', exact: true }) });
    await page.getByText('Wygeneruj nowe kody zapasowe', { exact: true }).click();
    await regenerate.locator('input[name=password]').fill('haslo-lokalnego-pomiaru');
    const responsePromise = page.waitForResponse(r => new URL(r.url()).pathname === codes && r.request().method() === 'GET');
    await regenerate.getByRole('button', { name: 'Wygeneruj nowe kody', exact: true }).click();
    const response = await responsePromise;
    await page.waitForURL('**' + codes);
    const initial = await page.locator('.kod-do-przepisania li').count() === 8;
    const headers = response.headers();
    await page.emulateMedia({ media: 'print' });
    const printable = await page.locator('.kod-do-przepisania').isVisible();
    await page.emulateMedia({ media: 'screen' });
    const before = requests;
    sources.length = 0;
    if (scenario === 'refresh') await page.reload();
    if (scenario === 'back') {
      await page.getByRole('link', { name: 'Kody zapisane — gotowe' }).click();
      await page.goBack();
    }
    if (scenario === 'logout-back') {
      await page.getByRole('link', { name: 'Kody zapisane — gotowe' }).click();
      // Prawdziwy formularz wylogowania i CSRF, także gdy menu jest zwinięte.
      await page.locator('form[action$="/logout"]').first().evaluate(f => f.requestSubmit());
      await page.waitForURL(base + '/');
      await page.goBack();
      await page.goBack();
    }
    if (scenario === 'reopen') {
      await page.close();
      page = await context.newPage();
      await attachNetwork(page);
      page.on('request', r => { if (new URL(r.url()).pathname === codes && r.method() === 'GET') requests++; });
      await page.goto(base + codes);
    }
    await page.waitForTimeout(300);
    const visible = await page.locator('.kod-do-przepisania li').count() > 0;
    const restored = await page.evaluate(() => window.__historyRestored === true);
    report.cases.push({ scenario, initial, printable, cacheControl: headers['cache-control'], pragma: headers.pragma ?? null, visible, restored, newCodeRequests: requests - before, sources, path: new URL(page.url()).pathname });
    await context.close();
  }
} finally { await browser.close(); }
writeFileSync(process.env.REPORT_PATH ?? 'storage/2fa-history.json', JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));
if (process.env.ASSERT_HIDDEN === '1' && report.cases.some(c => !c.initial || !c.printable || c.visible)) process.exitCode = 1;