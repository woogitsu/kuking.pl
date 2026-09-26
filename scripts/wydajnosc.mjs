/*
 * =============================================================================
 *  Kuking.pl — automat wydajności i SEO (issue #26, druga połowa)
 * =============================================================================
 *
 *  PO CO TEN PLIK JEST OSOBNY OD `dostepnosc.mjs`
 *  Ten sam automat (Lighthouse) potrafi ocenić też dostępność — i właśnie
 *  DLATEGO nie robimy tego tutaj. Lighthouse liczy kategorię „Accessibility"
 *  przez ten sam silnik co `scripts/dostepnosc.mjs` (axe-core), więc drugi
 *  przebieg dokładałby dokładnie te same naruszenia WCAG, tylko wolniej —
 *  Lighthouse renderuje całą stronę pod symulowanym throttlingiem sieci,
 *  jeden przebieg trwa 8-12 s, podczas gdy axe sam w sobie to ułamek sekundy
 *  na ekran. Ten skrypt liczy WYŁĄCZNIE `performance` i `seo`
 *  (`onlyCategories` niżej) — czyli dokładnie to, czego nie mierzy nic
 *  innego w tym repozytorium.
 *
 *  DLACZEGO MNIEJSZY ZESTAW EKRANÓW NIŻ AXE (8, NIE 23)
 *  axe analizuje drzewo dokumentu — tanie, więc stać nas na 23 ekrany
 *  × 4 warianty. Lighthouse faktycznie CZEKA na throttling sieci i licząc
 *  metryki typu Largest Contentful Paint — 23 ekrany kosztowałyby kilka
 *  minut na każdym PR-ze. Wybrane osiem to strony PUBLICZNE: ktoś je otwiera
 *  bez konta (więc pierwsze wrażenie wydajności liczy się najbardziej)
 *  i mają szansę trafić do wyszukiwarki (stąd SEO). Ekrany wymagające
 *  logowania (tablica, dodawanie, ustawienia) celowo pomijamy — Google i tak
 *  ich nie zaindeksuje, a wydajność panelu zalogowanego to inny budżet niż
 *  wydajność strony, na którą trafia się z wyszukiwarki.
 *
 *  DLACZEGO TEN SKRYPT NIE POBIERA WŁASNEJ PRZEGLĄDARKI
 *  `playwright` jest już zależnością tego repozytorium (`dostepnosc.mjs` go
 *  używa), a Lighthouse potrzebuje wyłącznie NUMERU PORTU protokołu CDP, nie
 *  własnej integracji z Playwrightem. Uruchamiamy więc Chromium Playwrighta
 *  z dopisanym `--remote-debugging-port` i podajemy ten port bibliotece
 *  `lighthouse` — bez dodatkowej zależności (`chrome-launcher`) i bez
 *  drugiego pobierania przeglądarki. Ta sama sztuczka, którą Playwright
 *  i Lighthouse już umożliwiają: Chromium nie wie, kto go uruchomił.
 *
 *  SKĄD WZIĄĆ CHROMIUM — TA SAMA HISTORIA CO W `dostepnosc.mjs`
 *  Obraz deweloperski ma gotowe Chromium pod stałą ścieżką i NIE MA tej
 *  wersji, której szuka paczka `playwright` (`chromium.executablePath()`
 *  zwraca wtedy ścieżkę do rewizji, która fizycznie nie istnieje na dysku).
 *  Runner GitHuba/self-hosted jest odwrotny: pobiera świeże Chromium przez
 *  `playwright install` w TYM SAMYM joobie, więc tam `executablePath()`
 *  wskazuje poprawnie. Ta sama funkcja `znajdzChrome()` co w `dostepnosc.mjs`,
 *  z tym samym porządkiem sprawdzeń.
 *
 *  DLACZEGO TEN JOB CI, A NIE OSOBNY (patrz też komentarz w `ci.yml`)
 *  Job `dostepnosc` ma już postawioną bazę PostgreSQL, zainstalowane PHP,
 *  Node i Composer/npm, oraz ŚCIĄGNIĘTE Chromium — powielenie tego w osobnym
 *  jobie kosztowałoby te same 1,5-2 minuty na KAŻDYM PR-ze, tylko po to, żeby
 *  postawić dokładnie ten sam serwer drugi raz. Ten skrypt startuje więc
 *  jako KOLEJNY KROK w tym samym joobie, po `dostepnosc.mjs` — patrz sekcja
 *  „URUCHOMIENIE" niżej o tym, jak dostać adres serwera bez ponownej migracji.
 *
 *  URUCHOMIENIE
 *      node scripts/wydajnosc.mjs                        # sam migruje, sieje i podnosi serwer
 *      POMIN_MIGRACJE=1 node scripts/wydajnosc.mjs        # jak wyżej, ale BEZ migrate:fresh --seed
 *      ADRES=http://127.0.0.1:8123 node scripts/...       # gotowy serwer, nic nie podnosimy
 *
 *  Domyślnie (bez zmiennych) skrypt migruje i sieje `kuking_wydajnosc`
 *  (albo bazę z `DB_DATABASE`) i sam stawia `php artisan serve` — do
 *  jednorazowego pomiaru lokalnego.
 *
 *  W CI baza jest już wysiana przez `dostepnosc.mjs` chwilę wcześniej,
 *  W TYM SAMYM joobie i na TEJ SAMEJ bazie (ten sam `DB_DATABASE` z `env:`
 *  joba) — powtórna migracja kosztowałaby te same kilka sekund na próżno,
 *  a to jest właśnie koszt, którego to umiejscowienie ma unikać (patrz
 *  akapit „DLACZEGO TEN JOB CI" wyżej). CI ustawia więc `POMIN_MIGRACJE=1`
 *  i NIE ustawia `ADRES` — skrypt sam podnosi DRUGI `php artisan serve`
 *  (własny port, żeby nie kolidować z serwerem axe, który już się zamknął)
 *  na gotowych, obcych danych.
 *
 *  `ADRES` zostaje osobną opcją (nieużywaną dziś przez `ci.yml`) na wypadek,
 *  gdyby ktoś chciał zmierzyć serwer, który już działa pod znanym adresem.
 *
 *  Wynik idzie do pliku (storage/wydajnosc.json), tak samo jak
 *  `storage/dostepnosc.json` — żeby dało się zobaczyć, co konkretnie spadło,
 *  bez odtwarzania pomiaru u siebie.
 * =============================================================================
 */
