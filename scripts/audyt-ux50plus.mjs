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
 *  ODBIÓR FORMY ZWRACANIA SIĘ (#1753, D-332) — OSOBNY PRZEBIEG Z WYROKIEM
 *   Macierz wyżej tylko MIERZY (raport w storage/audyt-ux50plus.json). Ten
 *   przebieg ORZEKA i ustawia kod wyjścia 1. Ustawia na koncie demo formę
 *   żeńską (przez prawdziwy formularz „Jak mamy do Ciebie pisać?", nie przez
 *   bazę), potem w oknie 320 px, przy tekście 100% i przy czcionce
 *   przeglądarki 150% (dla samego wyboru również 200%; CDP `Page.setFontSizes`, jak `PRZEGLADARKA_200` w
 *   `dostepnosc.mjs`), odwiedza ekrany, na których forma jest WIDOCZNA
 *   (`EKRANY_FORMY`), i wymaga: brak przewijania w poziomie, tekst formy
 *   ≥ 18 px, cele formy ≥ 48 px, nic nie ucięte ani poza oknem. Ekran bez
 *   napisu w formie żeńskiej to BŁĄD, nie zielony pomiar pustej strony.
 *   Po przebiegu forma wraca na neutralną.
 *
 *  URUCHOMIENIE
 *   ADRES=http://127.0.0.1:8137 node scripts/audyt-ux50plus.mjs
 *   ADRES=... node scripts/audyt-ux50plus.mjs --szybko   (mniejsza macierz)
 *   ADRES=... node scripts/audyt-ux50plus.mjs --tylko-forme   (tylko odbiór formy)
 *   ADRES=... node scripts/audyt-ux50plus.mjs --bez-formy     (bez odbioru formy)
 *   DB_DATABASE=<baza serwera z ADRES> ADRES=... node scripts/audyt-ux50plus.mjs
 *     (ekrany paczki S biorą dane z scripts/fixtures/nowe-ekrany-s.php; bez bazy: --bez-paczki-s)
 * =============================================================================
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const ADRES = process.env.ADRES || 'http://127.0.0.1:8137';
const SZYBKO = process.argv.includes('--szybko');
const TYLKO_FORME = process.argv.includes('--tylko-forme');
const BEZ_FORMY = process.argv.includes('--bez-formy');
// Ekrany paczki S wymagają fixture w bazie serwera; test atrapy
// (`fixtures/audyt-ux50plus-konteksty.test.mjs`) przebiega bez bazy.
const BEZ_PACZKI_S = process.argv.includes('--bez-paczki-s');
const TYLKO_EKRANY = process.env.TYLKO_EKRANY ? new RegExp(process.env.TYLKO_EKRANY, 'u') : null;
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
  ...(BEZ_PACZKI_S ? [] : [
  /*
   * PACZKA S (#2393). Adresy i dane z `scripts/fixtures/nowe-ekrany-s.php`
   * (`dynamiczny` = klucz z jego odpowiedzi). `znak` to dowód, że ekran ma
   * treść, a nie pusty stan; `poWejsciu` + `znakPo` — strona, która istnieje
   * tylko jako odpowiedź na POST (klikamy jak człowiek).
   */
  { nazwa: 'spiżarnia — Co mam w domu', adres: '/co-mam-w-domu', zalogowany: true, znak: '#sekcja-pilne' },
  { nazwa: 'spiżarnia — ustaw termin', dynamiczny: 'terminProduktu', zalogowany: true, znak: 'h1:text("Ustaw termin")' },
  { nazwa: 'spiżarnia — najpierw to, co się psuje', adres: '/co-ugotuje?najpierw=termin', zalogowany: true, znak: 'h1:text("Przepisy na produkty z krótkim terminem")' },
  { nazwa: 'sobotni list — wypisanie (pytanie)', dynamiczny: 'linkWypisz', zwolnijLimity: true, znak: 'button:text("Tak, nie wysyłajcie mi go")' },
  { nazwa: 'sobotni list — wypisano', dynamiczny: 'linkWypisz', poWejsciu: 'wypisano', zwolnijLimity: true, znakPo: 'h1:text("Nie wyślemy już")' },
  { nazwa: 'sobotni list — zgoda wróciła', dynamiczny: 'linkWypisz', poWejsciu: 'wrocono', zwolnijLimity: true, znakPo: 'h1:text("Sobotnie przypomnienie przyjdzie")' },
  { nazwa: 'sobotni list — link wygasł', dynamiczny: 'linkWypisz', poWejsciu: 'wygaslo', zwolnijLimity: true, znakPo: 'h1:text("Ten link wygasł")' },
  { nazwa: 'zeszyt do druku', dynamiczny: 'zeszytDoDruku', zalogowany: true, zwolnijLimity: true, znak: '#zeszyt-spis-naglowek' },
  { nazwa: 'karta z kodem QR — przepis', dynamiczny: 'kartaPrzepisu', znak: '.karta-qr-kod svg' },
  { nazwa: 'karta z kodem QR — profil', dynamiczny: 'kartaProfilu', znak: '.karta-qr-kod svg' },
  { nazwa: 'tablica — wspomnienie z wykonania', adres: '/home', zalogowany: true, znak: '.wspomnienie' },
  { nazwa: 'historia wersji przepisu', dynamiczny: 'historia', znak: '.historia-wersja-naglowek' },
  { nazwa: 'historia wersji — jedna wersja', dynamiczny: 'wersja', znak: '#hw-skladniki' },
  { nazwa: 'historia wersji — co się zmieniło', dynamiczny: 'zmiany', znak: '#hz-skladniki' },
  ]),
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
      || await zbierzZe('/szukaj?q=rosol', /^\/przepisy\/[^/]+$/)
      || await zbierzZe('/@basia', /^\/przepisy\/[^/]+$/),
    wpis: await zbierzZe('/odkryj', /^\/wpisy\/[^/]+$/),
  };
  znalezione.gotowanie = znalezione.przepis ? `${znalezione.przepis}/gotuj` : undefined;
  await kontekst.close();
  return BEZ_PACZKI_S ? znalezione : { ...znalezione, ...daneEkranowS() };
}

