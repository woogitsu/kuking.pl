/*
 * =============================================================================
 *  Kuking.pl — pomiar ZDJĘCIA PROFILOWEGO (issue #448)
 * =============================================================================
 *
 *  PO CO TO JEST
 *  Zgłoszenie brzmi: „zdjęcie profilowe dalej pokazuje napis zamiast twarzy”.
 *  To jest zdanie o wrażeniu. Ten skrypt zamienia je na liczby: czy w miejscu
 *  awatara stoi `<img class="avatar">` (twarz), czy `<span class="avatar">`
 *  (inicjał), czy obrazek NAPRAWDĘ się wczytał (`naturalWidth > 0`), jakim
 *  kodem HTTP odpowiedziała trasa zdjęcia i jakie zdanie stoi pod awatarem
 *  na `/ustawienia/zdjecie`.
 *
 *  TRZY STANY WIERSZA `media`, BO O NIE CAŁY SPÓR
 *    pending  — zaraz po wgraniu: wariant JEST, status wiersza jeszcze nie
 *               `ready`. To jest stan z zgłoszenia.
 *    deleted  — wiersz przejęty do skasowania: wariant JESZCZE jest
 *               (`KasujZdjecie` kasuje pliki po oznaczeniu wiersza), ale
 *               pokazać go nie wolno. Pilnuje tego, żeby reguła się ZWĘZIŁA.
 *    ready    — stan docelowy. Kontrola, że poprawka niczego nie zabrała.
 *
 *  DLACZEGO TYLE SZEROKOŚCI
 *  Warunek właściciela dla całej serii usterek mobilnych: sprawdzać na
 *  różnych rozdzielczościach. Stąd 320 / 360 / 390 / 414 px, każda również
 *  przy czcionce przeglądarki 200%.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/zdjecie-profilowe.mjs
 *      PORT=8101 node scripts/zdjecie-profilowe.mjs
 *
 *  Skrypt sam sieje własną bazę, buduje arkusz, podnosi `php artisan serve`
 *  i sam go gasi.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { ustalBazePomiarowa } from './bezpiecznik-bazy.mjs';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa — ten skrypt robi `migrate:fresh`. Wskazanie
   `kuking` albo `kuking_test` kasowałoby czyjąś pracę (AGENTS.md §6). */
const BAZA_DOMYSLNA = 'kuking_zdjecie_profilowe';

/* BEZPIECZNIK: ten skrypt robi `migrate:fresh`, czyli KASUJE zawartosc
   bazy. `ustalBazePomiarowa()` wpuszcza wylacznie jednorazowa baze pomiarowa
   i ODMAWIA startu przy nazwie, ktorej nie rozpoznaje — nie wiem, czyja to
   baza, wiec jej nie kasuje (scripts/bezpiecznik-bazy.mjs). Liczone RAZ, na
   starcie: odmowa ma paść, zanim skrypt cokolwiek zbuduje albo podniesie. */
const BAZA_POMIAROWA = ustalBazePomiarowa({
  domyslna: BAZA_DOMYSLNA,
  skrypt: 'scripts/zdjecie-profilowe.mjs',
});

const BAZOWA_CZCIONKA_PX = 16;

const SZEROKOSCI = [
  { nazwa: '320 px', szerokosc: 320, wysokosc: 844 },
  { nazwa: '360 px', szerokosc: 360, wysokosc: 800 },
  { nazwa: '390 px', szerokosc: 390, wysokosc: 844 },
  { nazwa: '414 px', szerokosc: 414, wysokosc: 896 },
];

const STANY = ['pending', 'deleted', 'ready'];

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

function tinker(php) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: env() }).toString().trim();
}

/*
 * ZDJĘCIE PROFILOWE W ZADANYM STANIE — z PRAWDZIWYM PLIKIEM WARIANTU.
 *
 * Plik musi istnieć naprawdę, bo pomiar pyta przeglądarkę o `naturalWidth`:
 * `<img>` wskazujący na nieistniejący plik też jest `<img>`, więc sama jego
 * obecność w HTML-u nie dowodzi, że człowiek widzi twarz. Rysujemy prosty
 * znak w GD i zapisujemy jako WebP — dokładnie tak, jak robi to
 * `ProcessUploadedImage`.
 *
 * ORYGINAŁ zapisujemy pod `incoming/`, wariant pod `media/` — ten sam
 * rozdział, co w potoku produkcyjnym. Dzięki temu pomiar umie odpowiedzieć
 * także na pytanie „czy klucz oryginału gdzieś wypłynął”.
 */
