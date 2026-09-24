/*
 * =============================================================================
 *  Kuking.pl — pomiar: CO DZIAŁA PRZY WYŁĄCZONYM JAVASCRIPCIE
 * =============================================================================
 *
 *  PO CO TO JEST
 *  `AGENTS.md` §5 (po zmianie D-053) mówi dwie rzeczy naraz i obie są twarde:
 *
 *    1. formularze chronione captchą WOLNO uzależnić od JavaScriptu,
 *    2. nigdzie nie wolno zostawić MARTWEGO PRZYCISKU — czegoś, co bez
 *       skryptu wygląda na sprawne, a po kliknięciu milczy.
 *
 *  Pojedyncze testy pilnują tego po kawałku. NIKT NIGDY NIE PRZESZEDŁ CAŁEGO
 *  PRODUKTU BEZ SKRYPTU I NIE POLICZYŁ, ile ścieżek dochodzi do końca. Ten
 *  skrypt to robi: przechodzi kluczowe ścieżki w przeglądarce z wyłączonym
 *  wykonywaniem skryptów i dla każdej melduje TAK/NIE razem z liczbami —
 *  kodem ekranu, metodą i kodem wysłania, adresem, na który przekierowało,
 *  i tym, CZY SKUTEK WIDAĆ NA NASTĘPNYM EKRANIE.
 *
 *  DLACZEGO „SKUTEK WIDAĆ", A NIE „FORMULARZ JEST"
 *  Sprawdzenie „czy na ekranie stoi `<form method=POST>`" przechodzi także
 *  wtedy, gdy wysłanie kończy się błędem walidacji, przekierowaniem na
 *  logowanie albo cichym 302 w to samo miejsce. Dlatego każda ścieżka ma
 *  KONTROLĘ SKUTKU: nowy wpis musi wyjść w strumieniu, komentarz pod wpisem,
 *  obserwowanie musi zmienić przycisk na „Przestań obserwować" i tak dalej.
 *
 *  DWIE BRAMKI, BEZ KTÓRYCH TEN POMIAR NIC NIE ZNACZY
 *  (`docs/PULAPKI_TESTOW.md` §5 — narzędzie potrafi zameldować sukces, nie
 *  robiąc nic):
 *
 *    A. CZY SKRYPT STRONY NAPRAWDĘ JEST WYŁĄCZONY. `javaScriptEnabled: false`
 *       w Playwrighcie NIE blokuje `page.evaluate()` (to idzie przez CDP,
 *       poza silnikiem skryptów strony), więc sama flaga niczego nie dowodzi.
 *       Mierzymy to wprost: strona `data:` z własnym `<script>`, który
 *       ustawia atrybut w DOM. Przy wyłączonym skrypcie atrybutu NIE MA.
 *    B. KONTROLA DODATNIA TEJ BRAMKI. Ta sama strona `data:` w kontekście
 *       z WŁĄCZONYM skryptem MUSI ten atrybut ustawić. Bez tego „nie ma
 *       atrybutu" znaczyłoby tylko tyle, że sonda jest zepsuta.
 *
 *  Obie bramki są sprawdzane przy starcie i obie odmawiają uruchomienia
 *  pomiaru, gdy nie wyjdą.
 *
 *  DWIE FAZY, BO SERWIS MA DWA RÓŻNE KSZTAŁTY
 *  FAZA 1 — 16 ścieżek produktu w konfiguracji, w jakiej chodzi kontener
 *  agenta i CI (bez kluczy Turnstile). FAZA 2 — siedem formularzy chronionych
 *  captchą w kształcie PRODUKCYJNYM (klucze podstawione, poczta dostarczająca):
 *  tam brak tokenu ODRZUCA wysłanie (D-050) i pytanie brzmi już nie „czy
 *  przejdzie", tylko „czy człowiek dostaje zdanie mówiące, co zrobić".
 *  Bez fazy 2 raport twierdziłby „rejestracja bez JavaScriptu: TAK" o stanie,
 *  którego na produkcji nie ma.
 *
 *  WŁASNA BAZA, WŁASNY SERWER
 *  Skrypt robi `migrate:fresh --seed`, więc chodzi na własnej bazie
 *  (`kuking_bez_javascriptu`). Wskazanie `kuking` albo `kuking_test`
 *  kasowałoby czyjąś pracę (AGENTS.md §6) — dlatego nazwa jest tu na sztywno,
 *  a jawne `DB_DATABASE` wskazujące na jedną z tych dwóch baz przerywa start.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/bez-javascriptu.mjs
 *      ADRES=http://127.0.0.1:8137 node scripts/bez-javascriptu.mjs
 * =============================================================================
 */
import { chromium } from 'playwright';
import { ustalBazePomiarowa } from './bezpiecznik-bazy.mjs';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa — patrz nagłówek. */
const BAZA_DOMYSLNA = 'kuking_bez_javascriptu';

/* BEZPIECZNIK: ten skrypt robi `migrate:fresh`, czyli KASUJE zawartosc
   bazy. `ustalBazePomiarowa()` wpuszcza wylacznie jednorazowa baze pomiarowa
   i ODMAWIA startu przy nazwie, ktorej nie rozpoznaje — nie wiem, czyja to
   baza, wiec jej nie kasuje (scripts/bezpiecznik-bazy.mjs). Liczone RAZ, na
   starcie: odmowa ma paść, zanim skrypt cokolwiek zbuduje albo podniesie. */
const BAZA_POMIAROWA = ustalBazePomiarowa({
  domyslna: BAZA_DOMYSLNA,
  skrypt: 'scripts/bez-javascriptu.mjs',
});

