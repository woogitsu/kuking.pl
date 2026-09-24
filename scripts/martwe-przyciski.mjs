/*
 * =============================================================================
 *  Kuking.pl — audyt MARTWYCH PRZYCISKÓW (AGENTS.md §5, D-053)
 * =============================================================================
 *
 *  PO CO TO JEST
 *  D-053 zabrania przycisku, który nic nie robi albo kończy się odmową.
 *  Pilnowały tego dotąd pojedyncze testy — każdy jednego ekranu. NIKT NIGDY
 *  NIE PRZESZEDŁ CAŁEGO SERWISU i nie policzył, ile odnośników prowadzi
 *  donikąd. Ten skrypt to robi: chodzi po ekranach, zbiera KAŻDY `<a href>`
 *  i KAŻDY `<form>`, wchodzi w nie i melduje kod odpowiedzi razem z ekranem,
 *  na którym odnośnik stał.
 *
 *  TRZY PRZEBIEGI, BO SERWIS MA TRZY RÓŻNE ZESTAWY PRZYCISKÓW
 *  zalogowany (`ania`), moderator (`moderacja`, przez prawdziwe 2FA) i gość.
 *  Ten sam ekran pokazuje każdemu z nich co innego, a martwy przycisk
 *  najłatwiej wchodzi tam, gdzie widzi go tylko jedna z tych trzech osób.
 *
 *  TRZY TWARDE GRANICE — SPRAWDZANE W KODZIE, NIE W KOMENTARZU
 *  AGENTS.md §6 mówi wprost przy migracjach: „napisanie w komentarzu «przy
 *  cofaniu najpierw kopia kolumny» NIE jest zabezpieczeniem". Tutaj obowiązuje
 *  ta sama zasada, więc każda z trzech granic ma jawne sprawdzenie odmawiające
 *  startu albo przerywające żądanie:
 *
 *    1. ŻADNEGO `POST`/`PUT`/`PATCH`/`DELETE` W FAZIE AUDYTU.
 *       `strazZadania()` wpięta w `context.route('**')` PRZERYWA każde takie
 *       żądanie przeglądarki, a `pobierz()` rzuca wyjątkiem przy próbie użycia
 *       innej metody niż GET. Wyjątkiem jest wyłącznie faza LOGOWANIA i w niej
 *       wyłącznie dwa nazwane adresy (`POST_WOLNO_PRZY_LOGOWANIU`) — faza gaśnie,
 *       zanim zacznie się audyt. Formularze zapisujące NIE SĄ wysyłane:
 *       sprawdzamy im trasę w tablicy tras (`route:list`), co odróżnia
 *       „przycisk prowadzi donikąd" od „przycisk działa", nie kasując danych.
 *
 *    2. TYLKO `127.0.0.1`. `czyNasz()` porównuje host i port z adresem
 *       postawionego serwera. Odnośniki na zewnątrz są LICZONE i WYPISANE,
 *       ale nie odwiedzane, a straż żądań przerywa każde wyjście poza
 *       `127.0.0.1` — także to, które zleciłaby sama strona.
 *
 *    3. NIGDY NA BAZIE `kuking` ANI `kuking_test`. Skrypt robi
 *       `migrate:fresh`, czyli kasuje wszystko, co w bazie stoi.
 *       `sprawdzBaze()` odmawia startu, gdy nazwa bazy jest na liście
 *       zakazanych albo gdy `APP_ENV=production`.
 *
 *  UCZCIWOŚĆ POMIARU (docs/PULAPKI_TESTOW.md, pułapki 2 i 5)
 *  Skan, który nie znalazł niczego, wygląda dokładnie tak samo jak skan,
 *  na którym wszystko jest w porządku. Dlatego:
 *    - progi minimalne: za mało ekranów albo za mało odnośników to PORAŻKA
 *      z komunikatem „skan nie chodzi po serwisie", nie cichy sukces;
 *    - dowód zalogowania: na ekranach przebiegu zalogowanego i moderatora MUSI
 *      stać formularz `POST /logout`, a na ekranach gościa MUSI go NIE być
 *      (kontrola dodatnia + ujemna w jednej parze). Serwis ma throttle na
 *      logowaniu i przy kilkunastu próbach pod rząd oddaje ekran „Za dużo
 *      prób" — na którym wszystko odpowiada 200 i raport wygląda na udany;
 *    - brak wyniku to NIE jest „w porządku": odnośnik, którego nie dało się
 *      sprawdzić, ląduje w osobnej rubryce „NIE WIEMY" i liczy się jako
 *      nieprzejście;
 *    - meldunek podaje KONKRETNE adresy z ekranem źródłowym, nie same liczby.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/martwe-przyciski.mjs
 *      DB_DATABASE=kuking_dev_wt_e node scripts/martwe-przyciski.mjs
 *      ADRES=http://127.0.0.1:8105 node scripts/martwe-przyciski.mjs   # gotowy serwer
 *
 *  Bez `ADRES` skrypt sam zakłada i sieje własną bazę, buduje arkusz, podnosi
 *  `php artisan serve` i SAM GO GASI — także wtedy, gdy audyt padnie po drodze.
 *
 *  Pełny wynik idzie do `storage/martwe-przyciski.json`; na konsolę idzie
 *  lista adresów, bo ona jest tu meldunkiem, a nie liczby.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';

// --------------------------------------------------------------------------
// Konta i baza
// --------------------------------------------------------------------------

/*
 * `ania`, nie `basia`: „basia" bywa personą treści zalążkowej, a persony mają
 * hasło losowe i nie są logowalne (D-025). Ten sam powód, co w
 * `scripts/dostepnosc.mjs`.
 */
const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/*
 * KONTO MODERATORA — OSOBNE I MUSI BYĆ OSOBNE. Panel moderacji stoi za rolą
 * (`EnsureUserIsModerator` odpowiada zwykłemu użytkownikowi 404, żeby nie
 * potwierdzać, że panel istnieje) i za weryfikacją dwuetapową
 * (`EnsureModeratorHasTwoFactor`). Ciasteczkiem konta `ania` nie da się tam
 * zajrzeć — dostalibyśmy 404 i mierzyli stronę błędu.
 *
 * Bez tego przebiegu cała warstwa panelu byłaby w tym audycie milczącym
 * pominięciem, a raport świeciłby na zielono, nie oglądając jej ani razu.
 */
const KONTO_MODERATORA = 'moderacja';

/*
 * Własna baza pomiarowa. Ten skrypt robi na niej `migrate:fresh`, czyli kasuje
 * wszystko — stąd osobna nazwa i stąd lista zakazanych niżej.
 */
const BAZA_DOMYSLNA = 'kuking_martwe_przyciski';

/** GRANICA 3. Bazy, na których ten skrypt nie ma prawa się uruchomić. */
const BAZY_ZAKAZANE = ['kuking', 'kuking_test'];

// --------------------------------------------------------------------------
// Progi uczciwości (pułapka 2: skan bez trafień to dla skanu sukces)
// --------------------------------------------------------------------------

/** Minimalna liczba ekranów, po których skan MUSI przejść w przebiegu zalogowanym. */
const MIN_EKRANOW_ZALOGOWANY = 25;

/** Minimalna liczba ekranów gościa. */
const MIN_EKRANOW_GOSC = 10;

/** Minimalna liczba ekranów panelu moderacji. */
const MIN_EKRANOW_MODERATOR = 8;

/** Minimalna liczba różnych wewnętrznych adresów GET zebranych łącznie. */
const MIN_ODNOSNIKOW = 100;

/** Minimalna liczba zebranych formularzy. */
const MIN_FORMULARZY = 15;

/** Ile ekranów najwyżej odwiedzamy w jednym przebiegu (żeby skan się kończył). */
const LIMIT_EKRANOW = 160;

/** Jak głęboko schodzimy od ekranu zalążkowego. */
const LIMIT_GLEBOKOSCI = 4;

