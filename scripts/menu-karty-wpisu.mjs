/*
 * =============================================================================
 *  Kuking.pl — regresja MENU „…" I POTWIERDZENIA USUNIĘCIA NA KARCIE WPISU
 *  (issue #1082)
 * =============================================================================
 *
 *  USTERKA. `.post-card` ma `overflow: hidden` (zaokrąglone rogi), a panel
 *  menu `.post-card-menu-tresc` jest `position: absolute`, więc nie powiększa
 *  karty. Na krótkim wpisie bez zdjęcia rozwinięte „Usuń wpis" → „Tak, usuń
 *  wpis" wychodziło poza dół karty i było ucinane: myszą nie do trafienia,
 *  a Tab przewijał TREŚĆ WEWNĄTRZ karty (nagłówek znikał nad jej krawędzią).
 *
 *  CO TO SPRAWDZA, w każdym wariancie:
 *    1. klawiaturą: Enter na „…", Enter na „Usuń wpis", Tab — fokus stoi na
 *       „Tak, usuń wpis";
 *    2. karta nie została przewinięta od środka (`scrollTop === 0`);
 *    3. po przewinięciu STRONY tak, żeby przycisk stał na środku okna, trzy
 *       punkty przycisku (środek, góra, dół w osi pionowej) trafiają w ten
 *       przycisk — `elementFromPoint`, czyli to, co naprawdę dostaje
 *       kliknięcie. Sama obecność w DOM ani prostokąt z
 *       `getBoundingClientRect()` tego nie dowodzą: prostokąt nie wie
 *       o przycięciu przez przodka.
 *
 *  Niczego nie usuwa — formularz nie jest wysyłany.
 *
 *  KONTROLA UJEMNA jest w tym samym przebiegu: skrypt dopisuje do
 *  `resources/css/app.css` przywrócenie przycinania karty, przebudowuje
 *  arkusz, oczekuje porażki z kodem MENU_KARTY i przywraca plik (suma
 *  kontrolna przed i po).
 *
 *  URUCHOMIENIE (z katalogu projektu):
 *      node scripts/menu-karty-wpisu.mjs
 *  Bez `ADRES` skrypt sam zakłada bazę, sieje dane, buduje arkusz i podnosi
 *  `php artisan serve`. Z `ADRES` kontroli ujemnej nie ma (nie ma czego
 *  przebudować na cudzym serwerze).
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, readFileSync, appendFileSync, copyFileSync, mkdtempSync, rmSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';
const TRESC_KROTKA = 'Zupa.';

/* Osobna baza — skrypt robi `migrate:fresh` (AGENTS.md §6). */
const BAZA_DOMYSLNA = 'kuking_menu_karty_wpisu';
const BAZOWA_CZCIONKA_PX = 16;

const WARIANTY = [
  { wpis: 'krótki bez zdjęcia', szerokosc: 1280, wysokosc: 800, pismo: 100 },
  { wpis: 'krótki bez zdjęcia', szerokosc: 1280, wysokosc: 800, pismo: 200 },
  { wpis: 'krótki bez zdjęcia', szerokosc: 390, wysokosc: 844, pismo: 100 },
  { wpis: 'krótki bez zdjęcia', szerokosc: 390, wysokosc: 844, pismo: 200 },
  { wpis: 'ze zdjęciem', szerokosc: 1280, wysokosc: 800, pismo: 100 },
];

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

/* Własny krótki wpis Ani, najnowszy w strumieniu — to jest przypadek
   z issue: karta bez zdjęcia, niższa niż rozwinięte menu. */
function dodajKrotkiWpis() {
  const php = `
    $u = App\\Models\\User::where('email', 'ania@example.test')->firstOrFail();
    App\\Models\\Post::factory()->create(['author_id' => $u->id, 'body' => ${JSON.stringify(TRESC_KROTKA)}, 'published_at' => now()->addMinute()]);
    echo 'ok';
  `;
  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: env() }).toString();

  if (! wynik.includes('ok')) throw new Error(`Nie udało się dodać wpisu. Wyjście tinkera: ${wynik}`);
}

async function podniesSerwer() {
  if (process.env.ADRES) return { adres: process.env.ADRES, zamknij: () => {} };

  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });
  console.log('Buduję arkusz (vite build)...');
  execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore', env: process.env });

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
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], { stdio: 'ignore', env: env() });
  dodajKrotkiWpis();

  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = await wolnyPort();
    const adres = `http://127.0.0.1:${port}`;
    /* `--no-reload` — patrz `scripts/port-projektu.mjs`. */
    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
      stdio: 'ignore',
      env: env(),
    });

    for (let i = 0; i < 60; i++) {
      try {
        if ((await fetch(`${adres}/health`)).ok) return { adres, zamknij: () => proces.kill('SIGTERM') };
      } catch { /* jeszcze nie wstał */ }
      await new Promise((r) => setTimeout(r, 500));
    }

    proces.kill('SIGKILL');
  }

  throw new Error('Nie udało się podnieść `php artisan serve`.');
}

const { adres, zamknij } = await podniesSerwer();
const przegladarka = await chromium.launch({ executablePath: znajdzChromium() });

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

