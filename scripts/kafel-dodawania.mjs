/*
 * =============================================================================
 *  Kuking.pl — pomiar KAFLA „DODAJ ZDJĘCIE TEGO, CO UGOTOWAŁEŚ" (pas C)
 * =============================================================================
 *
 *  CO TO MIERZY
 *  Odnośnik `.kafel-akcji.composer` na `/home` — ten sam, który
 *  `scripts/dostepnosc.mjs` zgłasza dwa razy jako „focus częściowo
 *  zasłonięty". Mierzone jest:
 *
 *    • CAŁKOWITA wysokość kafla i jej stosunek do wysokości okna (to jest ta
 *      jedna liczba, o którą w tym pasie chodzi: kontrolka wyższa od okna
 *      nie da się pokazać po Tabie w całości i żadna rezerwa nad dolną belką
 *      tego nie naprawi),
 *    • wysokość KAŻDEGO składnika osobno — awatar, kolumna tekstu, tytuł,
 *      podpis, ikona — plus wcięcia pionowe i obwódki kafla, żeby było
 *      widać, co dokładnie tę wysokość robi,
 *    • SZEROKOŚĆ kolumny tekstu i liczba WIERSZY, na które łamie się tytuł
 *      i podpis; przy 2087 px wysokości winowajcą nie jest pismo, tylko to,
 *      że kolumna tekstu została ściśnięta do kilkudziesięciu pikseli
 *      i każde słowo dostało własny wiersz,
 *    • cel dotknięcia całego kafla (ma trzymać 48 × 48 px w każdym wariancie),
 *    • wielkość pisma tytułu i podpisu (minimum produktowe 18 px dla obu;
 *      naruszenie kończy pomiar niezerowym kodem, jak pozostałe kategorie),
 *    • czy strona ma przewijanie w poziomie.
 *
 *  DLACZEGO TYLE WARIANTÓW
 *  Usterka nie zależy od samej szerokości okna, tylko od stosunku szerokości
 *  do rozmiaru pisma. Przy czcionce przeglądarki 200% wcięcia kafla
 *  (`--spacing-5`, 1.25rem) rosną do 40 px z każdej strony, odstępy
 *  (`--spacing-3`) do 24 px, a awatar (48 px) i ikona (28 px) zostają tam,
 *  gdzie były — więc na kolumnę z tekstem nie zostaje prawie nic. Dlatego
 *  każda szerokość idzie w trzech wariantach pisma:
 *
 *    • bez powiększania,
 *    • nasze ustawienie z profilu `data-text-scale="140"` (maksimum, jakie
 *      dopuszcza CHECK w bazie: `text_scale BETWEEN 90 AND 140`),
 *    • czcionka przeglądarki 200% przez CDP `Page.setFontSizes` — ten sam
 *      mechanizm, co w `scripts/dostepnosc.mjs` i `scripts/glowka-karty-wpisu.mjs`.
 *
 *  5 szerokości × 3 warianty pisma = 15 pomiarów.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/kafel-dodawania.mjs
 *      ADRES=http://127.0.0.1:8102 node scripts/kafel-dodawania.mjs
 *
 *  Bez `ADRES` skrypt sam zakłada i sieje WŁASNĄ bazę, buduje arkusz,
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
const BAZA_DOMYSLNA = 'kuking_kafel_pomiar';

/* Domyślny rozmiar pisma przeglądarki; wariant 200% ustawia dwa razy tyle
   przez CDP `Page.setFontSizes`. To jest EMULACJA CZCIONKI BAZOWEJ, a nie
   powiększenie strony: piksel CSS zostaje ten sam, rosną tylko `rem` i `em`. */
const BAZOWA_CZCIONKA_PX = 16;

/* Znacznik wariantu „czcionka przeglądarki podwojona" — celowo NIE liczba,
   bo liczby znaczą tu `data-text-scale`, czyli nasze ustawienie z profilu.
   To są dwa różne mechanizmy (patrz komentarz w `scripts/dostepnosc.mjs`). */
const PRZEGLADARKA_200 = 'przegladarka-200';

const SZEROKOSCI = [
  { nazwa: '320 px', szerokosc: 320, wysokosc: 740 },
  { nazwa: '360 px', szerokosc: 360, wysokosc: 740 },
  { nazwa: '390 px', szerokosc: 390, wysokosc: 740 },
  { nazwa: '414 px', szerokosc: 414, wysokosc: 740 },
  { nazwa: '768 px', szerokosc: 768, wysokosc: 740 },
];

