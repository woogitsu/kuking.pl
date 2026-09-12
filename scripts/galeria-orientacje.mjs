/*
 * =============================================================================
 *  Kuking.pl — pomiar GALERII WPISU PRZY MIESZANYCH ORIENTACJACH (issue #431)
 * =============================================================================
 *
 *  PO CO TO JEST
 *  Zgłoszenie właściciela ze zrzutu: „jedno zdjęcie pionowe, drugie poziome,
 *  przez to jest rozjazd i bierze całą wysokość najwyższego zdjęcia nawet jak
 *  nie jest wyświetlane". To są dwa zdania o wrażeniu, a wrażenia nie da się
 *  porównać przed zmianą i po. Ten skrypt zamienia je na trzy liczby:
 *
 *    MARTWE PIKSELE  ile wysokości bierze pole galerii PONAD to, co naprawdę
 *                    zajmuje stojące w nim zdjęcie. W karuzeli to jest wprost
 *                    ta druga połowa zgłoszenia: taśma jest tak wysoka jak
 *                    NAJWYŻSZY slajd, także wtedy, gdy widać zupełnie inny.
 *    ZNIEKSZTAŁCENIE o ile proporcja zdjęcia NA EKRANIE różni się od jego
 *                    proporcji własnej. Zero znaczy „nikt go nie rozciągnął".
 *    PRZEPEŁNIENIE   czy strona przewija się w bok (WCAG 2.2 AA, 1.4.10).
 *
 *  DANE DEMO NIE MAJĄ CZEGO ZMIERZYĆ — I TO JEST SEDNO ISSUE #431
 *  `DemoSeeder::wpisZKilkomaZdjeciami()` tworzy KAŻDE zdjęcie jako 1600×1200,
 *  czyli wszystkie w jednej orientacji i w jednej proporcji. Co gorsza, te
 *  zdjęcia nie mają wygenerowanych wariantów, więc `Media::url()` podstawia
 *  `icons/kuking-mark.svg` — znak o `viewBox="0 0 64 64"`, czyli KWADRAT.
 *  Atrybuty `width`/`height` dają wtedy tylko `aspect-ratio: auto 1600/1200`,
 *  a słowo `auto` znaczy „proporcja własna obrazka, jeśli ją ma" — więc po
 *  wczytaniu znaku wygrywa 1:1 i przeglądarka układa same kwadraty.
 *
 *  Pomiar na samym demo mierzyłby więc galerię, w której WSZYSTKIE zdjęcia
 *  mają tę samą proporcję — czyli stan, w którym zgłoszonej usterki nie ma
 *  z czego zrobić — i meldowałby „0 martwych pikseli, wszystko w porządku".
 *  Dokładnie pułapka 5 z `docs/PULAPKI_TESTOW.md`: narzędzie melduje sukces,
 *  nie zmierzywszy niczego.
 *
 *  Dlatego ten skrypt sam dokłada do bazy trzy wpisy — po jednym na każdy
 *  tryb wyświetlania — a w każdym PRAWDZIWE pliki PNG o prawdziwych
 *  wymiarach: jeden pionowy 1200×1600 i jeden poziomy 1600×900. I ZATRZYMUJE
 *  SIĘ Z BŁĘDEM, jeśli na zmierzonej stronie nie znalazł galerii, w której
 *  naprawdę stoją obok siebie zdjęcie pionowe i poziome.
 *
 *  LOGUJEMY SIĘ RAZ, POTEM NIESIEMY CIASTECZKO
 *  Serwis ma throttle na logowaniu. Osiem przebiegów (cztery szerokości ×
 *  dwie skale pisma) to osiem logowań pod rząd, a przy tylu próbach pomiar
 *  trafia na ekran „Za dużo prób" — który odpowiada 200, układa się ładnie
 *  i przechodzi każdy pomiar, nie pokazawszy ani jednego zdjęcia. Logowanie
 *  idzie więc RAZ, a jego `storageState()` wędruje do każdego kolejnego
 *  kontekstu.
 *
 *  CO MIERZYMY I DLACZEGO AKURAT TYLE
 *  320 / 360 / 390 / 414 px — minimum z WCAG i trzy najczęstsze telefony.
 *  Do tego czcionka przeglądarki 200% (CDP `Page.setFontSizes`, `standard`
 *  i `fixed` = 32), bo to jest twardy warunek właściciela dla wszystkich
 *  usterek mobilnych, a `rem`-owe progi liczą się wtedy inaczej — podmiana
 *  `style.fontSize` na `<html>` dałaby wynik fałszywy (patrz obszerny
 *  komentarz w `scripts/dostepnosc.mjs`).
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/galeria-orientacje.mjs
 *      ADRES=http://127.0.0.1:8123 node scripts/galeria-orientacje.mjs
 *
 *  Bez `ADRES` skrypt sam sieje bazę `kuking_galeria`, buduje arkusz,
 *  podnosi `php artisan serve` i sam go gasi.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { deflateSync } from 'node:zlib';
import { resolve } from 'node:path';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa. NIE `kuking` (deweloperska), NIE `kuking_test`
   (na niej chodzi `php artisan test`) i NIE `kuking_a11y` (automat
   dostępności) — ten skrypt robi `migrate:fresh`, więc wskazanie
   którejkolwiek z nich kasowałoby czyjąś pracę (AGENTS.md: nigdy
   `migrate:fresh` bez jawnego `DB_DATABASE`). */
