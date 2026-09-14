import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export function przygotujKaruzele(env) {
  const fixture = JSON.parse(execFileSync('php', ['scripts/fixtures/karuzela-mieszana.php'], { env }).toString());
  if (! fixture.id || ! fixture.path?.startsWith('/wpisy/')) throw new Error('Brak próbki431.');
  let cleaned = false;
  const sprzataj = () => {
    if (cleaned) return;
    execFileSync('php', ['scripts/fixtures/karuzela-mieszana.php', 'sprzataj', fixture.id], { env });
    cleaned = true;
  };
  return { ...fixture, sprzataj };
}

function wymagaj(warunek, opis, dane) {
  if (! warunek) throw new Error(`Karuzela431: ${opis}: ${JSON.stringify(dane)}`);
}

/** Ten sam przebieg obsługuje kliknięcia, Tab i geometrię. Nie uruchamia drugiego audytu axe. */
export async function zmierzKaruzele({ browser, adres, path, executablePath, output = 'output/playwright/karuzela431' }) {
  wymagaj(path?.startsWith('/wpisy/'), 'brak adresu próbki', path);
  mkdirSync(output, { recursive: true });
  const wyniki = [];
  const temp = mkdtempSync(join(tmpdir(), 'kuking431-zoom-'));
  let zoomContext;
  try {
    // Prawdziwy zoom karty. CSS zoom, deviceScaleFactor i Page.setPageScaleFactor
    // nie są zamiennikami chrome.tabs.setZoom (nie zmieniają tak samo layout viewport).
    const extension = join(temp, 'extension');
    mkdirSync(extension);
    writeFileSync(join(extension, 'manifest.json'), JSON.stringify({ manifest_version: 3, name: 'Pomiar431', version: '1.0', permissions: ['tabs'], background: { service_worker: 'worker.js' } }));
    writeFileSync(join(extension, 'worker.js'), 'chrome.runtime.onInstalled.addListener(() => {});');
    for (const width of [320, 390]) {
      for (const mode of ['zwykly', 'tekst140', 'font200', 'zoom200']) {
        let context;
        let worker;
        if (mode === 'zoom200') {
          zoomContext ??= await chromium.launchPersistentContext(join(temp, 'profile'), {
            executablePath, channel: 'chromium', headless: true, javaScriptEnabled: false,
            args: [`--disable-extensions-except=${extension}`, `--load-extension=${extension}`],
          });
          context = zoomContext;
          worker = context.serviceWorkers()[0] ?? await context.waitForEvent('serviceworker');
        } else {
          context = await browser.newContext({ viewport: { width, height: 900 }, javaScriptEnabled: false });
        }
        const page = await context.newPage();
        try {
          await page.setViewportSize({ width: mode === 'zoom200' ? width * 2 : width, height: 900 });
          const cdp = await context.newCDPSession(page);
          const screenshot = async (name) => {
            const shot = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false, fromSurface: true });
            writeFileSync(`${output}/${width}-${mode}${name}.png`, Buffer.from(shot.data, 'base64'));
          };
          if (mode === 'font200') await cdp.send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
          const response = await page.goto(`${adres}${path}`, { waitUntil: 'load' });
          wymagaj(response?.status() === 200, 'HTTP nie jest 200 (błąd infrastruktury/strony)', response?.status());
          if (mode === 'zoom200') {
            const tabs = await worker.evaluate(() => chrome.tabs.query({}));
            const tab = tabs.find(t => t.url === page.url());
            wymagaj(tab, 'nie znaleziono rzeczywistej karty do zoomu', page.url());
            await worker.evaluate(id => chrome.tabs.setZoom(id, 2), tab.id);
            await page.waitForFunction(w => innerWidth === w && devicePixelRatio === 2, width);
            wymagaj(await worker.evaluate(id => chrome.tabs.getZoom(id), tab.id) === 2, 'zoom nie wynosi 2', tab.id);
          }
          if (mode === 'tekst140') await page.evaluate(() => { document.documentElement.dataset.textScale = '140'; });
          await page.evaluate(() => document.fonts.ready);
          const slides = page.locator('.karuzela-slajd');
          wymagaj(await slides.count() === 2, 'brak dokładnie dwóch zdjęć próbki', await slides.count());
          // lazy loading: każde rzeczywiste zdjęcie musi zostać pobrane przed pomiarem.
          for (const image of await slides.locator('img').all()) {
            await image.scrollIntoViewIfNeeded();
            await image.evaluate(img => img.decode());
          }
          await page.goto(`${adres}${path}`, { waitUntil: 'load' });
          if (mode === 'tekst140') await page.evaluate(() => { document.documentElement.dataset.textScale = '140'; });
          await page.evaluate(() => document.fonts.ready);

          // Po drugim GET nie zakładamy, że cache zdążył zdekodować obrazy.
          // Czekamy na zasób, bez przewijania lub oczekiwania na pożądany styl.
          await slides.locator('img').evaluateAll(images => Promise.all(images.map(img => img.decode())));
          const sample = await slides.locator('img').evaluateAll(images => images.map(img => ({ width: img.naturalWidth, height: img.naturalHeight, src: img.currentSrc })));
          wymagaj(sample.length === 2 && sample.some(i => i.height > i.width) && sample.some(i => i.width > i.height)
            && sample.every(i => i.src.includes('/zdjecia/')), 'brak prawdziwej mieszanej próbki', sample);
          const steps = [];
          const snapshot = async (expected, action) => {
            const state = await page.evaluate(() => {
              const track = document.querySelector('.karuzela-tasma');
              const bounds = track.getBoundingClientRect();
              const all = [...track.querySelectorAll('.karuzela-slajd')];
              const index = all.findIndex(el => { const r = el.getBoundingClientRect(); return r.left <= bounds.left + bounds.width / 2 && r.right > bounds.left + bounds.width / 2; });
              const current = all[index];
              const frame = current.querySelector('.photo-zoom, .post-photo').getBoundingClientRect();
              return {
                slide: index + 1, width: innerWidth, documentWidth: document.documentElement.scrollWidth,
                scrollX, trackScroll: track.scrollLeft, trackHeight: bounds.height,
                frame: { width: frame.width, height: frame.height },
                fit: getComputedStyle(current.querySelector('img')).objectFit,
                clip: getComputedStyle(track).overflowX,
                controls: [...current.querySelectorAll('.karuzela-sterowanie .btn')].map(el => {
                  const r = el.getBoundingClientRect();
                  return { text: el.textContent.trim(), width: r.width, height: r.height, left: r.left, right: r.right,
                    fontSize: parseFloat(getComputedStyle(el).fontSize),
                    clippedText: el.scrollWidth > el.clientWidth + 1 };
                }),
              };
            });
            wymagaj(state.slide === expected, 'nawigacja nie dotarła do slajdu', { action, state });
            wymagaj(state.documentWidth <= state.width + 1 && Math.abs(state.scrollX) <= 1, 'przewija się dokument', state);
            wymagaj(Math.abs(state.frame.width - state.frame.height) <= 1 && state.fit === 'contain', 'utracona ramka D-191', state);
            wymagaj(['auto', 'scroll', 'hidden', 'clip'].includes(state.clip), 'sąsiednie slajdy nie są przycięte', state);
            wymagaj(state.controls.length === 2 && state.controls.every(c => c.width >= 48 && c.height >= 48 && c.left >= -1 && c.right <= state.width + 1 && ! c.clippedText), 'kontrolki są obcięte lub mniejsze niż 48px', state);
            if (steps.length) wymagaj(Math.abs(state.trackHeight - steps[0].trackHeight) <= 1, 'wysokość zmienia się po przejściu', state);
            steps.push({ action, ...state });
          };
          await snapshot(1, 'początek');
          await slides.nth(0).getByRole('link', { name: 'Następne zdjęcie' }).click();
          await page.waitForTimeout(250); // wyłącznie scroll-snap; poniżej niezależna asercja celu
          await snapshot(2, 'klik dalej');
          await slides.nth(1).getByRole('link', { name: 'Poprzednie zdjęcie' }).click();
          await page.waitForTimeout(250);
          await snapshot(1, 'klik wstecz');

          // Nowy dokument zeruje punkt tabulacji po kliknięciach. Nie ustawiamy focus() z JS.
          await page.goto(`${adres}${path}`, { waitUntil: 'load' });
          if (mode === 'tekst140') await page.evaluate(() => { document.documentElement.dataset.textScale = '140'; });
          await page.evaluate(() => document.fonts.ready);
          const tabTo = async (label) => {
            for (let tabs = 1; tabs <= 90; tabs++) {
              await page.keyboard.press('Tab');
              const found = await page.evaluate(text => {
                const el = document.activeElement;
                if (! el?.matches('.karuzela-sterowanie a') || el.textContent.trim() !== text) return null;
                const r = el.getBoundingClientRect();
                const s = getComputedStyle(el);
                const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
                return { visible: r.left >= 0 && r.right <= innerWidth && r.top >= 0 && r.bottom <= innerHeight && (hit === el || el.contains(hit)),
                  focus: s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0 || s.boxShadow !== 'none' };
              }, label);
              if (found) { wymagaj(found.visible && found.focus, 'Tab bez widocznej kontrolki/fokusu', found); return tabs; }
            }
            throw new Error(`Karuzela431: Tab nie dotarł do ${label}`);
          };
          const tabForward = await tabTo('Następne zdjęcie');
          await screenshot('-tab');
          await page.keyboard.press('Enter');
          await page.waitForTimeout(250);
          await snapshot(2, 'Tab Enter dalej');
          const tabBack = await tabTo('Poprzednie zdjęcie');
          await page.keyboard.press('Enter');
          await page.waitForTimeout(250);
          await snapshot(1, 'Tab Enter wstecz');
          const geometry = await page.evaluate(() => ({ innerWidth, dpr: devicePixelRatio, textScale: document.documentElement.dataset.textScale, rootFont: getComputedStyle(document.documentElement).fontSize }));
          if (mode === 'tekst140') {
            const baseline = wyniki.find(r => r.width === width && r.mode === 'zwykly');
            wymagaj(steps[0].controls[0].fontSize >= baseline.steps[0].controls[0].fontSize * 1.39, 'tekst140 nie powiększył kontrolki', steps[0].controls);
          }
          if (mode === 'font200') wymagaj(geometry.rootFont === '32px', 'font200 nie został ustawiony', geometry);
          await slides.nth(0).scrollIntoViewIfNeeded();
          await screenshot('');
          wyniki.push({ width, mode, ...geometry, sample, tabForward, tabBack, steps });
        } finally {
          await page.close();
          if (mode !== 'zoom200') await context.close();
        }
      }
    }
    writeFileSync(`${output}/wyniki.json`, JSON.stringify(wyniki, null, 2));
    wymagaj(wyniki.length === 8, 'niepełna macierz', wyniki.length);
    return wyniki;
  } finally {
    await zoomContext?.close();
    rmSync(temp, { recursive: true, force: true });
  }
}