/*
 * ILE RÓŻNYCH WARIANTÓW ZAPYTANIA (`?sortuj=…&kierunek=…&status=…`) BIERZEMY
 * Z JEDNEJ ŚCIEŻKI.
 *
 * Bez tego progu `/admin/uzytkownicy` zjada cały audyt: trzy sortowania × dwa
 * kierunki × sześć filtrów to 36 odnośników, z których KAŻDY prowadzi na
 * stronę z następnymi 36. Zmierzone 12 września przy pierwszym przebiegu
 * z panelem: skan wyczerpał limit 160 ekranów, mając w kolejce jeszcze 139,
 * i dostał 26 × 429 — czyli przekroczył WŁASNYM ruchem limit zapytań
 * (`throttle:60,1` na `admin_uzytkownicy`). Raport mówił wtedy „26 innych
 * kodów" o sprawnym serwisie.
 *
 * Wszystkie te odnośniki prowadzą do jednej trasy, a martwy przycisk jest
 * właściwością trasy, nie kombinacji filtrów. Warianty ponad próg są WYPISANE
 * w meldunku z nazwy — pominięcie nazwane, nie ciche.
 */
const LIMIT_WARIANTOW_SCIEZKI = 10;

// --------------------------------------------------------------------------
// Ekrany zalążkowe
// --------------------------------------------------------------------------

/*
 * Skan idzie wszerz od tych adresów i dalej po tym, co sam znajdzie —
 * bo D-053 mówi o przycisku WIDOCZNYM dla człowieka, a widoczne jest to,
 * do czego da się dojść odnośnikiem.
 *
 * Na liście stoją więc punkty WEJŚCIA, nie komplet ekranów: strony, na które
 * wchodzi się z zewnątrz (wyszukiwarka, list, zakładka), oraz te, których
 * nikt nie linkuje, a mimo to istnieją.
 */
const ZALAZKI_GOSC = [
  '/',
  '/odkryj',
  '/login',
  '/register',
  '/szukaj?q=rosol',
  '/tagi',
  '/pomoc',
  '/zasady',
  '/o-kuking',
  '/regulamin',
  '/prywatnosc',
  '/napisz-do-nas',
  '/zglos-nielegalna-tresc',
  '/nie-pamietam-hasla',
  '/logowanie/link',
  '/odwolanie',
  '/cofnij-usuniecie-konta',
  /*
   * EKRANY POTWIERDZENIA — dopisane z tego samego powodu co onboarding wyżej.
   * Stoi na nich po jednym przycisku „wracam do serwisu" i widzi go człowiek,
   * który właśnie coś wysłał; nie prowadzi do nich żaden odnośnik, bo
   * wchodzi się tam przez przekierowanie po `POST`, którego ten skrypt
   * świadomie nie wysyła (granica 1).
   */
  '/napisz-do-nas/dziekujemy',
  '/zglos-nielegalna-tresc/przyjete',
  `/@${KONTO}`,
];

const ZALAZKI_ZALOGOWANY = [
  '/home',
  '/',
  '/odkryj',
  '/dodaj',
  '/dodaj/zdjecie',
  '/dodaj/przepis',
  '/dodaj/przepis/jedna-strona',
  '/zeszyt',
  '/powiadomienia',
  '/ustawienia',
  '/ustawienia/profil',
  '/ustawienia/zdjecie',
  '/ustawienia/tagi',
  '/ustawienia/czytelnosc',
  '/ustawienia/prywatnosc',
  '/ustawienia/twoje-dane',
  '/ustawienia/bezpieczenstwo',
  '/ustawienia/e-mail',
  '/ustawienia/2fa',
  '/ustawienia/2fa/kody-zapasowe',
  '/zgloszenia',
  '/szukaj?q=rosol',
  '/szukaj?q=czegotunieznajdziesz',
  '/tagi',
  '/napisz-do-nas',
  /*
   * ONBOARDING I POTWIERDZENIE ADRESU — DOPISANE, BO SKAN SAM TAM NIE WCHODZIŁ.
   *
   * Te cztery ekrany widzi KAŻDY nowo założony człowiek, zanim zobaczy
   * cokolwiek innego. Nie prowadzi do nich jednak żaden odnośnik z serwisu:
   * `/witaj/*` pokazuje się raz, po rejestracji, a `/potwierdz-email` — po
   * kliknięciu w liście. Skan chodzący po odnośnikach nie miał jak ich
   * odwiedzić, więc cała ścieżka pierwszego dnia była w tym audycie
   * milczącym pominięciem.
   */
  '/witaj/zainteresowania',
  '/witaj/ludzie',
  '/witaj/gotowe',
  '/potwierdz-email',
  `/@${KONTO}`,
  '/@basia',
  '/@marek',
];

/*
 * Panel moderacji. Wszystkie osiem ekranów wprost, bo do panelu nie prowadzi
 * z serwisu żaden odnośnik dla zwykłego człowieka — skan sam by tam nie
 * trafił, a nietrafienie wyglądałoby jak „nic tam nie ma".
 */
const ZALAZKI_MODERATOR = [
  '/admin/zgloszenia',
  '/admin/sygnaly',
  '/admin/wiadomosci',
  '/admin/odwolania',
  '/admin/bez-odpowiedzi',
  '/admin/kolaz-powitalny',
  '/admin/kuking-na-dzis',
  '/admin/tagi-promowane',
  '/admin/uzytkownicy',
  '/home',
];

/*
 * SŁUSZNE 403 — odmowy, które są zachowaniem poprawnym, a nie martwym
 * przyciskiem. Każdy wpis ma UZASADNIENIE, bo lista bez uzasadnień jest
 * wyciszaczem, a nie listą.
 *
 * Wszystko, co odpowie 403 i NIE pasuje do żadnego wzorca stąd, jest w tym
 * skrypcie porażką — „nie wiemy" nie liczy się jako „w porządku" (pułapka 5).
 */
const SLUSZNE_403 = [
  {
    wzorzec: /^\/podsumowanie\/(wypisz|wracam)\//,
    powod: 'Adres wymaga podpisu aplikacji (middleware `signed`) — bez podpisu 403 jest '
      + 'jedyną poprawną odpowiedzią, bo inaczej dałoby się wypisać kogoś, znając samo konto.',
  },
];

// --------------------------------------------------------------------------
// GRANICA 3: baza
// --------------------------------------------------------------------------

function nazwaBazy() {
  return (process.env.DB_DATABASE || BAZA_DOMYSLNA).trim();
}

/**
 * Odmawia startu na bazie, której skasowanie byłoby cudzą stratą.
 * To jest zabezpieczenie, nie przypomnienie: kończy proces kodem 1.
 */
function sprawdzBaze(stawiamySerwer) {
  const baza = nazwaBazy();

  if ((process.env.APP_ENV || '').trim() === 'production') {
    console.error('ODMAWIAM STARTU: APP_ENV=production. Ten skrypt robi `migrate:fresh` '
      + '— na produkcji nie wolno go uruchomić nawet z ciekawości (AGENTS.md §6).');
    process.exit(1);
  }

  if (BAZY_ZAKAZANE.includes(baza.toLowerCase())) {
    console.error(`ODMAWIAM STARTU: baza „${baza}" jest na liście zakazanych `
      + `(${BAZY_ZAKAZANE.join(', ')}). Ten skrypt robi na swojej bazie `
      + '`migrate:fresh`, czyli kasuje wszystko, co w niej stoi — a to są bazy, '
      + 'z których korzysta praca kogoś innego. Podaj własną: '
      + 'DB_DATABASE=kuking_moja node scripts/martwe-przyciski.mjs');
    process.exit(1);
  }

  if (baza === '') {
    console.error('ODMAWIAM STARTU: pusta nazwa bazy. Ustaw DB_DATABASE albo zostaw '
      + `wartość domyślną („${BAZA_DOMYSLNA}").`);
    process.exit(1);
  }

  if (stawiamySerwer) {
    console.log(`Baza pomiarowa: ${baza} (zostanie wyczyszczona przez migrate:fresh).`);
  }

  return baza;
}

