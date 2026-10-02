/*
 * =============================================================================
 *  Kuking.pl — pomiar wydruku pojedynczego przepisu na A4 (issue #765)
 * =============================================================================
 *
 *  Kartka leży obok blatu (D-033): ma mieć tytuł, autora, adres przepisu,
 *  porcje, składniki z grupami i uwagami oraz WSZYSTKIE kroki — i nic, czego
 *  na papierze nie da się kliknąć. Zrzut ekranu tego nie dowodzi, bo ekran
 *  i druk to dwa różne arkusze. Ten skrypt włącza w Chromium PRAWDZIWE
 *  `@media print`, mierzy ułożoną stronę i drukuje PDF A4.
 *
 *  Karta z kodem QR (#2349) jest mierzona tym samym mechanizmem: kod ≥ 9 × 9 cm
 *  w całości na pierwszej stronie, biały podkład i czarne moduły, pismo ≥ 16 pt,
 *  jedna strona A4, a przy zoomie 200% na ekranie brak poziomego przewijania.
 *
 *  Warianty: krótki i długi przepis × motyw jasny i ciemny × gość i autor;
 *  dodatkowo 2/6/4 porcje przez rzeczywisty odnośnik „Drukuj przepis”
 *  (autor widzi najwięcej przycisków, a dolną belkę ma tylko zalogowany).
 *
 *  URUCHOMIENIE (lokalna baza z DemoSeederem, po `npm run build`):
 *      php artisan db:seed --class=DemoSeeder
 *      node scripts/wydruk-przepisu.mjs
 *      ADRES=http://127.0.0.1:8000 node scripts/wydruk-przepisu.mjs   # gotowy serwer
 *
 *  PDF-y do obejrzenia: storage/wydruk-765/*.pdf. Kod wyjścia ≠ 0 przy
 *  każdym naruszeniu — także wtedy, gdy strona w ogóle nie ma reguł druku
 *  (tak było na main przed #765: nawigacja, belki i przyciski szły na papier).
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { createServer } from 'node:net';
import { once } from 'node:events';

// Hasło z UserFactory — konto zakłada i zdejmuje scripts/fixtures/wydruk-765.php.
const HASLO_KONTA = 'haslo-testowe-123';
const KATALOG = 'storage/wydruk-765';

// Nic z tego nie ma sensu na kartce: nawigacja, belki, szybki wygląd,
// przyciski i formularze, cudze wykonania i rozmowa pod przepisem.
const UKRYTE = [
  '.skip-link', '.topbar', '.side-nav', '.bottom-nav', '.app-rail', '.site-footer',
  '.szybki-wyglad', '.pwa-install', '.okruchy', '.przepis-akcje', '.podziel-sie',
  '.przepis-autor form', '.danger-zone', '[aria-labelledby="komu-wyszlo"]',
  '[aria-labelledby="komentarze"]', 'main .btn', 'main button', 'main form',
  '[data-drukuj-przepis]', '.druk-podpowiedz',
  // Objaśnienie „Zgłoś” dla gościa: na papierze nie ma czego kliknąć (każdy wydruk).
  '.zglos-goscia',
  // #2600: tekst objaśnienia zostaje, ale odnośnik i zwijacz na papierze są martwe.
  '.porcje-wybor-uwaga a', '.wartosci-odzywcze-jak summary',
];
// Minimum czytelności na papierze: 12 pt (= 16 px CSS) dla KAŻDEGO tekstu na
// kartce — składników, kroków, ale też autora, daty, adresu i podpisów.
const MIN_PISMO_PX = 16;
// Zdjęcie główne „opcjonalnie małe”: najwyżej 6 cm wysokości.
const MAX_ZDJECIE_PX = 6 / 2.54 * 96;
// Karta z kodem QR (#2349): kod co najmniej 9 cm na 9 cm (skaner czyta
// z kilkudziesięciu centymetrów), pismo co najmniej 16 pt (= 21,33 px),
// wszystko na JEDNEJ stronie A4 (297 mm − 2 × 15 mm marginesu).
const cmNaPx = cm => cm / 2.54 * 96;
const MIN_KOD_PX = cmNaPx(9) - 0.5;
const MIN_PISMO_KARTY_PX = 16 / 72 * 96 - 0.05;
const WYSOKOSC_STRONY_PX = cmNaPx(26.7);
// Kartka „dla pomocnika” (#2345): kod co najmniej 4 × 4 cm.
const MIN_KOD_POMOCNIKA_PX = cmNaPx(4) - 0.5;

function znajdzChromium() {
  if (process.env.CHROMIUM_PATH) return process.env.CHROMIUM_PATH;
  try { if (existsSync(chromium.executablePath())) return undefined; } catch { /* dalej */ }
  return existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined;
}

async function wolnyPort() {
  const gniazdo = createServer().listen(0, '127.0.0.1');
  await once(gniazdo, 'listening');
  const { port } = gniazdo.address();
  await new Promise(r => gniazdo.close(r));
  return port;
}

async function podniesSerwer() {
  const port = await wolnyPort();
  const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'],
    { stdio: ['ignore', 'pipe', 'pipe'] });
  const adres = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 100; i++) {
    try { if ((await fetch(adres + '/login')).ok) return { adres, proces }; } catch { /* jeszcze wstaje */ }
    await new Promise(r => setTimeout(r, 100));
  }
  proces.kill();
  throw new Error('Nie wstał `php artisan serve` — sprawdź .env i bazę.');
}