import lighthouse from 'lighthouse';
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:net';
import { writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { PROG_WYDAJNOSC, PROG_SEO, BUDZET_LCP_MS, CEL_LCP_MS, metrykiZAudytow, ocenEkran } from './wydajnosc-progi.mjs';

/*
 * Skąd wziąć Chromium — pełne uzasadnienie w nagłówku pliku. Funkcja
 * CELOWO taka sama jak `znajdzChromium()` w `dostepnosc.mjs`: to jest ta sama
 * decyzja o tym samym pliku binarnym, tylko wołana z drugiego automatu.
 */
function znajdzChrome() {
  const wskazana = process.env.CHROME_PATH;

  if (wskazana) {
    if (! existsSync(wskazana)) {
      console.error(`BŁĄD: CHROME_PATH wskazuje na ${wskazana}, a tam nic nie ma.`);
      process.exit(1);
    }

    return wskazana;
  }

  const deweloperska = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

  if (existsSync(deweloperska)) {
    return deweloperska;
  }

  // Ostatnia deska ratunku: ścieżka, którą podaje sam pakiet `playwright`.
  // Poprawna dokładnie wtedy, gdy manifest paczki odpowiada temu, co
  // fizycznie leży na dysku — czyli na CI (świeże `playwright install`
  // w tym samym joobie), nie w obrazie deweloperskim (stąd sprawdzenie wyżej).
  return chromium.executablePath();
}

const CHROME = znajdzChrome();

/** Port, o którym system POTWIERDZIŁ, że jest wolny — patrz `dostepnosc.mjs`. */
async function wolnyPort() {
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

// BAZA DOMYŚLNA TEGO AUTOMATU przy uruchomieniu lokalnym (bez `ADRES`).
// Inna niż `kuking_a11y` z `dostepnosc.mjs` CELOWO — dwa automaty potrafią
// chodzić jeden po drugim albo równolegle na tej samej maszynie dewelopera,
// a każdy robi `migrate:fresh` na SWOJEJ bazie.
const BAZA_DOMYSLNA = 'kuking_wydajnosc';

async function podnies_serwer() {
  if (process.env.ADRES) {
    return { adres: process.env.ADRES, zamknij: () => {} };
  }

  // `POMIN_MIGRACJE=1` — tak woła ten skrypt `ci.yml`, żeby nie siać drugi
  // raz danych, które `dostepnosc.mjs` już wysiał chwilę wcześniej w tym
  // samym joobie (pełne uzasadnienie w nagłówku pliku, „URUCHOMIENIE").
  if (process.env.POMIN_MIGRACJE !== '1') {
    console.log('Przygotowuję dane demonstracyjne...');
    execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
      stdio: 'ignore',
      env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA },
    });
  }

  const port = await wolnyPort();
  const adres = `http://127.0.0.1:${port}`;

    /* `--no-reload` — patrz `scripts/port-projektu.mjs`: bez niego `artisan serve`
       wycina procesowi `php -S` zmienne środowiska joba i aplikacja spada na
       `.env`, czyli na współdzielony port 5432. */
  const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
    stdio: ['ignore', 'pipe', 'pipe'],
    env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA },
  });

  const dziennik = [];
  proces.stdout.on('data', (b) => dziennik.push(String(b)));
  proces.stderr.on('data', (b) => dziennik.push(String(b)));
  let umarl = null;
  proces.on('exit', (kod) => { umarl = kod; });

  // Czekamy na warunek (serwer odpowiada), nie na sztywny czas — ten sam
  // powód co w `dostepnosc.mjs`: na wolnej maszynie stały `sleep` daje
  // losowo czerwony wynik, który wygląda jak regresja.
  // ROZRÓŻNIAMY DWIE ZUPEŁNIE RÓŻNE AWARIE, bo jedna z nich potrafi
  // zasłonić drugą na cały wieczór.
  //
  // 9 września 2026 ten krok w CI zgłaszał „Brak odpowiedzi z /health przez
  // 30 s", a w tym samym logu stało kilkadziesiąt wpisów pokazujących, że
  // serwer wstał i odpowiadał po 0,06 ms. Odpowiadał BŁĘDEM: `/health`
  // sprawdza wykonane migracje, a baza nie była zmigrowana. Komunikat
  // opisywał więc ciszę, której nie było, i wskazywał na `artisan serve`,
  // który działał bez zarzutu. Szukanie prawdziwej przyczyny zaczęło się od
  // odrzucenia dwóch fałszywych hipotez.
  //
  // Dlatego zapamiętujemy OSTATNIĄ odpowiedź, jaka przyszła, i mówimy
  // wprost, czy serwer milczał, czy odmówił.
  let ostatniStatus = null;
  let ostatniaTresc = '';

  for (let i = 0; i < 60 && umarl === null; i++) {
    try {
      const odp = await fetch(`${adres}/health`);
      if (odp.ok) {
        return { adres, zamknij: () => proces.kill('SIGTERM') };
      }

      ostatniStatus = odp.status;
      ostatniaTresc = (await odp.text().catch(() => '')).slice(0, 500);
    } catch { /* jeszcze nie wstał — to jest ta druga, prawdziwa cisza */ }
    await new Promise((r) => setTimeout(r, 500));
  }

  proces.kill('SIGKILL');

  let powod;

  if (umarl !== null) {
    powod = `Proces zakończył się kodem ${umarl}.\n`;
  } else if (ostatniStatus !== null) {
    powod = `Serwer WSTAŁ i odpowiadał, ale /health zwracał HTTP ${ostatniStatus} przez 30 s.\n`
      + 'To NIE jest awaria `artisan serve` — to aplikacja mówi, że nie jest gotowa.\n'
      + 'Najczęstsza przyczyna: niezmigrowana baza (healthcheck pyta o wykonane migracje).\n'
      + `Treść odpowiedzi /health:\n${ostatniaTresc}\n`;
  } else {
    powod = 'Brak JAKIEJKOLWIEK odpowiedzi z /health przez 30 s — serwer nie zaczął słuchać.\n';
  }

  throw new Error(
    `Nie udało się podnieść „php artisan serve" na porcie ${port}.\n`
    + powod
    + dziennik.join(''),
  );
}

