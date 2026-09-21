/*
 * =============================================================================
 *  Kuking.pl — pomiar odstępów PIONOWYCH między blokami karty wpisu
 * =============================================================================
 *
 *  PO CO TO JEST
 *  Zgłoszenie właściciela: „«z przepisu» i «bigos z cukinii» jest zbyt blisko
 *  zdjęcia". To jest zdanie o wrażeniu, a wrażenia nie da się porównać przed
 *  zmianą i po. Ten skrypt zamienia je na liczby: dla każdej pary sąsiadujących
 *  bloków karty wpisu mierzy PRZERWĘ w pikselach — górna krawędź bloku niżej
 *  minus dolna krawędź bloku wyżej, czytane z `getBoundingClientRect()`.
 *
 *  Para z wynikiem 0 px to dwa bloki, które na ekranie się stykają.
 *
 *  KARTA Z PRZEPISEM I KARTA BEZ PRZEPISU TO DWA RÓŻNE EKRANY (D-099, D-106)
 *  Pasek „Z przepisu · …" renderuje się WYŁĄCZNIE dla wpisu wskazującego
 *  przepis (`@if($post->recipe)` w `components/post-card.blade.php`), a
 *  `DemoSeeder` nie tworzy ani jednego takiego wpisu. Pomiar samego demo
 *  mierzyłby więc kartę, na której zgłoszonego paska NIE MA — i meldował, że
 *  wszystko w porządku. Dlatego skrypt sam dokłada do bazy jeden wpis
 *  wskazujący przepis (tinkerem, nie zmianą cudzego seedera) i ZATRZYMUJE SIĘ
 *  Z BŁĘDEM, jeśli na zmierzonej stronie nie znalazł ani jednego takiego paska.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/odstepy-karty-wpisu.mjs
 *      ADRES=http://127.0.0.1:8123 node scripts/odstepy-karty-wpisu.mjs
 *
 *  Bez `ADRES` skrypt sam sieje bazę `kuking_odstep_karty`, podnosi
 *  `php artisan serve` i sam go gasi. `config:clear` idzie PRZED serwerem:
 *  zapamiętana konfiguracja (`config:cache`) wskazywałaby inną bazę, niż ta,
 *  którą właśnie zasialiśmy.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { ustalBazePomiarowa } from './bezpiecznik-bazy.mjs';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa. NIE `kuking` (deweloperska) i NIE `kuking_test`
   (na niej chodzi `php artisan test`) — ten skrypt robi `migrate:fresh`,
   więc wskazanie którejkolwiek z nich kasowałoby czyjąś pracę
   (AGENTS.md: nigdy `migrate:fresh` bez jawnego `DB_DATABASE`). */
const BAZA_DOMYSLNA = 'kuking_odstep_karty';

/* BEZPIECZNIK: ten skrypt robi `migrate:fresh`, czyli KASUJE zawartosc
   bazy. `ustalBazePomiarowa()` wpuszcza wylacznie jednorazowa baze pomiarowa
   i ODMAWIA startu przy nazwie, ktorej nie rozpoznaje — nie wiem, czyja to
   baza, wiec jej nie kasuje (scripts/bezpiecznik-bazy.mjs). Liczone RAZ, na
   starcie: odmowa ma paść, zanim skrypt cokolwiek zbuduje albo podniesie. */
const BAZA_POMIAROWA = ustalBazePomiarowa({
  domyslna: BAZA_DOMYSLNA,
  skrypt: 'scripts/odstepy-karty-wpisu.mjs',
});

/* Szerokości: desktop z paczki właściciela i telefon. Rytm karty bywa różny
   w dwóch układach — to była druga, groźniejsza usterka z PR #400. */
const SZEROKOSCI = [
  { nazwa: 'desktop 1512 px', szerokosc: 1512, wysokosc: 900 },
  { nazwa: 'telefon 390 px', szerokosc: 390, wysokosc: 844 },
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
  return { ...process.env, DB_DATABASE: BAZA_POMIAROWA };
}

