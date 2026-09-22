/*
 * =============================================================================
 *  Kuking.pl — pomiar GŁÓWKI PROFILU (issue #435)
 * =============================================================================
 *
 *  PO CO TO JEST
 *  Zgłoszenie właściciela brzmiało: „Mój profil jest miejsce by dać @woogitsu
 *  obok Mateusz" — czyli o marnowanej wysokości główki na telefonie. To jest
 *  zdanie o wrażeniu. Ten skrypt zamienia je na liczby: wysokość główki,
 *  pozycję `y` rzędu akcji („Zmień swój profil" · „Dodaj zdjęcie"
 *  · „Ustawienia" · „Wyloguj się"), liczbę wierszy przycisku pod awatarem
 *  i to, czy nazwa i `@nazwa` stoją w jednym wierszu.
 *
 *  DLACZEGO TYLE SZEROKOŚCI
 *  Warunek właściciela z tego samego dnia, obowiązujący całą serię usterek
 *  mobilnych (#430–#435): „Wszystkie te bugi mobilne musisz sprawdzać na
 *  różnych rozdzielczościach i formatach". Stąd 320 / 360 / 390 / 414 px,
 *  do tego 768 px (tablet, próg `--breakpoint-md`, na którym awatar wchodzi
 *  OBOK tekstu) i 1280 px — a każda z nich także przy czcionce przeglądarki
 *  200%. Poprawka, która pomaga przy 390 px, a psuje przy 320 px albo przy
 *  podwojonej czcionce, nie jest poprawką.
 *
 *  DŁUGA NAZWA JEST OSOBNYM EKRANEM, NIE OZDOBĄ POMIARU
 *  `display_name` ma dziś `max:100`. Główka, w której `@nazwa` staje obok
 *  nazwy, musi się z tego umieć wycofać — inaczej przy długiej nazwie albo
 *  wypycha stronę w bok, albo nachodzi jedno na drugie. Dlatego każdy
 *  pomiar idzie w dwóch wariantach: nazwa krótka („Ania") i nazwa na pełne
 *  pełną dopuszczalną długość.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/glowka-profilu.mjs
 *      ADRES=http://127.0.0.1:8123 node scripts/glowka-profilu.mjs
 *
 *  Bez `ADRES` skrypt sam sieje bazę `kuking_glowka_profilu`, buduje arkusz,
 *  podnosi `php artisan serve` i sam go gasi.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa — ten skrypt robi `migrate:fresh`. Wskazanie
   `kuking` albo `kuking_test` kasowałoby czyjąś pracę (AGENTS.md §6). */
const BAZA_DOMYSLNA = 'kuking_glowka_profilu';

/* Domyślny rozmiar pisma przeglądarki; wariant 200% ustawia dwa razy tyle
   przez CDP `Page.setFontSizes` — tak samo jak `scripts/dostepnosc.mjs`. */
const BAZOWA_CZCIONKA_PX = 16;

const SZEROKOSCI = [
  { nazwa: '320 px', szerokosc: 320, wysokosc: 844 },
  { nazwa: '360 px', szerokosc: 360, wysokosc: 800 },
  { nazwa: '390 px', szerokosc: 390, wysokosc: 844 },
  { nazwa: '414 px', szerokosc: 414, wysokosc: 896 },
  { nazwa: '768 px', szerokosc: 768, wysokosc: 1024 },
  { nazwa: '1280 px', szerokosc: 1280, wysokosc: 900 },
];

const NAZWA_KROTKA = 'Ania';
const NAZWA_DLUGA = 'Aleksandra Katarzyna Przepiórkowska-Wielopolska od kuchni babci Zofii z Podkarpacia';

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

function ustawNazwe(nazwa) {
  const php = `
    $p = App\\Models\\Profile::where('username', '${KONTO}')->firstOrFail();
    $p->forceFill(['display_name' => ${JSON.stringify(nazwa)}])->save();
    echo $p->display_name;
  `;
  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: env() })
    .toString().trim();

  if (! wynik.includes(nazwa.slice(0, 20))) {
    throw new Error(`Nie udało się ustawić nazwy „${nazwa}". Wyjście tinkera: ${wynik}`);
  }
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

  console.log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const bledy = [];

  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = await wolnyPort();
    const adres = `http://127.0.0.1:${port}`;
    const dziennik = [];

    /* `--no-reload` — patrz `scripts/port-projektu.mjs`: bez niego `artisan serve`
       wycina procesowi `php -S` zmienne środowiska joba i aplikacja spada na
       `.env`, czyli na współdzielony port 5432. */
    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
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

