/* Pomiar portu na prawdziwych stronach Laravel i danych demonstracyjnych. */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa — ten skrypt robi `migrate:fresh`. Wskazanie `kuking`
   albo `kuking_test` kasowałoby czyjąś pracę (AGENTS.md §6). */
const BAZA_DOMYSLNA = 'kuking_focus_pomiar';

/* Domyślny rozmiar pisma przeglądarki; wariant 200% ustawia dwa razy tyle
   przez CDP `Page.setFontSizes`. To jest EMULACJA CZCIONKI BAZOWEJ, a nie
   powiększenie strony: piksel CSS zostaje ten sam, rosną tylko `rem` i `em`. */
const BAZOWA_CZCIONKA_PX = 16;

/* Znacznik wariantu „czcionka przeglądarki podwojona" — celowo NIE liczba,
   bo liczby znaczą tu `data-text-scale`, czyli nasze ustawienie z profilu.
   To są dwa różne mechanizmy (patrz komentarz w `scripts/dostepnosc.mjs`). */
const PRZEGLADARKA_200 = 'przegladarka-200';

const SZEROKOSCI = [
  { nazwa: '320 px', szerokosc: 320, wysokosc: 740 },
  { nazwa: '360 px', szerokosc: 360, wysokosc: 740 },
  { nazwa: '390 px', szerokosc: 390, wysokosc: 740 },
  { nazwa: '414 px', szerokosc: 414, wysokosc: 740 },
  { nazwa: '768 px', szerokosc: 768, wysokosc: 740 },
];

/* Wysokość okna 740 px w KAŻDYM wariancie, nie „naturalna" dla danego
   telefonu: pomiar mówi o stosunku wysokości kafla do wysokości okna, więc
   zmienna wysokość okna mieszałaby dwie przyczyny w jednej liczbie. 740 to ta
   sama wysokość, na której mierzy focus `scripts/dostepnosc.mjs`, żeby dało
   się liczby z obu plików położyć obok siebie. */

const WARIANTY_PISMA = [null, 140, PRZEGLADARKA_200];

function etykietaPisma(wariant) {
  if (wariant === null) return '100%';

  return wariant === PRZEGLADARKA_200 ? 'przeglądarka 200%' : `tekst ${wariant}%`;
}

function znajdzChromium() {
  if (process.env.CHROMIUM_PATH) return process.env.CHROMIUM_PATH;
  try {
    const wlasna = chromium.executablePath();
    if (wlasna && existsSync(wlasna)) return undefined;
  } catch { /* idziemy dalej */ }
  const deweloperska = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

  return existsSync(deweloperska) ? deweloperska : undefined;
}

function env() {
  return { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA };
}

async function wolnyPort() {
  const { createServer } = await import('node:net');

  return new Promise((resolve, reject) => {
    const gniazdo = createServer();
    gniazdo.unref();
    gniazdo.on('error', reject);
    gniazdo.listen(0, '127.0.0.1', () => {
      const { port } = gniazdo.address();
      gniazdo.close(() => resolve(port));
    });
  });
}

async function podniesSerwer() {
  if (process.env.ADRES) return { adres: process.env.ADRES, zamknij: () => {} };

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  /* Strona wciąga zbudowany `public/build/assets/app-*.css` przez manifest
     Vite — pomiar bez przebudowania opisywałby POPRZEDNIĄ wersję arkusza. */
  console.log('Buduję arkusz (vite build)...');
  execFileSync('npm', ['run', 'build'], { stdio: 'ignore', env: process.env });

  try {
    execFileSync('createdb', [env().DB_DATABASE], {
      stdio: 'ignore',
      env: {
        ...process.env,
        PGHOST: process.env.DB_HOST || '127.0.0.1',
        PGUSER: process.env.DB_USERNAME || 'kuking',
        PGPASSWORD: process.env.DB_PASSWORD || 'kuking',
      },
    });
  } catch { /* baza już istnieje */ }

  console.log(`Przygotowuję dane demonstracyjne w bazie ${env().DB_DATABASE}...`);
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const bledy = [];

  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = await wolnyPort();
    const adres = `http://127.0.0.1:${port}`;
    const dziennik = [];

    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
      stdio: ['ignore', 'pipe', 'pipe'],
      env: env(),
    });

    proces.stdout.on('data', (b) => dziennik.push(String(b)));
    proces.stderr.on('data', (b) => dziennik.push(String(b)));

    let umarl = null;
    proces.on('exit', (kod) => { umarl = kod; });

    let wstal = false;

    for (let i = 0; i < 60 && umarl === null; i++) {
      try {
        const odp = await fetch(`${adres}/health`);
        if (odp.ok) { wstal = true; break; }
      } catch { /* jeszcze nie wstał */ }
      await new Promise((r) => setTimeout(r, 500));
    }

    if (wstal) return { adres, zamknij: () => proces.kill('SIGTERM') };

    proces.kill('SIGKILL');
    bledy.push(`  podejście ${podejscie}, port ${port}: `
      + (umarl !== null ? `proces zakończył się kodem ${umarl}` : 'brak odpowiedzi z /health')
      + (dziennik.length > 0 ? `\n${dziennik.join('').trimEnd()}` : ''));
  }

  throw new Error(`Nie udało się podnieść \`php artisan serve\`.\n${bledy.join('\n')}`);
}


