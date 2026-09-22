// Odbiór lokalnego menu moderatora; nie wysyła formularza wylogowania.
import { chromium } from 'playwright';
import { mkdtempSync, readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const url = process.env.BASE_URL || 'http://127.0.0.1:8033/home';
if (!['127.0.0.1', 'localhost'].includes(new URL(url).hostname)) throw Error('Wymagany lokalny serwer');
const state = process.env.STORAGE_STATE;
if (!state) throw Error('Wymagana sesja lokalnego moderatora: STORAGE_STATE');
const out = process.env.OUTPUT_DIR || 'output/menu-konta';
mkdirSync(out, { recursive: true });
const rows = [];
const height = Number(process.env.HEIGHT || 600);
const widths = process.env.WIDTHS ? process.env.WIDTHS.split(',').map(Number) : [320, 360, 390, 414, 768, 1440];
for (const width of process.env.QUICK ? [1440] : widths) {
  for (const zoom of process.env.QUICK ? [1] : [1, 2]) {
    const ext = mkdtempSync(join(tmpdir(), 'menu555-ext-'));
    const profile = mkdtempSync(join(tmpdir(), 'menu555-browser-'));
    writeFileSync(join(ext, 'manifest.json'), JSON.stringify({ manifest_version: 3, name: 'Odbior menu', version: '1.0', permissions: ['tabs'], background: { service_worker: 'worker.js' } }));
    writeFileSync(join(ext, 'worker.js'), 'chrome.runtime.onInstalled.addListener(()=>{});');
    const context = await chromium.launchPersistentContext(profile, {
      executablePath: process.env.CHROMIUM_PATH, headless: true, viewport: null,
      args: ['--no-sandbox', `--window-size=${width * zoom},${height * zoom + 87}`, `--disable-extensions-except=${ext}`, `--load-extension=${ext}`],
    });
    try {
      await context.addCookies(JSON.parse(readFileSync(state)).cookies);
      await context.route('**/*', r => new URL(r.request().url()).origin === new URL(url).origin ? r.continue() : r.abort());
      const page = await context.newPage();
      if (zoom === 1) await page.setViewportSize({ width, height });
      await page.goto(url);
      const worker = context.serviceWorkers()[0] || await context.waitForEvent('serviceworker');
      const tab = (await worker.evaluate(() => chrome.tabs.query({}))).find(t => t.url === url);
      await worker.evaluate(({ id, zoom }) => chrome.tabs.setZoom(id, zoom), { id: tab.id, zoom });
      for (const theme of ['light', 'dark']) for (const scale of [100, 140]) {
        await page.goto(url);
        await page.evaluate(({ theme, scale }) => { document.documentElement.dataset.theme = theme; document.documentElement.dataset.textScale = String(scale); }, { theme, scale });
        await page.evaluate(async () => { await document.fonts.ready; for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame); });
        await page.locator('.topbar-konto > summary').click();
        const geo = await page.locator('.topbar-konto-tresc').evaluate(e => {
          const r = e.getBoundingClientRect();
          const logout = e.querySelector('button');
          const text = document.createRange(); text.selectNodeContents(logout);
          return { x: r.x, right: r.right, width: r.width, viewport: innerWidth, scroll: document.documentElement.scrollWidth,
            logoutHeight: text.getBoundingClientRect().height, lineHeight: parseFloat(getComputedStyle(logout).lineHeight),
            controls: e.querySelectorAll('a,button').length, csrf: !!e.querySelector('form[method="POST"] input[name="_token"]') };
        });
        if (geo.controls !== 4 || !geo.csrf) throw Error('Brak pełnego menu moderatora lub CSRF');
        if (geo.x < 0 || geo.right > geo.viewport + 1 || geo.scroll > geo.viewport + 1) throw Error('Menu poza ekranem: ' + JSON.stringify(geo));
        if (width === 1440 && (geo.width < 300 || geo.logoutHeight > geo.lineHeight * 1.5)) throw Error('MENU_ZBYT_WASKIE ' + JSON.stringify(geo));
        const actualZoom = await worker.evaluate(id => chrome.tabs.getZoom(id), tab.id);
        if (actualZoom !== zoom || Math.abs(geo.viewport - width) > 1) throw Error('Nieprawidłowy zoom lub viewport');
        await page.locator('.topbar-konto > summary').focus();
        for (let i = 0; i < 4; i++) {
          await page.keyboard.press('Tab');
          const focus = await page.evaluate(() => {
            const a = document.activeElement, r = a.getBoundingClientRect(), hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
            return !!a.closest('.topbar-konto-tresc') && a.matches(':focus-visible') && r.height >= 48 && r.y >= 0 && r.bottom <= innerHeight && (hit === a || a.contains(hit));
          });
          if (!focus) throw Error('Niedostępny fokus menu');
        }
        if ([320, 1440].includes(width) && scale === 140) {
          // Bez klipu CSS: przy rzeczywistym zoomie klip ucinał fizyczny obraz.
          const session = await context.newCDPSession(page);
          const shot = await session.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
          writeFileSync(join(out, `${width}-${zoom}-${theme}.png`), Buffer.from(shot.data, 'base64'));
          await session.detach();
        }
        rows.push({ viewportWidth: width, viewportHeight: height, zoom: actualZoom, theme, scale, ...geo });
      }
    } finally { await context.close(); rmSync(ext, { recursive: true }); rmSync(profile, { recursive: true }); }
  }
}
writeFileSync(join(out, 'wyniki.json'), JSON.stringify(rows, null, 2));
console.log('Menu Konto: PASS', rows.length);