const BAZA_DOMYSLNA = 'kuking_galeria';

/* Szerokości z warunku właściciela: minimum WCAG i trzy najczęstsze telefony. */
const SZEROKOSCI = [320, 360, 390, 414];

/** Znacznik wariantu „czcionka przeglądarki podwojona". */
const PRZEGLADARKA_200 = 'przegladarka-200';
const BAZOWA_CZCIONKA_PX = 16;
const SKALE = [null, PRZEGLADARKA_200];

/* Wymiary zdjęć pomiarowych. Skrajne, ale prawdziwe: telefon trzymany pionowo
   daje 3:4, trzymany poziomo 16:9. O taką właśnie parę poszło zgłoszenie. */
const PIONOWE = { szerokosc: 1200, wysokosc: 1600 };
const POZIOME = { szerokosc: 1600, wysokosc: 900 };

/* Katalog na zdjęcia pomiarowe, W OBRĘBIE dysku `public`. Nazwa mówi wprost,
   skąd te pliki są — żeby nikt nie wziął ich kiedyś za dane demo. */
const KATALOG_ZDJEC = 'media/pomiar-orientacje';

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

/* =============================================================================
 *  PRAWDZIWY PLIK PNG O PRAWDZIWYCH WYMIARACH
 *
 *  Bez pliku nie ma pomiaru: przeglądarka bierze proporcję z OBRAZKA, nie
 *  z atrybutów `width`/`height` (te są tylko rezerwą na czas wczytywania).
 *  Zdjęcie zastępcze — kwadratowy znak Kuking — spłaszczyłoby wszystkie
 *  orientacje do jednej i pomiar mierzyłby co innego, niż myśli.
 *
 *  PNG składamy tutaj, zamiast wciągać bibliotekę graficzną: nagłówek, jeden
 *  blok `IDAT` ze spakowanymi wierszami i `IEND` to kilkanaście linijek,
 *  a nowa zależność w `package.json` wymagałaby uzasadnienia (AGENTS.md §3).
 * ========================================================================== */
function crc32(bufor) {
  let c;
  const tabela = [];

  for (let n = 0; n < 256; n++) {
    c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
    tabela[n] = c >>> 0;
  }

  let crc = 0xFFFFFFFF;
  for (const bajt of bufor) crc = tabela[(crc ^ bajt) & 0xFF] ^ (crc >>> 8);

  return (crc ^ 0xFFFFFFFF) >>> 0;
}

function kawalek(typ, dane) {
  const dlugosc = Buffer.alloc(4);
  dlugosc.writeUInt32BE(dane.length, 0);

  const tresc = Buffer.concat([Buffer.from(typ, 'ascii'), dane]);
  const suma = Buffer.alloc(4);
  suma.writeUInt32BE(crc32(tresc), 0);

  return Buffer.concat([dlugosc, tresc, suma]);
}

/**
 * Jednokolorowy PNG o zadanych wymiarach. Kolor jest po to, żeby na zrzucie
 * ekranu dało się odróżnić zdjęcie pionowe od poziomego gołym okiem.
 */
