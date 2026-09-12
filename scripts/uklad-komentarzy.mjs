/*
 * =============================================================================
 *  Kuking.pl — pomiar UKŁADU KOMENTARZY (issue #433)
 * =============================================================================
 *
 *  PO CO TO JEST
 *  Dwa zgłoszenia właściciela ze zrzutów z telefonu:
 *
 *    1. „odpowiedz i popraw jest jedno pod drugim, gdzie jest jednak miejsce
 *       by dać obok siebie"
 *    2. „patrz ile miejsca nad «napisz komentarz» a żadnego między «też jest
 *       w porządku» a polem do pisania. I te wyślij komentarz może dać po
 *       prawej stronie"
 *
 *  Oba są zdaniami o wrażeniu. Ten skrypt zamienia je na liczby, które da się
 *  porównać przed zmianą i po:
 *
 *    * wysokość, jaką blok akcji zabiera POD treścią komentarza, i wysokość
 *      samej treści — bo zgłoszenie mówi „obsługa zajmuje więcej miejsca niż
 *      treść", a to jest twierdzenie sprawdzalne;
 *    * wymiary KAŻDEGO celu dotykowego (AGENTS.md §5: minimum 48 px);
 *    * liczbę WIERSZY, w jakich stoją akcje — liczona z pozycji pionowych,
 *      nie z klas w HTML-u;
 *    * odstępy formularza: nad etykietą „Napisz komentarz", między podpowiedzią
 *      a polem, i pod polem;
 *    * `scrollWidth` dokumentu kontra szerokość okna oraz nachodzenie celów
 *      dotykowych na siebie — czyli oba warunki z „jak sprawdzić, że zrobione".
 *
 *  DWIE GAŁĘZIE, NIE JEDNA. Komentarz WŁASNY ma „Odpowiedz", „Popraw"
 *  i „Usuń"; komentarz CUDZY ma „Odpowiedz" i „Zgłoś". To są dwa różne układy
 *  i różne liczby akcji — pomiar jednego z nich opisywałby połowę ekranu
 *  (D-099, D-106). `DemoSeeder` daje tylko komentarz Ani, więc skrypt dokłada
 *  drugi — Marka — i PRZERYWA Z BŁĘDEM, jeśli na zmierzonej stronie nie
 *  znalazł obu.
 *
 *  „WYŚLIJ KOMENTARZ" PO LEWEJ CZY PO PRAWEJ — liczone, nie zgadywane.
 *  Skrypt podaje odległość poziomą od środka przycisku do lewego i prawego
 *  dolnego rogu ekranu, dla obu wariantów ustawienia. Wariant „po prawej"
 *  jest policzony arytmetycznie z krawędzi wnętrza formularza, więc nie
 *  wymaga zmiany arkusza, żeby dało się go porównać.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/uklad-komentarzy.mjs
 *      ADRES=http://127.0.0.1:8123 node scripts/uklad-komentarzy.mjs
 *      JSON=/tmp/przed.json node scripts/uklad-komentarzy.mjs
 *
 *  Bez `ADRES` skrypt sam sieje bazę `kuking_uklad_komentarzy`, podnosi
 *  `php artisan serve` i sam go gasi. `config:clear` idzie PRZED serwerem:
 *  zapamiętana konfiguracja wskazywałaby inną bazę niż ta, którą zasialiśmy.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, writeFileSync } from 'node:fs';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa. NIE `kuking` (deweloperska) i NIE baza testowa
   worktree — ten skrypt robi `migrate:fresh`, więc wskazanie którejkolwiek
   z nich kasowałoby czyjąś pracę. */
const BAZA_DOMYSLNA = 'kuking_uklad_komentarzy';

/* Rozmiar pisma przeglądarki uznawany za „zwykły". Chrome ma domyślnie 16 px
   i od tego liczy się „200%". */
const BAZOWA_CZCIONKA_PX = 16;