// --------------------------------------------------------------------------
// GRANICA 2: tylko 127.0.0.1
// --------------------------------------------------------------------------

/** Adres bazowy audytu; wypełniany po podniesieniu serwera. */
let POCZATEK = null;

/**
 * Czy ten adres należy do audytowanego serwisu. Host MUSI być `127.0.0.1`
 * — nie `localhost`, nie nazwa maszyny, nie adres publiczny.
 */
function czyNasz(adres) {
  try {
    const u = new URL(adres);

    return u.hostname === '127.0.0.1' && u.origin === POCZATEK;
  } catch {
    return false;
  }
}

function sprawdzPoczatek(adres) {
  let u;

  try {
    u = new URL(adres);
  } catch {
    console.error(`ODMAWIAM STARTU: „${adres}" nie jest adresem.`);
    process.exit(1);
  }

  if (u.hostname !== '127.0.0.1') {
    console.error(`ODMAWIAM STARTU: adres ${adres} wskazuje host „${u.hostname}", `
      + 'a ten skrypt chodzi wyłącznie po 127.0.0.1. Audyt wchodzi w każdy znaleziony '
      + 'odnośnik — puszczony na cudzy serwis byłby skanowaniem czyjejś strony, '
      + 'a puszczony na produkcję chodziłby po prawdziwych danych.');
    process.exit(1);
  }

  return u.origin;
}

// --------------------------------------------------------------------------
// GRANICA 1: żadnych metod zapisujących w fazie audytu
// --------------------------------------------------------------------------

/** `logowanie` dopuszcza dwa nazwane POST-y; `audyt` nie dopuszcza żadnego. */
let faza = 'logowanie';

/*
 * ZAMKNIĘTA LISTA ADRESÓW, POD KTÓRE WOLNO WYSŁAĆ `POST` — i tylko w fazie
 * logowania, która gaśnie, zanim ruszy audyt.
 *
 * Oba wpisy są tu z konieczności i żaden nie kasuje danych:
 *   `/login`                 — bez zalogowania nie da się zobaczyć połowy
 *                              serwisu, a o tę połowę w tym audycie chodzi;
 *   `/ustawienia/2fa/wlacz`  — panel moderacji stoi za `EnsureModeratorHasTwoFactor`
 *                              i automat przechodzi przez ten zamek TĄ SAMĄ
 *                              drogą co człowiek (przepisuje sekret z ekranu,
 *                              liczy kod, wpisuje). Osłabienie middleware
 *                              „na chwilę do pomiaru" zostałoby w repozytorium
 *                              na zawsze — patrz `scripts/dostepnosc.mjs`.
 *
 * Wszystko poza tą listą przerywa straż, niezależnie od tego, kto to zlecił.
 */
const POST_WOLNO_PRZY_LOGOWANIU = ['/login', '/ustawienia/2fa/wlacz'];

/** Żądania przerwane przez straż — liczymy je i wypisujemy, nie chowamy. */
const przerwane = [];

/**
 * Straż żądań wpięta w każdy kontekst przeglądarki. To jest ta połowa granic
 * 1 i 2, której nie da się ominąć przez to, że strona sama coś wyśle.
 */
async function strazZadania(trasa, zadanie) {
  const metoda = zadanie.method().toUpperCase();
  const adres = zadanie.url();

  if (! czyNasz(adres)) {
    przerwane.push({ powod: 'poza 127.0.0.1', metoda, adres });
    await trasa.abort();

    return;
  }

  const wolno = metoda === 'GET'
    || metoda === 'HEAD'
    || (faza === 'logowanie' && metoda === 'POST' && POST_WOLNO_PRZY_LOGOWANIU.includes(new URL(adres).pathname));

  if (! wolno) {
    przerwane.push({ powod: `metoda ${metoda} w fazie „${faza}"`, metoda, adres });
    await trasa.abort();

    return;
  }

  await trasa.continue();
}

/**
 * Jedyna droga pobrania czegokolwiek po adresie. Rzuca przy każdej metodzie
 * innej niż GET — żeby dołożenie „a tu jeszcze szybko POST" wymagało zmiany
 * tej funkcji, a nie przeoczenia w wywołaniu.
 */
async function pobierz(kontekst, adres, metoda = 'GET') {
  if (metoda !== 'GET') {
    throw new Error(`GRANICA 1: ten skrypt nie wysyła metody ${metoda}. `
      + 'Formularze zapisujące sprawdzamy przez tablicę tras, nie przez wysłanie.');
  }

  if (! czyNasz(adres)) {
    throw new Error(`GRANICA 2: ${adres} jest poza 127.0.0.1.`);
  }

  return kontekst.request.get(adres, { maxRedirects: 0, timeout: 20000 });
}

// --------------------------------------------------------------------------
// Serwer
// --------------------------------------------------------------------------

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
  return { ...process.env, DB_DATABASE: nazwaBazy() };
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

function zalozBazeJesliTrzeba() {
  const baza = nazwaBazy();

  try {
    execFileSync('psql', ['-h', process.env.DB_HOST || '127.0.0.1',
      '-U', process.env.DB_USERNAME || 'kuking', '-d', baza, '-c', 'select 1'], {
      stdio: 'ignore',
      env: { ...process.env, PGPASSWORD: process.env.DB_PASSWORD || 'kuking' },
    });

    return;
  } catch { /* nie ma jej — zakładamy niżej */ }

  console.log(`Baza ${baza} nie istnieje — zakładam.`);
  execFileSync('createdb', ['-h', process.env.DB_HOST || '127.0.0.1',
    '-U', process.env.DB_USERNAME || 'kuking', baza], {
    stdio: 'inherit',
    env: { ...process.env, PGPASSWORD: process.env.DB_PASSWORD || 'kuking' },
  });
}

async function podniesSerwer() {
  if (process.env.ADRES) {
    return { adres: sprawdzPoczatek(process.env.ADRES), zamknij: () => {} };
  }

  zalozBazeJesliTrzeba();

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  /* Strona wciąga zbudowany arkusz przez manifest Vite. Bez przebudowania
     chodzilibyśmy po POPRZEDNIEJ wersji strony. */
  console.log('Buduję arkusz (vite build)...');
  execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore', env: process.env });

  console.log('Podłączam storage (php artisan storage:link)...');
  execFileSync('php', ['artisan', 'storage:link'], { stdio: 'ignore', env: env() });

  console.log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const bledy = [];

  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = process.env.PORT ? Number(process.env.PORT) : await wolnyPort();
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

    if (wstal) return { adres: sprawdzPoczatek(adres), zamknij: () => proces.kill('SIGTERM') };

    proces.kill('SIGKILL');
    bledy.push(`  podejście ${podejscie}, port ${port}: `
      + (umarl !== null ? `proces zakończył się kodem ${umarl}` : 'brak odpowiedzi z /health')
      + (dziennik.length > 0 ? `\n${dziennik.join('').trimEnd()}` : ''));
  }

  throw new Error(`Nie udało się podnieść \`php artisan serve\`.\n${bledy.join('\n')}`);
}

// --------------------------------------------------------------------------
// Tablica tras — tak sprawdzamy formularze ZAPISUJĄCE, nie wysyłając ich
// --------------------------------------------------------------------------

/**
 * `route:list` w postaci par (metoda, wyrażenie na ścieżkę). Pozwala
 * odpowiedzieć na jedyne pytanie, które ma tu sens bez wysyłania zapisu:
 * czy ten przycisk w ogóle ma dokąd prowadzić.
 */
