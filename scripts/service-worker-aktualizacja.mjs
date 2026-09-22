/* Prawdziwa przeglądarka i stary CDN: /sw.js nadal zwraca poprzednie bajty,
   ale HTML nowego wydania ma nowy URL. Nie zastępujemy API service workera. */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { chromium } from 'playwright';

const root = new URL('../', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');
const worker = read('public/sw.js');
const wersja = worker.match(/const WERSJA = '([^']+)'/)[1];
const staryWorker = read('scripts/fixtures/sw-kuking-v1.js'); // cc1e3bf, przed Alfa 0.12
const rejestracja = read('resources/js/service-worker.js');
assert.match(read('resources/js/app.js'), /import\s+['"]\.\/service-worker\.js['"]/, 'Aplikacja musi uruchamiać rzeczywisty moduł rejestracji');
let noweWydanie = false;
const pobrania = [];
const server = createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname === '/sw.js') {
    pobrania.push(req.url);
    res.setHeader('Content-Type', 'application/javascript');
    // Symulacja CDN ignorującego rewalidację starego adresu, nie mock fetch.
    res.setHeader('Cache-Control', 'public, max-age=14400');
    res.end(url.searchParams.get('v') === 'nowe-wydanie' ? worker : staryWorker);
  } else if (url.pathname === '/rejestracja.js') {
    res.setHeader('Content-Type', 'application/javascript');
    res.setHeader('Cache-Control', 'no-store');
    res.end(rejestracja);
  } else if (url.pathname === '/offline.html') {
    res.setHeader('Content-Type', 'text/html');
    res.setHeader('Cache-Control', 'no-store');
    res.end(noweWydanie ? read('public/offline.html') : '<h1>Poprzedni ekran offline</h1>');
  } else if (url.pathname.startsWith('/icons/')) {
    const path = url.pathname.slice(1);
    res.setHeader('Content-Type', path.endsWith('.svg') ? 'image/svg+xml' : 'image/png');
    res.end(readFileSync(new URL(path, new URL('public/', root))));
  } else {
    res.setHeader('Content-Type', 'text/html');
    res.setHeader('Cache-Control', 'no-store');
    const module = url.pathname === '/pozny' ? '' : '<script type="module" src="/rejestracja.js"></script>';
    res.end(`<html><head><meta name="kuking-service-worker" content="/sw.js?v=nowe-wydanie">${noweWydanie ? module : ''}</head><body><input id="szkic"><p>Strona z sieci</p></body></html>`);
  }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const adres = `http://127.0.0.1:${server.address().port}`;
let browser;
try {
  browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
  for (const pozny of [false, true]) {
    noweWydanie = false;
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(adres);
    await page.evaluate(async () => {
      await navigator.serviceWorker.register('/sw.js', { scope: '/' });
      await navigator.serviceWorker.ready;
    });
    await page.waitForFunction(() => navigator.serviceWorker.controller && navigator.serviceWorker.controller.state === 'activated');
    assert.deepEqual(await page.evaluate(() => caches.keys()), ['kuking-v1']);
    noweWydanie = true;
    await page.goto(adres + (pozny ? '/pozny' : '/nowy'));
    await page.fill('#szkic', 'Niezapisany tekst zostaje');
    if (pozny) await page.evaluate(() => import('/rejestracja.js'));
    await page.waitForFunction(async expected => {
      const keys = await caches.keys();
      return keys.includes(expected) && !keys.includes('kuking-v1');
    }, wersja, { timeout: 12000 });
    await page.waitForFunction(() => navigator.serviceWorker.controller?.state === 'activated' && navigator.serviceWorker.controller.scriptURL.endsWith('?v=nowe-wydanie'), null, { timeout: 12000 });
    assert.deepEqual(await page.evaluate(() => caches.keys()), [wersja], 'Sprzątanie oceniamy dopiero po zakończonej aktywacji');
    const registration = await page.evaluate(async () => {
      const r = await navigator.serviceWorker.getRegistration();
      return { url: r.active.scriptURL, scope: r.scope, via: r.updateViaCache, count: (await navigator.serviceWorker.getRegistrations()).length };
    });
    assert.equal(registration.url, adres + '/sw.js?v=nowe-wydanie');
    assert.equal(registration.scope, adres + '/');
    assert.equal(registration.via, 'none');
    assert.equal(registration.count, 1, 'Aktualizacja nie może zostawić drugiej rejestracji');
    assert.equal(await page.inputValue('#szkic'), 'Niezapisany tekst zostaje', 'Aktywacja nie może przeładować formularza');
    const offline = await page.evaluate(async () => (await caches.match('/offline.html')).text());
    assert.equal(offline, read('public/offline.html'), 'Nowy worker musi zachować aktualny ekran offline');
    await context.setOffline(true);
    await page.goto(adres + '/bez-sieci');
    assert.match(await page.content(), /Nie masz teraz połączenia|Brak połączenia|offline/i);
    assert(!((await page.content()).includes('Poprzedni ekran offline')));
    await context.close();
    console.log(`Aktualizacja workera, moduł ${pozny ? 'po load' : 'przed load'}: OK`);
  }
  assert(pobrania.includes('/sw.js') && pobrania.includes('/sw.js?v=nowe-wydanie'));
} finally {
  await browser?.close();
  await new Promise(resolve => server.close(resolve));
}
