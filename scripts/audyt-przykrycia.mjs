/*
 * =============================================================================
 *  Kuking.pl — pomiar PRZYKRYCIA treści przez elementy pływające (#684)
 * =============================================================================
 *
 *  CO TEN SKRYPT MIERZY, CZEGO NIE MIERZY AXE
 *  axe-core czyta drzewo dokumentu. Element przykryty przez pływający przycisk
 *  jest w drzewie kompletny: ma nazwę dostępną, rolę, kontrast i cel dotknięcia
 *  48 px. Dla axe to zielony wynik. Dla człowieka z myszą albo palcem to link,
 *  w który nie da się trafić, bo pod kursorem jest co innego.
 *
 *  Dlatego ten pomiar nie pyta drzewa, tylko układu: bierze każdy widoczny
 *  element interaktywny i sprawdza `document.elementFromPoint` w jego środku
 *  oraz w ośmiu punktach wokół. Jeśli WSZYSTKIE trafiają w coś innego, element
 *  jest dla wskaźnika nieosiągalny — niezależnie od tego, co mówi drzewo.
 *
 *  DLACZEGO TO NIE JEST TO SAMO CO KLAWIATURA
 *  `resources/js/szybki-wyglad.js` odsłania treść w uchwycie `focusin`:
 *  Tab powoduje doprzewinięcie albo przejście widgetu w tryb przepływu.
 *  Mysz i dotyk nie wywołują `focusin` na linku, więc tej mitygacji nie
 *  dostają. Ten skrypt mierzy stan BEZ fokusu, czyli dokładnie to, co widzi
 *  osoba przewijająca stronę palcem.
 *
 *  UŻYCIE
 *    node scripts/audyt-przykrycia.mjs                # pełna macierz
 *    node scripts/audyt-przykrycia.mjs --szybko       # sam motyw jasny
 *  Wynik: storage/audyt-przykrycia.json + podsumowanie na konsoli.
 *  Kod wyjścia 1, gdy jakikolwiek element interaktywny jest nieosiągalny.
 */

import { chromium } from 'playwright';
import { ustalBazePomiarowa } from './bezpiecznik-bazy.mjs';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';

/* Osobna baza pomiarowa — ten skrypt robi `migrate:fresh` i zasiewa dane.
   Wskazanie `kuking` albo cudzej bazy odbiorowej kasowałoby czyjąś pracę
   (AGENTS.md §6). Port 5432 jest tu zakazany świadomie. */
const BAZA_DOMYSLNA = 'kuking_audyt_browser';

/* BEZPIECZNIK: ten skrypt robi `migrate:fresh`, czyli KASUJE zawartosc
   bazy. `ustalBazePomiarowa()` wpuszcza wylacznie jednorazowa baze pomiarowa
   i ODMAWIA startu przy nazwie, ktorej nie rozpoznaje — nie wiem, czyja to
   baza, wiec jej nie kasuje (scripts/bezpiecznik-bazy.mjs). Liczone RAZ, na
   starcie: odmowa ma paść, zanim skrypt cokolwiek zbuduje albo podniesie. */
const BAZA_POMIAROWA = ustalBazePomiarowa({
  domyslna: BAZA_DOMYSLNA,
  skrypt: 'scripts/audyt-przykrycia.mjs',
});
const PORT_BAZY_ZAKAZANY = '5432';

const SZYBKO = process.argv.includes('--szybko');

/* Szerokości z zlecenia audytu. 320 to najmniejszy powszechny telefon,
   390 to iPhone/Pixel, 768 tablet, 1440 typowy laptop. */
const SZEROKOSCI = [
  { nazwa: '320', szerokosc: 320, wysokosc: 740 },
  { nazwa: '390', szerokosc: 390, wysokosc: 844 },
  { nazwa: '768', szerokosc: 768, wysokosc: 1024 },
  { nazwa: '1440', szerokosc: 1440, wysokosc: 900 },
];

const MOTYWY = SZYBKO ? ['light'] : ['light', 'dark'];

/* Trzy warianty pisma. `100`/`140` to NASZE ustawienie z profilu
   (`data-text-scale`), `zoom200` to powiększenie strony: viewport CSS zmniejsza
   się o połowę przy `deviceScaleFactor: 2`. To są dwa różne mechanizmy
   i mylenie ich jest pułapką opisaną w docs/PULAPKI_TESTOW.md.

   GRANICA, KTÓREJ NIE WOLNO PRZEKROCZYĆ W INTERPRETACJI: zoom 200% przy oknie
   320 px daje 160 px CSS, a przy 390 px — 195 px. Obie wartości są PONIŻEJ
   320 px CSS, czyli poniżej podłogi, którą deklaruje docs/UX_50_PLUS.md
   („przy 200% powiększenia" i „przy szerokości 320 px" to tam DWA osobne
   warunki, nie jeden złożony). Mierzenie ich razem produkuje usterki spoza
   obiecanej obwiedni. Dlatego wariant `zoom200` biegnie wyłącznie tam, gdzie
   po zmniejszeniu zostaje co najmniej 320 px CSS — czyli od 640 px w górę. */