function tablicaTras() {
  const surowe = execFileSync('php', ['artisan', 'route:list', '--json'], { env: env() }).toString();
  const trasy = JSON.parse(surowe);

  return trasy.flatMap((t) => {
    /*
     * KOLEJNOŚĆ MA ZNACZENIE: najpierw wyjmujemy `{parametr}` na znacznik,
     * potem ucieczkę znaków specjalnych, na końcu wstawiamy wyrażenie.
     *
     * Odwrotna kolejność (ucieczka najpierw) zamienia `{username}` na
     * `\{username\}`, a wtedy podmiana parametru zostawia w środku wzorca
     * osierocony ukośnik odwrotny. Efekt zmierzony przy pierwszym przebiegu
     * 12 września: dwadzieścia żywych tras — `@{username}/obserwuj`,
     * `zglos/{type}/{id}`, `przepisy/{recipe}/ugotowalem` — zameldowało się
     * jako „BRAK TRASY". Fałszywy alarm skanu jest tu równie kosztowny jak
     * przeoczenie, bo uczy czytać raport jako szum.
     */
    const ZNACZNIK_WYMAGANY = '\u0001';
    const ZNACZNIK_OPCJONALNY = '\u0002';

    const wzorzec = new RegExp(`^/${
      t.uri
        .replace(/\{[^}]+\?\}/g, ZNACZNIK_OPCJONALNY)
        .replace(/\{[^}]+\}/g, ZNACZNIK_WYMAGANY)
        .replace(/[.+^${}()|[\]\\?*]/g, '\\$&')
        .split(ZNACZNIK_OPCJONALNY).join('[^/]*')
        .split(ZNACZNIK_WYMAGANY).join('[^/]+')
    }/?$`);

    return String(t.method).split('|').map((m) => ({ metoda: m, wzorzec, nazwa: t.name, uri: t.uri }));
  });
}

function trasaIstnieje(trasy, metoda, sciezka) {
  const s = sciezka === '' ? '/' : sciezka;

  return trasy.some((t) => t.metoda === metoda && t.wzorzec.test(s));
}

// --------------------------------------------------------------------------
// Zbieranie odnośników i formularzy z jednego ekranu
// --------------------------------------------------------------------------

/*
 * Wszystko czytane z ułożonego dokumentu, a nie z szablonu: liczy się to,
 * co dostaje człowiek. Odnośniki w zamkniętym `<details>` też są zbierane —
 * menu „więcej" na karcie wpisu jest zamknięte, a jego pozycje są przyciskami
 * jak każde inne.
 */
const ZBIERZ = () => {
  const widoczny = (el) => {
    const st = getComputedStyle(el);

    if (st.display === 'none' || st.visibility === 'hidden') return false;

    return el.getClientRects().length > 0 || el.closest('details') !== null;
  };

  const odnosniki = [...document.querySelectorAll('a[href]')].map((a) => ({
    href: a.getAttribute('href'),
    pelny: a.href,
    tekst: (a.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60)
      || a.getAttribute('aria-label') || '(bez napisu)',
    widoczny: widoczny(a),
    kotwicaMaCel: a.getAttribute('href').startsWith('#')
      ? (a.getAttribute('href') === '#'
        ? false
        : document.getElementById(decodeURIComponent(a.getAttribute('href').slice(1))) !== null)
      : null,
  }));

  /*
   * `<form method="dialog">` NIE JEST FORMULARZEM DO SERWERA — to natywny
   * sposób zamknięcia `<dialog>` (u nas: przycisk „Zamknij" w powiększeniu
   * zdjęcia, `components/layout.blade.php`). Przeglądarka nie wysyła z niego
   * ani jednego bajta, więc pytanie „czy ta trasa istnieje" nie ma tu sensu.
   * Pierwszy przebieg tego skryptu wypisał takich „martwych przycisków" 96 —
   * i wszystkie były sprawne.
   */
  /*
   * CEL FORMULARZA CZYTAMY Z ATRYBUTU, NIGDY Z WŁAŚCIWOŚCI `f.action`.
   *
   * Nazwane pola formularza PRZESŁANIAJĄ jego własne właściwości: formularz
   * z `<button name="action">` oddaje na `f.action` ten przycisk, a nie adres.
   * W tym serwisie tak jest w czterech miejscach — `/dodaj/przepis`,
   * `/przepisy/{p}/szczegoly` i formularz decyzji w `/admin/zgloszenia`
   * (`<input type="radio" name="action">`). Pierwsza wersja tego skanu
   * dostawała stamtąd łańcuch „ref: <Node>", nie umiała go dopasować do
   * naszego adresu i zaliczała te formularze do „odnośników na zewnątrz",
   * czyli po cichu ich NIE SPRAWDZAŁA. Wyszło to dopiero z listy hostów
   * zewnętrznych, na której stał host pusty.
   */
  const celFormularza = (f) => {
    const surowy = f.getAttribute('action');

    return new URL(surowy === null || surowy === '' ? location.href : surowy, location.href).href;
  };

  const formularze = [...document.querySelectorAll('form')]
    .filter((f) => (f.getAttribute('method') || '').toLowerCase() !== 'dialog')
    .map((f) => {
      const podmiana = f.querySelector('input[name="_method"]');
      const metoda = (podmiana?.value || f.getAttribute('method') || 'GET').toUpperCase();
      const przycisk = f.querySelector('button, input[type="submit"]');

      /* Wartości domyślne — potrzebne tylko formularzom GET, które wolno wysłać. */
      const pola = [...f.querySelectorAll('input, select, textarea')]
        .filter((p) => p.name && p.type !== 'submit' && p.type !== 'button' && p.type !== 'file')
        .filter((p) => ! ((p.type === 'checkbox' || p.type === 'radio') && ! p.checked))
        .map((p) => [p.name, p.value ?? '']);

      return {
        action: f.getAttribute('action'),
        pelny: celFormularza(f),
        metoda,
        napis: (przycisk?.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60)
          || przycisk?.getAttribute('aria-label') || '(bez napisu)',
        pola,
      };
    });

  /* DOWÓD ZALOGOWANIA. Wylogowanie jest jedynym formularzem, który stoi na
     każdym ekranie osoby zalogowanej i nie stoi na żadnym ekranie gościa. */
  const maWylogowanie = [...document.querySelectorAll('form')]
    .some((f) => new URL(celFormularza(f)).pathname === '/logout');

  return { odnosniki, formularze, maWylogowanie, tytul: document.title };
};

// --------------------------------------------------------------------------
// Przebieg
// --------------------------------------------------------------------------

function dodajZrodlo(mapa, klucz, wartosc, ekran) {
  const istniejacy = mapa.get(klucz);

  if (istniejacy) {
    istniejacy.ekrany.add(ekran);

    return istniejacy;
  }

  const nowy = { ...wartosc, ekrany: new Set([ekran]) };

  mapa.set(klucz, nowy);

  return nowy;
}

function sciezkaZ(adres) {
  const u = new URL(adres);

  return u.pathname + u.search;
}