/* Strona sondy dla bramek A i B. Skrypt ustawia atrybut w DOM — atrybut
   przeżywa wykonanie i da się go odczytać bez pytania o `window`, więc
   sonda mierzy silnik skryptów strony, a nie nasz kanał pomiarowy. */
const SONDA = 'data:text/html,<body><script>document.body.setAttribute("data-skrypt","tak")%3C/script>';

function znajdzChromium() {
  if (process.env.CHROMIUM_PATH) return process.env.CHROMIUM_PATH;
  try {
    const wlasna = chromium.executablePath();
    if (wlasna && existsSync(wlasna)) return undefined;
  } catch { /* idziemy dalej */ }
  const deweloperska = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

  return existsSync(deweloperska) ? deweloperska : undefined;
}

function env(dodatkowe = {}) {
  return { ...process.env, DB_DATABASE: BAZA_POMIAROWA, ...dodatkowe };
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

async function podniesSerwer(dodatkoweEnv = {}, przygotuj = true) {
  if (process.env.ADRES && przygotuj) return { adres: process.env.ADRES, zamknij: () => {} };

  // Rozpoznanie bazy siedzi w `ustalBazePomiarowa()` (BAZA_POMIAROWA wyżej)
  // i odmówiło startu zanim tu doszliśmy. Stała tu lista ZAKAZÓW
  // `['kuking', 'kuking_test']`, więc wszystko spoza niej było dozwolone —
  // a po #736 żadna kopia robocza nie nazywa już swojej bazy `kuking_test`.

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  if (! przygotuj) return uruchomSerwer(dodatkoweEnv);

  console.log('Buduję arkusz i skrypt (vite build)...');
  execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore', env: process.env });

  try {
    execFileSync('createdb', [env().DB_DATABASE], { stdio: 'ignore', env: {
      ...process.env,
      PGHOST: process.env.DB_HOST || '127.0.0.1',
      PGUSER: process.env.DB_USERNAME || 'kuking',
      PGPASSWORD: process.env.DB_PASSWORD || 'kuking',
    } });
  } catch { /* baza już istnieje */ }

  console.log(`Przygotowuję dane demonstracyjne w bazie ${env().DB_DATABASE}...`);
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  return uruchomSerwer(dodatkoweEnv);
}

/** Podnosi `php artisan serve` na wolnym porcie i czeka, aż odpowie `/health`. */
async function uruchomSerwer(dodatkoweEnv = {}) {
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
      env: env(dodatkoweEnv),
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

/** Odpytuje bazę pomiarową — używane tam, gdzie skutek trzeba potwierdzić poza ekranem. */
function tinker(php) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: env() }).toString().trim();
}

/**
 * Identyfikatory z danych demo. Nie wpisujemy ich na sztywno: `DemoSeeder`
 * losuje UUID-y, a wpisany na sztywno slug przestałby istnieć przy pierwszej
 * zmianie danych zalążkowych i pomiar meldowałby wtedy 404 jako usterkę
 * produktu (pułapka 8b — czerwień z niewłaściwej warstwy).
 */
function daneStartowe() {
  const surowe = tinker(`
    $ania = App\\Models\\User::whereHas('profile', fn($q) => $q->where('username', 'ania'))->firstOrFail();
    $obserwowani = $ania->following()->pluck('users.id')->all();
    $inny = App\\Models\\User::whereNotIn('id', array_merge($obserwowani, [$ania->getKey()]))
        ->whereHas('profile')->get()->first(fn($u) => $u->profile?->username);
    $obserwowaneTagi = $ania->followedTags()->pluck('tags.slug')->all();
    $tag = App\\Models\\Tag::whereNotIn('slug', $obserwowaneTagi)->value('slug');
    $post = App\\Models\\Post::whereNotNull('published_at')
        ->where('author_id', '!=', $ania->getKey())->latest('published_at')->firstOrFail();
    $przepis = App\\Models\\Recipe::where('status', 'published')->firstOrFail();
    echo json_encode([
        'inny' => $inny?->profile?->username,
        'tag' => $tag,
        'post' => $post->getKey(),
        'przepis' => $przepis->slug,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  `);
  const json = surowe.slice(surowe.indexOf('{'), surowe.lastIndexOf('}') + 1);
  const dane = JSON.parse(json);

  for (const [klucz, wartosc] of Object.entries(dane)) {
    if (! wartosc) throw new Error(`Dane demo nie mają czego trzeba: brak „${klucz}".`);
  }

  return dane;
}

/** Zdjęcie do wysłania. Generujemy je, żeby pomiar nie zależał od pliku w repozytorium. */
function przygotujZdjecie() {
  const katalog = mkdtempSync(join(tmpdir(), 'bez-js-'));
  const plik = join(katalog, 'obiad.jpg');

  execFileSync('php', ['-r', `
    $o = imagecreatetruecolor(1200, 900);
    imagefill($o, 0, 0, imagecolorallocate($o, 200, 140, 60));
    imagejpeg($o, ${JSON.stringify(plik)}, 85);
  `.replace(/\n\s*/g, ' ')]);

  if (! existsSync(plik)) throw new Error('Nie udało się wygenerować zdjęcia do wysłania.');

  return plik;
}

/* ---------------------------------------------------------------------------
   NARZĘDZIA POMIARU
   --------------------------------------------------------------------------- */

/** Wszystkie odpowiedzi HTTP jednej ścieżki — stąd biorą się liczby w tabeli. */
function nasluch(strona) {
  const zapis = [];

  strona.on('response', (odp) => zapis.push({
    metoda: odp.request().method(),
    url: odp.url(),
    kod: odp.status(),
    dokad: odp.headers().location ?? null,
  }));

  return zapis;
}