/*
 * SZEROKOŚCI. Właściciel powiedział wprost, że usterki mobilne mają być
 * sprawdzane na różnych rozdzielczościach — jedna szerokość pokazuje jeden
 * przypadek, a zawijanie zaczyna się dokładnie między nimi.
 *
 * 320 px to podłoga z WCAG 1.4.10 i z AGENTS.md §5. 360 px to najczęstszy
 * Android, 390 px to ekran ze zrzutu właściciela, 414 px to duży telefon.
 * Ostatni wariant to ten sam 320 px przy czcionce przeglądarki 200% — tam
 * napisy rosną, a szerokość nie, więc to jest najostrzejszy test zawijania.
 */
const WIDOKI = [
  { nazwa: '320 px', szerokosc: 320, wysokosc: 720 },
  { nazwa: '360 px', szerokosc: 360, wysokosc: 800 },
  { nazwa: '390 px', szerokosc: 390, wysokosc: 844 },
  { nazwa: '414 px', szerokosc: 414, wysokosc: 896 },
  { nazwa: '320 px + czcionka 200%', szerokosc: 320, wysokosc: 720, czcionka200: true },
  { nazwa: '390 px + czcionka 200%', szerokosc: 390, wysokosc: 844, czcionka200: true },
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
 * DRUGI KOMENTARZ — CUDZY, ŻEBY BYŁO CO ZMIERZYĆ W DRUGIEJ GAŁĘZI.
 *
 * `DemoSeeder` tworzy jeden komentarz: Ani pod rosołem Basi. Zalogowani jako
 * Ania widzimy więc WYŁĄCZNIE komentarz własny — z „Popraw" i „Usuń" — czyli
 * układ cudzego komentarza („Odpowiedz" + „Zgłoś") nie renderowałby się wcale,
 * a pomiar meldowałby, że wszystko policzone.
 *
 * Komentarz Marka jest świeży, ale to nie ma znaczenia dla widza: „Popraw"
 * zależy od `@can('update')`, a tej zgody Ania przy cudzym komentarzu nie ma.
 *
 * Zwracamy `slug` przepisu, bo to jego strona jest mierzona.
 */
function przygotujKomentarze() {
  const php = `
    $przepis = App\\Models\\Recipe::where('status','published')->orderBy('created_at')->first();
    if (! $przepis) { echo 'BRAK-PRZEPISU'; exit; }

    $marek = App\\Models\\User::whereHas('profile', fn ($q) => $q->where('username', 'marek'))->first();
    $ania  = App\\Models\\User::whereHas('profile', fn ($q) => $q->where('username', '${KONTO}'))->first();
    if (! $marek || ! $ania) { echo 'BRAK-KONTA'; exit; }

    $wlasny = App\\Models\\Comment::where('recipe_id', $przepis->getKey())
        ->where('author_id', $ania->getKey())->first();

    if (! $wlasny) {
        App\\Models\\Comment::create([
            'author_id' => $ania->getKey(),
            'recipe_id' => $przepis->getKey(),
            'body' => 'Pysznie wygląda',
            'status' => App\\Models\\Comment::STATUS_PUBLISHED,
        ]);
    }

    $cudzy = App\\Models\\Comment::where('recipe_id', $przepis->getKey())
        ->where('author_id', $marek->getKey())->first();

    if (! $cudzy) {
        App\\Models\\Comment::create([
            'author_id' => $marek->getKey(),
            'recipe_id' => $przepis->getKey(),
            'body' => 'Pysznie wygląda',
            'status' => App\\Models\\Comment::STATUS_PUBLISHED,
        ]);
    }

    echo $przepis->slug;
  `;

  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: env() })
    .toString().trim();

  if (wynik === '' || wynik.includes('BRAK-')) {
    throw new Error(
      'Nie udało się przygotować komentarzy do pomiaru.\n'
      + `Wyjście tinkera: ${wynik}`,
    );
  }

  return wynik.split('\n').pop().trim();
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
    return { adres: process.env.ADRES, slug: przygotujKomentarze(), zamknij: () => {} };
  }

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  /* ARKUSZ JEST ZBUDOWANY, NIE CZYTANY Z resources/. Bez przebudowania pomiar
     opisywałby POPRZEDNIĄ wersję CSS — czyli po poprawce pokazywałby te same
     liczby co przed nią. */
  console.log('Buduję arkusz (vite build)...');
  execFileSync('npm', ['run', 'build'], { stdio: 'ignore', env: process.env });

  console.log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const slug = przygotujKomentarze();
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

    if (wstal) return { adres, slug, zamknij: () => proces.kill('SIGTERM') };

    proces.kill('SIGKILL');
    bledy.push(`  podejście ${podejscie}, port ${port}: `
      + (umarl !== null ? `proces zakończył się kodem ${umarl}` : 'brak odpowiedzi z /health')
      + (dziennik.length > 0 ? `\n${dziennik.join('').trimEnd()}` : ''));
  }

  throw new Error(`Nie udało się podnieść \`php artisan serve\`.\n${bledy.join('\n')}`);
}