/**
 * Dane ekranów paczki S z fixture (`DB_DATABASE` musi wskazywać bazę serwera
 * z ADRES, tak samo jak przy `dostepnosc.mjs`). Bez bazy ekrany te wypadają,
 * ale GŁOŚNO: komunikat i kod wyjścia 1 (patrz `main`), nie cicha luka.
 */
let brakDanychS = null;
function daneEkranowS() {
  const uruchom = (...argumenty) => execFileSync('php', ['scripts/fixtures/nowe-ekrany-s.php', ...argumenty],
    { env: process.env, stdio: ['ignore', 'pipe', 'pipe'] }).toString().trim().split('\n').pop();
  try {
    const dane = JSON.parse(uruchom('przygotuj'));
    const link = new URL(uruchom('link', 'wypisz', ADRES));
    dane.linkWypisz = link.pathname + link.search;
    dane.linkWygasly = uruchom('link', 'wygasly', ADRES);
    return dane;
  } catch (e) {
    brakDanychS = String(e.stderr || e.message).slice(0, 300);
    return {};
  }
}

/** Klika drogę do strony, która istnieje tylko jako odpowiedź na POST. */
async function dojdzDoStronyListu(strona, krok, linkWygasly) {
  const kliknij = async (nazwa) => {
    const [odp] = await Promise.all([
      strona.waitForResponse((o) => o.request().method() === 'POST' && o.request().isNavigationRequest()),
      strona.getByRole('button', { name: nazwa }).click(),
    ]);
    await strona.waitForLoadState('domcontentloaded');
    if (odp.status() !== 200) throw new Error(`krok „${nazwa}" odpowiedział kodem ${odp.status()}`);
  };
  await kliknij('Tak, nie wysyłajcie mi go');
  if (krok === 'wypisano') return;
  if (krok === 'wygaslo') {
    await strona.locator('form[action*="/spizarnia/wracam/"]').evaluate((f, nowy) => { f.action = nowy; }, linkWygasly);
  }
  await kliknij('Jednak chcę go dostawać');
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

/* =============================================================================
 *  ODBIÓR FORMY ZWRACANIA SIĘ — 320 px × czcionka przeglądarki 150% (#1753)
 * ============================================================================= */

/** Wartości z `Profile::FORM_FEMININE` / `FORM_NEUTRAL` (pola radio wyboru formy). */
/** Pomoc kontekstowa obok tekstu ≥ 18 px (docs/design/DESIGN_SYSTEM.md §2.1). */
const PROG_POMOCY = 16;
const FORMA_ZENSKA = 'feminine';
const FORMA_NEUTRALNA = 'neutral';
const FORMA_SZEROKOSC = 320;
/** Warianty: sam układ 320 px oraz rozmiar pisma przeglądarki 150%. */
const FORMA_SKALE = [100, 150];
const BAZOWA_CZCIONKA_PX = 16;

/**
 * Ekrany, na których forma jest widoczna po zalogowaniu na konto z formą
 * żeńską. `napis` MUSI stać na ekranie — dowód, że mierzymy stan z formą,
 * a nie stronę, która akurat jej nie pokazuje (np. pusty stan zamiast listy).
 * Ekran bez napisu to błąd, nie pomiar. `wybor` znaczy „stoi formularz
 * wyboru formy (`#forma-zwracania`)".
 */
const EKRANY_FORMY = [
  { nazwa: 'ustawienia — profil (wybór formy)', adres: '/ustawienia/profil', napis: 'Jak mamy do Ciebie pisać?', wybor: true },
  { nazwa: 'onboarding — gotowe', adres: '/witaj/gotowe', napis: 'ugotowałaś', wybor: true },
  { nazwa: 'przepis — przycisk „Ugotowałam"', dynamiczny: 'przepis', napis: 'Ugotowałam' },
  { nazwa: 'tryb gotowania, ostatni krok — przycisk „Ugotowałam"', dynamiczny: 'gotowanieOstatni', napis: 'Ugotowałam' },
  { nazwa: 'formularz wykonania', dynamiczny: 'ugotowalem', napis: 'Ugotowałam' },
  /*
   * CELOWO POZA MACIERZĄ (dane demo tego nie dają, więc pomiar byłby
   * pustym ekranem — dokładnie fałszywa zieleń, przed którą stoi ten plik):
   * powiadomienie „X ugotowała Twój przepis" (widzi je autor przepisu, a
   * persony demo nie są logowalne, D-025), pusty Start i pusty stan
   * powiadomień (konto `ania` ma wpisy), przycisk na karcie wpisu (demo nie
   * ma wpisu powiązanego z przepisem) oraz ekran „wyszło" (widzi go autor).
   * Ich teksty pilnuje `FormaTekstyTest`; pomiar piksela wymaga danych,
   * które dopiero trzeba dosiać.
   */
];

/** Napisy dotyczące formy: formy żeńskie z tekstów i etykiety wyboru. */
const WZOR_FORMY = 'ugotowałam|ugotowałaś|ugotowała|autorka przepisu|jak mamy do ciebie|forma żeńska|forma męska|forma neutralna|inni przeczytają';

/*
 * Funkcja wykonywana W PRZEGLĄDARCE. Surowe liczby, wyrok zapada w
 * `ocenFormePomiar` po stronie Node (tak jak przy `ZMIERZ`).
 */
const ZMIERZ_FORME = ({ wzor }) => {
  const re = new RegExp(wzor, 'i');
  const widoczny = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return false;
    const s = getComputedStyle(el);
    return s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) !== 0;
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
  const wlasny = (el) => [...el.childNodes].filter((w) => w.nodeType === 3)
    .map((w) => w.textContent).join(' ').replace(/\s+/g, ' ').trim();

  const doc = document.documentElement;
  const okno = doc.clientWidth;

  // Elementy formy: liście tekstu z napisem formy oraz cały #forma-zwracania.
  const elementy = new Set();
  for (const el of document.querySelectorAll('body *')) {
    if (el.closest('script, style, template, noscript')) continue;
    const t = wlasny(el);
    if (t && re.test(t) && widoczny(el)) elementy.add(el);
  }
  for (const el of document.querySelectorAll('#forma-zwracania *')) {
    if (wlasny(el) && widoczny(el)) elementy.add(el);
  }

  const tekst = [];
  const ucieta = [];
  const pozaOknem = [];
  for (const el of elementy) {
    const s = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    // Pomoc kontekstowa (`--text-help`, DESIGN_SYSTEM §2.1) wolno mieć 16 px
    // WYŁĄCZNIE obok tekstu głównego ≥ 18 px: etykieta wyboru albo legenda.
    const glowny = el.matches('.choice-help') ? el.parentElement?.querySelector('.choice-label')
      : (el.matches('.field-help') ? el.closest('fieldset')?.querySelector('legend') : null);
    const pomoc = !!glowny && parseFloat(getComputedStyle(glowny).fontSize) >= 18;
    tekst.push({
      sciezka: sciezka(el),
      px: Math.round(parseFloat(s.fontSize) * 100) / 100,
      pomoc,
      probka: wlasny(el).slice(0, 50),
    });
    if (r.left < -1 || r.right > okno + 1) {
      pozaOknem.push({ sciezka: sciezka(el), left: Math.round(r.left), right: Math.round(r.right), probka: wlasny(el).slice(0, 40) });
    }
    // Ucięcie: element albo którykolwiek przodek przycina zawartość (overflow
    // różny od visible), a zawartość jest większa niż jego pole; albo wielokropek.
    for (let n = el; n && n !== document.body; n = n.parentElement) {
      const sn = getComputedStyle(n);
      const przycina = ['hidden', 'clip'].includes(sn.overflowX) || ['hidden', 'clip'].includes(sn.overflowY);
      const wielokropek = sn.textOverflow === 'ellipsis' && n.scrollWidth > n.clientWidth + 1;
      const klamra = sn.webkitLineClamp && sn.webkitLineClamp !== 'none' && n.scrollHeight > n.clientHeight + 1;
      const zaDuze = n.scrollWidth > n.clientWidth + 1 || n.scrollHeight > n.clientHeight + 1;
      if ((przycina && zaDuze) || wielokropek || klamra) {
        ucieta.push({
          sciezka: sciezka(n),
          scrollW: n.scrollWidth, clientW: n.clientWidth, scrollH: n.scrollHeight, clientH: n.clientHeight,
          probka: wlasny(el).slice(0, 40),
        });
        break;
      }
    }
  }

  // Cele: interaktywne elementy formy. Radio i pole wyboru mierzymy po
  // etykiecie (`label.choice`), bo to ona jest celem dotyku.
  const cele = [];
  const SELEKTOR = 'a[href], button, summary, select, input:not([type="hidden"]), textarea, [role="button"]';
  const kandydaci = new Set();
  for (const el of document.querySelectorAll(SELEKTOR)) {
    if (!widoczny(el)) continue;
    if (el.closest('#forma-zwracania') || re.test((el.innerText || el.value || '').trim())) kandydaci.add(el);
  }
  for (const el of kandydaci) {
    const cel = ['radio', 'checkbox'].includes(el.getAttribute('type')) ? (el.closest('label') || el) : el;
    const r = cel.getBoundingClientRect();
    cele.push({
      sciezka: sciezka(cel),
      w: Math.round(r.width * 10) / 10,
      h: Math.round(r.height * 10) / 10,
      nazwa: (el.getAttribute('aria-label') || cel.innerText || el.value || '').replace(/\s+/g, ' ').trim().slice(0, 40),
    });
  }

  const winowajcy = [];
  if (doc.scrollWidth > okno + 1) {
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      if (r.right > okno + 1 || r.left < -1) {
        winowajcy.push({ sciezka: sciezka(el), left: Math.round(r.left), right: Math.round(r.right) });
      }
    }
  }

  return {
    scrollWidth: doc.scrollWidth,
    clientWidth: okno,
    winowajcy: winowajcy.slice(0, 8),
    korzenPx: parseFloat(getComputedStyle(doc).fontSize),
    prog64rem: matchMedia('(min-width: 64rem)').matches,
    napisow: elementy.size,
    tekst,
    cele,
    ucieta: ucieta.slice(0, 8),
    pozaOknem: pozaOknem.slice(0, 8),
  };
};