async function sprawdz(wariant) {
  const opis = `${wariant.wpis}, ${wariant.szerokosc} px, pismo ${wariant.pismo}%`;
  const kontekst = await przegladarka.newContext({
    viewport: { width: wariant.szerokosc, height: wariant.wysokosc },
    storageState: sesja,
  });

  try {
    const strona = await kontekst.newPage();

    if (wariant.pismo !== 100) {
      const cdp = await kontekst.newCDPSession(strona);
      const px = BAZOWA_CZCIONKA_PX * wariant.pismo / 100;

      await cdp.send('Page.setFontSizes', { fontSizes: { standard: px, fixed: px } });
    }

    await strona.goto(`${adres}/home`, { waitUntil: 'networkidle' });

    /* Podpowiedź „Dopasuj rozmiar tekstu" przykrywa róg strony przy
       pierwszej wizycie — zamykamy ją tak, jak zrobiłby to człowiek. */
    const podpowiedz = strona.locator('[data-wyglad-pomin]');
    if (await podpowiedz.isVisible().catch(() => false)) await podpowiedz.click();

    const karta = wariant.wpis === 'ze zdjęciem'
      ? strona.locator('article.post-card:has(> .photo-grid, > .karuzela, > .kolaz):has(.confirm)').first()
      : strona.locator('article.post-card', { hasText: TRESC_KROTKA }).filter({ has: strona.locator('.confirm') }).first();

    if (await karta.count() === 0) throw new Error(`BŁĄD DANYCH (${opis}): nie ma karty „${wariant.wpis}" z menu autora.`);

    await karta.locator('.post-card-menu > summary').focus();
    await strona.keyboard.press('Enter');
    await karta.locator('.confirm-summary').focus();
    await strona.keyboard.press('Enter');

    /* Ile panel wystaje poza dół karty — PRZED Tab, bo Tab przy przycinaniu
       przewija kartę od środka i ta liczba przestaje cokolwiek znaczyć. */
    const wystaje = await karta.evaluate((a) => Math.round(
      a.querySelector('.post-card-menu-tresc').getBoundingClientRect().bottom - a.getBoundingClientRect().bottom,
    ));

    await strona.keyboard.press('Tab');

    const wynik = await karta.evaluate((a) => {
      const przycisk = a.querySelector('.confirm-body button[type="submit"]');
      const naFokusie = document.activeElement === przycisk;
      const przewinietaOdSrodka = a.scrollTop;

      const p0 = przycisk.getBoundingClientRect();
      window.scrollBy(0, (p0.top + p0.bottom) / 2 - window.innerHeight / 2);

      const p = przycisk.getBoundingClientRect();
      const x = (p.left + p.right) / 2;
      const punkty = { srodek: [x, (p.top + p.bottom) / 2], gora: [x, p.top + 3], dol: [x, p.bottom - 3] };
      const chybione = Object.entries(punkty)
        .filter(([, [px, py]]) => { const el = document.elementFromPoint(px, py); return ! el || ! (el === przycisk || przycisk.contains(el)); })
        .map(([nazwa, [px, py]]) => {
          const el = document.elementFromPoint(px, py);
          return `${nazwa} → ${el ? el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(' ').join('.') : '') : 'nic'}`;
        });

      return {
        naFokusie,
        przewinietaOdSrodka,
        chybione,
        wysokoscKarty: Math.round(a.getBoundingClientRect().height),
      };
    });

    const bledy = [];
    if (! wynik.naFokusie) bledy.push('Tab po „Usuń wpis" nie prowadzi do „Tak, usuń wpis"');
    if (wynik.przewinietaOdSrodka !== 0) bledy.push(`karta przewinięta od środka o ${wynik.przewinietaOdSrodka} px`);
    if (wynik.chybione.length > 0) bledy.push(`przycisk przykryty albo ucięty: ${wynik.chybione.join(', ')}`);

    console.log(`${bledy.length === 0 ? 'OK ' : 'ŹLE'}  ${opis}: karta ${wynik.wysokoscKarty} px, `
      + `panel menu wystaje ${wystaje} px poniżej dołu karty`);

    if (bledy.length > 0) throw new Error(`MENU_KARTY (${opis}): ${bledy.join('; ')}`);
  } finally {
    await kontekst.close();
  }
}

async function wszystkie() {
  for (const wariant of WARIANTY) await sprawdz(wariant);
}

const zrodlo = 'resources/css/app.css';
const skrot = () => createHash('sha256').update(readFileSync(zrodlo)).digest('hex').slice(0, 16);
const przed = skrot();
const folder = mkdtempSync(join(tmpdir(), 'menu-karty-'));
const kopia = join(folder, 'app.css');

copyFileSync(zrodlo, kopia);

try {
  await wszystkie();

  if (! process.env.ADRES) {
    let wykryto = false;

    try {
      appendFileSync(zrodlo, '\n.post-card:has(.post-card-menu[open]) { overflow: hidden; }\n');
      console.log(`MENU_UJEMNA przed=${przed} zmieniony=${skrot()}`);
      execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
      await wszystkie();
    } catch (blad) {
      if (! String(blad.message).startsWith('MENU_KARTY')) throw blad;
      wykryto = true;
      console.log(`MENU_UJEMNA wykryto: ${blad.message}`);
    } finally {
      copyFileSync(kopia, zrodlo);
      execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
      if (skrot() !== przed) throw new Error('Nie przywrócono resources/css/app.css');
      console.log(`MENU_UJEMNA przywrocony=${skrot()}`);
    }

    if (! wykryto) throw new Error('Kontrola ujemna nie wykryła przycięcia menu karty (MENU_KARTY).');

    await wszystkie();
  }
} finally {
  copyFileSync(kopia, zrodlo);
  rmSync(folder, { recursive: true, force: true });
  await przegladarka.close();
  zamknij();
}