/*
 * POMIAR. Wszystko czytane z `getBoundingClientRect()` po ułożeniu strony —
 * liczy się to, co widzi człowiek, a nie deklaracja w arkuszu.
 *
 * `wierszePrzycisku` liczymy jako wysokość napisu podzieloną przez wysokość
 * jednego wiersza, a nie „czy jest w nim znak nowej linii": łamanie robi
 * przeglądarka i w HTML-u nie zostawia po sobie śladu.
 */
const POMIAR = () => {
  const zaokr = (x) => Math.round(x * 100) / 100;
  const glowka = document.querySelector('header.sekcja-strony');

  if (! glowka) return { blad: 'nie znalazłem `header.sekcja-strony`' };

  const akcje = glowka.querySelector(':scope > div.flex');
  const h1 = glowka.querySelector('h1');
  const przycisk = glowka.querySelector('.profil-awatar-zmiana-akcja');

  /* `@nazwa` ma dziś własny akapit `.meta`; po poprawce może stać w środku
     nagłówka. Szukamy więc PO TREŚCI, nie po miejscu w drzewie — inaczej
     pomiar „przed" i „po" mierzyłby dwie różne rzeczy. */
  const nazwaZMalpa = [...glowka.querySelectorAll('p, span, small')]
    .filter((el) => el.textContent.trim().startsWith('@'))
    .filter((el) => ! el.querySelector('p, span, small'))
    .at(0);

    const prostokat = (el) => {
    if (! el) return null;
    const p = el.getBoundingClientRect();

    return {
      gora: zaokr(p.top + window.scrollY),
      dol: zaokr(p.bottom + window.scrollY),
      lewo: zaokr(p.left),
      prawo: zaokr(p.right),
      wysokosc: zaokr(p.height),
      szerokosc: zaokr(p.width),
    };
  };

  const wiersze = (el) => {
    if (! el) return null;
    const linia = Number.parseFloat(getComputedStyle(el).lineHeight);

    if (! Number.isFinite(linia) || linia <= 0) return null;

    return Math.round(el.getBoundingClientRect().height / linia * 10) / 10;
  };

  return {
    glowka: prostokat(glowka),
    akcje: prostokat(akcje),
    nazwa: prostokat(h1),
    malpa: prostokat(nazwaZMalpa),
    przyciskZdjecia: prostokat(przycisk),
    wierszePrzyciskuZdjecia: wiersze(przycisk),

    /* Czy nazwa i `@nazwa` stoją w jednym wierszu: pionowe zakresy obu
       pudełek zachodzą na siebie. Porównanie samego `top` dałoby fałsz
       przy różnych rozmiarach pisma. */
    wJednymWierszu: (h1 && nazwaZMalpa)
      ? (h1.getBoundingClientRect().bottom > nazwaZMalpa.getBoundingClientRect().top + 1
        && nazwaZMalpa.getBoundingClientRect().bottom > h1.getBoundingClientRect().top + 1)
      : null,

    /* ROZBIÓRKA GŁÓWKI NA KLOCKI — bez tego wiadomo TYLKO, że jest za
       wysoka, a nie który blok ją podbija. Bierzemy kolumnę awatara
       i wszystkie bezpośrednie dzieci kolumny z treścią. */
    bloki: [
      ...[...(glowka.querySelector('.profil-glowka-tresc')?.children ?? [])],
      ...[...(glowka.querySelector('.profil-glowka-tresc .min-w-0')?.children ?? [])],
    ]
      .filter((el) => el.getBoundingClientRect().height > 0)
      .map((el) => ({
        co: `${el.tagName.toLowerCase()}.${[...el.classList].join('.') || '(bez klasy)'}`,
        wysokosc: zaokr(el.getBoundingClientRect().height),
      })),

    /* Wyjazd w bok — osobno dla całego dokumentu i dla samej główki. */
    szerokoscOkna: window.innerWidth,
    szerokoscDokumentu: document.documentElement.scrollWidth,
    najdalszyPikselGlowki: zaokr(Math.max(
      ...[...glowka.querySelectorAll('*')].map((el) => el.getBoundingClientRect().right),
    )),
  };
};