/**
 * WYROK — czysta funkcja z surowych liczb, bez przeglądarki (testowana
 * w `scripts/fixtures/audyt-ux50plus-konteksty.test.mjs`). Zwraca listę
 * naruszeń po polsku; pusta lista znaczy „ekran czysty".
 */
function ocenFormePomiar(m, progi = { tekst: PROG_TEKSTU, cel: PROG_CELU }) {
  const bledy = [];
  if (m.napisow === 0) {
    bledy.push('na ekranie nie znaleziono żadnego napisu formy — pomiar nie dotyczyłby niczego');
  }
  if (m.scrollWidth > m.clientWidth + 1) {
    const kto = m.winowajcy.slice(0, 3).map((c) => `${c.sciezka} (${c.left}…${c.right})`).join('; ');
    bledy.push(`przewijanie w poziomie: scrollWidth ${m.scrollWidth} > okno ${m.clientWidth}${kto ? ` — ${kto}` : ''}`);
  }
  for (const t of m.tekst) {
    if (t.px < (t.pomoc ? PROG_POMOCY : progi.tekst)) bledy.push(`tekst ${t.px} px < ${progi.tekst} px: ${t.sciezka} „${t.probka}"`);
  }
  for (const c of m.cele) {
    if (c.h < progi.cel || c.w < progi.cel) {
      bledy.push(`cel ${c.w}×${c.h} px < ${progi.cel} px: ${c.sciezka} „${c.nazwa}"`);
    }
  }
  for (const u of m.ucieta) {
    bledy.push(`tekst ucięty (${u.sciezka}: zawartość ${u.scrollW}×${u.scrollH} > pole ${u.clientW}×${u.clientH}) „${u.probka}"`);
  }
  for (const p of m.pozaOknem) {
    bledy.push(`tekst poza oknem (${p.left}…${p.right}): ${p.sciezka} „${p.probka}"`);
  }
  return bledy;
}