const WARIANTY_PISMA = SZYBKO ? ['100'] : ['100', '140', 'zoom200'];
const MIN_CSS_PO_ZOOMIE = 320;

/* Ścieżki z zlecenia audytu. Zeszyt, dodawanie i tryb gotowania stoją za
   `auth`, więc logujemy się prawdziwym formularzem — tak jak robi to
   scripts/dostepnosc.mjs, tym samym kontem demonstracyjnym.

   CZEGO TU NIE MA: panelu moderacji. Wejście wymaga konta moderatora ORAZ
   potwierdzonego 2FA (kod TOTP liczony po stronie serwisu). Panel ma własny
   pomiar axe i układu w scripts/dostepnosc.mjs; ten skrypt go nie dubluje.
   To jest granica narzędzia, nie wynik pozytywny dla panelu. */
const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

const SCIEZKI_GOSCIA = [
  { nazwa: 'strona-glowna', adres: '/' },
  { nazwa: 'swiezo', adres: '/odkryj' },
  { nazwa: 'logowanie', adres: '/login' },
  { nazwa: 'tagi', adres: '/tagi' },
  { nazwa: 'szukaj', adres: '/szukaj?sekcja=przepisy' },
];

/* `{przepis}` podstawia slug pierwszego publicznego przepisu z DemoSeeder. */
const SCIEZKI_KONTA = [
  { nazwa: 'zeszyt', adres: '/zeszyt' },
  { nazwa: 'dodaj-wpis', adres: '/dodaj/zdjecie' },
  { nazwa: 'dodaj-przepis', adres: '/dodaj/przepis' },
  { nazwa: 'przepis', adres: '/przepisy/{przepis}' },
  { nazwa: 'tryb-gotowania', adres: '/przepisy/{przepis}/gotuj' },
];

const wolnyPort = () => new Promise((resolve, reject) => {
  const gniazdo = createServer();
  gniazdo.on('error', reject);
  gniazdo.listen(0, '127.0.0.1', () => {
    const { port } = gniazdo.address();
    gniazdo.close(() => resolve(port));
  });
});

function sprawdzBaze() {
  const baza = BAZA_POMIAROWA;
  const port = process.env.DB_PORT || '';
  if (port === PORT_BAZY_ZAKAZANY) {
    throw new Error(`DB_PORT=${PORT_BAZY_ZAKAZANY} jest zakazany dla pomiarów — użyj izolowanej instancji (AGENTS.md §10).`);
  }
  if (!/^kuking_audyt_/.test(baza)) {
    throw new Error(`DB_DATABASE="${baza}" nie wygląda na bazę tego pomiaru. Ten skrypt robi migrate:fresh — wskaż bazę "kuking_audyt_*".`);
  }
  return baza;
}

async function podniesSerwer(baza) {
  const bledy = [];
  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = await wolnyPort();
    const adres = `http://127.0.0.1:${port}`;
    const dziennik = [];
    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, DB_DATABASE: baza },
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
    bledy.push(`  podejście ${podejscie}, port ${port}: ${umarl !== null ? `kod ${umarl}` : 'brak /health przez 30 s'}\n${dziennik.join('')}`);
  }
  throw new Error(`Nie udało się podnieść \`php artisan serve\`.\n${bledy.join('\n')}`);
}

/* Pomiar w przeglądarce. Zwraca listę elementów interaktywnych, których
   NIE DA SIĘ dosięgnąć wskaźnikiem, bo hit-test trafia w co innego. */
