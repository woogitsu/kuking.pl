/*
 * =============================================================================
 *  Kuking.pl — pomiar przycisku wyjęcia wpisu z zeszytu na karcie (audyt L1,
 *  po ujednoliceniu dwóch dróg — D-225)
 * =============================================================================
 *
 *  PO CO TO JEST
 *  Wpis odłożony do zeszytu nie miał w całym serwisie drogi wyjścia: trasa
 *  `DELETE /wpisy/{post}/zapisz` istniała, ale żaden widok jej nie wołał
 *  (`docs/AUDYT_2026-09.md`, wiersz L1). Przycisk dokładamy na karcie wpisu,
 *  czyli wszędzie tam, gdzie widać „Masz to w zeszycie" — a karta wpisu jest
 *  już gęsta. Ten skrypt zamienia pytanie „czy się mieści" na liczby.
 *
 *  CO MIERZY — `getBoundingClientRect()`, NIE KLASY CSS
 *  Klasa w szablonie nie dowodzi niczego: 18 września znaleziono w tym
 *  repozytorium cztery widoki używające klasy, której w zbudowanym arkuszu
 *  nie było wcale. Dlatego liczymy ułożoną stronę:
 *
 *   1. wysokość i szerokość przycisku (próg 48 px — AGENTS.md §5),
 *   2. rozmiar pisma w przycisku (próg 18 px),
 *   3. ODLEGŁOŚĆ od odnośnika „Masz to w zeszycie" — bo to on stoi w miejscu,
 *      w które przed chwilą kliknięto „Zapisuję"; zero pikseli przerwy
 *      znaczyłoby, że drugie kliknięcie (norma w grupie 50+, issue #43)
 *      trafia w przycisk kasujący,
 *   4. `scrollWidth` dokumentu kontra szerokość okna — czy strona nie
 *      przewija się w bok,
 *   5. to samo dla przycisku „Zapisz ponownie" w komunikacie po akcji.
 *
 *  WARIANTY: 320 px i 320 px z tekstem 140% (nasze ustawienie z profilu,
 *  maksimum dopuszczone CHECK-iem w bazie), plus 390 px jako zwykły telefon.
 *  Do tego axe-core na obu ekranach W STANIE, W KTÓRYM PRZYCISK JEST WIDOCZNY
 *  — `scripts/dostepnosc.mjs` chodzi po tych ekranach jako ktoś, kto nie ma
 *  tego wpisu w zeszycie, więc tego przycisku nigdy nie ogląda.
 *
 *  PRZEBIEG BEZ JAVASCRIPTU JEST OSOBNYM PRZEBIEGIEM (`javaScriptEnabled:
 *  false`): to jest zwykły formularz `DELETE` i ma działać tak samo.
 *
 *  URUCHOMIENIE (z katalogu projektu)
 *      node scripts/wyjecie-z-zeszytu.mjs
 *      ADRES=http://127.0.0.1:8123 node scripts/wyjecie-z-zeszytu.mjs
 *
 *  Bez `ADRES` skrypt sieje własną bazę `kuking_wyjecie` (NIE `kuking_test`
 *  i NIE deweloperską — robi `migrate:fresh`), podnosi `php artisan serve`
 *  i sam go gasi.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';
const BAZA_DOMYSLNA = 'kuking_wyjecie';

const PROG_PRZYCISK = 48;
const PROG_TEKST = 18;

const WARIANTY = [
  { nazwa: '320 px', szerokosc: 320, wysokosc: 800, skalaTekstu: null },
  { nazwa: '320 px · tekst 140%', szerokosc: 320, wysokosc: 800, skalaTekstu: 140 },
  { nazwa: '390 px', szerokosc: 390, wysokosc: 844, skalaTekstu: null },
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

/*
 * FIXTURE: CUDZY wpis w zeszycie Ani.
 *
 * Cudzy, nie własny — własny wpis w zeszycie też ma przycisk, ale karta autora
 * niesie dodatkowo menu „…" i to jest inny, luźniejszy układ. Mierzymy ten
 * ciaśniejszy. Żaden seeder nie zapisuje wpisów do zeszytów, więc bez tej
 * funkcji ekran zeszytu byłby pusty, a pusty ekran przechodzi każdy pomiar.
 */
