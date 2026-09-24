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
 *  Warianty: krótki i długi przepis × motyw jasny i ciemny × gość i autor
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
];
// Minimum czytelności na papierze: 12 pt (= 16 px CSS) dla składników i kroków.
const MIN_PISMO_PX = 16;
// Zdjęcie główne „opcjonalnie małe”: najwyżej 6 cm wysokości.
const MAX_ZDJECIE_PX = 6 / 2.54 * 96;

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

async function zmierz(strona) {
  return strona.evaluate(({ UKRYTE, MIN_PISMO_PX, MAX_ZDJECIE_PX }) => {
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
    const musi = { 'tytuł': 'h1', 'autor': '.przepis-autor .author-name', 'adres przepisu': '.przepis-adres-druk', 'porcje': '.przepis-liczby' };
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
    return { bledy, skladniki: document.querySelectorAll('.ingredient-list li').length,
      kroki: document.querySelectorAll('.step-list > li').length };
  }, { UKRYTE, MIN_PISMO_PX, MAX_ZDJECIE_PX });
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
        await strona.emulateMedia({ media: 'screen' });
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
console.log('Wydruk przepisu: wszystkie warianty czytelne na A4.');
