/*
 * =============================================================================
 *  Kuking.pl — czy widget „Wygląd" nie odbiera dostępu do treści (issue #684)
 * =============================================================================
 *
 *  CO TO MIERZY
 *  Pływający przycisk „Wygląd" i stała podpowiedź „Dopasuj rozmiar tekstu"
 *  leżą NAD stroną. Mierzone jest jedno pytanie, zadane tak, jak zadaje je
 *  przeglądarka przy kliknięciu i przy dotknięciu:
 *
 *      czy do każdego odnośnika i każdego przycisku na ekranie da się
 *      trafić wskaźnikiem — czy któryś jest przykryty W CAŁOŚCI.
 *
 *  Pytamy `document.elementFromPoint` w dziewięciu punktach rozłożonych po
 *  prostokącie kontrolki (rogi, środki boków, środek), z niewielkim wcięciem
 *  od krawędzi, żeby nie trafiać w sąsiada przez zaokrąglenie do piksela.
 *  Kontrolka jest OSIĄGALNA, gdy choć jeden z tych punktów wraca nią samą
 *  albo czymś, co w niej leży.
 *
 *  DLACZEGO BRAMKĄ JEST OSIĄGALNOŚĆ, A NIE „ZERO NACHODZENIA"
 *  Bo zero nachodzenia jest nieosiągalne z definicji: przycisk jest
 *  `position: fixed`, więc podczas przewijania przechodzi nad każdym
 *  fragmentem strony po kolei. To jest zamierzone i nie jest usterką.
 *  Usterką jest dopiero stan, w którym człowiek NIE MA JAK dosięgnąć treści —
 *  i tylko to ta bramka sprawdza.
 *
 *  SKĄD SIĘ WZIĘŁO (issue #684)
 *  Odsłanianie przykrytej treści siedziało wyłącznie w uchwycie `focusin`,
 *  czyli działało dla klawiatury. Mysz i dotyk nie dostawały nic: na stronie
 *  tagu przy 320 px i tekście 140% przycisk „Wygląd" przykrywał piąty kafel
 *  kolażu w całości — dziewięć na dziewięć punktów próbnych trafiało
 *  w przycisk. Ten skrypt mierzy dokładnie to, co tam zmierzono ręcznie.
 *
 *  DLACZEGO TYLE WARIANTÓW
 *  Usterka zależy od stosunku szerokości okna do rozmiaru pisma: przy tekście
 *  140% przycisk rośnie, a okno nie. Do tego podpowiedź (szeroka na
 *  `min(360px, 100vw − 24px)`) pokazuje się TYLKO przy pierwszej wizycie —
 *  czyli w stanie, w którym przykrywa najwięcej, a testujący ją zwykle już
 *  odklikał. Dlatego każdy wariant idzie w dwóch stanach podpowiedzi.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/wyglad-nie-zaslania.mjs
 *      ADRES=http://127.0.0.1:8102 node scripts/wyglad-nie-zaslania.mjs
 *
 *  Bez `ADRES` skrypt sam zakłada i sieje WŁASNĄ bazę, buduje arkusz,
 *  podnosi `php artisan serve` na wolnym porcie i sam go gasi.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';

/* Osobna baza pomiarowa — ten skrypt robi `migrate:fresh`. Wskazanie `kuking`
   albo `kuking_test` kasowałoby czyjąś pracę (AGENTS.md §6). */
const BAZA_DOMYSLNA = 'kuking_wyglad_pomiar';

const BAZOWA_CZCIONKA_PX = 16;
const PRZEGLADARKA_200 = 'przegladarka-200';

/* Szerokości i pisma z opisu usterki: 320 px przy tekście 140% to wariant,
   w którym przycisk przykrył kafel w całości; 360 i 390 px przy 100% to
   warianty, w których przykryła go PODPOWIEDŹ. */
const WARIANTY = [
  { szerokosc: 320, wysokosc: 740, pismo: 140 },
  { szerokosc: 320, wysokosc: 740, pismo: PRZEGLADARKA_200 },
  { szerokosc: 360, wysokosc: 740, pismo: null },
  { szerokosc: 390, wysokosc: 740, pismo: null },
  { szerokosc: 414, wysokosc: 740, pismo: 140 },
];

/* Strony dobrane pod kątem GĘSTOŚCI kontrolek przy dolnej krawędzi — tam,
   gdzie pływający przycisk leży. Strona tagu ma kolaż, którego kafle bywają
   jedynym wejściem do wpisu, i to o nią poszło w zgłoszeniu. */
const STRONY = ['/', '/tagi', '/tag/zupy', '/szukaj'];

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

/* Ogon dziennika aplikacji — ten sam powód co w `scripts/port-projektu.mjs`:
   przy `APP_DEBUG=false` strona błędu 500 nie niesie nazwy wyjątku, a plik
   znika z runnera przy następnym `actions/checkout`. */
