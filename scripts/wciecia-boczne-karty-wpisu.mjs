/*
 * =============================================================================
 *  Kuking.pl — pomiar WCIĘĆ BOCZNYCH bloków karty wpisu
 * =============================================================================
 *
 *  PO CO TO JEST
 *  `scripts/odstepy-karty-wpisu.mjs` (PR #405) mierzył przerwy PIONOWE między
 *  blokami karty. Przy okazji tamtej naprawy widać było drugą usterkę, wtedy
 *  świadomie pominiętą jako inny problem: chipsy z tagami wpisu
 *  (`.post-card-tagi`) dochodzą do samej krawędzi karty, a każdy inny blok tej
 *  karty ma 20 px wcięcia z obu stron. Ten skrypt zamienia „dochodzą do
 *  krawędzi" na liczby.
 *
 *  CO LICZYMY
 *  Dla każdego bezpośredniego dziecka karty: odległość od lewej krawędzi karty
 *  do pierwszego piksela, na którym coś stoi, i symetrycznie z prawej. Czytamy
 *  KRAWĘDŹ TREŚCI, nie krawędź pudełka — wcięcia tej karty siedzą w `padding`,
 *  a padding leży wewnątrz pudełka, więc różnica samych ramek wychodziłaby zero
 *  także tam, gdzie człowiek widzi 20 px marginesu (ta sama pułapka co przy
 *  pomiarze pionowym).
 *
 *  BLOK ZDJĘĆ MA WYCHODZIĆ NA KRAWĘDŹ — i wypisujemy go osobno, żeby zero przy
 *  nim nie wyglądało na usterkę. To jest jedyny blok, dla którego zero jest
 *  zamierzone (`.post-card { padding: 0; overflow: hidden }`).
 *
 *  STAN EKRANU: CHIPSY RENDERUJĄ SIĘ WARUNKOWO (D-099, D-106)
 *  `components/post-card.blade.php` pokazuje tagi tylko wtedy, gdy relacja
 *  tagów jest DOŁADOWANA (`$post->relationLoaded('tags')`). `DemoSeeder` nie
 *  dokłada wpisowi tagów, więc pomiar samego demo opisywałby kartę, na której
 *  mierzonego bloku NIE MA, i meldowałby, że wszystko w porządku. Dlatego
 *  skrypt sam dokłada wpis z tagami (tinkerem, nie zmianą cudzego seedera)
 *  i ZATRZYMUJE SIĘ Z BŁĘDEM, jeśli na zmierzonej stronie nie zobaczył ani
 *  jednego bloku `.post-card-tagi`.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/wciecia-boczne-karty-wpisu.mjs
 *      ADRES=http://127.0.0.1:8123 node scripts/wciecia-boczne-karty-wpisu.mjs
 *
 *  Bez `ADRES` skrypt sam sieje bazę `kuking_css_porzadki`, podnosi
 *  `php artisan serve` i sam go gasi. `config:clear` idzie PRZED serwerem:
 *  zapamiętana konfiguracja (`config:cache`) wskazywałaby inną bazę, niż ta,
 *  którą właśnie zasialiśmy.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa. NIE `kuking` (deweloperska) i NIE `kuking_test*`
   (na nich chodzi `php artisan test`) — ten skrypt robi `migrate:fresh`. */
const BAZA_DOMYSLNA = 'kuking_css_porzadki';

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
  return { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA };
}

/*
 * WPIS Z TAGAMI, ZE ZDJĘCIEM I Z SĄSIADAMI DO PORÓWNANIA.
 *
 * Karta ma pokazać naraz: nagłówek, treść, zdjęcie, pasek „Z przepisu",
 * chipsy z tagami i pasek akcji — dopiero wtedy widać, że jeden blok wypada
 * z rytmu bocznego, a nie że cała karta ma inne wcięcie.
 *
 * Zwracamy slug pierwszego tagu: strona tagu (`/tag/{slug}`) to drugi ekran,
 * na którym chipsy się renderują (`TagController::show` ładuje `tags:id,…`),
 * i chcemy zmierzyć oba, żeby liczby nie zależały od jednego szablonu.
 */
function przygotujWpisZTagami() {
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
            'body' => 'Wyszło dokładnie tak, jak w przepisie. Polecam każdemu.',
            'visibility' => App\\Models\\Post::VISIBILITY_PUBLIC,
            'status' => App\\Models\\Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /* Pozycja w pivocie jest OBOWIĄZKOWA — tabela post_tags ma unikat na parze
       (post_id, position), a kolejność tagów jest wyborem autora. */
    $pozycje = [];
    $tagi = App\\Models\\Tag::limit(3)->get();
    foreach ($tagi->pluck('id')->all() as $i => $id) {
        $pozycje[$id] = ['position' => $i];
    }
    if ($pozycje) { $wpis->tags()->syncWithoutDetaching($pozycje); }

    echo $tagi->first()?->slug ?? 'BRAK-TAGU';
  `;

  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: env() })
    .toString().trim();

  if (wynik === '' || wynik.includes('BRAK-')) {
    throw new Error(
      'Nie udało się przygotować wpisu z tagami. Bez niego chipsy `.post-card-tagi` '
      + 'w ogóle się nie renderują, a pomiar opisywałby inną kartę.\n'
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
    return { adres: process.env.ADRES, slug: przygotujWpisZTagami(), zamknij: () => {} };
  }

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  /* ARKUSZ JEST ZBUDOWANY, NIE CZYTANY Z resources/. Strona wciąga
     `public/build/assets/app-*.css` przez manifest Vite, więc pomiar bez
     przebudowania opisywałby POPRZEDNIĄ wersję CSS — po poprawce pokazywałby
     te same liczby co przed nią i wyglądałoby to na nieskuteczną zmianę. */
  console.log('Buduję arkusz (vite build)...');
  execFileSync('npm', ['run', 'build'], { stdio: 'ignore', env: process.env });

  console.log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const slug = przygotujWpisZTagami();
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

    if (wstal) return { adres, slug, zamknij: () => proces.kill('SIGTERM') };

    proces.kill('SIGKILL');
    bledy.push(`  podejście ${podejscie}, port ${port}: `
      + (umarl !== null ? `proces zakończył się kodem ${umarl}` : 'brak odpowiedzi z /health')
      + (dziennik.length > 0 ? `\n${dziennik.join('').trimEnd()}` : ''));
  }

  throw new Error(`Nie udało się podnieść \`php artisan serve\`.\n${bledy.join('\n')}`);
}

