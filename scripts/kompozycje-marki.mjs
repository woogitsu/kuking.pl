/* Odbiór #509 na prawdziwych stronach. Wywołuje go port-projektu.mjs,
   który przygotowuje izolowaną bazę i sesję. */
import { execFileSync } from 'node:child_process';
import { appendFileSync, readFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { createHash } from 'node:crypto';

export async function sprawdzKompozycje({ browser, adres, sesja, przepis, bezZdjecia }) {
  const paths = ['/login', '/register', '/', przepis, bezZdjecia, '/@ania', '/@zofia_z_bieszczad'];
  const wyniki = [];
  async function pomiar(page, path, wariant = null) {
    const response = await page.goto(adres + path, { waitUntil: 'load' });
    if (response.status() !== 200) throw new Error('K509_HTTP ' + path + ' ' + response.status());
    await page.evaluate(() => document.fonts.ready);
    if (wariant) {
      const expected = 18 * (String(wariant.scale).startsWith('font-200') ? 2 : 1) * (wariant.scale === 140 || wariant.scale === 'font-200+140' ? 1.4 : 1);
      await page.waitForFunction(({value, dark}) => Math.abs(parseFloat(getComputedStyle(document.body).fontSize) - value) < .15 && getComputedStyle(document.body).color === (dark ? 'rgb(244, 245, 241)' : 'rgb(21, 23, 20)'), {value:expected,dark:wariant.dark});
    }
    await page.evaluate(async () => { for (let i = 0; i < 2; i++) await new Promise(requestAnimationFrame); });
    const r = await page.evaluate(() => {
      const visible = el => {
        if (!el || el.getBoundingClientRect().width <= 0 || el.getBoundingClientRect().height <= 0) return false;
        for (let node = el; node instanceof Element; node = node.parentElement) {
          const css = getComputedStyle(node);
          if (css.display === 'none' || css.visibility !== 'visible' || Number(css.opacity) < 0.99 || css.contentVisibility === 'hidden') return false;
        }
        return true;
      };
      const image = document.querySelector('.marka-przepis-zdjecie img');
      const box = selector => {
        const el = document.querySelector(selector);
        if (!el) return null;
        const b = el.getBoundingClientRect();
        return { x: b.x, y: b.y, right: b.right, bottom: b.bottom, width: b.width, height: b.height, background: getComputedStyle(el).backgroundColor, shadow: getComputedStyle(el).boxShadow };
      };
      return {
        width: innerWidth, scroll: document.documentElement.scrollWidth,
        rootFont: parseFloat(getComputedStyle(document.documentElement).fontSize),
        bodyFont: parseFloat(getComputedStyle(document.body).fontSize),
        bodyColor: getComputedStyle(document.body).color,
        imageReady: image && image.complete && image.naturalWidth > 0 && visible(image),
        statsVisible: [...document.querySelectorAll('.marka-profil-statystyki .stat-value, .marka-profil-statystyki .stat-label')].map(visible),
        actionBoxes: [...document.querySelectorAll('.przepis-akcje > a, .przepis-akcje > form button')].map(el => { const b = el.getBoundingClientRect(); return { x: b.x, right: b.right, y: b.y, width: b.width, visible: visible(el) }; }),
        desktop: matchMedia('(min-width: 901px)').matches,
        recipeDesktop: matchMedia('(min-width: 60rem)').matches,
        auth: box('.marka-wejscie'), brand: box('.marka-wejscie-zaproszenie'), form: box('.marka-wejscie-karta'),
        formInner: box('.marka-wejscie-karta .panel-formularza'),
        ownership: [...document.querySelectorAll('.marka-wlasnosc-karty > article')].map(el => ({ y: el.getBoundingClientRect().y, width: el.getBoundingClientRect().width })),
        hero: box('.marka-przepis-hero'), text: box('.marka-przepis-tekst'), photo: box('.marka-przepis-zdjecie'), actions: box('.marka-przepis > .przepis-panel'),
        profile: box('.marka-profil-kompozycja'), identity: box('.marka-profil-kompozycja .profil-tozsamosc'), avatar: box('.marka-profil-kompozycja .avatar'),
        profileGrid: box('.marka-profil-kompozycja .profil-glowka-tresc'), stats: box('.marka-profil-statystyki'),
        counters: document.querySelectorAll('.profil-liczby-karta').length,
        counterBoxes: [...document.querySelectorAll('.marka-profil-statystyki .profil-licznik')].map(el => ({y:el.getBoundingClientRect().y,width:el.getBoundingClientRect().width})),
      };
    });
    if (wariant) {
      const largeFont = String(wariant.scale).startsWith('font-200');
      const largeText = wariant.scale === 140 || wariant.scale === 'font-200+140';
      const expectedFont = 18 * (largeFont ? 2 : 1) * (largeText ? 1.4 : 1);
      if (Math.abs(r.bodyFont - expectedFont) > 0.15 || Math.abs(r.rootFont - (largeFont ? 32 : 16)) > 0.1) throw new Error('K509_SKALA ' + JSON.stringify({ wariant, root: r.rootFont, body: r.bodyFont, expectedFont }));
      if (r.bodyColor !== (wariant.dark ? 'rgb(244, 245, 241)' : 'rgb(21, 23, 20)')) throw new Error('K509_MOTYW ' + JSON.stringify({ wariant, color: r.bodyColor }));
    }
    if (r.scroll > r.width + 1) throw new Error('K509_OVERFLOW ' + path + ' ' + JSON.stringify(r));
    if (path === '/login' || path === '/register') {
      if (!r.auth || !r.brand || !r.form) throw new Error('K509_WEJSCIE brak kompozycji');
      if (r.desktop && (r.form.x < r.brand.right || r.form.width < 480)) throw new Error('K509_WEJSCIE_KOLUMNY kolumny ' + JSON.stringify(r));
      if (!r.desktop && r.form.y < r.brand.bottom - 1) throw new Error('K509_WEJSCIE kolejność');
      if (r.formInner.shadow !== 'none') throw new Error('K509_WEJSCIE zagnieżdżona karta');
    }
    if (path === '/') {
      if (r.ownership.length !== 3 || r.ownership.some(c => c.width < Math.min(200, r.width - 24))) throw new Error('K509_WLASNOSC brak trzech czytelnych kart');
      if (r.desktop && r.ownership.some(c => Math.abs(c.y - r.ownership[0].y) > 1)) throw new Error('K509_WLASNOSC_KOLUMNY kolumny');
    }
    if (path === bezZdjecia) {
      if (!r.hero || !r.text || r.photo || r.text.width < r.hero.width - 2) throw new Error('K509_BEZ_ZDJECIA pusta kolumna');
    }
    if (path === przepis) {
      if (!r.hero || !r.photo || !r.text || !r.actions) throw new Error('K509_PRZEPIS brak pełnego hero do pomiaru');
      if (!r.imageReady) throw new Error('K509_PRZEPIS zdjęcie niewidoczne lub niewczytane');
      if (wariant?.scale === 100 && wariant.zalogowany && r.recipeDesktop) {
        if (r.actionBoxes.length < 2 || r.actionBoxes.some(a => !a.visible) || Math.abs(r.actionBoxes[0].y - r.actionBoxes[1].y) > 1 || r.actionBoxes[1].x < r.actionBoxes[0].right - 1) throw new Error('K509_PRZEPIS_AKCJE akcje nie stoją obok siebie ' + JSON.stringify(r.actionBoxes));
      }
      if (r.recipeDesktop && (r.photo.x < r.text.right - 1 || Math.abs(r.photo.width - r.text.width) > 2)) throw new Error('K509_PRZEPIS_KOLUMNY kolumny');
      if (!r.recipeDesktop && r.photo.y < r.text.bottom - 1) throw new Error('K509_PRZEPIS kolejność');
      if (r.actions.y < r.hero.bottom - 1 || r.actions.width < r.hero.width - 2) throw new Error('K509_PRZEPIS akcje nadal w szynie');
    }
    if (path.startsWith('/@')) {
      if (!r.profile || !r.stats || r.stats.y < r.profile.bottom - 1 || r.counters !== 1) throw new Error('K509_PROFIL liczniki');
      if (r.statsVisible.length === 0 || r.statsVisible.some(v => !v)) throw new Error('K509_PROFIL niewidoczne liczby lub podpisy');
      if (r.avatar.width < 169 || r.avatar.height < 169) throw new Error('K509_PROFIL_AWATAR awatar');
      if (r.profileGrid.width >= 36 * r.rootFont && r.identity.x < r.avatar.right) throw new Error('K509_PROFIL kolumny');
      if (r.width === 1440 && wariant?.scale === 100 && (r.profile.width < 1100 || r.counterBoxes.length !== 5 || r.counterBoxes.some(b => Math.abs(b.y-r.counterBoxes[0].y)>1))) throw new Error('K509_PROFIL_PAS szeroka główka i pięć pól');
    }
    return r;
  }
  for (const width of [320, 360, 390, 414, 768, 1440]) for (const dark of [false, true]) for (const scale of [100, 140, 'font-200', 'font-200+140']) {
    for (const zalogowany of [false, true]) {
      const context = await browser.newContext({ storageState: zalogowany ? sesja : undefined, viewport: { width, height: 900 }, reducedMotion: 'reduce' });
      try {
        const page = await context.newPage();
        if (String(scale).startsWith('font-200')) await (await context.newCDPSession(page)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
        await page.addInitScript(({ dark, scale }) => document.addEventListener('DOMContentLoaded', () => {
          document.documentElement.dataset.theme = dark ? 'dark' : 'light';
          document.documentElement.dataset.textScale = String(scale === 140 || scale === 'font-200+140' ? 140 : 100);
        }), { dark, scale });
        for (const path of paths.filter(p => zalogowany ? p.startsWith('/@') || p === przepis || p === bezZdjecia : !p.startsWith('/@'))) {
          wyniki.push({ path, dark, scale, zalogowany, ...await pomiar(page, path, { dark, scale, zalogowany }) });
          if (path === przepis && ((width === 1440 && scale === 100) || (width === 320 && scale === 'font-200+140'))) {
            const link = page.locator('.marka-przepis-zdjecie a[data-powieksz]');
            await link.focus();
            await page.keyboard.press('Enter');
            await page.locator('#powiekszenie[open]').waitFor({ state: 'visible' });
            await page.waitForFunction(() => { const img = document.querySelector('#powiekszenie .lightbox-obraz'); return img?.complete && img.naturalWidth > 0; });
            await page.keyboard.press('Escape');
            await page.locator('#powiekszenie').waitFor({ state: 'hidden' });
            if (page.url() !== adres + path) throw new Error('K509_PRZEPIS powiększenie opuściło przepis');
          }
          if ((width === 1440 && scale === 100) || (width === 320 && scale === 140)) {
            await page.screenshot({ path: `storage/port-projektu/kompozycja509-${path.replaceAll('/', '').replace('@', '') || 'publiczna'}-${width}-${dark}-${zalogowany}.png`, fullPage: true });
          }
        }
      } finally { await context.close(); }
    }
  }

  // Każda mutacja dotyczy rzeczywistego arkusza, po niej odbudowa Vite,
  // oczekiwany błąd, odtworzenie bajtów/mtime i ponowny poprawny pomiar.
  for (const [file, css, path, code, logged, width = 1440, scale = 100] of [
    ['marka-wejscie.css', '.marka-wejscie { grid-template-columns: 1fr !important; }', '/login', 'K509_WEJSCIE_KOLUMNY', false],
    ['marka-wlasnosc.css', '.marka-wlasnosc-karty { grid-template-columns: 1fr !important; }', '/', 'K509_WLASNOSC_KOLUMNY', false],
    ['marka-przepis.css', '.marka-przepis > .marka-przepis-hero { grid-template-columns: 1fr !important; }', przepis, 'K509_PRZEPIS_KOLUMNY', true],
    ['marka-profil.css', '.marka-profil-kompozycja .avatar { width: 80px !important; height: 80px !important; }', '/@ania', 'K509_PROFIL_AWATAR', true],
    ['marka-profil.css', '.marka-profil-kompozycja { max-width: 720px !important; }', '/@ania', 'K509_PROFIL_PAS', true],
    ['marka-profil.css', '.marka-profil-statystyki .profil-liczby-karta { grid-template-columns: 1fr !important; }', '/@ania', 'K509_PROFIL_PAS', true],
    ['marka-przepis.css', '.marka-przepis .przepis-akcje .btn { width: 100% !important; }', przepis, 'K509_PRZEPIS_AKCJE', true],
    ['marka-rama.css', '[data-marka] .wordmark > span[aria-hidden] { display: inline !important; }', '/login', 'K509_OVERFLOW', false, 390, 'font-200+140'],
  ]) {
    const wariant = { dark: false, scale, zalogowany: logged };
    const newPage = async context => {
      const page = await context.newPage();
      if (String(scale).startsWith('font-200')) await (await context.newCDPSession(page)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
      await page.addInitScript(({ scale }) => document.addEventListener('DOMContentLoaded', () => {
        document.documentElement.dataset.theme = 'light';
        document.documentElement.dataset.textScale = String(scale === 140 || scale === 'font-200+140' ? 140 : 100);
      }), wariant);
      return page;
    };
    const options = { storageState: logged ? sesja : undefined, viewport: { width, height: 900 }, reducedMotion: 'reduce' };
    const source = 'resources/css/' + file;
    const copy = mkdtempSync(tmpdir() + '/kuking509-') + '/' + file;
    const hash = () => createHash('md5').update(readFileSync(source)).digest('hex');
    const before = hash();
    execFileSync('cp', ['-p', source, copy]);
    const context = await browser.newContext(options);
    try {
      // Punkt odniesienia musi przejść w dokładnie tym samym wariancie.
      await pomiar(await newPage(context), path, wariant);
      appendFileSync(source, '\n' + css + '\n');
      execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
      let detected = false;
      try { await pomiar(await newPage(context), path, wariant); }
      catch (e) { if (e.message.split(' ')[0] !== code) throw e; detected = true; }
      if (!detected) throw new Error('Niewykryta kontrola ujemna ' + code);
      console.log('K509_UJEMNA ' + code + ' przed=' + before + ' zmieniony=' + hash());
    } finally {
      execFileSync('cp', ['-p', copy, source]);
      if (hash() !== before) throw new Error('Nie odtworzono ' + source);
      execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
      await context.close();
    }
    const restored = await browser.newContext(options);
    try { await pomiar(await newPage(restored), path, wariant); } finally { await restored.close(); }
    console.log('K509_PRZYWROCONO ' + file + ' MD5=' + hash());
  }
  console.log('K509_OK ' + wyniki.length + ' wariantów');
  return wyniki;
}
