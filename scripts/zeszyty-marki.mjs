/* Odbiór #511: prawdziwe GET, geometria tekstu i kliknięcia. Bazę i sesję
   przygotowuje port-projektu; zoom karty mierzy osobno zoom-marki. */
import { appendFileSync, readFileSync, mkdtempSync, statSync, realpathSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';

export async function sprawdzZeszyty({ browser, adres, sesja, zeszyt, negatywy = true }) {
  if (!zeszyt?.startsWith('/zeszyt/')) throw new Error('K511_FIXTURE brak zeszytu');
  let count = 0;
  async function sprawdz(width, dark, scale, path, interaction = false) {
    const context = await browser.newContext({ storageState: sesja, viewport: { width, height: 900 }, reducedMotion: 'reduce' });
    try {
      const page = await context.newPage();
      const imageRequests = new Map();
      const pathname = url => { try { return new URL(url).pathname; } catch { return null; } };
      const imageTrace = request => {
        let first = request;
        while (first.redirectedFrom()) first = first.redirectedFrom();
        if (first.resourceType() !== 'image') return null;
        // Łączymy również odpowiedź docelową po 302 z adresem użytym przez img.
        // Pełne URL służą tylko dopasowaniu w pamięci; raport zawiera same ścieżki.
        if (!imageRequests.has(first.url())) imageRequests.set(first.url(), []);
        return imageRequests.get(first.url());
      };
      page.on('response', response => {
        const trace = imageTrace(response.request());
        if (trace) trace.push({ pathname: pathname(response.url()), status: response.status() });
      });
      page.on('requestfailed', request => {
        const trace = imageTrace(request);
        // Nie wypisujemy surowej treści błędu: mogłaby zawierać podpisany URL.
        const failure = request.failure()?.errorText?.match(/\bnet::ERR_[A-Z0-9_]+\b/)?.[0] ?? 'REQUEST_FAILED';
        if (trace) trace.push({ pathname: pathname(request.url()), failure });
      });
      const large = String(scale).startsWith('font-200');
      const factor = scale === 140 || scale === 'font-200+140' ? 1.4 : 1;
      if (large) await (await context.newCDPSession(page)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
      await page.addInitScript(({ dark, factor }) => document.addEventListener('DOMContentLoaded', () => {
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        document.documentElement.dataset.textScale = factor === 1.4 ? '140' : '100';
      }), { dark, factor });
      const response = await page.goto(adres + path, { waitUntil: 'networkidle' });
      if (response.status() !== 200) throw new Error('K511_HTTP ' + response.status());
      await page.evaluate(() => document.fonts.ready);
      await page.waitForFunction(({ size, color }) => Math.abs(parseFloat(getComputedStyle(document.body).fontSize) - size) < .15 && getComputedStyle(document.body).color === color,
        { size: 18 * (large ? 2 : 1) * factor, color: dark ? 'rgb(244, 245, 241)' : 'rgb(21, 23, 20)' });
      // Zdjęcia poniżej ekranu są lazy: najpierw rzeczywiście je przewijamy.
      for (const image of await page.locator('.marka-zeszyt img').all()) {
        await image.scrollIntoViewIfNeeded();
        try {
          await image.evaluate(async img => {
            for (let i = 0; i < 100; i++) {
              if (img.complete && img.naturalWidth > 0) return;
              await new Promise(resolve => setTimeout(resolve, 50));
            }
            throw new Error('K511_IMAGE');
          });
        } catch (error) {
          if (!error.message.includes('K511_IMAGE')) throw error;
          const source = await image.evaluate(img => img.currentSrc || img.src);
          throw new Error('K511_IMAGE ' + JSON.stringify({
            width, dark, scale, path: pathname(adres + path),
            pathname: pathname(source), responses: imageRequests.get(source) ?? [],
          }));
        }
      }
      await page.evaluate(() => scrollTo(0, 0));
      const r = await page.evaluate(() => {
        const visible = el => {
          if (!el || !el.getClientRects().length) return false;
          for (let p = el; p; p = p.parentElement) {
            const s = getComputedStyle(p);
            if (s.display === 'none' || s.visibility !== 'visible' || Number(s.opacity) < .99) return false;
          }
          return true;
        };
        const completeText = el => {
          if (!visible(el)) return false;
          const range = document.createRange(); range.selectNodeContents(el);
          for (const rect of range.getClientRects()) {
            if (rect.left < -1 || rect.right > innerWidth + 1) return false;
            for (let p = el; p; p = p.parentElement) {
              const s = getComputedStyle(p), b = p.getBoundingClientRect();
              if (s.webkitLineClamp !== 'none' && s.webkitLineClamp !== '0') return false;
              if (/(hidden|clip|auto|scroll)/.test(s.overflowY) && (rect.top < b.top - 1 || rect.bottom > b.bottom + 1)) return false;
              if (/(hidden|clip|auto|scroll)/.test(s.overflowX) && (rect.left < b.left - 1 || rect.right > b.right + 1)) return false;
            }
          }
          return true;
        };
        const folders = document.querySelector('.marka-zeszyty');
        const recent = document.querySelector('section.marka-zeszyt-ostatnie');
        const details = document.querySelector('.marka-zeszyt details.panel-formularza');
        const cards = [...document.querySelectorAll('.marka-zeszyty > article')];
        // Od #978 elementem siatki jest `.marka-zeszyt-pozycja` (kafel + notatka
        // właściciela pod nim); kafel musi wypełniać całą szerokość pozycji.
        const recipes = [...document.querySelectorAll('.marka-zeszyt-przepisy > .marka-zeszyt-pozycja > article')];
        const long = recipes.find(el => el.textContent.includes('KONIEC511'));
        const noImage = recipes.find(el => el.querySelector('a[href$="pomiar-zeszytu-511-1"]'));
        return {
          width: innerWidth, scroll: document.documentElement.scrollWidth,
          wrapper: !!document.querySelector('main .marka-zeszyt'),
          folders: cards.map(el => ({ classes: el.matches('.card.blok-ciemny.marka-zeszyt-karta'), background: getComputedStyle(el).backgroundColor, title: completeText(el.querySelector('h2 a')) })),
          recent: !!recent && !!folders && !!details && !!recent.closest('main') && !recent.closest('aside')
            && !!(folders.compareDocumentPosition(recent) & Node.DOCUMENT_POSITION_FOLLOWING)
            && !!(recent.compareDocumentPosition(details) & Node.DOCUMENT_POSITION_FOLLOWING)
            && document.querySelectorAll('.marka-zeszyt-ostatnie').length === 1,
          recentLinks: [...document.querySelectorAll('.marka-zeszyt-zapis')].map(el => el.querySelectorAll('a').length === 1 && !!el.querySelector('.marka-zeszyt-zapis-link') && !el.querySelector('a img')),
          recipes: recipes.map(el => {
            const image = el.querySelector('img'), title = el.querySelector('h3');
            return { tile: el.matches('.card.recipe-card-kafel') && Math.abs(el.getBoundingClientRect().width - el.parentElement.getBoundingClientRect().width) <= 1, x: el.getBoundingClientRect().x, y: el.getBoundingClientRect().y,
              full: completeText(title), image: !image || (image.complete && image.naturalWidth > 0 && visible(image)),
              photoAbove: !image || image.getBoundingClientRect().bottom <= title.getBoundingClientRect().top + 1 };
          }),
          long: !!long && completeText(long.querySelector('h3')),
          noImage: !!noImage && !noImage.querySelector('img, .recipe-card-miniatura'),
        };
      });
      const fail = code => { throw new Error(code + ' ' + JSON.stringify({ path, width, dark, scale, r })); };
      if (r.scroll > r.width + 1) fail('K511_OVERFLOW');
      if (!r.wrapper) fail('K511_WRAPPER');
      if (path === '/zeszyt') {
        if (r.folders.length < 3 || r.folders.some(c => !c.classes || !c.title)) fail('K511_ZESZYTY');
        if (r.folders.some(c => c.background !== 'rgb(21, 23, 20)')) fail('K511_CIEMNE_KARTY');
        if (!r.recent || !r.recentLinks.length || r.recentLinks.some(v => !v)) fail('K511_OSTATNIE_MAIN');
      } else {
        if (r.recipes.length !== 3 || r.recipes.some(c => !c.tile || !c.image || !c.photoAbove)) fail('K511_KAFLE');
        if (!r.long || r.recipes.some(c => !c.full)) fail('K511_PELNY_TYTUL');
        if (!r.noImage) fail('K511_BEZ_ZDJECIA');
        if (width === 1440 && scale === 100 && new Set(r.recipes.map(c => Math.round(c.x))).size < 2) fail('K511_GRID');
      }
      if (interaction) {
        const selector = path === '/zeszyt' ? '.marka-zeszyt-zapis-link, .marka-zeszyty h2 a' : '.marka-zeszyt-przepisy a';
        const expected = await page.locator(selector).evaluateAll(elements => { elements.forEach((el, i) => el.dataset.tab511 = String(i)); return elements.length; });
        if (!expected) fail('K511_TAB_EMPTY');
        const seen = new Set();
        await page.evaluate(() => { document.activeElement?.blur(); scrollTo(0, 0); });
        for (let i = 0; i < expected + 80 && seen.size < expected; i++) {
          await page.keyboard.press('Tab');
          const focus = await page.evaluate(async () => {
            const el = document.activeElement;
            if (!el?.hasAttribute('data-tab511')) return null;
            await Promise.all(el.getAnimations().map(a => a.finished.catch(() => {})));
            const s = getComputedStyle(el);
            const fragments = [...el.getClientRects()].filter(b => b.width > 0 && b.height > 0);
            return { id: el.dataset.tab511, html: el.outerHTML.slice(0, 300), boxes: fragments.map(b => b.toJSON()), outline: s.outline, shadow: s.boxShadow, visible: fragments.length > 0 && fragments.every(b => {
              const hit = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
              return b.top >= 0 && b.bottom <= innerHeight && (hit === el || el.contains(hit));
            }), marked: s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0 || s.boxShadow !== 'none' };
          });
          if (focus) {
            if (!focus.visible || !focus.marked) {
              await page.screenshot({ path: 'storage/port-projektu/zeszyty511-focus-failure.png' });
              throw new Error('K511_TAB ' + JSON.stringify({ path, width, dark, scale, focus }));
            }
            seen.add(focus.id);
          }
        }
        if (seen.size !== expected) fail('K511_TAB_INCOMPLETE');
        {
          const index = path === '/zeszyt';
          const code = index ? 'K511_KLIK_ZDJECIA' : 'K511_KLIK_PRZEPISU';
          const card = page.locator(index ? '.marka-zeszyt-zapis' : '.recipe-card-kafel').filter({ has: page.locator('img') }).first();
          const link = card.locator(index ? '.marka-zeszyt-zapis-link' : '.recipe-card-otworz');
          const target = await link.getAttribute('href');
          const photo = card.locator('img');
          await photo.scrollIntoViewIfNeeded();
          const b = await photo.boundingBox();
          if (!b) fail(code);
          await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2);
          try { await page.waitForURL(target, { timeout: 3000 }); } catch { fail(code); }
        }
      }
      if ((width === 1440 && scale === 100) || (width === 320 && scale === 'font-200+140')) {
        await page.goto(adres + path, { waitUntil: 'networkidle' });
        await page.screenshot({ path: `storage/port-projektu/zeszyty511-${path === '/zeszyt' ? 'index' : 'show'}-${width}-${dark}-${scale}.png`, fullPage: true });
      }
      return r;
    } finally { await context.close(); }
  }
  for (const width of [320, 360, 390, 414, 768, 1440]) for (const dark of [false, true]) for (const scale of [100, 140, 'font-200', 'font-200+140']) for (const path of ['/zeszyt', zeszyt]) {
    await sprawdz(width, dark, scale, path, (width === 320 && scale === 140) || (width === 1440 && scale === 100)); count++;
  }
  if (negatywy) {
    const source = 'resources/css/marka-zeszyt.css';
    if (!['localhost', '127.0.0.1'].includes(new URL(adres).hostname) || !realpathSync(source).startsWith(realpathSync(process.cwd()) + '/')) throw new Error('K511_NEGATIVE_LOCAL_ONLY');
    execFileSync('git', ['ls-files', '--error-unmatch', source]);
    const backup = mkdtempSync(tmpdir() + '/zeszyty511-negative-') + '/source.css';
    const md5 = file => createHash('md5').update(readFileSync(file)).digest('hex');
    execFileSync('cp', ['-p', source, backup]);
    const hash = md5(source), mtime = statSync(source).mtimeMs;
    const build = () => execFileSync('npm', ['run', 'build:assets'], { stdio: 'pipe' });
    for (const [name, code, css, path, interaction] of [
      ['jasne-karty', 'K511_CIEMNE_KARTY', '.marka-zeszyt-karta { background: white !important; }', '/zeszyt', false],
      ['jedna-kolumna', 'K511_GRID', '.marka-zeszyt-przepisy { grid-template-columns: 1fr !important; }', zeszyt, false],
      ['uciety-tytul', 'K511_PELNY_TYTUL', '.recipe-card-kafel h3 { display: -webkit-box !important; -webkit-box-orient: vertical !important; -webkit-line-clamp: 2 !important; overflow: hidden !important; }', zeszyt, false],
      ['klik-zdjecia', 'K511_KLIK_ZDJECIA', '.marka-zeszyt-zapis-link::after { content: none !important; }', '/zeszyt', true],
      ['klik-przepisu', 'K511_KLIK_PRZEPISU', '.recipe-card-otworz::after { content: none !important; }', zeszyt, true],
    ]) {
      let failure;
      try {
        appendFileSync(source, '\n/* Negatyw511 */\n' + css + '\n'); build();
        try { await sprawdz(1440, false, 100, path, interaction); } catch (error) { failure = error; }
      } finally {
        execFileSync('cp', ['-p', backup, source]);
        if (md5(source) !== hash || statSync(source).mtimeMs !== mtime) throw new Error('K511_RESTORE');
        build();
      }
      for (const dark of [false, true]) await sprawdz(1440, dark, 100, path, interaction);
      if (!failure?.message.startsWith(code + ' ')) throw new Error('K511_NEGATIVE_WRONG_RESULT ' + name + ' ' + (failure?.message ?? 'PASS'));
      console.log(`K511_NEGATIVE_OK ${name} code=${code} MD5=${hash} mtime=restored`);
    }
  }
  console.log(`K511_OK ${count} konfiguracji`);
}