function stronyPdf(bufor) {
  return (bufor.toString('latin1').match(/\/Type\s*\/Page(?!s)/g) ?? []).length;
}

async function bledyKorzeniaDruku(strona) {
  return strona.evaluate(() => {
    const korzen = getComputedStyle(document.documentElement);
    const bledy = [];
    if (korzen.backgroundColor !== 'rgb(255, 255, 255)') {
      bledy.push(`tło korzenia wydruku nie jest białe: ${korzen.backgroundColor}`);
    }
    if (korzen.colorScheme !== 'light') {
      bledy.push(`schemat kolorów korzenia wydruku nie jest jasny: ${korzen.colorScheme}`);
    }
    return bledy;
  });
}

async function zmierz(strona, pomocnik = null) {
  return strona.evaluate(({ UKRYTE, MIN_PISMO_PX, MAX_ZDJECIE_PX, pomocnik, MIN_KOD_POMOCNIKA_PX }) => {
    const bledy = [];
    const widoczny = el => {
      if (!el.isConnected) return false;
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
    };
    for (const selektor of UKRYTE) {
      const na = [...document.querySelectorAll(selektor)].filter(widoczny);
      if (na.length) bledy.push(`widoczne na papierze: ${selektor} (${na.length})`);
    }
    for (const el of document.querySelectorAll('body *')) {
      const pozycja = getComputedStyle(el).position;
      if ((pozycja === 'fixed' || pozycja === 'sticky') && widoczny(el)) {
        bledy.push(`element ${pozycja} nachodzi na treść: ${el.tagName.toLowerCase()}.${[...el.classList].join('.')}`);
      }
    }
    const musi = { 'tytuł': 'h1', 'autor': '.przepis-autor .author-name', 'adres przepisu': '.przepis-adres-druk', 'porcje': pomocnik ? '.druk-pomocnik-porcje' : '.przepis-liczby' };
    for (const [co, selektor] of Object.entries(musi)) {
      const el = document.querySelector(selektor);
      if (!el || !widoczny(el) || !el.textContent.trim()) bledy.push(`brak na papierze: ${co}`);
    }
    // Adres źródła (przepis z książki lub bloga) stoi w panelu z „Ugotowałem”
    // — panel znika z papieru, jego źródło nie może.
    const zrodlo = [...document.querySelectorAll('.przepis-panel p')].find(p => p.textContent.includes('Przepis pochodzi ze strony'));
    if (zrodlo && !widoczny(zrodlo)) bledy.push('brak na papierze: adres źródła');
    const adres = document.querySelector('.przepis-adres-druk')?.textContent ?? '';
    if (!adres.includes(location.origin + location.pathname)) bledy.push('adres na kartce nie jest adresem tego przepisu');
    const pozycje = [...document.querySelectorAll('.ingredient-list li, .step-list > li, .naglowek-grupy')];
    if (!pozycje.length) bledy.push('brak składników i kroków w pomiarze');
    for (const el of pozycje) {
      if (!widoczny(el)) bledy.push(`ukryty składnik lub krok: ${el.textContent.trim().slice(0, 40)}`);
      const px = parseFloat(getComputedStyle(el).fontSize);
      if (px < MIN_PISMO_PX) bledy.push(`za małe pismo (${px}px): ${el.textContent.trim().slice(0, 40)}`);
    }
    for (const krok of document.querySelectorAll('.step-list > li')) {
      if (getComputedStyle(krok).breakInside !== 'avoid') { bledy.push('krok może się przełamać między stronami'); break; }
    }
    // Każde tło i każdy tekst, nie tylko <body>: pierwszy pomiar przepuścił
    // ciemne tło nagłówka przepisu z arkusza marki i szary tekst na nim.
    const kanal = kolor => Math.max(...(kolor.match(/[\d.]+/g) ?? ['0']).slice(0, 3).map(Number));
    const przezroczysty = kolor => kolor === 'transparent' || /rgba\(.*,\s*0\)$/.test(kolor);
    for (const el of document.querySelectorAll('body, body *')) {
      if (!widoczny(el)) continue;
      for (const pseudo of [null, '::before', '::after']) {
        const styl = getComputedStyle(el, pseudo);
        const tloEl = styl.backgroundColor;
        if (!przezroczysty(tloEl) && tloEl !== 'rgb(255, 255, 255)') { bledy.push(`kolorowe tło na papierze: ${el.tagName.toLowerCase()}.${[...el.classList].join('.')}${pseudo ?? ''} ${tloEl}`); }
        if (styl.backgroundImage !== 'none' && el.tagName !== 'IMG') { bledy.push(`obraz tła na papierze: ${el.tagName.toLowerCase()}.${[...el.classList].join('.')}${pseudo ?? ''}`); }
      }
      const maTekst = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim());
      const pismo = parseFloat(getComputedStyle(el).fontSize);
      if (maTekst && pismo < MIN_PISMO_PX) bledy.push(`pismo poniżej 12 pt (${pismo}px): ${el.tagName.toLowerCase()}.${[...el.classList].join('.')} „${el.textContent.trim().slice(0, 30)}”`);
      if (maTekst && kanal(getComputedStyle(el).color) > 100) bledy.push(`jasny tekst na papierze: ${el.textContent.trim().slice(0, 30)} ${getComputedStyle(el).color}`);
    }
    const tlo = getComputedStyle(document.body).backgroundColor;
    if (!['rgb(255, 255, 255)', 'rgba(0, 0, 0, 0)'].includes(tlo)) bledy.push(`tło kartki nie jest białe: ${tlo}`);
    const kolor = getComputedStyle(document.querySelector('h1')).color.match(/\d+/g).map(Number);
    if (Math.max(...kolor.slice(0, 3)) > 80) bledy.push(`tytuł nie jest ciemny na papierze: ${kolor}`);
    const zdjecie = document.querySelector('.przepis-hero-zdjecie');
    if (zdjecie && widoczny(zdjecie) && zdjecie.getBoundingClientRect().height > MAX_ZDJECIE_PX) {
      bledy.push(`zdjęcie główne za duże: ${Math.round(zdjecie.getBoundingClientRect().height)}px`);
    }
    if (pomocnik) {
      // „Dla pomocnika” (#2345): kartka na blat — krótsza (bez opisu i „Skąd ten
      // przepis”), pismo co najmniej 16 pt, jedna liczba porcji, kod QR tylko na żądanie.
      for (const selektor of ['.recipe-story', '.text-lead', '.porcje-wybor', '.porcje-wybor-uwaga', '.zglos-goscia']) {
        const na = [...document.querySelectorAll(selektor)].filter(widoczny);
        if (na.length) bledy.push(`widoczne na kartce dla pomocnika: ${selektor} (${na.length})`);
      }
      const min16 = 16 / 72 * 96 - 0.05;
      for (const el of document.querySelectorAll('.przepis-uklad *')) {
        if (!widoczny(el) || el.closest('svg')) continue;
        if (![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) continue;
        const px = parseFloat(getComputedStyle(el).fontSize);
        if (px < min16) bledy.push(`pismo poniżej 16 pt (${(px / 96 * 72).toFixed(1)} pt): ${el.tagName.toLowerCase()}.${[...el.classList].join('.')} „${el.textContent.trim().slice(0, 30)}”`);
      }
      const kod = document.querySelector('.druk-pomocnik-qr-kod');
      const svg = kod?.querySelector('svg');
      if (pomocnik.qr !== Boolean(kod && widoczny(kod))) bledy.push(pomocnik.qr ? 'brak kodu QR na kartce' : 'kod QR na kartce, której o niego nie proszono');
      if (pomocnik.qr && svg) {
        const r = svg.getBoundingClientRect();
        if (r.width < MIN_KOD_POMOCNIKA_PX || Math.abs(r.width - r.height) > 1) bledy.push(`kod QR za mały lub nie kwadrat: ${(r.width / 96 * 2.54).toFixed(2)} × ${(r.height / 96 * 2.54).toFixed(2)} cm (minimum 4 × 4)`);
        if (getComputedStyle(kod).backgroundColor !== 'rgb(255, 255, 255)') bledy.push(`kod QR bez białego podkładu: ${getComputedStyle(kod).backgroundColor}`);
        const w = [...svg.querySelectorAll('rect, path')].map(el => getComputedStyle(el).fill);
        if (!w.includes('rgb(0, 0, 0)')) bledy.push('kod QR bez czarnych modułów');
        if (w.some(f => !['rgb(0, 0, 0)', 'rgb(255, 255, 255)', 'none'].includes(f))) bledy.push('kod QR z kolorem innym niż czarny i biały');
        if (!document.querySelector('.druk-pomocnik-qr-adres')?.textContent.trim().startsWith('http')) bledy.push('brak adresu tekstem pod kodem');
        const sekcja = document.querySelector('.druk-pomocnik-qr').getBoundingClientRect();
        if (sekcja.right > document.documentElement.clientWidth + 0.5) bledy.push('kod QR wystaje poza szerokość kartki');
      }
    }
    return { bledy, skladniki: document.querySelectorAll('.ingredient-list li').length,
      kroki: document.querySelectorAll('.step-list > li').length };
  }, { UKRYTE, MIN_PISMO_PX, MAX_ZDJECIE_PX, pomocnik, MIN_KOD_POMOCNIKA_PX });
}

