/*
 * =============================================================================
 *  Kuking.pl — POMIAR I STRAŻNIK: hasło i akcja na PIERWSZYM EKRANIE
 * =============================================================================
 *
 *  CZEGO TO PILNUJE
 *  `docs/design/system-v3.1/uploads/KuKING-design-system-v3.1-poprawiony/
 *  04-strona-www/STRONA-WWW.md:121` mówi o sekcji głównej strony powitalnej:
 *  „hasło i akcja mają być widoczne bez przewijania". Przed poprawką dół
 *  przycisku „Zostań kuKINGiem" stał przy 320 px na 776 px, czyli 208 px
 *  poniżej dolnej krawędzi okna 568 px. Człowiek wchodzący z telefonu
 *  widział hasło i ani jednej drogi dalej.
 *
 *  ZAKRES JEST DECYZJĄ WŁAŚCICIELA, NIE NIEDOBOREM POMIARU
 *  Naprawiamy i STRZEŻEMY WYŁĄCZNIE SKALI TEKSTU 100%. Przy 140% właściciel
 *  zgodził się na przewijanie. Dlatego 140% jest tutaj MIERZONE I WYPISYWANE,
 *  ale NIE JEST ASERCJĄ: asercja zabetonowałaby zachowanie, którego nikt nie
 *  wybrał, a zdejmowanie asercji później wygląda jak cofnięcie wymagania.
 *
 *  DLACZEGO OKNO MA RÓŻNĄ WYSOKOŚĆ PRZY RÓŻNEJ SZEROKOŚCI
 *  Inne pomiary w tym repozytorium trzymają wysokość okna na sztywno (740 px),
 *  bo mierzą STOSUNEK wysokości elementu do okna i stała wysokość rozdziela
 *  przyczyny. Tutaj pytanie jest inne — „czy mieści się na PIERWSZYM EKRANIE
 *  TEGO telefonu" — więc wysokość okna musi być wysokością tego telefonu,
 *  a nie liczbą wspólną. 320×568, 360×640, 375×667 i 414×736 to cztery
 *  najczęstsze porty widoku telefonów.
 *
 *  CZTERY BRAMKI, BEZ KTÓRYCH TEN POMIAR NIC NIE ZNACZY
 *  (`docs/PULAPKI_TESTOW.md` §5 — narzędzie umie zameldować sukces bez roboty)
 *
 *    A. PRZYCISK MUSI SIĘ ZNALEŹĆ. Selektor, który nie trafia w nic, daje
 *       zero elementów i „zero naruszeń". Liczymy elementy i żądamy dokładnie
 *       jednego — inaczej `BRAK_CTA`.
 *    B. SKALA TEKSTU MUSI NAPRAWDĘ WEJŚĆ. `data-text-scale` ustawione na
 *       elemencie, którego arkusz nie czyta, nie zmienia niczego. Czytamy
 *       wyliczony rozmiar pisma przycisku i porównujemy ze skalą.
 *    C. PEŁNA MACIERZ. Cztery szerokości mają dać cztery pomiary przy 100%.
 *       Pętla przerwana po pierwszej też „nie znalazła naruszeń".
 *    D. PROGI UX 50+ MIERZONE RAZEM Z POŁOŻENIEM. Gdyby strażnik pilnował
 *       samego położenia, najtańszą naprawą następnej regresji byłoby
 *       zmniejszenie pisma albo przycisku. Dlatego w tej samej pętli stoją
 *       progi z `docs/UX_50_PLUS.md`: pismo ≥ 18 px, cel dotknięcia ≥ 48 px.
 *
 *  URUCHOMIENIE (z katalogu projektu — inaczej `playwright` się nie znajdzie)
 *      node scripts/hero-nad-zgieciem.mjs
 *      ADRES=http://127.0.0.1:8137 node scripts/hero-nad-zgieciem.mjs
 *      HERO_POMIN_BAZE=1 node scripts/hero-nad-zgieciem.mjs   # bez migrate:fresh
 *
 *  Da się też wpiąć w cudzą przeglądarkę — `scripts/port-projektu.mjs` robi
 *  tak z resztą pomiarów:
 *      await sprawdzHeroNadZgieciem({ browser, adres });
 * =============================================================================
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { ustalBazePomiarowa } from './bezpiecznik-bazy.mjs';

/* Osobna baza pomiarowa — ten skrypt potrafi zrobić `migrate:fresh`.
   Wskazanie `kuking` albo `kuking_test` kasowałoby czyjąś pracę (AGENTS.md §6). */