function ogonDziennika(ileRamek = 5) {
  const sciezka = 'storage/logs/laravel.log';

  try {
    if (!existsSync(sciezka)) return 'dziennik aplikacji nie powstał';

    const wiersze = readFileSync(sciezka, 'utf8').split('\n').filter((w) => w.trim() !== '');

    if (wiersze.length === 0) return 'dziennik aplikacji jest pusty';

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

    /* `--no-reload` — patrz `scripts/port-projektu.mjs`: bez niego `artisan serve`
       wycina procesowi `php -S` zmienne środowiska joba i aplikacja spada na
       `.env`, czyli na współdzielony port 5432. */
    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...env(), APP_URL: adres },
    });

    proces.stdout.on('data', (b) => dziennik.push(String(b)));
    proces.stderr.on('data', (b) => dziennik.push(String(b)));

    let umarl = null;
    proces.on('exit', (kod) => { umarl = kod; });

    let wstal = false;
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

/*
 * POMIAR. Cały poniższy blok chodzi W PRZEGLĄDARCE, więc nie widzi niczego
 * z tego pliku — stąd powtórzony w środku opis punktów próbnych.
 */
function zmierzOsiagalnosc() {
  const widget = document.querySelector('[data-szybki-wyglad]');
  const podpowiedz = document.querySelector('[data-wyglad-podpowiedz]');

  if (!widget) return { blad: 'na tej stronie nie ma widgetu „Wygląd”' };

  const przykrywajace = [];
  const summary = widget.querySelector('summary');

  /* Bierzemy prostokąty rzeczy LEŻĄCYCH NAD stroną. Widget w przepływie
     (`data-wyglad-w-przeplywie`) już nad nią nie leży — zajmuje własne
     miejsce w dokumencie i wtedy nie ma czego mierzyć. */
  const wPrzeplywie = widget.hasAttribute('data-wyglad-w-przeplywie');

  if (!wPrzeplywie && summary) przykrywajace.push({ nazwa: 'przycisk Wygląd', rect: summary.getBoundingClientRect(), wezel: widget });
  if (podpowiedz && !podpowiedz.hidden && getComputedStyle(podpowiedz).position === 'fixed') {
    przykrywajace.push({ nazwa: 'podpowiedź', rect: podpowiedz.getBoundingClientRect(), wezel: podpowiedz });
  }

  const kontrolki = [...document.querySelectorAll('a[href], button, summary, select, input:not([type="hidden"]), textarea, [role="button"]')]
    .filter((el) => !widget.contains(el) && !(podpowiedz && podpowiedz.contains(el)));

  const nieosiagalne = [];
  let zbadanych = 0;

  for (const el of kontrolki) {
    const r = el.getBoundingClientRect();

    if (r.width <= 0 || r.height <= 0) continue;
    if (r.bottom <= 0 || r.top >= innerHeight || r.right <= 0 || r.left >= innerWidth) continue;

    const kolidujace = przykrywajace.filter((p) => r.right > p.rect.left && r.left < p.rect.right
      && r.bottom > p.rect.top && r.top < p.rect.bottom);

    if (kolidujace.length === 0) continue;

    zbadanych += 1;

    /* Dziewięć punktów: rogi, środki boków i środek. Wcięcie 2 px od
       krawędzi, żeby zaokrąglenie do piksela nie wysłało próbki do sąsiada. */
    const wciecie = 2;
    const xs = [r.left + wciecie, r.left + r.width / 2, r.right - wciecie];
    const ys = [r.top + wciecie, r.top + r.height / 2, r.bottom - wciecie];
    const punkty = [];

    for (const y of ys) {
      for (const x of xs) {
        if (x < 0 || y < 0 || x >= innerWidth || y >= innerHeight) { punkty.push(null); continue; }
        punkty.push(document.elementFromPoint(x, y));
      }
    }

    const trafione = punkty.filter((p) => p !== null && (p === el || el.contains(p) || p.contains(el))).length;

    if (trafione > 0) continue;

    const winowajcy = [...new Set(punkty
      .filter((p) => p !== null)
      .map((p) => przykrywajace.find((q) => q.wezel.contains(p))?.nazwa)
      .filter(Boolean))];

    nieosiagalne.push({
      kontrolka: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''),
      tekst: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60),
      rozmiar: `${Math.round(r.width)}×${Math.round(r.height)} px`,
      punktowTrafionych: trafione,
      zaslania: winowajcy.length > 0 ? winowajcy.join(' i ') : 'coś spoza widgetu',
    });
  }

  return {
    wPrzeplywie,
    przykrywajacych: przykrywajace.length,
    kolidujacych: zbadanych,
    nieosiagalne,
  };
}

const CHROMIUM = znajdzChromium();
const { adres, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });
const naruszenia = [];
const pomiary = [];

