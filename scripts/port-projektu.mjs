/* Pomiar portu na prawdziwych stronach Laravel i danych demonstracyjnych. */
import { wybierzGrupe, wykonajGrupe } from './port-grupy.mjs';
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
/* `readFileSync` pod własną nazwą: kilkaset linii niżej ten sam plik wciąga
   `node:fs` drugi raz przez `await import(...)` i rozpakowuje z niego
   `readFileSync` do stałej modułu. Zwykły import kolidowałby z tamtą stałą. */
import { existsSync, readFileSync as czytajPlik } from 'node:fs';
import { sprawdzKompozycje } from './kompozycje-marki.mjs';
import { sprawdzZoomMarki } from './zoom-marki.mjs';
import { sprawdzKompozycje513 } from './zainteresowania-powiadomienia-marki.mjs';
import { sprawdzZeszyty } from './zeszyty-marki.mjs';
import { sprawdzPodpowiedzi } from './kontrast-notice.mjs';
import { sprawdzNawigacje492 } from './nawigacja-niski-widok.mjs';
import { sprawdzTagi } from './tagi-marki.mjs';
import { sprawdzSzybkiWyglad } from './szybki-wyglad.mjs';
import { sprawdzPasek } from './pasek-przewijany.mjs';
import { sprawdzZwarteKolumny } from './zwarte-kolumny.mjs';
import { sprawdzListeOsob } from './lista-osob-szerokosc.mjs';
import { sprawdzPrzyciskRejestracji } from './przycisk-rejestracji.mjs';
import { sprawdzHeroNadZgieciem } from './hero-nad-zgieciem.mjs';
import { sprawdzInstalacjePwa } from './pwa-install-browser.mjs';
import { sprawdzMacierzNawigacji } from './nawigacja-etykiety.mjs';
import { sprawdzZoomNawigacji } from './nawigacja-zoom.mjs';
import { sprawdzStopke } from './stopka-pusty-pas.mjs';
import { wymagajStanu } from './lib/stan-ustalony.mjs';

const grupa = wybierzGrupe(process.env.PORT_GRUPA);
const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa — ten skrypt robi `migrate:fresh`. Wskazanie `kuking`
   albo `kuking_test` kasowałoby czyjąś pracę (AGENTS.md §6). */
const BAZA_DOMYSLNA = 'kuking_port_pomiar';

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

/* Ogon dziennika aplikacji — dokładany do komunikatu, kiedy serwer nie wstał.
   Powód jest taki sam jak przy zapisywaniu ostatniej odpowiedzi, tylko o krok
   dalej: przy `APP_DEBUG=false` (a tak chodzi CI) strona błędu 500 NIE NIESIE
   ani nazwy wyjątku, ani komunikatu — samo „Coś poszło nie tak". Wyjątek jest
   w `storage/logs/laravel.log`, a ten plik na runnerze znika przy następnym
   `actions/checkout` (`git clean -ffdx` kasuje pliki pominięte przez gita).
   Czyli po nieudanym przebiegu nie da się go już odzyskać — trzeba go
   przepisać OD RAZU, w tym samym procesie, który zauważył porażkę.

   OSTATNI WPIS, A NIE OSTATNIE N WIERSZY. Wyjątek Laravela zajmuje w tym
   pliku kilkadziesiąt wierszy, z czego pierwszy niesie nazwę i komunikat,
   a cała reszta to ramki stosu z `vendor/`. Ogon liczony wierszami pokazywał
   więc wyłącznie `#38 … Pipeline->handle()` — prawdę o niczym. Bierzemy
   NAGŁÓWEK ostatniego wpisu i kilka pierwszych ramek pod nim. */