async function przejdzSerwis(przegladarka, etykieta, zalazki, stan) {
  const kontekst = await przegladarka.newContext(stan ? { storageState: stan } : {});

  await kontekst.route('**', strazZadania);

  const strona = await kontekst.newPage();

  const doOdwiedzenia = zalazki.map((z) => ({ adres: `${POCZATEK}${z}`, glebokosc: 0 }));
  const odwiedzone = new Map();          // ścieżka ekranu → { kod, tytul }
  const odnosniki = new Map();           // pełny adres → { tekst, widoczny, ekrany }
  const formularze = new Map();          // metoda + adres → { napis, pola, ekrany }
  const zewnetrzne = new Map();
  const kotwice = [];
  const inneSchematy = new Map();
  const wariantySciezki = new Map();     // ścieżka bez zapytania → ile wariantów wzięliśmy
  const pominieteWarianty = [];
  const nieczytelneFormularze = [];
  let ekranyZWylogowaniem = 0;

  while (doOdwiedzenia.length > 0 && odwiedzone.size < LIMIT_EKRANOW) {
    const { adres, glebokosc } = doOdwiedzenia.shift();
    const sciezka = sciezkaZ(adres);

    if (odwiedzone.has(sciezka)) continue;

    let kod = null;
    let zebrane = null;

    let wyladowal = sciezka;

    try {
      const odp = await strona.goto(adres, { waitUntil: 'domcontentloaded', timeout: 25000 });

      kod = odp?.status() ?? null;
      wyladowal = sciezkaZ(strona.url());
      zebrane = await strona.evaluate(ZBIERZ);
    } catch (e) {
      odwiedzone.set(sciezka, { kod: null, blad: String(e.message).split('\n')[0] });
      continue;
    }

    /*
     * LICZY SIĘ ADRES, NA KTÓRYM NAPRAWDĘ WYLĄDOWALIŚMY. Zalogowany wpuszczony
     * na `/login` dostaje przekierowanie na `/home`; bez tego rozróżnienia ten
     * sam ekran wchodziłby do zestawienia pod trzema nazwami (`/login`,
     * `/register`, `/home`), liczba „ekranów obeszłem" byłaby zawyżona
     * o ekrany, których nie było, a odnośniki z tablicy meldowałyby się jako
     * stojące „na ekranie logowania".
     */
    odwiedzone.set(sciezka, { kod, tytul: zebrane.tytul, wyladowal });

    if (wyladowal !== sciezka) {
      if (odwiedzone.has(wyladowal)) continue;

      odwiedzone.set(wyladowal, { kod, tytul: zebrane.tytul, przezPrzekierowanieZ: sciezka });
    }

    if (zebrane.maWylogowanie) ekranyZWylogowaniem++;

    for (const a of zebrane.odnosniki) {
      const surowy = a.href;

      if (surowy.startsWith('#')) {
        kotwice.push({ ekran: wyladowal, href: surowy, tekst: a.tekst, maCel: a.kotwicaMaCel });
        continue;
      }

      let u;

      try {
        u = new URL(a.pelny);
      } catch {
        kotwice.push({ ekran: wyladowal, href: surowy, tekst: a.tekst, maCel: false });
        continue;
      }

      if (u.protocol !== 'http:' && u.protocol !== 'https:') {
        dodajZrodlo(inneSchematy, a.pelny, { tekst: a.tekst }, wyladowal);
        continue;
      }

      if (! czyNasz(u.href)) {
        dodajZrodlo(zewnetrzne, u.href, { tekst: a.tekst }, wyladowal);
        continue;
      }

      const bezKotwicy = `${u.origin}${u.pathname}${u.search}`;

      /* Próg wariantów jednej ścieżki — uzasadnienie przy stałej. Liczymy
         TYLKO nowe adresy: powtórzenie znanego wariantu nic nie kosztuje. */
      if (! odnosniki.has(bezKotwicy)) {
        const ile = (wariantySciezki.get(u.pathname) ?? 0) + 1;

        wariantySciezki.set(u.pathname, ile);

        if (ile > LIMIT_WARIANTOW_SCIEZKI) {
          pominieteWarianty.push({ adres: sciezkaZ(bezKotwicy), tekst: a.tekst, ekran: wyladowal });
          continue;
        }
      }

      dodajZrodlo(odnosniki, bezKotwicy, { tekst: a.tekst, widoczny: a.widoczny }, wyladowal);

      if (glebokosc < LIMIT_GLEBOKOSCI && ! odwiedzone.has(sciezkaZ(bezKotwicy))) {
        doOdwiedzenia.push({ adres: bezKotwicy, glebokosc: glebokosc + 1 });
      }
    }

    for (const f of zebrane.formularze) {
      let u;

      try {
        u = new URL(f.pelny);
      } catch {
        /* Brak wyniku to nie jest „w porządku" (pułapka 5). Formularz,
           którego adresu nie umiemy rozczytać, jest niesprawdzony — i ma
           o tym powiedzieć, zamiast wypaść z zestawienia. */
        nieczytelneFormularze.push({ adres: String(f.pelny), napis: f.napis, ekran: wyladowal });
        continue;
      }

      /* `new URL()` połyka prawie wszystko: łańcuch „ref: <Node>" rozkłada na
         schemat „ref:" i przechodzi. Bez tego sprawdzenia formularz o takim
         adresie wyszedłby z zestawienia jako „odnośnik na zewnątrz" i nikt by
         się nie dowiedział, że go nie sprawdziliśmy. */
      if (u.protocol !== 'http:' && u.protocol !== 'https:') {
        nieczytelneFormularze.push({ adres: String(f.pelny), napis: f.napis, ekran: wyladowal });
        continue;
      }

      if (! czyNasz(u.href)) {
        dodajZrodlo(zewnetrzne, u.href, { tekst: `formularz: ${f.napis}` }, wyladowal);
        continue;
      }

      dodajZrodlo(
        formularze,
        `${f.metoda} ${u.origin}${u.pathname}`,
        { metoda: f.metoda, adres: `${u.origin}${u.pathname}${u.search}`, napis: f.napis, pola: f.pola },
        wyladowal,
      );
    }
  }

  return {
    etykieta,
    kontekst,
    odwiedzone,
    odnosniki,
    formularze,
    zewnetrzne,
    kotwice,
    inneSchematy,
    pominieteWarianty,
    nieczytelneFormularze,
    ekranyZWylogowaniem,
    nieodwiedzone: doOdwiedzenia.length,
  };
}

/**
 * Kod odpowiedzi pod adresem, z przejściem przekierowań krok po kroku.
 * Przekierowanie poza 127.0.0.1 KOŃCZY łańcuch — granica 2 obowiązuje także
 * wtedy, gdy to serwis nas tam wysyła.
 */
async function sprawdzAdres(kontekst, adres) {
  const lancuch = [];
  let biezacy = adres;

  for (let krok = 0; krok < 5; krok++) {
    let odp;

    try {
      odp = await pobierz(kontekst, biezacy);
    } catch (e) {
      return { kod: null, lancuch, blad: String(e.message).split('\n')[0] };
    }

    const kod = odp.status();

    if (kod < 300 || kod >= 400) return { kod, lancuch, koncowy: biezacy };

    const dokad = odp.headers()['location'];

    if (! dokad) return { kod, lancuch, koncowy: biezacy, blad: 'przekierowanie bez nagłówka Location' };

    const cel = new URL(dokad, biezacy).href;

    lancuch.push(`${kod} → ${czyNasz(cel) ? sciezkaZ(cel) : cel}`);

    if (! czyNasz(cel)) {
      return { kod, lancuch, koncowy: cel, pozaSerwisem: true };
    }

    biezacy = cel;
  }

  return { kod: null, lancuch, blad: 'pętla przekierowań (5 kroków bez końca)' };
}

// --------------------------------------------------------------------------
// Sesja moderatora — przez prawdziwe 2FA, bez osłabiania zamka
// --------------------------------------------------------------------------

/** Bieżący kod TOTP, liczony tą samą biblioteką, którą serwis go sprawdza. */
function kodTotp(sekret) {
  const kod = execFileSync('php', ['artisan', 'tinker', '--execute',
    `echo (new PragmaRX\\Google2FA\\Google2FA)->getCurrentOtp('${sekret}');`,
  ], { env: env() }).toString().trim();

  /* Bez tego komunikat `tinkera` wjechałby do pola „kod", a skrypt przewracałby
     się dopiero na „Kod jest nieprawidłowy" — czyli w miejscu wskazującym na
     serwis, a nie na to, że kodu w ogóle nie policzyliśmy. */
  if (! /^\d{6}$/.test(kod)) {
    throw new Error(`Nie udało się policzyć kodu TOTP — dostałem: ${JSON.stringify(kod)}`);
  }

  return kod;
}

/*
 * CZEGO TU NIE MA I DLACZEGO. Nie ma wyłączenia middleware `moderator.2fa`,
 * nie ma ustawienia `two_factor_confirmed_at` w bazie „na skróty" i nie ma
 * konta z 2FA wsianego przez seeder. Panel widzi dane WSZYSTKICH ludzi
 * w serwisie; audyt martwych przycisków nie jest powodem, żeby ten zamek
 * osłabiać choćby w środowisku pomiarowym — obejście napisane „na chwilę"
 * zostaje w repozytorium na zawsze i pokazuje następnej osobie, że tak wolno.
 *
 * Automat robi dokładnie to, co człowiek: loguje się hasłem, wchodzi na ekran
 * włączania 2FA, PRZEPISUJE sekret pokazany tam jako zwykły tekst, liczy z
 * niego kod i wpisuje go w formularz. Serwis sprawdza ten kod normalną drogą.
 */
