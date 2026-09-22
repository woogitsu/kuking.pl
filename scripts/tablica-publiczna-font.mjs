/* Regresja CI #557: nagłówek listy nie rozszerza strony przy dużym piśmie.
 * To font bazowy 32px, nie zoom. Rzeczywisty zoom mierzy tablica-publiczna.mjs.
 * Lokalna strona musi zawierać przynajmniej jedną osobę na publicznej tablicy.
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';

const out = process.env.OUTPUT_DIR || 'output/tablica-font557';
mkdirSync(out, { recursive: true });
const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox'] });
const results = [];
try {
  for (const width of [320, 360, 390, 414, 768, 1440]) for (const theme of ['light', 'dark']) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await context.newPage();
    await (await context.newCDPSession(page)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
    await page.goto(process.env.BASE_URL || 'http://127.0.0.1:8033/');
    await page.evaluate(theme => {
      document.documentElement.dataset.theme = theme;
      document.documentElement.dataset.textScale = '140';
    }, theme);
    await page.evaluate(async () => { await document.fonts.ready; for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame); });
    const result = await page.locator('.landing-tablica .marka-tablica-naglowek h3').evaluate(el => {
      const r = el.getBoundingClientRect();
      return { viewport: innerWidth, scroll: document.documentElement.scrollWidth, root: parseFloat(getComputedStyle(document.documentElement).fontSize), body: parseFloat(getComputedStyle(document.body).fontSize), x: r.x, right: r.right, width: r.width, headingFont: parseFloat(getComputedStyle(el).fontSize) };
    });
    if (result.root !== 32 || Math.abs(result.body - 50.4) > .15) throw new Error('FONT_NIEUSTAWIONY ' + JSON.stringify(result));
    if (result.scroll > width + 1 || result.right > width || result.x < 0) throw new Error('NAGLOWEK_TABLICY_OVERFLOW ' + JSON.stringify(result));
    results.push({ width, theme, ...result });
    if (width === 320) {
      await page.locator('.landing-tablica .marka-tablica-naglowek').evaluate(el => scrollTo(0, scrollY + el.getBoundingClientRect().y - document.querySelector('header').getBoundingClientRect().height - 20));
      await page.screenshot({ path: `${out}/font-${theme}.png` });
    }
    await context.close();
  }
  writeFileSync(`${out}/wyniki.json`, JSON.stringify(results, null, 2));
  console.log('FONT_TABLICY_OK', results.length);
} finally {
  await browser.close();
}