/** Kod odpowiedzi ekranu, na który właśnie weszliśmy. */
async function wejdz(strona, adres) {
  const odp = await strona.goto(adres, { waitUntil: 'load' });

  return odp?.status() ?? 0;
}

/**
 * Kliknięcie w przycisk wysyłki i odczyt TEGO, co odpowiedział serwer.
 *
 * Czekamy na odpowiedź metodą inną niż GET, bo to ona niesie wynik wysłania.
 * `PUT`/`DELETE` z `@method` idą po HTTP jako POST i tak też są tu liczone —
 * w tabeli stoi metoda HTTP, nie nazwa z Blade.
 */
async function wyslij(strona, selektor) {
  /* `locator(...)` w trybie ścisłym, a NIE `strona.click(selektor)`.
     Ta druga metoda bierze PIERWSZY pasujący element i milczy o pozostałych —
     a formularz publikacji wpisu ma w sobie cztery przyciski `submit`
     („Szukaj tagów", „Dodaj", „Usuń", „Opublikuj"). Pierwszy pomiar tego
     skryptu meldował z tego powodu, że główna akcja produktu NIE DZIAŁA
     bez skryptu; w rzeczywistości klikał „Szukaj tagów". Tryb ścisły zamienia
     taką pomyłkę w głośny błąd zamiast w fałszywy wynik. */
  const [odp] = await Promise.all([
    strona.waitForResponse((r) => r.request().method() !== 'GET', { timeout: 30000 }),
    strona.locator(selektor).click(),
  ]);

  await strona.waitForLoadState('load');

  return { metoda: odp.request().method(), kod: odp.status(), dokad: odp.headers().location ?? null };
}

/** Sama treść `<main>` — bez belki, szyny i stopki (docs/PULAPKI_TESTOW.md §1 i §1b). */
async function trescEkranu(strona) {
  return (await strona.textContent('main').catch(() => null)) ?? '';
}

/** Czy element jest naprawdę widoczny (ma pole na ekranie), a nie tylko obecny w HTML-u. */
async function widocznyNaEkranie(strona, selektor) {
  const pole = await strona.locator(selektor).first().boundingBox().catch(() => null);

  return pole !== null && pole.width > 0 && pole.height > 0;
}

const wyniki = [];

async function sciezka(nazwa, uwaga, fn) {
  try {
    const wynik = await fn();

    wyniki.push({ nazwa, uwaga, ...wynik });
  } catch (blad) {
    wyniki.push({
      nazwa,
      uwaga,
      ekran: '—',
      wyslanie: '—',
      dokad: '—',
      skutek: false,
      opis: `przerwane: ${String(blad.message ?? blad).split('\n')[0].slice(0, 90)}`,
    });
  }
}

/* ---------------------------------------------------------------------------
   START
   --------------------------------------------------------------------------- */

const CHROMIUM = znajdzChromium();
const { adres, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);

const dane = daneStartowe();
const zdjecie = przygotujZdjecie();