/* Wysokość okna 740 px w KAŻDYM wariancie, nie „naturalna" dla danego
   telefonu: pomiar mówi o stosunku wysokości kafla do wysokości okna, więc
   zmienna wysokość okna mieszałaby dwie przyczyny w jednej liczbie. 740 to ta
   sama wysokość, na której mierzy focus `scripts/dostepnosc.mjs`, żeby dało
   się liczby z obu plików położyć obok siebie. */

const WARIANTY_PISMA = [null, 140, PRZEGLADARKA_200];

function etykietaPisma(wariant) {
  if (wariant === null) return '100%';

  return wariant === PRZEGLADARKA_200 ? 'przeglądarka 200%' : `tekst ${wariant}%`;
}

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

async function podniesSerwer() {
  if (process.env.ADRES) return { adres: process.env.ADRES, zamknij: () => {} };

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  /* Strona wciąga zbudowany `public/build/assets/app-*.css` przez manifest
     Vite — pomiar bez przebudowania opisywałby POPRZEDNIĄ wersję arkusza. */
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

    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
      stdio: ['ignore', 'pipe', 'pipe'],
      env: env(),
    });

    proces.stdout.on('data', (b) => dziennik.push(String(b)));
    proces.stderr.on('data', (b) => dziennik.push(String(b)));

    let umarl = null;
    proces.on('exit', (kod) => { umarl = kod; });

    let wstal = false;
    /* OSTATNIA ODPOWIEDŹ, NIE SAM FAKT PORAŻKI — powód przy tej samej pętli
       w `scripts/port-projektu.mjs`. Krótko: `/health` oddaje 503, kiedy padnie
       `database` albo `migrations`, a dotąd treść tej odpowiedzi szła do kosza
       i w dzienniku CI zostawało samo „brak odpowiedzi". */
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
      + (dziennik.length > 0 ? `\n${dziennik.join('').trimEnd()}` : ''));
  }

  throw new Error(`Nie udało się podnieść \`php artisan serve\`.\n${bledy.join('\n')}`);
}

/*
 * POMIAR. Wszystko z `getBoundingClientRect()` po ułożeniu strony — liczy się
 * to, co widzi człowiek, a nie deklaracja w arkuszu.
 *
 * SKŁADNIKI BIERZEMY PO ROLI, NIE PO KLASIE, tam gdzie się da: awatar to
 * pierwsze dziecko kafla, ikona — ostatnie, kolumna tekstu — to, co zostaje.
 * Dzięki temu ten sam skrypt mierzy stan PRZED poprawką i PO niej, choćby
 * klasa albo kolejność reguł się zmieniła.
 *
 * LICZBA WIERSZY IDZIE Z `Range.getClientRects()`, a nie z dzielenia
 * wysokości przez `line-height`: przy zawijaniu wiersze mają czasem różną
 * wysokość (inne pismo w środku, `hyphens`), a dzielenie dawałoby wtedy
 * liczbę, która wygląda na pomiar, a jest oszacowaniem.
 */
