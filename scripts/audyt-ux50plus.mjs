/*
 * =============================================================================
 *  Kuking.pl — POMIAR twardych reguł UX 50+ (AGENTS.md §5)
 * =============================================================================
 *
 *  Ten skrypt NIE czyta klas CSS. Czyta `getBoundingClientRect()`
 *  i `getComputedStyle()` na UŁOŻONEJ stronie. Klasa może być martwa —
 *  18 września znaleziono cztery widoki z klasą `lead`, której nie było
 *  w arkuszu. Pomiar klasy nie odróżnia tych dwóch stanów; pomiar piksela tak.
 *
 *  CO MIERZY
 *   1. rozmiar pisma po wyrenderowaniu (reguła: tekst podstawowy ≥ 18 px),
 *   2. wysokość i szerokość celów dotknięcia (reguła: ważne przyciski ≥ 48 px),
 *   3. przewijanie w poziomie (`scrollWidth` > `innerWidth`),
 *   4. czy element NIE reaguje na ustawienie „powiększ tekst" (ten sam piksel
 *      przy 100% i przy 140% — dla naszej grupy to osobna usterka).
 *
 *  MACIERZ
 *   szerokości 320/360/390/414/768/1440 × motyw jasny/ciemny × tekst 100/140%.
 *
 *  URUCHOMIENIE
 *   ADRES=http://127.0.0.1:8137 node scripts/audyt-ux50plus.mjs
 *   ADRES=... node scripts/audyt-ux50plus.mjs --szybko   (mniejsza macierz)
 * =============================================================================
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

const ADRES = process.env.ADRES || 'http://127.0.0.1:8137';
const SZYBKO = process.argv.includes('--szybko');
const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

const SZEROKOSCI = SZYBKO ? [320, 390] : [320, 360, 390, 414, 768, 1440];
const MOTYWY = SZYBKO ? ['light'] : ['light', 'dark'];
const SKALE = SZYBKO ? [100] : [100, 140];

/** Reguły z AGENTS.md §5 — progi w pikselach CSS. */
const PROG_TEKSTU = 18;
const PROG_CELU = 48;

/**
 * Ekrany. `zalogowany` znaczy „mierz w kontekście z ciasteczkiem sesji".
 * `dynamiczny` znaczy „adres złóż w czasie przebiegu" (przepis, wpis).
 */
const EKRANY = [
  { nazwa: 'strona powitalna', adres: '/' },
  { nazwa: 'Świeżo z Kuking', adres: '/odkryj' },
  { nazwa: 'Poradźcie (pytania)', adres: '/pytania' },
  { nazwa: 'szukaj (wyniki)', adres: '/szukaj?q=zupa' },
  { nazwa: 'logowanie', adres: '/login' },
  { nazwa: 'rejestracja', adres: '/register' },
  { nazwa: 'nie pamiętam hasła', adres: '/nie-pamietam-hasla' },
  { nazwa: 'napisz do nas', adres: '/napisz-do-nas' },
  { nazwa: 'pomoc', adres: '/pomoc' },
  { nazwa: 'zasady', adres: '/zasady' },
  { nazwa: 'przepis', dynamiczny: 'przepis' },
  { nazwa: 'tryb gotowania', dynamiczny: 'gotowanie' },
  { nazwa: 'wpis', dynamiczny: 'wpis' },
  { nazwa: 'profil (cudzy)', adres: '/@basia' },
  { nazwa: 'tablica startowa', adres: '/home', zalogowany: true },
  { nazwa: 'dodaj (rozdroże)', adres: '/dodaj', zalogowany: true },
  { nazwa: 'dodaj zdjęcie', adres: '/dodaj/zdjecie', zalogowany: true },
  { nazwa: 'dodaj przepis', adres: '/dodaj/przepis', zalogowany: true },
  { nazwa: 'zadaj pytanie', adres: '/pytania/zadaj', zalogowany: true },
  { nazwa: 'powiadomienia', adres: '/powiadomienia', zalogowany: true },
  { nazwa: 'zeszyt', adres: '/zeszyt', zalogowany: true },
  { nazwa: 'profil (własny)', adres: `/@${KONTO}`, zalogowany: true },
  { nazwa: 'ustawienia', adres: '/ustawienia', zalogowany: true },
  { nazwa: 'ustawienia — profil', adres: '/ustawienia/profil', zalogowany: true },
  { nazwa: 'ustawienia — czytelność', adres: '/ustawienia/czytelnosc', zalogowany: true },
];