const CHROMIUM = znajdzChromium();
const { adres, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/*
 * LOGUJEMY SIĘ RAZ, A POTEM PRZENOSIMY CIASTECZKO.
 *
 * Ten pomiar ma 24 warianty (dwie nazwy × sześć szerokości × dwa rozmiary
 * pisma). Logowanie w każdym z nich to 24 próby pod rząd z jednego adresu
 * — a serwis ma na to `throttle` i po kilku odpowiada ekranem „Za dużo
 * prób”. Pomiar leci wtedy na ekranie blokady zamiast na profilu.
 * ZMIERZONE 12 września 2026: pierwsza wersja tego skryptu padła dokładnie
 * tak, przy piątym wariancie.
 */
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

const wiersze = [];
let bylBlad = false;

try {
  for (const [etykietaNazwy, nazwa] of [['nazwa krótka', NAZWA_KROTKA], ['nazwa 80+ znaków', NAZWA_DLUGA]]) {
    ustawNazwe(nazwa);

    for (const widok of SZEROKOSCI) {
      for (const czcionka of [null, 200]) {
        const kontekst = await przegladarka.newContext({
          viewport: { width: widok.szerokosc, height: widok.wysokosc },
          storageState: sesja,
        });
        const strona = await kontekst.newPage();

        if (czcionka === 200) {
          // PRZED nawigacją: to ma być stan przeglądarki zastany przez stronę.
          const cdp = await kontekst.newCDPSession(strona);

          await cdp.send('Page.setFontSizes', {
            fontSizes: { standard: 2 * BAZOWA_CZCIONKA_PX, fixed: 2 * BAZOWA_CZCIONKA_PX },
          });
        }

        const odp = await strona.goto(`${adres}/@${KONTO}`, { waitUntil: 'domcontentloaded' });

        if ((odp?.status() ?? 0) !== 200) {
          console.error(`BŁĄD: /@${KONTO} odpowiedziało kodem ${odp?.status()}.`);
          bylBlad = true;
          await kontekst.close();
          continue;
        }

        await strona.waitForSelector('header.sekcja-strony');
        await strona.evaluate(() => document.fonts?.ready);

        if (czcionka === 200) {
          const korzen = await strona.evaluate(
            () => Number.parseFloat(getComputedStyle(document.documentElement).fontSize),
          );

          if (korzen < 2 * BAZOWA_CZCIONKA_PX) {
            console.error(`BŁĄD: czcionka korzenia to ${korzen} px zamiast ${2 * BAZOWA_CZCIONKA_PX} px `
              + '— wariant 200% nie zadziałał, jego liczby nic nie znaczą.');
            bylBlad = true;
          }
        }

        const wynik = await strona.evaluate(POMIAR);

        if (wynik.blad) {
          console.error(`BŁĄD: ${wynik.blad}`);
          bylBlad = true;
        } else {
          wiersze.push({ etykietaNazwy, widok: widok.nazwa, czcionka: czcionka ? '200%' : '100%', ...wynik });
        }

        await kontekst.close();
      }
    }
  }
} finally {
  await przegladarka.close();
  zamknij();
}

const kol = (x, n) => String(x).padStart(n);

console.log('\n'
  + 'nazwa            okno      czc.  wys. główki   y rzędu akcji  wierszy przycisku  @ w wierszu nazwy  wyjazd w bok');
console.log('-'.repeat(112));

for (const w of wiersze) {
  const wyjazd = w.szerokoscDokumentu > w.szerokoscOkna
    ? `TAK (${w.szerokoscDokumentu} > ${w.szerokoscOkna})`
    : 'nie';

  console.log(
    w.etykietaNazwy.padEnd(17)
    + w.widok.padEnd(10)
    + w.czcionka.padEnd(6)
    + kol(w.glowka?.wysokosc ?? '—', 11) + ' px'
    + kol(w.akcje?.gora ?? '—', 14) + ' px'
    + kol(w.wierszePrzyciskuZdjecia ?? '—', 15) + '   '
    + String(w.wJednymWierszu === null ? '—' : (w.wJednymWierszu ? 'TAK' : 'nie')).padStart(15) + '  '
    + wyjazd,
  );
}

const wzorcowy = wiersze.find((w) => w.widok === '390 px' && w.czcionka === '100%' && w.etykietaNazwy === 'nazwa krótka');

if (wzorcowy) {
  console.log('\nROZBIÓRKA GŁÓWKI — 390 px, czcionka 100%, nazwa krótka:');
  for (const b of wzorcowy.bloki) {
    console.log(`  ${b.co.padEnd(52)} ${String(b.wysokosc).padStart(8)} px`);
  }
}

/* PUSTY EKRAN PRZECHODZI KAŻDY POMIAR. Brak `@nazwy` w główce znaczy, że
   mierzyliśmy nie ten ekran, o który poszło zgłoszenie. */
if (wiersze.some((w) => ! w.malpa)) {
  console.error('\nBŁĄD: na co najmniej jednym pomiarze nie było w główce elementu zaczynającego się od „@" '
    + '— a to jest rzecz, o którą poszło zgłoszenie. Pomiar nic nie znaczy.');
  bylBlad = true;
}

if (bylBlad) process.exit(1);