console.log(`Dane demo: inna osoba „${dane.inny}", tag „${dane.tag}", przepis „${dane.przepis}".`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/* --- BRAMKA A i B: czy skrypt strony NAPRAWDĘ jest wyłączony --------------- */
async function sondaSkryptu(javaScriptEnabled) {
  const kontekst = await przegladarka.newContext({ javaScriptEnabled });
  const strona = await kontekst.newPage();

  await strona.goto(SONDA);
  const atrybut = await strona.getAttribute('body', 'data-skrypt');

  await kontekst.close();

  return atrybut;
}

const bezSkryptu = await sondaSkryptu(false);
const zeSkryptem = await sondaSkryptu(true);

console.log(`Bramka A (skrypt wyłączony → sonda ma zostać pusta): ${bezSkryptu === null ? 'pusta' : `„${bezSkryptu}"`}`);
console.log(`Bramka B (skrypt włączony → sonda ma zadziałać):     ${zeSkryptem === null ? 'pusta' : `„${zeSkryptem}"`}`);

if (bezSkryptu !== null || zeSkryptem !== 'tak') {
  await przegladarka.close();
  zamknij();
  throw new Error('Bramki sondy nie wyszły — pomiar bez JavaScriptu nie mierzyłby tego, co obiecuje.');
}

const WIDOK = { width: 390, height: 844 };

async function nowyKontekst(sesja = undefined) {
  return przegladarka.newContext({ javaScriptEnabled: false, viewport: WIDOK, storageState: sesja });
}

let sesjaAni = null;

try {
  /* --- 1. REJESTRACJA ---------------------------------------------------- */
  await sciezka('rejestracja', 'Turnstile wyłączony w tej konfiguracji — patrz raport', async () => {
    const kontekst = await nowyKontekst();
    const strona = await kontekst.newPage();
    const znacznik = `bezjs${Date.now().toString().slice(-8)}`;

    const ekran = await wejdz(strona, `${adres}/register`);

    await strona.fill('input[name="display_name"]', 'Bez Skryptu');
    await strona.fill('input[name="username"]', znacznik);
    await strona.fill('input[name="email"]', `${znacznik}@example.test`);
    await strona.fill('input[name="password"]', 'haslo-testowe-123');
    await strona.check('input[name="age_confirmed"]');
    await strona.check('input[name="terms_accepted"]');

    const wyslanie = await wyslij(strona, 'form[action$="/register"] button[type="submit"]');
    const konto = tinker(`echo App\\Models\\Profile::where('username', '${znacznik}')->exists() ? 'JEST' : 'NIE MA';`);

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: konto.includes('JEST'),
      opis: `konto „${znacznik}" w bazie: ${konto.includes('JEST') ? 'jest' : 'NIE MA'}`,
    };
  });

  /* --- 2. LOGOWANIE ------------------------------------------------------ */
  await sciezka('logowanie', 'Turnstile wyłączony w tej konfiguracji — patrz raport', async () => {
    const kontekst = await nowyKontekst();
    const strona = await kontekst.newPage();

    const ekran = await wejdz(strona, `${adres}/login`);

    await strona.fill('input[name="login"]', KONTO);
    await strona.fill('input[name="password"]', HASLO);

    const wyslanie = await wyslij(strona, 'form[action$="/login"] button[type="submit"]');
    const kart = await strona.locator('article.post-card').count();

    sesjaAni = await kontekst.storageState();

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: strona.url().endsWith('/home') && kart > 0,
      opis: `kart wpisów w strumieniu: ${kart}`,
    };
  });

  if (sesjaAni === null) throw new Error('Bez zalogowania reszta ścieżek nie ma czego mierzyć.');

  /* --- 3. GŁÓWNA AKCJA: WPIS ZE ZDJĘCIEM --------------------------------- */
  let trescWpisu = null;

  await sciezka('wpis ze zdjęciem', 'główna akcja produktu', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();

    trescWpisu = `Bez skryptu ugotowane ${Date.now().toString().slice(-8)}`;

    const ekran = await wejdz(strona, `${adres}/dodaj/zdjecie`);

    await strona.setInputFiles('input[type="file"][name="photos[]"]', zdjecie);
    await strona.fill('textarea[name="body"]', trescWpisu);

    const wyslanie = await wyslij(strona, 'form[action$="/dodaj/zdjecie"] button[type="submit"]:text-is("Opublikuj")');
    const naStronieWpisu = (await trescEkranu(strona)).includes(trescWpisu);

    /* Skutek sprawdzamy DRUGI RAZ, w strumieniu — bo to on jest produktem. */
    await wejdz(strona, `${adres}/home`);
    const wStrumieniu = (await trescEkranu(strona)).includes(trescWpisu);

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: wyslanie.dokad?.replace(adres, '') ?? '—',
      skutek: naStronieWpisu && wStrumieniu,
      opis: `po wysłaniu widać treść: ${naStronieWpisu ? 'tak' : 'NIE'}; w strumieniu /home: ${wStrumieniu ? 'tak' : 'NIE'}`,
    };
  });

  /* --- 4. PRZEPIS -------------------------------------------------------- */
  await sciezka('dodanie przepisu', 'kreator Livewire ma osobną drogę bez skryptu', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();
    const tytul = `Zupa bez skryptu ${Date.now().toString().slice(-8)}`;

    const ekran = await wejdz(strona, `${adres}/dodaj/przepis`);

    await strona.fill('input[name="title"]', tytul);
    await strona.fill('textarea[name="skladniki_tekst"]', '2 marchewki\n1 pietruszka\nsól');
    await strona.fill('textarea[name="przygotowanie_tekst"]', 'Obrać warzywa.\nGotować 30 minut.');

    const wyslanie = await wyslij(strona, '#formularz-przepisu button[type="submit"][name="action"][value="publish"]');
    const widac = (await trescEkranu(strona)).includes(tytul);

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: widac,
      opis: `przepis „${tytul}" widoczny po wysłaniu: ${widac ? 'tak' : 'NIE'}`,
    };
  });

  /* --- 5. KOMENTARZ ------------------------------------------------------ */
  await sciezka('komentarz pod wpisem', '', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();
    const tresc = `Wygląda świetnie, bez skryptu ${Date.now().toString().slice(-8)}`;

    const ekran = await wejdz(strona, `${adres}/wpisy/${dane.post}`);

    const formularz = 'form[action$="/komentarz"]:has(button:text-is("Wyślij komentarz"))';

    await strona.locator(`${formularz} textarea[name="body"]`).fill(tresc);

    const wyslanie = await wyslij(strona, `${formularz} button[type="submit"]:text-is("Wyślij komentarz")`);
    const widac = (await trescEkranu(strona)).includes(tresc);

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: widac,
      opis: `komentarz widoczny pod wpisem: ${widac ? 'tak' : 'NIE'}`,
    };
  });

  /* --- 6. UGOTOWAŁEM ----------------------------------------------------- */
  await sciezka('„Ugotowałem"', 'najważniejszy sygnał w serwisie (AGENTS.md §1)', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();
    const notatka = `Wyszło wyśmienicie ${Date.now().toString().slice(-8)}`;

    const przed = Number(tinker('echo App\\Models\\CookedEvent::count();').match(/\d+$/)?.[0] ?? '-1');

    const ekran = await wejdz(strona, `${adres}/przepisy/${dane.przepis}/ugotowalem`);

    await strona.fill('textarea[name="note"]', notatka);

    const wyslanie = await wyslij(strona, 'form[action$="/ugotowalem"] button[type="submit"]:text-is("Wyślij")');
    const po = Number(tinker('echo App\\Models\\CookedEvent::count();').match(/\d+$/)?.[0] ?? '-1');

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: po === przed + 1,
      opis: `wykonań przepisu w bazie: ${przed} → ${po}`,
    };
  });

  /* --- 7. OBSERWOWANIE OSOBY --------------------------------------------- */
  await sciezka('obserwowanie osoby', '', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();

    const ekran = await wejdz(strona, `${adres}/@${dane.inny}`);
    const przed = (await trescEkranu(strona)).includes('Przestań obserwować');

    const wyslanie = await wyslij(strona, `form[action$="/@${dane.inny}/obserwuj"] button[type="submit"]`);
    const po = (await trescEkranu(strona)).includes('Przestań obserwować');

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: przed === false && po === true,
      opis: `przycisk „Przestań obserwować" przed: ${przed ? 'jest' : 'nie ma'}, po: ${po ? 'jest' : 'NIE MA'}`,
    };
  });

  /* --- 8. OBSERWOWANIE TAGU ---------------------------------------------- */
  await sciezka('obserwowanie tagu', '', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();

    const ekran = await wejdz(strona, `${adres}/tag/${dane.tag}`);
    const przed = (await trescEkranu(strona)).includes('Przestań obserwować ten tag');

    const wyslanie = await wyslij(strona, `form[action$="/tag/${dane.tag}/obserwuj"] button[type="submit"]`);
    const po = (await trescEkranu(strona)).includes('Przestań obserwować ten tag');

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: przed === false && po === true,
      opis: `„Przestań obserwować ten tag" przed: ${przed ? 'jest' : 'nie ma'}, po: ${po ? 'jest' : 'NIE MA'}`,
    };
  });

  /* --- 9. ZESZYT --------------------------------------------------------- */
  await sciezka('zapisanie do zeszytu', '', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();

    const policz = () => Number(
      tinker("echo Illuminate\\Support\\Facades\\DB::table('collection_items')->count();").match(/\d+$/)?.[0] ?? '-1',
    );
    const przed = policz();

    const ekran = await wejdz(strona, `${adres}/wpisy/${dane.post}`);
    const wyslanie = await wyslij(strona, `form[action$="/wpisy/${dane.post}/zapisz"] button[type="submit"]:text-is("Zapisuję")`);

    const po = policz();

    /* Skutek widoczny na ekranie, nie tylko w bazie: przycisk „Zapisuję"
       zamienia się w odnośnik „Masz to w zeszycie". */
    const stanNaEkranie = await widocznyNaEkranie(strona, '[data-rola="stan-zapisu"]');

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: po === przed + 1 && stanNaEkranie,
      opis: `pozycji w zeszytach: ${przed} → ${po}; „Masz to w zeszycie" na ekranie: ${stanNaEkranie ? 'tak' : 'NIE'}`,
    };
  });

  /* --- 10. WYSZUKIWANIE -------------------------------------------------- */
  await sciezka('wyszukiwanie', 'formularz GET — bez wysłania POST-em', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();

    const ekran = await wejdz(strona, `${adres}/szukaj`);

    /* NA EKRANIE SĄ DWA FORMULARZE SZUKANIA: ten na ekranie i ten w belce
       (przy tej szerokości schowany). Pierwszy z brzegu jest tym schowanym,
       więc zawężamy do `<main>` — inaczej pomiar meldowałby, że wyszukiwarka
       bez skryptu nie działa, a mierzyłby własną pomyłkę. */
    await strona.locator('main form[action$="/szukaj"] input[name="q"]').fill('rosół');

    const [odp] = await Promise.all([
      strona.waitForResponse((r) => r.url().includes('/szukaj?') && r.request().method() === 'GET', { timeout: 30000 }),
      strona.locator('main form[action$="/szukaj"] button[type="submit"]').click(),
    ]);

    await strona.waitForLoadState('load');

    const tresc = await trescEkranu(strona);
    const trafienia = (tresc.match(/rosó?ł|Rosó?ł/gi) ?? []).length;

    await kontekst.close();

    return {
      ekran,
      wyslanie: `GET ${odp.status()}`,
      dokad: strona.url().replace(adres, ''),
      skutek: strona.url().includes('q=') && trafienia > 0,
      opis: `trafień słowa „rosół" w treści wyników: ${trafienia}`,
    };
  });

  /* --- 11. USTAWIENIA: PROFIL -------------------------------------------- */
  await sciezka('ustawienia — profil', 'formularz z @method(PUT)', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();
    const opis = `Gotuję bez skryptu ${Date.now().toString().slice(-8)}`;

    const ekran = await wejdz(strona, `${adres}/ustawienia/profil`);

    await strona.fill('textarea[name="bio"]', opis);

    const wyslanie = await wyslij(strona, 'form[action$="/ustawienia/profil"] button[type="submit"]:text-is("Zapisz")');

    await wejdz(strona, `${adres}/ustawienia/profil`);
    const zapisane = await strona.inputValue('textarea[name="bio"]');

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: zapisane === opis,
      opis: `pole „o sobie" po ponownym wejściu: ${zapisane === opis ? 'zapisane' : `NIE ZAPISANE („${zapisane.slice(0, 30)}")`}`,
    };
  });

  /* --- 12. USTAWIENIA: MOTYW --------------------------------------------- */
  await sciezka('ustawienia — motyw', 'przełącznik w stopce (D-051)', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();

    const ekran = await wejdz(strona, `${adres}/home`);
    const przed = await strona.getAttribute('html', 'data-theme');

    const wyslanie = await wyslij(strona, 'form.site-footer-motyw button[type="submit"]');
    const po = await strona.getAttribute('html', 'data-theme');

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: przed !== po,
      opis: `data-theme: ${przed ?? '(brak)'} → ${po ?? '(brak)'}`,
    };
  });

  /* --- 13. ZGŁOSZENIE TREŚCI --------------------------------------------- */
  await sciezka('zgłoszenie treści', '', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();

    const przed = Number(tinker('echo App\\Models\\Report::count();').match(/\d+$/)?.[0] ?? '-1');

    const ekran = await wejdz(strona, `${adres}/zglos/post/${dane.post}`);

    await strona.locator('input[name="reason"]').first().check();

    const wyslanie = await wyslij(strona, 'form[action*="/zglos/"] button[type="submit"]:text-is("Wyślij zgłoszenie")');
    const po = Number(tinker('echo App\\Models\\Report::count();').match(/\d+$/)?.[0] ?? '-1');

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: po === przed + 1,
      opis: `zgłoszeń w bazie: ${przed} → ${po}`,
    };
  });

  /* --- 14. NAWIGACJA: MENU KONTA ----------------------------------------- */
  await sciezka('nawigacja — menu konta', '`<details>` otwiera się bez skryptu', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();

    const ekran = await wejdz(strona, `${adres}/home`);
    const przed = await widocznyNaEkranie(strona, '.topbar-konto-tresc');

    await strona.locator('.topbar-konto > summary').click();

    const po = await widocznyNaEkranie(strona, '.topbar-konto-tresc');
    const otwarte = await strona.locator('.topbar-konto').first().evaluate((el) => el.open);

    /* Menu bez działającego odnośnika byłoby martwym przyciskiem — sprawdzamy
       więc, że da się nim naprawdę przejść na ustawienia. */
    await strona.locator('.topbar-konto-tresc a[href$="/ustawienia"]').click();
    await strona.waitForLoadState('load');

    const doszlo = strona.url().endsWith('/ustawienia');

    await kontekst.close();

    return {
      ekran,
      wyslanie: 'GET (odnośnik)',
      dokad: strona.url().replace(adres, ''),
      skutek: przed === false && po === true && otwarte === true && doszlo,
      opis: `treść menu widoczna przed: ${przed ? 'tak' : 'nie'}, po kliknięciu: ${po ? 'tak' : 'NIE'}; wejście w „Ustawienia": ${doszlo ? 'tak' : 'NIE'}`,
    };
  });

  /* --- 15. NAWIGACJA: MENU KARTY WPISU ----------------------------------- */
  await sciezka('nawigacja — menu karty wpisu', 'jedyny wyjątek „sama ikona" (AGENTS.md §5)', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();

    const ekran = await wejdz(strona, `${adres}/home`);
    const przed = await widocznyNaEkranie(strona, '.post-card-menu > :not(summary)');
    const menu = strona.locator('.post-card-menu').first();

    await strona.locator('.post-card-menu > summary').first().click();

    const po = await widocznyNaEkranie(strona, '.post-card-menu > :not(summary)');
    const otwarte = await menu.evaluate((el) => el.open);

    /* Dokąd ma zaprowadzić, czytamy Z SAMEGO ODNOŚNIKA, zamiast zgadywać
       kształt adresu: „Otwórz wpis" prowadzi do wpisu, ale wpis z przepisem
       ma adres treści na `/przepisy/…` (`Post::adresTresci()`). Porównanie
       z `href` mierzy to, co ma znaczenie — że kliknięcie bez skryptu
       naprawdę dochodzi tam, dokąd obiecuje. */
    const odnosnik = strona.locator('.post-card-menu[open] a').filter({ hasText: 'Otwórz wpis' }).first();
    const cel = await odnosnik.getAttribute('href');
    const odpowiedzi = nasluch(strona);

    await odnosnik.click();
    await strona.waitForLoadState('load');

    /* Adres KOŃCOWY nie musi być równy `href`: wpis z przepisem prowadzi na
       `/wpisy/…`, a serwis odsyła stamtąd na stronę przepisu. Mierzymy więc
       to, co znaczy „przycisk nie jest martwy": żądanie poszło pod `href`,
       strona się zmieniła i skończyło się kodem 200, a nie błędem. */
    const poszlo = odpowiedzi.some((o) => o.url === cel);
    const koncowa = odpowiedzi.filter((o) => o.url === strona.url()).pop();
    const doszlo = poszlo && strona.url() !== `${adres}/home` && koncowa?.kod === 200;

    await kontekst.close();

    return {
      ekran,
      wyslanie: 'GET (odnośnik)',
      dokad: strona.url().replace(adres, ''),
      skutek: przed === false && po === true && otwarte === true && doszlo,
      opis: `treść menu widoczna przed: ${przed ? 'tak' : 'nie'}, po kliknięciu: ${po ? 'tak' : 'NIE'}; `
        + `„Otwórz wpis" → ${cel?.replace(adres, '') ?? '(brak href)'} → ${strona.url().replace(adres, '')} (kod ${koncowa?.kod ?? '—'})`,
    };
  });

  /* --- 16. KREATOR PRZEPISU (LIVEWIRE) I JEGO DROGA BEZ SKRYPTU ---------- */
  await sciezka('szczegóły przepisu — droga bez skryptu', 'kreator wymaga JS; mierzymy, czy nie zostaje martwy przycisk', async () => {
    const kontekst = await nowyKontekst(sesjaAni);
    const strona = await kontekst.newPage();
    const wlasny = tinker(`
      $ania = App\\Models\\User::whereHas('profile', fn($q) => $q->where('username', '${KONTO}'))->firstOrFail();
      echo App\\Models\\Recipe::where('author_id', $ania->getKey())->where('status', 'published')->value('slug');
    `).split('\n').pop().trim();

    const ekran = await wejdz(strona, `${adres}/przepisy/${wlasny}/szczegoly`);

    /* D-053 punkt 3: bez skryptu człowiek ma zobaczyć zdanie mówiące, CO
       ZROBIĆ, i mieć dokąd pójść. Sprawdzamy jedno i drugie. */
    const tresc = await trescEkranu(strona);
    const zdanie = tresc.includes('nie wykonuje skryptów');
    const wyjscie = strona.locator('main a[href$="/edycja"]').first();
    const jestWyjscie = await wyjscie.count() > 0 && await wyjscie.isVisible();

    if (! jestWyjscie) {
      await kontekst.close();

      return {
        ekran,
        wyslanie: '—',
        dokad: '—',
        skutek: false,
        opis: `zdanie „bez skryptu": ${zdanie ? 'jest' : 'NIE MA'}; wyjście na formularz bez skryptu: NIE MA`,
      };
    }

    await wyjscie.click();
    await strona.waitForLoadState('load');

    const porcje = `${4 + (Date.now() % 5)}`;

    await strona.locator('input[name="servings"]').fill(porcje);

    const wyslanie = await wyslij(strona, 'form[action*="/przepisy/"] button[type="submit"]:text-is("Zapisz zmiany")');
    const zapisane = tinker(`echo App\\Models\\Recipe::where('slug', '${wlasny}')->value('servings');`).split('\n').pop().trim();

    await kontekst.close();

    return {
      ekran,
      wyslanie: `${wyslanie.metoda} ${wyslanie.kod}`,
      dokad: strona.url().replace(adres, ''),
      skutek: zdanie && Number(zapisane) === Number(porcje),
      opis: `zdanie „bez skryptu": ${zdanie ? 'jest' : 'NIE MA'}; porcje po zapisie: ${zapisane} (chciane ${porcje})`,
    };
  });
} finally {
  await przegladarka.close();
  zamknij();
}

