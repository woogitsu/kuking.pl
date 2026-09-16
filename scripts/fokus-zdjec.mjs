// Prawdziwe komponenty Blade, CSS z buildu i delegacja dialogu z app.js.
// Obrazy są syntetyczne. Nie zapisujemy danych i nie odpytyjemy produkcji.
import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { sprawdzTab } from './zoom-marki.mjs';

const fixtures = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['scripts/fixtures/fokus-zdjec.php'], { encoding: 'utf8' }));
const source = readFileSync('resources/js/app.js', 'utf8');
const anchor = source.indexOf("const okno = document.getElementById('powiekszenie')");
const begin = source.lastIndexOf('(() => {', anchor);
const end = source.indexOf('// --- Tryb gotowania', anchor);
assert(anchor >= 0 && begin >= 0 && end > anchor, 'Nie znaleziono obsługi dialogu');
const script = source.slice(begin, end);
const layout = readFileSync('resources/views/components/layout.blade.php', 'utf8');
const dialog = layout.match(/<dialog id="powiekszenie"[\s\S]*?<\/dialog>/)?.[0].replace(/\{\{--[\s\S]*?--\}\}/g, '');
assert(dialog, 'Nie znaleziono prawdziwego dialogu');
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const css = readFileSync('public/build/' + manifest['resources/css/app.css'].file, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true });
mkdirSync('output/fokus-zdjec', { recursive: true });

async function visibleFocus(page) {
  const state = await page.evaluate(async () => {
    await new Promise(requestAnimationFrame);
    await new Promise(requestAnimationFrame);
    const a = document.activeElement;
    const r = a.getBoundingClientRect();
    const css = getComputedStyle(a);
    return { focus: a.matches(':focus-visible'), top: r.top, bottom: r.bottom,
      left: r.left, right: r.right, width: innerWidth, height: innerHeight,
      forced: matchMedia('(forced-colors: active)').matches,
      outline: css.outlineStyle, outlineWidth: parseFloat(css.outlineWidth),
      outlineColor: css.outlineColor };
  });
  assert(state.focus && state.top >= 3 && state.bottom <= state.height - 3
    && state.left >= 3 && state.right <= state.width - 3, 'FOKUS_ZDJECIA ' + JSON.stringify(state));
  if (state.forced) assert(state.outline !== 'none' && state.outlineWidth >= 2
    && state.outlineColor !== 'transparent' && !state.outlineColor.endsWith(', 0)'), 'BRAK_SYSTEMOWEGO_FOKUSU ' + JSON.stringify(state));
}