const POMIAR = () => {
  const zaokr = (x) => Math.round(x * 100) / 100;

  const kafel = document.querySelector('a.kafel-akcji.composer');

  if (! kafel) return { blad: 'nie znalazłem `a.kafel-akcji.composer` na /home' };

  const dzieci = [...kafel.children];
  const awatar = kafel.querySelector('.avatar');
  const ikona = kafel.querySelector('.ikona');
  const kolumna = dzieci.find((el) => el !== awatar && el !== ikona && ! el.contains(awatar) && ! el.contains(ikona))
    ?? kafel.querySelector('.composer-copy');
  const tytul = kafel.querySelector('.composer-title');
  const podpis = kafel.querySelector('.composer-help');

  const prostokat = (el) => {
    if (! el) return null;
    const p = el.getBoundingClientRect();

    return {
      szerokosc: zaokr(p.width),
      wysokosc: zaokr(p.height),
      lewo: zaokr(p.left),
      prawo: zaokr(p.right),
      gora: zaokr(p.top),
      dol: zaokr(p.bottom),
    };
  };

  /* Ile WIERSZY zajmuje tekst w elemencie. `Range` nad całą zawartością daje
     jeden prostokąt na wiersz; grupujemy po zaokrąglonej górnej krawędzi, bo
     prostokąty jednego wiersza potrafią się różnić o ułamek piksela. */
  const wierszy = (el) => {
    if (! el) return null;
    const zakres = document.createRange();
    zakres.selectNodeContents(el);
    const gory = [...zakres.getClientRects()]
      .filter((p) => p.width > 0 && p.height > 0)
      .map((p) => Math.round(p.top));

    return new Set(gory).size;
  };

  const pismo = (el) => (el ? zaokr(Number.parseFloat(getComputedStyle(el).fontSize)) : null);

  const stylKafla = getComputedStyle(kafel);
  const ramkaKafla = kafel.getBoundingClientRect();

  const wciecieGora = zaokr(Number.parseFloat(stylKafla.paddingTop));
  const wciecieDol = zaokr(Number.parseFloat(stylKafla.paddingBottom));
  const obwodkaGora = zaokr(Number.parseFloat(stylKafla.borderTopWidth));
  const obwodkaDol = zaokr(Number.parseFloat(stylKafla.borderBottomWidth));

  /* Wysokość ZAWARTOŚCI kafla: najwyższy ze składników w rzędzie. To jest
     liczba, którą wcięcia i obwódki dopełniają do wysokości całego kafla —
     a różnica między nią a sumą składników mówi, czy kafel jest jednym
     rzędem, czy już się złamał na dwa. */
  const skladniki = [awatar, kolumna, ikona].filter(Boolean).map((el) => el.getBoundingClientRect());
  const najwyzszySkladnik = skladniki.length > 0 ? zaokr(Math.max(...skladniki.map((p) => p.height))) : null;

  /* Ile RZĘDÓW ma kafel. Nie po górnej krawędzi (przy `align-items: center`
     składniki jednego rzędu mają różne `top`), tylko po ZACHODZENIU zakresów
     pionowych — dwa rzędy flexa nigdy na siebie nie zachodzą. */
  const pudelka = skladniki.filter((p) => p.height > 0).sort((a, b) => a.top - b.top);
  const rzedy = [];

  for (const p of pudelka) {
    const ostatni = rzedy.at(-1);

    if (ostatni && p.top < ostatni.dol - 1) ostatni.dol = Math.max(ostatni.dol, p.bottom);
    else rzedy.push({ gora: p.top, dol: p.bottom });
  }

  return {
    kafel: {
      ...prostokat(kafel),
      wciecieGora,
      wciecieDol,
      obwodkaGora,
      obwodkaDol,
    },
    awatar: prostokat(awatar),
    kolumna: prostokat(kolumna),
    tytul: prostokat(tytul),
    podpis: prostokat(podpis),
    ikona: prostokat(ikona),
    najwyzszySkladnik,
    rzedy: rzedy.length,
    wierszyTytulu: wierszy(tytul),
    wierszyPodpisu: wierszy(podpis),
    pismoTytulu: pismo(tytul),
    pismoPodpisu: pismo(podpis),
    wysokoscOkna: window.innerHeight,
    szerokoscOkna: window.innerWidth,
    szerokoscDokumentu: document.documentElement.scrollWidth,
    pismoKorzenia: zaokr(Number.parseFloat(getComputedStyle(document.documentElement).fontSize)),
    pismoCiala: zaokr(Number.parseFloat(getComputedStyle(document.body).fontSize)),
    wysokoscBelki: (() => {
      const belka = document.querySelector('.bottom-nav');

      if (! belka) return null;
      const s = getComputedStyle(belka);

      if (s.display === 'none' || s.visibility === 'hidden') return null;

      return Math.round(belka.getBoundingClientRect().height * 10) / 10;
    })(),
  };
};

/*
 * POKRYCIE KAFLA PRZEZ DOLNĄ BELKĘ PO NACIŚNIĘCIU TAB.
 *
 * To jest DOKŁADNIE ta liczba, którą `scripts/dostepnosc.mjs` melduje jako
 * „focus częściowo zasłonięty" — ta sama siatka 4 × 4 na CZĘŚCI WIDOCZNEJ
 * kontrolki i to samo `elementFromPoint`. Powtarzamy ją tutaj, bo tamten
 * skrypt chodzi kilkanaście minut i mierzy cały serwis, a przy dobieraniu
 * poprawki trzeba widzieć TĘ jedną kontrolkę po każdej zmianie w arkuszu.
 * Wynik z tego pliku niczego nie zastępuje — rozstrzyga `dostepnosc.mjs`.
 *
 * Chodzimy Tabem, a nie `element.focus()`: przewinięcie po Tabie jest tym,
 * które robi przeglądarka człowiekowi, i to ono ogląda się na
 * `scroll-padding-top` / `scroll-padding-bottom` (`--rezerwa-nad-belka`
 * i `--rezerwa-pod-belka` w `app.css`).
 */