/* ---------------------------------------------------------------------------
   TABELA
   --------------------------------------------------------------------------- */

console.log('');
console.log('ścieżka                        ekran  wysłanie      dokąd                                   BEZ JS');
console.log('-'.repeat(118));

for (const w of wyniki) {
  console.log(
    w.nazwa.padEnd(31)
    + String(w.ekran).padEnd(7)
    + String(w.wyslanie).padEnd(14)
    + String(w.dokad).slice(0, 38).padEnd(40)
    + (w.skutek ? 'TAK' : 'NIE'),
  );
  console.log(`${' '.repeat(31)}${w.opis}`);
  if (w.uwaga) console.log(`${' '.repeat(31)}(${w.uwaga})`);
}

const dziala = wyniki.filter((w) => w.skutek).length;

console.log('');
console.log(`Ścieżek zmierzonych: ${wyniki.length}`);
console.log(`Dochodzą do końca BEZ JavaScriptu: ${dziala} z ${wyniki.length}`);
console.log(`Nie dochodzą: ${wyniki.length - dziala}`);


/* ---------------------------------------------------------------------------
   FAZA 2 — TEN SAM POMIAR PRZY WŁĄCZONYM TURNSTILE (KSZTAŁT PRODUKCYJNY)
   ---------------------------------------------------------------------------
   Faza 1 chodzi bez kluczy Turnstile, czyli tak, jak chodzi kontener agenta,
   CI i każde lokalne środowisko (`config/kuking.php`: puste klucze = Turnstile
   nie istnieje). Na produkcji klucze SĄ, a wtedy D-050 i D-053 mówią coś
   innego: brak tokenu ODRZUCA wysłanie rejestracji i logowania.

   Gdyby pomiar kończył się na fazie 1, raport mówiłby „rejestracja bez
   JavaScriptu: TAK" o stanie, którego na produkcji nie ma. Dlatego faza 2
   podnosi DRUGI serwer z podstawionymi kluczami i pyta o to, czego D-053
   wymaga naprawdę: czy zamiast martwego przycisku człowiek dostaje zdanie
   mówiące, co się stało i co zrobić.

   Klucze są nieprawdziwe i to nie szkodzi: brak tokenu jest odrzucany, ZANIM
   ktokolwiek pyta Cloudflare, więc ta faza nie wychodzi do sieci.
   --------------------------------------------------------------------------- */