const BAZA_DOMYSLNA = 'kuking_hero_zgiecie';

/* BEZPIECZNIK (#736, D-243): nazwę bazy sprawdza `ustalBazePomiarowa()`, a nie
   lista dwóch dosłownych nazw — `kuking_test_<worktree>` przez taką listę
   przechodził. Ustalana dopiero w `podniesSerwer()`, NIE przy imporcie modułu:
   `scripts/port-projektu.mjs` importuje stąd `sprawdzHeroNadZgieciem()` i chodzi
   na własnej bazie `kuking_port_*`, której ten skrypt nie kasuje — odmowa
   w chwili importu wyłożyłaby port marki bez powodu. */
let bazaPomiarowa = null;

/* Port widoku = prawdziwy telefon, razem z wysokością. Patrz nagłówek. */
export const TELEFONY = [
  { szerokosc: 320, wysokosc: 568 },
  { szerokosc: 360, wysokosc: 640 },
  { szerokosc: 375, wysokosc: 667 },
  { szerokosc: 414, wysokosc: 736 },
];

/* Skala 100 jest STRZEŻONA, skala 140 tylko MIERZONA (decyzja właściciela). */
const SKALA_STRZEZONA = 100;
const SKALE_MIERZONE = [100, 140];

/* Progi z `docs/UX_50_PLUS.md`. Stoją tu po to, żeby najtańszą naprawą
   następnej regresji NIE BYŁO zmniejszenie pisma albo przycisku (bramka D). */
const MIN_PISMO_PX = 18;
const MIN_CEL_PX = 48;

/* Margines na zaokrąglenia układu. Jeden piksel, nie „trochę": większy luz
   zaczyna przepuszczać przycisk naprawdę wystający poza ekran. */
const LUZ_PX = 1;

/* Napis przycisku jest decyzją właściciela (`docs/brand/GLOS_MARKI.md` §6),
   a nie szczegółem układu — pilnujemy go, żeby „naprawa" przez skrócenie
   samego napisu CTA nie przeszła po cichu. Wielkość liter bierze się
   z arkusza, więc porównujemy z tym, co zwraca DOM. */
const NAPIS_CTA = 'Zostań kukingiem — bez opłat i bez reklam';

function znajdzChromium() {
  if (process.env.CHROMIUM_PATH) return process.env.CHROMIUM_PATH;
  try {
    const wlasna = chromium.executablePath();
    if (wlasna && existsSync(wlasna)) return undefined;
  } catch { /* idziemy dalej */ }
  const deweloperska = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

  return existsSync(deweloperska) ? deweloperska : undefined;
}

