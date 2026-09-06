/*
 * =============================================================================
 *  Kuking.pl — automat dostępności (issue #26) i układu (issue #80)
 * =============================================================================
 *
 *  DWA RÓŻNE POMIARY W JEDNYM SKRYPCIE
 *  1. axe-core — analiza drzewa dokumentu: etykiety, nazwy dostępne, kontrast.
 *  2. pomiar układu — czy strona przewija się w bok przy 320/360/414/768 px.
 *
 *  Drugi punkt istnieje, bo pierwszy nie mógł go złapać. Belka górna
 *  wychodziła poza ekran telefonu na KAŻDEJ stronie serwisu (`scrollWidth`
 *  493 px przy oknie 360 px), a axe świecił na zielono: reflow nie jest
 *  regułą axe, bo wymaga ZMIERZENIA ułożonej strony, a nie sprawdzenia
 *  drzewa elementów. To jest dokładnie ta klasa błędu, o której mówi #26 —
 *  automat łapie 30%, reszta wymaga spojrzenia albo innego pomiaru.
 *
 *  CO TEN SKRYPT ŁAPIE, A CZEGO NIE
 *  Automaty wykrywają około 30% problemów z dostępnością. To jednak dokładnie
 *  te błędy, które najłatwiej wprowadzić przypadkiem: pole bez etykiety,
 *  przycisk bez nazwy dostępnej, kontrast zepsuty jedną „drobną poprawką"
 *  koloru. Reszty — czy tekst przycisku ZNACZY to, co przycisk robi, czy
 *  kolejność Taba ma sens — nie sprawdzi żadna maszyna. Lista ręczna:
 *  docs/design/A11Y_CHECKLIST.md.
 *
 *  DLACZEGO CZTERY WARIANTY KAŻDEGO EKRANU
 *  Kontrast liczy się osobno dla motywu jasnego i ciemnego; przy skali tekstu
 *  140% i szerokości 320 px wychodzą nakładające się elementy i ucięte
 *  przyciski. Sprawdzanie samego „normalnego" widoku przepuszczałoby dokładnie
 *  te usterki, które dotykają naszej grupy najczęściej — bo to ona włącza
 *  większy tekst.
 *
 *  URUCHOMIENIE
 *      node scripts/dostepnosc.mjs                    # wszystko
 *      node scripts/dostepnosc.mjs --szybko           # wariant jasny, węższy pomiar układu
 *      ADRES=http://127.0.0.1:8123 node scripts/...   # gotowy serwer
 *
 *  Bez zmiennej ADRES skrypt sam podnosi `php artisan serve` na wolnym porcie
 *  i sam go gasi. Dane bierze z DemoSeedera — bez nich strona przepisu
 *  i profilu nie mają czego pokazać, a pusty ekran przechodzi każdy test
 *  dostępności, nie sprawdzając niczego.
 *
 *  Wynik idzie do pliku (storage/dostepnosc.json), nie tylko na konsolę:
 *  przy 44 przebiegach lista naruszeń nie mieści się w oknie terminala.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import { spawn, execFileSync } from 'node:child_process';
import { writeFileSync, mkdirSync, existsSync } from 'node:fs';

const SZYBKO = process.argv.includes('--szybko');

/*
 * Skąd wziąć Chromium — dwa różne światy, jedna reguła.
 *
 * Obraz deweloperski ma gotowe Chromium pod stałą ścieżką i NIE MA tej
 * wersji, której szuka paczka `playwright`. Runner GitHuba jest odwrotnie:
 * pobiera własną przeglądarkę przez `playwright install`, a tamtej ścieżki
 * nie zna w ogóle.
 *
 * Pierwsza wersja robiła `process.env.CHROMIUM_PATH || '/opt/...'` —
 * czyli przy NIEUSTAWIONEJ zmiennej i tak wskazywała ścieżkę deweloperską.
 * Na CI dawało to natychmiastowe „executable doesn't exist", mimo że
 * Playwright miał swoją przeglądarkę gotową. Komentarz w workflow opisywał
 * zachowanie, którego kod nie miał.
 *
 * Teraz: bierzemy stałą ścieżkę TYLKO wtedy, gdy plik pod nią istnieje.
 * `undefined` znaczy dla Playwrighta „użyj swojej".
 */