const turnstile = [];

/*
 * WSZYSTKIE SIEDEM MIEJSC Z TURNSTILE (`config/kuking.php`). Każde da się
 * otworzyć bez przygotowań — także „cofnij usunięcie konta", bo ten ekran
 * pyta o login i hasło dopiero w formularzu.
 *
 * `czynnosc` musi być dokładnie tym słowem, które `Turnstile::czynnosc()`
 * wstawia po „Do " — tak sprawdzamy, że `<noscript>` mówi, CZEGO KONKRETNIE
 * nie da się zrobić, a nie ogólne „wymagany JavaScript" (D-053).
 */
const MIEJSCA_TURNSTILE = [
  ['rejestracja', '/register', 'założenia konta'],
  ['logowanie', '/login', 'zalogowania się'],
  ['odzyskanie hasła', '/nie-pamietam-hasla', 'wysłania linku do nowego hasła'],
  ['link do logowania', '/logowanie/link', 'wysłania linku do zalogowania się'],
  ['napisz do nas', '/napisz-do-nas', 'wysłania do nas wiadomości'],
  ['zgłoszenie nielegalnej treści', '/zglos-nielegalna-tresc', 'wysłania zgłoszenia'],
  ['cofnięcie usunięcia konta', '/cofnij-usuniecie-konta', 'cofnięcia usunięcia konta'],
];