/*
 * EKRANY DO POMIARU — świadomie mniejszy zestaw niż `dostepnosc.mjs`
 * (uzasadnienie w nagłówku pliku). Wszystkie osiem to strony GOŚCIA —
 * żaden wymaga logowania, bo Lighthouse w tym skrypcie mierzy dokładnie to,
 * co dostaje osoba bez konta, wchodząca z wyszukiwarki albo z linku.
 *
 * `seo: false` przy „logowanie" NIE JEST pominięciem błędu — to jest
 * odróżnienie CELOWEGO `noindex` (formularz logowania nie ma prawa trafić
 * do wyszukiwarki) od naruszenia. Bez tego rozróżnienia bramka SEO paliłaby
 * się na czerwono za coś, co jest poprawnym działaniem serwisu — dokładnie
 * ta klasa fałszywej czerwieni, przed którą ostrzega nagłówek
 * `dostepnosc.mjs`. Zmierzone 9 września: `/login` dostaje SEO 58/100
 * (`is-crawlable`, `meta-description`) właśnie z tego powodu.
 */
const EKRANY = [
  { nazwa: 'strona powitalna', adres: '/', seo: true },
  { nazwa: 'Świeżo z Kuking', adres: '/odkryj', seo: true },
  { nazwa: 'logowanie', adres: '/login', seo: false },
  { nazwa: 'rejestracja', adres: '/register', seo: true },
  { nazwa: 'przepis', adres: null, znajdz: 'przepis', seo: true },
  { nazwa: 'profil', adres: '/@basia', seo: true },
  { nazwa: 'strona tagu (gość)', adres: '/tag/zupy', seo: true },
  { nazwa: 'regulamin', adres: '/regulamin', seo: true },
];

