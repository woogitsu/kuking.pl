/*
 * =============================================================================
 *  Kuking.pl — pomiar PODGLĄDU ZDJĘCIA JESZCZE PRZED WYSŁANIEM (issue #430)
 * =============================================================================
 *
 *  PO CO TO JEST
 *  Wgranie zdjęcia z telefonu to 6,14 MB, które muszą dojechać na serwer.
 *  Żeby ten transfer zmieścił się w 400 ms, trzeba by mieć ~130 Mbps w górę;
 *  przy typowym LTE trwa on kilka sekund. Przez te kilka sekund człowiek
 *  patrzy na formularz i nie wie, czy cokolwiek się dzieje.
 *
 *  `resources/js/app.js` pokazuje w tym czasie zdjęcie WPROST Z PAMIĘCI
 *  PRZEGLĄDARKI (`URL.createObjectURL`) — bez ani jednego bajtu z sieci.
 *  Ten skrypt sprawdza, że to naprawdę działa i JAK WYGLĄDA: źródło `blob:`,
 *  czas od wyboru pliku, szerokość obrazka względem okna i brak naruszeń CSP.
 *
 *  CZEGO NIE ZROBI ZA NIEGO `php artisan test`
 *  Tu wszystko dzieje się w przeglądarce: zdarzenie `change`, `blob:`,
 *  siatka CSS i `img-src` z nagłówka CSP. Test w PHPUnit widzi HTML sprzed
 *  wykonania skryptu, więc o tym ekranie nie powie nic.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/podglad-przed-wyslaniem.mjs
 *      ADRES=http://127.0.0.1:8123 node scripts/podglad-przed-wyslaniem.mjs
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';
const BAZA_DOMYSLNA = 'kuking_podglad_wyboru';

/* Szerokości telefonów z warunku właściciela dla usterek mobilnych. */
const SZEROKOSCI = [320, 360, 390, 414];

/* Ile MIEJSCA MA ZAJĄĆ jedno zdjęcie w podglądzie — ułamek szerokości
   kontenera. Poniżej tego progu skrypt kończy się błędem: dokładnie tak
   wyglądała usterka sprzed 12 września 2026 (150 px z 390 px okna). */
const MINIMALNY_UDZIAL = 0.9;

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

/*
 * ZDJĘCIE W ROZMIARZE Z TELEFONU, nie miniaturka.
 *
 * Podgląd z pamięci przeglądarki ma pokazać, że rozmiar pliku NIE MA
 * znaczenia dla czasu pojawienia się obrazka. Na pliku 20 kB nie byłoby tego
 * widać — i pomiar mówiłby o czymś innym, niż mówi zgłoszenie.
 */
function zrobZdjecieProbne() {
  const katalog = mkdtempSync(join(tmpdir(), 'kuking-podglad-'));
  const plik = join(katalog, 'proba.jpg');

  execFileSync('php', ['-r', `
    $i = imagecreatetruecolor(4032, 3024);
    for ($x = 0; $x < 4032; $x += 8) {
      imagefilledrectangle($i, $x, 0, $x + 7, 3023, imagecolorallocate($i, $x % 255, (2 * $x) % 255, 120));
    }
    imagejpeg($i, '${plik}', 92);
  `]);

  return plik;
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

  /* Skrypt renderujący podgląd jest w ZBUDOWANYM `public/build/assets/app-*.js`.
     Pomiar bez przebudowania opisywałby poprzednią wersję. */
  console.log('Buduję skrypt i arkusz (vite build)...');
  execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore', env: process.env });

  console.log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const port = await wolnyPort();
  const adres = `http://127.0.0.1:${port}`;
    /* `--no-reload` — patrz `scripts/port-projektu.mjs`: bez niego `artisan serve`
       wycina procesowi `php -S` zmienne środowiska joba i aplikacja spada na
       `.env`, czyli na współdzielony port 5432. */
  const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
    stdio: 'ignore',
    env: env(),
  });

  for (let i = 0; i < 60; i++) {
    try {
      if ((await fetch(`${adres}/health`)).ok) {
        return { adres, zamknij: () => proces.kill('SIGTERM') };
      }
    } catch { /* jeszcze nie wstał */ }
    await new Promise((r) => setTimeout(r, 500));
  }

  proces.kill('SIGKILL');
  throw new Error('Nie udało się podnieść `php artisan serve`.');
}

const CHROMIUM = znajdzChromium();
const PLIK = zrobZdjecieProbne();
const { adres, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/* Logujemy się RAZ i przenosimy ciasteczko: serwis ma `throttle` na
   logowaniu, a kilka prób pod rząd kończy się ekranem „Za dużo prób"
   — pomiar leciałby wtedy na ekranie blokady. */
const kontekstLogowania = await przegladarka.newContext();
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

let bylBlad = false;

console.log('\nokno    źródło   obrazek / kontener   udział   czas    CSP');
console.log('-'.repeat(62));

try {
  for (const szerokosc of SZEROKOSCI) {
    const kontekst = await przegladarka.newContext({
      viewport: { width: szerokosc, height: 844 },
      storageState: sesja,
    });
    const strona = await kontekst.newPage();
    const naruszeniaCsp = [];

    strona.on('console', (m) => {
      if (/Content Security Policy|Refused to load/i.test(m.text())) naruszeniaCsp.push(m.text());
    });

    await strona.goto(`${adres}/dodaj/zdjecie`, { waitUntil: 'domcontentloaded' });

    const czas = Date.now();

    await strona.setInputFiles('#f-photos', PLIK);
    await strona.waitForSelector('#f-photos-podglad img', { timeout: 5000 });

    const ms = Date.now() - czas;
    const wynik = await strona.evaluate(() => {
      const obraz = document.querySelector('#f-photos-podglad img');
      const pojemnik = document.querySelector('#f-photos-podglad');

      return {
        zrodlo: obraz.src.slice(0, obraz.src.indexOf(':') + 1),
        obrazek: Math.round(obraz.getBoundingClientRect().width),
        kontener: Math.round(pojemnik.getBoundingClientRect().width),
        wyjazdWBok: document.documentElement.scrollWidth > window.innerWidth,
      };
    });

    const udzial = wynik.obrazek / wynik.kontener;
    const zle = wynik.zrodlo !== 'blob:'
      || udzial < MINIMALNY_UDZIAL
      || wynik.wyjazdWBok
      || naruszeniaCsp.length > 0;

    if (zle) bylBlad = true;

    console.log(
      `${String(szerokosc).padEnd(8)}${wynik.zrodlo.padEnd(9)}`
      + `${String(wynik.obrazek).padStart(4)} / ${String(wynik.kontener).padEnd(11)}`
      + `${(100 * udzial).toFixed(0).padStart(5)}%${String(ms).padStart(7)} ms`
      + `${String(naruszeniaCsp.length).padStart(5)}`
      + (wynik.wyjazdWBok ? '   WYJAZD W BOK' : '')
      + (zle ? '   <-- ŹLE' : ''),
    );

    await kontekst.close();
  }
} finally {
  await przegladarka.close();
  zamknij();
}

if (bylBlad) {
  console.error(`\nBŁĄD: podgląd ma pochodzić z pamięci przeglądarki (\`blob:\`), zajmować co `
    + `najmniej ${Math.round(100 * MINIMALNY_UDZIAL)}% szerokości swojego pojemnika, nie wypychać `
    + 'strony w bok i nie naruszać CSP.');
  process.exit(1);
}