const POMIAR = () => {
  const WYBOR = 'a[href], button, input:not([type=hidden]), select, textarea, summary, [role="button"], [tabindex]:not([tabindex="-1"])';
  const plywajace = (el) => {
    for (let n = el; n && n !== document.documentElement; n = n.parentElement) {
      if (n.classList?.contains('szybki-wyglad') || n.classList?.contains('szybki-wyglad-podpowiedz')) return n;
    }
    return null;
  };
  const widget = document.querySelector('.szybki-wyglad');
  const podpowiedz = document.querySelector('.szybki-wyglad-podpowiedz:not([hidden])');
  const summary = widget?.querySelector('summary');

  const wynik = { nieosiagalne: [], sprawdzonych: 0, wPrzeplywie: widget?.hasAttribute('data-wyglad-w-przeplywie') ?? null };

  for (const el of document.querySelectorAll(WYBOR)) {
    if (plywajace(el)) continue;                 // sam widget nie jest ofiarą
    const prostokaty = [...el.getClientRects()].filter((r) => r.width > 2 && r.height > 2);
    if (!prostokaty.length) continue;
    const styl = getComputedStyle(el);
    if (styl.visibility === 'hidden' || styl.display === 'none' || styl.pointerEvents === 'none') continue;
    // Element poza oknem nie jest „przykryty" — jest po prostu niewidoczny.
    const w = prostokaty.filter((r) => r.bottom > 0 && r.top < innerHeight && r.right > 0 && r.left < innerWidth);
    if (!w.length) continue;
    wynik.sprawdzonych++;

    // Dziewięć punktów próbnych: środek plus ośmiu sąsiadów przy krawędziach.
    // Jeden punkt dawałby fałszywy alarm na elemencie zasłoniętym w rogu.
    const trafienia = [];
    for (const r of w) {
      const xs = [r.left + 2, r.left + r.width / 2, r.right - 2];
      const ys = [r.top + 2, r.top + r.height / 2, r.bottom - 2];
      for (const x of xs) {
        for (const y of ys) {
          if (x < 0 || y < 0 || x > innerWidth || y > innerHeight) continue;
          const pod = document.elementFromPoint(x, y);
          if (!pod) continue;
          trafienia.push(el.contains(pod) || pod === el ? 'swoj' : (plywajace(pod) ? 'plywajace' : 'inne'));
        }
      }
    }
    if (!trafienia.length) continue;
    if (trafienia.includes('swoj')) continue;     // gdziekolwiek da się trafić
    if (!trafienia.includes('plywajace')) continue; // zasłania coś innego — nie nasz zakres

    const r = w[0];
    const f = summary?.getBoundingClientRect();
    const p = podpowiedz?.getBoundingClientRect();
    const pole = (a, b) => (a && b)
      ? Math.max(0, Math.min(a.right, b.right) - Math.max(a.left, b.left)) * Math.max(0, Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top))
      : 0;
    wynik.nieosiagalne.push({
      tekst: (el.innerText || el.getAttribute('aria-label') || el.value || '').trim().slice(0, 60),
      znacznik: el.tagName.toLowerCase(),
      cel: el.getAttribute('href') || null,
      rect: { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height) },
      zPrzyciskiem: Math.round(pole(r, f)),
      zPodpowiedzia: Math.round(pole(r, p)),
      punktow: trafienia.length,
    });
  }
  return wynik;
};