function ogonDziennika(ileRamek = 5) {
  const sciezka = 'storage/logs/laravel.log';

  try {
    if (!existsSync(sciezka)) return 'dziennik aplikacji nie powstał';

    const wiersze = czytajPlik(sciezka, 'utf8').split('\n').filter((w) => w.trim() !== '');

    if (wiersze.length === 0) return 'dziennik aplikacji jest pusty';

    /* Nagłówek wpisu zaczyna się od daty w nawiasie kwadratowym; wszystko
       inne to kontynuacja poprzedniego wpisu. */
    const naglowki = wiersze
      .map((wiersz, i) => (/^\[\d{4}-\d{2}-\d{2}/.test(wiersz) ? i : -1))
      .filter((i) => i !== -1);

    if (naglowki.length === 0) return wiersze.slice(-ileRamek).join('\n');

    const od = naglowki[naglowki.length - 1];

    return wiersze.slice(od, od + 1 + ileRamek).map((w) => w.slice(0, 500)).join('\n');
  } catch (blad) {
    return `dziennika nie dało się odczytać: ${blad.message}`;
  }
}

async function podniesSerwer() {
  if (process.env.ADRES) return { adres: process.env.ADRES, zamknij: () => {} };

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  /* Strona wciąga zbudowany `public/build/assets/app-*.css` przez manifest
     Vite — pomiar bez przebudowania opisywałby POPRZEDNIĄ wersję arkusza. */
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
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const bledy = [];

  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = await wolnyPort();
    const adres = `http://127.0.0.1:${port}`;
    const dziennik = [];

    /* `--no-reload` NIE JEST TU OPTYMALIZACJĄ — ono ratuje całe środowisko.
       `ServeCommand::startProcess()` mapuje `$_ENV` i każdej zmiennej spoza
       swojej krótkiej listy przepustek (APP_ENV, PATH, XDEBUG_*, kilka HERD_*)
       podstawia `false`, czyli WYCINA JĄ procesowi `php -S`. Wyjątek robi
       dokładnie dla `--no-reload`.

       Bez tej flagi zachowanie zależy od `variables_order` w php.ini: przy
       `GPCS` tablica `$_ENV` jest pusta, mapa wychodzi pusta i dziecko
       dziedziczy wszystko, więc wszystko działa. Przy `EGPCS` — a tak bywa na
       runnerze — wycinanie wchodzi w życie i serwowana aplikacja spada na
       wartości z `.env`, czyli `DB_PORT=5432`, `DB_DATABASE=kuking`
       i `SESSION_DRIVER=database`. Na maszynie z CI na porcie 5432 naprawdę
       stoi współdzielony klaster (AGENTS.md §6 zabrania go używać), więc
       zapytanie o sesję kończyło się `password authentication failed`,
       nieobsłużonym wyjątkiem i HTTP 500 na KAŻDYM żądaniu — także na
       `/health`. Stąd „brak odpowiedzi z /health" przy serwerze, który stał
       i grzecznie odpowiadał (issue #684 nie ma z tym nic wspólnego; to był
       ten sam objaw w jobach `Port marki` i `Dostępność`).

       Zmierzone na trzech wariantach tego samego żądania `/health`:
       `GPCS` bez flagi → 200, `EGPCS` bez flagi → 500, `EGPCS` z flagą → 200.
       `scripts/panel-marki-run.mjs` miał tę flagę od początku i jako jedyny
       job przeglądarkowy nie padał. */
    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
      stdio: ['ignore', 'pipe', 'pipe'],
      // Ochrona przekierowań porównuje host z app.url, także w lokalnym pomiarze.
      env: { ...env(), APP_URL: adres },
    });

    proces.stdout.on('data', (b) => dziennik.push(String(b)));
    proces.stderr.on('data', (b) => dziennik.push(String(b)));

    let umarl = null;
    proces.on('exit', (kod) => { umarl = kod; });

    let wstal = false;
    /* OSTATNIA ODPOWIEDŹ, NIE SAM FAKT PORAŻKI. Do 19 września 2026 pętla
       wyrzucała odpowiedź w całości i komunikat mówił tylko „brak odpowiedzi
       z /health". Serwer tymczasem ODPOWIADAŁ — `/health` oddaje 503, gdy
       padnie `database` albo `migrations` — a przyczyna stała w treści, której
       nikt nie zapisywał. Cztery przebiegi CI i kilka godzin poszły na
       zgadywanie, co w tej odpowiedzi było. Teraz idzie do dziennika. */
    let ostatnia = null;

    for (let i = 0; i < 60 && umarl === null; i++) {
      try {
        const odp = await fetch(`${adres}/health`);
        if (odp.ok) { wstal = true; break; }
        ostatnia = `HTTP ${odp.status}: ${(await odp.text()).slice(0, 600)}`;
      } catch (blad) { ostatnia = `połączenie nieudane: ${blad.message}`; }
      await new Promise((r) => setTimeout(r, 500));
    }

    if (wstal) return { adres, zamknij: () => proces.kill('SIGTERM') };

    proces.kill('SIGKILL');
    bledy.push(`  podejście ${podejscie}, port ${port}: `
      + (umarl !== null ? `proces zakończył się kodem ${umarl}` : 'brak odpowiedzi z /health')
      + (ostatnia !== null ? `\n  ostatnia odpowiedź — ${ostatnia}` : '')
      + `\n  ogon storage/logs/laravel.log:\n${ogonDziennika()}`
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


const { mkdirSync, readFileSync, appendFileSync, mkdtempSync } = await import('node:fs');
const { createHash } = await import('node:crypto');
const { tmpdir } = await import('node:os');
mkdirSync('storage/port-projektu', { recursive: true });
const slugPrzepisu = execFileSync('php', ['artisan', 'tinker', '--execute',
  "echo optional(App\\Models\\Recipe::where('status','published')->where('visibility','public')->orderBy('id')->first())->slug;",
], { env: env() }).toString().trim();
if (!slugPrzepisu) throw new Error('Brak przepisu do pomiaru marki');
const przepis = '/przepisy/' + slugPrzepisu;
const ekranyMarki = ['/home', '/', '/odkryj', '/szukaj', '/zeszyt', '/@ania', '/@zofia_z_bieszczad', przepis, '/dodaj', '/ustawienia', '/ustawienia/czytelnosc', '/powiadomienia'];
const wyniki = [];
const pomiarLanding = async (page, width) => {
  const response = await page.goto(adres + '/', { waitUntil: 'networkidle' });
  if (response.status() !== 200) throw new Error('LANDING_HTTP: ' + response.status());
  await page.evaluate(async () => { await document.fonts.ready; for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame); });
  const result = await page.evaluate(() => {
    const title = document.querySelector('.landing-opowiesc-tytul');
    const card = document.querySelector('.landing-wykonanie-karta');
    const steps = [...document.querySelectorAll('.landing-kroki > li')];
    const ratio = parseFloat(getComputedStyle(document.body).fontSize) / 18;
    return {
      scroll: document.documentElement.scrollWidth, ratio,
      title: title ? parseFloat(getComputedStyle(title).fontSize) : 0,
      card: card ? { background: getComputedStyle(card).backgroundColor, radius: parseFloat(getComputedStyle(card).borderTopLeftRadius) } : null,
      steps: steps.map(el => ({ background: getComputedStyle(el).backgroundColor, shadow: getComputedStyle(el).boxShadow, radius: parseFloat(getComputedStyle(el).borderRadius), border: parseFloat(getComputedStyle(el).borderTopWidth), y: el.getBoundingClientRect().y })),
      columns: matchMedia('(min-width: 48rem)').matches,
      photo: document.querySelector('.landing-wykonanie-zdjecie img')?.getBoundingClientRect().width || 0,
    };
  });
  if (result.scroll > width + 1) throw new Error('LANDING_OVERFLOW: ' + JSON.stringify(result));
  if (result.title < 32 * result.ratio - 1) throw new Error('LANDING_TYTUL: ' + JSON.stringify(result));
  if (result.steps.length !== 3 || result.steps.some(s => s.background !== 'rgba(0, 0, 0, 0)' || s.shadow !== 'none' || s.radius !== 0 || s.border < 1)) throw new Error('LANDING_KROKI: ' + JSON.stringify(result));
  if (result.columns && result.steps.some(s => Math.abs(s.y - result.steps[0].y) > 1)) throw new Error('LANDING_KOLUMNY: ' + JSON.stringify(result));
  if (!result.card || result.card.background !== 'rgb(21, 23, 20)' || result.card.radius < 28) throw new Error('LANDING_BLOK: ' + JSON.stringify(result));
  if (result.photo < 100) throw new Error('LANDING_ZDJECIE: brak fotografii do pomiaru');
  return result;
};
const pomiar = async (page, width, path) => {
  const response = await page.goto(`${adres}${path}`, { waitUntil: 'networkidle' });
  if (response.status() !== 200) throw new Error(`${path}: HTTP ${response.status()}`);
  await page.evaluate(() => document.fonts.ready);
  /* KOLORY PORÓWNUJEMY DOPIERO PO PRZEJŚCIACH CSS (wzorzec z PR #1472:
     `scripts/lib/stan-ustalony.mjs`, `docs/PULAPKI_TESTOW.md` §14).
     `KARTA_OSOB` porównuje tło karty osób z tłem paska, a te dwa elementy
     przechodzą zmianę motywu RÓŻNIE: pasek ma `data-pasek-przewijany`, więc
     przy `reducedMotion: 'reduce'` `pasek-przewijany.css` daje mu
     `transition: none` i nowe tło ma od razu; karta dostaje z `tokens.css`
     `transition-duration: 0.01ms` przy domyślnym `transition-property: all`,
     więc tło po zmianie `data-theme`/`data-text-scale` z `DOMContentLoaded`
     PRZECHODZI i do najbliższej klatki `getComputedStyle` oddaje wartość
     pośrednią. Na zajętym runnerze (main 1acbfeeb, DOM-NEW-04, wariant
     „tekst 140%") pomiar trafiał przed tę klatkę. Odtworzone lokalnie
     wydłużeniem samych przejść: karta `rgb(70, 73, 68)` przy pasku
     `rgb(34, 38, 32)`. Czekamy więc, co klatkę, aż w dokumencie nie ma
     trwającego przejścia i fonty są wczytane — stan, nie zegar. */
  await wymagajStanu(page, {
    opis: `${path}: przejścia CSS przed pomiarem marki (${width} px)`,
    warunek: (_, przejscia) => document.fonts.status === 'loaded' && przejscia().length === 0,
    pomiar: (_, przejscia) => ({ fonty: document.fonts.status, przejscia: przejscia().slice(0, 12) }),
  });
  const result = await page.evaluate(() => {
    const main = document.querySelector('.app-main').getBoundingClientRect();
    const nav = document.querySelector('.marka-nawigacja');
    const top = document.querySelector('.marka-topbar');
    return { width: innerWidth, scroll: document.documentElement.scrollWidth,
      main: main.width, radius: getComputedStyle(top).borderTopLeftRadius,
      navigation: nav ? getComputedStyle(nav).display : null,
      brand: document.body.dataset.marka,
      heading: document.querySelector('h1')?.textContent.trim(),
      profil: document.querySelector('.marka-profil') ? getComputedStyle(document.querySelector('.marka-profil')).backgroundColor : null,
      przepisFont: document.querySelector('.przepis-uklad > header h1') ? parseFloat(getComputedStyle(document.querySelector('.przepis-uklad > header h1')).fontSize) : null,
      rootFont: parseFloat(getComputedStyle(document.documentElement).fontSize),
      bodyFont: parseFloat(getComputedStyle(document.body).fontSize),
      tablica: (() => {
        const rozmiary = selector => [...document.querySelectorAll(selector)].map(el => {
          const box = el.getBoundingClientRect();
          return { width: box.width, height: box.height, font: parseFloat(getComputedStyle(el).fontSize) };
        });
        return {
          wrappers: rozmiary('.marka-tablica .kuking-board-avatar'),
          avatars: rozmiary('.marka-tablica .kuking-board-avatar .avatar'),
          photos: rozmiary('.marka-tablica .kuking-board-post-photo img'),
        };
      })(),
      opisy: [...document.querySelectorAll('.ustawienia-nawigacja-tu, .ustawienia-nawigacja-opis')].map(el => parseFloat(getComputedStyle(el).fontSize)),
      wybory: [...document.querySelectorAll('.panel-formularza .choice-help')].map(el => parseFloat(getComputedStyle(el).fontSize)),
      wzor: document.querySelector('.start-naglowek') ? (() => {
        const composer = document.querySelector('.marka-publikacja');
        const intro = document.querySelector('.marka-tablica-wstep');
        const people = document.querySelector('.marka-tablica .kuking-board-kolumna');
        const ring = getComputedStyle(composer, '::after');
        return {
          tytul: parseFloat(getComputedStyle(composer.querySelector('.composer-title')).fontSize),
          pierscien: [parseFloat(ring.width), parseFloat(ring.borderTopWidth)],
          wstep: intro && getComputedStyle(intro).backgroundColor,
          kartaOsob: people && getComputedStyle(people).backgroundColor,
          powierzchnia: getComputedStyle(top).backgroundColor,
          menu: [...nav.querySelectorAll('a')].map(a => a.textContent.trim()),
          szerokosc: composer.getBoundingClientRect().width,
          akcje: composer.querySelector('.marka-publikacja-akcje').getBoundingClientRect().width,
          szynaX: intro?.getBoundingClientRect().x,
          glownaPrawa: composer.getBoundingClientRect().right,
        };
      })() : null,
    };
  });
  if (result.brand !== 'kuking-2026') throw new Error('Brak portu marki');
  if (['/home', '/', '/odkryj', '/szukaj'].includes(path)) {
    const t = result.tablica;
    const zlyRozmiar = (el, size) => Math.abs(el.width - size) > 0.5 || Math.abs(el.height - size) > 0.5;
    if (!t.wrappers.length || !t.avatars.length || !t.photos.length) throw new Error('TABLICA_DANE: brak osób lub zdjęć do pomiaru ' + path);
    if (t.wrappers.some(el => zlyRozmiar(el, 52)) || t.avatars.some(el => zlyRozmiar(el, 52) || Math.abs(el.font - result.bodyFont) > 0.5)) throw new Error('TABLICA_AWATAR: ' + path + ' ' + JSON.stringify(t));
    if (t.photos.some(el => zlyRozmiar(el, 72))) throw new Error('TABLICA_ZDJECIE: ' + path + ' ' + JSON.stringify(t));
  }
  if (result.scroll > width + 1) throw new Error(`${path}: poziome przewijanie ${JSON.stringify(result)}`);
  if (result.main < Math.min(width - 60, 500)) throw new Error(`WASKA_KOLUMNA: ${JSON.stringify(result)}`);
  if (result.radius !== '20px') throw new Error('Nagłówek nie używa nowego projektu');
  if (width === 1440 && result.navigation === 'none') throw new Error('Brak menu desktop');
  if (path.startsWith('/@') && result.profil !== 'rgb(21, 23, 20)') throw new Error('MARKA_PROFIL: ' + JSON.stringify(result));
  if (path === przepis && (!result.przepisFont || result.przepisFont < result.rootFont * 2.125 - 0.5)) throw new Error('MARKA_TYTUL: ' + JSON.stringify(result));
  if (path.startsWith('/ustawienia') && (!result.opisy.length || result.opisy.some(size => size < result.bodyFont - 0.5))) throw new Error('MARKA_OPISY: ' + JSON.stringify(result));
  if (path === '/ustawienia/czytelnosc' && (!result.wybory.length || result.wybory.some(size => size < result.bodyFont - 0.5))) throw new Error('MARKA_WYBORY: ' + JSON.stringify(result));
  if (path === '/home' || path === '/') {
    const w = result.wzor;
    const braki = [];
    if (!w) throw new Error('KOMPOZYCJA: brak nagłówka startu');
    if (w.tytul < result.bodyFont * 28 / 18 - 0.5) braki.push('TYTUL_KAFLA');
    if (w.pierscien[0] < 200 || w.pierscien[1] < 30) braki.push('PIERSCIEN');
    if (w.wstep !== 'rgb(21, 23, 20)') braki.push('CIEMNY_WSTEP');
    if (w.kartaOsob !== w.powierzchnia) braki.push('KARTA_OSOB');
    if (w.menu.join('|') !== 'Start|Odkrywaj|Mój zeszyt') braki.push('MENU');
    if (width >= 1280 && result.rootFont <= 16) {
      if (w.szerokosc < 740 || w.szerokosc > 760 || w.szynaX - w.glownaPrawa < 39) braki.push('KOLUMNY');
      if (w.akcje > 501) braki.push('SZEROKOSC_AKCJI');
    }
    if (braki.length) throw new Error('KOMPOZYCJA: ' + braki.join(', '));
  }
  return result;
};
try {
  await wykonajGrupe(grupa, 'baza', async () => {
  for (const width of [320, 360, 390, 414, 768, 1440]) {
    for (const dark of [false, true]) {
      const context = await przegladarka.newContext({ storageState: sesja, viewport: { width, height: 900 }, reducedMotion: 'reduce' });
      const page = await context.newPage();
      await page.addInitScript((dark) => { if (dark) document.addEventListener('DOMContentLoaded', () => document.documentElement.dataset.theme = 'dark'); }, dark);
      for (const path of ekranyMarki) {
        const result = await pomiar(page, width, path);
        wyniki.push({ path, dark, ...result });
        if (!dark && ((width === 390 && ['/home', '/@ania', '/szukaj', '/@zofia_z_bieszczad', przepis, '/ustawienia/czytelnosc'].includes(path)) || (width === 1440 && path === '/home'))) {
          const name = `${width}-${path.replaceAll('/', '').replace('@', '')}`;
          const buffer = await page.screenshot({ path: `storage/port-projektu/${name}.jpg`, type: 'jpeg', quality: 65 });
          console.log(`PORT_SCREEN_${name} ${buffer.toString('base64')}`);
        }
      }
      await context.close();
    }
  }

  for (const width of [320, 360, 390, 414, 768, 1440]) {
    for (const dark of [false, true]) {
      const context = await przegladarka.newContext({ storageState: sesja, viewport: { width, height: 900 }, reducedMotion: 'reduce' });
      const page = await context.newPage();
      await page.addInitScript((dark) => document.addEventListener('DOMContentLoaded', () => {
        document.documentElement.dataset.textScale = '140';
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
      }), dark);
      for (const path of ['/home', '/']) wyniki.push({ text140: true, dark, path, ...await pomiar(page, width, path) });
      await context.close();
    }
  }
  for (const width of [320, 1440]) {
    const guest = await przegladarka.newContext({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
    const guestPage = await guest.newPage();
    for (const path of ['/', '/login', '/register', '/o-kuking']) {
      const response = await guestPage.goto(adres + path, { waitUntil: 'networkidle' });
      if (response.status() !== 200) throw new Error(path + ': HTTP ' + response.status());
      const scroll = await guestPage.evaluate(() => document.documentElement.scrollWidth);
      if (scroll > width + 1) throw new Error(path + ': poziome przewijanie gościa ' + scroll);
      wyniki.push({ guest: true, path, width, scroll });
    }
    await guest.close();
  }
  for (const width of [320, 360, 390, 414, 768, 1440]) for (const dark of [false, true]) for (const scale of [100, 140, PRZEGLADARKA_200]) {
    const guest = await przegladarka.newContext({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
    const page = await guest.newPage();
    if (scale === PRZEGLADARKA_200) await (await guest.newCDPSession(page)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
    await page.addInitScript(({ dark, scale }) => document.addEventListener('DOMContentLoaded', () => {
      document.documentElement.dataset.theme = dark ? 'dark' : 'light';
      document.documentElement.dataset.textScale = String(scale === 140 ? 140 : 100);
    }), { dark, scale });
    wyniki.push({ landing: true, width, dark, scale, ...await pomiarLanding(page, width) });
    if ((width === 1440 && scale === 100) || (width === 320 && scale === 140)) {
      await page.locator('#jak-dziala').screenshot({ path: `storage/port-projektu/landing-kroki-${width}-${dark}.png` });
      await page.locator('#ugotowalem').screenshot({ path: `storage/port-projektu/landing-wykonanie-${width}-${dark}.png` });
    }
    await guest.close();
  }
  const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 320, height: 740 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  const cdp = await context.newCDPSession(page);
  await cdp.send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
  for (const path of ekranyMarki) {
    wyniki.push({ largeText: true, path, ...await pomiar(page, 320, path) });
  }
  await context.close();

  await sprawdzMacierzNawigacji({ browser: przegladarka, adres, sesja });
  await sprawdzZoomNawigacji({ chromium, adres, sesja, outputDir: 'storage/port-projektu/nawigacja638' });
  });
  await wykonajGrupe(grupa, 'rozszerzenia', async () => {
  if (!['127.0.0.1', 'localhost'].includes(new URL(adres).hostname)) throw new Error('Fixture kompozycji wymaga lokalnego serwera.');
  const kompozycje = JSON.parse(execFileSync('php', ['scripts/fixtures/kompozycje-marki.php'], { env: env() }).toString());
  await sprawdzKompozycje({ browser: przegladarka, adres, sesja, ...kompozycje });
  const zeszyty = JSON.parse(execFileSync('php', ['scripts/fixtures/zeszyty-marki.php'], { env: env() }).toString());
  await sprawdzZeszyty({ browser: przegladarka, adres, sesja, ...zeszyty });
  const paczka513 = JSON.parse(execFileSync('php', ['scripts/fixtures/kompozycje-513.php'], { env: env() }).toString());
  await sprawdzKompozycje513({ browser: przegladarka, adres, sesja, phpEnv: env(), ...paczka513 });
  await sprawdzPodpowiedzi({ browser: przegladarka, adres, sesja, phpEnv: env() });
  await sprawdzZwarteKolumny({ browser: przegladarka, adres });
  await sprawdzListeOsob({ browser: przegladarka, adres, sesja });
  await sprawdzPrzyciskRejestracji({ browser: przegladarka, adres });
  /* `sprawdzPrzyciskRejestracji` mierzy w oknie 900 px wysokości, więc odpowiada
     na pytanie „czy przycisk jest sprawny", a nie „czy widać go bez przewijania".
     To drugie pytanie ma własne okna — wysokości prawdziwych telefonów. */
  await sprawdzHeroNadZgieciem({ browser: przegladarka, adres });
  await sprawdzInstalacjePwa({ browser: przegladarka, adres, sesja, phpEnv: env() });
  await sprawdzPasek({ browser: przegladarka, adres, sesja });
  await sprawdzSzybkiWyglad({ browser: przegladarka, adres });
  await sprawdzStopke({ browser: przegladarka, adres, sesja });
  await sprawdzTagi({ browser: przegladarka, adres, sesja, phpEnv: env() });
  await sprawdzNawigacje492({ adres, sesja, phpEnv: env() });
  await sprawdzZoomMarki({ adres, sesja, przepis: kompozycje.przepis, ...zeszyty, ...paczka513, sciezki515: ['/szukaj'] });

  });
  await wykonajGrupe(grupa, 'baza', async () => {
  // Kontrola ujemna zmienia źródło CSS, nie wynik pomiaru ani atrapę DOM.
  const source = 'resources/css/marka-rama.css';
  const copy = `${mkdtempSync(`${tmpdir()}/kuking-port-`)}/marka-rama.css`;
  const hash = () => createHash('md5').update(readFileSync(source)).digest('hex');
  const before = hash();
  execFileSync('cp', [source, copy]);
  try {
    appendFileSync(source, '\n[data-marka] .marka-rama:not([data-tryb-panelu]) { width: 80px; }\n');
    console.log(`KONTROLA_UJEMNA przed=${before} zmieniony=${hash()}`);
    execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
    const negativeContext = await przegladarka.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
    const negativePage = await negativeContext.newPage();
    let detected = false;
    try { await pomiar(negativePage, 390, '/home'); }
    catch (error) { if (/WASKA_KOLUMNA|poziome przewijanie/.test(error.message)) { detected = true; console.log(`KONTROLA_UJEMNA wykryto=${error.message}`); } else throw error; }
    await negativeContext.close();
    if (!detected) throw new Error('Kontrola ujemna nie wykryła zwężenia strony');
  } finally {
    execFileSync('cp', [copy, source]);
    execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
    if (hash() !== before) throw new Error('Źródło nie zostało odtworzone');
    console.log(`KONTROLA_UJEMNA przywrocony=${hash()}`);
  }
  const restored = await przegladarka.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
  await pomiar(await restored.newPage(), 390, '/home');
  await restored.close();
  // Każda regresja dotyczy rzeczywistego arkusza; jeden build, cztery różne ekrany.
  execFileSync('cp', [source, copy]);
  try {
    appendFileSync(source, '\n[data-marka] .marka-profil.blok-ciemny { background-color: #fff; }\n[data-marka] .przepis-uklad > header h1 { font-size: 12px; }\n[data-marka] .ustawienia-nawigacja-opis { font-size: 12px; }\n[data-marka] .panel-formularza .choice-help { font-size: 12px; }\n');
    console.log('MARKA_UJEMNA przed=' + before + ' zmieniony=' + hash());
    execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
    for (const [path, kod] of [['/@zofia_z_bieszczad', 'MARKA_PROFIL'], [przepis, 'MARKA_TYTUL'], ['/ustawienia', 'MARKA_OPISY']]) {
      const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
      let wykryto = false;
      try { await pomiar(await context.newPage(), 390, path); }
      catch (error) { if (error.message.startsWith(kod)) wykryto = true; else throw error; }
      finally { await context.close(); }
      if (!wykryto) throw new Error('Kontrola ujemna nie wykryła ' + kod);
      console.log('MARKA_UJEMNA wykryto=' + kod);
    }
    // Opisy ustawień nie mogą zamaskować osobnego sprawdzenia opisów wyboru.
    execFileSync('cp', [copy, source]);
    appendFileSync(source, '\n[data-marka] .panel-formularza .choice-help { font-size: 12px; }\n');
    execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
    const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
    let wykryto = false;
    try { await pomiar(await context.newPage(), 390, '/ustawienia/czytelnosc'); }
    catch (error) { if (error.message.startsWith('MARKA_WYBORY')) wykryto = true; else throw error; }
    finally { await context.close(); }
    if (!wykryto) throw new Error('Kontrola ujemna nie wykryła MARKA_WYBORY');
    console.log('MARKA_UJEMNA wykryto=MARKA_WYBORY');
  } finally {
    execFileSync('cp', [copy, source]);
    execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
    if (hash() !== before) throw new Error('Źródło marki nie zostało odtworzone');
    console.log('MARKA_UJEMNA przywrocony=' + hash());
  }
  const kontekstPrzywrocony = await przegladarka.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
  const stronaPrzywrocona = await kontekstPrzywrocony.newPage();
  for (const path of ['/@zofia_z_bieszczad', przepis, '/ustawienia', '/ustawienia/czytelnosc']) await pomiar(stronaPrzywrocona, 390, path);
  await kontekstPrzywrocony.close();

  // Wzorzec właściciela: sabotaż czterech niezależnych cech rzeczywistego CSS.
  // Pomiar zbiera wszystkie braki, więc pierwszy nie maskuje pozostałych.
  execFileSync('cp', [source, copy]);
  try {
    appendFileSync(source, '\n[data-marka] .marka-publikacja .composer-title { font-size: 14px; }\n[data-marka] .marka-publikacja::after { border-width: 0; }\n[data-marka] .marka-tablica-wstep { background: #fff; }\n[data-marka] .marka-tablica .kuking-board-kolumna { background: transparent; }\n');
    console.log('KOMPOZYCJA_UJEMNA przed=' + before + ' zmieniony=' + hash());
    execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
    const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 1440, height: 900 } });
    let blad = '';
    try { await pomiar(await context.newPage(), 1440, '/home'); }
    catch (error) { blad = error.message; }
    finally { await context.close(); }
    for (const kod of ['TYTUL_KAFLA', 'PIERSCIEN', 'CIEMNY_WSTEP', 'KARTA_OSOB']) {
      if (!blad.startsWith('KOMPOZYCJA:') || !blad.includes(kod)) throw new Error('Niewykryty sabotaż ' + kod + ': ' + blad);
      console.log('KOMPOZYCJA_UJEMNA wykryto=' + kod);
    }
  } finally {
    execFileSync('cp', [copy, source]);
    execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
    if (hash() !== before) throw new Error('Nie odtworzono CSS po kontroli kompozycji');
    console.log('KOMPOZYCJA_UJEMNA przywrocony=' + hash());
  }
  const odbior = await przegladarka.newContext({ storageState: sesja, viewport: { width: 1440, height: 900 } });
  await pomiar(await odbior.newPage(), 1440, '/home');
  await odbior.close();
  // Issue #503: regresja selektora szyny nie może przejść tylko dlatego,
  // że /home ma poprawny rozmiar. Mutujemy prawdziwy CSS i mierzymy /odkryj.
  for (const [selector, declaration, kod] of [
    ['.marka-tablica .kuking-board-avatar, [data-marka] .marka-tablica .kuking-board-avatar .avatar', 'width: 120px; height: 120px;', 'TABLICA_AWATAR'],
    ['.marka-tablica .kuking-board-post-photo img', 'width: 120px; height: 120px;', 'TABLICA_ZDJECIE'],
  ]) {
    execFileSync('cp', ['-p', source, copy]);
    try {
      appendFileSync(source, `\n[data-marka] ${selector} { ${declaration} }\n`);
      console.log('TABLICA_UJEMNA przed=' + before + ' zmieniony=' + hash());
      execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
      const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 1440, height: 900 } });
      let wykryto = false;
      try { await pomiar(await context.newPage(), 1440, '/odkryj'); }
      catch (error) { if (error.message.startsWith(kod)) wykryto = true; else throw error; }
      finally { await context.close(); }
      if (!wykryto) throw new Error('Niewykryty sabotaż ' + kod);
      console.log('TABLICA_UJEMNA wykryto=' + kod);
    } finally {
      execFileSync('cp', ['-p', copy, source]);
      execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
      if (hash() !== before) throw new Error('Nie odtworzono CSS po kontroli tablicy');
      console.log('TABLICA_UJEMNA przywrocony=' + hash());
    }
  }
  const tablicaOdbior = await przegladarka.newContext({ storageState: sesja, viewport: { width: 1440, height: 900 } });
  for (const path of ['/home', '/', '/odkryj', '/szukaj']) await pomiar(await tablicaOdbior.newPage(), 1440, path);
  await tablicaOdbior.close();
  // D-208: rzeczywista kompozycja strony publicznej, nie sam brak overflow.
  for (const [selector, declaration, kod] of [
    ['.landing-opowiesc-tytul', 'font-size: 18px;', 'LANDING_TYTUL'],
    ['.landing-kroki > li', 'background: white; border-radius: 24px;', 'LANDING_KROKI'],
    ['.landing-wykonanie-karta', 'background: white;', 'LANDING_BLOK'],
  ]) {
    execFileSync('cp', ['-p', source, copy]);
    try {
      appendFileSync(source, `\n[data-marka] ${selector} { ${declaration} }\n`);
      execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
      const guest = await przegladarka.newContext({ viewport: { width: 1440, height: 900 } });
      let wykryto = false;
      try { await pomiarLanding(await guest.newPage(), 1440); }
      catch (error) { if (error.message.startsWith(kod)) wykryto = true; else throw error; }
      finally { await guest.close(); }
      if (!wykryto) throw new Error('Niewykryty sabotaż ' + kod);
      console.log('LANDING_UJEMNA wykryto=' + kod + ' przed=' + before + ' zmieniony=' + hash());
    } finally {
      execFileSync('cp', ['-p', copy, source]);
      execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore' });
      if (hash() !== before) throw new Error('Nie odtworzono CSS landingu');
      console.log('LANDING_UJEMNA przywrocony=' + hash());
    }
  }
  const landingOdbior = await przegladarka.newContext({ viewport: { width: 1440, height: 900 } });
  await pomiarLanding(await landingOdbior.newPage(), 1440);
  await landingOdbior.close();
  });
  console.log(`PORT_OK ${JSON.stringify(wyniki)}`);
} finally {
  await przegladarka.close();
  zamknij();
}