/*
 * PROGI — Z POMIARU, NIE Z GŁOWY. Zmierzone 9 września 2026, lokalnie, dwa
 * przebiegi: DemoSeeder, Chromium Playwrighta, throttling symulowany,
 * mobile 360×640 (konfiguracja domyślna Lighthouse'a — patrz `KONFIGURACJA`
 * niżej).
 *
 *   ekran                 wydajność    SEO
 *   strona powitalna       95–99       100
 *   Świeżo z Kuking        96–100      100
 *   logowanie              97–98        58  (celowy `noindex` — nie liczymy do bramki)
 *   rejestracja            97–100      100
 *   przepis                    96      100
 *   profil                 96–99       100
 *   strona tagu                97–98   100  (NAPRAWIONE issue #191, patrz niżej — zmierzone ponownie)
 *   regulamin                  96       92  (NAPRAWIONE issue #191, patrz niżej — nie odtworzone w tym środowisku)
 *
 * ZNALEZISKO Z TEGO POMIARU, NAPRAWIONE W ISSUE #191
 * `/tag/{slug}` i strony prawne (`/regulamin`, `/prywatnosc`, `/zasady`) nie
 * przekazywały `description` do `<x-layout>`, więc nie miały meta-opisu —
 * `resources/views/components/layout.blade.php` renderuje ten znacznik
 * TYLKO, gdy `$description` jest ustawione. To był prawdziwy, zmierzony
 * brak SEO 92/100 na obu ekranach, naprawiony w #191: `regulamin`,
 * `prywatnosc`, `zasady` i `/tag/{slug}` dostały każdy własny, sensowny opis
 * (`tags/show.blade.php`, `StaticPageController`) — dowód, że opis naprawdę
 * tam jest, to `KazdaPublicznaStronaMaMetaOpisTest` (regresja po WSZYSTKICH
 * trasach publicznych, nie tylko tych dwóch).
 *
 * PONOWNY POMIAR TEGO SKRYPTU PO NAPRAWIE (9 września 2026, ten sam dzień):
 * `/tag/zupy` faktycznie skoczyło z 92 na SEO 100 — potwierdzone żywym
 * przebiegiem Lighthouse'a w tym repozytorium. `/regulamin` NIE dało się tu
 * ponownie zmierzyć: to repozytorium żyje w git worktree z DOWIĄZANYM
 * (symlink) `vendor/`, a Composer wygenerował swój classmap dla GŁÓWNEGO
 * katalogu — `php artisan serve`/`tinker` ładują stamtąd KLASY (kontrolery),
 * podczas gdy WIDOKI (Blade) i tak czytają się z bieżącego katalogu roboczego.
 * Naprawa `/tag` siedzi wyłącznie w widoku, więc `serve` przypadkiem pokazał
 * ją poprawnie; naprawa stron prawnych zmienia też `StaticPageController`
 * (nowy parametr `$description`), więc pod `serve` w TYM WORKTREE kontroler
 * ze starym podpisem i widok z nowym się rozjeżdżają i strona 500-uje —
 * mieszanina starej klasy i nowego widoku, nie usterka samej poprawki.
 * `php artisan test` (jedyne narzędzie, które AGENTS.md każe tu używać do
 * weryfikacji, z `APP_BASE_PATH=$(pwd)`) NIE ma tego problemu — dowiedzione
 * osobnym testem odbicia klasy przy pracy nad tym zgłoszeniem — i properny
 * przebieg testów regresyjnych POTWIERDZA naprawę obu stron. Ten skrypt
 * (Lighthouse przez `php artisan serve`) nie jest bezpieczny do własnej
 * weryfikacji w worktree z dowiązanym `vendor/`; w CI (checkout zwykły, bez
 * dowiązań) tego problemu nie ma w ogóle.
 *
 * PRÓG PODNIESIONY Z 85 DO 98 razem z tą naprawą — dokładnie do wartości
 * z kryterium akceptacji #191 („Lighthouse SEO na tych stronach ≥ 98").
 * Margines dwóch punktów zostaje: audyty SEO w tym zestawie są prawie
 * całkowicie deterministyczne (patrz akapit niżej), więc 98 łapie realną
 * regresję (np. ktoś znowu wytnie `description` przy refaktorze widoku),
 * nie szum pomiaru. Jeśli ten próg padnie na `/regulamin` mimo poprawki
 * opisów, sprawdź NAJPIERW `curl` żywej odpowiedzi pod kątem
 * `<meta name="description">`, zanim uznasz próg za zbyt wysoki.
 *
 * MARGINES WYDAJNOŚCI JEST SZEROKI, I TO CELOWO
 * `ci.yml` opisuje trzy runnery na JEDNEJ maszynie, dzielące CPU. Wydajność
 * (w przeciwieństwie do SEO, które jest w większości deterministyczne —
 * `document-title`, `meta-description`, `http-status-code` nie zależą od
 * obciążenia procesora) jest wrażliwa na to, co jeszcze w danej chwili robi
 * współdzielona maszyna: `Total Blocking Time` rośnie pod obcym obciążeniem,
 * nawet gdy throttling sieci jest symulowany, a nie prawdziwy. Próg 70 przy
 * zmierzonych lokalnie 95–100 to margines na szum sprzętu, nie na regresję
 * kodu — i dalej łapie prawdziwą katastrofę (zapomniany `npm run build`,
 * nieskompresowane zdjęcie w tle, zablokowany render synchronicznym
 * skryptem). Warto go zacieśnić, gdy zbierze się kilka prawdziwych
 * przebiegów CI do porównania — dziś tych danych nie ma.
 *
 * Stałe progów (i od #1029 osobny budżet LCP liczony z `numericValue`)
 * mieszkają w `scripts/wydajnosc-progi.mjs` — tam też seria pomiarów,
 * z której wyszedł budżet, i test bramki bez przeglądarki.
 */

