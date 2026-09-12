/*
 * =============================================================================
 *  Kuking.pl — pomiar GŁÓWKI KARTY WPISU (pas B)
 * =============================================================================
 *
 *  CO TO MIERZY
 *  Górny rząd karty wpisu — awatar, kolumnę „nazwa autora + data" i przycisk
 *  menu „…" — w chwili, w której robi się za ciasno. Mierzone jest:
 *
 *    • szerokość kolumny z nazwą i datą (to ona przy 200% czcionki schodziła
 *      do 0 px i to jest liczba, o którą w tym pasie chodzi),
 *    • cel dotknięcia menu (szerokość × wysokość `summary`) — twarda granica
 *      48 × 48 px w KAŻDYM wariancie (docs/UX_50_PLUS.md),
 *    • liczba rzędów główki (1 = wszystko obok siebie, 2 = główka się zawinęła),
 *    • czy cokolwiek z główki wychodzi poza kartę,
 *    • czy strona ma przewijanie poziome.
 *
 *  DLACZEGO AŻ TYLE WARIANTÓW
 *  Usterka nie zależy od samej szerokości okna, tylko od STOSUNKU szerokości
 *  do rozmiaru pisma: przy czcionce przeglądarki 200% przycisk menu
 *  (`--control-height-min`, 3rem) rośnie do 96 px, wcięcia karty do 40 px
 *  z każdej strony, a awatar zostaje przy 52 px — i na kolumnę z nazwą nie
 *  zostaje nic. Dlatego każda szerokość idzie przy 100% i przy 200%, a do tego
 *  w trzech długościach nazwy autora: przy krótkiej („Ala") kolumna zwęża się
 *  inaczej niż przy nazwie na pełny limit, który `display_name` dopuszcza.
 *
 *  4 szerokości × 2 rozmiary pisma × 3 długości nazwy = 24 pomiary.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/glowka-karty-wpisu.mjs
 *      ADRES=http://127.0.0.1:8102 node scripts/glowka-karty-wpisu.mjs
 *
 *  Bez `ADRES` skrypt sam zakłada i sieje własną bazę, buduje arkusz,
 *  podnosi `php artisan serve` na wolnym porcie i sam go gasi.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa — ten skrypt robi `migrate:fresh`. Wskazanie `kuking`
   albo `kuking_test` kasowałoby czyjąś pracę (AGENTS.md §6). */
const BAZA_DOMYSLNA = 'kuking_glowka_karty_wpisu';

/* Domyślny rozmiar pisma przeglądarki; wariant 200% ustawia dwa razy tyle
   przez CDP `Page.setFontSizes` — tak samo jak `scripts/dostepnosc.mjs`
   i `scripts/glowka-profilu.mjs`. To jest EMULACJA CZCIONKI BAZOWEJ, a nie
   powiększenie strony: piksel CSS zostaje ten sam, rosną tylko `rem` i `em`.
   Dokładnie tak działa ustawienie „rozmiar czcionki" w przeglądarce. */
const BAZOWA_CZCIONKA_PX = 16;

const SZEROKOSCI = [
  { nazwa: '320 px', szerokosc: 320, wysokosc: 844 },
  { nazwa: '360 px', szerokosc: 360, wysokosc: 800 },
  { nazwa: '390 px', szerokosc: 390, wysokosc: 844 },
  { nazwa: '414 px', szerokosc: 414, wysokosc: 896 },

  /* SZEROKOŚCI POWYŻEJ PROGU 16em — dołożone przy issue #467.
     Pierwsza wersja tego skryptu kończyła się na 414 px, bo usterka z #440
     była telefonowa. To zostawiło w pomiarze DZIURĘ: przy czcionce
     przeglądarki 200% próg `@media (max-width: 16em)` to 512 px, więc
     wszystkie cztery szerokości wyżej są POD progiem i kolumna dostaje tam
     cały rząd. Stan, w którym reguła progowa NIE działa, a broni sama siatka
     bezpieczeństwa (`flex: 1 1 96px`), nie był mierzony ani razu.

     `dostepnosc.mjs` zgłosił stamtąd odnośnik daty o wysokości 783 px przy
     oknie wysokim na 740 px. Te trzy szerokości to te same, których używa
     tamten skrypt. Wysokość okna 740 px jest wspólna, żeby „wyższy niż okno"
     znaczyło w obu skryptach to samo. */
  { nazwa: '768 px', szerokosc: 768, wysokosc: 740 },
  { nazwa: '900 px', szerokosc: 900, wysokosc: 740 },
  { nazwa: '1280 px', szerokosc: 1280, wysokosc: 740 },
];