/*
 * POMIAR.
 *
 * Wszystko z `getBoundingClientRect()`, czyli z tego, co naprawdę stoi na
 * ekranie — a nie z odczytu `margin-top` ze stylów. Marginesy raz się zlewają,
 * a raz sumują (D-154, przyczyna nr 3), `padding` sąsiada też jest częścią
 * widzianej przerwy, a `<details>` zmienia wysokość bez zmiany klas.
 *
 * WIERSZE LICZONE Z POZYCJI, NIE Z HTML-A. Dwa cele dotykowe są „w jednym
 * wierszu", jeśli ich pasy pionowe zachodzą na siebie choć w połowie niższego
 * z nich. Liczba wierszy policzona tak jest odporna na to, czy układ robi to
 * `flex`-em, siatką, czy `inline-block`-iem — a to jest dokładnie ta liczba,
 * o którą poszło zgłoszenie.
 */
const POMIAR = () => {
  const zaokr = (x) => Math.round(x * 100) / 100;

  const wnetrzeGora = (el) => {
    const p = el.getBoundingClientRect();
    const cs = getComputedStyle(el);

    return p.top + parseFloat(cs.paddingTop) + parseFloat(cs.borderTopWidth);
  };

  /*
   * WNĘTRZE ZWINIĘTEGO `<details>` NIE JEST NA EKRANIE — ale Chromium i tak
   * daje mu prostokąt, i to zdegenerowany.
   *
   * ZMIERZONE, NIE ZAŁOŻONE. Pierwsza wersja tego pomiaru filtrowała cele
   * przez `width > 0 && height > 0` i meldowała przy 390 px sześć wierszy
   * akcji zamiast trzech, w tym przycisk „Tak, usuń" o wymiarach
   * 53,67 × 208 px — czyli napis złamany na jedną literę w wierszu. To jest
   * podpis `content-visibility: hidden`, którym nowe Chromium realizuje
   * zwinięty `<details>`: potomkowie mają pominiętą fazę układu, więc zwijają
   * się do szerokości minimalnej zamiast zniknąć.
   *
   * Gdyby to zostało, pomiar „przed" i „po" liczyłby przyciski, których
   * człowiek nie widzi, a poprawka wyglądałaby na skuteczniejszą, niż jest.
   */
  const wZwinietymDetails = (el) => {
    for (let d = el.closest('details'); d; d = d.parentElement?.closest('details')) {
      if (d.open) continue;

      const naglowek = d.querySelector(':scope > summary');

      /* Sam `<summary>` zwiniętego bloku JEST na ekranie — to on jest celem
         dotykowym. Wszystko poza nim w tym bloku nie jest. */
      if (! (naglowek && (naglowek === el || naglowek.contains(el)))) return true;
    }

    return false;
  };

  const widoczny = (el) => {
    if (wZwinietymDetails(el)) return false;

    const p = el.getBoundingClientRect();

    return p.width > 0 && p.height > 0;
  };

  /* Cele dotykowe komentarza: rozwijacze („Odpowiedz", „Popraw", „Usuń"
     z potwierdzeniem), łącza („Zgłoś") i zwykłe przyciski. Bierzemy tylko
     te, które stoją POD treścią danego komentarza, a nie te w środku
     rozwiniętego formularza (te przy zamkniętym `<details>` i tak nie
     istnieją na ekranie). */
  const celeW = (korzen) => [...korzen.querySelectorAll('summary.btn, a.btn, button.btn')]
    .filter(widoczny)
    .map((el) => {
      const p = el.getBoundingClientRect();

      return {
        napis: el.textContent.trim().replace(/\s+/g, ' '),
        x: zaokr(p.left),
        y: zaokr(p.top),
        szer: zaokr(p.width),
        wys: zaokr(p.height),
        dol: zaokr(p.bottom),
        prawo: zaokr(p.right),
      };
    })
    .sort((a, b) => (a.y - b.y) || (a.x - b.x));

  const wiersze = (cele) => {
    const grupy = [];

    for (const c of cele) {
      const g = grupy.find((grupa) => {
        const zachodzi = Math.min(grupa.dol, c.dol) - Math.max(grupa.y, c.y);

        return zachodzi >= Math.min(grupa.dol - grupa.y, c.wys) / 2;
      });

      if (g) {
        g.y = Math.min(g.y, c.y);
        g.dol = Math.max(g.dol, c.dol);
        g.napisy.push(c.napis);
      } else {
        grupy.push({ y: c.y, dol: c.dol, napisy: [c.napis] });
      }
    }

    return grupy.map((g) => g.napisy);
  };

  const nachodza = (cele) => {
    const pary = [];

    for (let i = 0; i < cele.length; i++) {
      for (let j = i + 1; j < cele.length; j++) {
        const a = cele[i]; const b = cele[j];
        const wPoziomie = Math.min(a.prawo, b.prawo) - Math.max(a.x, b.x);
        const wPionie = Math.min(a.dol, b.dol) - Math.max(a.y, b.y);

        if (wPoziomie > 0.5 && wPionie > 0.5) pary.push(`${a.napis} × ${b.napis}`);
      }
    }

    return pary;
  };

  const sekcja = document.querySelector('section[aria-labelledby="komentarze"]');

  if (! sekcja) return { blad: 'Na stronie nie ma sekcji komentarzy.' };

  const komentarze = [...sekcja.querySelectorAll(':scope > article.card')].map((karta) => {
    const tresc = karta.querySelector('p.tekst-jak-napisano, p.meta.italic');
    const cele = celeW(karta);
    const trescP = tresc?.getBoundingClientRect();
    const pierwszy = cele[0];
    const ostatni = cele.reduce((n, c) => (c.dol > n.dol ? c : n), cele[0]);
    const kartaP = karta.getBoundingClientRect();
    const kartaCs = getComputedStyle(karta);

    /* Kreska oddzielająca akcję nieodwracalną. Mierzymy, czy JEST i ile jej
       zostaje odstępu — to jest ten warunek z issue, który wolno złamać
       najłatwiej i najciszej. */
    const strefa = karta.querySelector('.danger-zone');
    const strefaCs = strefa ? getComputedStyle(strefa) : null;

    return {
      autorNapis: karta.querySelector('.author-name')?.textContent?.trim() ?? '?',
      trescWysokosc: trescP ? zaokr(trescP.height) : null,
      /* Ile miejsca zabiera OBSŁUGA komentarza: od ostatniego piksela treści
         do ostatniego piksela ostatniej akcji. To jest liczba, którą
         zgłoszenie porównuje z wysokością treści. */
      blokAkcjiOdTresci: (trescP && ostatni) ? zaokr(ostatni.dol - trescP.bottom) : null,
      blokAkcjiSam: (pierwszy && ostatni) ? zaokr(ostatni.dol - pierwszy.y) : null,
      kartaWysokosc: zaokr(kartaP.height),
      kartaWnetrzeSzer: zaokr(
        kartaP.width
        - parseFloat(kartaCs.paddingLeft) - parseFloat(kartaCs.paddingRight)
        - parseFloat(kartaCs.borderLeftWidth) - parseFloat(kartaCs.borderRightWidth),
      ),
      cele,
      wiersze: wiersze(cele),
      nachodzenia: nachodza(cele),
      kreskaUsun: strefa
        ? {
          jest: parseFloat(strefaCs.borderTopWidth) > 0,
          borderTop: strefaCs.borderTopWidth,
          marginTop: strefaCs.marginTop,
          paddingTop: strefaCs.paddingTop,
          /* Rzeczywista przerwa między dolną krawędzią ostatniej zwykłej
             akcji a górną krawędzią „Usuń" — to widzi palec, nie `margin`. */
          przerwaOdZwyklych: (() => {
            const strefaP = strefa.getBoundingClientRect();
            const zwykle = cele.filter((c) => c.dol <= strefaP.top + 0.5);
            const najnizsza = zwykle.reduce((n, c) => (c.dol > (n?.dol ?? -1) ? c : n), null);
            const usun = strefa.querySelector('summary.btn, a.btn, button.btn');

            return (najnizsza && usun)
              ? zaokr(usun.getBoundingClientRect().top - najnizsza.dol)
              : null;
          })(),
        }
        : null,
    };
  });

  /* --- Formularz „Napisz komentarz" ------------------------------------- */
  const formularz = sekcja.querySelector('form.panel-formularza');
  let form = null;

  if (formularz) {
    const etykieta = formularz.querySelector('label');
    const podpowiedz = formularz.querySelector('.field-help');
    const pole = formularz.querySelector('textarea.field-input');
    const przycisk = formularz.querySelector('button[type="submit"]');
    const cs = getComputedStyle(formularz);
    const p = formularz.getBoundingClientRect();
    const lewaWnetrza = p.left + parseFloat(cs.paddingLeft) + parseFloat(cs.borderLeftWidth);
    const prawaWnetrza = p.right - parseFloat(cs.paddingRight) - parseFloat(cs.borderRightWidth);
    const przyciskP = przycisk.getBoundingClientRect();

    form = {
      /* Odstęp NAD nagłówkiem: od pierwszego piksela wnętrza panelu do
         pierwszego piksela napisu „Napisz komentarz". `@csrf` renderuje
         ukryte pole, więc „pierwsze dziecko" formularza to NIE etykieta. */
      nadNaglowkiem: zaokr(etykieta.getBoundingClientRect().top - wnetrzeGora(formularz)),
      naglowekDoPodpisu: zaokr(
        podpowiedz.getBoundingClientRect().top - etykieta.getBoundingClientRect().bottom,
      ),
      podpisDoPola: zaokr(
        pole.getBoundingClientRect().top - podpowiedz.getBoundingClientRect().bottom,
      ),
      podPolem: zaokr(przyciskP.top - pole.getBoundingClientRect().bottom),
      podPrzyciskiem: zaokr(
        (p.bottom - parseFloat(cs.paddingBottom) - parseFloat(cs.borderBottomWidth))
        - przyciskP.bottom,
      ),
      poleWysokosc: zaokr(pole.getBoundingClientRect().height),
      przycisk: {
        napis: przycisk.textContent.trim(),
        szer: zaokr(przyciskP.width),
        wys: zaokr(przyciskP.height),
        srodekX: zaokr(przyciskP.left + przyciskP.width / 2),
        /* Wariant „po prawej" policzony z krawędzi wnętrza panelu — bez
           zmiany arkusza, więc oba warianty da się porównać jednym
           przebiegiem. */
        srodekXpoPrawej: zaokr(prawaWnetrza - przyciskP.width / 2),
      },
      wnetrzeLewa: zaokr(lewaWnetrza),
      wnetrzePrawa: zaokr(prawaWnetrza),
    };
  }

  return {
    okno: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    korzenCzcionka: zaokr(parseFloat(getComputedStyle(document.documentElement).fontSize)),
    komentarze,
    form,
  };
};