/* Dwa formularze, które dodatkowo WYSYŁAMY — żeby zobaczyć, co się dzieje po
   kliknięciu. Nie wysyłamy pozostałych czterech, bo trzy z nich wypuszczają
   list albo wiadomość do skrzynki, której pilnuje jedna osoba. */
const WYSYLANE = {
  '/register': async (strona) => {
    const znacznik = `turn${Date.now().toString().slice(-8)}`;

    await strona.fill('input[name="display_name"]', 'Bez Skryptu');
    await strona.fill('input[name="username"]', znacznik);
    await strona.fill('input[name="email"]', `${znacznik}@example.test`);
    await strona.fill('input[name="password"]', 'haslo-testowe-123');
    await strona.check('input[name="age_confirmed"]');
    await strona.check('input[name="terms_accepted"]');

    return {
      przycisk: 'form[action$="/register"] button[type="submit"]:text-is("Załóż konto")',
      czyPrzeszlo: () => tinker(`echo App\\Models\\Profile::where('username', '${znacznik}')->exists() ? 'JEST' : 'NIE MA';`).includes('JEST'),
    };
  },
  '/login': async (strona) => {
    await strona.fill('input[name="login"]', KONTO);
    await strona.fill('input[name="password"]', HASLO);

    return {
      przycisk: 'form[action$="/login"] button[type="submit"]:text-is("Zaloguj się")',
      czyPrzeszlo: () => strona.url().endsWith('/home'),
    };
  },
};