function ustawZdjecie(status) {
  const php = `
    $profil = App\\Models\\Profile::where('username', '${KONTO}')->firstOrFail();

    $obraz = imagecreatetruecolor(320, 320);
    imagefilledrectangle($obraz, 0, 0, 320, 320, imagecolorallocate($obraz, 214, 104, 46));
    imagefilledellipse($obraz, 160, 120, 150, 150, imagecolorallocate($obraz, 250, 232, 214));
    imagefilledellipse($obraz, 160, 300, 260, 200, imagecolorallocate($obraz, 250, 232, 214));
    ob_start();
    imagewebp($obraz, null, 82);
    $bajty = ob_get_clean();

    $kluczWariantu = 'media/pomiar/448/twarz_thumb.webp';
    $kluczOryginalu = 'incoming/pomiar/448/oryginal-z-exifem.jpg';

    Illuminate\\Support\\Facades\\Storage::disk('public')->put($kluczWariantu, $bajty);
    Illuminate\\Support\\Facades\\Storage::disk('public')->put($kluczOryginalu, $bajty);

    $zdjecie = App\\Models\\Media::updateOrCreate(
        ['object_key' => $kluczOryginalu],
        [
            'owner_id' => $profil->user_id,
            'disk' => 'public',
            'variants_disk' => 'public',
            'mime_type' => 'image/webp',
            'bytes' => strlen($bajty),
            'width' => 320,
            'height' => 320,
            'status' => '${status}',
            'metadata' => ['variants' => ['thumb' => ['key' => $kluczWariantu, 'width' => 320, 'height' => 320]]],
        ],
    );

    $profil->forceFill(['avatar_media_id' => $zdjecie->getKey()])->save();

    echo $zdjecie->status.'|'.$zdjecie->getKey().'|'.$kluczWariantu.'|'.$kluczOryginalu;
  `;
  const wynik = tinker(php).split('\n').at(-1).trim();
  const [ustawiony, id, kluczWariantu, kluczOryginalu] = wynik.split('|');

  if (ustawiony !== status) {
    throw new Error(`Nie udało się ustawić stanu „${status}". Wyjście tinkera: ${wynik}`);
  }

  return { id, kluczWariantu, kluczOryginalu };
}

async function podniesSerwer() {
  if (process.env.ADRES) return { adres: process.env.ADRES, zamknij: () => {} };

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  console.log('Buduję arkusz (vite build)...');
  execFileSync('npm', ['run', 'build:assets'], { stdio: 'ignore', env: process.env });

  /* Bez dowiązania `public/storage` wariant leży na dysku, ale nie ma spod
     jakiego adresu go wziąć — a pomiar pyta właśnie o to, czy obrazek się
     wczytał. */
  console.log('Dowiązuję public/storage...');
  execFileSync('php', ['artisan', 'storage:link'], { stdio: 'ignore', env: env() });

  console.log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const port = Number(process.env.PORT || 8101);
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

  for (let i = 0; i < 60 && umarl === null; i++) {
    try {
      const odp = await fetch(`${adres}/health`);
      if (odp.ok) return { adres, zamknij: () => proces.kill('SIGTERM') };
    } catch { /* jeszcze nie wstał */ }
    await new Promise((r) => setTimeout(r, 500));
  }

  proces.kill('SIGKILL');
  throw new Error(`Nie udało się podnieść \`php artisan serve\` na porcie ${port}.\n${dziennik.join('')}`);
}

/*
 * POMIAR. Wszystko czytane po ułożeniu strony — liczy się to, co widzi
 * człowiek, a nie deklaracja w arkuszu.
 *
 * `ksztalt` rozróżnia dwa warianty komponentu `x-avatar`: `<img class="avatar">`
 * (twarz) i `<span class="avatar">` (inicjał). `wczytany` pyta przeglądarkę,
 * czy w tym `<img>` NAPRAWDĘ są piksele — bez tego pomiar mylił „jest
 * znacznik" z „widać zdjęcie".
 */