function zrobPng(szerokosc, wysokosc, [r, g, b]) {
  const naglowek = Buffer.alloc(13);
  naglowek.writeUInt32BE(szerokosc, 0);
  naglowek.writeUInt32BE(wysokosc, 4);
  naglowek[8] = 8;    // 8 bitów na kanał
  naglowek[9] = 2;    // typ 2: truecolor RGB
  naglowek[10] = 0;   // kompresja deflate
  naglowek[11] = 0;   // filtr standardowy
  naglowek[12] = 0;   // bez przeplotu

  const wiersz = Buffer.alloc(1 + szerokosc * 3);
  for (let x = 0; x < szerokosc; x++) {
    wiersz[1 + x * 3] = r;
    wiersz[2 + x * 3] = g;
    wiersz[3 + x * 3] = b;
  }

  const surowe = Buffer.concat(Array.from({ length: wysokosc }, () => wiersz));

  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]),
    kawalek('IHDR', naglowek),
    kawalek('IDAT', deflateSync(surowe, { level: 9 })),
    kawalek('IEND', Buffer.alloc(0)),
  ]);
}

/**
 * Kładzie pliki wariantów na dysku `public` i oddaje kształt `metadata.variants`
 * dokładnie taki, jaki zapisuje `ProcessUploadedImage` — bo to on jest tu
 * wzorcem, a nie odwrotnie.
 */
function polozZdjecie(nazwa, wymiary, kolor) {
  const katalog = resolve('storage/app/public', KATALOG_ZDJEC);
  mkdirSync(katalog, { recursive: true });

  const warianty = {};

  /* Te same trzy warianty i te same krawędzie co `config('kuking.media.variants')`.
     `scaleDown` NIGDY NIE POWIĘKSZA, więc wariant nie może wyjść większy niż
     oryginał — inaczej `srcset` obiecywałby przeglądarce plik, którego nie ma
     (ten sam błąd, który naprawiał audyt T30, patrz `components/photo.blade.php`). */
  for (const [wariant, krawedz] of [['thumb', 320], ['feed', 960], ['large', 1600]]) {
    const skala = Math.min(1, krawedz / Math.max(wymiary.szerokosc, wymiary.wysokosc));
    const szerokosc = Math.max(1, Math.round(wymiary.szerokosc * skala));
    const wysokosc = Math.max(1, Math.round(wymiary.wysokosc * skala));

    const klucz = `${KATALOG_ZDJEC}/${nazwa}-${wariant}.png`;
    const bajty = zrobPng(szerokosc, wysokosc, kolor);

    writeFileSync(resolve('storage/app/public', klucz), bajty);

    warianty[wariant] = {
      key: klucz,
      width: szerokosc,
      height: wysokosc,
      bytes: bajty.length,
    };
  }

  return warianty;
}

/* =============================================================================
 *  TRZY WPISY POMIAROWE — PO JEDNYM NA TRYB WYŚWIETLANIA
 *
 *  Każdy ma DOKŁADNIE dwa zdjęcia: pionowe i poziome. Kolejność jest zawsze
 *  ta sama (najpierw pionowe), bo w galerii dwukolumnowej to ONO dyktuje
 *  wysokość wiersza — a właśnie o tę wysokość poszło zgłoszenie.
 * ========================================================================== */
