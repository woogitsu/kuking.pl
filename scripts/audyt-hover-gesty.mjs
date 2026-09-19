/*
 * =============================================================================
 *  Kuking.pl — czy JAKAKOLWIEK akcja jest dostępna WYŁĄCZNIE przez najechanie
 *  myszą albo gest (AGENTS.md §5: „żadna ważna funkcja nie wymaga hover,
 *  swipe, long-press ani gestu od krawędzi")
 * =============================================================================
 *
 *  DWA POMIARY, BO TO DWA RÓŻNE RYZYKA
 *
 *  1. HOVER. Najpierw CSSOM: szukamy reguł z `:hover`, które ODSŁANIAJĄ coś
 *     (`display`, `visibility`, `opacity`, `pointer-events`, `max-height`,
 *     `transform`). Kandydat to za mało — regułę trzeba SPRAWDZIĆ na żywo:
 *     najeżdżamy na element i patrzymy, czy pojawia się interaktywny potomek,
 *     którego przed najechaniem nie było widać. Sama obecność `:hover`
 *     w arkuszu nic nie znaczy — zmiana koloru przycisku jest w porządku.
 *
 *  2. GEST. Nasłuchy `touchstart`/`touchmove`/`pointerdown` rejestrowane
 *     z JavaScriptu. Przechwytujemy `addEventListener` PRZED wczytaniem
 *     strony (`addInitScript`), bo po fakcie nie da się ich odczytać.
 *     Sam nasłuch to jeszcze nie usterka — usterką jest nasłuch gestu tam,
 *     gdzie nie ma widocznego przycisku robiącego to samo. Dlatego obok
 *     nasłuchu notujemy, czy w tym samym kontenerze są widoczne przyciski.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

const ADRES = process.env.ADRES || 'http://127.0.0.1:8137';
const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

const EKRANY = [
  { nazwa: 'strona powitalna', adres: '/' },
  { nazwa: 'Świeżo z Kuking', adres: '/odkryj' },
  { nazwa: 'szukaj (wyniki)', adres: '/szukaj?q=zupa' },
  { nazwa: 'logowanie', adres: '/login' },
  { nazwa: 'rejestracja', adres: '/register' },
  { nazwa: 'przepis', adres: process.env.PRZEPIS || '/przepisy/rosol-babci-zofii' },
  { nazwa: 'tryb gotowania', adres: `${process.env.PRZEPIS || '/przepisy/rosol-babci-zofii'}/gotuj` },
  { nazwa: 'profil (cudzy)', adres: '/@basia' },
  { nazwa: 'tablica startowa', adres: '/home', zalogowany: true },
  { nazwa: 'dodaj zdjęcie', adres: '/dodaj/zdjecie', zalogowany: true },
  { nazwa: 'dodaj przepis', adres: '/dodaj/przepis', zalogowany: true },
  { nazwa: 'powiadomienia', adres: '/powiadomienia', zalogowany: true },
  { nazwa: 'zeszyt', adres: '/zeszyt', zalogowany: true },
  { nazwa: 'profil (własny)', adres: `/@${KONTO}`, zalogowany: true },
  { nazwa: 'ustawienia', adres: '/ustawienia', zalogowany: true },
];

const PODGLAD_NASLUCHOW = () => {
  window.__kukingNasluchy = [];
  const oryginal = EventTarget.prototype.addEventListener;
  const GESTY = new Set(['touchstart', 'touchmove', 'touchend', 'pointerdown',
    'pointermove', 'gesturestart', 'swipe', 'contextmenu']);
  EventTarget.prototype.addEventListener = function (typ, ...reszta) {
    if (GESTY.has(typ)) {
      let opis = '(nie element)';
      try {
        if (this instanceof Element) {
          opis = this.tagName.toLowerCase()
            + (this.id ? `#${this.id}` : '')
            + (this.classList.length ? `.${[...this.classList].slice(0, 3).join('.')}` : '');
        } else if (this === window) opis = 'window';
        else if (this === document) opis = 'document';
      } catch { /* pusty */ }
      window.__kukingNasluchy.push({ typ, cel: opis });
    }
    return oryginal.call(this, typ, ...reszta);
  };
};

const SZUKAJ_HOVERA = () => {
  const REWELATORY = ['display', 'visibility', 'opacity', 'pointer-events',
    'max-height', 'height', 'transform', 'width', 'clip-path'];
  const kandydaci = [];
  for (const arkusz of document.styleSheets) {
    let reguly;
    try { reguly = arkusz.cssRules; } catch { continue; }
    if (!reguly) continue;
    const przejdz = (lista) => {
      for (const r of lista) {
        if (r.cssRules) { przejdz(r.cssRules); continue; }
        if (!r.selectorText || !r.selectorText.includes(':hover')) continue;
        const wlasciwosci = [...(r.style || [])].filter((p) => REWELATORY.includes(p));
        if (!wlasciwosci.length) continue;
        kandydaci.push({
          selektor: r.selectorText,
          wlasciwosci: wlasciwosci.map((p) => `${p}: ${r.style.getPropertyValue(p)}`),
        });
      }
    };
    przejdz(reguly);
  }
  return kandydaci;
};