function env(dodatkowe = {}) {
  return { ...process.env, DB_DATABASE: bazaPomiarowa ?? (process.env.DB_DATABASE || BAZA_DOMYSLNA), ...dodatkowe };
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

/** Podnosi `php artisan serve` na wolnym porcie i czeka, aż odpowie `/health`. */
async function uruchomSerwer() {
  const bledy = [];

  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = await wolnyPort();
    const adres = `http://127.0.0.1:${port}`;
    const dziennik = [];

    /* `--no-reload` — bez niego `artisan serve` wycina procesowi `php -S`
       zmienne środowiska joba i aplikacja spada na `.env`, czyli na
       współdzielony port 5432 (ta sama pułapka co w `port-projektu.mjs`). */
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

async function podniesSerwer() {
  if (process.env.ADRES) return { adres: process.env.ADRES, zamknij: () => {} };

  bazaPomiarowa = ustalBazePomiarowa({
    domyslna: BAZA_DOMYSLNA,
    skrypt: 'scripts/hero-nad-zgieciem.mjs',
  });

  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  console.log('Buduję arkusz i skrypt (vite build)...');
  execFileSync('npm', ['run', 'build'], { stdio: 'ignore', env: process.env });

  if (process.env.HERO_POMIN_BAZE !== '1') {
    try {
      execFileSync('createdb', [env().DB_DATABASE], {
        stdio: 'ignore',
        env: {
          ...process.env,
          PGHOST: process.env.DB_HOST || '127.0.0.1',
          PGPORT: process.env.DB_PORT || '5432',
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
  }

  return uruchomSerwer();
}

/**
 * Jeden pomiar: strona powitalna widziana przez GOŚCIA (bez sesji) przy
 * zadanej szerokości, wysokości okna i skali tekstu z profilu.
 */
async function zmierz(page, { szerokosc, wysokosc, skala, adres }) {
  await page.setViewportSize({ width: szerokosc, height: wysokosc });
  await page.goto(`${adres}/`);
  await page.evaluate((s) => { document.documentElement.dataset.textScale = String(s); }, skala);
  /* Czcionki zmieniają wysokość wierszy, a wysokość wierszy jest połową tego
     pomiaru. Bez `document.fonts.ready` liczby są z układu zastępczego. */
  await page.evaluate(async () => {
    await document.fonts.ready;
    for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame);
  });

  const cta = page.locator('.hero-akcje > .btn-duzy');
  const ile = await cta.count();

  return {
    ile,
    ...(ile === 1 ? await cta.evaluate((a) => {
      const r = a.getBoundingClientRect();
      const napis = a.cloneNode(true);
      napis.querySelectorAll('[aria-hidden="true"]').forEach((el) => el.remove());
      const lead = document.querySelector('.hero-lead');
      const tytul = document.querySelector('.hero-tytul');

      return {
        gora: Math.round(r.top * 10) / 10,
        dol: Math.round(r.bottom * 10) / 10,
        wysokoscCta: Math.round(r.height * 10) / 10,
        okno: innerHeight,
        oknoSzerokosc: innerWidth,
        pismoCta: parseFloat(getComputedStyle(a).fontSize),
        pismoLead: lead ? parseFloat(getComputedStyle(lead).fontSize) : null,
        pismoTytul: tytul ? parseFloat(getComputedStyle(tytul).fontSize) : null,
        napis: napis.textContent.replace(/\s+/g, ' ').trim(),
        przewijaniePoziome: document.documentElement.scrollWidth > innerWidth + 1,
        wierszeLeadu: lead ? lead.getClientRects().length : null,
        wysokoscLeadu: lead ? Math.round(lead.getBoundingClientRect().height * 10) / 10 : null,
      };
    }) : {}),
  };
}

/**
 * SAM POMIAR, bez jednej asercji. Osobno od strażnika, bo służy do czegoś
 * innego: do porównania stanu SPRZED poprawki ze stanem PO. Gdyby to była
 * flaga „nie sprawdzaj" w strażniku, ta sama flaga prędzej czy później
 * uciszyłaby strażnika w CI. Funkcja bez asercji nie umie nic zepsuć,
 * bo niczego nie pilnuje.
 */
export async function zmierzHeroNadZgieciem({ browser, adres }) {
  const context = await browser.newContext({ reducedMotion: 'reduce' });
  const page = await context.newPage();
  const pomiary = [];

  try {
    for (const telefon of TELEFONY) {
      for (const skala of SKALE_MIERZONE) {
        pomiary.push({ ...telefon, skala, ...await zmierz(page, { ...telefon, skala, adres }) });
      }
    }
  } finally {
    await context.close();
  }

  return pomiary;
}

/**
 * Strażnik. Wywoływany też z `scripts/port-projektu.mjs`.
 * Zwraca WSZYSTKIE pomiary — także te przy 140%, których nie asercjonujemy.
 */
export async function sprawdzHeroNadZgieciem({ browser, adres }) {
  const pomiary = await zmierzHeroNadZgieciem({ browser, adres });
  let strzezonych = 0;

  for (const p of pomiary) {
    const opis = `${p.szerokosc}×${p.wysokosc}/skala ${p.skala}%`;

    /* Bramka A — selektor, który nie trafia w nic, nie ma prawa uchodzić
       za „brak naruszeń". */
    assert.equal(p.ile, 1, `BRAK_CTA ${opis} (znaleziono ${p.ile})`);

    /* Bramka B — skala tekstu musi NAPRAWDĘ wejść, inaczej wariant 140%
       jest wariantem 100% pod inną nazwą. Przycisk ma 20 px przy 100%. */
    const oczekiwanePismo = 20 * p.skala / 100;
    assert(Math.abs(p.pismoCta - oczekiwanePismo) < 0.5,
      `NIEZASTOSOWANA_SKALA ${opis}: pismo przycisku ${p.pismoCta} px, oczekiwane ${oczekiwanePismo} px`);

    if (p.skala !== SKALA_STRZEZONA) continue;

    /* Bramka D — położenie i progi UX 50+ w jednej pętli. */
    assert(p.dol <= p.okno + LUZ_PX,
      `CTA_PONIZEJ_ZGIECIA ${opis}: dół przycisku ${p.dol} px, okno ${p.okno} px `
      + `(brakuje ${Math.round((p.dol - p.okno) * 10) / 10} px) ${JSON.stringify(p)}`);
    assert(p.gora >= 0, `CTA_PONAD_EKRANEM ${opis}: góra przycisku ${p.gora} px`);
    assert(p.wysokoscCta >= MIN_CEL_PX,
      `ZA_MALY_CEL ${opis}: przycisk ${p.wysokoscCta} px, próg ${MIN_CEL_PX} px`);
    assert(p.pismoCta >= MIN_PISMO_PX,
      `ZA_MALE_PISMO_CTA ${opis}: ${p.pismoCta} px, próg ${MIN_PISMO_PX} px`);
    assert(p.pismoLead >= MIN_PISMO_PX,
      `ZA_MALE_PISMO_LEADU ${opis}: ${p.pismoLead} px, próg ${MIN_PISMO_PX} px`);
    assert(p.pismoTytul >= MIN_PISMO_PX,
      `ZA_MALE_PISMO_TYTULU ${opis}: ${p.pismoTytul} px, próg ${MIN_PISMO_PX} px`);
    assert(!p.przewijaniePoziome, `POZIOME_PRZEWIJANIE ${opis}`);
    assert.equal(p.napis, NAPIS_CTA, `ZMIENIONA_ETYKIETA_CTA ${opis}`);

    strzezonych++;
  }

  /* Bramka C — pełna macierz. Pętla przerwana po pierwszym wariancie też
     „nie znalazła naruszeń". */
  assert.equal(strzezonych, TELEFONY.length,
    `NIEPELNA_MACIERZ_ZGIECIA: ${strzezonych}/${TELEFONY.length} wariantów przy skali ${SKALA_STRZEZONA}%`);

  console.log(`Pierwszy ekran: ${strzezonych}/${TELEFONY.length} przy skali ${SKALA_STRZEZONA}% PASS.`);

  return pomiary;
}

function tabela(pomiary) {
  const w = (t, n) => String(t).padStart(n);
  console.log('');
  console.log('  okno        skala   gora CTA   dol CTA   okno    zapas   lead px');
  console.log('  ----------- ------- ---------- --------- ------- ------- --------');
  for (const p of pomiary) {
    const zapas = Math.round((p.okno - p.dol) * 10) / 10;
    console.log(`  ${w(`${p.szerokosc}×${p.wysokosc}`, 11)} ${w(`${p.skala}%`, 7)} `
      + `${w(p.gora, 10)} ${w(p.dol, 9)} ${w(p.okno, 7)} ${w(zapas, 7)} ${w(p.wysokoscLeadu, 8)}`);
  }
  console.log('');
}

async function main() {
  const { adres, zamknij } = await podniesSerwer();
  const browser = await chromium.launch({ executablePath: znajdzChromium() });
  try {
    /* `HERO_TYLKO_POMIAR=1` wywołuje funkcję BEZ ASERCJI — po to, żeby dało
       się zmierzyć stan SPRZED poprawki, który z definicji oblewa. To nie
       jest wyciszenie strażnika: strażnik jest osobną funkcją i tej ścieżki
       nie widzi. */
    const pomiary = process.env.HERO_TYLKO_POMIAR === '1'
      ? await zmierzHeroNadZgieciem({ browser, adres })
      : await sprawdzHeroNadZgieciem({ browser, adres });
    tabela(pomiary);
    if (process.env.RAPORT) {
      mkdirSync(dirname(process.env.RAPORT), { recursive: true });
      writeFileSync(process.env.RAPORT, `${JSON.stringify(pomiary, null, 2)}\n`);
      console.log(`  zapis: ${process.env.RAPORT}`);
    }
  } finally {
    await browser.close();
    zamknij();
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((e) => { console.error(e.message); process.exit(1); });
}