async function main() {
  const baza = sprawdzBaze();
  console.log(`Baza pomiarowa: ${baza} na ${process.env.DB_HOST || '?'}:${process.env.DB_PORT || '?'}`);
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--force', '--no-interaction'], {
    stdio: 'ignore',
    env: { ...process.env, DB_DATABASE: baza },
  });

  const serwer = await podniesSerwer(baza);
  const przegladarka = await chromium.launch();

  // Prawdziwe logowanie formularzem, raz — `config/kuking.php` daje pięć prób
  // na minutę, a kontekstów jest kilkadziesiąt. Ciasteczko sesji działa
  // w każdym z nich tak samo.
  const wstepny = await przegladarka.newContext();
  const logowanie = await wstepny.newPage();
  await logowanie.goto(`${serwer.adres}/login`);
  await logowanie.fill('input[name="login"]', KONTO);
  await logowanie.fill('input[name="password"]', HASLO);
  await Promise.all([
    logowanie.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
    logowanie.click('button[type="submit"]'),
  ]);
  const sesja = await wstepny.storageState();
  // Slug pierwszego publicznego przepisu — bierzemy go ze strony, a nie
  // z założenia o zawartości seedera.
  let slugPrzepisu = null;
  for (const gdzie of ['/odkryj', '/', '/szukaj?sekcja=przepisy&q=e']) {
    await logowanie.goto(`${serwer.adres}${gdzie}`);
    slugPrzepisu = await logowanie.evaluate(() => {
      const a = [...document.querySelectorAll('a[href*="/przepisy/"]')]
        .map((e) => new URL(e.href).pathname.split('/'))
        .filter((cz) => cz[1] === 'przepisy' && cz[2] && !cz[3])
        .map((cz) => cz[2]);
      return a[0] || null;
    });
    if (slugPrzepisu) break;
  }
  await wstepny.close();
  if (!slugPrzepisu) console.log('  (uwaga) nie znaleziono publicznego przepisu — ścieżki przepisu i trybu gotowania pominięte');
  const wyniki = [];
  let nieosiagalnych = 0;

  try {
    for (const motyw of MOTYWY) {
      for (const pismo of WARIANTY_PISMA) {
        for (const s of SZEROKOSCI) {
          const zoom = pismo === 'zoom200';
          if (zoom && s.szerokosc / 2 < MIN_CSS_PO_ZOOMIE) continue;
          const widok = {
            colorScheme: motyw,
            viewport: { width: zoom ? Math.round(s.szerokosc / 2) : s.szerokosc, height: zoom ? Math.round(s.wysokosc / 2) : s.wysokosc },
            deviceScaleFactor: zoom ? 2 : 1,
          };
          const kontekst = await przegladarka.newContext(widok);
          const kontekstKonta = await przegladarka.newContext({ ...widok, storageState: sesja });
          const doSprawdzenia = [
            ...SCIEZKI_GOSCIA.map((x) => ({ ...x, konto: false })),
            ...SCIEZKI_KONTA
              .filter((x) => slugPrzepisu || !x.adres.includes('{przepis}'))
              .map((x) => ({ ...x, konto: true, adres: x.adres.replace('{przepis}', slugPrzepisu) })),
          ];
          for (const sciezka of doSprawdzenia) {
            const strona = await (sciezka.konto ? kontekstKonta : kontekst).newPage();
            await strona.goto(serwer.adres + sciezka.adres, { waitUntil: 'networkidle' });
            if (pismo === '140') await strona.evaluate(() => document.documentElement.setAttribute('data-text-scale', '140'));
            // Najgorszy przypadek z #684: dół dokumentu, gdzie pływający
            // przycisk siada na ostatnim wierszu treści.
            //
            // PRZEWIJAMY KÓŁKIEM, NIE `window.scrollTo`. To nie jest ozdoba
            // metody: `scrollTo` jest wywołaniem z kodu i nie niesie żadnej
            // informacji o tym, że przy ekranie siedzi człowiek. Mierzylibyśmy
            // wtedy stan, do którego nikt nie może dojść — bo żeby zobaczyć dół
            // strony, trzeba ją przewinąć, a każde ludzkie przewinięcie jest
            // gestem. Pomiar na `scrollTo` produkuje usterki, których nikt
            // nigdy nie zobaczy (sprawdzone: 50 „nieosiągalnych" spada do
            // garstki, gdy gest jest prawdziwy).
            for (let i = 0; i < 80; i++) {
              await strona.mouse.wheel(0, 900);
              if (await strona.evaluate(() => scrollY >= document.documentElement.scrollHeight - innerHeight - 2)) break;
            }
            await strona.waitForTimeout(350);

            const pomiar = await strona.evaluate(POMIAR);
            const overflow = await strona.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
            const konfiguracja = `${sciezka.nazwa}/${motyw}/${pismo}/${s.nazwa}`;
            nieosiagalnych += pomiar.nieosiagalne.length;
            wyniki.push({ konfiguracja, sciezka: sciezka.nazwa, konto: sciezka.konto, motyw, pismo, szerokosc: s.nazwa, overflowPoziomy: overflow, ...pomiar });
            if (pomiar.nieosiagalne.length) {
              console.log(`  ✗ ${konfiguracja}: ${pomiar.nieosiagalne.length} nieosiągalnych — ${pomiar.nieosiagalne.map((n) => n.tekst || n.znacznik).join(', ')}`);
            }
            if (overflow) console.log(`  ✗ ${konfiguracja}: poziome przewijanie strony`);
            await strona.close();
          }
          await kontekst.close();
          await kontekstKonta.close();
        }
      }
    }
  } finally {
    await przegladarka.close();
    serwer.zamknij();
  }

  const overflowow = wyniki.filter((w) => w.overflowPoziomy).length;
  mkdirSync('storage', { recursive: true });
  const raport = {
    data: new Date().toISOString(),
    konfiguracji: wyniki.length,
    nieosiagalnych,
    overflowow,
    szerokosci: SZEROKOSCI.map((s) => s.nazwa),
    motywy: MOTYWY,
    warianty_pisma: WARIANTY_PISMA,
    wyniki,
  };
  writeFileSync('storage/audyt-przykrycia.json', JSON.stringify(raport, null, 2));
  console.log(`\nKonfiguracji: ${wyniki.length}. Nieosiągalnych elementów: ${nieosiagalnych}. Poziomego przewijania: ${overflowow}.`);
  console.log('Pełny wynik: storage/audyt-przykrycia.json');

  if (nieosiagalnych > 0 || overflowow > 0) process.exit(1);
}

main().catch((blad) => { console.error(blad.message); process.exit(1); });