async function stanModeratora(przegladarka) {
  const kontekst = await przegladarka.newContext();

  await kontekst.route('**', strazZadania);

  const strona = await kontekst.newPage();

  await strona.goto(`${POCZATEK}/login`);
  await strona.fill('input[name="login"]', KONTO_MODERATORA);
  await strona.fill('input[name="password"]', HASLO);
  await Promise.all([
    strona.waitForURL((u) => ! u.pathname.endsWith('/login'), { timeout: 15000 }),
    /* NIE `button[type="submit"]`: dla zalogowanego pierwszym takim przyciskiem
       w dokumencie jest „Wyloguj się" z nawigacji bocznej. Celujemy po napisie. */
    strona.getByRole('button', { name: 'Zaloguj się' }).first().click(),
  ]);

  await strona.goto(`${POCZATEK}/ustawienia/2fa/wlacz`);

  const sekret = (await strona.locator('.sekret-do-przepisania').innerText()).trim();

  await strona.fill('input[name="code"]', kodTotp(sekret));
  // Włączenie 2FA prosi też o obecne hasło (#1376, D-245).
  await strona.fill('form[action$="/ustawienia/2fa/wlacz"] input[name="password"]', HASLO);
  await Promise.all([
    strona.waitForURL((u) => ! u.pathname.endsWith('/wlacz'), { timeout: 15000 }),
    strona.getByRole('button', { name: 'Potwierdź i włącz' }).click(),
  ]);

  /* SPRAWDZENIE WEJŚCIA. Bez niego dziewięć ekranów panelu odesłałoby na
     `/login`, a raport zameldowałby dziewięć osobnych usterek zamiast jednej
     prawdziwej: „automat nie umie wejść do panelu". */
  const odpowiedz = await strona.goto(`${POCZATEK}/admin/uzytkownicy`, { waitUntil: 'domcontentloaded' });
  const kod = odpowiedz?.status() ?? 0;
  const sciezka = new URL(strona.url()).pathname;

  if (kod !== 200 || sciezka !== '/admin/uzytkownicy') {
    throw new Error(`Nie wszedłem do panelu moderacji: /admin/uzytkownicy odpowiedziało ${kod} `
      + `i wylądowało na ${sciezka}. Panel nie zostałby zaudytowany wcale. Sprawdź, czy `
      + `DemoSeeder nadal tworzy konto „${KONTO_MODERATORA}" z rolą moderatora — `
      + 'NIE wyłączaj middleware `moderator.2fa`, żeby to obejść.');
  }

  const stan = await kontekst.storageState();

  await kontekst.close();

  return stan;
}

// --------------------------------------------------------------------------
// Uruchomienie
// --------------------------------------------------------------------------

const stawiamySerwer = ! process.env.ADRES;

sprawdzBaze(stawiamySerwer);

const CHROMIUM = znajdzChromium();
const { adres: poczatek, zamknij } = await podniesSerwer();

POCZATEK = poczatek;

console.log(`Serwer: ${POCZATEK}`);

const TRASY = tablicaTras();