function przygotujZapisanyWpis() {
  const php = `
    $ania = App\\Models\\Profile::where('username', '${KONTO}')->value('user_id');
    if (! $ania) { echo 'BRAK-KONTA'; exit; }

    $wpis = App\\Models\\Post::query()
        ->where('author_id', '!=', $ania)
        ->where('visibility', 'public')
        ->whereNotNull('published_at')
        ->whereHas('media')
        ->latest('published_at')
        ->first();

    if (! $wpis) { echo 'BRAK-WPISU'; exit; }

    $zeszyt = App\\Models\\User::find($ania)->defaultCollection();
    $zeszyt->posts()->syncWithoutDetaching([$wpis->getKey()]);

    echo $wpis->getKey().';'.$zeszyt->getKey();
  `;

  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: env() })
    .toString().trim().split('\n').pop().trim();

  if (! wynik.includes(';')) {
    throw new Error(`Nie udało się przygotować zapisanego wpisu. Wyjście tinkera: ${wynik}`);
  }

  const [wpis, zeszyt] = wynik.split(';');

  return { wpis, zeszyt };
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
  if (process.env.ADRES) {
    return { adres: process.env.ADRES, zamknij: () => {} };
  }

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  // Arkusz jest ZBUDOWANY, nie czytany z `resources/` — bez przebudowania
  // pomiar opisywałby poprzednią wersję CSS.
  console.log('Buduję arkusz (vite build)...');
  execFileSync('npm', ['run', 'build'], { stdio: 'ignore', env: process.env });

  console.log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const port = await wolnyPort();
  const adres = `http://127.0.0.1:${port}`;
  const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
    stdio: ['ignore', 'pipe', 'pipe'],
    env: env(),
  });

  for (let i = 0; i < 60; i++) {
    try {
      const odp = await fetch(`${adres}/health`);
      if (odp.ok) return { adres, zamknij: () => proces.kill('SIGTERM') };
    } catch { /* jeszcze nie wstał */ }
    await new Promise((r) => setTimeout(r, 500));
  }

  proces.kill('SIGKILL');
  throw new Error('Nie udało się podnieść `php artisan serve`.');
}

const POMIAR = () => {
  const zaokr = (x) => Math.round(x * 100) / 100;
  const opis = (el) => {
    if (! el) return null;
    const p = el.getBoundingClientRect();

    return {
      szerokosc: zaokr(p.width),
      wysokosc: zaokr(p.height),
      pismo: zaokr(parseFloat(getComputedStyle(el).fontSize)),
      napis: el.textContent.trim().replace(/\s+/g, ' '),
      lewo: zaokr(p.left),
      gora: zaokr(p.top),
    };
  };

  /*
   * JEDEN SELEKTOR NA OBIE DROGI WYJĘCIA (D-225).
   *
   * Ten sam przycisk nazywa się inaczej w zależności od zakresu: w środku
   * zeszytu „Usuń z tego zeszytu" (`wyjmij-z-tego-zeszytu`), poza nim
   * „Usuń z zeszytu" (`wyjmij-z-zeszytu`). Mierzymy ten, który akurat stoi
   * na ekranie — a `napis` niżej mówi, który to był.
   */
  const wyjmij = document.querySelector('[data-rola^="wyjmij-z-"]');
  const stan = document.querySelector('[data-rola="stan-zapisu"]');
  const powrot = document.querySelector('[data-rola="powrot-po-akcji"]');

  let przerwa = null;
  if (wyjmij && stan) {
    const a = stan.getBoundingClientRect();
    const b = wyjmij.getBoundingClientRect();
    // Odległość między najbliższymi krawędziami — w pionie, jeśli pasek się
    // zawinął, w poziomie, jeśli stoją obok siebie.
    przerwa = zaokr(b.top >= a.bottom ? b.top - a.bottom : b.left - a.right);
  }

  return {
    wyjmij: opis(wyjmij),
    stan: opis(stan),
    powrot: opis(powrot),
    przerwaOdStanu: przerwa,
    flash: document.querySelector('.flash')?.textContent.trim().replace(/\s+/g, ' ') ?? null,
    scrollWidth: document.documentElement.scrollWidth,
    okno: window.innerWidth,
  };
};