/** Wybór formy PRZEZ FORMULARZ (ten, który widzi człowiek). Zwraca opis błędu albo null. */
async function ustawFormeWFormularzu(strona, wartosc) {
  await strona.goto(`${ADRES}/ustawienia/profil`, { waitUntil: 'domcontentloaded' });
  const pole = `#forma-zwracania input[name="form_of_address"][value="${wartosc}"]`;
  if (!(await strona.$(pole))) {
    return 'brak formularza „Jak mamy do Ciebie pisać?" na /ustawienia/profil (okres przejściowy polityki? wtedy `Forma::wyborDostepny()` jest fałszem)';
  }
  await strona.check(pole, { force: true });
  await Promise.all([
    strona.waitForLoadState('domcontentloaded'),
    strona.click('#forma-zwracania button[type="submit"]'),
  ]);
  await strona.goto(`${ADRES}/ustawienia/profil`, { waitUntil: 'domcontentloaded' });
  const zaznaczone = await strona.evaluate((w) => !!document.querySelector(
    `#forma-zwracania input[name="form_of_address"][value="${w}"]:checked`,
  ), wartosc);
  return zaznaczone ? null : `formularz przyjął wybór „${wartosc}", ale po przeładowaniu nie jest zaznaczony`;
}

/** #2405: prawdziwy formularz przy 320 px / 200%, także po błędzie i z klawiaturą. */
async function sprawdzWyborFormyPoBledzie(strona) {
  const radia = strona.locator('#forma-zwracania input[name="form_of_address"]');
  if (await radia.count() !== 3) return ['brak trzech natywnych pól radio'];

  await radia.nth(0).focus();
  await strona.keyboard.press('ArrowRight');
  const strzalkaDziala = await radia.nth(1).isChecked();
  await radia.evaluateAll((pola) => pola.forEach((pole) => { pole.checked = false; }));
  await Promise.all([
    strona.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    strona.locator('#forma-zwracania button[type="submit"]').click(),
  ]);
  await strona.waitForFunction(() => document.activeElement?.classList.contains('error-summary'), null, { timeout: 2000 });
  const stan = await strona.evaluate(() => {
    const pola = [...document.querySelectorAll('#forma-zwracania input[name="form_of_address"]')];
    const grupa = document.querySelector('#f-form_of_address');
    const blad = document.querySelector('#f-form_of_address-error');
    const podsumowanie = document.querySelector('.error-summary');
    return {
      pola: pola.length,
      opisane: pola.every((pole) => pole.getAttribute('aria-invalid') === 'true'
        && ['forma-zwracania-pomoc', 'f-form_of_address-error'].every((id) =>
          pole.getAttribute('aria-describedby')?.split(/\s+/).includes(id) && document.getElementById(id))),
      grupa: grupa?.getAttribute('aria-invalid') === 'true',
      blad: blad?.textContent.includes('Zaznacz jedną z trzech odpowiedzi'),
      link: !!podsumowanie?.querySelector('a[href="#f-form_of_address"]'),
      fokus: document.activeElement === podsumowanie,
      bezPrzewijania: document.documentElement.scrollWidth <= window.innerWidth,
    };
  });
  return [
    ...(!strzalkaDziala ? ['strzałka nie wybiera następnego radia'] : []),
    ...(stan.pola !== 3 || !stan.opisane || !stan.grupa || !stan.blad || !stan.link || !stan.fokus
      ? ['błąd nie jest powiązany z każdym radiem, grupą i podsumowaniem albo fokus nie trafił na podsumowanie'] : []),
    ...(!stan.bezPrzewijania ? ['po błędzie formularz przewija się poziomo przy 320 px / 200%'] : []),
  ];
}