try {
  for (const [name, html] of Object.entries(fixtures)) {
    for (const theme of ['light', 'dark', 'forced']) for (const width of [320, 360, 390, 414, 768, 1440]) {
      for (const [font, scale] of [[16, 70], [16, 100], [16, 140], [32, 140]]) {
      if (theme === 'forced' && !(width === 390 && font === 16 && scale === 100)) continue;
      const context = await browser.newContext({ viewport: { width, height: 900 }, hasTouch: true,
        forcedColors: theme === 'forced' ? 'active' : 'none' });
      const page = await context.newPage();
      const portrait = name.startsWith('pionowe');
      const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${portrait ? 900 : 1600}" height="${portrait ? 1600 : 1200}"><rect width="100%" height="100%" fill="#afa08b"/></svg>`;
      await page.route('**/zdjecia/**', route => route.fulfill({ contentType: 'image/svg+xml', body: svg }));
      await page.setContent(`<html data-theme="${theme === 'forced' ? 'light' : theme}" data-text-scale="${scale}"><head><style>${css}</style></head><body><main><div class="post-card" style="width:min(1030px,calc(100% - 48px));margin:24px auto">${html}</div></main>${dialog}</body></html>`);
      // Osobny przypadek dużego fontu; nie nazywamy go zoomem przeglądarki.
      await page.evaluate(font => document.documentElement.style.fontSize = font + 'px', font);
      await page.addScriptTag({ content: script });
      // Ten pomiar dotyczy gotowych zdjęć, nie polityki lazy loading.
      // Ostatni slajd może być poza progiem pobierania przeglądarki.
      await page.locator('img.post-photo').evaluateAll(images => Promise.all(images.map(img => {
        img.loading = 'eager';
        return img.decode();
      })));
      const count = await page.locator('a[data-powieksz]').count();
      assert.equal(count, name.endsWith('pojedyncze') ? 1 : 3, 'Niepełna lista zdjęć');
      const seen = new Set();
      for (let i = 0; i < count * 5 + 10 && seen.size < count; i++) {
        await page.keyboard.press('Tab');
        const href = await page.evaluate(() => document.activeElement.matches('a[data-powieksz]') ? document.activeElement.href : null);
        if (!href) continue;
        await visibleFocus(page);
        seen.add(href);
        await page.keyboard.press('Enter');
        assert(await page.locator('#powiekszenie').evaluate(el => el.open), 'Enter nie otworzył dialogu');
        await page.keyboard.press('Escape');
        assert.equal(await page.evaluate(() => document.activeElement.href), href, 'Escape zgubił fokus');
        await visibleFocus(page);
      }
      assert.equal(seen.size, count, 'Tab pominął zdjęcie');
      const image = page.locator('img.post-photo').first();
      const imagePoint = async () => {
        await image.scrollIntoViewIfNeeded();
        const r = await image.boundingBox();
        assert(r, 'Brak widocznego obrazu');
        return { x: (Math.max(0, r.x) + Math.min(width, r.x + r.width)) / 2,
          y: (Math.max(0, r.y) + Math.min(900, r.y + r.height)) / 2 };
      };
      // Pseudo-element linku celowo leży nad img. Klikamy współrzędne obrazu,
      // bez force i bez dispatchEvent, tak jak mysz lub palec człowieka.
      let point = await imagePoint();
      await page.mouse.click(point.x, point.y);
      assert(await page.locator('#powiekszenie').evaluate(el => el.open), 'Kliknięcie zdjęcia nie otworzyło dialogu');
      await page.getByRole('button', { name: 'Zamknij', exact: true }).click();
      assert.equal(await page.locator('#powiekszenie').evaluate(el => el.open), false);
      point = await imagePoint();
      await page.touchscreen.tap(point.x, point.y);
      assert(await page.locator('#powiekszenie').evaluate(el => el.open), 'Dotyk zdjęcia nie otworzył dialogu');
      // Prawdziwe zdarzenie close dociera po ponownym otwarciu dialogu.
      await page.evaluate(() => new Promise(resolve => {
        const dialog = document.querySelector('#powiekszenie');
        dialog.addEventListener('close', () => requestAnimationFrame(resolve), { once: true });
        dialog.close();
        document.querySelector('a[data-powieksz]').click();
      }));
      assert(await page.locator('#powiekszenie').evaluate(el => el.open), 'Nie otwarto zdjęcia ponownie');
      assert(await page.locator('.lightbox-obraz').getAttribute('src'), 'CLOSE_USUNAL_NOWE_ZDJECIE');
      await page.keyboard.press('Escape');
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'Poziomy overflow');
      if (font === 16 && scale === 140 && (width === 390 || width === 1440)) await sprawdzTab(page, 'zdjecia-' + name);
      if ((width === 320 || width === 1440) && scale === 140) await page.screenshot({ path: `output/fokus-zdjec/${name.replace('/', '-')}-${theme}-${width}-${font}-${scale}.png` });
      console.log('PASS', name, theme, width, font, scale, `Tab ${seen.size}/${count}, Enter/Escape, kliknięcie, dotyk`);
      await context.close();
      }
    }
  }
  for (const [name, html] of Object.entries(fixtures)) {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 900 } });
    const page = await context.newPage();
    await page.route('**/zdjecia/**', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="gray"/></svg>' }));
    await page.setContent(`<html><head><style>${css}</style></head><body><main>${html}</main>${dialog}</body></html>`);
    const link = page.locator('a[data-powieksz]').first();
    const href = await link.getAttribute('href');
    assert(href && href.includes('/large'), 'Brak rzeczywistego adresu dużego wariantu');
    await Promise.all([page.waitForURL(href), link.click()]);
    assert.equal(page.url(), href, 'Bez JS nie otwarto dużego zdjęcia');
    console.log('PASS bez JS', name);
    await context.close();
  }
} finally {
  await browser.close();
}