/*
 * WPIS WSKAZUJĄCY PRZEPIS, ZE ZDJĘCIEM PRZEPISU I Z TAGAMI.
 *
 * Dokładnie taka karta jest na zrzucie od właściciela: zdjęcie dania, pod nim
 * pasek „Z przepisu · <tytuł>". Wpis powstaje BEZ `body` i BEZ własnych zdjęć
 * (issue #368) — zdjęcie bierze się z relacji do przepisu, więc przepis musi
 * mieć `hero_media_id`, a `DemoSeeder` go nie ustawia.
 *
 * Autorem jest autor przepisu, a `ania` go obserwuje (graf z `DemoSeeder`),
 * więc wpis wchodzi do `/home` — strumienia obserwowanych.
 *
 * Sprawdzenie „czy już jest" PRZED zapisem: przy `ADRES=…` skrypt trafia na
 * bazę postawioną wcześniej i nie ma dokładać drugiego wpisu.
 */
function przygotujWpisZPrzepisem() {
  const php = `
    $przepis = App\\Models\\Recipe::where('status','published')->orderBy('created_at')->first();
    if (! $przepis) { echo 'BRAK-PRZEPISU'; exit; }

    if (! $przepis->hero_media_id) {
        $zdjecie = App\\Models\\Media::create([
            'owner_id' => $przepis->author_id,
            'disk' => 'public',
            'object_key' => 'media/pomiar/'.Illuminate\\Support\\Str::uuid()->toString().'.webp',
            'mime_type' => 'image/webp',
            'bytes' => 180000,
            'width' => 1600,
            'height' => 1200,
            'status' => App\\Models\\Media::STATUS_READY,
            'alt_text' => 'Zdjęcie dania do przepisu (dane pomiarowe)',
            'metadata' => ['variants' => []],
        ]);
        $przepis->forceFill(['hero_media_id' => $zdjecie->getKey()])->save();
    }

    $wpis = App\\Models\\Post::whereNotNull('recipe_id')->first();
    if (! $wpis) {
        $wpis = App\\Models\\Post::create([
            'author_id' => $przepis->author_id,
            'recipe_id' => $przepis->getKey(),
            'body' => null,
            'visibility' => App\\Models\\Post::VISIBILITY_PUBLIC,
            'status' => App\\Models\\Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /* Pozycja w pivocie jest OBOWIĄZKOWA — tabela post_tags ma unikat na
       parze (post_id, position), a kolejność tematów jest wyborem autora
       (App\\Domain\\Posts\\Actions\\PublishPost). Bez tego pola drugi temat
       wchodzi z tą samą pozycją 0 i baza słusznie odmawia. */
    $pozycje = [];
    foreach (App\\Models\\Tag::limit(2)->pluck('id')->all() as $i => $id) {
        $pozycje[$id] = ['position' => $i];
    }
    if ($pozycje) { $wpis->tags()->syncWithoutDetaching($pozycje); }

    /* DRUGA MIERZONA KARTA: ZDJĘCIE, a pod nim „N osób zapisało to u siebie
       w zeszycie" — bez paska przepisu i bez tematów, żeby ten akapit
       naprawdę stanął PRZY zdjęciu. Ten sam blok w demo nigdy się nie
       renderuje: liczbę widzi autor swojego wpisu od pierwszego zapisu, a inni
       od trzech (ZapisyWpisu::PROG_DLA_OBCYCH), a żaden seeder nie zapisuje
       wpisów do zeszytów. Autorem jest konto, którym ten skrypt się loguje. */
    $widz = App\\Models\\Profile::where('username', '${KONTO}')->value('user_id');
    $obcy = App\\Models\\User::where('id', '!=', $widz)->where('status', 'active')->value('id');

    $zZapisami = App\\Models\\Post::where('author_id', $widz)
        ->whereNull('recipe_id')->whereNotNull('published_at')
        ->whereDoesntHave('tags')->latest('published_at')->first();

    if (! $zZapisami) {
        $zZapisami = App\\Models\\Post::create([
            'author_id' => $widz,
            'body' => null,
            'visibility' => App\\Models\\Post::VISIBILITY_PUBLIC,
            'status' => App\\Models\\Post::STATUS_PUBLISHED,
            'published_at' => now()->subMinute(),
        ]);
    }

    if ($zZapisami->media()->count() === 0) {
        $wlasne = App\\Models\\Media::create([
            'owner_id' => $widz,
            'disk' => 'public',
            'object_key' => 'media/pomiar/'.Illuminate\\Support\\Str::uuid()->toString().'.webp',
            'mime_type' => 'image/webp',
            'bytes' => 180000,
            'width' => 1600,
            'height' => 1200,
            'status' => App\\Models\\Media::STATUS_READY,
            'alt_text' => 'Zdjęcie dania (dane pomiarowe)',
            'metadata' => ['variants' => []],
        ]);
        $zZapisami->media()->attach($wlasne->getKey(), ['position' => 0]);
    }

    if ($obcy) {
        $zeszyt = App\\Models\\Collection::firstOrCreate(
            ['owner_id' => $obcy, 'name' => 'Zeszyt pomiarowy'],
            ['visibility' => 'private', 'is_default' => false],
        );
        $zeszyt->posts()->syncWithoutDetaching([$zZapisami->getKey()]);
    }

    echo $wpis->getKey();
  `;

  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: env() })
    .toString().trim();

  if (wynik === '' || wynik.includes('BRAK-PRZEPISU')) {
    throw new Error(
      'Nie udało się przygotować wpisu wskazującego przepis. Bez niego pasek '
      + '„Z przepisu" w ogóle się nie renderuje, a pomiar opisywałby inną kartę.\n'
      + `Wyjście tinkera: ${wynik}`,
    );
  }

  return wynik;
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
    przygotujWpisZPrzepisem();

    return { adres: process.env.ADRES, zamknij: () => {} };
  }

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  /* ARKUSZ JEST ZBUDOWANY, NIE CZYTANY Z resources/. Strona wciąga
     `public/build/assets/app-*.css` przez manifest Vite, więc pomiar bez
     przebudowania opisywałby POPRZEDNIĄ wersję CSS — czyli po poprawce
     pokazywałby te same liczby co przed nią i wyglądałoby to na nieskuteczną
     zmianę. Build trwa sekundę; zgadywanie, czy manifest jest świeży, kosztuje
     więcej. */
  console.log('Buduję arkusz (vite build)...');
  execFileSync('npm', ['run', 'build'], { stdio: 'ignore', env: process.env });

  console.log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });
  przygotujWpisZPrzepisem();

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
 * POMIAR. Dla każdej karty wpisu w kolumnie głównej bierzemy jej BEZPOŚREDNIE
 * dzieci (to one są blokami karty: nagłówek, treść, zdjęcie, pasek przepisu,
 * tematy, liczba zapisów, pasek akcji) i liczymy przerwę między każdą parą
 * sąsiadów.
 *
 * `next.top - prev.bottom`, a nie odczyt `margin-top` ze stylów: marginesy
 * się zlewają albo sumują zależnie od układu, a `padding` sąsiada też jest
 * częścią widzianej przerwy. Liczy się to, co widzi człowiek.
 */