// #2484: mierzymy tekst wewnątrz właściwego kroku przy rzeczywistym @media print,
// a nie wystąpienie „Czas kroku” gdziekolwiek w HTML (np. w niewidocznej belce).
async function zmierzCzasKroku(strona, zeszyt = false) {
  return strona.evaluate(zeszyt => {
    const zakres = zeszyt ? document.querySelector('.zeszyt-przepis') : document.querySelector('.przepis-uklad');
    const kroki = [...(zakres?.querySelectorAll('.step-list > li') ?? [])];
    const bledy = [];
    if (kroki.length < 3) return ['brak trzech kroków kontrolnych z czasem, zerem i NULL'];
    const etykiety = krok => [...krok.querySelectorAll('p')].filter(p => p.textContent.trim().startsWith('Czas kroku:'));
    const pierwszy = etykiety(kroki[0]);
    if (pierwszy.length !== 1 || pierwszy[0].textContent.trim() !== 'Czas kroku: 10 minut') {
      bledy.push('pierwszy krok bez dokładnego czasu 10 minut na kartce');
    } else {
      const r = pierwszy[0].getBoundingClientRect();
      if (r.width <= 0 || r.height <= 0 || getComputedStyle(pierwszy[0]).visibility === 'hidden') {
        bledy.push('czas pierwszego kroku niewidoczny na papierze');
      }
    }
    for (const indeks of [1, 2]) {
      if (etykiety(kroki[indeks]).length) bledy.push(`krok ${indeks + 1} z czasem 0/NULL ma fałszywą etykietę`);
    }
    if (kroki.flatMap(etykiety).length !== 1) bledy.push('liczba etykiet czasu na kartce różni się od jednej');
    for (const element of zakres?.querySelectorAll('.cook-timer, [role="timer"]') ?? []) {
      const r = element.getBoundingClientRect();
      if (r.width > 0 && r.height > 0 && getComputedStyle(element).visibility !== 'hidden') {
        bledy.push('interaktywny minutnik jest widoczny na papierze');
        break;
      }
    }
    return bledy;
  }, zeszyt);
}