async function zaloguj(strona, adres) {
  await strona.goto(`${adres}/login`);
  await strona.fill('input[name="login"]', KONTO);
  await strona.fill('input[name="password"]', HASLO);
  await Promise.all([
    strona.waitForURL((u) => ! u.pathname.endsWith('/login'), { timeout: 20000 }),
    strona.click('button[type="submit"]'),
  ]);
}

const CHROMIUM = znajdzChromium();
const { adres, zamknij } = await podniesSerwer();
const { wpis, zeszyt } = przygotujZapisanyWpis();

console.log(`Serwer: ${adres}\nWpis: ${wpis}\nZeszyt: ${zeszyt}\n`);

const EKRANY = [
  { nazwa: 'zeszyt', sciezka: `/zeszyt/${zeszyt}` },
  { nazwa: 'karta wpisu', sciezka: `/wpisy/${wpis}` },
];

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });
const bledy = [];

/*
 * LOGUJEMY SIĘ RAZ NA CAŁY PRZEBIEG, a nie raz na wariant.
 *
 * Logowanie ma własny limit żądań (`config/kuking.php`), więc siódme
 * podejście z tego samego adresu kończy się odmową — i wygląda to jak awaria
 * mierzonego ekranu, a nie jak działający limit. Stan sesji przenosimy
 * między kontekstami przez `storageState`.
 */
const kontekstLogowania = await przegladarka.newContext({ viewport: { width: 390, height: 844 } });
const stronaLogowania = await kontekstLogowania.newPage();
await zaloguj(stronaLogowania, adres);
const SESJA = await kontekstLogowania.storageState();
await kontekstLogowania.close();

function sprawdz(warunek, komunikat) {
  if (! warunek) bledy.push(komunikat);
}