const POMIAR = () => {
  const opis = (el) => {
    const klasy = [...el.classList];
    const wlasna = klasy.find((k) => k.startsWith('post-card-'))
      ?? klasy.find((k) => ['photo-grid', 'karuzela', 'kolaz', 'chipsy'].includes(k));

    return wlasna ?? `${el.tagName.toLowerCase()}.${klasy.join('.')}`;
  };

  const karty = [];

  for (const karta of document.querySelectorAll('article.post-card')) {
    const dzieci = [...karta.children].filter((el) => {
      const p = el.getBoundingClientRect();

      return p.width > 0 || p.height > 0;
    });

    const pary = [];

    /* PRZERWA LICZONA MIĘDZY TREŚCIĄ, NIE MIĘDZY RAMKAMI.
       Bloki tej karty trzymają swój odstęp w `padding-bottom`, a padding leży
       W ŚRODKU pudełka: dolna krawędź pudełka nagłówka pokrywa się więc
       z górną krawędzią pudełka treści i różnica ramek wychodzi 0 także tam,
       gdzie człowiek widzi 16 px przerwy. Liczymy od ostatniego piksela, na
       którym coś stoi, do pierwszego, na którym znów coś stoi. */
    const dolTresci = (el) => {
      const p = el.getBoundingClientRect();
      const cs = getComputedStyle(el);

      return p.bottom - parseFloat(cs.paddingBottom) - parseFloat(cs.borderBottomWidth);
    };

    const gornaTresci = (el) => {
      const p = el.getBoundingClientRect();
      const cs = getComputedStyle(el);

      return p.top + parseFloat(cs.paddingTop) + parseFloat(cs.borderTopWidth);
    };

    const zaokr = (x) => Math.round(x * 100) / 100;

    for (let i = 1; i < dzieci.length; i++) {
      const wyzej = dzieci[i - 1];
      const nizej = dzieci[i];

      pary.push({
        para: `${opis(wyzej)} → ${opis(nizej)}`,
        przerwa: zaokr(gornaTresci(nizej) - dolTresci(wyzej)),
        ramki: zaokr(nizej.getBoundingClientRect().top - wyzej.getBoundingClientRect().bottom),
      });
    }

    karty.push({
      zPaskiemPrzepisu: karta.querySelector('.post-card-recipe') !== null,
      tytul: karta.querySelector('.post-card-recipe a')?.textContent?.trim() ?? null,
      pary,
    });
  }

  return karty;
};