function znajdzChromium() {
  const wskazana = process.env.CHROMIUM_PATH;

  if (wskazana) {
    if (! existsSync(wskazana)) {
      console.error(`BŁĄD: CHROMIUM_PATH wskazuje na ${wskazana}, a tam nic nie ma.`);
      process.exit(1);
    }

    return wskazana;
  }

  const deweloperska = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

  return existsSync(deweloperska) ? deweloperska : undefined;
}

const CHROMIUM = znajdzChromium();

/*
 * Ekrany z issue #26. Te wymagające logowania są oznaczone — Playwright
 * przechodzi przez prawdziwy formularz logowania, a nie podstawia ciasteczka.
 * Formularz logowania jest jednym z badanych ekranów, więc i tak musi działać.
 */
const EKRANY = [
  { nazwa: 'strona powitalna', adres: '/' },
  { nazwa: 'Świeżo z Kuking', adres: '/odkryj' },
  { nazwa: 'logowanie', adres: '/login' },
  { nazwa: 'rejestracja', adres: '/register' },
  { nazwa: 'przepis', adres: null, znajdz: 'przepis' },
  /*
   * Trzy sposoby wyświetlania zdjęć we wpisie (issue #92).
   *
   * DLACZEGO TRZY OSOBNE EKRANY, A NIE JEDEN „WPIS"
   * To są trzy różne układy, nie trzy warianty jednego. Karuzela jest jedynym
   * miejscem w serwisie, gdzie coś przewija się w POZIOMIE — a właśnie
   * przewijanie w poziomie mierzy ta druga połowa skryptu (issue #80).
   * Kolaż jest jedynym miejscem z siatką dwóch kolumn zdjęć przy 320 px.
   * Sprawdzanie tylko trybu domyślnego przepuszczałoby dokładnie te dwa
   * układy, które w tym issue są ryzykiem.
   */
  { nazwa: 'wpis — zdjęcia zwykle', adres: null, znajdz: 'wpis:normal' },
  { nazwa: 'wpis — karuzela', adres: null, znajdz: 'wpis:carousel' },
  { nazwa: 'wpis — kolaż', adres: null, znajdz: 'wpis:collage' },
  { nazwa: 'profil', adres: '/@basia' },
  { nazwa: 'tablica', adres: '/home', zalogowany: true },
  { nazwa: 'dodaj zdjęcie', adres: '/dodaj/zdjecie', zalogowany: true },
  { nazwa: 'dodaj przepis', adres: '/dodaj/przepis', zalogowany: true },
  { nazwa: 'czytelność', adres: '/ustawienia/czytelnosc', zalogowany: true },
  { nazwa: 'szukaj', adres: '/szukaj?q=rosol', zalogowany: true },
];

/*
 * Ekrany dla pomiaru układu (issue #80). To EKRANY plus dwa widoki wymienione
 * w kryteriach akceptacji tamtego issue, których lista axe nie obejmowała.
 * Osobna lista, a nie rozszerzone EKRANY: pomiar szerokości jest tani
 * (kilkadziesiąt milisekund), a przebieg axe kosztuje sekundę na ekran.
 */
const EKRANY_UKLADU = [
  ...EKRANY,
  { nazwa: 'zeszyt', adres: '/zeszyt', zalogowany: true },
  { nazwa: 'powiadomienia', adres: '/powiadomienia', zalogowany: true },
  // Ekran autora: kolejność zdjęć i wybór wyglądu (issue #92). Miniatura,
  // dwa przyciski „w górę / w dół" i trzy kafelki wyboru w jednym wierszu —
  // to jest układ, który przy 320 px i tekście 150% ma najwięcej okazji,
  // żeby wypchnąć stronę w bok.
  { nazwa: 'kolejność i wygląd zdjęć', adres: null, znajdz: 'wpis:carousel:zdjecia', zalogowany: true },
];

