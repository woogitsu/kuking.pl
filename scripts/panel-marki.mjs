/* Prawdziwe GET panelu #581. Bez uruchamiania serwera, tworzenia danych i POST.
 * Wywołujący dostarcza izolowane sesje oraz selektory danych pełnych/pustych.
 * To pomiar kompozycji przy skali tekstu, nie test zapisu preferencji ani zoomu.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const rodziny = ['zgloszenia', 'sygnaly', 'odwolania', 'bez-odpowiedzi', 'wiadomosci', 'wiadomosc', 'kolaz-powitalny', 'kuking-na-dzis', 'tagi-promowane', 'uzytkownicy', 'uzytkownik'];
const listy = rodziny.filter(r => !['wiadomosc', 'uzytkownik'].includes(r));
const wymagaj = (warunek, kod, dane = {}) => { if (!warunek) throw new Error(`P581_${kod} ${JSON.stringify(dane)}`); };

function kompletneScenariusze(scenariusze, phase) {
  wymagaj(['pusty', 'pelny'].includes(phase), 'FAZA');
  const stan = phase;
  for (const r of phase === 'pelny' ? rodziny : listy) wymagaj(scenariusze.some(s => s.rodzina === r && s.stan === stan), 'BRAK_RODZINY', { phase, rodzina: r });
  for (const typ of ['wpisy', 'przepisy', 'ugotowane']) wymagaj(scenariusze.some(s => s.rodzina === 'bez-odpowiedzi' && s.stan === stan && (new URL(s.path, 'http://localhost').searchParams.get('typ') || 'wpisy') === typ), 'BRAK_TYPU', { phase, typ });
  if (phase === 'pusty') wymagaj(scenariusze.some(s => s.stan === 'bramka'), 'BRAK_BRAMKI');
}

function sprawdzKontrakt(adres, scenariusze, phase) {
  const url = new URL(adres);
  wymagaj(['localhost', '127.0.0.1', '[::1]'].includes(url.hostname) && ['http:', 'https:'].includes(url.protocol), 'TYLKO_LOKALNIE');
  wymagaj(Array.isArray(scenariusze), 'SCENARIUSZE');
  const ids = new Set();
  for (const s of scenariusze) {
    wymagaj(/^[a-z0-9-]+$/.test(s.id) && !ids.has(s.id), 'ID');
    ids.add(s.id);
    wymagaj(s.sesja && /^\/admin(?:\/|$)/.test(s.path) && !s.path.includes('..'), 'SESJA_LUB_TRASA', { id: s.id });
    wymagaj(['pelny', 'pusty', 'bramka'].includes(s.stan), 'STAN', { id: s.id });
    wymagaj(s.oczekiwaneSelektory?.some(e => e.min > 0) && s.oczekiwaneSelektory.every(e => typeof e.selector === 'string' && Number.isInteger(e.min) && e.min >= 0 && (e.max === undefined || Number.isInteger(e.max) && e.max >= e.min)), 'ORACLE_DANYCH', { id: s.id });
  }
  wymagaj(scenariusze.every(s => s.stan === phase || s.stan === 'bramka'), 'POMIESZANE_FAZY');
  kompletneScenariusze(scenariusze, phase);
}

// Wywołać dopiero po odbiorze pustej bazy, przygotowaniu danych i odbiorze pełnym.
export function sprawdzKompletnoscPaneluMarki({ pusty, pelny }) {
  for (const [phase, rows] of Object.entries({ pusty, pelny })) {
    wymagaj(Array.isArray(rows) && rows.length > 0 && rows.every(r => r.pass === true && r.phase === phase), 'AGREGAT_WYNIKI', { phase });
    kompletneScenariusze(rows, phase);
    for (const id of new Set(rows.map(r => r.id))) {
      const grupa = rows.filter(r => r.id === id);
      wymagaj(grupa.length === 24, 'AGREGAT_LICZBA', { phase, id, count: grupa.length });
      for (const width of [320, 360, 390, 414, 768, 1440]) for (const dark of [false, true]) for (const scale of [100, 140]) wymagaj(grupa.filter(r => r.width === width && r.dark === dark && r.scale === scale).length === 1, 'AGREGAT_WARIANT', { phase, id, width, dark, scale });
    }
  }
  return { pass: true, pusty: pusty.length, pelny: pelny.length, razem: pusty.length + pelny.length };
}

async function odczytajFokus(page) {
  return page.evaluate(() => {
    const e = document.activeElement;
    if (!e?.matches('[data-panel-pomiar]')) return null;
    const c = getComputedStyle(e);
    const probe = document.createElement('i');
    probe.style.setProperty('transition', 'none', 'important');
    probe.style.setProperty('animation', 'none', 'important');
    probe.style.setProperty('position', 'fixed', 'important');
    probe.style.setProperty('pointer-events', 'none', 'important');
    probe.style.setProperty('width', '0', 'important');
    probe.style.setProperty('height', '0', 'important');
    // Input/select/textarea nie są miejscem na renderowane dzieci. Token
    // odczytujemy z aktywnego elementu, normalizację koloru wykonuje body.
    const tokenFokusu = c.getPropertyValue('--color-focus').trim();
    probe.style.setProperty('color', tokenFokusu, 'important');
    document.body.append(probe);
    const kolorFokusu = tokenFokusu ? getComputedStyle(probe).color : ''; probe.remove();
    const nieprzezroczysty = kolor => /^rgb\(/.test(kolor) || /^rgba\([^)]*,\s*1\)$/.test(kolor);
    const warstwy = c.boxShadow.split(/,(?![^()]*\))/).map(w => {
      const kolor = w.match(/rgba?\([^)]*\)/)?.[0];
      const px = [...w.matchAll(/(-?[\d.]+)px/g)].map(m => Number(m[1]));
      return { kolor, px, inset: /\binset\b/.test(w) };
    });
    const pierscien = warstwy.some((w, i) => {
      if (w.inset || w.kolor !== kolorFokusu || !nieprzezroczysty(w.kolor) || w.px.length !== 4 || w.px[0] !== 0 || w.px[1] !== 0 || w.px[2] !== 0) return false;
      // Cienie wcześniejsze w CSS są nad pierścieniem: halo 2px pod pierścieniem
      // 5px pozostawia 3px niebieskiego, a nie pięć. Zwykły cień karty nie pasuje.
      const zakrycie = Math.max(0, ...warstwy.slice(0, i).filter(v => !v.inset).map(v => (v.px[3] || 0) + (v.px[2] || 0) + Math.max(Math.abs(v.px[0] || 0), Math.abs(v.px[1] || 0))));
      return w.px[3] - zakrycie >= 2;
    });
    const fragments = [...e.getClientRects()].map(b => {
      const x1 = Math.max(1, b.left + 2), x2 = Math.min(innerWidth - 1, b.right - 2);
      const y1 = Math.max(1, b.top + 2), y2 = Math.min(innerHeight - 1, b.bottom - 2);
      if (x2 < x1 || y2 < y1) return false;
      // Narożnik prostokąta może leżeć poza zaokrąglonym przyciskiem.
      // Środki krawędzi i centrum pozostają w powierzchni celu; przycinamy
      // je do widocznego fragmentu, lecz nie uznajemy przodka za trafienie.
      const mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
      return [[mx,y1],[mx,y2],[x1,my],[x2,my],[mx,my]].every(([x,y]) => { const h = document.elementFromPoint(x,y); return h === e || e.contains(h); });
    });
    const focusVisible = e.matches(':focus-visible');
    const dateFocusWithin = e.matches('input[type=date]:focus-within');
    return { id: e.dataset.panelPomiar, checked: e.checked, focus: focusVisible || dateFocusWithin, focusVisible, dateFocusWithin, outline: c.outlineStyle !== 'none' && parseFloat(c.outlineWidth) >= 2 && c.outlineColor === kolorFokusu && nieprzezroczysty(c.outlineColor), outlineStyle: c.outlineStyle, outlineWidth: c.outlineWidth, outlineColor: c.outlineColor, kolorFokusu, pierscien, shadow: c.boxShadow, fragments, width: e.getBoundingClientRect().width, height: e.getBoundingClientRect().height, target: e.matches('button,select,textarea,input:not([type=radio]):not([type=checkbox])') || !!e.closest('nav.side-nav') };
  });
}

function sprawdzFokus(r) {
  wymagaj(r && r.focus && (r.outline || r.pierscien), 'FOKUS', r);
  wymagaj(r.fragments.some(Boolean), 'FOKUS_ZASLONIETY', r);
  if (r.target) wymagaj(r.width >= 48 && r.height >= 48, 'CEL_48', r);
}

async function ustalonyFokus(page) {
  let r;
  for (let klatki = 0; klatki <= 30; klatki++) {
    r = await odczytajFokus(page);
    if (!r || (r.focus && (r.outline || r.pierscien)) || klatki === 30) return r && { ...r, klatki };
    // Czekamy wyłącznie na rzeczywisty styl pierścienia. Geometria nie jest
    // warunkiem ponawiania: zasłonięty cel nie może przejść dzięki oczekiwaniu.
    await page.evaluate(() => new Promise(requestAnimationFrame));
  }
}

// Wspólna kontrola także dla osobnego odbioru rzeczywistego zoomu.
export async function sprawdzKlawiaturePanelu(page) {
  // Identyfikatory są wyłącznie sondą. Fokus przesuwa tylko prawdziwy Tab.
  const cel = await page.evaluate(() => {
    const widoczny = e => e.getClientRects().length && getComputedStyle(e).visibility === 'visible' && e.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true });
    const wszystkie = [...document.querySelectorAll('a[href],button,input:not([type=hidden]),select,textarea,summary,[tabindex]')].filter(e => e.tabIndex >= 0 && !e.disabled && widoczny(e));
    const grupy = new Map();
    wszystkie.forEach((e, i) => {
      e.dataset.panelPomiar = String(i);
      if (e.matches('input[type=radio]') && e.name) {
        const k = `${[...document.forms].indexOf(e.form)}:${e.name}`;
        if (!grupy.has(k)) grupy.set(k, []);
        grupy.get(k).push(e);
      }
    });
    const pomin = new Set([...grupy.values()].flatMap(g => g.filter(e => e !== (g.find(r => r.checked) || g[0]))));
    return { ids: wszystkie.filter(e => !pomin.has(e) && e.closest('main,nav.side-nav[data-tryb-panelu]')).map(e => e.dataset.panelPomiar), limit: wszystkie.length * 2 + 20 };
  });
  const odwiedzone = new Set();
  let grupyRadio = 0;
  const ustalenieFokusu = [];
  for (let i = 0; i < cel.limit && odwiedzone.size < cel.ids.length; i++) {
    await page.keyboard.press('Tab');
    await page.evaluate(() => new Promise(requestAnimationFrame));
    const r = await ustalonyFokus(page);
    if (!r || !cel.ids.includes(r.id)) continue;
    sprawdzFokus(r);
    ustalenieFokusu.push({ id: r.id, klatki: r.klatki, wejscie: 'Tab' });
    odwiedzone.add(r.id);
    const radio = await page.evaluate(() => {
      const e = document.activeElement;
      if (!e.matches('input[type=radio]') || !e.name) return null;
      return [...document.querySelectorAll('input[type=radio]')].filter(n => n.name === e.name && n.form === e.form && !n.disabled && n.getClientRects().length && getComputedStyle(n).visibility === 'visible' && n.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })).map(n => n.dataset.panelPomiar);
    });
    if (radio?.length > 1) {
      const seen = new Set([r.id]);
      for (let j = 0; j < radio.length; j++) {
        await page.keyboard.press('ArrowRight');
        await page.evaluate(() => new Promise(requestAnimationFrame));
        const a = await ustalonyFokus(page);
        wymagaj(a && radio.includes(a.id) && a.checked, 'RADIO_STRZALKI', a);
        sprawdzFokus(a);
        ustalenieFokusu.push({ id: a.id, klatki: a.klatki, wejscie: 'ArrowRight' });
        seen.add(a.id);
      }
      wymagaj(seen.size === radio.length, 'RADIO_NIEPELNE');
      grupyRadio++;
    }
  }
  wymagaj(cel.ids.length > 0 && odwiedzone.size === cel.ids.length, 'TAB_NIEPELNY', { oczekiwane: cel.ids.length, odwiedzone: odwiedzone.size, brak: cel.ids.filter(i => !odwiedzone.has(i)) });
  return { oczekiwane: cel.ids.length, odwiedzone: odwiedzone.size, grupyRadio, ustalenieFokusu, zakres: 'Początkowo widoczne kontrolki main i nawigacji; jedno wejście Tab na grupę radio i pełny cykl strzałek. Widoczny fragment celu z obrysem CSS, nie raster wszystkich krawędzi. Bez otwierania zamkniętych details, odbioru nowo ujawnionych pól i wysyłania formularzy.' };
}

export async function sprawdzCzytelnoscNawigacjiPanelu(page) {
  const podzieloneSlowa = await page.evaluate(() => {
    const bledy = [];
    for (const label of document.querySelectorAll('.marka-panel-nav-etykieta')) {
      const walker = document.createTreeWalker(label, NodeFilter.SHOW_TEXT);
      for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        for (const match of node.textContent.matchAll(/\S+/gu)) {
          const range = document.createRange();
          range.setStart(node, match.index);
          range.setEnd(node, match.index + match[0].length);
          const lines = [...range.getClientRects()].filter(r => r.width > 0 && r.height > 0);
          if (lines.some(r => Math.abs(r.top - lines[0].top) > 1)) bledy.push({ etykieta: label.textContent.trim(), slowo: match[0] });
        }
      }
    }
    return bledy;
  });
  wymagaj(podzieloneSlowa.length === 0, 'ROZCIETE_SLOWO_NAWIGACJI', podzieloneSlowa);
}

export async function sprawdzZwijaniePanelu(page) {
  const button = page.locator('[data-panel-menu-przelacznik]');
  const content = page.locator('[data-panel-menu-tresc]');
  const desktop = await page.evaluate(() => matchMedia('(min-width:64rem)').matches);
  wymagaj(await button.count() === 1 && await content.count() === 1, 'MENU_JEDNA_LISTA');
  if (desktop) {
    wymagaj(!await button.isVisible() && await content.isVisible(), 'MENU_DESKTOP');
    return;
  }
  wymagaj(await button.isVisible() && !await content.isVisible(), 'MENU_MOBILE_ZWINIETE');
  wymagaj(await button.getAttribute('aria-expanded') === 'false', 'MENU_ARIA_ZWINIETE');
  wymagaj(await button.getAttribute('aria-controls') === await content.getAttribute('id'), 'MENU_ARIA_CEL');
  const przed = await page.locator('main h1').first().evaluate(e => e.getBoundingClientRect().top + scrollY);
  await button.click();
  wymagaj(await content.isVisible() && await button.getAttribute('aria-expanded') === 'true', 'MENU_OTWARCIE');
  const po = await page.locator('main h1').first().evaluate(e => e.getBoundingClientRect().top + scrollY);
  wymagaj(po - przed > 100, 'MENU_ODZYSKANE_MIEJSCE', { przed, po });
  await button.press('Tab');
  wymagaj(await content.evaluate(e => e.contains(document.activeElement)), 'MENU_TAB_WEJSCIE');
  await page.keyboard.press('Escape');
  wymagaj(!await content.isVisible() && await button.evaluate(e => e === document.activeElement), 'MENU_ESCAPE_FOKUS');
  await button.press('Enter');
  wymagaj(await content.isVisible(), 'MENU_ENTER');
  // Dalszy historyczny pomiar nadal obejmuje WSZYSTKIE linki rozwiniętej listy.
}

export async function sprawdzDodatkoweStanyMenu({ browser, adres, sesja, outputDir }) {
  wymagaj(['localhost', '127.0.0.1', '[::1]'].includes(new URL(adres).hostname), 'MENU_TYLKO_LOKALNIE');
  const wyniki = [];
  for (const dark of [false, true]) for (const scale of [100, 140]) {
    const context = await browser.newContext({ storageState: sesja, viewport: { width: 320, height: 900 }, reducedMotion: 'reduce' });
    try {
      await context.route('**/*', route => ['GET', 'HEAD'].includes(route.request().method()) ? route.continue() : route.abort());
      const page = await context.newPage();
      await page.goto(`${adres}/admin/zgloszenia`, { waitUntil: 'load' });
      const zmienSzerokosc = async width => {
        await page.setViewportSize({ width, height: 900 });
        // matchMedia aktualizuje menu w zdarzeniu change, po zmianie viewportu.
        await page.evaluate(async () => {
          for (let i = 0; i < 2; i++) await new Promise(requestAnimationFrame);
        });
      };
      await page.evaluate(({ dark, scale }) => {
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        document.documentElement.dataset.textScale = String(scale);
      }, { dark, scale });
      const button = page.locator('[data-panel-menu-przelacznik]');
      const content = page.locator('[data-panel-menu-tresc]');
      await button.click();
      await button.press('Tab');
      const link = await page.evaluate(() => document.activeElement.getAttribute('href'));
      wymagaj(link && await content.evaluate(e => e.contains(document.activeElement)), 'MENU_RESIZE_LINK');
      await zmienSzerokosc(1440);
      wymagaj(await content.isVisible() && !await button.isVisible(), 'MENU_RESIZE_DESKTOP');
      wymagaj(await page.evaluate(() => document.activeElement.getAttribute('href')) === link, 'MENU_RESIZE_ZACHOWAJ_FOKUS');
      await zmienSzerokosc(320);
      wymagaj(await content.isVisible() && await page.evaluate(() => document.activeElement.getAttribute('href')) === link, 'MENU_RESIZE_MOBILE_FOKUS');
      await page.keyboard.press('Escape');
      wymagaj(!await content.isVisible() && await button.evaluate(e => e === document.activeElement), 'MENU_RESIZE_ESCAPE');
      await zmienSzerokosc(1440);
      wymagaj(await page.locator('.side-nav-powrot').evaluate(e => e === document.activeElement), 'MENU_RESIZE_PRZELACZNIK_FOKUS');
      await zmienSzerokosc(320);
      wymagaj(!await content.isVisible(), 'MENU_RESIZE_PONOWNE_ZWINIECIE');
      await page.screenshot({ path: resolve(outputDir, `menu-zwiniete-${dark ? 'dark' : 'light'}-${scale}.png`), fullPage: false });
      wyniki.push({ stan: 'resize', dark, scale, pass: true });
    } finally { await context.close(); }
  }
  for (const width of [320, 1440]) {
    const context = await browser.newContext({ storageState: sesja, viewport: { width, height: 900 }, javaScriptEnabled: false });
    try {
      const page = await context.newPage();
      const response = await page.goto(`${adres}/admin/zgloszenia`, { waitUntil: 'load' });
      wymagaj(response.status() === 200, 'MENU_BEZ_JS_HTTP');
      wymagaj(!await page.locator('[data-panel-menu-przelacznik]').isVisible(), 'MENU_BEZ_JS_PRZYCISK');
      const links = page.locator('.side-nav-moderacja-lista a');
      wymagaj(await links.count() === 10, 'MENU_BEZ_JS_KOMPLET');
      for (const link of await links.all()) wymagaj(await link.isVisible(), 'MENU_BEZ_JS_LINK');
      await page.locator('.side-nav-moderacja-lista a').filter({ hasText: 'Tagi promowane' }).click();
      wymagaj(new URL(page.url()).pathname === '/admin/tagi-promowane', 'MENU_BEZ_JS_NAWIGACJA');
      wyniki.push({ stan: 'bez-js', width, pass: true });
    } finally { await context.close(); }
  }
  const touch = await browser.newContext({ storageState: sesja, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  try {
    const page = await touch.newPage();
    await page.goto(`${adres}/admin/zgloszenia`, { waitUntil: 'load' });
    const button = page.locator('[data-panel-menu-przelacznik]');
    const content = page.locator('[data-panel-menu-tresc]');
    await button.tap();
    wymagaj(await content.isVisible(), 'MENU_DOTYK_OTWORZ');
    await button.tap();
    wymagaj(!await content.isVisible(), 'MENU_DOTYK_ZAMKNIJ');
    wyniki.push({ stan: 'dotyk-emulowany', width: 390, pass: true });
    await button.tap();
    await page.evaluate(() => document.dispatchEvent(new Event('livewire:navigating')));
    wymagaj(await content.isVisible() && !await button.isVisible(), 'MENU_CLEANUP');
    await page.evaluate(() => {
      document.dispatchEvent(new Event('livewire:navigated'));
      document.dispatchEvent(new Event('livewire:navigated'));
    });
    wymagaj(!await content.isVisible() && await button.isVisible(), 'MENU_REINIT');
    await button.tap();
    wymagaj(await content.isVisible(), 'MENU_REINIT_JEDEN_LISTENER');
    await button.focus();
    await page.keyboard.press('Escape');
    wymagaj(!await content.isVisible(), 'MENU_REINIT_ESCAPE');
    wyniki.push({ stan: 'livewire-cleanup-reinit', width: 390, pass: true });
  } finally { await touch.close(); }
  return wyniki;
}

export async function sprawdzPanelMarki({ browser, adres, scenariusze, phase, outputDir }) {
  sprawdzKontrakt(adres, scenariusze, phase);
  wymagaj(outputDir, 'KATALOG_DOWODOW');
  const katalog = resolve(outputDir);
  mkdirSync(katalog, { recursive: true });
  const wyniki = [];
  const zapis = () => writeFileSync(resolve(katalog, `wyniki-${phase}.json`), JSON.stringify({ phase, zakres: 'Kompozycja rzeczywistych GET, skala tekstu 100/140, reducedMotion:reduce. Bez POST, testu preferencji, zwykłych przejść i zoomu przeglądarki.', wyniki }, null, 2));
  for (const width of [320, 360, 390, 414, 768, 1440]) for (const dark of [false, true]) for (const scale of [100, 140]) for (const s of scenariusze) {
    const wariant = { id: s.id, rodzina: s.rodzina, stan: s.stan, path: s.path, phase, width, dark, scale };
    const nazwa = `${s.id}-${width}-${dark ? 'dark' : 'light'}-${scale}`;
    const context = await browser.newContext({ storageState: s.sesja, viewport: { width, height: 900 }, reducedMotion: 'reduce' });
    let page;
    const mutacje = [];
    try {
      await context.route('**/*', route => {
        if (!['GET', 'HEAD'].includes(route.request().method())) { mutacje.push(route.request().method()); return route.abort(); }
        return route.continue();
      });
      page = await context.newPage();
      await page.addInitScript(({ dark, scale }) => document.addEventListener('DOMContentLoaded', () => {
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        document.documentElement.dataset.textScale = String(scale);
      }), { dark, scale });
      const response = await page.goto(adres.replace(/\/$/, '') + s.path, { waitUntil: 'load' });
      wymagaj(response?.status() === (s.stan === 'bramka' ? 403 : 200), 'HTTP', wariant);
      await page.evaluate(async () => { await document.fonts.ready; for (let i = 0; i < 2; i++) await new Promise(requestAnimationFrame); });
      for (const e of s.oczekiwaneSelektory) {
        const count = await page.locator(e.selector).count();
        wymagaj(count >= e.min && (e.max === undefined || count <= e.max), 'DANE', { id: s.id, selector: e.selector, count, min: e.min, max: e.max });
      }
      if (s.stan === 'bramka') wymagaj(await page.locator('main .marka-panel-bramka[aria-labelledby="panel-wymaga-2fa"] #panel-wymaga-2fa').count() === 1, 'KARTA_BRAMKI');
      await sprawdzZwijaniePanelu(page);
      const geo = await page.evaluate(() => {
        const box = selector => { const e = document.querySelector(selector); if (!e) return null; const b = e.getBoundingClientRect(), c = getComputedStyle(e); return { x: b.x, y: b.y, right: b.right, bottom: b.bottom, width: b.width, height: b.height, background: c.backgroundColor, color: c.color, radius: parseFloat(c.borderRadius), display: c.display, paddingLeft: parseFloat(c.paddingLeft), paddingRight: parseFloat(c.paddingRight), borderLeft: parseFloat(c.borderLeftWidth), borderRight: parseFloat(c.borderRightWidth), gap: parseFloat(c.columnGap) }; };
        const probe = document.createElement('i');
        probe.style.setProperty('transition', 'none', 'important');
        probe.style.setProperty('animation', 'none', 'important');
        document.body.append(probe);
        const kolor = token => { probe.style.color = `var(${token})`; return getComputedStyle(probe).color; };
        const tokens = { surface: kolor('--color-surface-raised'), active: kolor('--color-brand-tint'), activeInk: kolor('--color-brand-tint-ink') }; probe.remove();
        return { viewport: innerWidth, scroll: document.documentElement.scrollWidth, font: parseFloat(getComputedStyle(document.body).fontSize), ink: getComputedStyle(document.body).color, desktop: matchMedia('(min-width:64rem)').matches, tokens, body: box('.app-body[data-marka-panel]'), nav: box('nav.side-nav[data-tryb-panelu]'), main: box('main'), content: box('main .marka-panel-tresc'), header: box('.marka-panel-naglowek'), active: box('nav.side-nav [aria-current=page]') };
      });
      wymagaj(geo.body && geo.header && geo.content && geo.nav, 'KOMPOZYCJA', geo);
      wymagaj(geo.scroll <= geo.viewport + 1, 'OVERFLOW', geo);
      wymagaj(Math.abs(geo.font - 18 * scale / 100) < .15 && geo.ink === (dark ? 'rgb(244, 245, 241)' : 'rgb(21, 23, 20)'), 'SKALA_MOTYW', geo);
      wymagaj(geo.nav.width > 0 && geo.nav.height > 0 && geo.nav.background === geo.tokens.surface && Math.abs(geo.nav.radius - 26) < .2, 'KARTA_NAWIGACJI', geo);
      await sprawdzCzytelnoscNawigacjiPanelu(page);
      wymagaj(geo.desktop ? geo.main.x >= geo.nav.right - 1 : geo.main.y >= geo.nav.bottom - 1, 'KOLEJNOSC', geo);
      const lewa = geo.desktop ? geo.nav.right + geo.body.gap : geo.body.x + geo.body.borderLeft + geo.body.paddingLeft;
      const prawa = geo.body.right - geo.body.borderRight - geo.body.paddingRight;
      wymagaj(Math.abs(geo.main.x - lewa) <= 1 && Math.abs(geo.main.right - prawa) <= 1 && geo.main.width > 0, 'SZEROKOSC_TRESCI', geo);
      if (s.stan !== 'bramka') wymagaj(geo.active && geo.active.background === geo.tokens.active && geo.active.color === geo.tokens.activeInk, 'AKTYWNA_POZYCJA', geo);
      const tab = await sprawdzKlawiaturePanelu(page);
      wymagaj(mutacje.length === 0, 'NIEOCZEKIWANA_MUTACJA', { metody: mutacje });
      // Raster początku strony, niezależnie od końcowej pozycji Tab.
      if ([320, 1440].includes(width) && scale === 140) {
        await page.evaluate(() => scrollTo(0, 0));
        await page.screenshot({ path: resolve(katalog, `${nazwa}.png`) });
        // Na telefonie jawna nawigacja poprzedza treść. Osobny kadr main
        // pozwala obejrzeć formularz lub listę zamiast samego spisu narzędzi.
        await page.locator('main h1').first().scrollIntoViewIfNeeded();
        await page.screenshot({ path: resolve(katalog, `${nazwa}-tresc.png`) });
      }
      wyniki.push({ ...wariant, pass: true, geo, tab });
      zapis();
    } catch (error) {
      if (page) await page.screenshot({ path: resolve(katalog, `${nazwa}-FAIL.png`) }).catch(() => {});
      wyniki.push({ ...wariant, pass: false, blad: String(error.message) }); zapis(); throw error;
    } finally { await context.close(); }
  }
  return wyniki;
}