/* `display_name` ma dziś `max:100` — wariant „bardzo długa" wykorzystuje to
   do końca, bo to jest stan, który serwis naprawdę dopuszcza. */
const NAZWY = [
  ['krótka', 'Ala'],
  ['średnia', 'Katarzyna Wiśniewska'],
  ['bardzo długa', 'Aleksandra Katarzyna Przepiórkowska-Wielopolska od kuchni babci Zofii z Podkarpacia'],
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

/* Nazwę autora ustawiamy WSZYSTKIM profilom, a nie jednemu: pomiar czyta
   pierwszą kartę w strumieniu, a to, czyj wpis jest pierwszy, zależy od
   danych zalążkowych i od godziny. Tak każda karta jest tą kartą. */
function ustawNazwe(nazwa) {
  const php = `
    App\\Models\\Profile::query()->update(['display_name' => ${JSON.stringify(nazwa)}]);
    echo App\\Models\\Profile::query()->value('display_name');
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

  /* Baza pomiarowa bywa świeża po pierwszym uruchomieniu na nowej maszynie.
     Nieudane `createdb` (bo już jest) nie jest błędem. */
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

  const bledy = [];

  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = await wolnyPort();
    const adres = `http://127.0.0.1:${port}`;
    const dziennik = [];

    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
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
 * POMIAR. Wszystko z `getBoundingClientRect()` po ułożeniu strony — liczy się
 * to, co widzi człowiek, a nie deklaracja w arkuszu.
 *
 * KOLUMNĘ Z NAZWĄ ZNAJDUJEMY PRZEZ ODJĘCIE, a nie po klasie: to bezpośrednie
 * dziecko główki, które nie jest ani odnośnikiem awatara, ani menu. Dzięki
 * temu ten sam skrypt mierzy stan PRZED poprawką (`div.min-w-0`) i PO niej,
 * choćby klasa się zmieniła — inaczej pomiar „przed" i „po" dotyczyłby dwóch
 * różnych rzeczy.
 */
const POMIAR = () => {
  const zaokr = (x) => Math.round(x * 100) / 100;
  const karta = document.querySelector('article.post-card');

  if (! karta) return { blad: 'nie znalazłem `article.post-card`' };

  const glowka = karta.querySelector('.post-card-head');

  if (! glowka) return { blad: 'karta nie ma `.post-card-head`' };

  const awatar = glowka.querySelector(':scope > .post-card-awatar');
  const menu = glowka.querySelector(':scope > .post-card-menu');
  const przyciskMenu = menu?.querySelector(':scope > summary') ?? null;
  const kolumna = [...glowka.children].find((el) => el !== awatar && el !== menu) ?? null;
  const nazwa = glowka.querySelector('.author-name');

  /* ODNOŚNIK DATY (issue #467). Szukamy przez `<time>`, nie po klasie
     `link-jak-tekst`: klasa jest ozdobą i może się zmienić, a data jest
     w karcie zawsze i zawsze jest w `<time>`. Bierzemy jego przodka `<a>`,
     bo to ON jest kontrolką, którą przeglądarka przewija w widok po Tab —
     i to jego wysokość zgłosił `dostepnosc.mjs`. */
  const czas = kolumna?.querySelector('time') ?? null;
  const odnosnikDaty = czas?.closest('a') ?? czas;

  const prostokat = (el) => {
    if (! el) return null;
    const p = el.getBoundingClientRect();

    return {
      szerokosc: zaokr(p.width),
      wysokosc: zaokr(p.height),
      lewo: zaokr(p.left),
      prawo: zaokr(p.right),
      gora: zaokr(p.top + window.scrollY),
    };
  };

  const kartaP = karta.getBoundingClientRect();

  /* Ile rzędów ma główka. NIE po górnej krawędzi: przy `align-items: center`
     awatar, kolumna i menu mają różne wysokości, więc różne `top` — a stoją
     w jednym rzędzie. Rzędem jest grupa dzieci, których zakresy pionowe
     ZACHODZĄ NA SIEBIE; dwa rzędy flexa nigdy na siebie nie zachodzą. */
  const pudelka = [...glowka.children]
    .map((el) => el.getBoundingClientRect())
    .filter((p) => p.height > 0)
    .sort((a, b) => a.top - b.top);
  const rzedy = [];

  for (const p of pudelka) {
    const ostatni = rzedy.at(-1);

    if (ostatni && p.top < ostatni.dol - 1) ostatni.dol = Math.max(ostatni.dol, p.bottom);
    else rzedy.push({ gora: p.top, dol: p.bottom });
  }

  /* Wyjazd poza kartę liczymy na WSZYSTKICH potomkach główki, nie na samej
     główce: to pojedynczy przycisk albo długie słowo wychodzi poza krawędź,
     a pudełko rodzica zostaje na miejscu. */
  /* ZWINIĘTE MENU NIE LICZY SIĘ DO WYJAZDU — i to jest pułapka, na którą ten
     skrypt się nadział przy pierwszym przebiegu. Rozwijana lista
     (`.post-card-menu-tresc`, `position: absolute; right: 0; min-width: 14rem`)
     ma w zamkniętym `<details>` prawdziwe pudełko, tylko niewidoczne — przy
     200% czcionki to 448 px sterczące 211 px w LEWO poza kartę. Liczba była
     prawdziwa i nic nie znaczyła: człowiek tego nie widzi, dopóki nie otworzy
     menu. Bierzemy więc tylko to, co narysowane: sam `summary` i wszystko
     poza zwiniętym `<details>`. */
  const widoczny = (el) => {
    const zwiniete = el.closest('details:not([open])');

    return ! zwiniete || el.closest('summary') !== null;
  };

  const potomkowie = [...glowka.querySelectorAll('*')]
    .filter((el) => el.getBoundingClientRect().width > 0)
    .filter(widoczny);
  const najdalszy = potomkowie
    .slice()
    .sort((a, b) => b.getBoundingClientRect().right - a.getBoundingClientRect().right)
    .at(0);

  return {
    karta: { szerokosc: zaokr(kartaP.width), lewo: zaokr(kartaP.left), prawo: zaokr(kartaP.right) },
    glowka: prostokat(glowka),
    kolumna: prostokat(kolumna),
    menu: prostokat(przyciskMenu),
    awatar: prostokat(awatar),
    nazwa: prostokat(nazwa),
    odnosnikDaty: prostokat(odnosnikDaty),
    tekstDaty: (czas?.textContent ?? '').trim(),
    wysokoscOkna: window.innerHeight,
    rzedyGlowki: rzedy.length,

    /* KTO wystaje, nie tylko o ile — bez tego wiadomo, że główka się rozpada,
       ale nie wiadomo, co naprawiać. */
    najdalszy: najdalszy
      ? {
        co: `${najdalszy.tagName.toLowerCase()}.${[...najdalszy.classList].join('.') || '(bez klasy)'}`,
        tresc: (najdalszy.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 40),
        prawo: zaokr(najdalszy.getBoundingClientRect().right),
      }
      : null,

    wystajeWPrawo: zaokr(Math.max(0, Math.max(...potomkowie.map((el) => el.getBoundingClientRect().right)) - kartaP.right)),
    wystajeWLewo: zaokr(Math.max(0, kartaP.left - Math.min(...potomkowie.map((el) => el.getBoundingClientRect().left)))),

    szerokoscOkna: window.innerWidth,
    szerokoscDokumentu: document.documentElement.scrollWidth,
    pismoKorzenia: zaokr(Number.parseFloat(getComputedStyle(document.documentElement).fontSize)),
    pismoNazwy: nazwa ? zaokr(Number.parseFloat(getComputedStyle(nazwa).fontSize)) : null,
  };
};

const CHROMIUM = znajdzChromium();
const { adres, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/*
 * LOGUJEMY SIĘ RAZ, A POTEM PRZENOSIMY CIASTECZKO PRZEZ `storageState`.
 * Ten pomiar ma 24 warianty; logowanie w każdym z nich to 24 próby pod rząd
 * z jednego adresu, a serwis ma na to `throttle` i po kilku odpowiada ekranem
 * „Za dużo prób". Pomiar leciałby wtedy na ekranie blokady zamiast na feedzie.
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
  for (const [etykietaNazwy, nazwa] of NAZWY) {
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

        const odp = await strona.goto(`${adres}/home`, { waitUntil: 'domcontentloaded' });

        if ((odp?.status() ?? 0) !== 200) {
          console.error(`BŁĄD: /home odpowiedziało kodem ${odp?.status()}.`);
          bylBlad = true;
          await kontekst.close();
          continue;
        }

        await strona.waitForSelector('article.post-card');
        await strona.evaluate(() => document.fonts?.ready);

        const wynik = await strona.evaluate(POMIAR);

        if (wynik.blad) {
          console.error(`BŁĄD: ${wynik.blad}`);
          bylBlad = true;
          await kontekst.close();
          continue;
        }

        /* Wariant 200%, który nie zadziałał, daje liczby wyglądające na dobre
           — i to jest najgorszy możliwy wynik pomiaru (pułapka 5). */
        if (czcionka === 200 && wynik.pismoKorzenia < 2 * BAZOWA_CZCIONKA_PX) {
          console.error(`BŁĄD: czcionka korzenia to ${wynik.pismoKorzenia} px zamiast `
            + `${2 * BAZOWA_CZCIONKA_PX} px — wariant 200% nie zadziałał, jego liczby nic nie znaczą.`);
          bylBlad = true;
        }

        wiersze.push({ etykietaNazwy, widok: widok.nazwa, czcionka: czcionka ? '200%' : '100%', ...wynik });

        await kontekst.close();
      }
    }
  }
} finally {
  await przegladarka.close();
  zamknij();
}