/*
 * SZEROKOŚCI DO POMIARU PRZEPEŁNIENIA (issue #80)
 *
 * 320 px to minimum z WCAG 2.2 AA, kryterium 1.4.10 (Reflow). 360 i 414 to
 * dwa najczęstsze telefony, 768 to tablet w pionie i próg tuż pod układem
 * dwukolumnowym. Skala tekstu 150% jest tu obowiązkowa, bo nasza grupa
 * realnie ją włącza — a to przy niej belka pękała najbrzydziej.
 */
const SZEROKOSCI_UKLADU = SZYBKO ? [320, 360] : [320, 360, 414, 768];
const SKALE_UKLADU = SZYBKO ? [null] : [null, 150];

/*
 * `skalaTekstu` ustawiamy atrybutem na <html>, tak samo jak robi to layout
 * dla zalogowanego z ustawieniem w profilu. Symulowanie tego zoomem
 * przeglądarki sprawdzałoby coś innego niż to, co dostaje człowiek.
 */
const WARIANTY = SZYBKO
  ? [{ nazwa: 'jasny', motyw: 'light', szerokosc: 1280 }]
  : [
    { nazwa: 'jasny', motyw: 'light', szerokosc: 1280 },
    { nazwa: 'ciemny', motyw: 'dark', szerokosc: 1280 },
    { nazwa: 'tekst 140%', motyw: 'light', szerokosc: 1280, skalaTekstu: 140 },
    { nazwa: '320 px', motyw: 'light', szerokosc: 320 },
  ];

/** Naruszenia poniżej tej wagi notujemy, ale nie zatrzymują one wysyłki. */
const BLOKUJACE = new Set(['critical', 'serious']);

function log(...args) {
  console.log(...args);
}

async function podnies_serwer() {
  if (process.env.ADRES) {
    return { adres: process.env.ADRES, zamknij: () => {} };
  }

  const port = 8000 + Math.floor(Math.random() * 900);
  const adres = `http://127.0.0.1:${port}`;

  log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || 'kuking_test' },
  });

  const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
    stdio: 'ignore',
    env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || 'kuking_test' },
  });

  // Czekamy na serwer zamiast zgadywać czas startu — na wolnej maszynie
  // sztywne „sleep 2" daje losowo czerwony wynik, który wygląda jak regresja.
  for (let i = 0; i < 60; i++) {
    try {
      const odp = await fetch(`${adres}/health`);
      if (odp.ok) break;
    } catch { /* jeszcze nie wstał */ }
    await new Promise((r) => setTimeout(r, 500));
  }

  return { adres, zamknij: () => proces.kill('SIGTERM') };
}

/*
 * Logujemy się DOKŁADNIE RAZ i przenosimy ciasteczka do kolejnych kontekstów.
 *
 * `config/kuking.php` daje pięć prób logowania na minutę. Dopóki skrypt miał
 * cztery warianty, mieścił się w tym limicie o włos. Pomiar układu (issue #80)
 * dokłada kilkanaście kontekstów i przy logowaniu „za każdym razem" serwis
 * odpowiadałby 429 — a skrypt raportowałby to jako błąd strony, nie jako
 * własny. Ciasteczko sesji działa w każdym kontekście tak samo.
 */
async function stanZalogowanego(przegladarka, adres) {
  const kontekst = await przegladarka.newContext();
  const strona = await kontekst.newPage();
  await strona.goto(`${adres}/login`);
  await strona.fill('input[name="login"]', 'basia');
  await strona.fill('input[name="password"]', 'haslo-testowe-123');
  await Promise.all([
    strona.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
    strona.click('button[type="submit"]'),
  ]);
  const stan = await kontekst.storageState();
  await kontekst.close();

  return stan;
}