async function faza2() {
  const { adres: adres2, zamknij: zamknij2 } = await podniesSerwer({
    TURNSTILE_SITE_KEY: '0x0000000000000000000000',
    TURNSTILE_SECRET_KEY: '0x0000000000000000000000000000000000000000',

    /* MAIL_MAILER TU NIE JEST OZDOBĄ — BEZ NIEGO TA FAZA KŁAMIE.
       `/nie-pamietam-hasla` i `/logowanie/link` świadomie NIE POKAZUJĄ
       formularza, gdy serwis nie ma czym wysłać poczty (`Poczta::dziala()`:
       sterowniki `log` i `array` meldują sukces, a list nie przychodzi).
       W kontenerze agenta stoi `MAIL_MAILER=log`, więc pierwszy przebieg tej
       fazy zameldował „2 ekrany bez widgetu Turnstile, mimo kluczy" — i była
       to czerwień z niewłaściwej warstwy (docs/PULAPKI_TESTOW.md §8b), bo oba
       ekrany w ogóle nie miały formularza do ochrony. `smtp` sprawia, że
       formularze się renderują; NICZEGO stąd nie wysyłamy — te dwa ekrany są
       tylko oglądane, nie ma ich w tablicy `WYSYLANE`. */
    MAIL_MAILER: 'smtp',
  }, false);

  const przegladarka2 = await chromium.launch({ executablePath: CHROMIUM });

  try {
    for (const [nazwa, sciezkaEkranu, czynnosc] of MIEJSCA_TURNSTILE) {
      const kontekst = await przegladarka2.newContext({ javaScriptEnabled: false, viewport: WIDOK });
      const strona = await kontekst.newPage();
      const ekran = await wejdz(strona, `${adres2}${sciezkaEkranu}`);

      const widget = await strona.locator('.cf-turnstile').count();
      const tresc = await trescEkranu(strona);
      const zdanie = tresc.includes(`Do ${czynnosc} potrzebny jest włączony JavaScript`);
      const adresKontaktowy = /[\w.+-]+@[\w.-]+\.\w+/.test(tresc);

      const wiersz = { nazwa, ekran, widget, zdanie, adresKontaktowy, wyslanie: 'nie wysyłane', przeszlo: null, odmowa: null };

      if (WYSYLANE[sciezkaEkranu] !== undefined) {
        const { przycisk, czyPrzeszlo } = await WYSYLANE[sciezkaEkranu](strona);
        const wyslanie = await wyslij(strona, przycisk);

        wiersz.wyslanie = `${wyslanie.metoda} ${wyslanie.kod}`;
        wiersz.odmowa = (await trescEkranu(strona)).includes('Nie udało się wczytać sprawdzenia');
        wiersz.przeszlo = czyPrzeszlo();
      }

      turnstile.push(wiersz);

      await kontekst.close();
    }
  } finally {
    await przegladarka2.close();
    zamknij2();
  }
}

await faza2();

console.log('');
console.log('FAZA 2 — Turnstile WŁĄCZONY, poczta dostarczająca (kształt produkcyjny), przeglądarka bez JavaScriptu');
console.log('-'.repeat(118));

for (const t of turnstile) {
  console.log(`${t.nazwa.padEnd(31)}ekran ${t.ekran}  widgetów: ${t.widget}  wysłanie: ${t.wyslanie}`);
  console.log(`${' '.repeat(31)}zdanie <noscript> mówiące, czego nie da się zrobić: ${t.zdanie ? 'JEST' : 'NIE MA'}`
    + `; adres kontaktowy: ${t.adresKontaktowy ? 'jest' : 'NIE MA'}`);
  if (t.przeszlo !== null) {
    console.log(`${' '.repeat(31)}formularz przeszedł: ${t.przeszlo ? 'TAK' : 'nie'}`
      + `; komunikat odmowy po polsku: ${t.odmowa ? 'JEST' : 'NIE MA'}`);
  }
}

/* MARTWY PRZYCISK = odmowa (albo cisza) bez zdania mówiącego, co zrobić.
   Ekran, którego nie wysyłaliśmy, liczy się jako martwy, jeśli nie ma
   `<noscript>` — bo wtedy człowiek dowiaduje się o wymogu dopiero po
   kliknięciu, czyli za późno. */
const martwePrzyciski = turnstile.filter((t) => t.zdanie === false || (t.przeszlo === false && t.odmowa === false));
const bezWidgetu = turnstile.filter((t) => t.widget < 1);

console.log('');
console.log(`Ekranów bez widgetu Turnstile (czyli bez ochrony, mimo kluczy): ${bezWidgetu.length} z ${turnstile.length}`);
console.log(`Ekranów z wymogiem skryptu bez zdania „co zrobić" (martwy przycisk): ${martwePrzyciski.length} z ${turnstile.length}`);

if (wyniki.length < 16) {
  console.error(`\nBŁĄD: zebrano ${wyniki.length} ścieżek zamiast 16 — pomiar jest niepełny.`);
  process.exit(1);
}

if (turnstile.length < MIEJSCA_TURNSTILE.length) {
  console.error(`\nBŁĄD: faza 2 zmierzyła ${turnstile.length} ekranów zamiast ${MIEJSCA_TURNSTILE.length}.`);
  process.exit(1);
}

process.exit(dziala === wyniki.length && martwePrzyciski.length === 0 && bezWidgetu.length === 0 ? 0 : 1);