function przygotujWpisy() {
  const wariantyPionowe = JSON.stringify(polozZdjecie('pionowe', PIONOWE, [214, 92, 60]));
  const wariantyPoziome = JSON.stringify(polozZdjecie('poziome', POZIOME, [60, 120, 180]));

  const php = `
    $autor = App\\Models\\Profile::where('username', '${KONTO}')->value('user_id');
    if (! $autor) { echo 'BRAK-AUTORA'; exit; }

    $warianty = [
        'pionowe' => json_decode('${wariantyPionowe}', true),
        'poziome' => json_decode('${wariantyPoziome}', true),
    ];

    $wymiary = [
        'pionowe' => [${PIONOWE.szerokosc}, ${PIONOWE.wysokosc}],
        'poziome' => [${POZIOME.szerokosc}, ${POZIOME.wysokosc}],
    ];

    $opisy = [
        'pionowe' => 'Zdjęcie pionowe (dane pomiarowe, issue 431)',
        'poziome' => 'Zdjęcie poziome (dane pomiarowe, issue 431)',
    ];

    $tryby = [
        App\\Models\\Post::DISPLAY_NORMAL   => 'zwykle',
        App\\Models\\Post::DISPLAY_CAROUSEL => 'karuzela',
        App\\Models\\Post::DISPLAY_COLLAGE  => 'kolaz',
    ];

    $adresy = [];
    $minuta = 1;

    foreach ($tryby as $tryb => $nazwa) {
        $wpis = App\\Models\\Post::create([
            'author_id' => $autor,
            'body' => 'Pomiar orientacji zdjęć (' . $nazwa . '): jedno pionowe, jedno poziome.',
            'visibility' => App\\Models\\Post::VISIBILITY_PUBLIC,
            'status' => App\\Models\\Post::STATUS_PUBLISHED,
            'display_mode' => $tryb,
            'published_at' => now()->subMinutes($minuta++),
        ]);

        foreach (['pionowe', 'poziome'] as $pozycja => $ksztalt) {
            $zdjecie = App\\Models\\Media::create([
                'owner_id' => $autor,
                'disk' => 'public',
                /* Klucz musi być UNIKALNY: kolumna object_key ma unikat w bazie,
                   a tych zdjęć powstaje sześć (trzy wpisy razy dwa ujęcia). Same
                   PLIKI wariantów są wspólne i to jest w porządku, bo object_key
                   wskazuje ORYGINAŁ, którego ten pomiar nigdy nie serwuje.
                   (Bez odwrotnych apostrofów: cały ten blok jest szablonem
                   JavaScriptu i apostrof zamknąłby go w środku zdania.) */
                'object_key' => '${KATALOG_ZDJEC}/' . $ksztalt . '-' . Illuminate\\Support\\Str::uuid()->toString() . '.png',
                'mime_type' => 'image/png',
                'bytes' => 120000,
                'width' => $wymiary[$ksztalt][0],
                'height' => $wymiary[$ksztalt][1],
                'status' => App\\Models\\Media::STATUS_READY,
                'alt_text' => $opisy[$ksztalt],
                'metadata' => ['variants' => $warianty[$ksztalt]],
            ]);

            $wpis->media()->attach($zdjecie->getKey(), ['position' => $pozycja]);
        }

        $adresy[] = $nazwa . '=' . route('posts.show', $wpis, false);
    }

    echo implode(' ', $adresy);
  `;

  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: env() })
    .toString().trim();

  if (wynik === '' || wynik.includes('BRAK-AUTORA')) {
    throw new Error(
      'Nie udało się przygotować wpisów pomiarowych. Bez nich w bazie nie ma ANI JEDNEJ\n'
      + 'galerii o mieszanych orientacjach — a to jest jedyna rzecz, którą ten skrypt mierzy.\n'
      + `Wyjście tinkera: ${wynik}`,
    );
  }

  const adresy = {};

  for (const para of wynik.split(/\s+/)) {
    const [nazwa, adres] = para.split('=');
    if (nazwa && adres) adresy[nazwa] = adres;
  }

  if (Object.keys(adresy).length !== 3) {
    throw new Error(`Tinker oddał ${Object.keys(adresy).length} adresów zamiast trzech: ${wynik}`);
  }

  return adresy;
}

async function wolnyPort() {
  const { createServer } = await import('node:net');

  return new Promise((resolve_, reject) => {
    const gniazdo = createServer();
    gniazdo.unref();
    gniazdo.on('error', reject);
    gniazdo.listen(0, '127.0.0.1', () => {
      const { port } = gniazdo.address();
      gniazdo.close(() => resolve_(port));
    });
  });
}

async function podniesSerwer() {
  if (process.env.ADRES) {
    return { adres: process.env.ADRES, adresy: przygotujWpisy(), zamknij: () => {} };
  }

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  /* ARKUSZ JEST ZBUDOWANY, NIE CZYTANY Z resources/. Strona wciąga
     `public/build/assets/app-*.css` przez manifest Vite, więc pomiar bez
     przebudowania opisywałby POPRZEDNIĄ wersję CSS — czyli po poprawce
     pokazywałby te same liczby co przed nią i wyglądałoby to na nieskuteczną
     zmianę. */
  console.log('Buduję arkusz (vite build)...');
  execFileSync('npm', ['run', 'build'], { stdio: 'ignore', env: process.env });

  console.log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const adresy = przygotujWpisy();
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

    if (wstal) return { adres, adresy, zamknij: () => proces.kill('SIGTERM') };

    proces.kill('SIGKILL');
    bledy.push(`  podejście ${podejscie}, port ${port}: `
      + (umarl !== null ? `proces zakończył się kodem ${umarl}` : 'brak odpowiedzi z /health')
      + (dziennik.length > 0 ? `\n${dziennik.join('').trimEnd()}` : ''));
  }

  throw new Error(`Nie udało się podnieść \`php artisan serve\`.\n${bledy.join('\n')}`);
}