/*
 * POMIAR PRZEPEŁNIENIA W POZIOMIE (issue #80, WCAG 2.2 AA — 1.4.10 Reflow)
 *
 * DLACZEGO POMIAR, A NIE REGUŁA AXE
 * Reflow nie jest i nie może być regułą axe: żeby go stwierdzić, trzeba
 * ZMIERZYĆ ułożony dokument, a nie przeanalizować drzewo elementów. Belka
 * górna wychodziła poza ekran na KAŻDEJ stronie serwisu, a automat świecił
 * na zielono, bo z punktu widzenia drzewa wszystko było w porządku.
 *
 * Sprawdzamy `documentElement`, czyli całą stronę. Szeroka treść — tabela,
 * blok kodu — ma prawo się przewijać, ale we WŁASNYM kontenerze
 * z `overflow-x: auto`, nie razem z całym dokumentem.
 *
 * Zwracamy też listę elementów, które wystają. Sam komunikat „strona ma
 * 493 px zamiast 360" nie mówi, czego szukać w kodzie.
 */
async function zmierzUklad(strona) {
  return strona.evaluate(() => {
    const korzen = document.documentElement;
    const winni = [];

    if (korzen.scrollWidth > korzen.clientWidth) {
      for (const el of document.querySelectorAll('body *')) {
        const ramka = el.getBoundingClientRect();

        // Element zerowej wielkości nie może niczego rozpychać, a jest ich
        // na stronie sporo (choćby napisy tylko dla czytnika ekranu).
        if (ramka.width === 0 && ramka.height === 0) continue;

        if (ramka.right > korzen.clientWidth + 1 || ramka.left < -1) {
          const klasy = typeof el.className === 'string'
            ? el.className
            : (el.className?.baseVal ?? '');

          winni.push(
            `${el.tagName.toLowerCase()}${klasy ? '.' + klasy.trim().split(/\s+/).slice(0, 2).join('.') : ''}`
            + ` [${Math.round(ramka.left)}…${Math.round(ramka.right)}]`,
          );
        }
      }
    }

    return {
      scrollWidth: korzen.scrollWidth,
      clientWidth: korzen.clientWidth,
      // Pierwsze kilka wystarczy, żeby trafić w miejsce w kodzie. Element,
      // który wystaje, zwykle pociąga za sobą wszystkich swoich rodziców.
      winni: [...new Set(winni)].slice(0, 6),
    };
  });
}

const { adres, zamknij } = await podnies_serwer();
const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/*
 * Adres przepisu bierzemy z BAZY, nie ze strony.
 *
 * Pierwsza wersja szukała linku na „Świeżo z Kuking" — i nic nie znajdowała,
 * bo ta strona pokazuje wpisy, nie przepisy. Skutek był gorszy niż błąd:
 * ekran przepisu po cichu WYPADAŁ ze sprawdzania, a raport wyglądał
 * na kompletny. Zapytanie do bazy nie zależy od tego, która strona akurat
 * linkuje do przepisów.
 */
const adresPrzepisu = (() => {
  const slug = execFileSync('php', ['artisan', 'tinker', '--execute',
    "echo optional(App\\Models\\Recipe::where('status','published')->where('visibility','public')->first())->slug;",
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || 'kuking_test' } })
    .toString().trim();

  return slug === '' ? null : `/przepisy/${slug}`;
})();

if (adresPrzepisu === null) {
  console.error('BŁĄD: w bazie nie ma opublikowanego przepisu — ekran przepisu nie zostałby sprawdzony.');
  console.error('       Uruchom seeder albo wskaż inną bazę przez DB_DATABASE.');
  zamknij();
  process.exit(1);
}

/*
 * Adresy wpisów z KILKOMA zdjęciami — po jednym na każdy tryb (issue #92).
 *
 * Ta sama zasada co przy przepisie: pytamy BAZĘ, a nie stronę. Wpis z jednym
 * zdjęciem nie ma ani karuzeli, ani kolażu (przy jednym zdjęciu wszystkie
 * tryby dają ten sam widok), więc szukamy wyłącznie takich, które naprawdę
 * mają co pokazać — inaczej mierzylibyśmy trzy razy ten sam układ i raport
 * wyglądałby na kompletny.
 */
