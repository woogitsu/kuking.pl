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
 * Ekrany. `zalogowany` znaczy „mierz w kontekście z ciasteczkiem sesji";
 * bez tej flagi ekran mierzymy jako GOŚĆ, w osobnym kontekście (#89).
 * `dynamiczny` znaczy „adres złóż w czasie przebiegu" (przepis, wpis).
 * `znak` to selektor, który istnieje wyłącznie na tym ekranie — dowód, że
 * mierzymy zamówiony formularz, a nie stronę, która pod tym samym adresem
 * pokazuje coś innego (`/` dla zalogowanego renderuje tablicę bez
 * przekierowania, więc porównanie ścieżek samo tego nie złapie).
 * Przy „nie pamiętam hasła" znakiem jest nagłówek, nie formularz: formularz
 * pokazuje się tylko przy działającej poczcie (`Poczta::dziala()`), a ekran
 * bez niego to nadal ten sam ekran.
 */
const EKRANY = [
  { nazwa: 'strona powitalna', adres: '/' },
  { nazwa: 'Świeżo z Kuking', adres: '/odkryj' },
  { nazwa: 'Poradźcie (pytania)', adres: '/pytania' },
  { nazwa: 'szukaj (wyniki)', adres: '/szukaj?q=zupa' },
  { nazwa: 'logowanie', adres: '/login', znak: 'form[action$="/login"] input[name="login"]' },
  { nazwa: 'rejestracja', adres: '/register', znak: 'form[action$="/register"]' },
  { nazwa: 'nie pamiętam hasła', adres: '/nie-pamietam-hasla', znak: 'h1:text-is("Nie pamiętam hasła")' },
  { nazwa: 'napisz do nas', adres: '/napisz-do-nas' },
  { nazwa: 'pomoc', adres: '/pomoc' },
  { nazwa: 'zasady', adres: '/zasady' },
  { nazwa: 'przepis', dynamiczny: 'przepis' },
  { nazwa: 'tryb gotowania', dynamiczny: 'gotowanie' },
  { nazwa: 'wpis', dynamiczny: 'wpis' },
  { nazwa: 'profil (cudzy)', adres: '/@basia' },
  // Te same ekrany publiczne PO ZALOGOWANIU: bloki `@auth` („Ugotowałem",
  // zeszyt, komentarz, obserwuj) istnieją tylko dla zalogowanego, a główna
  // akcja produktu nie może wypaść z pomiaru ≥48 px / 18 px (#89).
  { nazwa: 'Świeżo z Kuking (zalogowany)', adres: '/odkryj', zalogowany: true },
  { nazwa: 'Poradźcie (zalogowany)', adres: '/pytania', zalogowany: true },
  { nazwa: 'przepis (zalogowany)', dynamiczny: 'przepis', zalogowany: true },
  { nazwa: 'tryb gotowania (zalogowany)', dynamiczny: 'gotowanie', zalogowany: true },
  { nazwa: 'wpis (zalogowany)', dynamiczny: 'wpis', zalogowany: true },
  { nazwa: 'profil (cudzy, zalogowany)', adres: '/@basia', zalogowany: true },
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
  const przekierowania = [];
  let przebiegi = 0;

  for (const szerokosc of SZEROKOSCI) {
    for (const motyw of MOTYWY) {
      for (const skala of SKALE) {
        /*
         * DWA KONTEKSTY, JEDNO LOGOWANIE (#89). Wspólny kontekst z sesją
         * mierzył `/login` i `/register` jako zalogowany — trasy `guest`
         * odsyłały na `/home`, a wynik dostawał etykietę formularza. Oba
         * konteksty biorą gotowy stan, więc limit pięciu logowań na minutę
         * zostaje nietknięty bez względu na rozmiar macierzy.
         */
        const ustawienia = { viewport: { width: szerokosc, height: 900 }, deviceScaleFactor: 1 };
        const kontekstGoscia = await przegladarka.newContext(ustawienia);
        const kontekstZalogowanego = await przegladarka.newContext({ ...ustawienia, storageState: stan });
        const stronaGoscia = await kontekstGoscia.newPage();
        const stronaZalogowanego = await kontekstZalogowanego.newPage();

        for (const ekran of EKRANY) {
          const adres = ekran.dynamiczny ? dyn[ekran.dynamiczny] : ekran.adres;
          if (!adres) continue;
          const strona = ekran.zalogowany ? stronaZalogowanego : stronaGoscia;
          try {
            const odp = await strona.goto(ADRES + adres, {
              waitUntil: 'networkidle',
              timeout: 30000,
            });
            if (!odp || odp.status() >= 400) {
              wyniki.push({ ekran: ekran.nazwa, adres, szerokosc, motyw, skala, blad: `HTTP ${odp && odp.status()}` });
              continue;
            }
            /*
             * Przekierowanie to BŁĄD, nie pomiar innej strony pod cudzą
             * etykietą. Kod 200 przychodzi już z celu przekierowania, więc
             * sprawdzenie statusu wyżej tego nie widzi.
             */
            const zamowiona = new URL(ADRES + adres).pathname;
            const otrzymana = new URL(strona.url()).pathname;
            const bezZnaku = ekran.znak && !(await strona.$(ekran.znak));
            if (otrzymana !== zamowiona || bezZnaku) {
              const blad = otrzymana !== zamowiona
                ? `odesłał na ${otrzymana}`
                : `brak znaku ekranu (${ekran.znak})`;
              przekierowania.push({ ekran: ekran.nazwa, zamowiona, otrzymana, szerokosc, motyw, skala, blad });
              wyniki.push({ ekran: ekran.nazwa, adres, szerokosc, motyw, skala, blad });
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
        await kontekstGoscia.close();
        await kontekstZalogowanego.close();
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
    przekierowania,
    wyniki,
  }, null, 1));
  console.log(`Gotowe: ${wyniki.length} pomiarów → storage/audyt-ux50plus.json`);

  if (przekierowania.length > 0) {
    const ekrany = new Map();
    for (const p of przekierowania) ekrany.set(p.ekran, p);
    for (const p of ekrany.values()) {
      console.error(
        `BŁĄD: ekran „${p.ekran}" (${p.zamowiona}) — ${p.blad}. `
        + 'Pomiar dotyczyłby innej strony niż zamówiona. Sprawdź flagę `zalogowany` '
        + 'tego ekranu albo trasę w routes/web.php.',
      );
    }
    process.exitCode = 1;
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