/**
 * Przycisk „Ugotowałam" stoi dopiero na OSTATNIM kroku trybu gotowania, więc
 * idziemy odnośnikami „Następny krok", aż ich nie będzie. Bez tego audyt
 * mierzyłby pierwszy krok, na którym formy nie ma.
 */
async function ostatniKrokGotowania(strona, adresGotowania) {
  let adres = adresGotowania;
  for (let i = 0; i < 60; i += 1) {
    await strona.goto(ADRES + adres, { waitUntil: 'domcontentloaded' });
    const nastepny = await strona.evaluate(() => {
      const a = [...document.querySelectorAll('.cook-nav a')].find((x) => /Następny krok/.test(x.textContent));
      return a ? new URL(a.href, location.origin).pathname + new URL(a.href, location.origin).search : null;
    });
    if (!nastepny) return adres;
    adres = nastepny;
  }
  return adres;
}

/**
 * Cały przebieg odbioru formy. Zwraca `{ pomiary, bledy }`; `bledy` to
 * zdania po polsku (każde = powód czerwieni), `pomiary` idą do raportu.
 */
async function przebiegForma(przegladarka, stan, dyn) {
  const bledy = [];
  const pomiary = [];

  const kontekstUstawien = await przegladarka.newContext({ storageState: stan, viewport: { width: 1280, height: 900 } });
  const stronaUstawien = await kontekstUstawien.newPage();
  const bladUstawienia = await ustawFormeWFormularzu(stronaUstawien, FORMA_ZENSKA);
  if (bladUstawienia) {
    await kontekstUstawien.close();
    return { pomiary, bledy: [`nie da się ustawić formy żeńskiej: ${bladUstawienia}`] };
  }

  try {
    const dynForma = {
      ...dyn,
      ugotowalem: dyn.przepis ? `${dyn.przepis}/ugotowalem` : undefined,
      gotowanieOstatni: dyn.gotowanie ? await ostatniKrokGotowania(stronaUstawien, dyn.gotowanie) : undefined,
    };

    for (const skala of [...FORMA_SKALE, 200]) {
      const kontekst = await przegladarka.newContext({
        storageState: stan,
        viewport: { width: FORMA_SZEROKOSC, height: 900 },
        deviceScaleFactor: 1,
      });
      for (const ekran of EKRANY_FORMY) {
        if (skala === 200 && !ekran.wybor) continue;
        const adres = ekran.dynamiczny ? dynForma[ekran.dynamiczny] : ekran.adres;
        const etykieta = `„${ekran.nazwa}" (${adres || '?'}) ${FORMA_SZEROKOSC} px / czcionka ${skala}%`;
        if (!adres) {
          bledy.push(`${etykieta}: brak adresu — seeder nie dał przepisu albo wpisu, ekran pozostałby niezmierzony`);
          continue;
        }
        const strona = await kontekst.newPage();
        try {
          if (skala !== 100) {
            const cdp = await kontekst.newCDPSession(strona);
            const rozmiar = Math.round(BAZOWA_CZCIONKA_PX * (skala / 100));
            await cdp.send('Page.setFontSizes', { fontSizes: { standard: rozmiar, fixed: rozmiar } });
          }
          const odp = await strona.goto(ADRES + adres, { waitUntil: 'networkidle', timeout: 30000 });
          if (!odp || odp.status() >= 400) {
            bledy.push(`${etykieta}: HTTP ${odp && odp.status()}`);
            continue;
          }
          const otrzymana = new URL(strona.url()).pathname;
          if (otrzymana !== new URL(ADRES + adres).pathname) {
            bledy.push(`${etykieta}: odesłał na ${otrzymana} — pomiar dotyczyłby innej strony`);
            continue;
          }
          await strona.evaluate(() => Promise.race([
            document.fonts.ready,
            new Promise((g) => setTimeout(g, 3000)),
          ]));
          const m = await strona.evaluate(ZMIERZ_FORME, { wzor: WZOR_FORMY });
          const tekstStrony = await strona.evaluate(() => document.body.innerText);
          const naruszenia = [];
          if (!tekstStrony.includes(ekran.napis)) {
            naruszenia.push(`brak napisu „${ekran.napis}" — ekran nie pokazuje formy żeńskiej`);
          }
          if (ekran.wybor && !(await strona.$('#forma-zwracania'))) {
            naruszenia.push('brak formularza wyboru formy (#forma-zwracania)');
          }
          const oczekiwanyKorzen = BAZOWA_CZCIONKA_PX * (skala / 100);
          if (m.korzenPx < oczekiwanyKorzen - 0.5) {
            naruszenia.push(`czcionka korzenia ${m.korzenPx} px zamiast ${oczekiwanyKorzen} px — skala przeglądarki nie weszła, pomiar byłby fałszywą zielenią`);
          }
          if (skala !== 100 && m.prog64rem) {
            naruszenia.push('próg 64rem nadal aktywny przy powiększonej czcionce — zmiana nie dotknęła bazy media queries');
          }
          naruszenia.push(...ocenFormePomiar(m));
          if (ekran.wybor && skala === 200 && await strona.$('#forma-zwracania')) {
            naruszenia.push(...await sprawdzWyborFormyPoBledzie(strona));
          }
          for (const n of naruszenia) bledy.push(`${etykieta}: ${n}`);
          pomiary.push({ ekran: ekran.nazwa, adres, szerokosc: FORMA_SZEROKOSC, skala, ...m, naruszenia });
        } catch (e) {
          bledy.push(`${etykieta}: ${String(e.message).slice(0, 200)}`);
        } finally {
          await strona.close();
        }
      }
      await kontekst.close();
    }
  } finally {
    // Sprzątanie: konto demo wraca na formę neutralną (domyślną).
    const bladPrzywrocenia = await ustawFormeWFormularzu(stronaUstawien, FORMA_NEUTRALNA);
    if (bladPrzywrocenia) bledy.push(`nie przywrócono formy neutralnej: ${bladPrzywrocenia}`);
    await kontekstUstawien.close();
  }
  return { pomiary, bledy };
}