const CHROMIUM = znajdzChromium();
const { adres, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/* Logujemy się RAZ i przenosimy ciasteczko przez `storageState`: piętnaście
   logowań pod rząd z jednego adresu trafia na `throttle` i pomiar leciałby
   na ekranie „Za dużo prób" zamiast na tablicy. */
const kontekstLogowania = await przegladarka.newContext({ viewport: { width: 390, height: 844 } });
const stronaLogowania = await kontekstLogowania.newPage();

await stronaLogowania.goto(`${adres}/login`);
await stronaLogowania.fill('input[name="login"]', KONTO);
await stronaLogowania.fill('input[name="password"]', HASLO);
await Promise.all([
  stronaLogowania.waitForURL((u) => ! u.pathname.endsWith('/login'), { timeout: 15000 }),
  stronaLogowania.click('button[type="submit"]'),
]);

const sesja = await kontekstLogowania.storageState();

await kontekstLogowania.close();



import { readFileSync, appendFileSync, mkdtempSync, rmSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
const MAKS_KROKOW_FOCUS = 400;
async function przejdzTabemIZmierzFocus(strona) {
  const kroki = [];

  for (let krok = 0; krok < MAKS_KROKOW_FOCUS; krok++) {
    await strona.keyboard.press('Tab');

    const stan = await strona.evaluate(() => {
      const el = document.activeElement;

      if (!el || el === document.body || el === document.documentElement) {
        return { koniec: true };
      }

      if (el.dataset.a11yFokusWidziany === '1') {
        return { petla: true };
      }
      el.dataset.a11yFokusWidziany = '1';

      const ramka = el.getBoundingClientRect();

      function pokrycieNakladki(selektor) {
        const nakladka = document.querySelector(selektor);
        if (!nakladka) return null;

        const styl = getComputedStyle(nakladka);
        if (styl.display === 'none' || styl.visibility === 'hidden') return null;

        // Kontrolka jest częścią samej belki — to nie jest "zasłonięcie
        // treści", to sama belka.
        if (nakladka.contains(el)) return 0;

        if (ramka.width === 0 || ramka.height === 0) return 0;

        /*
         * SIATKĘ ROZKŁADAMY NA CZĘŚCI WIDOCZNEJ, NIE NA CAŁYM PROSTOKĄCIE.
         *
         * Pierwsza wersja rozkładała 4×4 punkty na całej ramce kontrolki
         * i POMIJAŁA te, które wypadły poza okno. Przy kontrolce WYŻSZEJ
         * NIŻ OKNO zostawał z tego jeden rząd próbek w zupełnie przypadkowym
         * miejscu. Zmierzone na `/home` przy 320 px i czcionce przeglądarki
         * 200%: odnośnik „Dodaj zdjęcie tego dnia" ma tam 2087,1 px wysokości
         * (ramka od -882 do 1205,1 px), a z czterech rzędów siatki w oknie
         * leżał JEDEN — ten na wysokości 422,4 px, czyli wewnątrz dolnej
         * belki. Wynik: „zasłonięte w 100%" dla kontrolki, której 364 px
         * widać nad belką jak na dłoni. To jest FAŁSZYWY ALARM, i to
         * dokładnie tej klasy, przed którą ostrzega zlecenie audytu:
         * kryterium 2.4.11 mówi „not entirely hidden", a kontrolka wyższa
         * od okna nie może być schowana w całości pod belką, która zajmuje
         * część okna.
         *
         * Przycięcie ramki do okna PRZED rozłożeniem siatki daje próbki
         * reprezentatywne dla tego, co człowiek naprawdę widzi, niezależnie
         * od wysokości kontrolki. Część poza oknem to inny problem
         * (przewinięcie poza widok) i pozostaje poza zakresem tego
         * sprawdzenia — tak jak dotąd.
         */
        const lewa = Math.max(ramka.left, 0);
        const gora = Math.max(ramka.top, 0);
        const prawa = Math.min(ramka.right, innerWidth);
        const dol = Math.min(ramka.bottom, innerHeight);

        // Kontrolka w całości poza oknem — nie ma czego mierzyć.
        if (prawa <= lewa || dol <= gora) return null;

        // Siatka 4×4: dość gęsto, żeby złapać częściowe pokrycie, dość
        // rzadko, żeby nie mnożyć kosztu `elementFromPoint` na setkach
        // kroków Taba.
        const SIATKA = 4;
        let zaslonietych = 0;

        for (let iy = 0; iy < SIATKA; iy++) {
          for (let ix = 0; ix < SIATKA; ix++) {
            const x = lewa + ((prawa - lewa) * (ix + 0.5)) / SIATKA;
            const y = gora + ((dol - gora) * (iy + 0.5)) / SIATKA;

            const trafiony = document.elementFromPoint(x, y);

            if (trafiony && nakladka.contains(trafiony) && !el.contains(trafiony)) {
              zaslonietych++;
            }
          }
        }

        // Wszystkie próbki leżą teraz w oknie, więc mianownik jest stały —
        // licznik odrzuconych punktów, który stał tu wcześniej, nie miałby
        // już czego liczyć.
        return zaslonietych / (SIATKA * SIATKA);
      }

      const prostokat = (element) => element ? element.getBoundingClientRect().toJSON() : null;
      const geometria = {
        klasa: el.className,
        href: el.getAttribute('href'),
        rodzic: el.parentElement?.className,
        ramka: prostokat(el),
        topbar: prostokat(document.querySelector('.topbar')),
        bottomNav: prostokat(document.querySelector('.bottom-nav')),
        scrollPaddingTop: getComputedStyle(document.documentElement).scrollPaddingTop,
        scrollPaddingBottom: getComputedStyle(document.documentElement).scrollPaddingBottom,
      };

      const opis = `${el.tagName.toLowerCase()}`
        + (el.id ? `#${el.id}` : '')
        + (el.getAttribute('aria-label') ? ` [aria-label="${el.getAttribute('aria-label')}"]` : '')
        + (el.textContent?.trim() ? ` „${el.textContent.trim().slice(0, 40)}"` : '');

      return {
        opis,
        geometria,
        pokrycieTopbar: pokrycieNakladki('.topbar'),
        pokrycieBottomNav: pokrycieNakladki('.bottom-nav'),
      };
    });

    if (stan.koniec || stan.petla) {
      return { kroki, pelnyPrzebieg: true };
    }

    kroki.push(stan);
  }

  return { kroki, pelnyPrzebieg: false };
}

async function fokus(width, scale, path, dark = false) {
  const context = await przegladarka.newContext({ storageState: sesja, viewport: { width, height: 740 }, reducedMotion: 'reduce' });
  try {
    const page = await context.newPage();
    if (scale === 200) {
      const cdp = await context.newCDPSession(page);
      await cdp.send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
    }
    const response = await page.goto(adres + path, { waitUntil: 'networkidle' });
    if (response.status() !== 200) throw new Error('HTTP ' + response.status());
    await page.evaluate(async ({ scale, dark }) => {
      document.documentElement.dataset.textScale = scale === 140 ? '140' : '100';
      document.documentElement.dataset.theme = dark ? 'dark' : 'light';
      await document.fonts.ready;
      await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
    }, { scale, dark });
    const bodyFont = await page.evaluate(() => parseFloat(getComputedStyle(document.body).fontSize));
    const expected = scale === 200 ? 36 : 18 * scale / 100;
    if (Math.abs(bodyFont - expected) > 0.5) throw new Error('Nie zastosowano skali tekstu');
    const oczekiwane = await page.locator('.kuking-board-post-link').evaluateAll(els => els.map(el => ({
      href: el.getAttribute('href'), nazwa: el.textContent.trim(),
      widoczny: getComputedStyle(el).visibility !== 'hidden' && el.getBoundingClientRect().width > 0,
    })));
    if (!oczekiwane.some(el => el.nazwa === 'Małgorzata Konstantynopolitańczykowianka')) throw new Error('FOKUS_ZESTAW brak długiej nazwy w danych');
    if (oczekiwane.some(el => !el.widoczny)) throw new Error('FOKUS_ZESTAW ukryta karta');
    const { kroki, pelnyPrzebieg } = await przejdzTabemIZmierzFocus(page);
    if (!pelnyPrzebieg) throw new Error('Niepełna kolejność Tab');
    const linki = kroki.filter(k => String(k.geometria?.klasa).split(' ').includes('kuking-board-post-link'));
    if (JSON.stringify(linki.map(k => k.geometria.href).sort()) !== JSON.stringify(oczekiwane.map(k => k.href).sort())) throw new Error('FOKUS_ZESTAW Tab pominął kartę');
    for (const link of linki) {
      const g = link.geometria.ramka;
      if (link.pokrycieTopbar > 0 || link.pokrycieBottomNav > 0 || g.top < 0 || g.bottom > 740) {
        throw new Error('FOKUS_KARTY ' + JSON.stringify({ width, scale, path, dark, ...link }));
      }
    }
    console.log('FOKUS_KARTY_OK ' + JSON.stringify({ width, scale, path, dark, liczba: linki.length }));
  } finally { await context.close(); }
}

async function klik(fragment) {
  const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  try {
    const page = await context.newPage();
    await page.goto(adres + '/home', { waitUntil: 'networkidle' });
    const row = page.locator('.kuking-board-post-row').first();
    const href = await row.locator('a.kuking-board-post-link').getAttribute('href');
    if (!href) throw new Error('Brak docelowego wpisu');
    const target = fragment === 'wolne-miejsce' ? row : row.locator(fragment).first();
    await target.scrollIntoViewIfNeeded();
    const box = await target.boundingBox();
    if (!box) throw new Error('Brak fragmentu karty: ' + fragment);
    const x = fragment === 'wolne-miejsce' ? box.x + box.width - 2 : box.x + box.width / 2;
    const y = fragment === 'wolne-miejsce' ? box.y + box.height - 2 : box.y + box.height / 2;
    try {
      await Promise.all([
        page.waitForURL(url => url.pathname === new URL(href, adres).pathname, { timeout: 5000 }),
        page.mouse.click(x, y),
      ]);
    } catch (error) {
      if (error.name === 'TimeoutError') throw new Error('KLIK_KARTY ' + fragment);
      throw error;
    }
    console.log('KLIK_KARTY_OK ' + fragment);
  } finally { await context.close(); }
}

const source = 'resources/css/app.css';
const folder = mkdtempSync(tmpdir() + '/kuking-fokus-');
const backup = folder + '/app.css';
const hash = () => createHash('md5').update(readFileSync(source)).digest('hex');
const before = hash();
execFileSync('cp', [source, backup]);
try {
  for (const width of [320, 360, 414]) {
    for (const scale of [100, 140, 200]) {
      for (const dark of [false, true]) {
        for (const path of ['/home', '/szukaj']) await fokus(width, scale, path, dark);
      }
    }
  }
  for (const fragment of ['.kuking-board-post-photo', '.kuking-board-excerpt', 'wolne-miejsce']) await klik(fragment);
  for (const [css, code, check] of [
    ['\n[data-marka] .kuking-board-post-link { min-height: 900px; }\n', 'FOKUS_KARTY', () => fokus(320, 140, '/home')],
    ['\n[data-marka] .kuking-board-post-link::after { content: none; }\n', 'KLIK_KARTY', () => klik('.kuking-board-post-photo')],
    ['\n[data-marka] .kuking-board-post:first-child .kuking-board-post-link { visibility: hidden; }\n', 'FOKUS_ZESTAW', () => fokus(320, 140, '/home')],
  ]) {
    try {
      appendFileSync(source, css);
      console.log('FOKUS_UJEMNA przed=' + before + ' zmieniony=' + hash());
      execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
      let caught = false;
      try { await check(); }
      catch (error) { if (error.message.startsWith(code)) { caught = true; console.log('FOKUS_UJEMNA wykryto=' + code); } else throw error; }
      if (!caught) throw new Error('Kontrola ujemna nie wykryła ' + code);
    } finally {
      execFileSync('cp', [backup, source]);
      execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
      if (hash() !== before) throw new Error('Nie przywrócono źródła');
      console.log('FOKUS_UJEMNA przywrocony=' + hash());
    }
    await check();
  }
} finally {
  execFileSync('cp', [backup, source]);
  if (hash() !== before) throw new Error('Źródło po pomiarze jest zmienione');
  rmSync(folder, { recursive: true, force: true });
  await przegladarka.close();
  zamknij();
}