console.log(`Tablica tras: ${TRASY.length} par (metoda, adres).`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

let przebiegi = [];
let bladKrytyczny = null;

try {
  /*
   * LOGUJEMY SIĘ RAZ I PRZENOSIMY CIASTECZKO PRZEZ `storageState`.
   * `config/kuking.php` daje pięć prób na minutę z pary konto+adres. Przy
   * logowaniu na każdym ekranie audyt leciałby po kilku ekranach na stronie
   * „Za dużo prób" — gdzie WSZYSTKO odpowiada 200 i raport wygląda na udany.
   */
  const kontekstLogowania = await przegladarka.newContext();

  await kontekstLogowania.route('**', strazZadania);

  const stronaLogowania = await kontekstLogowania.newPage();

  await stronaLogowania.goto(`${POCZATEK}/login`);
  await stronaLogowania.fill('input[name="login"]', KONTO);
  await stronaLogowania.fill('input[name="password"]', HASLO);
  await Promise.all([
    stronaLogowania.waitForURL((u) => ! u.pathname.endsWith('/login'), { timeout: 15000 }),
    stronaLogowania.click('button[type="submit"]'),
  ]);

  const sesja = await kontekstLogowania.storageState();

  await kontekstLogowania.close();

  const sesjaModeratora = await stanModeratora(przegladarka);

  /* Od tej chwili żadna metoda zapisująca nie ma prawa wyjść z przeglądarki. */
  faza = 'audyt';

  console.log('Przechodzę serwis jako zalogowany...');
  przebiegi.push(await przejdzSerwis(przegladarka, 'zalogowany', ZALAZKI_ZALOGOWANY, sesja));

  console.log('Przechodzę panel moderacji...');
  przebiegi.push(await przejdzSerwis(przegladarka, 'moderator', ZALAZKI_MODERATOR, sesjaModeratora));

  console.log('Przechodzę serwis jako gość...');
  przebiegi.push(await przejdzSerwis(przegladarka, 'gość', ZALAZKI_GOSC, null));

  for (const przebieg of przebiegi) {
    console.log(`Sprawdzam odnośniki przebiegu „${przebieg.etykieta}" `
      + `(${przebieg.odnosniki.size} adresów)...`);

    przebieg.wyniki = [];

    for (const [adres, dane] of przebieg.odnosniki) {
      const wynik = await sprawdzAdres(przebieg.kontekst, adres);

      przebieg.wyniki.push({ adres, sciezka: sciezkaZ(adres), ...dane, ekrany: [...dane.ekrany], ...wynik });
    }

    /* Formularze GET wolno wysłać — to zwykłe wejście pod adres z parametrami. */
    przebieg.wynikiFormularzy = [];

    for (const [, f] of przebieg.formularze) {
      const ekrany = [...f.ekrany];

      if (f.metoda === 'GET') {
        const u = new URL(f.adres);

        for (const [nazwa, wartosc] of f.pola) u.searchParams.set(nazwa, wartosc);

        const wynik = await sprawdzAdres(przebieg.kontekst, u.href);

        przebieg.wynikiFormularzy.push({
          metoda: 'GET', adres: u.href, sciezka: sciezkaZ(u.href), napis: f.napis, ekrany, ...wynik,
        });
        continue;
      }

      /* GRANICA 1: zapisu nie wysyłamy. Pytamy tablicę tras, czy przycisk
         ma dokąd prowadzić — to odróżnia martwy przycisk od działającego,
         nie ruszając ani jednego wiersza w bazie. */
      const sciezka = new URL(f.adres).pathname;
      const istnieje = trasaIstnieje(TRASY, f.metoda, sciezka);

      przebieg.wynikiFormularzy.push({
        metoda: f.metoda,
        adres: f.adres,
        sciezka: sciezkaZ(f.adres),
        napis: f.napis,
        ekrany,
        niewyslany: true,
        kod: istnieje ? 'TRASA JEST' : 'BRAK TRASY',
      });
    }

    await przebieg.kontekst.close();
  }
} catch (e) {
  bladKrytyczny = e;
} finally {
  await przegladarka.close();
  zamknij();
}

if (bladKrytyczny) {
  console.error(`\nBŁĄD KRYTYCZNY: ${bladKrytyczny.message}`);

  /*
   * CZERWIEŃ BEZ PRZECZYTANEJ PRZYCZYNY NIE JEST INFORMACJĄ
   * (docs/PULAPKI_TESTOW.md, pułapka 8b). Najczęstszym powodem, dla którego
   * ten skrypt przewraca się na logowaniu, jest WŁASNA straż żądań — a sam
   * Playwright mówi wtedy tylko „net::ERR_FAILED", czyli komunikat
   * wskazujący na serwis zamiast na nas. Zmierzone przy kontroli ujemnej
   * granicy 1: po przestawieniu fazy na „audyt" przed logowaniem skrypt
   * padał z „page.waitForURL: net::ERR_FAILED" i nie mówił ani słowa o tym,
   * że to on sam przerwał `POST /login`.
   */
  if (przerwane.length > 0) {
    console.error(`\nStraż żądań przerwała po drodze ${przerwane.length} żądań `
      + '— sprawdź, czy to nie one są przyczyną powyższego błędu:');
    for (const z of przerwane.slice(0, 10)) {
      console.error(`  ${z.powod}: ${z.metoda} ${z.adres}`);
    }
  }

  process.exit(1);
}

// --------------------------------------------------------------------------
// Meldunek
// --------------------------------------------------------------------------

let porazka = false;
const powodyPorazki = [];

function zle(powod) {
  porazka = true;
  powodyPorazki.push(powod);
}

const zalogowany = przebiegi.find((p) => p.etykieta === 'zalogowany');
const moderator = przebiegi.find((p) => p.etykieta === 'moderator');
const gosc = przebiegi.find((p) => p.etykieta === 'gość');

/* ---- Uczciwość: czy skan w ogóle chodził po serwisie (pułapka 2) --------- */

if (zalogowany.odwiedzone.size < MIN_EKRANOW_ZALOGOWANY) {
  zle(`SKAN NIE CHODZI PO SERWISIE: przebieg zalogowany obszedł tylko `
    + `${zalogowany.odwiedzone.size} ekranów, próg to ${MIN_EKRANOW_ZALOGOWANY}.`);
}

if (gosc.odwiedzone.size < MIN_EKRANOW_GOSC) {
  zle(`SKAN NIE CHODZI PO SERWISIE: przebieg gościa obszedł tylko `
    + `${gosc.odwiedzone.size} ekranów, próg to ${MIN_EKRANOW_GOSC}.`);
}

if (moderator.odwiedzone.size < MIN_EKRANOW_MODERATOR) {
  zle('SKAN NIE CHODZI PO PANELU: przebieg moderatora obszedł tylko '
    + `${moderator.odwiedzone.size} ekranów, próg to ${MIN_EKRANOW_MODERATOR}.`);
}

const razemOdnosnikow = zalogowany.odnosniki.size + moderator.odnosniki.size + gosc.odnosniki.size;

if (razemOdnosnikow < MIN_ODNOSNIKOW) {
  zle(`SKAN NIE CHODZI PO SERWISIE: zebrano ${razemOdnosnikow} odnośników, `
    + `próg to ${MIN_ODNOSNIKOW}.`);
}

const razemFormularzy = zalogowany.formularze.size + moderator.formularze.size + gosc.formularze.size;

if (razemFormularzy < MIN_FORMULARZY) {
  zle(`SKAN NIE CHODZI PO SERWISIE: zebrano ${razemFormularzy} formularzy, `
    + `próg to ${MIN_FORMULARZY}.`);
}

/* ---- Uczciwość: czy na pewno byliśmy zalogowani (pułapka 5) -------------- */

for (const p of [zalogowany, moderator]) {
  if (p.ekranyZWylogowaniem === 0) {
    zle(`PRZEBIEG „${p.etykieta.toUpperCase()}" NIE BYŁ ZALOGOWANY: na żadnym z jego ekranów `
      + 'nie było formularza `POST /logout`. Tak wygląda pomiar zrobiony na ekranie '
      + '„Za dużo prób" albo po cichym wylogowaniu — i tak samo wygląda sukces, '
      + 'gdyby nie to sprawdzenie.');
  }
}

/* Kontrola ujemna do powyższej kontroli dodatniej: gość NIE MA prawa mieć
   wylogowania. Gdyby miał, znaczyłoby to, że oba przebiegi to ten sam
   przebieg i cała różnica ról jest pozorna. */
if (gosc.ekranyZWylogowaniem > 0) {
  zle(`PRZEBIEG „GOŚĆ" BYŁ ZALOGOWANY: na ${gosc.ekranyZWylogowaniem} jego ekranach `
    + 'stoi formularz `POST /logout`. Oba przebiegi mierzą wtedy to samo.');
}

/* ---- Wyniki ------------------------------------------------------------- */

function powodSlusznego403(sciezka) {
  return SLUSZNE_403.find((w) => w.wzorzec.test(sciezka))?.powod ?? null;
}

const podsumowania = [];

for (const p of przebiegi) {
  const wszystkie = [...p.wyniki, ...p.wynikiFormularzy.filter((w) => ! w.niewyslany)];

  const grupy = {
    ok: wszystkie.filter((w) => w.kod >= 200 && w.kod < 300),
    czteryZeroTrzy: wszystkie.filter((w) => w.kod === 403),
    czteryZeroCztery: wszystkie.filter((w) => w.kod === 404),
    piecset: wszystkie.filter((w) => w.kod >= 500),
    limitZapytan: wszystkie.filter((w) => w.kod === 429),
    inne: wszystkie.filter((w) => w.kod !== null && w.kod !== 403 && w.kod !== 404 && w.kod !== 429
      && ! (w.kod >= 200 && w.kod < 300) && w.kod < 500),
    nieWiemy: wszystkie.filter((w) => w.kod === null),
  };

  podsumowania.push({ przebieg: p, wszystkie, grupy });
}

function wypisz(tytul, lista) {
  if (lista.length === 0) return;

  console.log(`\n  ${tytul} (${lista.length}):`);

  for (const w of lista) {
    const ogon = w.lancuch?.length ? `  [${w.lancuch.join(' → ')}]` : '';

    console.log(`    ${String(w.kod ?? 'NIE WIEMY').padEnd(9)} ${w.sciezka}`);
    console.log(`              napis: „${w.tekst ?? w.napis}"`);
    console.log(`              ekrany: ${w.ekrany.join(', ')}${ogon}`);

    if (w.blad) console.log(`              powód: ${w.blad}`);
  }
}

console.log('\n'.padEnd(2) + '='.repeat(78));
console.log('AUDYT MARTWYCH PRZYCISKÓW — MELDUNEK');
console.log('='.repeat(78));

for (const { przebieg: p, wszystkie, grupy } of podsumowania) {
  console.log(`\n### Przebieg: ${p.etykieta}`);
  console.log(`  ekranów obeszłem:        ${p.odwiedzone.size}`
    + (p.nieodwiedzone > 0 ? ` (w kolejce zostało ${p.nieodwiedzone} — limit ${LIMIT_EKRANOW})` : ''));
  console.log(`  odnośników sprawdzonych: ${wszystkie.length}`);
  console.log(`    200-299:               ${grupy.ok.length}`);
  console.log(`    403:                   ${grupy.czteryZeroTrzy.length}`);
  console.log(`    404:                   ${grupy.czteryZeroCztery.length}`);
  console.log(`    500+:                  ${grupy.piecset.length}`);
  console.log(`    429 (nasz własny ruch): ${grupy.limitZapytan.length}`);
  console.log(`    inne (3xx/4xx):        ${grupy.inne.length}`);
  console.log(`    NIE WIEMY:             ${grupy.nieWiemy.length}`);
  console.log(`  formularzy zapisujących: ${p.wynikiFormularzy.filter((w) => w.niewyslany).length} (nie wysłane — granica 1)`);
  console.log(`  odnośników na zewnątrz:  ${p.zewnetrzne.size} (nie odwiedzane — granica 2)`);

  wypisz('404 — KTÓRE', grupy.czteryZeroCztery);
  wypisz('500+ — KTÓRE', grupy.piecset);
  wypisz('403 — KTÓRE', grupy.czteryZeroTrzy);
  wypisz('429 — KTÓRE (to NASZ ruch przekroczył limit, nie usterka serwisu)', grupy.limitZapytan);
  wypisz('inne kody — KTÓRE', grupy.inne);
  wypisz('NIE WIEMY — KTÓRE', grupy.nieWiemy);

  const bezTrasy = p.wynikiFormularzy.filter((w) => w.niewyslany && w.kod === 'BRAK TRASY');

  if (bezTrasy.length > 0) {
    console.log(`\n  FORMULARZE BEZ TRASY (${bezTrasy.length}) — przycisk, który po kliknięciu dostanie 404/405:`);
    for (const w of bezTrasy) {
      console.log(`    ${w.metoda.padEnd(7)} ${w.sciezka}`);
      console.log(`              napis: „${w.napis}", ekrany: ${w.ekrany.join(', ')}`);
    }
  }

  if (p.pominieteWarianty.length > 0) {
    console.log(`\n  POMINIĘTE WARIANTY ZAPYTANIA (${p.pominieteWarianty.length}) — `
      + `ponad ${LIMIT_WARIANTOW_SCIEZKI} wariantów tej samej ścieżki. `
      + 'To pominięcie NAZWANE, nie ciche: trasa jest sprawdzona wariantami wcześniejszymi.');
    for (const w of p.pominieteWarianty.slice(0, 40)) {
      console.log(`    ${w.adres}`);
      console.log(`              napis: „${w.tekst}", ekran: ${w.ekran}`);
    }
    if (p.pominieteWarianty.length > 40) {
      console.log(`    …i jeszcze ${p.pominieteWarianty.length - 40} (pełna lista w pliku JSON)`);
    }
  }

  if (p.nieczytelneFormularze.length > 0) {
    console.log(`\n  FORMULARZE O NIECZYTELNYM ADRESIE (${p.nieczytelneFormularze.length}) — NIESPRAWDZONE:`);
    for (const f of p.nieczytelneFormularze) {
      console.log(`    ${f.adres}`);
      console.log(`              napis: „${f.napis}", ekran: ${f.ekran}`);
    }
  }

  for (const f of p.nieczytelneFormularze) {
    zle(`NIE WIEMY (${p.etykieta}): formularza „${f.napis}" na ekranie ${f.ekran} nie umiem `
      + `sprawdzić — jego adres to „${f.adres}". Brak wyniku to nie jest sukces (pułapka 5).`);
  }

  const zlaKotwica = p.kotwice.filter((k) => k.maCel === false);

  if (zlaKotwica.length > 0) {
    console.log(`\n  KOTWICE BEZ CELU (${zlaKotwica.length}) — odnośnik prowadzi w to samo miejsce:`);
    for (const k of zlaKotwica) {
      console.log(`    ${k.href.padEnd(24)} „${k.tekst}"   ekran: ${k.ekran}`);
    }
  }

  /* ---- Który kod jest usterką ------------------------------------------ */

  for (const w of grupy.czteryZeroCztery) {
    zle(`404 (${p.etykieta}): ${w.sciezka} — napis „${w.tekst ?? w.napis}", ekran: ${w.ekrany[0]}`);
  }

  for (const w of grupy.piecset) {
    zle(`${w.kod} (${p.etykieta}): ${w.sciezka} — napis „${w.tekst ?? w.napis}", ekran: ${w.ekrany[0]}`);
  }

  for (const w of grupy.nieWiemy) {
    zle(`NIE WIEMY (${p.etykieta}): ${w.sciezka} — ${w.blad ?? 'bez powodu'}. `
      + 'Brak wyniku to nie jest sukces (pułapka 5).');
  }

  /*
   * 429 TO NIE JEST WYNIK POMIARU — to znak, że pomiar sam sobie przeszkodził.
   * Nie wolno go zaliczyć ani jako „w porządku", ani jako usterki serwisu:
   * o tym odnośniku po prostu NIC NIE WIEMY (pułapka 5).
   */
  for (const w of grupy.limitZapytan) {
    zle(`429 — POMIAR NIEWAŻNY (${p.etykieta}): ${w.sciezka}. To nasz własny skan `
      + 'przekroczył limit zapytań tej trasy, więc o tym odnośniku nic nie wiemy. '
      + `Zmniejsz LIMIT_WARIANTOW_SCIEZKI (dziś ${LIMIT_WARIANTOW_SCIEZKI}) albo zwolnij skan.`);
  }

  for (const w of grupy.czteryZeroTrzy) {
    const powod = powodSlusznego403(new URL(w.adres).pathname);

    if (powod) {
      console.log(`\n  403 SŁUSZNE: ${w.sciezka}\n    ${powod}`);
      continue;
    }

    zle(`403 BEZ UZASADNIENIA (${p.etykieta}): ${w.sciezka} — napis „${w.tekst ?? w.napis}", `
      + `ekran: ${w.ekrany[0]}. Przycisk pokazany komuś, kto nie ma prawa go użyć, `
      + 'jest martwym przyciskiem tak samo jak 404.');
  }

  for (const w of bezTrasy) {
    zle(`FORMULARZ BEZ TRASY (${p.etykieta}): ${w.metoda} ${w.sciezka} — napis „${w.napis}", `
      + `ekran: ${w.ekrany[0]}`);
  }

  for (const k of zlaKotwica) {
    zle(`KOTWICA BEZ CELU (${p.etykieta}): ${k.href} na ekranie ${k.ekran} — napis „${k.tekst}"`);
  }
}

/* ---- Ekrany, na które sam skan nie wszedł ------------------------------- */

for (const p of przebiegi) {
  const padle = [...p.odwiedzone].filter(([, v]) => v.kod === null || v.kod >= 400);

  if (padle.length > 0) {
    console.log(`\n### Ekrany przebiegu „${p.etykieta}", które same nie wstały:`);
    for (const [sciezka, v] of padle) {
      console.log(`    ${String(v.kod ?? 'NIE WIEMY').padEnd(9)} ${sciezka}${v.blad ? `  — ${v.blad}` : ''}`);
      zle(`EKRAN ${v.kod ?? 'NIE WIEMY'} (${p.etykieta}): ${sciezka}${v.blad ? ` — ${v.blad}` : ''}`);
    }
  }
}

/* ---- Straż żądań ------------------------------------------------------- */

if (przerwane.length > 0) {
  console.log(`\n### Straż żądań przerwała ${przerwane.length} żądań (tak ma być — granice 1 i 2):`);

  const zliczone = new Map();

  for (const z of przerwane) {
    const klucz = `${z.powod} — ${z.metoda} ${z.adres}`;

    zliczone.set(klucz, (zliczone.get(klucz) ?? 0) + 1);
  }

  for (const [klucz, ile] of [...zliczone].slice(0, 20)) {
    console.log(`    ${String(ile).padStart(4)} × ${klucz}`);
  }
}

/* ---- Zapis pełnego wyniku ---------------------------------------------- */

mkdirSync('storage', { recursive: true });
writeFileSync('storage/martwe-przyciski.json', `${JSON.stringify({
  data: new Date().toISOString(),
  poczatek: POCZATEK,
  przebiegi: przebiegi.map((p) => ({
    etykieta: p.etykieta,
    ekrany: [...p.odwiedzone].map(([sciezka, v]) => ({ sciezka, ...v })),
    odnosniki: p.wyniki,
    formularze: p.wynikiFormularzy,
    zewnetrzne: [...p.zewnetrzne].map(([adres, v]) => ({ adres, tekst: v.tekst, ekrany: [...v.ekrany] })),
    inneSchematy: [...p.inneSchematy].map(([adres, v]) => ({ adres, tekst: v.tekst, ekrany: [...v.ekrany] })),
    kotwice: p.kotwice,
    pominieteWarianty: p.pominieteWarianty,
    nieczytelneFormularze: p.nieczytelneFormularze,
  })),
  przerwane,
}, null, 2)}\n`);

console.log('\nPełny wynik: storage/martwe-przyciski.json');

// --------------------------------------------------------------------------

console.log(`\n${'='.repeat(78)}`);

if (porazka) {
  console.error(`AUDYT OBLANY — ${powodyPorazki.length} rzeczy do naprawy:\n`);
  for (const powod of powodyPorazki) console.error(`  • ${powod}`);
  console.error('');
  process.exit(1);
}

console.log('AUDYT PRZESZEDŁ: żaden widoczny odnośnik ani formularz nie prowadzi donikąd.');