const wpisyPoTrybie = (() => {
  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute',
    "foreach (['normal','carousel','collage'] as $t) { "
    + "$w = App\\Models\\Post::where('display_mode', $t)->where('status','published')"
    + "->where('visibility','public')->has('media', '>=', 2)->first(); "
    + "echo $t.'='.($w?->getKey() ?? '').PHP_EOL; }",
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || 'kuking_test' } })
    .toString();

  const mapa = {};

  for (const linia of wynik.split('\n')) {
    const [tryb, id] = linia.trim().split('=');

    if (tryb && id) {
      mapa[tryb] = id;
    }
  }

  return mapa;
})();

/*
 * Adres ekranu z listy: `adres` wprost albo `znajdz` do rozwiązania z bazy.
 *
 * `znajdz: 'wpis:carousel'` → wpis w tym trybie,
 * `znajdz: 'wpis:carousel:zdjecia'` → ekran kolejności i wyglądu tego wpisu.
 *
 * Zwrócenie `null` jest tu BŁĘDEM, nie pominięciem: obie pętle niżej wypisują
 * wtedy komunikat i ustawiają kod wyjścia. Ekran, który po cichu wypada
 * ze sprawdzania, jest gorszy niż ekran, który oblewa.
 */
function sciezkaEkranu(ekran) {
  if (! ekran.znajdz) {
    return ekran.adres;
  }

  if (ekran.znajdz === 'przepis') {
    return adresPrzepisu;
  }

  const [, tryb, sufiks] = ekran.znajdz.split(':');
  const id = wpisyPoTrybie[tryb];

  if (! id) {
    return null;
  }

  return sufiks ? `/wpisy/${id}/${sufiks}` : `/wpisy/${id}`;
}

const wyniki = [];
let blokujacych = 0;

/** Przepełnienia w poziomie — osobna lista, bo to nie jest naruszenie axe. */
const przepelnienia = [];

const stanZalogowany = await stanZalogowanego(przegladarka, adres);