/*
 * POMIAR. Dla każdej karty bierzemy jej BEZPOŚREDNIE dzieci i liczymy, ile
 * pikseli dzieli krawędź karty od pierwszego (odpowiednio ostatniego) piksela
 * treści tego bloku.
 *
 * Blok, który sam w sobie jest pojemnikiem bez `padding` (jak `.chipsy`),
 * ma krawędź treści równą krawędzi pudełka — i to jest dokładnie ten przypadek,
 * o który chodzi.
 */
const POMIAR = () => {
  const opis = (el) => {
    const klasy = [...el.classList];
    const wlasna = klasy.find((k) => k.startsWith('post-card-'))
      ?? klasy.find((k) => ['photo-grid', 'karuzela', 'kolaz', 'chipsy'].includes(k));

    return wlasna ?? `${el.tagName.toLowerCase()}.${klasy.join('.')}`;
  };

  const zaokr = (x) => Math.round(x * 100) / 100;
  const karty = [];

  for (const karta of document.querySelectorAll('article.post-card')) {
    const pudelkoKarty = karta.getBoundingClientRect();
    const csKarty = getComputedStyle(karta);

    /* Wnętrze karty: od jej krawędzi odejmujemy własną ramkę karty, bo blok
       i tak nie może stanąć na ramce. `padding` karty to obecnie 0 i o to
       w tej usterce chodzi — bloki mają nieść wcięcie same. */
    const lewaKarty = pudelkoKarty.left + parseFloat(csKarty.borderLeftWidth);
    const prawaKarty = pudelkoKarty.right - parseFloat(csKarty.borderRightWidth);

    const bloki = [];

    for (const el of karta.children) {
      const p = el.getBoundingClientRect();
      if (p.width === 0 && p.height === 0) continue;

      const cs = getComputedStyle(el);
      const trescLewa = p.left + parseFloat(cs.paddingLeft) + parseFloat(cs.borderLeftWidth);
      const trescPrawa = p.right - parseFloat(cs.paddingRight) - parseFloat(cs.borderRightWidth);

      bloki.push({
        nazwa: opis(el),
        lewe: zaokr(trescLewa - lewaKarty),
        prawe: zaokr(prawaKarty - trescPrawa),
        zamierzoneZero: ['photo-grid', 'karuzela', 'kolaz'].includes(opis(el)),
      });
    }

    karty.push({
      maTagi: karta.querySelector('.post-card-tagi') !== null,
      szerokosc: zaokr(pudelkoKarty.width),
      bloki,
    });
  }

  return karty;
};

const CHROMIUM = znajdzChromium();
const { adres, slug, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);
console.log(`Slug do strony tagu: ${slug}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });
let znalazlemTagi = false;

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

    for (const sciezka of ['/home', `/tag/${slug}`]) {
      await strona.goto(`${adres}${sciezka}`);
      await strona.waitForSelector('article.post-card');

      const karty = (await strona.evaluate(POMIAR)).filter((k) => k.maTagi);

      console.log(`\n=== ${widok.nazwa} · ${sciezka} ===`);

      if (karty.length === 0) {
        console.log('  (na tym ekranie nie ma karty z tagami)');
        continue;
      }

      znalazlemTagi = true;

      for (const karta of karty) {
        console.log(`\n  KARTA Z TAGAMI (szerokość karty ${karta.szerokosc} px)`);
        console.log(`    ${'blok'.padEnd(24)} ${'z lewej'.padStart(9)} ${'z prawej'.padStart(9)}`);

        for (const blok of karta.bloki) {
          const uwaga = blok.lewe === 0 || blok.prawe === 0
            ? (blok.zamierzoneZero ? '   (zero zamierzone: zdjęcie idzie na krawędź)' : '   <-- ZERO, blok dotyka krawędzi karty')
            : '';
          console.log(`    ${blok.nazwa.padEnd(24)} ${String(blok.lewe).padStart(9)} ${String(blok.prawe).padStart(9)}${uwaga}`);
        }
      }
    }

    await kontekst.close();
  }
} finally {
  await przegladarka.close();
  zamknij();
}

/*
 * PUSTY EKRAN PRZECHODZI KAŻDY POMIAR. Brak bloku `.post-card-tagi` na
 * zmierzonych stronach znaczy, że mierzyliśmy NIE TĘ kartę, o którą chodzi —
 * i wtedy żadna z wypisanych liczb nie mówi niczego (D-099, D-106).
 */
if (! znalazlemTagi) {
  console.error('\nBŁĄD: na żadnej zmierzonej karcie nie było bloku `.post-card-tagi` — '
    + 'a to jest blok, o który poszło zgłoszenie. Pomiar nic nie znaczy.');
  process.exit(1);
}