const CHROMIUM = znajdzChromium();
const { adres, slug, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);
console.log(`Mierzona strona: /przepisy/${slug}\n`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });
const wyniki = [];
let widzialemWlasny = false;
let widzialemCudzy = false;

/*
 * LOGUJEMY SIĘ RAZ, POTEM ODTWARZAMY CIASTECZKO.
 *
 * Logowanie w każdym z sześciu widoków wpadało w ogranicznik prób logowania —
 * szósty kontekst wisiał na `waitForURL` do timeoutu i pomiar kończył się
 * wyjątkiem po pięciu poprawnych przebiegach. Ogranicznik działa poprawnie;
 * to pomiar go wywoływał bez potrzeby.
 */
async function zalogujISchowajSesje() {
  const kontekst = await przegladarka.newContext({ viewport: { width: 390, height: 844 } });
  const strona = await kontekst.newPage();

  await strona.goto(`${adres}/login`);
  await strona.fill('input[name="login"]', KONTO);
  await strona.fill('input[name="password"]', HASLO);
  await Promise.all([
    strona.waitForURL((u) => ! u.pathname.endsWith('/login'), { timeout: 15000 }),
    strona.click('button[type="submit"]'),
  ]);

  const stan = await kontekst.storageState();

  await kontekst.close();

  return stan;
}