try {
  for (const wariant of WARIANTY) {
    for (const ekran of EKRANY) {
      const kontekst = await przegladarka.newContext({
        viewport: { width: wariant.szerokosc, height: wariant.wysokosc },
        storageState: SESJA,
      });
      const strona = await kontekst.newPage();

      await strona.goto(adres + ekran.sciezka);
      await strona.waitForSelector('[data-rola^="wyjmij-z-"]', { timeout: 15000 });

      /*
       * SKALĘ TEKSTU USTAWIAMY PO ZAŁADOWANIU, NIE `addInitScript`.
       *
       * `data-text-scale` wypisuje na `<html>` serwer (layout, z ustawienia
       * w profilu), więc atrybut nadany przed parsowaniem dokumentu zostaje
       * nadpisany przez parser — pomiar wychodził wtedy identyczny jak bez
       * skali i cicho mierzył nie to, co trzeba. Dlatego niżej sprawdzamy
       * jeszcze, czy atrybut NAPRAWDĘ tam stoi.
       */
      if (wariant.skalaTekstu) {
        await strona.evaluate(
          (s) => document.documentElement.setAttribute('data-text-scale', String(s)),
          wariant.skalaTekstu,
        );

        const stoi = await strona.evaluate(() => document.documentElement.getAttribute('data-text-scale'));
        sprawdz(stoi === String(wariant.skalaTekstu),
          `${wariant.nazwa} · ${ekran.nazwa}: skala tekstu nie weszła (atrybut: ${stoi}).`);
      }

      const p = await strona.evaluate(POMIAR);

      console.log(`=== ${wariant.nazwa} · ${ekran.nazwa} ===`);
      console.log(`  „${p.wyjmij.napis}"  ${p.wyjmij.szerokosc} × ${p.wyjmij.wysokosc} px, pismo ${p.wyjmij.pismo} px`);
      console.log(`  „${p.stan?.napis ?? '— (w zeszycie nie ma odnośnika stanu)'}"  ${p.stan?.szerokosc ?? '—'} × ${p.stan?.wysokosc ?? '—'} px`);
      console.log(`  przerwa od „Masz to w zeszycie": ${p.przerwaOdStanu ?? 'nie dotyczy (ekran zeszytu)'}`);
      console.log(`  scrollWidth ${p.scrollWidth} px przy oknie ${p.okno} px`);

      sprawdz(p.wyjmij.wysokosc >= PROG_PRZYCISK,
        `${wariant.nazwa} · ${ekran.nazwa}: przycisk ma ${p.wyjmij.wysokosc} px wysokości, próg to ${PROG_PRZYCISK}.`);
      sprawdz(p.wyjmij.pismo >= PROG_TEKST,
        `${wariant.nazwa} · ${ekran.nazwa}: pismo w przycisku ma ${p.wyjmij.pismo} px, próg to ${PROG_TEKST}.`);
      // Przerwa ma sens tylko tam, gdzie odnośnik „Masz to w zeszycie" w ogóle
      // stoi — czyli POZA zeszytem (D-225). W zeszycie przycisk jest jedyną
      // rzeczą w tym miejscu paska i nie ma się z czym stykać.
      sprawdz(p.stan === null || p.przerwaOdStanu > 0,
        `${wariant.nazwa} · ${ekran.nazwa}: przycisk kasujący styka się z „Masz to w zeszycie" (${p.przerwaOdStanu} px).`);
      sprawdz(p.scrollWidth <= p.okno,
        `${wariant.nazwa} · ${ekran.nazwa}: strona przewija się w bok (${p.scrollWidth} px przy oknie ${p.okno} px).`);

      const axe = await new AxeBuilder({ page: strona })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();

      const powazne = axe.violations.filter((n) => ['critical', 'serious'].includes(n.impact));

      console.log(`  axe: ${axe.violations.length} naruszeń, w tym ${powazne.length} poważnych`);
      for (const n of axe.violations) {
        console.log(`    [${n.impact}] ${n.id} — ${n.nodes.length} × ${n.help}`);
      }
      sprawdz(powazne.length === 0, `${wariant.nazwa} · ${ekran.nazwa}: axe zgłasza ${powazne.length} poważnych naruszeń.`);

      console.log('');
      await kontekst.close();
    }
  }

  /*
   * PRZEBIEG BEZ JAVASCRIPTU — pełna droga: klik → komunikat → powrót.
   * Bez skryptu nie da się wypełnić formularza logowania przez Turnstile
   * (D-050), więc ciasteczko sesji bierzemy z kontekstu ze skryptem
   * i przenosimy do kontekstu bez skryptu. Mierzona jest droga wyjęcia,
   * nie logowanie.
   */
  /*
   * BRAMKA: czy skrypt strony NAPRAWDĘ jest wyłączony (i czy sonda działa).
   * `javaScriptEnabled: false` nie blokuje `page.evaluate()` (to idzie przez
   * CDP), więc sama flaga niczego nie dowodzi — wzorzec z
   * `scripts/bez-javascriptu.mjs`, §5 `docs/PULAPKI_TESTOW.md`.
   */
  const SONDA = 'data:text/html,<body><script>document.body.setAttribute("data-skrypt","dziala")</scr'
    + 'ipt></body>';

  const sonda = async (javaScriptEnabled) => {
    const k = await przegladarka.newContext({ javaScriptEnabled });
    const s = await k.newPage();
    await s.goto(SONDA);
    const a = await s.getAttribute('body', 'data-skrypt');
    await k.close();

    return a;
  };

  const bezSondy = await sonda(false);
  const zeSonda = await sonda(true);

  console.log(`Bramka A (skrypt wyłączony → pusto): ${bezSondy ?? 'pusto'}`);
  console.log(`Bramka B (skrypt włączony → działa): ${zeSonda ?? 'pusto'}`);
  sprawdz(bezSondy === null, 'Bramka A: skrypt strony NIE był wyłączony — przebieg „bez JavaScriptu" nic nie dowodzi.');
  sprawdz(zeSonda === 'dziala', 'Bramka B: sonda skryptu jest zepsuta — brak atrybutu nic nie znaczy.');

  const bezSkryptu = await przegladarka.newContext({
    viewport: { width: 320, height: 800 },
    javaScriptEnabled: false,
    storageState: SESJA,
  });
  const strona = await bezSkryptu.newPage();

  await strona.goto(`${adres}/zeszyt/${zeszyt}`);

  const przed = await strona.evaluate(POMIAR).catch(() => null);
  console.log('=== bez JavaScriptu · zeszyt ===');
  console.log(`  przycisk widoczny: ${await strona.locator('[data-rola^="wyjmij-z-"]').count() === 1}`);

  await Promise.all([
    strona.waitForNavigation({ timeout: 15000 }),
    strona.click('[data-rola^="wyjmij-z-"]'),
  ]);

  const po = await strona.evaluate(POMIAR);

  console.log(`  komunikat: „${po.flash}"`);
  console.log(`  powrót: „${po.powrot?.napis}"  ${po.powrot?.szerokosc} × ${po.powrot?.wysokosc} px, pismo ${po.powrot?.pismo} px`);
  console.log(`  wpis nadal w zeszycie: ${await strona.locator('[data-rola^="wyjmij-z-"]').count() > 0}`);
  console.log(`  scrollWidth ${po.scrollWidth} px przy oknie ${po.okno} px`);

  sprawdz(przed !== null, 'Bez JavaScriptu nie udało się w ogóle zmierzyć ekranu zeszytu.');
  sprawdz(po.flash !== null && po.flash.includes('wyjęty z zeszytu'),
    `Bez JavaScriptu komunikat po akcji brzmi „${po.flash}".`);
  sprawdz(po.powrot !== null, 'Bez JavaScriptu nie ma przycisku powrotu w komunikacie.');
  sprawdz((po.powrot?.wysokosc ?? 0) >= PROG_PRZYCISK,
    `Bez JavaScriptu przycisk powrotu ma ${po.powrot?.wysokosc} px wysokości.`);
  sprawdz((po.powrot?.pismo ?? 0) >= PROG_TEKST,
    `Bez JavaScriptu pismo przycisku powrotu ma ${po.powrot?.pismo} px.`);
  sprawdz(po.scrollWidth <= po.okno,
    `Bez JavaScriptu strona z komunikatem przewija się w bok (${po.scrollWidth} px przy oknie ${po.okno} px).`);

  // I powrót naprawdę wraca — bez skryptu.
  await Promise.all([
    strona.waitForNavigation({ timeout: 15000 }),
    strona.click('[data-rola="powrot-po-akcji"]'),
  ]);

  const wrocil = await strona.evaluate(POMIAR);
  console.log(`  po kliknięciu „Zapisz ponownie": „${wrocil.flash}"\n`);
  sprawdz(wrocil.flash !== null && wrocil.flash.includes('Zapisane w zeszycie'),
    `Bez JavaScriptu powrót nie zapisał wpisu — komunikat: „${wrocil.flash}".`);

  await bezSkryptu.close();
} finally {
  await przegladarka.close();
  zamknij();
}

if (bledy.length > 0) {
  console.error('\nPOMIAR ZGŁASZA PROBLEMY:');
  for (const b of bledy) console.error(`  ✗ ${b}`);
  process.exit(1);
}

console.log('Wszystkie progi spełnione.');