// Kontrola ujemna bez zmiany plików: zdjęcie etykiety z DOM musi oblać pomiar.
async function sprawdzMutacjeCzasu(strona, zeszyt = false) {
  await strona.evaluate(zeszyt => {
    const zakres = zeszyt ? document.querySelector('.zeszyt-przepis') : document.querySelector('.przepis-uklad');
    const pierwszy = zakres?.querySelector('.step-list > li');
    [...(pierwszy?.querySelectorAll('p') ?? [])]
      .find(p => p.textContent.trim().startsWith('Czas kroku:'))?.remove();
  }, zeszyt);
  return (await zmierzCzasKroku(strona, zeszyt)).includes('pierwszy krok bez dokładnego czasu 10 minut na kartce');
}

// #2474: idziemy pod rzeczywisty href z wybranego ekranu, a nie składamy
// drugiego adresu w teście. Dzięki temu błąd w samym przycisku oblewa pomiar.
async function adresDrukuWybranychPorcji(strona, ile, sciezka) {
  const link = await strona.locator('a[data-drukuj-przepis]').getAttribute('href');
  if (!link) throw new Error(`${sciezka}: brak głównego odnośnika „Drukuj przepis”`);
  const cel = new URL(link, strona.url());
  const obecny = new URL(strona.url());
  const oczekiwanePorcje = ile === 6 ? null : String(ile); // 6 to liczba autora w obu fixture.
  if (cel.origin !== obecny.origin || cel.pathname !== obecny.pathname
    || cel.searchParams.get('druk') !== '1'
    || cel.searchParams.get('porcje') !== oczekiwanePorcje
    || cel.hash !== '#jak-wydrukowac') {
    throw new Error(`${sciezka}: link druku nie zachował wyboru ${ile} porcji`);
  }
  return cel.href;
}

async function zmierzWybranePorcjeNaKartce(strona, ile, sciezka) {
  const bledy = await strona.evaluate(wybrane => {
    const wynik = [];
    const wybor = document.querySelector('.porcje-wybor-liczba');
    const prostokat = wybor?.getBoundingClientRect();
    const etykieta = { 2: '2 porcje', 4: '4 porcje', 6: '6 porcji' }[wybrane];
    if (wybor?.textContent.trim() !== etykieta || !prostokat || prostokat.width <= 0
      || prostokat.height <= 0 || getComputedStyle(wybor).visibility === 'hidden') {
      wynik.push('na kartce nie widać wybranej liczby porcji');
    }
    const pierwszy = document.querySelector('.ingredient-list li');
    if (!pierwszy) return [...wynik, 'na kartce nie ma pierwszego składnika'];
    const przeliczone = document.querySelectorAll('.ingredient-list strong.skladnik-przeliczony').length;
    if (wybrane === 6 && przeliczone !== 0) wynik.push('liczba porcji autora nie powinna przeliczać składników');
    if (wybrane !== 6 && przeliczone === 0) wynik.push('wybrane porcje nie przeliczyły żadnego składnika');
    return wynik;
  }, ile);
  if (bledy.length) throw new Error(`${sciezka}: ${bledy.join('; ')}`);
}

// #2600: mutacje rzeczywistego dokumentu w media=print, nie tekstu arkusza.
// Każda kontrola musi oblać własnym komunikatem i po przywróceniu przejść.
async function sprawdzMutacjeMartwychElementow(strona) {
  for (const selektor of ['.porcje-wybor-uwaga a', '.wartosci-odzywcze-jak summary']) {
    const element = strona.locator(selektor).first();
    if (await element.count() !== 1) return false;
    const poprzedniStyl = await element.getAttribute('style');
    try {
      await element.evaluate(el => el.style.setProperty('display', 'inline', 'important'));
      const bledy = (await zmierz(strona)).bledy;
      if (!bledy.some(blad => blad.includes(`widoczne na papierze: ${selektor}`))) return false;
    } finally {
      await element.evaluate((el, styl) => {
        if (styl === null) el.removeAttribute('style');
        else el.setAttribute('style', styl);
      }, poprzedniStyl);
    }
    if ((await zmierz(strona)).bledy.some(blad => blad.includes(`widoczne na papierze: ${selektor}`))) return false;
  }
  return true;
}

async function sprawdzMutacjeCiemnejRamy(strona) {
  const poprzedniStyl = await strona.evaluate(() => document.documentElement.getAttribute('style'));
  try {
    await strona.evaluate(() => {
      document.documentElement.style.setProperty('background-color', '#000', 'important');
      document.documentElement.style.setProperty('color-scheme', 'dark', 'important');
    });
    const bledy = await bledyKorzeniaDruku(strona);
    if (!bledy.some(blad => blad.includes('tło korzenia wydruku nie jest białe'))) return false;
  } finally {
    await strona.evaluate(styl => {
      if (styl === null) document.documentElement.removeAttribute('style');
      else document.documentElement.setAttribute('style', styl);
    }, poprzedniStyl);
  }
  return (await bledyKorzeniaDruku(strona)).length === 0;
}