async function zmierzFocusKafla(strona) {
  for (let krok = 0; krok < 120; krok++) {
    await strona.keyboard.press('Tab');

    const stan = await strona.evaluate(() => {
      const el = document.activeElement;

      if (! el || el === document.body || el === document.documentElement) {
        return { koniec: true };
      }

      const kafel = document.querySelector('a.kafel-akcji.composer');

      if (el !== kafel) return { inny: true };

      const ramka = el.getBoundingClientRect();

      /* OBIE nakładki, nie tylko dolna belka. `scripts/dostepnosc.mjs` liczy
         ostrzeżenia z `.topbar` i z `.bottom-nav` do JEDNEGO licznika, więc
         poprawka, która zdejmuje kafel spod dolnej belki i wsuwa go pod
         górny pasek, nie zmieniłaby w nim niczego — a wyglądałaby na naprawę. */
      function pokrycieNakladki(selektor) {
        const nakladka = document.querySelector(selektor);

        if (! nakladka) return null;

        const styl = getComputedStyle(nakladka);

        if (styl.display === 'none' || styl.visibility === 'hidden') return null;
        if (nakladka.contains(el)) return 0;
        if (ramka.width === 0 || ramka.height === 0) return 0;

        const lewa = Math.max(ramka.left, 0);
        const gora = Math.max(ramka.top, 0);
        const prawa = Math.min(ramka.right, innerWidth);
        const dol = Math.min(ramka.bottom, innerHeight);

        if (prawa <= lewa || dol <= gora) return null;

        const SIATKA = 4;
        let zaslonietych = 0;

        for (let iy = 0; iy < SIATKA; iy++) {
          for (let ix = 0; ix < SIATKA; ix++) {
            const x = lewa + ((prawa - lewa) * (ix + 0.5)) / SIATKA;
            const y = gora + ((dol - gora) * (iy + 0.5)) / SIATKA;
            const trafiony = document.elementFromPoint(x, y);

            if (trafiony && nakladka.contains(trafiony) && ! el.contains(trafiony)) zaslonietych++;
          }
        }

        return zaslonietych / (SIATKA * SIATKA);
      }

      const belka = document.querySelector('.bottom-nav');

      return {
        pokrycie: pokrycieNakladki('.bottom-nav'),
        pokrycieTopbar: pokrycieNakladki('.topbar'),
        gora: Math.round(ramka.top * 10) / 10,
        dol: Math.round(ramka.bottom * 10) / 10,
        gornaBelki: belka ? Math.round(belka.getBoundingClientRect().top * 10) / 10 : null,
      };
    });

    if (stan.koniec) return null;
    if (stan.inny) continue;

    return stan;
  }

  return null;
}