const SESJA = await zalogujISchowajSesje();

try {
  for (const widok of WIDOKI) {
    const kontekst = await przegladarka.newContext({
      viewport: { width: widok.szerokosc, height: widok.wysokosc },
      storageState: SESJA,
    });
    const strona = await kontekst.newPage();

    if (widok.czcionka200) {
      /* PRZED nawigacją i przez CDP, nie przez `style.fontSize` na korzeniu:
         podmiana przez CSSOM podwaja tekst, ale zostawia progi `rem`
         w media queries tam, gdzie były — czyli mierzy układ, w którym nikt
         nie jest (uzasadnienie w `scripts/dostepnosc.mjs`). */
      const cdp = await kontekst.newCDPSession(strona);

      await cdp.send('Page.setFontSizes', {
        fontSizes: { standard: 2 * BAZOWA_CZCIONKA_PX, fixed: 2 * BAZOWA_CZCIONKA_PX },
      });
    }

    const odp = await strona.goto(`${adres}/przepisy/${slug}`, { waitUntil: 'domcontentloaded' });

    if (odp.status() !== 200) {
      throw new Error(`Strona przepisu odpowiedziała kodem ${odp.status()} — pomiar nic nie znaczy.`);
    }

    await strona.waitForSelector('section[aria-labelledby="komentarze"]');
    await strona.evaluate(() => document.fonts.ready);

    const w = await strona.evaluate(POMIAR);

    if (w.blad) throw new Error(w.blad);

    /* Formularz „Napisz komentarz" renderuje się tylko dla zalogowanego.
       Jego brak znaczy, że odtworzona sesja nie zadziałała — a wtedy cały
       pomiar opisuje ekran gościa, na którym połowy zgłoszenia nie ma. */
    if (! w.form) {
      throw new Error('Na mierzonej stronie nie ma formularza „Napisz komentarz" — '
        + 'sesja się nie odtworzyła i mierzony byłby ekran gościa.');
    }

    if (widok.czcionka200 && w.korzenCzcionka < 2 * BAZOWA_CZCIONKA_PX) {
      throw new Error(
        `Czcionka korzenia to ${w.korzenCzcionka} px zamiast ${2 * BAZOWA_CZCIONKA_PX} px — `
        + 'wariant 200% nie został naprawdę ustawiony, więc jego wynik byłby fałszywy.',
      );
    }

    wyniki.push({ widok: widok.nazwa, ...w });

    console.log(`\n════ ${widok.nazwa}  (okno ${w.okno} px, korzeń ${w.korzenCzcionka} px) ════`);
    console.log(`  scrollWidth dokumentu: ${w.scrollWidth} px `
      + `${w.scrollWidth > w.okno ? '  <-- WYCHODZI POZA OKNO' : '(mieści się)'}`);

    for (const k of w.komentarze) {
      const napisy = k.cele.map((c) => c.napis);
      const wlasny = napisy.includes('Popraw');

      if (wlasny) widzialemWlasny = true;
      if (! wlasny && napisy.includes('Zgłoś')) widzialemCudzy = true;

      console.log(`\n  KOMENTARZ ${wlasny ? 'WŁASNY' : 'CUDZY'} (${k.autorNapis}), `
        + `wnętrze karty ${k.kartaWnetrzeSzer} px`);
      console.log(`    treść: ${k.trescWysokosc} px wysokości`);
      console.log(`    blok akcji od końca treści: ${k.blokAkcjiOdTresci} px `
        + `${k.blokAkcjiOdTresci > k.trescWysokosc ? '  <-- OBSŁUGA WIĘKSZA NIŻ TREŚĆ' : ''}`);
      console.log(`    same akcje (pierwsza góra → ostatnia dół): ${k.blokAkcjiSam} px`);
      console.log(`    wiersze akcji: ${k.wiersze.length}`);

      for (const wiersz of k.wiersze) console.log(`      • ${wiersz.join('  |  ')}`);

      for (const c of k.cele) {
        const zaMale = c.wys < 48 || c.szer < 48;

        console.log(`      ${c.napis.padEnd(12)} ${String(c.szer).padStart(7)} × `
          + `${String(c.wys).padStart(6)} px${zaMale ? '   <-- PONIŻEJ 48 px' : ''}`);
      }

      if (k.kreskaUsun) {
        console.log(`    „Usuń": kreska ${k.kreskaUsun.borderTop}, margin-top `
          + `${k.kreskaUsun.marginTop}, padding-top ${k.kreskaUsun.paddingTop}, `
          + `przerwa od zwykłych akcji ${k.kreskaUsun.przerwaOdZwyklych} px`);
      }

      if (k.nachodzenia.length) {
        console.log(`    NACHODZENIE CELÓW: ${k.nachodzenia.join(', ')}`);
      }
    }

    if (w.form) {
      const f = w.form;

      console.log('\n  FORMULARZ „Napisz komentarz"');
      console.log(`    nad nagłówkiem:        ${String(f.nadNaglowkiem).padStart(7)} px`);
      console.log(`    nagłówek → podpis:     ${String(f.naglowekDoPodpisu).padStart(7)} px`);
      console.log(`    podpis → pole:         ${String(f.podpisDoPola).padStart(7)} px`
        + `${f.podpisDoPola <= 2 ? '   <-- SKLEJONE' : ''}`);
      console.log(`    pod polem (→ przycisk):${String(f.podPolem).padStart(7)} px`);
      console.log(`    pod przyciskiem:       ${String(f.podPrzyciskiem).padStart(7)} px`);
      console.log(`    pole: ${f.poleWysokosc} px wysokości`);
      console.log(`    „${f.przycisk.napis}": ${f.przycisk.szer} × ${f.przycisk.wys} px`);
      console.log(`    wnętrze panelu: ${f.wnetrzeLewa} … ${f.wnetrzePrawa} px`);
      console.log(`    środek przycisku PO LEWEJ:  ${f.przycisk.srodekX} px  `
        + `(do lewego rogu ${zaokrG(f.przycisk.srodekX)} px, `
        + `do prawego ${zaokrG(w.okno - f.przycisk.srodekX)} px)`);
      console.log(`    środek przycisku PO PRAWEJ: ${f.przycisk.srodekXpoPrawej} px  `
        + `(do lewego rogu ${zaokrG(f.przycisk.srodekXpoPrawej)} px, `
        + `do prawego ${zaokrG(w.okno - f.przycisk.srodekXpoPrawej)} px)`);
    }

    /*
     * STAN ROZWINIĘTY, bo rząd `flex` zmienia szerokość swoich dzieci.
     *
     * Zwinięty `<details>` jest wąski jak jego `<summary>`. Gdyby rozwinięty
     * został zwykłym elementem rzędu, pole do pisania miałoby przy 390 px
     * około 140 px zamiast całej szerokości karty — czyli poprawka układu
     * akcji zepsułaby formularz odpowiedzi. Reguła `flex-basis: 100%` na
     * `details[open]` temu zapobiega i to jest tutaj mierzone.
     */
    await strona.click('section[aria-labelledby="komentarze"] .akcje-komentarza summary');

    const rozwiniete = await strona.evaluate(() => {
      const zaokr = (x) => Math.round(x * 100) / 100;
      const rzad = document.querySelector('section[aria-labelledby="komentarze"] .akcje-komentarza');
      const pole = rzad?.querySelector('details[open] textarea.field-input');

      return {
        rzadSzer: rzad ? zaokr(rzad.getBoundingClientRect().width) : null,
        poleSzer: pole ? zaokr(pole.getBoundingClientRect().width) : null,
        scrollWidth: document.documentElement.scrollWidth,
        okno: window.innerWidth,
      };
    });

    if (rozwiniete.poleSzer === null) {
      throw new Error('Po rozwinięciu „Odpowiedz" nie ma pola do pisania — '
        + 'pomiar stanu rozwiniętego opisywałby co innego.');
    }

    console.log(`\n  ROZWINIĘTE „Odpowiedz": pole ${rozwiniete.poleSzer} px `
      + `przy rzędzie ${rozwiniete.rzadSzer} px `
      + `(${Math.round((rozwiniete.poleSzer / rozwiniete.rzadSzer) * 100)}% szerokości rzędu), `
      + `scrollWidth ${rozwiniete.scrollWidth}/${rozwiniete.okno} px`
      + `${rozwiniete.scrollWidth > rozwiniete.okno ? '  <-- WYCHODZI POZA OKNO' : ''}`);

    wyniki[wyniki.length - 1].rozwiniete = rozwiniete;

    await kontekst.close();
  }
} finally {
  await przegladarka.close();
  zamknij();
}

function zaokrG(x) { return Math.round(x * 10) / 10; }

if (process.env.JSON) {
  writeFileSync(process.env.JSON, `${JSON.stringify(wyniki, null, 2)}\n`);
  console.log(`\nZapisane do ${process.env.JSON}`);
}

/*
 * PUSTY EKRAN PRZECHODZI KAŻDY POMIAR. Brak którejkolwiek z dwóch gałęzi
 * znaczy, że mierzyliśmy połowę zgłoszenia — i wtedy „zero naruszeń" nie mówi
 * niczego (D-099, D-106).
 */
if (! widzialemWlasny || ! widzialemCudzy) {
  console.error('\nBŁĄD: na mierzonej stronie zabrakło '
    + [! widzialemWlasny ? 'komentarza WŁASNEGO (z „Popraw")' : null,
      ! widzialemCudzy ? 'komentarza CUDZEGO (z „Zgłoś")' : null]
      .filter(Boolean).join(' i ')
    + ' — pomiar opisuje nie ten ekran, o który poszło zgłoszenie.');
  process.exit(1);
}