for (const wariant of WARIANTY) {
  /*
   * DWA KONTEKSTY, NIE JEDEN (issue #89)
   *
   * Wcześniej wszystkie ekrany były badane w JEDNYM kontekście, zalogowanym.
   * `/login` i `/register` mają middleware `guest`, więc dla zalogowanego
   * odsyłały na `/home` — raport wypisywał „✓ logowanie" i „✓ rejestracja",
   * ale oba wpisy dotyczyły STRONY GŁÓWNEJ PO ZALOGOWANIU.
   *
   * Dwa najważniejsze ekrany dla kogoś, kto dopiero wchodzi do serwisu, nie
   * były zbadane nigdy — a raport wyglądał na kompletny. To jest fałszywa
   * zieleń w narzędziu, którego jedynym zadaniem jest wykrywanie fałszywej
   * zieleni.
   */
  const ustawienia = {
    colorScheme: wariant.motyw,
    viewport: { width: wariant.szerokosc, height: 900 },
  };

  const kontekstGosciaAxe = await przegladarka.newContext(ustawienia);
  const kontekstZalogowanegoAxe = await przegladarka.newContext({
    ...ustawienia,
    storageState: stanZalogowany,
  });

  const stronaGoscia = await kontekstGosciaAxe.newPage();
  const stronaZalogowanego = await kontekstZalogowanegoAxe.newPage();

  for (const ekran of EKRANY) {
    const strona = ekran.zalogowany ? stronaZalogowanego : stronaGoscia;
    const sciezka = sciezkaEkranu(ekran);

    if (! sciezka) {
      // Ciche pominięcie ekranu jest gorsze niż błąd: raport wygląda
      // na kompletny, a jeden widok nie został sprawdzony w ogóle.
      console.error(`BŁĄD: brak adresu dla ekranu „${ekran.nazwa}".`);
      process.exitCode = 1;
      continue;
    }

    const zamowiony = sciezka.startsWith('http') ? sciezka : `${adres}${sciezka}`;

    await strona.goto(zamowiony, { waitUntil: 'domcontentloaded' });

    /*
     * SPRAWDZAMY, CZY DOSTALIŚMY TO, O CO PROSILIŚMY.
     *
     * Samo przeniesienie ekranów do kontekstu gościa nie wystarcza: ta sama
     * pomyłka wróci przy kolejnej trasie za `auth` albo `guest`, tylko wtedy
     * nikt jej nie zauważy. Przekierowanie musi być BŁĘDEM, nie cichym
     * zbadaniem innej strony.
     */
    if (new URL(strona.url()).pathname !== new URL(zamowiony).pathname) {
      console.error(
        `BŁĄD: ekran „${ekran.nazwa}" (${sciezka}) odesłał na ${new URL(strona.url()).pathname}. `
        + 'Raport badałby inną stronę niż zamówiona.',
      );
      process.exitCode = 1;
      continue;
    }

    if (wariant.skalaTekstu) {
      await strona.evaluate(
        (skala) => document.documentElement.setAttribute('data-text-scale', String(skala)),
        wariant.skalaTekstu,
      );
    }

    const wynik = await new AxeBuilder({ page: strona })
      // Reguły WCAG 2.2 AA — cel produktowy z docs/design/DESIGN_SYSTEM.md.
      // `best-practice` świadomie pomijamy: to zalecenia, nie wymagania,
      // a mieszanie ich z naruszeniami AA zamazuje, co trzeba naprawić.
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze();

    for (const naruszenie of wynik.violations) {
      const blokuje = BLOKUJACE.has(naruszenie.impact);
      if (blokuje) blokujacych++;

      wyniki.push({
        ekran: ekran.nazwa,
        wariant: wariant.nazwa,
        waga: naruszenie.impact,
        regula: naruszenie.id,
        opis: naruszenie.help,
        ile: naruszenie.nodes.length,
        // Pierwszy element wystarczy do znalezienia miejsca w kodzie;
        // pełna lista przy 44 przebiegach robi plik nie do przeczytania.
        gdzie: naruszenie.nodes[0]?.target?.join(' ') ?? null,
        pomoc: naruszenie.helpUrl,
      });
    }

    const ile = wynik.violations.length;
    log(`  ${ile === 0 ? '✓' : '✗'} ${ekran.nazwa} (${wariant.nazwa})${ile ? ` — ${ile}` : ''}`);
  }

  await kontekstGosciaAxe.close();
  await kontekstZalogowanegoAxe.close();
}

/* =============================================================================
   UKŁAD: strona nie przewija się w bok (issue #80)

   Osobny przebieg, bo mierzymy coś innego niż axe i na innych szerokościach.
   Jest tani: samo wczytanie strony i jedno `evaluate`, bez analizy drzewa.

   GOŚĆ I ZALOGOWANY OSOBNO
   Belka wyglądała inaczej dla jednego i drugiego — i to wersja zalogowanego
   była gorsza (493 px zamiast 360). Do tego `/login` i `/register` odsyłają
   zalogowanego na `/home`, więc w kontekście z ciasteczkiem sesji te dwa
   ekrany w ogóle nie byłyby sprawdzone.
   ========================================================================== */
log('');
log('Układ (przewijanie w bok):');