const kol = (x, n) => String(x).padStart(n);

console.log('\nnazwa autora   okno      czc.  karta   kolumna nazwy   wys. główki   cel menu   rzędy  data (szer.×wys.)  wys. nazwy  poza kartą  przewijanie');
console.log('-'.repeat(140));

for (const w of wiersze) {
  const poza = (w.wystajeWPrawo > 0.5 || w.wystajeWLewo > 0.5)
    ? `TAK (${Math.max(w.wystajeWPrawo, w.wystajeWLewo)} px)`
    : 'nie';
  const przewijanie = w.szerokoscDokumentu > w.szerokoscOkna
    ? `TAK (${w.szerokoscDokumentu} > ${w.szerokoscOkna})`
    : 'nie';

  console.log(
    w.etykietaNazwy.padEnd(15)
    + w.widok.padEnd(10)
    + w.czcionka.padEnd(6)
    + kol(w.karta.szerokosc, 6) + '  '
    + kol(w.kolumna?.szerokosc ?? '—', 12) + ' px  '
    + kol(w.glowka?.wysokosc ?? '—', 10) + ' px  '
    + kol(`${w.menu?.szerokosc ?? '—'}×${w.menu?.wysokosc ?? '—'}`, 8) + '  '
    + kol(w.rzedyGlowki, 5) + '  '
    + kol(`${w.odnosnikDaty?.szerokosc ?? '—'}×${w.odnosnikDaty?.wysokosc ?? '—'}`, 17) + '  '
    + kol(`${w.nazwa?.wysokosc ?? '—'}`, 9) + ' px  '
    + poza.padEnd(12)
    + przewijanie,
  );
}