/*
 * Funkcja wykonywana W PRZEGLĄDARCE. Zwraca surowe liczby, bez ocen —
 * ocenianie zostaje po stronie Node, żeby próg dało się zmienić w jednym
 * miejscu i żeby dane w pliku dało się przeliczyć ponownie.
 */
const ZMIERZ = ({ progTekstu, progCelu }) => {
  const widoczny = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return false;
    const s = getComputedStyle(el);
    if (s.visibility === 'hidden' || s.display === 'none') return false;
    if (Number(s.opacity) === 0) return false;
    return true;
  };

  const sciezka = (el) => {
    const czesci = [];
    let n = el;
    for (let i = 0; n && i < 4; i += 1) {
      let c = n.tagName.toLowerCase();
      if (n.id) c += `#${n.id}`;
      else if (n.classList.length) c += `.${[...n.classList].slice(0, 3).join('.')}`;
      czesci.unshift(c);
      n = n.parentElement;
    }
    return czesci.join(' > ');
  };

  const tekstWlasny = (el) => [...el.childNodes]
    .filter((w) => w.nodeType === 3)
    .map((w) => w.textContent.trim())
    .join(' ')
    .replace(/\s+/g, ' ')
    .trim();

  // ---- 1. rozmiar pisma ---------------------------------------------------
  const tekst = [];
  for (const el of document.querySelectorAll('body *')) {
    const t = tekstWlasny(el);
    if (!t || !widoczny(el)) continue;
    const s = getComputedStyle(el);
    tekst.push({
      sciezka: sciezka(el),
      px: Math.round(parseFloat(s.fontSize) * 100) / 100,
      waga: s.fontWeight,
      probka: t.slice(0, 60),
    });
  }

  // ---- 2. cele dotknięcia -------------------------------------------------
  const SELEKTOR = 'a[href], button, summary, select, [role="button"], '
    + 'input:not([type="hidden"]), textarea, [tabindex]:not([tabindex="-1"])';
  const cele = [];
  for (const el of document.querySelectorAll(SELEKTOR)) {
    if (!widoczny(el)) continue;
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    // Odnośnik w akapicie to nie jest „ważny przycisk" — trzymamy to
    // rozróżnienie w danych, a nie w progu, żeby dało się je zakwestionować.
    const wAkapicie = !!el.closest('p, li p, .tresc-wpisu, .prose');
    const typ = el.tagName.toLowerCase()
      + (el.getAttribute('type') ? `[type=${el.getAttribute('type')}]` : '');
    cele.push({
      sciezka: sciezka(el),
      typ,
      w: Math.round(r.width * 10) / 10,
      h: Math.round(r.height * 10) / 10,
      px: Math.round(parseFloat(s.fontSize) * 100) / 100,
      wAkapicie,
      nazwa: (el.getAttribute('aria-label') || el.innerText || el.value || '')
        .replace(/\s+/g, ' ').trim().slice(0, 50),
    });
  }

  // ---- 3. przewijanie w poziomie -----------------------------------------
  const doc = document.documentElement;
  const przepelnienie = {
    scrollWidth: doc.scrollWidth,
    clientWidth: doc.clientWidth,
    innerWidth: window.innerWidth,
    winowajcy: [],
  };
  if (doc.scrollWidth > doc.clientWidth + 1) {
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      if (r.right > doc.clientWidth + 1 || r.left < -1) {
        przepelnienie.winowajcy.push({
          sciezka: sciezka(el),
          left: Math.round(r.left),
          right: Math.round(r.right),
          szerokosc: Math.round(r.width),
          probka: (el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 40),
        });
      }
    }
    // Same liście wystarczą do wskazania winnego; rodziców przycinamy.
    przepelnienie.winowajcy = przepelnienie.winowajcy.slice(0, 12);
  }

  return {
    tekst,
    cele,
    przepelnienie,
    bodyPx: Math.round(parseFloat(getComputedStyle(document.body).fontSize) * 100) / 100,
    skalaZmienna: getComputedStyle(doc).getPropertyValue('--user-text-scale').trim(),
    progTekstu,
    progCelu,
  };
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