for (const szerokosc of SZEROKOSCI_UKLADU) {
  for (const skala of SKALE_UKLADU) {
    const opis = `${szerokosc} px${skala ? ` / tekst ${skala}%` : ''}`;

    const kontekstGoscia = await przegladarka.newContext({
      viewport: { width: szerokosc, height: 740 },
    });
    const kontekstZalogowanego = await przegladarka.newContext({
      viewport: { width: szerokosc, height: 740 },
      storageState: stanZalogowany,
    });

    let zlych = 0;

    for (const ekran of EKRANY_UKLADU) {
      const sciezka = sciezkaEkranu(ekran);

      if (! sciezka) {
        console.error(`BŁĄD: brak adresu dla ekranu „${ekran.nazwa}".`);
        process.exitCode = 1;
        continue;
      }

      const kontekst = ekran.zalogowany ? kontekstZalogowanego : kontekstGoscia;
      const strona = await kontekst.newPage();

      await strona.goto(sciezka.startsWith('http') ? sciezka : `${adres}${sciezka}`,
        { waitUntil: 'domcontentloaded' });

      if (skala) {
        await strona.evaluate(
          (s) => document.documentElement.setAttribute('data-text-scale', String(s)),
          skala,
        );
      }

      const uklad = await zmierzUklad(strona);
      await strona.close();

      if (uklad.scrollWidth > uklad.clientWidth) {
        zlych++;
        przepelnienia.push({
          ekran: ekran.nazwa,
          wariant: opis,
          scrollWidth: uklad.scrollWidth,
          clientWidth: uklad.clientWidth,
          winni: uklad.winni,
        });
      }
    }

    await kontekstGoscia.close();
    await kontekstZalogowanego.close();

    log(`  ${zlych === 0 ? '✓' : '✗'} ${opis}${zlych ? ` — ${zlych} z ${EKRANY_UKLADU.length} ekranów` : ''}`);
  }
}

/* =============================================================================
   KARUZELA Z WYŁĄCZONYM JAVASCRIPTEM (issue #92)

   DLACZEGO TO NIE MOŻE BYĆ TEST PHPUnit
   Test w PHP potrafi sprawdzić, że odnośnik „Następne zdjęcie" prowadzi pod
   istniejącą kotwicę — i taki test jest (WygladZdjecWeWpisieTest). Nie potrafi
   natomiast sprawdzić rzeczy, która w tym issue jest warunkiem: czy po
   kliknięciu PRZEGLĄDARKA naprawdę przewinęła taśmę do następnego zdjęcia.
   To wymaga ułożonej strony, tak samo jak pomiar przepełnienia wyżej.

   `javaScriptEnabled: false` to nie symulacja — to przeglądarka bez skryptów,
   czyli dokładnie to, co ma człowiek przy słabym zasięgu, gdy plik JS się nie
   dociągnie (AGENTS.md §5).

   Sprawdzamy trzy rzeczy naraz:
     1. da się dojść do OSTATNIEGO zdjęcia, klikając „Następne zdjęcie";
     2. przewija się TAŚMA, a nie strona (`documentElement` bez zmian);
     3. droga powrotna działa tak samo.
   ========================================================================== */
log('');
log('Karuzela bez JavaScriptu:');

const karuzelaBezJs = await (async () => {
  const sciezka = sciezkaEkranu({ znajdz: 'wpis:carousel' });

  if (! sciezka) {
    console.error('BŁĄD: brak wpisu z karuzelą — warunek z issue #92 nie zostałby sprawdzony.');
    process.exitCode = 1;

    return null;
  }

  // 320 px: najwęższy ekran z WCAG 2.2 AA. Jeśli karuzela ma gdzieś pęknąć,
  // pęknie tutaj.
  const kontekst = await przegladarka.newContext({
    viewport: { width: 320, height: 740 },
    javaScriptEnabled: false,
  });

  const strona = await kontekst.newPage();
  await strona.goto(`${adres}${sciezka}`, { waitUntil: 'domcontentloaded' });

  const ile = await strona.locator('.karuzela-slajd').count();

  /** Numer slajdu, który jest teraz na wierzchu taśmy (liczony od 1). */
  const widocznySlajd = () => strona.evaluate(() => {
    const tasma = document.querySelector('.karuzela-tasma');
    const slajdy = [...tasma.querySelectorAll('.karuzela-slajd')];
    const srodek = tasma.getBoundingClientRect().left + tasma.clientWidth / 2;

    const numer = slajdy.findIndex((s) => {
      const ramka = s.getBoundingClientRect();

      return ramka.left <= srodek && ramka.right >= srodek;
    });

    return {
      numer: numer + 1,
      przewinieteTasma: Math.round(tasma.scrollLeft),
      przewinietaStrona: Math.round(document.documentElement.scrollLeft),
    };
  });

  const droga = [(await widocznySlajd()).numer];

  for (let i = 1; i < ile; i++) {
    await strona.locator('.karuzela-slajd').nth(i - 1)
      .getByRole('link', { name: 'Następne zdjęcie' }).click();
    await strona.waitForTimeout(200);
    droga.push((await widocznySlajd()).numer);
  }

  const naKoncu = await widocznySlajd();

  const powrot = [];

  for (let i = ile - 1; i > 0; i--) {
    await strona.locator('.karuzela-slajd').nth(i)
      .getByRole('link', { name: 'Poprzednie zdjęcie' }).click();
    await strona.waitForTimeout(200);
    powrot.push((await widocznySlajd()).numer);
  }

  await kontekst.close();

  const oczekiwana = Array.from({ length: ile }, (_, i) => i + 1);

  return {
    slajdow: ile,
    droga,
    powrot,
    przewinieteTasma: naKoncu.przewinieteTasma,
    przewinietaStrona: naKoncu.przewinietaStrona,
    dotarloDoKonca: JSON.stringify(droga) === JSON.stringify(oczekiwana),
    wrocilo: JSON.stringify(powrot) === JSON.stringify(oczekiwana.slice(0, -1).reverse()),
    przewijaSieTasmaNieStrona: naKoncu.przewinieteTasma > 0 && naKoncu.przewinietaStrona === 0,
  };
})();

