// #589: rzeczywista kaskada z buildu, bez PHP, bazy i połączeń z serwisem.
import assert from 'node:assert/strict';
import { readFileSync, mkdirSync, writeFileSync, readdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { poczekajNaStan } from './lib/stan-ustalony.mjs';

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const entry = manifest['resources/css/app.css'];
assert(entry?.file, 'Brak CSS w manifeście Vite');
const css = readFileSync(`public/build/${entry.file}`, 'utf8');
const fontName = readdirSync('public/build/assets').find(name => name.startsWith('inter-latin-wght-normal-') && name.endsWith('.woff2'));
assert(fontName, 'Brak lokalnego fontu Inter w buildzie');
const font = readFileSync(`public/build/assets/${fontName}`).toString('base64');
const out = 'output/skala589';
mkdirSync(out, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
const rows = [];
let kontekst = '';
const near = (actual, expected, name) => assert(Math.abs(actual - expected) < .2, `${name}: ${actual} zamiast ${expected} (${kontekst})`);
try {
  for (const width of [320, 360, 390, 414, 768, 1440]) for (const theme of ['light', 'dark']) {
    const context = await browser.newContext({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
    await context.route('**/*', route => route.abort());
    const page = await context.newPage();
    await page.setContent(`<html data-text-scale="100" data-theme="${theme}"><body data-marka>
      <main><button id="button" class="btn btn-primary">Zapisz</button>
      <header class="topbar-inner">Menu</header>
      <section class="marka-publikacja">Dodaj zdjęcie</section>
      <article class="post-card"><div class="post-card-head">Autor</div></article>
      <nav class="feed-tabs"><a class="tab" href="#button">Wpisy</a></nav>
      <input id="field" class="field-input" aria-label="Nazwa">
      <div id="utilities" class="p-4 gap-6 flex"><span>A</span><span>B</span></div>
      <ul class="landing-kroki"><li><h3>Robisz zdjęcie</h3><p>Kilka słów o daniu.</p><a href="#button">Dodaj zdjęcie dania</a></li></ul>
      <section class="landing-wykonanie"><div class="landing-wykonanie-tekst">Twój przepis.</div></section>
      <section class="landing-tablica"><div class="kuking-board-posts"><article class="kuking-board-post"><div class="kuking-board-post-body">Danie</div></article></div></section>
      </main></body></html>`);
    await page.addStyleTag({ content: css });
    await page.evaluate(async font => {
      const face = new FontFace('Inter Variable', `url(data:font/woff2;base64,${font})`, { weight: '100 900' });
      document.fonts.add(await face.load());
      await document.fonts.ready;
    }, font);
    const group = [];
    for (const scale of [100, 70, 80, 90, 140, 100]) {
      await page.evaluate(scale => { document.documentElement.dataset.textScale = String(scale); }, scale);
      /* Stan zamiast trzech klatek (24 września 2026). Arkusz wchodzi przez
         `addStyleTag` na stronę już wyrenderowaną w 16 px, a przy
         `reducedMotion: 'reduce'` każda zmiana rozmiaru pisma jest przejściem
         0.01ms (`tokens.css`, `transition-property: all`). „Trzy klatki”
         bywały za krótkie i CI mierzyło jeszcze stan sprzed arkusza:
         „tekst: 16 zamiast 18”. Czekamy na oczekiwany rozmiar pisma, fonty
         i koniec wszystkich przejść; porażka podaje zmierzone wartości. */
      const oczekiwane = { font: 18 * scale / 100, skala: String(scale), motyw: theme };
      const stan = await poczekajNaStan(page, {
        arg: oczekiwane,
        limitMs: 10_000,
        warunek: (o, przejscia) => document.fonts.status === 'loaded'
          && Math.abs(parseFloat(getComputedStyle(document.body).fontSize) - o.font) < .2
          && przejscia().length === 0,
        pomiar: (o, przejscia) => ({ oczekiwane: o, font: parseFloat(getComputedStyle(document.body).fontSize), color: getComputedStyle(document.body).color, skala: document.documentElement.dataset.textScale, motyw: document.documentElement.dataset.theme, fonty: document.fonts.status, przejscia: przejscia() }),
      });
      assert(stan.ustalony, `tekst: stan skali nie ustalił się w 10000 ms (${width} px, ${theme}, skala ${scale}). Zmierzone: ${JSON.stringify(stan.zmierzone)}`);
      const geo = await page.evaluate(() => {
        const style = selector => getComputedStyle(document.querySelector(selector));
        return {
          font: parseFloat(style('body').fontSize),
          color: style('body').color,
          padding: parseFloat(style('#button').paddingTop),
          button: document.querySelector('#button').getBoundingClientRect().height,
          field: document.querySelector('#field').getBoundingClientRect().height,
          fieldMinimum: parseFloat(style('#field').minHeight),
          gap: parseFloat(style('.landing-kroki').rowGap),
          step: parseFloat(style('.landing-kroki > li').paddingTop),
          card: parseFloat(style('.kuking-board-post-body').paddingTop),
          story: parseFloat(style('.landing-wykonanie-tekst').paddingTop),
          utility: parseFloat(style('#utilities').paddingTop),
          utilityGap: parseFloat(style('#utilities').gap),
          topbar: parseFloat(style('.topbar-inner').paddingTop),
          composer: parseFloat(style('.marka-publikacja').paddingTop),
          post: parseFloat(style('.post-card-head').paddingTop),
          tab: parseFloat(style('.feed-tabs .tab').paddingTop),
          overflow: document.documentElement.scrollWidth > innerWidth + 1,
        };
      });
      kontekst = `${width} px, ${theme}, skala ${scale}, kolor ${geo.color}`;
      const density = Math.min(1, scale / 100);
      near(geo.font, 18 * scale / 100, 'tekst');
      near(geo.padding, 12 * density, 'padding przycisku');
      near(geo.fieldMinimum, Math.max(48, 64 * density), 'minimalna wysokość pola');
      assert(geo.field >= geo.fieldMinimum, 'Pole musi pomieścić tekst i padding ponad minimum');
      near(geo.gap, 32 * density, 'odstęp kroków');
      near(geo.step, 28 * density, 'padding kroku');
      near(geo.card, 20 * density, 'padding karty tablicy');
      near(geo.story, (width >= 768 ? 52 : 28) * density, 'padding opowieści');
      near(geo.utility, 16 * density, 'utility padding');
      near(geo.utilityGap, 24 * density, 'utility gap');
      near(geo.topbar, (width <= 480 ? 12 : 10) * density, 'padding belki');
      near(geo.composer, (width <= 480 ? 20 : 28) * density, 'padding publikacji');
      near(geo.post, 22 * density, 'padding nagłówka wpisu');
      near(geo.tab, 12 * density, 'padding zakładki');
      assert(geo.button >= 48 && !geo.overflow, 'Cel dotykowy lub overflow');
      group.push({ scale, ...geo });
      rows.push({ width, theme, scale, ...geo });
    }
    assert.deepEqual(group[0], group.at(-1), 'Powrót do 100% musi odtworzyć geometrię');
    assert(group.find(r => r.scale === 140).button >= group[0].button, '140% nie zmniejsza przycisku');
    assert(group.find(r => r.scale === 70).field < group[0].field, 'Mała skala zmniejsza rzeczywistą wysokość pola');
    await context.close();
  }
  writeFileSync(`${out}/wyniki.json`, JSON.stringify({ css: entry.file, scope: 'Chromium, compiled CSS, reprezentatywny DOM; bez Laravel, zoomu i zapisu preferencji', rows }, null, 2));
  console.log(`Skala589 PASS: ${rows.length} konfiguracji`);
} finally { await browser.close(); }