const INTERAKTYWNE_UKRYTE = () => {
  const SEL = 'a[href], button, summary, input:not([type=hidden]), select, textarea, [role="button"]';
  const widoczny = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return false;
    const s = getComputedStyle(el);
    return s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) > 0.05;
  };
  const wynik = [];
  document.querySelectorAll(SEL).forEach((el, i) => {
    if (widoczny(el)) return;
    el.setAttribute('data-kuking-ukryty', String(i));
    wynik.push({
      indeks: i,
      typ: el.tagName.toLowerCase(),
      nazwa: (el.getAttribute('aria-label') || el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 50),
      // `hidden`/`aria-expanded` to świadome schowanie w panelu, nie hover.
      wSchowanym: !!el.closest('[hidden], details:not([open]), dialog:not([open])'),
    });
  });
  return wynik;
};

async function stanZalogowanego(przegladarka) {
  const kontekst = await przegladarka.newContext();
  const strona = await kontekst.newPage();
  await strona.goto(`${ADRES}/login`);
  await strona.fill('input[name="login"]', KONTO);
  await strona.fill('input[name="password"]', HASLO);
  await Promise.all([
    strona.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }),
    strona.click('button[type="submit"]'),
  ]);
  const stan = await kontekst.storageState();
  await kontekst.close();
  return stan;
}

async function main() {
  const przegladarka = await chromium.launch();
  const stan = await stanZalogowanego(przegladarka);
  const kontekst = await przegladarka.newContext({
    viewport: { width: 390, height: 844 },
    storageState: stan,
  });
  await kontekst.addInitScript(PODGLAD_NASLUCHOW);
  const strona = await kontekst.newPage();

  const raport = [];
  for (const ekran of EKRANY) {
    try {
      const odp = await strona.goto(ADRES + ekran.adres, { waitUntil: 'networkidle', timeout: 30000 });
      if (!odp || odp.status() >= 400) {
        raport.push({ ekran: ekran.nazwa, blad: `HTTP ${odp && odp.status()}` });
        continue;
      }
      const kandydaci = await strona.evaluate(SZUKAJ_HOVERA);
      const nasluchy = await strona.evaluate(() => window.__kukingNasluchy || []);
      const ukryte = await strona.evaluate(INTERAKTYWNE_UKRYTE);

      /*
       * SPRAWDZENIE NA ŻYWO. Dla każdego kandydata z arkusza bierzemy jego
       * postać BEZ `:hover`, najeżdżamy na pierwszy pasujący element i
       * patrzymy, czy przybyło widocznych elementów interaktywnych.
       */
      const potwierdzone = [];
      for (const k of kandydaci) {
        const bazowy = k.selektor.split(',')[0].replace(/:hover/g, '').trim();
        if (!bazowy) continue;
        let element;
        try { element = await strona.$(bazowy); } catch { continue; }
        if (!element) continue;
        const przed = await strona.evaluate(INTERAKTYWNE_UKRYTE);
        try { await element.hover({ timeout: 2000 }); } catch { continue; }
        await strona.waitForTimeout(150);
        const po = await strona.evaluate(INTERAKTYWNE_UKRYTE);
        const odslonieci = przed.filter((p) => !po.some((q) => q.indeks === p.indeks));
        if (odslonieci.length) {
          potwierdzone.push({ selektor: k.selektor, wlasciwosci: k.wlasciwosci, odslonieci });
        }
        await strona.mouse.move(0, 0);
      }

      raport.push({
        ekran: ekran.nazwa,
        adres: ekran.adres,
        kandydatowHover: kandydaci.length,
        hoverOdslaniaAkcje: potwierdzone,
        nasluchyGestow: nasluchy,
        ukryteInteraktywne: ukryte.length,
        ukryteSpozaPanelu: ukryte.filter((u) => !u.wSchowanym),
      });
    } catch (e) {
      raport.push({ ekran: ekran.nazwa, blad: String(e.message).slice(0, 200) });
    }
  }

  await przegladarka.close();
  mkdirSync('storage', { recursive: true });
  writeFileSync('storage/audyt-hover-gesty.json', JSON.stringify(raport, null, 1));

  for (const r of raport) {
    if (r.blad) { console.log(`${r.ekran}: BŁĄD ${r.blad}`); continue; }
    const h = r.hoverOdslaniaAkcje.length;
    const g = r.nasluchyGestow.length;
    console.log(`${r.ekran.padEnd(24)} hover-odsłania=${h}  nasłuchów-gestu=${g}  `
      + `ukrytych-interaktywnych-poza-panelem=${r.ukryteSpozaPanelu.length}`);
    for (const p of r.hoverOdslaniaAkcje) console.log(`    ! ${p.selektor} → ${p.odslonieci.map((o) => o.nazwa || o.typ).join(', ')}`);
    for (const n of r.nasluchyGestow) console.log(`    ~ ${n.typ} na ${n.cel}`);
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