/*
 * KONFIGURACJA LIGHTHOUSE'A — mobile, throttling symulowany.
 *
 * To są DOMYŚLNE ustawienia Lighthouse'a (mobilny profil, wolne 4G
 * symulowane), wypisane tu WPROST zamiast zostawione niejawnie — ten sam
 * powód, dla którego `dostepnosc.mjs` tłumaczy każdy swój wybór zamiast
 * polegać na domyślnym zachowaniu biblioteki, którego czytelnik pliku by
 * się nie domyślił. Mobile, nie desktop: axe też traktuje 320–414 px jako
 * pierwszy przypadek (WCAG 2.2 AA, kryterium 1.4.10), a to jest ten sam
 * profil ruchu — telefon, nie desktop.
 */
const KONFIGURACJA = {
  onlyCategories: ['performance', 'seo'],
  formFactor: 'mobile',
  screenEmulation: { mobile: true, width: 360, height: 640, deviceScaleFactor: 2, disabled: false },
  throttlingMethod: 'simulate',
};

function log(...args) {
  console.log(...args);
}

const { adres, zamknij } = await podnies_serwer();

/*
 * Adres przepisu z BAZY, nie ze strony — ten sam powód i ten sam mechanizm
 * co `adresPrzepisu` w `dostepnosc.mjs`: pytanie do bazy nie zależy od tego,
 * która strona akurat linkuje do przepisów, więc ekran nie wypada po cichu
 * ze sprawdzania, gdy coś się przełoży w nawigacji.
 */