/** Adresy przepisu i wpisu bierzemy ze strony, a nie z założenia o seederze. */
async function adresyDynamiczne(przegladarka) {
  const kontekst = await przegladarka.newContext();
  const strona = await kontekst.newPage();
  const zbierzZe = async (adres, wzor) => {
    await strona.goto(ADRES + adres, { waitUntil: 'domcontentloaded' });
    return strona.evaluate((w) => [...document.querySelectorAll('a[href]')]
      .map((a) => new URL(a.href, location.origin).pathname)
      .find((p) => new RegExp(w).test(p)), wzor.source);
  };

  const znalezione = {
    przepis: process.env.PRZEPIS
      || await zbierzZe('/odkryj', /^\/przepisy\/[^/]+$/)
      || await zbierzZe('/szukaj?q=zupa', /^\/przepisy\/[^/]+$/)
      || await zbierzZe('/@basia', /^\/przepisy\/[^/]+$/),
    wpis: await zbierzZe('/odkryj', /^\/wpisy\/[^/]+$/),
  };
  znalezione.gotowanie = znalezione.przepis ? `${znalezione.przepis}/gotuj` : undefined;
  await kontekst.close();
  return znalezione;
}

/*
 * Skala tekstu: atrybut na korzeniu, a potem CZEKANIE NA UŁOŻONĄ STRONĘ.
 * Sam `setAttribute` nie przelicza układu natychmiast — pomiar zaraz po nim
 * potrafi zobaczyć stan sprzed skalowania (ta sama pułapka, którą opisuje
 * nagłówek `wlaczSkaleTekstu` w scripts/dostepnosc.mjs).
 */
async function ustawSkale(strona, skala) {
  await strona.evaluate((s) => {
    document.documentElement.setAttribute('data-text-scale', String(s));
  }, skala);
  const oczekiwana = 1.125 * 16 * (skala / 100);
  try {
    await strona.waitForFunction(
      (cel) => Math.abs(parseFloat(getComputedStyle(document.body).fontSize) - cel) < 0.6,
      oczekiwana,
      { timeout: 5000 },
    );
    return true;
  } catch {
    return false;
  }
}

async function main() {
  const przegladarka = await chromium.launch();
  const stan = await stanZalogowanego(przegladarka);
  const dyn = await adresyDynamiczne(przegladarka);
  console.log(`Adresy dynamiczne: przepis=${dyn.przepis} wpis=${dyn.wpis}`);

  const wyniki = [];
  let przebiegi = 0;

  for (const szerokosc of SZEROKOSCI) {
    for (const motyw of MOTYWY) {
      for (const skala of SKALE) {
        const kontekst = await przegladarka.newContext({
          viewport: { width: szerokosc, height: 900 },
          storageState: stan,
          deviceScaleFactor: 1,
        });
        const strona = await kontekst.newPage();

        for (const ekran of EKRANY) {
          const adres = ekran.dynamiczny ? dyn[ekran.dynamiczny] : ekran.adres;
          if (!adres) continue;
          try {
            const odp = await strona.goto(ADRES + adres, {
              waitUntil: 'networkidle',
              timeout: 30000,
            });
            if (!odp || odp.status() >= 400) {
              wyniki.push({ ekran: ekran.nazwa, adres, szerokosc, motyw, skala, blad: `HTTP ${odp && odp.status()}` });
              continue;
            }
            await strona.evaluate((m) => {
              document.documentElement.setAttribute('data-theme', m);
            }, motyw);
            const skalaOk = await ustawSkale(strona, skala);
            if (!skalaOk) {
              wyniki.push({ ekran: ekran.nazwa, adres, szerokosc, motyw, skala, blad: 'skala tekstu nie weszła w układ' });
              continue;
            }
            await strona.evaluate(() => Promise.race([
              document.fonts.ready,
              new Promise((g) => setTimeout(g, 3000)),
            ]));
            const dane = await strona.evaluate(ZMIERZ, { progTekstu: PROG_TEKSTU, progCelu: PROG_CELU });
            wyniki.push({ ekran: ekran.nazwa, adres, szerokosc, motyw, skala, ...dane });
            przebiegi += 1;
          } catch (e) {
            wyniki.push({ ekran: ekran.nazwa, adres, szerokosc, motyw, skala, blad: String(e.message).slice(0, 200) });
          }
        }
        await kontekst.close();
        console.log(`… ${szerokosc}px / ${motyw} / ${skala}% — zmierzone (${przebiegi} ekranów łącznie)`);
      }
    }
  }

  await przegladarka.close();
  mkdirSync('storage', { recursive: true });
  writeFileSync('storage/audyt-ux50plus.json', JSON.stringify({
    kiedy: new Date().toISOString(),
    adres: ADRES,
    macierz: { SZEROKOSCI, MOTYWY, SKALE },
    progi: { PROG_TEKSTU, PROG_CELU },
    wyniki,
  }, null, 1));
  console.log(`Gotowe: ${wyniki.length} pomiarów → storage/audyt-ux50plus.json`);
}

main().catch((e) => { console.error(e); process.exit(1); });