if (karuzelaBezJs) {
  const dobrze = karuzelaBezJs.dotarloDoKonca
    && karuzelaBezJs.wrocilo
    && karuzelaBezJs.przewijaSieTasmaNieStrona;

  log(`  ${dobrze ? '✓' : '✗'} zdjęć: ${karuzelaBezJs.slajdow}, droga ${karuzelaBezJs.droga.join('→')}`
    + `, powrót ${karuzelaBezJs.powrot.join('→')}`
    + `, taśma przewinięta o ${karuzelaBezJs.przewinieteTasma} px`
    + `, strona o ${karuzelaBezJs.przewinietaStrona} px`);

  if (! dobrze) {
    process.exitCode = 1;
  }
}

await przegladarka.close();
zamknij();

mkdirSync('storage', { recursive: true });
writeFileSync('storage/dostepnosc.json', JSON.stringify({
  data: new Date().toISOString(),
  warianty: WARIANTY.map((w) => w.nazwa),
  naruszen: wyniki.length,
  blokujacych,
  wyniki,
  uklad: {
    szerokosci: SZEROKOSCI_UKLADU,
    skale: SKALE_UKLADU,
    przepelnien: przepelnienia.length,
    przepelnienia,
  },
  karuzelaBezJs,
}, null, 2));

log('');
log(`Wynik zapisany: storage/dostepnosc.json (naruszeń: ${wyniki.length}, `
  + `blokujących: ${blokujacych}, przepełnień w poziomie: ${przepelnienia.length})`);

if (przepelnienia.length > 0) {
  log('');
  log('Strona przewija się w bok (WCAG 2.2 AA — 1.4.10 Reflow):');
  for (const p of przepelnienia) {
    log(`  ${p.ekran} / ${p.wariant}: ${p.scrollWidth} px przy ${p.clientWidth} px okna`);
    log(`      wystaje: ${p.winni.join(' | ') || '(nie ustalono elementu)'}`);
  }
}

if (blokujacych > 0) {
  log('');
  log('Blokujące naruszenia (critical/serious):');
  for (const w of wyniki.filter((w) => BLOKUJACE.has(w.waga))) {
    log(`  [${w.waga}] ${w.ekran} / ${w.wariant}: ${w.regula} — ${w.opis} (${w.ile}x, ${w.gdzie})`);
  }
}

if (blokujacych > 0 || przepelnienia.length > 0) {
  process.exit(1);
}

// `process.exitCode` mógł zostać ustawiony wyżej (nierozwiązany adres ekranu,
// karuzela nieprzechodząca bez JavaScriptu). Wychodzimy z nim, zamiast go
// zgubić — cichy kod 0 przy niesprawdzonym ekranie to dokładnie ta fałszywa
// zieleń, przed którą ten skrypt ma bronić.
process.exit(process.exitCode ?? 0);