const CHROMIUM = znajdzChromium();
const { adres, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/* Logujemy się RAZ i przenosimy ciasteczko przez `storageState`: piętnaście
   logowań pod rząd z jednego adresu trafia na `throttle` i pomiar leciałby
   na ekranie „Za dużo prób" zamiast na tablicy. */
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
  for (const widok of SZEROKOSCI) {
    for (const wariant of WARIANTY_PISMA) {
      const kontekst = await przegladarka.newContext({
        viewport: { width: widok.szerokosc, height: widok.wysokosc },
        storageState: sesja,
        /* Ten sam powód co w `scripts/dostepnosc.mjs`: elementy z `transition`
           złapane w połowie ruchu dają niestabilny pomiar. */
        reducedMotion: 'reduce',
      });
      const strona = await kontekst.newPage();

      if (wariant === PRZEGLADARKA_200) {
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

      await strona.waitForSelector('a.kafel-akcji.composer');
      await strona.evaluate(() => document.fonts?.ready);

      if (typeof wariant === 'number') {
        await strona.evaluate(
          (s) => document.documentElement.setAttribute('data-text-scale', String(s)),
          wariant,
        );

        /* Kontrola metody, nie ozdoba: wariant, który się nie przeliczył, daje
           liczby wyglądające na dobre — i to jest najgorszy możliwy wynik
           pomiaru (docs/PULAPKI_TESTOW.md, pułapka 5). */
        const oczekiwane = 1.125 * BAZOWA_CZCIONKA_PX * (wariant / 100);
        const zgadza = await strona.evaluate(async (cel) => {
          const teraz = () => Number.parseFloat(getComputedStyle(document.body).fontSize);
          const klatka = () => new Promise((dalej) => requestAnimationFrame(() => dalej()));

          for (let proba = 0; proba < 30; proba++) {
            if (Math.abs(teraz() - cel) < 0.5) return true;
            await klatka();
          }

          return Math.abs(teraz() - cel) < 0.5;
        }, oczekiwane);

        if (! zgadza) {
          console.error(`BŁĄD: ${widok.nazwa} / tekst ${wariant}% — strona nie przeliczyła się `
            + `na ${oczekiwane} px pisma podstawowego. Liczby tego wariantu nic nie znaczą.`);
          bylBlad = true;
          await kontekst.close();
          continue;
        }
      }

      const wynik = await strona.evaluate(POMIAR);

      if (wynik.blad) {
        console.error(`BŁĄD: ${wynik.blad}`);
        bylBlad = true;
        await kontekst.close();
        continue;
      }

      if (wariant === PRZEGLADARKA_200 && wynik.pismoKorzenia < 2 * BAZOWA_CZCIONKA_PX) {
        console.error(`BŁĄD: czcionka korzenia to ${wynik.pismoKorzenia} px zamiast `
          + `${2 * BAZOWA_CZCIONKA_PX} px — wariant 200% nie zadziałał, jego liczby nic nie znaczą.`);
        bylBlad = true;
      }

      const focus = await zmierzFocusKafla(strona);

      if (focus === null) {
        console.error(`BŁĄD: ${widok.nazwa} / ${etykietaPisma(wariant)} — Tab nie doszedł `
          + 'do kafla dodawania. Pomiar pokrycia przez belkę jest wtedy pusty, a nie zerowy.');
        bylBlad = true;
      }

      wiersze.push({ widok: widok.nazwa, pismo: etykietaPisma(wariant), focus, ...wynik });

      await kontekst.close();
    }
  }
} finally {
  await przegladarka.close();
  zamknij();
}

const kol = (x, n) => String(x).padStart(n);

console.log('\nCAŁY KAFEL WOBEC OKNA');
console.log('okno      pismo               wys. kafla   okno   kafel/okno   mieści się   przewijanie w bok');
console.log('-'.repeat(96));

for (const w of wiersze) {
  const udzial = w.kafel.wysokosc / w.wysokoscOkna;
  const przewijanie = w.szerokoscDokumentu > w.szerokoscOkna
    ? `TAK (${w.szerokoscDokumentu} > ${w.szerokoscOkna})`
    : 'nie';

  console.log(
    w.widok.padEnd(10)
    + w.pismo.padEnd(20)
    + kol(w.kafel.wysokosc, 8) + ' px  '
    + kol(w.wysokoscOkna, 5) + '  '
    + kol(`${Math.round(udzial * 100)}%`, 10) + '   '
    + (udzial <= 1 ? 'tak' : 'NIE').padEnd(13)
    + przewijanie,
  );
}

console.log('\nSKŁADNIKI WYSOKOŚCI (co dokładnie ją robi)');
console.log('okno      pismo               wciecia  awatar  ikona  kolumna  tytuł(wiersze)  podpis(wiersze)  szer.kolumny  rzędy');
console.log('-'.repeat(124));

for (const w of wiersze) {
  const wciecia = w.kafel.wciecieGora + w.kafel.wciecieDol + w.kafel.obwodkaGora + w.kafel.obwodkaDol;

  console.log(
    w.widok.padEnd(10)
    + w.pismo.padEnd(20)
    + kol(wciecia, 6) + '  '
    + kol(w.awatar?.wysokosc ?? '—', 6) + '  '
    + kol(w.ikona?.wysokosc ?? '—', 5) + '  '
    + kol(w.kolumna?.wysokosc ?? '—', 7) + '  '
    + kol(`${w.tytul?.wysokosc ?? '—'} (${w.wierszyTytulu ?? '—'})`, 14) + '  '
    + kol(`${w.podpis?.wysokosc ?? '—'} (${w.wierszyPodpisu ?? '—'})`, 15) + '  '
    + kol(w.kolumna?.szerokosc ?? '—', 12) + '  '
    + kol(w.rzedy, 5),
  );
}

console.log('\nCZYTELNOŚĆ I CEL DOTKNIĘCIA (twarde minima z docs/UX_50_PLUS.md)');
console.log('okno      pismo               pismo tytułu  pismo podpisu  cel dotknięcia kafla  belka');
console.log('-'.repeat(100));

for (const w of wiersze) {
  console.log(
    w.widok.padEnd(10)
    + w.pismo.padEnd(20)
    + kol(w.pismoTytulu ?? '—', 9) + ' px  '
    + kol(w.pismoPodpisu ?? '—', 10) + ' px  '
    + kol(`${w.kafel.szerokosc}×${w.kafel.wysokosc}`, 20) + '  '
    + kol(w.wysokoscBelki ?? '—', 5) + ' px',
  );
}

console.log('\nKAFEL PO NACIŚNIĘCIU TAB (ta sama siatka 4 × 4, co w scripts/dostepnosc.mjs)');
console.log('okno      pismo               ramka po Tabie        górna krawędź belki  pokrycie belką  pokrycie paskiem');
console.log('-'.repeat(112));

for (const w of wiersze) {
  const f = w.focus;

  console.log(
    w.widok.padEnd(10)
    + w.pismo.padEnd(20)
    + kol(f ? `${f.gora} … ${f.dol}` : '—', 20) + '  '
    + kol(f?.gornaBelki ?? '—', 19) + '  '
    + kol(f && f.pokrycie !== null ? `${Math.round(f.pokrycie * 100)}%` : '—', 14) + '  '
    + (f && f.pokrycieTopbar !== null ? `${Math.round(f.pokrycieTopbar * 100)}%` : 'brak paska'),
  );
}

/* --- BRAMKI POMIARU ------------------------------------------------------
   Tabela sama w sobie niczego nie przesądza; te warunki przesądzają. */
const niemieszczace = wiersze.filter((w) => w.kafel.wysokosc > w.wysokoscOkna);
const zaslonione = wiersze.filter((w) => (w.focus?.pokrycie ?? 0) > 0 || (w.focus?.pokrycieTopbar ?? 0) > 0);
const zaMaleCele = wiersze.filter((w) => w.kafel.szerokosc < 48 || w.kafel.wysokosc < 48);
const zaMalyTytul = wiersze.filter((w) => (w.pismoTytulu ?? 0) < 18);
const zaMalyPodpis = wiersze.filter((w) => (w.pismoPodpisu ?? 0) < 18);
const przewijanie = wiersze.filter((w) => w.szerokoscDokumentu > w.szerokoscOkna);

console.log('');
console.log(`Kafel wyższy niż okno:                    ${niemieszczace.length} z ${wiersze.length}`);
console.log(`Cel dotknięcia kafla poniżej 48 × 48 px:  ${zaMaleCele.length} z ${wiersze.length}`);
console.log(`Pismo tytułu poniżej 18 px:               ${zaMalyTytul.length} z ${wiersze.length}`);
console.log(`Pismo podpisu poniżej 18 px:              ${zaMalyPodpis.length} z ${wiersze.length}`);
console.log(`Przewijanie strony w bok:                 ${przewijanie.length} z ${wiersze.length}`);
console.log(`Kafel zasłonięty nakładką po Tabie:       ${zaslonione.length} z ${wiersze.length}`);

if (niemieszczace.length > 0) {
  console.log('\nKTÓRE WARIANTY NIE MIESZCZĄ SIĘ W OKNIE:');
  for (const w of niemieszczace) {
    console.log(`  ${w.widok} / ${w.pismo}: ${w.kafel.wysokosc} px przy oknie ${w.wysokoscOkna} px `
      + `(kolumna tekstu ${w.kolumna?.szerokosc} px, tytuł w ${w.wierszyTytulu} wierszach, `
      + `podpis w ${w.wierszyPodpisu})`);
  }
}

/* Każda raportowana kategoria jest bramką; zachowujemy wcześniejsze błędy. */
if ([niemieszczace, zaslonione, zaMaleCele, zaMalyTytul, zaMalyPodpis, przewijanie]
  .some((naruszenia) => naruszenia.length > 0)) {
  bylBlad = true;
}

if (wiersze.length !== SZEROKOSCI.length * WARIANTY_PISMA.length) {
  console.error(`\nBŁĄD: zebrano ${wiersze.length} pomiarów zamiast `
    + `${SZEROKOSCI.length * WARIANTY_PISMA.length}.`);
  bylBlad = true;
}

if (bylBlad) process.exit(1);