/* =============================================================================
 *  POMIAR
 *
 *  MARTWE PIKSELE LICZYMY OD TEGO, CO ZDJĘCIE NAPRAWDĘ ZAJMUJE — nie od
 *  deklaracji w CSS. Bierzemy `getBoundingClientRect()` pola galerii i
 *  `getBoundingClientRect()` stojącego w nim obrazka; różnica to wysokość,
 *  za którą człowiek płaci przewijaniem, nie widząc w niej niczego.
 *
 *  ZNIEKSZTAŁCENIE liczymy jako różnicę proporcji WYŚWIETLONEJ i WŁASNEJ
 *  (`naturalWidth`/`naturalHeight`). Rozciągnięcie obrazka przez
 *  `align-items: stretch` daje tu liczbę różną od zera — i to jest ten
 *  „rozjazd" ze zgłoszenia, widziany od strony jedzenia na zdjęciu.
 * ========================================================================== */
const POMIAR = () => {
  const zaokr = (x) => Math.round(x * 10) / 10;

  /*
   * MIERZYMY ZDJĘCIE, NIE JEGO PUDEŁKO — i to jest poprawka błędu, który ten
   * skrypt miał przy pierwszym pomiarze „po".
   *
   * Przy `object-fit: contain` pudełko `<img>` ma proporcję nadaną CSS-em,
   * a namalowane piksele mają proporcję własną zdjęcia i leżą wyśrodkowane
   * w środku pudełka. Odczyt samego `getBoundingClientRect()` meldował więc
   * „zdjęcie rozciągnięte o 25%" o zdjęciu, którego nikt nie tknął — bo
   * czytał pudełko. To ta sama pomyłka co pułapka 1, przeniesiona na
   * geometrię: mierzysz coś, co jest obok tego, o co pytasz.
   *
   * Liczymy więc prostokąt NAMALOWANY, dokładnie tak, jak liczy go
   * przeglądarka, i od niego liczymy jedno i drugie:
   *   - zniekształcenie wychodzi wtedy 0 dla `contain` (bo `contain` nigdy
   *     nie zniekształca) i różne od zera tam, gdzie zdjęcie NAPRAWDĘ
   *     rozciągnięto;
   *   - martwe piksele obejmują też LETTERBOX, czyli pas tła nad i pod
   *     zdjęciem w polu o stałej proporcji. I bardzo dobrze: dla człowieka
   *     przewijającego kartę pusty pas jest pustym pasem niezależnie od
   *     tego, czy powstał z układu siatki, czy z `object-fit`.
   */
  const opisObrazka = (img) => {
    const p = img.getBoundingClientRect();
    const nw = img.naturalWidth || 0;
    const nh = img.naturalHeight || 0;
    const dopasowanie = getComputedStyle(img).objectFit;

    const wlasna = nw > 0 && nh > 0 ? nw / nh : null;

    let malowaneW = p.width;
    let malowaneH = p.height;

    if (nw > 0 && nh > 0 && p.width > 0 && p.height > 0) {
      if (dopasowanie === 'contain' || dopasowanie === 'scale-down') {
        let skala = Math.min(p.width / nw, p.height / nh);
        if (dopasowanie === 'scale-down') skala = Math.min(skala, 1);
        malowaneW = nw * skala;
        malowaneH = nh * skala;
      } else if (dopasowanie === 'cover') {
        const skala = Math.max(p.width / nw, p.height / nh);
        malowaneW = nw * skala;
        malowaneH = nh * skala;
      }
    }

    const widziana = malowaneH > 0 ? malowaneW / malowaneH : null;

    return {
      wlasneWymiary: nw > 0 && nh > 0 ? `${nw}×${nh}` : 'nieznane',
      orientacja: wlasna === null ? 'nieznana' : (wlasna > 1.02 ? 'poziome' : (wlasna < 0.98 ? 'pionowe' : 'kwadrat')),
      szerokosc: zaokr(malowaneW),
      wysokosc: zaokr(malowaneH),
      /* Wysokość pudełka obok wysokości zdjęcia — różnica to letterbox. */
      pudelko: zaokr(p.height),
      /* Procentowa różnica proporcji. 0 = nikt zdjęcia nie rozciągnął. */
      znieksztalcenie: (wlasna === null || widziana === null)
        ? null
        : zaokr(Math.abs(widziana - wlasna) / wlasna * 100),
      malowanaWysokosc: malowaneH,
    };
  };

  const galerie = [];

  /* --- Galeria „zwykle": siatka. Polem jest bezpośrednie dziecko. --------- */
  for (const siatka of document.querySelectorAll('.photo-grid')) {
    const pola = [...siatka.children].map((pole) => {
      const img = pole.tagName === 'IMG' ? pole : pole.querySelector('img');
      if (! img) return null;

      const pp = pole.getBoundingClientRect();
      const obraz = opisObrazka(img);

      return {
        pole: zaokr(pp.height),
        ...obraz,
        martwe: zaokr(pp.height - obraz.malowanaWysokosc),
      };
    }).filter(Boolean);

    if (pola.length > 0) {
      galerie.push({
        rodzaj: 'zwykle (.photo-grid)',
        wysokoscCalosci: zaokr(siatka.getBoundingClientRect().height),
        /* Ile kolumn naprawdę wyszło — dwa zdjęcia obok siebie czy jedno
           pod drugim. Bez tej liczby nie da się przeczytać pozostałych. */
        kolumny: getComputedStyle(siatka).gridTemplateColumns.split(' ').filter(Boolean).length,
        pola,
      });
    }
  }

  /* --- Karuzela: taśma i slajdy. TU SIEDZI DRUGA POŁOWA ZGŁOSZENIA. ------- */
  for (const karuzela of document.querySelectorAll('.karuzela')) {
    const tasma = karuzela.querySelector('.karuzela-tasma');
    if (! tasma) continue;

    const wysokoscTasmy = tasma.getBoundingClientRect().height;

    const pola = [...tasma.querySelectorAll('.karuzela-slajd')].map((slajd) => {
      const img = slajd.querySelector('img');
      if (! img) return null;

      const pasek = slajd.querySelector('.karuzela-pasek');
      const obraz = opisObrazka(img);

      /* Ile ten slajd NAPRAWDĘ potrzebuje: zdjęcie plus pasek z licznikiem
         i przyciskami. Wszystko ponad to jest wysokością pożyczoną od
         najwyższego slajdu — czyli od zdjęcia, którego w tej chwili NIE WIDAĆ. */
      const wlasna = obraz.malowanaWysokosc
        + (pasek ? pasek.getBoundingClientRect().height : 0);

      return {
        pole: zaokr(wysokoscTasmy),
        ...obraz,
        martwe: zaokr(wysokoscTasmy - wlasna),
      };
    }).filter(Boolean);

    if (pola.length > 0) {
      galerie.push({
        rodzaj: 'karuzela (.karuzela-tasma)',
        wysokoscCalosci: zaokr(wysokoscTasmy),
        kolumny: 1,
        pola,
      });
    }
  }

  /* --- Kolaż: pola siatki. ----------------------------------------------- */
  for (const kolaz of document.querySelectorAll('.kolaz')) {
    const pola = [...kolaz.querySelectorAll('.kolaz-pole')].map((pole) => {
      const img = pole.querySelector('img');
      if (! img) return null;

      const pp = pole.getBoundingClientRect();

      const obraz = opisObrazka(img);

      return {
        pole: zaokr(pp.height),
        ...obraz,
        martwe: zaokr(pp.height - obraz.malowanaWysokosc),
      };
    }).filter(Boolean);

    if (pola.length > 0) {
      galerie.push({
        rodzaj: 'kolaż (.kolaz)',
        wysokoscCalosci: zaokr(kolaz.getBoundingClientRect().height),
        kolumny: getComputedStyle(kolaz).gridTemplateColumns.split(' ').filter(Boolean).length,
        pola,
      });
    }
  }

  const korzen = document.documentElement;

  return {
    galerie,
    scrollWidth: korzen.scrollWidth,
    clientWidth: korzen.clientWidth,
  };
};