const CHROMIUM = znajdzChromium();
const { adres, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });
let znalazlemPasek = false;

try {
  for (const widok of SZEROKOSCI) {
    const kontekst = await przegladarka.newContext({
      viewport: { width: widok.szerokosc, height: widok.wysokosc },
    });
    const strona = await kontekst.newPage();

    await strona.goto(`${adres}/login`);
    await strona.fill('input[name="login"]', KONTO);
    await strona.fill('input[name="password"]', HASLO);
    await Promise.all([
      strona.waitForURL((u) => ! u.pathname.endsWith('/login'), { timeout: 15000 }),
      strona.click('button[type="submit"]'),
    ]);

    await strona.goto(`${adres}/home`);
    await strona.waitForSelector('article.post-card');

    const karty = await strona.evaluate(POMIAR);

    console.log(`\n=== ${widok.nazwa} · /home ===`);

    for (const karta of karty) {
      if (karta.zPaskiemPrzepisu) znalazlemPasek = true;

      console.log(karta.zPaskiemPrzepisu
        ? `\n  KARTA Z PASKIEM „Z przepisu · ${karta.tytul}"`
        : '\n  karta bez paska przepisu (dla porównania)');

      for (const { para, przerwa, ramki } of karta.pary) {
        const uwaga = przerwa === 0 ? '   <-- ZERO, bloki się stykają' : '';
        console.log(`    ${para.padEnd(46)} treść ${String(przerwa).padStart(6)} px`
          + `   ramki ${String(ramki).padStart(6)} px${uwaga}`);
      }
    }

    await kontekst.close();
  }
} finally {
  await przegladarka.close();
  zamknij();
}

/*
 * PUSTY EKRAN PRZECHODZI KAŻDY POMIAR. Brak paska „Z przepisu" na zmierzonej
 * stronie znaczy, że mierzyliśmy NIE TĘ kartę, o którą było zgłoszenie —
 * i wtedy zero par z wynikiem 0 px nie mówi niczego (D-099, D-106).
 */
if (! znalazlemPasek) {
  console.error('\nBŁĄD: na żadnej zmierzonej karcie nie było paska „Z przepisu" — '
    + 'a to jest blok, o który poszło zgłoszenie. Pomiar nic nie znaczy.');
  process.exit(1);
}