for (const wariant of WARIANTY) {
  for (const podpowiedzWidoczna of [true, false]) {
    for (const strona of STRONY) {
      const kontekst = await przegladarka.newContext({
        viewport: { width: wariant.szerokosc, height: wariant.wysokosc },
        hasTouch: true,
      });

      /* Podpowiedź chowa się na zawsze po „Rozumiem" — zapisuje to
         `localStorage`. Wariant „już odklikana" ustawiamy tym samym kluczem,
         a nie klikaniem, żeby pomiar nie zależał od tego, czy przycisk
         akurat da się kliknąć (a to jest przecież mierzona rzecz). */
      if (!podpowiedzWidoczna) {
        await kontekst.addInitScript(() => {
          try { localStorage.setItem('kuking-wyglad-poznany', '1'); } catch { /* prywatne okno */ }
        });
      }

      const strona_ = await kontekst.newPage();

      if (wariant.pismo === PRZEGLADARKA_200) {
        const cdp = await kontekst.newCDPSession(strona_);
        await cdp.send('Page.setFontSizes', { fontSizes: { standard: BAZOWA_CZCIONKA_PX * 2, fixed: BAZOWA_CZCIONKA_PX * 2 } });
      }

      await strona_.goto(adres + strona, { waitUntil: 'networkidle' });

      if (typeof wariant.pismo === 'number') {
        await strona_.evaluate((skala) => { document.documentElement.dataset.textScale = String(skala); }, wariant.pismo);
      }

      /* PRZEMIATAMY CAŁĄ STRONĘ, nie sam jej dół. Na samym dole pływający
         przycisk siada na rezerwie, którą arkusz dokłada pod stopką
         (`body:has(.szybki-wyglad) { padding-bottom: … }`) — czyli akurat
         tam NIE zasłania prawie nigdy. Zasłania w ŚRODKU przewijania, i tak
         właśnie wyglądało zgłoszenie #684: kafel kolażu przy dolnej krawędzi
         okna. Pomiar tylko na dole przepuściłby dokładnie ten przypadek. */
      const etykietaPisma = wariant.pismo === null ? '100%' : (wariant.pismo === PRZEGLADARKA_200 ? 'przeglądarka 200%' : wariant.pismo + '%');
      const bazowa = `${strona} · ${wariant.szerokosc} px · pismo ${etykietaPisma} · podpowiedź ${podpowiedzWidoczna ? 'widoczna' : 'odklikana'}`;
      const zasieg = await strona_.evaluate(() => Math.max(0, document.documentElement.scrollHeight - innerHeight));
      const ulamki = [0, 0.25, 0.5, 0.75, 1];

      for (const ulamek of ulamki) {
        await strona_.evaluate((y) => window.scrollTo(0, y), Math.round(zasieg * ulamek));
        await strona_.waitForTimeout(250);

        const wynik = await strona_.evaluate(zmierzOsiagalnosc);
        const etykieta = `${bazowa} · przewinięcie ${Math.round(ulamek * 100)}%`;

        pomiary.push({ wariant: etykieta, ...wynik });

        if (wynik.blad) {
          console.log(`  ${etykieta}: ${wynik.blad}`);
        } else if (wynik.nieosiagalne.length > 0) {
          naruszenia.push({ wariant: etykieta, nieosiagalne: wynik.nieosiagalne });
          console.log(`  ✗ ${etykieta}: ${wynik.nieosiagalne.length} kontrolek nie do trafienia`);
          for (const n of wynik.nieosiagalne) {
            console.log(`      ${n.kontrolka} „${n.tekst}” ${n.rozmiar} — zasłania: ${n.zaslania}`);
          }
        }

        if (zasieg === 0) break;
      }

      await kontekst.close();
    }
  }
}

await przegladarka.close();
zamknij();

console.log('');

if (naruszenia.length > 0) {
  console.log(`WIDGET ZASŁANIA: ${naruszenia.length} wariantów ma kontrolkę, w którą nie da się trafić wskaźnikiem.`);
  process.exit(1);
}

/* Ile razy widget ZSZEDŁ DO PRZEPŁYWU. Ta liczba jest tu po to, żeby
   naprawa nie mogła „wygrać" najprostszym sposobem: gdyby widget przestał
   pływać zawsze, wszystkie pomiary byłyby zielone, a funkcja — wyłączona.
   Liczba blisko zera znaczy „pływa i nie zasłania", liczba równa liczbie
   pomiarów znaczy „nie pływa wcale" i jest tak samo zła jak naruszenia. */
const wPrzeplywie = pomiary.filter((p) => p.wPrzeplywie).length;

console.log(`Widget „Wygląd”: w ${pomiary.length} pomiarach każda kontrolka pod pływającą warstwą pozostaje osiągalna wskaźnikiem — OK.`);
console.log(`Zszedł do przepływu w ${wPrzeplywie} z ${pomiary.length} pomiarów (${Math.round(wPrzeplywie / pomiary.length * 100)}%); w pozostałych pływa jak dotąd.`);

if (wPrzeplywie === pomiary.length) {
  console.log('WIDGET NIE PŁYWA NIGDZIE: zielony wynik wziął się z wyłączenia funkcji, nie z naprawy.');
  process.exit(1);
}