// Kontrola ujemna linku: usunięcie parametru z href musi zostać wykryte.
async function sprawdzMutacjeLinkuPorcji(strona, sciezka) {
  await strona.locator('a[data-drukuj-przepis]').evaluate(link => {
    const cel = new URL(link.href);
    cel.searchParams.delete('porcje');
    link.href = cel.href;
  });
  try {
    await adresDrukuWybranychPorcji(strona, 2, sciezka);
    return false;
  } catch (blad) {
    return blad instanceof Error && blad.message.includes('link druku nie zachował wyboru 2 porcji');
  }
}

// Pomiar karty z kodem QR w emulacji druku: geometria i kontrast. Samego
// dekodowania kodu nie ma (brak biblioteki w zależnościach) — o tym, że
// skaner go przeczyta, świadczą rozmiar, biały podkład i czarne moduły.
async function zmierzKarte(strona) {
  return strona.evaluate(({ UKRYTE, MIN_KOD_PX, MIN_PISMO_KARTY_PX, WYSOKOSC_STRONY_PX }) => {
    const bledy = [];
    const widoczny = el => {
      const r = el.getBoundingClientRect();
      return el.isConnected && r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
    };
    for (const selektor of UKRYTE) {
      const na = [...document.querySelectorAll(selektor)].filter(widoczny);
      if (na.length) bledy.push(`widoczne na papierze: ${selektor} (${na.length})`);
    }
    const karta = document.querySelector('.karta-qr');
    const kod = document.querySelector('.karta-qr-kod');
    const svg = kod?.querySelector('svg');
    if (!karta || !kod || !svg || !widoczny(kod)) return { bledy: ['brak kodu QR na papierze'], kod: null };
    const r = svg.getBoundingClientRect();
    const dol = r.bottom + scrollY;
    if (r.width < MIN_KOD_PX || r.height < MIN_KOD_PX) {
      bledy.push(`kod QR za mały: ${(r.width / 96 * 2.54).toFixed(2)} × ${(r.height / 96 * 2.54).toFixed(2)} cm (minimum 9 × 9)`);
    }
    if (Math.abs(r.width - r.height) > 1) bledy.push(`kod QR nie jest kwadratem: ${r.width} × ${r.height}`);
    if (dol > WYSOKOSC_STRONY_PX) bledy.push(`kod QR nie mieści się na pierwszej stronie (dół na ${Math.round(dol)} px, strona ${Math.round(WYSOKOSC_STRONY_PX)} px)`);
    const kr = karta.getBoundingClientRect();
    if (kr.bottom + scrollY > WYSOKOSC_STRONY_PX) bledy.push(`karta nie mieści się na jednej stronie (dół na ${Math.round(kr.bottom + scrollY)} px, strona ${Math.round(WYSOKOSC_STRONY_PX)} px)`);
    if (r.right > document.documentElement.clientWidth + 0.5 || r.left < -0.5) bledy.push('kod QR wystaje poza szerokość kartki');
    // Biały podkład i czarne moduły: skaner potrzebuje ciemnych modułów
    // na jasnym polu, także wtedy, gdy strona ma ciemny motyw.
    const tlo = getComputedStyle(kod).backgroundColor;
    if (tlo !== 'rgb(255, 255, 255)') bledy.push(`kod QR bez białego podkładu: ${tlo}`);
    const wypelnienia = [...svg.querySelectorAll('rect, path')].map(el => getComputedStyle(el).fill);
    const czarne = wypelnienia.filter(w => w === 'rgb(0, 0, 0)').length;
    const biale = wypelnienia.filter(w => w === 'rgb(255, 255, 255)').length;
    const inne = wypelnienia.filter(w => !['rgb(0, 0, 0)', 'rgb(255, 255, 255)', 'none'].includes(w));
    if (!czarne) bledy.push('kod QR bez czarnych modułów');
    if (!biale) bledy.push('kod QR bez białego tła w obrazku');
    if (inne.length) bledy.push(`kod QR z kolorem innym niż czarny i biały: ${[...new Set(inne)].join(', ')}`);
    // Cały tekst na karcie: co najmniej 16 pt, ciemny na białym.
    let najmniejsze = Infinity;
    for (const el of karta.querySelectorAll('*')) {
      if (el.closest('svg') || !widoczny(el)) continue;
      const styl = getComputedStyle(el);
      if (![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) continue;
      const px = parseFloat(styl.fontSize);
      najmniejsze = Math.min(najmniejsze, px);
      const opis = `${el.tagName.toLowerCase()}.${[...el.classList].join('.')} „${el.textContent.trim().slice(0, 30)}”`;
      if (px < MIN_PISMO_KARTY_PX) bledy.push(`pismo poniżej 16 pt (${(px / 96 * 72).toFixed(1)} pt): ${opis}`);
      if (Math.max(...styl.color.match(/\d+/g).slice(0, 3).map(Number)) > 80) bledy.push(`tekst nie jest ciemny na papierze: ${opis} ${styl.color}`);
    }
    for (const el of document.querySelectorAll('body, body *')) {
      if (!widoczny(el) || el.closest('svg')) continue;
      const t = getComputedStyle(el).backgroundColor;
      if (t !== 'rgba(0, 0, 0, 0)' && t !== 'transparent' && t !== 'rgb(255, 255, 255)') bledy.push(`kolorowe tło na papierze: ${el.tagName.toLowerCase()}.${[...el.classList].join('.')} ${t}`);
    }
    const adres = karta.querySelector('.karta-qr-adres')?.textContent.trim() ?? '';
    if (!adres.startsWith(location.origin.replace(/:\d+$/, '')) && !adres.startsWith('http')) bledy.push('brak adresu tekstem pod kodem');
    return { bledy, kod: { szer: r.width, wys: r.height, dol, czarne, biale, najmniejsze } };
  }, { UKRYTE, MIN_KOD_PX, MIN_PISMO_KARTY_PX, WYSOKOSC_STRONY_PX });
}

// Zoom 200% w przeglądarce = połowa szerokości okna w pikselach CSS: na
// ekranie (nie na papierze) nic nie może wyjść poza szerokość.
async function zmierzKarteNaEkranie(strona) {
  return strona.evaluate(() => {
    const bledy = [];
    const okno = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth > okno + 1) bledy.push(`poziome przewijanie: strona ${document.documentElement.scrollWidth} px, okno ${okno} px`);
    const svg = document.querySelector('.karta-qr-kod svg');
    const r = svg?.getBoundingClientRect();
    if (!r || r.right > okno + 0.5 || r.left < -0.5) bledy.push('kod QR wystaje poza szerokość okna');
    for (const el of document.querySelectorAll('main *')) {
      const b = el.getBoundingClientRect();
      if (b.width > 0 && b.right > okno + 1) bledy.push(`wystaje poza okno: ${el.tagName.toLowerCase()}.${[...el.classList].join('.')} (${Math.round(b.right)} px)`);
    }
    return { bledy, szer: Math.round(r?.width ?? 0) };
  });
}