const POMIAR = () => {
  const zaokr = (x) => Math.round(x * 100) / 100;

  const opisz = (korzen) => {
    if (! korzen) return null;

    const obrazek = korzen.querySelector('img.avatar');
    const inicjal = korzen.querySelector('span.avatar');
    const el = obrazek ?? inicjal;

    if (! el) return { ksztalt: 'brak', wczytany: null, src: null, bok: null };

    const p = el.getBoundingClientRect();

    return {
      ksztalt: obrazek ? 'zdjecie' : 'inicjal',
      wczytany: obrazek ? (obrazek.naturalWidth > 0) : null,
      src: obrazek ? obrazek.getAttribute('src') : null,
      bok: `${zaokr(p.width)}×${zaokr(p.height)}`,
    };
  };

  const opis = document.querySelector('.zdjecie-profilowe-opis');
  const przyciskUsun = [...document.querySelectorAll('button, summary, a')]
    .some((el) => el.textContent.trim() === 'Usuń zdjęcie');

  return {
    tresc: opisz(document.querySelector('main')),
    pasek: opisz(document.querySelector('header.topbar')),
    napis: opis ? opis.textContent.trim().replace(/\s+/g, ' ') : null,
    przyciskUsun,
    szerokoscOkna: window.innerWidth,
    szerokoscDokumentu: document.documentElement.scrollWidth,
  };
};

const CHROMIUM = znajdzChromium();
const { adres, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/*
 * LOGUJEMY SIĘ RAZ, A POTEM PRZENOSIMY CIASTECZKO.
 * Ten pomiar ma 48 odsłon (trzy stany × cztery szerokości × dwa rozmiary
 * pisma × dwa ekrany). Logowanie w każdej z nich to seria prób z jednego
 * adresu, a serwis ma na to `throttle` i po kilku odpowiada ekranem
 * „Za dużo prób" — pomiar leciałby wtedy na ekranie blokady.
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
  for (const stan of STANY) {
    const { kluczWariantu, kluczOryginalu } = ustawZdjecie(stan);

    for (const widok of SZEROKOSCI) {
      for (const czcionka of [null, 200]) {
        for (const [ekran, sciezka] of [['/ustawienia/zdjecie', '/ustawienia/zdjecie'], [`/@${KONTO}`, `/@${KONTO}`]]) {
          const kontekst = await przegladarka.newContext({
            viewport: { width: widok.szerokosc, height: widok.wysokosc },
            storageState: sesja,
          });
          const strona = await kontekst.newPage();

          /* Kod odpowiedzi trasy zdjęcia — bo `<img>` z adresem, spod którego
             przychodzi 403, wygląda w HTML-u dokładnie tak samo jak działający. */
          const kodyZdjec = [];
          strona.on('response', (odp) => {
            if (odp.url().includes('/zdjecia/') || odp.url().includes('/storage/media/')) {
              kodyZdjec.push(odp.status());
            }
          });

          if (czcionka === 200) {
            const cdp = await kontekst.newCDPSession(strona);

            await cdp.send('Page.setFontSizes', {
              fontSizes: { standard: 2 * BAZOWA_CZCIONKA_PX, fixed: 2 * BAZOWA_CZCIONKA_PX },
            });
          }

          const odp = await strona.goto(`${adres}${sciezka}`, { waitUntil: 'networkidle' });

          if ((odp?.status() ?? 0) !== 200) {
            console.error(`BŁĄD: ${sciezka} odpowiedziało kodem ${odp?.status()}.`);
            bylBlad = true;
            await kontekst.close();
            continue;
          }

          if (czcionka === 200) {
            const korzen = await strona.evaluate(
              () => Number.parseFloat(getComputedStyle(document.documentElement).fontSize),
            );

            if (korzen < 2 * BAZOWA_CZCIONKA_PX) {
              console.error(`BŁĄD: czcionka korzenia to ${korzen} px — wariant 200% nie zadziałał.`);
              bylBlad = true;
            }
          }

          const wynik = await strona.evaluate(POMIAR);
          const html = await strona.content();

          wiersze.push({
            stan,
            widok: widok.nazwa,
            czcionka: czcionka ? '200%' : '100%',
            ekran,
            kodyZdjec: [...new Set(kodyZdjec)].sort().join(',') || '—',
            oryginalWDokumencie: html.includes(kluczOryginalu),
            wariantWDokumencie: html.includes(kluczWariantu),
            ...wynik,
          });

          await kontekst.close();
        }
      }
    }
  }
} finally {
  await przegladarka.close();
  zamknij();
}