/* =============================================================================
 *  PRZEBIEG
 * ========================================================================== */
const CHROMIUM = znajdzChromium();
const { adres, adresy, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);
console.log(`Wpisy pomiarowe: ${Object.entries(adresy).map(([k, v]) => `${k} → ${v}`).join(', ')}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/* Rzeczy, których brak unieważnia CAŁY pomiar. Zbierane w trakcie, sprawdzane
   na końcu — patrz „BRAMKI" niżej. */
const widziane = {
  galerieMieszane: 0,
  rodzaje: new Set(),
  przebiegi: 0,
};

const naruszenia = [];

try {
  /* ---------------------------------------------------------------------
     LOGUJEMY SIĘ RAZ.

     Serwis ma throttle na logowaniu, a ten skrypt robi osiem przebiegów.
     Osiem logowań pod rząd kończy się ekranem „Za dużo prób", który odpowiada
     kodem 200 i układa się poprawnie — czyli przechodzi każdy pomiar, nie
     pokazawszy ani jednego zdjęcia. Ciasteczko sesji wędruje więc do każdego
     kolejnego kontekstu przez `storageState()`.
     --------------------------------------------------------------------- */
  const kontekstLogowania = await przegladarka.newContext();
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

  console.log(`Zalogowano raz jako „${KONTO}" — ciasteczko idzie do wszystkich przebiegów.\n`);

  for (const skala of SKALE) {
    for (const szerokosc of SZEROKOSCI) {
      const etykieta = `${szerokosc} px${skala === PRZEGLADARKA_200 ? ' / czcionka przeglądarki 200%' : ''}`;

      const kontekst = await przegladarka.newContext({
        viewport: { width: szerokosc, height: 900 },
        storageState: sesja,
      });

      console.log(`\n=== ${etykieta} ===`);

      for (const [nazwaTrybu, sciezka] of Object.entries(adresy)) {
        const strona = await kontekst.newPage();

        if (skala === PRZEGLADARKA_200) {
          /* PRZED nawigacją — to ma być stan przeglądarki ZASTANY przez
             stronę, a nie zmiana doklejona po jej ułożeniu. Przez CDP, a nie
             przez `style.fontSize` na `<html>`: w media query `rem` liczy się
             od POCZĄTKOWEGO pisma przeglądarki, więc podmiana przez CSSOM
             podwaja tekst, ale zostawia progi tam, gdzie były — i mierzy
             układ, w którym żaden człowiek nie jest. */
          const cdp = await kontekst.newCDPSession(strona);

          await cdp.send('Page.setFontSizes', {
            fontSizes: { standard: 2 * BAZOWA_CZCIONKA_PX, fixed: 2 * BAZOWA_CZCIONKA_PX },
          });
        }

        const odpowiedz = await strona.goto(`${adres}${sciezka}`, { waitUntil: 'load' });
        const kod = odpowiedz?.status() ?? 0;

        if (kod !== 200) {
          /* Strona błędu i ekran „Za dużo prób" układają się poprawnie
             i przechodzą każdy pomiar. Milczące „✓" na nich byłoby
             najgorszym możliwym wynikiem. */
          console.error(`  BŁĄD: ${sciezka} odpowiedziało kodem ${kod} — pomiar nic nie znaczy.`);
          process.exitCode = 1;
          await strona.close();
          continue;
        }

        /* NAJPIERW ZDEJMUJEMY `loading="lazy"` Z MIERZONYCH ZDJĘĆ.

           Bez tego pomiar przy czcionce 200% nie dochodził do skutku: tekst
           jest wtedy dwa razy większy, galeria schodzi daleko pod zgięcie,
           przeglądarka słusznie nie pobiera zdjęć spod zgięcia i czekanie na
           `complete` kończyło się przekroczeniem czasu. Zmierzone: 320 px
           przy 200% — timeout na każdym z trzech wpisów.

           Zmiana dotyczy WYŁĄCZNIE momentu pobrania pliku, nie układu: o tym,
           ile miejsca zajmie zdjęcie, decydują `width`/`height`, CSS i
           proporcja własna obrazka — a te są takie same przy `lazy` i przy
           `eager`. Gdybyśmy tego nie zrobili, zostałaby gorsza opcja:
           mierzyć układ SPRZED wczytania zdjęć, czyli nie ten, który widzi
           człowiek. */
        await strona.evaluate(() => {
          for (const img of document.querySelectorAll('.photo-grid img, .karuzela img, .kolaz img')) {
            img.loading = 'eager';
          }
        });

        /* Zdjęcie niewczytane ma `naturalWidth` równy zeru, więc orientacji
           nie da się z niego ustalić — a galeria „bez orientacji" przechodzi
           bramkę mieszanych orientacji jako brak danych, nie jako usterka. */
        await strona.waitForFunction(
          () => [...document.querySelectorAll('.photo-grid img, .karuzela img, .kolaz img')]
            .every((i) => i.complete && i.naturalWidth > 0),
          null,
          { timeout: 30000 },
        );

        const wynik = await strona.evaluate(POMIAR);

        widziane.przebiegi++;

        if (wynik.scrollWidth > wynik.clientWidth) {
          naruszenia.push(`${etykieta} · ${nazwaTrybu}: strona przewija się w bok `
            + `(${wynik.scrollWidth} px przy oknie ${wynik.clientWidth} px)`);
        }

        for (const galeria of wynik.galerie) {
          widziane.rodzaje.add(galeria.rodzaj);

          const orientacje = new Set(galeria.pola.map((p) => p.orientacja));
          const mieszana = orientacje.has('pionowe') && orientacje.has('poziome');

          if (mieszana) widziane.galerieMieszane++;

          console.log(`\n  ${galeria.rodzaj} · ${nazwaTrybu}`
            + `  (kolumn: ${galeria.kolumny}, wysokość całości: ${galeria.wysokoscCalosci} px`
            + `${mieszana ? '' : ', UWAGA: orientacje NIE są mieszane'})`);

          for (const pole of galeria.pola) {
            const uwagi = [];
            if (pole.martwe > 1) uwagi.push(`<-- ${pole.martwe} px MARTWE`);
            if (pole.znieksztalcenie !== null && pole.znieksztalcenie > 1) {
              uwagi.push(`<-- ROZCIĄGNIĘTE o ${pole.znieksztalcenie}%`);
            }

            console.log(`    ${pole.orientacja.padEnd(8)} ${String(pole.wlasneWymiary).padEnd(10)}`
              + ` obraz ${String(pole.szerokosc).padStart(6)} × ${String(pole.wysokosc).padStart(6)} px`
              + `   pudełko ${String(pole.pudelko).padStart(6)} px`
              + `   pole ${String(pole.pole).padStart(6)} px`
              + `   martwe ${String(pole.martwe).padStart(6)} px`
              + `   zniekszt. ${String(pole.znieksztalcenie).padStart(5)}%`
              + (uwagi.length > 0 ? `   ${uwagi.join(' ')}` : ''));
          }
        }

        await strona.close();
      }

      await kontekst.close();
    }
  }
} finally {
  await przegladarka.close();
  zamknij();
}

