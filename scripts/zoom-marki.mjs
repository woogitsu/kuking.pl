/* Prawdziwy zoom karty Chromium przez chrome.tabs.setZoom, nie font ani
   transform CSS. Rozszerzenie i profil powstają wyłącznie w katalogu tmp. */
import { chromium } from 'playwright';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';

async function sprawdzTab(page, path) {
  const expected = await page.evaluate(() => {
    const elements = [...document.querySelectorAll('main a[href], main button, main input, main select, main textarea, main summary, main [tabindex]')]
      .filter(el => el.tabIndex >= 0 && !el.disabled && el.getClientRects().length && getComputedStyle(el).visibility === 'visible')
      .filter(el => !el.closest('details:not([open])') || el.tagName === 'SUMMARY');
    elements.forEach((el, i) => el.dataset.pomiarTab = String(i));
    window.scrollTo(0, 0);
    document.activeElement?.blur();
    return elements.length;
  });
  if (!expected) throw new Error('ZOOM_TAB brak elementów ' + path);
  const seen = new Set();
  for (let i = 0; i < expected + 80 && seen.size < expected; i++) {
    await page.keyboard.press('Tab');
    const r = await page.evaluate(async () => {
      await new Promise(requestAnimationFrame);
      const el = document.activeElement;
      if (!el?.hasAttribute('data-pomiar-tab')) return null;
      const b = el.getBoundingClientRect();
      const top = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
      const css = getComputedStyle(el);
      return { id: el.dataset.pomiarTab, name: el.textContent.trim().slice(0,80), visible: b.y >= -1 && b.bottom <= innerHeight + 1 && b.x >= -1 && b.right <= innerWidth + 1 && (top === el || el.contains(top)), outline: parseFloat(css.outlineWidth) > 0 && css.outlineStyle !== 'none' };
    });
    if (!r) continue;
    if (!r.visible || !r.outline) throw new Error('ZOOM_TAB zasłonięty lub niewidoczny fokus ' + path + ' ' + JSON.stringify(r));
    seen.add(r.id);
  }
  if (seen.size !== expected) throw new Error('ZOOM_TAB niepełne przejście ' + path + ' ' + seen.size + '/' + expected);
  console.log('ZOOM_TAB_OK ' + path + ' ' + seen.size + '/' + expected);
}

export async function sprawdzZoomMarki({ adres, sesja, przepis }) {
  const directory = mkdtempSync(tmpdir() + '/kuking-zoom-');
  writeFileSync(directory + '/manifest.json', JSON.stringify({ manifest_version: 3, name: 'Odbior zoom Kuking', version: '1.0', permissions: ['tabs'], background: { service_worker: 'worker.js' } }));
  writeFileSync(directory + '/worker.js', 'chrome.runtime.onInstalled.addListener(() => {});');
  const context = await chromium.launchPersistentContext(mkdtempSync(tmpdir() + '/kuking-zoom-profil-'), {
    executablePath: process.env.CHROMIUM_PATH || chromium.executablePath(), headless: true,
    viewport: { width: 1440, height: 1480 }, reducedMotion: 'reduce',
    args: ['--no-sandbox', '--disable-extensions-except=' + directory, '--load-extension=' + directory],
  });
  let liczba = 0;
  try {
    const worker = context.serviceWorkers()[0] || await context.waitForEvent('serviceworker', { timeout: 15000 });
    const page = await context.newPage();
    for (const width of [640, 1440]) for (const dark of [false, true]) for (const scale of [100, 140]) {
      await page.setViewportSize({ width, height: 1480 });
      for (const path of ['/login', '/register', '/', przepis, '/@ania', '/@zofia_z_bieszczad']) {
        await context.clearCookies();
        if (path.startsWith('/@') || path === przepis) await context.addCookies(sesja.cookies);
        const response = await page.goto(adres + path, { waitUntil: 'networkidle' });
        if (response.status() !== 200) throw new Error('ZOOM_HTTP ' + path);
        const zoom = await worker.evaluate(async url => {
          const tab = (await chrome.tabs.query({})).find(t => t.url === url);
          if (!tab) throw new Error('Brak karty do pomiaru');
          await chrome.tabs.setZoom(tab.id, 2);
          return chrome.tabs.getZoom(tab.id);
        }, page.url());
        await page.evaluate(async ({ dark, scale }) => {
          document.documentElement.dataset.theme = dark ? 'dark' : 'light';
          document.documentElement.dataset.textScale = String(scale);
          await document.fonts.ready;
          for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame);
        }, { dark, scale });
        const r = await page.evaluate(() => ({ width: innerWidth, dpr: devicePixelRatio, scroll: document.documentElement.scrollWidth, font: parseFloat(getComputedStyle(document.body).fontSize) }));
        if (zoom !== 2 || Math.abs(r.width - width / 2) > 1 || r.dpr !== 2) throw new Error('ZOOM_NIE_PRZYLOZONY ' + JSON.stringify({zoom, width, ...r}));
        if (r.scroll > r.width + 1 || Math.abs(r.font - 18 * scale / 100) > .1) throw new Error('ZOOM_UKLAD ' + path + ' ' + JSON.stringify(r));
        if (width === 640 && scale === 140 && !dark) await sprawdzTab(page, path);
        if (width === 640 && scale === 140) await page.screenshot({ path: `storage/port-projektu/zoom200-${path.replaceAll('/', '').replace('@', '') || 'publiczna'}-${dark}.png`, fullPage: true });
        liczba++;
      }
    }
  } finally { await context.close(); }
  console.log('ZOOM200_OK ' + liczba + ' wariantów; chrome.tabs.getZoom=2, DPR=2, viewport=połowa szerokości');
}