const wlasnySerwer = process.env.ADRES ? null : await podniesSerwer();
const adres = process.env.ADRES ?? wlasnySerwer.adres;
if (!['127.0.0.1', 'localhost'].includes(new URL(adres).hostname)) throw new Error('Pomiar wydruku tylko lokalnie.');
const przepisy = JSON.parse(execFileSync('php', ['scripts/fixtures/wydruk-765.php', 'utworz'], { encoding: 'utf8' }));
mkdirSync(KATALOG, { recursive: true });
const przegladarka = await chromium.launch({ headless: true, executablePath: znajdzChromium() });
let bledow = 0;
try {
  const logowanie = await przegladarka.newContext();
  const s = await logowanie.newPage();
  await s.goto(adres + '/login');
  await s.fill('input[name="login"]', przepisy.konto);
  await s.fill('input[name="password"]', HASLO_KONTA);
  await Promise.all([s.waitForURL(u => !u.pathname.endsWith('/login')), s.click('button[type="submit"]')]);
  const sesjaAutora = await logowanie.storageState();
  await logowanie.close();

  for (const kto of ['gosc', 'autor']) {
    const kontekst = await przegladarka.newContext({
      storageState: kto === 'autor' ? sesjaAutora : undefined,
      // Szerokość A4 minus marginesy — tak Chromium układa stronę do druku.
      viewport: { width: 718, height: 1000 },
      serviceWorkers: 'block',
    });
    const strona = await kontekst.newPage();
    for (const [dlugosc, sciezka] of [['krotki', przepisy.krotki], ['dlugi', przepisy.dlugi]]) {
      for (const motyw of ['jasny', 'ciemny']) {
        const odp = await strona.goto(adres + sciezka, { waitUntil: 'load' });
        if (odp.status() !== 200) throw new Error(`${sciezka}: HTTP ${odp.status()}`);
        await strona.evaluate(m => { if (m === 'ciemny') document.documentElement.dataset.theme = 'dark'; else delete document.documentElement.dataset.theme; }, motyw);
        await strona.emulateMedia({ media: 'print' });
        const wynik = await zmierz(strona);
        wynik.bledy.push(...await bledyKorzeniaDruku(strona));
        if (dlugosc === 'dlugi') wynik.bledy.push(...await zmierzCzasKroku(strona));
        const pdf = await strona.pdf({ format: 'A4', printBackground: true, margin: { top: '15mm', bottom: '15mm', left: '15mm', right: '15mm' } });
        const plik = `${KATALOG}/${dlugosc}-${motyw}-${kto}.pdf`;
        writeFileSync(plik, pdf);
        const opis = `${dlugosc}/${motyw}/${kto}: ${wynik.skladniki} składników, ${wynik.kroki} kroków, ${stronyPdf(pdf)} str. A4 → ${plik}`;
        if (wynik.bledy.length) {
          bledow += wynik.bledy.length;
          console.log(`✗ ${opis}\n  - ${[...new Set(wynik.bledy)].join('\n  - ')}`);
        } else {
          console.log(`✓ ${opis}`);
        }
        if (dlugosc === 'dlugi' && motyw === 'jasny' && kto === 'gosc' && !await sprawdzMutacjeCzasu(strona)) {
          bledow++;
          console.log('✗ kontrola ujemna: usunięty czas kroku nie został wykryty');
        }
        await strona.emulateMedia({ media: 'screen' });
      }
    }

    // #2474/#2484: fizyczna nawigacja od wybranych porcji przez główny
    // odnośnik, potem A4 PDF. Długi przepis sprawdza także czas 10/0/NULL.
    for (const [dlugosc, sciezka] of [['krotki', przepisy.krotki], ['dlugi', przepisy.dlugi]]) {
      for (const ile of [2, 6, 4]) {
        for (const motyw of ['jasny', 'ciemny']) {
          const wybrane = `${sciezka}${sciezka.includes('?') ? '&' : '?'}porcje=${ile}`;
          const ekran = await strona.goto(adres + wybrane, { waitUntil: 'load' });
          if (ekran.status() !== 200) throw new Error(`${wybrane}: HTTP ${ekran.status()}`);
          const link = await adresDrukuWybranychPorcji(strona, ile, wybrane);
          if (dlugosc === 'dlugi' && ile === 2 && motyw === 'jasny' && kto === 'gosc'
            && !await sprawdzMutacjeLinkuPorcji(strona, wybrane)) {
            bledow++;
            console.log('✗ kontrola ujemna: brak porcji w linku druku nie został wykryty');
          }
          const odp = await strona.goto(link, { waitUntil: 'load' });
          if (odp.status() !== 200) throw new Error(`${link}: HTTP ${odp.status()}`);
          await strona.evaluate(m => { if (m === 'ciemny') document.documentElement.dataset.theme = 'dark'; else delete document.documentElement.dataset.theme; }, motyw);
          await strona.emulateMedia({ media: 'print' });
          const wynik = await zmierz(strona);
          wynik.bledy.push(...await bledyKorzeniaDruku(strona));
          try { await zmierzWybranePorcjeNaKartce(strona, ile, link); } catch (blad) { wynik.bledy.push(blad.message); }
          if (dlugosc === 'dlugi') wynik.bledy.push(...await zmierzCzasKroku(strona));
          if (dlugosc === 'krotki' && ile === 2 && motyw === 'jasny' && kto === 'gosc') {
            if (await sprawdzMutacjeMartwychElementow(strona)) {
              console.log('✓ kontrola ujemna #2600: odnośnik i zwijacz wykryte, stan przywrócony');
            } else {
              wynik.bledy.push('kontrola ujemna #2600: martwy odnośnik lub zwijacz nie został wykryty');
            }
            if (await sprawdzMutacjeCiemnejRamy(strona)) {
              console.log('✓ kontrola ujemna #2600: ciemny korzeń wykryty, stan przywrócony');
            } else {
              wynik.bledy.push('kontrola ujemna #2600: ciemny korzeń kartki nie został wykryty');
            }
          }
          const pdf = await strona.pdf({ format: 'A4', printBackground: true, margin: { top: '15mm', bottom: '15mm', left: '15mm', right: '15mm' } });
          const plik = `${KATALOG}/${dlugosc}-${ile}-porcje-${motyw}-${kto}.pdf`;
          writeFileSync(plik, pdf);
          const opis = `${dlugosc}/${ile} porcji/${motyw}/${kto}: ${stronyPdf(pdf)} str. A4 → ${plik}`;
          if (wynik.bledy.length) {
            bledow += wynik.bledy.length;
            console.log(`✗ ${opis}\n  - ${[...new Set(wynik.bledy)].join('\n  - ')}`);
          } else {
            console.log(`✓ ${opis}`);
          }
          await strona.emulateMedia({ media: 'screen' });
        }
      }
    }

    // Kartka „dla pomocnika” (#2345): bez kodu QR i z kodem QR.
    for (const [dlugosc, sciezka] of [['krotki', przepisy.krotki], ['dlugi', przepisy.dlugi]]) {
      for (const qr of [false, true]) {
        for (const motyw of ['jasny', 'ciemny']) {
          const adresPomocnika = `${sciezka}${sciezka.includes('?') ? '&' : '?'}druk=1&dla=pomocnika${qr ? '&qr=1' : ''}`;
          const odp = await strona.goto(adres + adresPomocnika, { waitUntil: 'load' });
          if (odp.status() !== 200) throw new Error(`${adresPomocnika}: HTTP ${odp.status()}`);
          await strona.evaluate(m => { if (m === 'ciemny') document.documentElement.dataset.theme = 'dark'; else delete document.documentElement.dataset.theme; }, motyw);
          await strona.emulateMedia({ media: 'print' });
          const wynik = await zmierz(strona, { qr });
          wynik.bledy.push(...await bledyKorzeniaDruku(strona));
          if (dlugosc === 'dlugi') wynik.bledy.push(...await zmierzCzasKroku(strona));
          const pdf = await strona.pdf({ format: 'A4', printBackground: true, margin: { top: '15mm', bottom: '15mm', left: '15mm', right: '15mm' } });
          const plik = `${KATALOG}/pomocnik-${dlugosc}${qr ? '-qr' : ''}-${motyw}-${kto}.pdf`;
          writeFileSync(plik, pdf);
          const opis = `pomocnik/${dlugosc}${qr ? '/z QR' : ''}/${motyw}/${kto}: ${wynik.skladniki} składników, ${wynik.kroki} kroków, ${stronyPdf(pdf)} str. A4 → ${plik}`;
          if (wynik.bledy.length) {
            bledow += wynik.bledy.length;
            console.log(`✗ ${opis}\n  - ${[...new Set(wynik.bledy)].join('\n  - ')}`);
          } else {
            console.log(`✓ ${opis}`);
          }
          await strona.emulateMedia({ media: 'screen' });
        }
      }
    }

    // #2484: także kartka zeszytu. HTML jest drukowany przez przeglądarkę,
    // a PDF powstaje z tego samego @media print co pojedynczy przepis.
    for (const motyw of ['jasny', 'ciemny']) {
      const odp = await strona.goto(adres + przepisy.zeszyt_czas, { waitUntil: 'load' });
      if (odp.status() !== 200) throw new Error(`${przepisy.zeszyt_czas}: HTTP ${odp.status()}`);
      await strona.evaluate(m => { if (m === 'ciemny') document.documentElement.dataset.theme = 'dark'; else delete document.documentElement.dataset.theme; }, motyw);
      await strona.emulateMedia({ media: 'print' });
      const bledy = [...await zmierzCzasKroku(strona, true), ...await bledyKorzeniaDruku(strona)];
      const pdf = await strona.pdf({ format: 'A4', printBackground: true, margin: { top: '15mm', bottom: '15mm', left: '15mm', right: '15mm' } });
      const plik = `${KATALOG}/zeszyt-czas-${motyw}-${kto}.pdf`;
      writeFileSync(plik, pdf);
      const stron = stronyPdf(pdf);
      if (stron < 1) bledy.push('zeszyt nie dał strony A4');
      if (bledy.length) {
        bledow += bledy.length;
        console.log(`✗ zeszyt/${motyw}/${kto}: ${stron} str. A4 → ${plik}\n  - ${bledy.join('\n  - ')}`);
      } else {
        console.log(`✓ zeszyt/${motyw}/${kto}: czas kroku i ${stron} str. A4 → ${plik}`);
      }
      if (motyw === 'jasny' && kto === 'gosc' && !await sprawdzMutacjeCzasu(strona, true)) {
        bledow++;
        console.log('✗ kontrola ujemna: usunięty czas kroku w zeszycie nie został wykryty');
      }
      await strona.emulateMedia({ media: 'screen' });
    }

    // Karta z kodem QR (#2349): krótki i długi tytuł przepisu oraz profil.
    for (const [nazwa, sciezka] of [['karta-przepis-krotki', przepisy.karta_krotki], ['karta-przepis-dlugi', przepisy.karta_dlugi], ['karta-profil', przepisy.karta_profil]]) {
      for (const motyw of ['jasny', 'ciemny']) {
        const odp = await strona.goto(adres + sciezka, { waitUntil: 'load' });
        if (odp.status() !== 200) throw new Error(`${sciezka}: HTTP ${odp.status()}`);
        await strona.evaluate(m => { if (m === 'ciemny') document.documentElement.dataset.theme = 'dark'; else delete document.documentElement.dataset.theme; }, motyw);
        await strona.emulateMedia({ media: 'print' });
        const wynik = await zmierzKarte(strona);
        wynik.bledy.push(...await bledyKorzeniaDruku(strona));
        const pdf = await strona.pdf({ format: 'A4', printBackground: true, margin: { top: '15mm', bottom: '15mm', left: '15mm', right: '15mm' } });
        const plik = `${KATALOG}/${nazwa}-${motyw}-${kto}.pdf`;
        writeFileSync(plik, pdf);
        const stron = stronyPdf(pdf);
        if (stron !== 1) wynik.bledy.push(`karta zajmuje ${stron} str. A4 zamiast jednej`);
        const k = wynik.kod;
        const opis = `${nazwa}/${motyw}/${kto}: kod ${k ? `${(k.szer / 96 * 2.54).toFixed(2)} × ${(k.wys / 96 * 2.54).toFixed(2)} cm (${Math.round(k.szer)} × ${Math.round(k.wys)} px), dół na ${Math.round(k.dol)} px, najmniejsze pismo ${(k.najmniejsze / 96 * 72).toFixed(1)} pt` : 'brak'}, ${stron} str. A4 → ${plik}`;
        if (wynik.bledy.length) {
          bledow += wynik.bledy.length;
          console.log(`✗ ${opis}\n  - ${[...new Set(wynik.bledy)].join('\n  - ')}`);
        } else {
          console.log(`✓ ${opis}`);
        }
        // Ten sam ekran przy zoomie 200% (okno 1280 px → 640 px CSS).
        await strona.emulateMedia({ media: 'screen' });
        await strona.setViewportSize({ width: 640, height: 500 });
        const naEkranie = await zmierzKarteNaEkranie(strona);
        await strona.setViewportSize({ width: 718, height: 1000 });
        if (naEkranie.bledy.length) {
          bledow += naEkranie.bledy.length;
          console.log(`✗ ${nazwa}/${motyw}/${kto} zoom 200%: kod ${naEkranie.szer} px\n  - ${[...new Set(naEkranie.bledy)].join('\n  - ')}`);
        } else {
          console.log(`✓ ${nazwa}/${motyw}/${kto} zoom 200%: bez poziomego przewijania, kod ${naEkranie.szer} px`);
        }
      }
    }
    await kontekst.close();
  }
} finally {
  await przegladarka.close();
  execFileSync('php', ['scripts/fixtures/wydruk-765.php', 'usun']);
  wlasnySerwer?.proces.kill();
}
if (bledow) {
  console.error(`Wydruk przepisu: ${bledow} naruszeń. Popraw regułę @media print w resources/css/wydruk-przepisu.css.`);
  process.exit(1);
}
console.log('Wydruk przepisu i karty z kodem QR: wszystkie warianty czytelne na A4.');