/* =============================================================================
 *  BRAMKI — czyli po czym poznać, że ten pomiar cokolwiek zmierzył
 *
 *  Pusty ekran przechodzi każdy pomiar. Galeria z samymi zdjęciami o tej samej
 *  proporcji przechodzi TEN pomiar, bo martwych pikseli w niej z definicji nie
 *  ma. Oba stany wyglądają w raporcie identycznie jak stan naprawiony — i to
 *  jest pułapka 5 z `docs/PULAPKI_TESTOW.md`. Dlatego brak danych do pomiaru
 *  kończy się tutaj kodem różnym od zera, a nie ciszą.
 * ========================================================================== */
const oczekiwaneRodzaje = ['zwykle (.photo-grid)', 'karuzela (.karuzela-tasma)', 'kolaż (.kolaz)'];
const brakujace = oczekiwaneRodzaje.filter((r) => ! widziane.rodzaje.has(r));

console.log(`\n\nPrzebiegów: ${widziane.przebiegi}`
  + `, galerii o MIESZANYCH orientacjach: ${widziane.galerieMieszane}`);

if (widziane.przebiegi === 0) {
  console.error('\nBŁĄD: nie zmierzono ANI JEDNEGO ekranu.');
  process.exit(1);
}

if (brakujace.length > 0) {
  console.error(`\nBŁĄD: nie zobaczyłem galerii rodzaju: ${brakujace.join(', ')}.`);
  console.error('Zgłoszenie dotyczy wszystkich trzech sposobów wyświetlania zdjęć —');
  console.error('pomiar dwóch z nich nie mówi nic o trzecim.');
  process.exit(1);
}

if (widziane.galerieMieszane === 0) {
  console.error('\nBŁĄD: na żadnej zmierzonej galerii nie stanęły obok siebie zdjęcie');
  console.error('pionowe i poziome — a to jest JEDYNA rzecz, o którą poszło issue #431.');
  console.error('Zero martwych pikseli w takim przebiegu nie znaczy „naprawione",');
  console.error('tylko „nie było czego zmierzyć".');
  process.exit(1);
}

if (naruszenia.length > 0) {
  console.error(`\nPRZEPEŁNIENIE POZIOME — ${naruszenia.length}:`);
  for (const n of naruszenia) console.error(`  ${n}`);
  process.exit(1);
}

console.log('\nPomiar zakończony. Liczby wyżej są wynikiem — nie ma tu progu,');
console.log('który by je za ciebie oceniał.');