const adresPrzepisu = (() => {
  const slug = execFileSync('php', ['artisan', 'tinker', '--execute',
    "echo optional(App\\Models\\Recipe::where('status','published')->where('visibility','public')->first())->slug;",
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA } })
    .toString().trim();

  return slug === '' ? null : `/przepisy/${slug}`;
})();

if (adresPrzepisu === null) {
  console.error('BŁĄD: w bazie nie ma opublikowanego przepisu — ekran przepisu nie zostałby zmierzony.');
  console.error('       Uruchom seeder albo wskaż inną bazę przez DB_DATABASE.');
  zamknij();
  process.exit(1);
}

function sciezkaEkranu(ekran) {
  return ekran.znajdz === 'przepis' ? adresPrzepisu : ekran.adres;
}

// Jeden Chromium na wszystkie ekrany — Lighthouse czyści pamięć podręczną
// i magazyn danych źródła PRZED każdym przebiegiem (domyślne zachowanie,
// `disableStorageReset` zostaje `false`), więc kolejne pomiary na tej samej
// przeglądarce zostają „zimne", tak jak dla prawdziwego pierwszego wejścia.
const portCdp = await wolnyPort();
const przegladarka = await chromium.launch({
  executablePath: CHROME,
  args: [`--remote-debugging-port=${portCdp}`],
});

const wyniki = [];
let niezaliczonych = 0;

/*
 * PĘTLA POMIARU W `try/finally`, ŻEBY SERWER ZAWSZE ZGASŁ.
 *
 * CI stoi na trzech runnerach dzielących JEDNĄ maszynę (nagłówek `ci.yml`)
 * — proces `php artisan serve`, którego nikt nie ubił, zostaje i trzyma
 * port aż do restartu maszyny, dokładnie ta klasa problemu, którą
 * `dostepnosc.mjs` już raz złapał (patrz jego komentarz przy `wolnyPort()`).
 * Wyjątek gdziekolwiek w pętli (błąd Lighthouse'a, zerwane połączenie
 * z Chromium) nie może więc ominąć zamknięcia przeglądarki i serwera.
 */