console.log('\nstan      okno     czc.  ekran                 treść             pasek             kod zdjęcia  wyjazd');
console.log('-'.repeat(112));

for (const w of wiersze) {
  const opisz = (m) => {
    if (! m) return '—';
    if (m.ksztalt !== 'zdjecie') return m.ksztalt;

    return `zdjęcie ${m.wczytany ? 'OK' : 'PUSTE'}`;
  };

  console.log(
    w.stan.padEnd(10)
    + w.widok.padEnd(9)
    + w.czcionka.padEnd(6)
    + w.ekran.padEnd(22)
    + opisz(w.tresc).padEnd(18)
    + opisz(w.pasek).padEnd(18)
    + w.kodyZdjec.padEnd(13)
    + (w.szerokoscDokumentu > w.szerokoscOkna ? `TAK (${w.szerokoscDokumentu} > ${w.szerokoscOkna})` : 'nie'),
  );
}

console.log('\nZDANIE POD AWATAREM na /ustawienia/zdjecie (390 px, 100%):');
for (const stan of STANY) {
  const w = wiersze.find((x) => x.stan === stan && x.widok === '390 px' && x.czcionka === '100%' && x.ekran === '/ustawienia/zdjecie');

  console.log(`  ${stan.padEnd(9)} ${w ? `„${w.napis}"` : '—'}`);
  console.log(`  ${''.padEnd(9)} przycisk „Usuń zdjęcie": ${w ? (w.przyciskUsun ? 'JEST' : 'nie ma') : '—'}`);
}

/* ORYGINAŁ NIE MA PRAWA NIGDZIE TRAFIĆ — w jego EXIF-ie siedzi lokalizacja
   kuchni. To jest asercja, nie statystyka: jedno trafienie unieważnia wynik. */
const wycieki = wiersze.filter((w) => w.oryginalWDokumencie);

console.log(`\nKlucz ORYGINAŁU w dokumencie: ${wycieki.length} z ${wiersze.length} odsłon`);

if (wycieki.length > 0) {
  console.error('BŁĄD: klucz oryginału wypłynął do dokumentu.');
  bylBlad = true;
}

/* PRZEWIJANIE W POZIOMIE TO NARUSZENIE, A NIE STATYSTYKA (WCAG 1.4.10).

   Kolumna „wyjazd" była dotąd samym napisem w tabeli: pierwszy przebieg po
   scaleniu #430 pokazał `333 > 320` na `/ustawienia/zdjecie` przy czcionce
   200%, a skrypt mimo to skończył się kodem 0. Liczba, której nikt nie musi
   przeczytać, żeby pomiar „przeszedł", jest liczbą do przeoczenia — tym
   bardziej że tego ekranu nie ma w `scripts/dostepnosc.mjs`, więc bramka
   przed PR-em też go nie mierzy. Od teraz wyjazd oblewa. */
const wyjazdy = wiersze.filter((w) => w.szerokoscDokumentu > w.szerokoscOkna);

console.log(`Wyjazd strony w bok: ${wyjazdy.length} z ${wiersze.length} odsłon`);

if (wyjazdy.length > 0) {
  console.error('BŁĄD: strona przewija się w poziomie — WCAG 2.2 AA, 1.4.10 (Reflow).');
  for (const w of wyjazdy) {
    console.error(`  ${w.stan} / ${w.widok} / ${w.czcionka} / ${w.ekran}: `
      + `${w.szerokoscDokumentu} > ${w.szerokoscOkna}`);
  }
  bylBlad = true;
}

/* Pusty ekran przechodzi każdy pomiar (pułapka 2). Gdyby awatara nie było
   w ogóle, wszystkie wiersze mówiłyby „brak" i wyglądałoby to jak wynik. */
if (wiersze.every((w) => w.tresc?.ksztalt === 'brak')) {
  console.error('BŁĄD: na żadnej odsłonie nie było awatara w treści — mierzyliśmy nie ten ekran.');
  bylBlad = true;
}

if (bylBlad) process.exit(1);