/* --- BRAMKI POMIARU ------------------------------------------------------
   Tabela sama w sobie niczego nie przesądza; te cztery warunki przesądzają. */
const zerowe = wiersze.filter((w) => (w.kolumna?.szerokosc ?? 0) < 1);
const zaMaleCele = wiersze.filter((w) => (w.menu?.szerokosc ?? 0) < 48 || (w.menu?.wysokosc ?? 0) < 48);
const wyjazdy = wiersze.filter((w) => w.wystajeWPrawo > 0.5 || w.wystajeWLewo > 0.5 || w.szerokoscDokumentu > w.szerokoscOkna);

/* CZWARTA BRAMKA (issue #467): kontrolka wyższa niż okno.
   Przeglądarka po Tab przewija kontrolkę w widok — ale kontrolki, która się
   w oknie NIE MIEŚCI, nie da się pokazać całej żadnym przewijaniem. Żadna
   rezerwa (D-184) tego nie naprawi. Mierzymy odnośnik daty, bo to on wypadł
   w pomiarze dostępności; próg jest bezwzględny, nie procentowy: albo się
   mieści, albo nie. */
const wyzszeNizOkno = wiersze.filter((w) => (w.odnosnikDaty?.wysokosc ?? 0) > w.wysokoscOkna);

const wystajace = wiersze.filter((w) => w.wystajeWPrawo > 0.5);