try {
  for (const ekran of EKRANY) {
    const sciezka = sciezkaEkranu(ekran);

    if (! sciezka) {
      console.error(`BŁĄD: brak adresu dla ekranu „${ekran.nazwa}".`);
      niezaliczonych++;
      continue;
    }

    const zamowiony = `${adres}${sciezka}`;

    /*
     * JEDNO PONOWIENIE PRZY BŁĘDZIE POMIARU, NIE PRZY NISKIM WYNIKU.
     *
     * Zmierzone przy pisaniu tego skryptu: Lighthouse raz na kilkanaście
     * przebiegów zwraca `categories.performance.score === null` (audyt
     * wydajności nie policzył się w ogóle — zwykle chwilowy problem ze
     * zbieraniem śladu wydajności, nie z mierzoną stroną) bez żadnego
     * `runtimeError`. TA SAMA strona, zmierzona zaraz potem, dawała 0.97
     * cztery razy z rzędu. `null` znaczy „nie zmierzono", nie „zero" —
     * zamiana jednego na drugie (`score ?? 0`) fałszywie oskarżałaby stronę
     * o katastrofalną wydajność, której nie ma. Jedno ponowienie odróżnia
     * ten szum od prawdziwego, powtarzalnego problemu.
     */
    let lhr = (await lighthouse(zamowiony, { port: portCdp, output: 'json', ...KONFIGURACJA })).lhr;

    if (lhr.categories.performance?.score === null || lhr.categories.seo?.score === null) {
      console.error(`  (pomiar „${ekran.nazwa}" nie policzył jednej z kategorii za pierwszym razem — ponawiam)`);
      lhr = (await lighthouse(zamowiony, { port: portCdp, output: 'json', ...KONFIGURACJA })).lhr;
    }

    if (lhr.categories.performance?.score === null || lhr.categories.seo?.score === null) {
      console.error(
        `BŁĄD: pomiar ekranu „${ekran.nazwa}" (${sciezka}) nie policzył wyniku nawet po ponowieniu `
        + `(runtimeError: ${lhr.runtimeError?.message ?? 'brak'}). To jest awaria pomiaru, nie niska ocena.`,
      );
      niezaliczonych++;
      continue;
    }

    /*
     * SPRAWDZAMY, CZY DOSTALIŚMY TO, O CO PROSILIŚMY — ten sam powód co
     * w `dostepnosc.mjs`: strona błędu albo przekierowanie na inny adres
     * (np. `guest` middleware odsyłające zalogowanego) dają WYNIK, tylko
     * dla innej strony niż ta, którą zamówiliśmy w raporcie.
     */
    const otrzymanaSciezka = new URL(lhr.mainDocumentUrl ?? lhr.finalDisplayedUrl).pathname;

    if (otrzymanaSciezka !== sciezka) {
      console.error(
        `BŁĄD: ekran „${ekran.nazwa}" (${sciezka}) odesłał na ${otrzymanaSciezka}. `
        + 'Raport mierzyłby inną stronę niż zamówioną.',
      );
      niezaliczonych++;
      continue;
    }

    // Bez `?? 0` — powyższy warunek już wykluczył `null`, a fałszywe zero
    // jest właśnie tym, przed czym ostrzega komentarz nad nim.
    const wydajnosc = Math.round(lhr.categories.performance.score * 100);
    const seo = Math.round(lhr.categories.seo.score * 100);

    // Surowe `numericValue` do decyzji, `displayValue` dla człowieka (#1029).
    const metryki = metrykiZAudytow(lhr.audits);

    // Audyty SEO nieprzeszłe (score < 1) — sedno tego, co „się zepsuło",
    // bez odtwarzania pomiaru u siebie. `null` score (np. audyt niedotyczący
    // tej strony) celowo pomijamy.
    const seoUsterki = (lhr.categories.seo?.auditRefs ?? [])
      .map((ref) => lhr.audits[ref.id])
      .filter((a) => a && a.score !== null && a.score < 1)
      .map((a) => a.id);

    const ocena = ocenEkran({ nazwa: ekran.nazwa, wydajnosc, seo, seoLiczone: ekran.seo, metryki });
    const { ok } = ocena;

    if (! ok) niezaliczonych++;

    wyniki.push({
      ekran: ekran.nazwa,
      sciezka,
      wydajnosc,
      seo,
      seoPominieteWBramce: ! ekran.seo,
      seoUsterki,
      powody: ocena.powody,
      lcpCel: ocena.lcpCel,
      ...metryki,
    });

    const lcpMs = metryki.lcp.numericValue;
    log(
      `  ${ok ? '✓' : '✗'} ${ekran.nazwa} (${sciezka}) — `
      + `wydajność ${wydajnosc}, SEO ${seo}${ekran.seo ? '' : ' (nieliczone — celowy noindex)'}, `
      + `LCP ${lcpMs === null ? '—' : `${Math.round(lcpMs)} ms`} `
      + `(budżet CI ${BUDZET_LCP_MS} ms; cel ${CEL_LCP_MS} ms ${ocena.lcpCel ? 'spełniony' : 'jeszcze nie'})`,
    );
    for (const powod of ocena.powody) console.error(`    ✗ ${powod}`);
  }
} finally {
  // `finally`, nie koniec skryptu: patrz komentarz „PĘTLA POMIARU..." wyżej.
  await przegladarka.close();
  zamknij();
}

mkdirSync('storage', { recursive: true });
writeFileSync('storage/wydajnosc.json', JSON.stringify({
  data: new Date().toISOString(),
  progi: { wydajnosc: PROG_WYDAJNOSC, seo: PROG_SEO, lcpMs: BUDZET_LCP_MS, celLcpMs: CEL_LCP_MS },
  konfiguracja: KONFIGURACJA,
  niezaliczonych,
  wyniki,
}, null, 2));

log('');
log(`Wynik zapisany: storage/wydajnosc.json (niezaliczonych ekranów: ${niezaliczonych} z ${EKRANY.length})`);

process.exit(niezaliczonych > 0 ? 1 : 0);