async function main() {
  const przegladarka = await chromium.launch();
  const stan = await stanZalogowanego(przegladarka);
  const dyn = await adresyDynamiczne(przegladarka);
  console.log(`Adresy dynamiczne: przepis=${dyn.przepis} wpis=${dyn.wpis}`);
  if (brakDanychS !== null) {
    // Ekrany paczki S bez danych to pusta zieleń — przerywamy, nie pomijamy.
    console.error(`BŁĄD: nie przygotowano danych ekranów paczki S (fixture nowe-ekrany-s.php, ustaw DB_DATABASE bazy serwera): ${brakDanychS}`);
    await przegladarka.close();
    process.exit(1);
  }

  const wyniki = [];
  const przekierowania = [];
  let przebiegi = 0;

  for (const szerokosc of (TYLKO_FORME ? [] : SZEROKOSCI)) {
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
          // Ponowienie wybranych ekranów po poprawce: TYLKO_EKRANY='sobotni|QR'.
          // Raport z takiego przebiegu jest niepełny.
          if (TYLKO_EKRANY && !TYLKO_EKRANY.test(ekran.nazwa)) continue;
          const adres = ekran.dynamiczny ? dyn[ekran.dynamiczny] : ekran.adres;
          if (!adres) continue;
          const strona = ekran.zalogowany ? stronaZalogowanego : stronaGoscia;
          try {
            // Limity tras są celowe; pomiar wchodzi na te strony dziesiątki razy
            // (429 przechodzi każdy audyt, nie sprawdzając niczego).
            if (ekran.zwolnijLimity) {
              execFileSync('php', ['scripts/fixtures/nowe-ekrany-s.php', 'zwolnij-limity'], { env: process.env });
            }
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
            if (ekran.poWejsciu) {
              await dojdzDoStronyListu(strona, ekran.poWejsciu, dyn.linkWygasly);
              if (!(await strona.$(ekran.znakPo))) {
                const blad = `brak znaku ekranu po kliknięciu (${ekran.znakPo})`;
                przekierowania.push({ ekran: ekran.nazwa, zamowiona, otrzymana: new URL(strona.url()).pathname, szerokosc, motyw, skala, blad });
                wyniki.push({ ekran: ekran.nazwa, adres, szerokosc, motyw, skala, blad });
                continue;
              }
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

  let forma = { pomiary: [], bledy: [] };
  if (!BEZ_FORMY) {
    forma = await przebiegForma(przegladarka, stan, dyn);
    console.log(`… odbiór formy: ${forma.pomiary.length} pomiarów (${FORMA_SZEROKOSC} px × czcionka ${FORMA_SKALE.join('/')}%, wybór także 200%), naruszeń: ${forma.bledy.length}`);
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
    forma,
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

  if (forma.bledy.length > 0) {
    for (const b of forma.bledy) console.error(`BŁĄD (forma zwracania się): ${b}`);
    process.exitCode = 1;
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