if (wystajace.length > 0) {
  console.log('\nCO WYCHODZI POZA KARTĘ (element o najdalszej prawej krawędzi):');
  for (const w of wystajace) {
    console.log(`  ${w.etykietaNazwy.padEnd(15)}${w.widok.padEnd(9)}${w.czcionka.padEnd(6)}`
      + `${String(w.wystajeWPrawo).padStart(7)} px   ${w.najdalszy?.co ?? '—'}  „${w.najdalszy?.tresc ?? ''}"`);
  }
}

console.log('');
console.log(`Kolumna z nazwą i datą węższa niż 1 px:  ${zerowe.length} z ${wiersze.length}`);
console.log(`Cel dotknięcia menu poniżej 48 × 48 px: ${zaMaleCele.length} z ${wiersze.length}`);
console.log(`Wyjazd poza kartę albo przewijanie w bok: ${wyjazdy.length} z ${wiersze.length}`);
console.log(`Odnośnik daty wyższy niż okno:          ${wyzszeNizOkno.length} z ${wiersze.length}`);

if (wyzszeNizOkno.length > 0) {
  console.log('\nODNOŚNIK DATY WYŻSZY NIŻ OKNO (po Tab nie da się go pokazać całego):');
  for (const w of wyzszeNizOkno) {
    console.log(`  ${w.etykietaNazwy.padEnd(15)}${w.widok.padEnd(10)}${w.czcionka.padEnd(6)}`
      + `${String(w.odnosnikDaty.wysokosc).padStart(8)} px  przy oknie ${w.wysokoscOkna} px`
      + `   (szerokość odnośnika ${w.odnosnikDaty.szerokosc} px, data „${w.tekstDaty}")`);
  }
}

if (wiersze.length !== SZEROKOSCI.length * NAZWY.length * 2) {
  console.error(`\nBŁĄD: zebrano ${wiersze.length} pomiarów zamiast ${SZEROKOSCI.length * NAZWY.length * 2}.`);
  bylBlad = true;
}

if (bylBlad) process.exit(1);
