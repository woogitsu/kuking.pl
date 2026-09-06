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

const wyniki = [];
let blokujacych = 0;

/** Przepełnienia w poziomie — osobna lista, bo to nie jest naruszenie axe. */
const przepelnienia = [];

const stanZalogowany = await stanZalogowanego(przegladarka, adres);

for (const wariant of WARIANTY) {
  const kontekst = await przegladarka.newContext({
    colorScheme: wariant.motyw,
    viewport: { width: wariant.szerokosc, height: 900 },
    storageState: stanZalogowany,
  });

  const strona = await kontekst.newPage();

  for (const ekran of EKRANY) {
    const sciezka = ekran.znajdz === 'przepis' ? adresPrzepisu : ekran.adres;

    if (! sciezka) {
      // Ciche pominięcie ekranu jest gorsze niż błąd: raport wygląda
      // na kompletny, a jeden widok nie został sprawdzony w ogóle.
      console.error(`BŁĄD: brak adresu dla ekranu „${ekran.nazwa}".`);
      process.exitCode = 1;
      continue;
    }

    await strona.goto(sciezka.startsWith('http') ? sciezka : `${adres}${sciezka}`,
      { waitUntil: 'domcontentloaded' });

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

  await kontekst.close();
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
      const sciezka = ekran.znajdz === 'przepis' ? adresPrzepisu : ekran.adres;

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
