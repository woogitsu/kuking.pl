/*
 * =============================================================================
 *  Kuking.pl — automat dostępności (issue #26) i układu (issue #80)
 * =============================================================================
 *
 *  DWA RÓŻNE POMIARY W JEDNYM SKRYPCIE
 *  1. axe-core — analiza drzewa dokumentu: etykiety, nazwy dostępne, kontrast.
 *  2. pomiar układu — czy strona przewija się w bok przy 320/360/414/768/1280 px,
 *     przy naszym ustawieniu tekstu 140% i przy podwojonej czcionce przeglądarki.
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
  // Tryb gotowania (issue #24) — jeden krok na cały ekran, bardzo dużym
  // tekstem. Ten sam przepis co ekran „przepis” wyżej, bo demo ma dla niego
  // gotowe kroki — patrz komentarz przy `adresPrzepisu` niżej w tym pliku.
  { nazwa: 'tryb gotowania', adres: null, znajdz: 'gotowanie' },

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
  /*
   * EKRANY TAGÓW (D-021). Publiczna strona tagu jest jednym z niewielu
   * miejsc, w które ma sens trafić z wyszukiwarki, więc mierzymy ją jako
   * GOŚCIA, nie jako zalogowanego.
   *
   * `/ustawienia/tagi` i sekcja tagów w formularzu wpisu (mierzona przez
   * „dodaj zdjęcie" i „dodaj przepis", które ją zawierają) to rzędy
   * przycisków „Dodaj"/„Usuń" obok tekstu — dokładnie ten układ, który przy
   * 320 px i tekście 140% ma najwięcej okazji, żeby wypchnąć stronę w bok.
   * Slug `zupy` pochodzi z `DemoSeeder::otagujWpisy()`; gdyby ten seeder
   * przestał go tworzyć, ta pozycja zgłosi 404 zamiast po cichu przejść.
   */
  { nazwa: 'strona tagu (gość)', adres: '/tag/zupy' },
  { nazwa: 'twoje tagi', adres: '/ustawienia/tagi', zalogowany: true },

  /*
   * EKRANY DOPISANE 7 WRZEŚNIA — zmienione albo nowe tego dnia i dotąd
   * nienotowane przez ten automat.
   *
   * „Twoje dane" (D-022) jest tu z jednego powodu: to jedyny formularz
   * w serwisie, którego skutku nie da się cofnąć bez czekania 30 dni,
   * a od dziś ma dodatkowy haczyk zakresu i trzy listy „co znika / co
   * zostaje". Musi dać się przeczytać i obsłużyć klawiaturą, bo to jest
   * dokładnie ten ekran, na którym pomyłka najwięcej kosztuje.
   */
  { nazwa: 'twoje dane (usunięcie konta)', adres: '/ustawienia/twoje-dane', zalogowany: true },

  // Zgłoszenie treści niezgodnej z prawem (DSA art. 16). Publiczny, bez
  // logowania — pole imienia jest od dziś opcjonalne, z nowym wyjaśnieniem
  // przy polu. Mierzymy jako gościa, bo dla gościa ten formularz istnieje.
  { nazwa: 'zgłoś treść niezgodną z prawem', adres: '/zglos-nielegalna-tresc' },

  /*
   * Odwołanie od decyzji moderacyjnej (issue #10). DemoSeeder NIE tworzy
   * żadnej `ModerationAction`, więc bez tego bloku ten ekran nie miałby
   * czego pokazać — pusty/404 ekran przechodzi każdy test dostępności, nie
   * sprawdzając niczego (patrz nagłówek pliku). Seedujemy TU, w automacie,
   * a nie w `DemoSeeder` (nie nasze do ruszania) — dokładnie tym samym
   * mechanizmem, którym `adresPrzepisu` niżej pyta bazę o gotowe dane.
   * Decyzja MUSI dotyczyć konta, którym automat się loguje: ten ekran widzi
   * wyłącznie osoba, której decyzja dotyczy, a dla każdej innej serwis
   * odpowiada 403 — czyli stroną błędu, która przechodzi audyt.
   * Rozwiązywane przez `znajdz: 'odwolanie'` w `sciezkaEkranu`.
   */
  { nazwa: 'odwołanie od decyzji', adres: null, znajdz: 'odwolanie', zalogowany: true },

  /*
   * Strona ZGŁASZAJĄCEGO (issue #10, DSA art. 16 ust. 4 i 5) — lista własnych
   * spraw i karta jednej sprawy. Tak jak przy odwołaniu wyżej, `DemoSeeder`
   * nie tworzy żadnego zgłoszenia, więc dane dokłada ten automat
   * (`idZgloszenia` niżej) — inaczej lista pokazywałaby pusty stan, a karta
   * 403, i oba przeszłyby audyt, nic nie sprawdzając.
   *
   * Karta sprawy jest tu ważniejsza niż wygląda: to kilka kart pod sobą
   * z długimi zdaniami pouczenia i numerem sprawy, który nie może się złamać
   * w pół — czyli dokładnie ten kształt, który przy 320 px i tekście 140%
   * najłatwiej wypycha stronę w bok (issue #80).
   */
  { nazwa: 'twoje zgłoszenia', adres: '/zgloszenia', zalogowany: true },
  { nazwa: 'zgłoszenie — karta sprawy', adres: null, znajdz: 'zgloszenie', zalogowany: true },

  /*
   * Trzy dokumenty prawne, przepisane dziś w całości (prywatność, regulamin,
   * zasady). Długie strony z tabelami — dokładnie ten kształt treści, który
   * przy 320 px i przy tekście 140% ma największą szansę wypchnąć całą
   * stronę w bok, gdy tabela nie ma własnego przewijania (issue #80).
   */
  { nazwa: 'polityka prywatności', adres: '/prywatnosc' },
  { nazwa: 'regulamin', adres: '/regulamin' },
  { nazwa: 'zasady', adres: '/zasady' },
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
  // to jest układ, który przy 320 px i tekście 140% ma najwięcej okazji,
  // żeby wypchnąć stronę w bok.
  { nazwa: 'kolejność i wygląd zdjęć', adres: null, znajdz: 'wpis:carousel:zdjecia', zalogowany: true },
];

/**
 * Znacznik wariantu „czcionka przeglądarki podwojona". Celowo NIE jest
 * liczbą: liczby w `SKALE_UKLADU` znaczą `data-text-scale`, czyli nasze
 * ustawienie z profilu, a to jest inny mechanizm — patrz komentarz niżej.
 */
const PRZEGLADARKA_200 = 'przegladarka-200';

/**
 * Domyślny rozmiar pisma przeglądarki. Wariant `PRZEGLADARKA_200` ustawia
 * dwa razy tyle — czyli dokładnie to, co robi „Rozmiar czcionki: bardzo duży"
 * w ustawieniach Chrome.
 */
const BAZOWA_CZCIONKA_PX = 16;

/** Opis wariantu skali do logu i do raportu. */
function etykietaSkali(skala) {
  if (skala === null) {
    return '';
  }

  return skala === PRZEGLADARKA_200
    ? ' / czcionka przeglądarki 200%'
    : ` / tekst ${skala}%`;
}

/*
 * SZEROKOŚCI DO POMIARU PRZEPEŁNIENIA (issue #80)
 *
 * 320 px to minimum z WCAG 2.2 AA, kryterium 1.4.10 (Reflow). 360 i 414 to
 * dwa najczęstsze telefony, 768 to tablet w pionie i próg tuż pod układem
 * dwukolumnowym. Skala tekstu 140% jest tu obowiązkowa, bo nasza grupa
 * realnie ją włącza — a to przy niej belka pękała najbrzydziej.
 */
const SZEROKOSCI_UKLADU = SZYBKO ? [320, 360] : [320, 360, 414, 768, 1280];
/* 140, NIE 150 — i to jest poprawka błędu, który sam wprowadziłem.
 *
 * Do 8 września arkusz znał skale 112/125/150, a konfiguracja oferowała
 * 100/112/125/140. Poprawka rozjazdu usunęła martwą regułę dla 150 i dołożyła
 * brakującą dla 140 — ale TA linijka została przy 150. Skutek: atrybut
 * `data-text-scale="150"` nie trafiał już na żadną regułę, `--user-text-scale`
 * zostawał przy 1, i cały przebieg „320/360/414/768 px × tekst 140%" był bit
 * w bit taki sam jak przebieg bez skalowania.
 *
 * Czyli najmocniejszy pomiar przepełnienia w tym projekcie — ten, który
 * powstał po issue #80 — przez chwilę nie dokładał niczego, świecąc na
 * zielono. Dokładnie ta klasa usterki, której ten plik ma pilnować.
 *
 * 140 to maksimum, jakie CHECK w migracji `users` w ogóle dopuszcza
 * (`text_scale BETWEEN 90 AND 140`), więc jest to zarazem najgorszy przypadek,
 * jaki człowiek może sobie ustawić. */
const SKALE_UKLADU = SZYBKO ? [null] : [null, 140, PRZEGLADARKA_200];

/*
 * DLACZEGO 140% NIE WYSTARCZY I POTRZEBNY BYŁ DRUGI MECHANIZM
 *
 * `data-text-scale` to NASZE ustawienie z profilu, ograniczone CHECK-iem bazy
 * do 140 (`text_scale BETWEEN 90 AND 140`). Człowiek ma jednak drugą, całkiem
 * niezależną drogę: powiększenie czcionki w przeglądarce albo w systemie.
 * Tamtej nie ogranicza nic — 200% jest zwykłym ustawieniem, a Chrome oferuje
 * nawet „Bardzo duży".
 *
 * Te dwa mechanizmy dają RÓŻNE wyniki, bo skalują różne rzeczy. Zmierzone
 * przy oknie 320 px, wrzesień 2026: przy `data-text-scale="140"` cały serwis
 * był czysty, a przy podwojonej czcionce przeglądarki `/dodaj/zdjecie` miało
 * `scrollWidth` 553 px w oknie 320 px. Ten sam plik świecił na zielono
 * i przepuścił usterkę, przez którą osoba z powiększonym tekstem nie mogła
 * dodać zdjęcia. Znalazł ją dopiero audyt zewnętrzny.
 *
 * `rem` skaluje się z czcionką KORZENIA, więc `minmax(15rem, …)` przy bazie
 * 32 px żąda kolumny 480 px w oknie 320 px. To jest ta klasa błędu i dlatego
 * mierzymy ją osobno, zamiast podnosić limit ustawienia w profilu.
 *
 * JAK TO MIERZYMY I DLACZEGO NIE PROŚCIEJ
 *
 * Prosta droga — `document.documentElement.style.fontSize = '32px'` — DAJE
 * FAŁSZYWY WYNIK i tak właśnie zmierzył to najpierw audyt, a potem ja.
 * Powód: w media query `rem` liczy się od POCZĄTKOWEGO rozmiaru pisma
 * przeglądarki, nie od tego, co arkusz albo skrypt ustawi na `<html>`.
 * Podmiana przez CSSOM podwaja więc tekst, ale zostawia progi tam, gdzie
 * były. Zmierzone przy oknie 1280 px:
 *
 *     metoda                          korzeń   (min-width: 64rem)
 *     bez zmian                        16 px   true
 *     style.fontSize = '32px'          32 px   true    ← nieprawda
 *     CDP Page.setFontSizes 32         32 px   false   ← tak jest naprawdę
 *
 * Pierwszy wariant bada układ DESKTOPOWY z podwojonym tekstem — stan, w
 * którym żaden człowiek nie jest, bo przy prawdziwej czcionce 32 px próg
 * 64rem to 2048 px i desktop się nie załapuje. Zgłaszał za to szynę boczną
 * i awatar jako winnych przepełnienia przy 1280 px.
 *
 * Dlatego idziemy przez CDP `Page.setFontSizes`, czyli przez to samo pokrętło,
 * które ma człowiek w ustawieniach przeglądarki. Obie własności — podwojony
 * korzeń I nieaktywny próg 64rem — są sprawdzane w pętli; wariant, który
 * mierzy nie to, co trzeba, kończy się błędem, a nie cichą zielenią.
 *
 * CZEGO TEN POMIAR NIE ZASTĘPUJE: natywnego zoomu przeglądarki (Ctrl +).
 * Zoom skaluje cały layout razem z pikselami CSS, powiększenie samej czcionki
 * — nie. To dwa różne mechanizmy i ten skrypt sprawdza drugi.
 */

/*
 * `skalaTekstu` ustawiamy atrybutem na <html>, tak samo jak robi to layout
 * dla zalogowanego z ustawieniem w profilu. Symulowanie tego zoomem
 * przeglądarki sprawdzałoby coś innego niż to, co dostaje człowiek.
 *
 * `motyw` DZIAŁA TAK SAMO — `data-theme`, nie `colorScheme` kontekstu
 * (docs/DECISIONS.md, D-019). Arkusz stylów już nie ogląda się na
 * `prefers-color-scheme` — to była właśnie usterka, którą ta decyzja
 * zamyka — więc `newContext({ colorScheme: 'dark' })` sam z siebie nie
 * włączyłby już niczego. Ustawiamy atrybut wprost, tym samym mechanizmem
 * co `skalaTekstu` niżej, żeby ten automat wymuszał ciemny motyw dokładnie
 * tak, jak zrobiłby to prawdziwy przełącznik w stopce albo w ustawieniach.
 */
const WARIANTY = SZYBKO
  ? [{ nazwa: 'jasny', motyw: null, szerokosc: 1280 }]
  : [
    { nazwa: 'jasny', motyw: null, szerokosc: 1280 },
    { nazwa: 'ciemny', motyw: 'dark', szerokosc: 1280 },
    { nazwa: 'tekst 140%', motyw: null, szerokosc: 1280, skalaTekstu: 140 },
    { nazwa: '320 px', motyw: null, szerokosc: 320 },
  ];

/** Naruszenia poniżej tej wagi notujemy, ale nie zatrzymują one wysyłki. */
const BLOKUJACE = new Set(['critical', 'serious']);

// BAZA DOMYŚLNA TEGO AUTOMATU. Do 7 września 2026 stało tu `kuking_test`,
// czyli baza, na której chodzi `php artisan test` — a ten skrypt wykonuje
// `migrate:fresh --seed`. Uruchomienie automatu bez `DB_DATABASE` KASOWAŁO
// więc schemat bazy testowej, i to w środku ewentualnego przebiegu testów.
// Dokładnie ta klasa wypadku zdarzyła się w tym projekcie raz, na bazie
// deweloperskiej (AGENTS.md: nigdy `migrate:fresh` bez jawnego
// `DB_DATABASE`) — nie ma powodu, żeby automat dostępności był wyjątkiem.
//
// Stała stoi w zasięgu MODUŁU, nie funkcji, bo czytają ją cztery różne
// miejsca w tym pliku.
const BAZA_DOMYSLNA = 'kuking_a11y';

/*
 * KONTO, KTÓRYM AUTOMAT SIĘ LOGUJE — jedna nazwa, czytana w trzech miejscach.
 *
 * `ania`, nie `basia`: „basia" jest jednocześnie personą treści zalążkowej,
 * a persony mają hasło LOSOWE i nie są logowalne (D-025). `DemoSeeder`
 * znajdował wtedy personę i nie ustawiał jej hasła demo, więc logowanie cicho
 * padało — automat mierzył ekrany GOŚCIA, będąc pewnym, że mierzy ekrany
 * zalogowanej osoby.
 *
 * Stała stoi w zasięgu modułu, bo tę samą nazwę musi znać logowanie ORAZ dwa
 * ekrany, które są dostępne WYŁĄCZNIE dla właściciela treści: odwołanie od
 * decyzji (dla osoby, której decyzja dotyczy) i kolejność zdjęć we wpisie
 * (`PostPolicy::update` — tylko autor). Gdy te trzy miejsca rozjeżdżały się
 * na dwa różne konta, serwis odpowiadał 403, a automat wpisywał „✓": mierzył
 * stronę błędu, która przechodzi każdy audyt dostępności, nie sprawdzając
 * niczego. Zmierzone 7 września, po dodaniu sprawdzenia kodu HTTP niżej.
 */
const KONTO_ZALOGOWANE = 'ania';

function log(...args) {
  console.log(...args);
}

async function podnies_serwer() {
  if (process.env.ADRES) {
    return { adres: process.env.ADRES, zamknij: () => {} };
  }

  log('Przygotowuję dane demonstracyjne...');
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA },
  });

  // Trzy podejścia, za każdym razem inny port. Jedno by wystarczyło, gdyby
  // port dało się zarezerwować — a nie da się: między zwolnieniem gniazda
  // a startem PHP jest okno, w które może wejść inny proces.
  const bledy = [];

  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = await wolnyPort();
    const adres = `http://127.0.0.1:${port}`;
    const dziennik = [];

    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA },
    });

    // Zbieramy wyjście serwera, żeby przy nieudanym starcie MIEĆ CO POKAZAĆ.
    // Wcześniej stało tu `stdio: 'ignore'` i komunikat „Failed to listen on
    // 127.0.0.1:8496 (reason: Address already in use)" szedł do kosza.
    proces.stdout.on('data', (b) => dziennik.push(String(b)));
    proces.stderr.on('data', (b) => dziennik.push(String(b)));

    let umarl = null;
    proces.on('exit', (kod) => { umarl = kod; });

    // Czekamy na serwer zamiast zgadywać czas startu — na wolnej maszynie
    // sztywne „sleep 2" daje losowo czerwony wynik, który wygląda jak regresja.
    let wstal = false;

    for (let i = 0; i < 60 && umarl === null; i++) {
      try {
        const odp = await fetch(`${adres}/health`);
        if (odp.ok) { wstal = true; break; }
      } catch { /* jeszcze nie wstał */ }
      await new Promise((r) => setTimeout(r, 500));
    }

    if (wstal) {
      return { adres, zamknij: () => proces.kill('SIGTERM') };
    }

    proces.kill('SIGKILL');

    bledy.push(
      `  podejście ${podejscie}, port ${port}: `
      + (umarl !== null ? `proces zakończył się kodem ${umarl}` : 'brak odpowiedzi z /health przez 30 s')
      + (dziennik.length > 0 ? `\n${dziennik.join('').trimEnd().split('\n').map((w) => `      ${w}`).join('\n')}` : ''),
    );
  }

  // GŁOŚNO, nie po cichu. Do 9 września pętla po prostu kończyła się po
  // trzydziestu sekundach i skrypt szedł dalej — a Playwright zgłaszał wtedy
  // `net::ERR_CONNECTION_REFUSED at .../login`, czyli komunikat wskazujący
  // na stronę logowania, a nie na to, że serwera nigdy nie było.
  throw new Error(
    'Nie udało się podnieść `php artisan serve` w trzech podejściach.\n'
    + bledy.join('\n')
    + '\n\nJeśli powodem jest zajęty port: na jednej maszynie stoją trzy runnery '
    + 'i dwa joby mogą podnosić serwer równocześnie.',
  );
}

/*
 * Port, o którym system POTWIERDZIŁ, że jest wolny.
 *
 * Stało tu `8000 + Math.floor(Math.random() * 900)` — losowanie z dziewięciuset
 * numerów, bez pytania kogokolwiek, czy port jest zajęty. Przy jednej maszynie
 * i jednym biegu to działało. Runnery `kuking-wsl-DOM-NEW-01`, `-02` i `-03`
 * stoją jednak na JEDNYM systemie, więc dwa joby losują z tej samej puli:
 * przy dwóch równoczesnych biegach szansa kolizji to około 1 na 900 na parę,
 * ale przy kilkunastu biegach dziennie trafia regularnie. Drugi `php artisan
 * serve` nie może wtedy zająć portu, a skrypt szedł dalej i przewracał się
 * dopiero na `page.goto` (zmierzone 9 września, PR #162, port 8496).
 *
 * Port 0 znaczy „daj mi jakikolwiek wolny" — decyduje jądro, nie losowanie.
 * Zwalniamy gniazdo przed oddaniem numeru, więc zostaje okno, w które teoretycznie
 * może wejść inny proces; dlatego wywołujący ponawia próbę na innym porcie,
 * zamiast zakładać, że raz wystarczy.
 */
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
  // Nazwa konta — `KONTO_ZALOGOWANE` wyżej, razem z uzasadnieniem.
  await strona.fill('input[name="login"]', KONTO_ZALOGOWANE);
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
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA } })
    .toString().trim();

  return slug === '' ? null : `/przepisy/${slug}`;
})();

// Tryb gotowania tego samego przepisu — DemoSeeder daje mu kroki, więc ekran
// pokazuje prawdziwą treść, nie pustą kartę „autor jeszcze nie opisał
// przygotowania” (a pusty ekran przechodzi każdy test dostępności, nie
// sprawdzając niczego — patrz nagłówek tego pliku).
const adresGotowania = adresPrzepisu === null ? null : `${adresPrzepisu}/gotuj`;

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
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA } })
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
 * Decyzja moderacyjna, od której może się odwołać konto, którym ten automat
 * się loguje — `KONTO_ZALOGOWANE`, czyli dziś `ania` (issue #10).
 *
 * DemoSeeder NIE tworzy żadnej `ModerationAction` — sprawdzone przez
 * `grep -n ModerationAction database/seeders/DemoSeeder.php`, zero wyników.
 * Bez tego bloku ekran „/odwolanie/{id}" nie miałby czego pokazać dla
 * żadnego konta z demo, więc dopisujemy dane TUTAJ, tym samym mechanizmem
 * co `adresPrzepisu` i `wpisyPoTrybie` wyżej — pytaniem (i, gdy trzeba,
 * jednym zapisem) do bazy przez `tinker` — a NIE zmianą `DemoSeeder`, który
 * jest czyjąś cudzą, trwającą pracą.
 *
 * Sprawdzenie „czy już jest" PRZED zapisem czyni to bezpiecznym do
 * odpalenia także wtedy, gdy `ADRES` wskazuje serwer już postawiony wcześniej
 * (bez świeżego `migrate:fresh`) — drugie uruchomienie znajdzie ten sam
 * wiersz zamiast dokładać kolejny.
 *
 * `warn` jest w `ODWOLYWALNE` (da się od niego odwołać) i w `DOZWOLONE` dla
 * każdego typu celu, który tu wchodzi w grę, a przy tym nie zmienia
 * widoczności treści — więc nie kolidujemy z żadnym innym ekranem, który
 * tę samą treść ogląda.
 *
 * TYP CELU LICZYMY Z TEGO, CO NAPRAWDĘ ZNALEŹLIŚMY. Stało tu na sztywno
 * `'target_type' => 'recipe'`, a `DemoSeeder` nie daje temu kontu ANI
 * JEDNEGO przepisu: `Recipe::create` jest tam wyłącznie dla `basia`
 * (rosół) i `marek` (chleb) — sprawdzone
 * `grep -n 'Recipe::create' database/seeders/DemoSeeder.php`, dwa
 * wystąpienia, oba cudze. Automat zawsze wpadał więc w gałąź `Post`
 * i zapisywał identyfikator WPISU opisany jako przepis. Ekran odwołania
 * dziś tego nie pokazuje, ale pierwszy ekran, który zechce wyświetlić
 * zgłoszoną treść, dostałby `null`.
 *
 * Dopuszczalne wartości `target_type` to klucze `ModerationAction::DOZWOLONE`
 * (`user`, `post`, `recipe`, `comment`, `cooked_event`). W bazie ta kolumna
 * jest zwykłym `varchar(30)` bez CHECK-a (CHECK ma tylko `reports`), więc
 * pomyłki nie łapało nic.
 *
 * Ostatnia deska ratunku to samo konto (`user`, `target_id` równe jego `id`):
 * `warn` jest dla `user` dozwolony, a taki cel ISTNIEJE — inaczej niż losowy
 * UUID, który stał tu wcześniej i z definicji nie wskazywał niczego.
 */
const idOdwolania = (() => {
  const id = execFileSync('php', ['artisan', 'tinker', '--execute',
    "$m = App\\Models\\User::where('role','moderator')->value('id'); "
    // `username` mieszka na `Profile` (klucz główny `user_id`), nie na
    // `User` — patrz komentarz w App\Models\User o danych publicznych.
    + `$b = App\\Models\\Profile::where('username','${KONTO_ZALOGOWANE}')->value('user_id'); `
    + "if (!$m || !$b) { echo ''; exit; } "
    + "$a = App\\Models\\ModerationAction::where('subject_user_id',$b)"
    + "->whereIn('action', App\\Models\\ModerationAction::ODWOLYWALNE)->first(); "
    + "if (!$a) { "
    + "$przepis = App\\Models\\Recipe::where('author_id',$b)->value('id'); "
    + "$wpis = $przepis ? null : App\\Models\\Post::where('author_id',$b)->value('id'); "
    + "$cel = $przepis ?? $wpis ?? $b; "
    + "$typ = $przepis ? 'recipe' : ($wpis ? 'post' : 'user'); "
    + "$a = App\\Models\\ModerationAction::create(['moderator_id'=>$m,'target_type'=>$typ,"
    + "'target_id'=>$cel,'subject_user_id'=>$b,'action'=>'warn','reason_code'=>'niezgodne_z_zasadami',"
    + "'note'=>'Utworzone przez automat dostępności (scripts/dostepnosc.mjs) do zmierzenia ekranu odwołania.',"
    // Komunikat bez słowa „przepis": ta sama decyzja dotyczy dziś wpisu,
    // a przy innej zawartości bazy — przepisu albo konta.
    + "'user_message'=>'Ta treść reklamowała konkretny sklep, co jest niezgodne z naszymi zasadami. "
    + "Poprawiliśmy opis i treść zostaje widoczna — to ostrzeżenie zapisujemy do wiadomości.']); "
    + "} echo $a->getKey();",
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA } })
    .toString().trim();

  return id === '' ? null : id;
})();

if (idOdwolania === null) {
  console.error(`BŁĄD: nie udało się przygotować decyzji moderacyjnej dla „${KONTO_ZALOGOWANE}" — `
    + 'ekran odwołania nie zostałby sprawdzony (brak konta moderatora albo tego konta w bazie).');
  zamknij();
  process.exit(1);
}

/*
 * Zgłoszenie ZŁOŻONE przez konto, którym ten automat się loguje, wraz
 * z rozstrzygnięciem (issue #10). Ten sam mechanizm i te same powody co przy
 * `idOdwolania` wyżej: `DemoSeeder` nie tworzy żadnego `Report`, a karta
 * sprawy jest widoczna wyłącznie dla zgłaszającego (`ReportPolicy::view()`),
 * więc bez tych danych automat mierzyłby stronę 403.
 *
 * ROZSTRZYGNIĘTE, NIE OTWARTE — bo sprawa zamknięta pokazuje WIĘCEJ:
 * decyzję i pełne pouczenie o dostępnych środkach. Ekran sprawy otwartej to
 * podzbiór tego samego układu.
 *
 * DECYZJA `no_action`, ŚWIADOMIE. Jako jedyna nie rusza ani treści, ani
 * konta (`ModerationController::applyAction()`), więc dołożenie tych danych
 * nie zmienia ANI JEDNEGO innego ekranu z listy wyżej. `hide` ukryłby
 * demonstracyjny wpis oglądany przez trzy inne pozycje.
 *
 * CEL MUSI ISTNIEĆ I NIE MOŻE BYĆ WŁASNY: zgłoszenie społecznościowe wymaga
 * niepustego `target_id` (CHECK `reports_community_target_check`), a
 * zgłaszanie własnego wpisu byłoby danymi, których w produkcie prawie nie ma.
 * Bierzemy więc dowolny CUDZY wpis z demo.
 */
const idZgloszenia = (() => {
  const id = execFileSync('php', ['artisan', 'tinker', '--execute',
    `$b = App\\Models\\Profile::where('username','${KONTO_ZALOGOWANE}')->value('user_id'); `
    + "$m = App\\Models\\User::where('role','moderator')->value('id'); "
    + "if (!$b || !$m) { echo ''; exit; } "
    + "$z = App\\Models\\Report::where('reporter_id',$b)->first(); "
    + "if (!$z) { "
    + "$cel = App\\Models\\Post::where('author_id','!=',$b)->value('id'); "
    + "if (!$cel) { echo ''; exit; } "
    + "$z = App\\Models\\Report::create(['reporter_id'=>$b,'target_type'=>'post','target_id'=>$cel,"
    + "'reason'=>'harassment','details'=>'Ten wpis wyśmiewa konkretną osobę z nazwiska.',"
    + "'status'=>App\\Models\\Report::STATUS_REJECTED,'resolved_by'=>$m,'resolved_at'=>now(),"
    + "'resolution_note'=>'Utworzone przez automat dostępności (scripts/dostepnosc.mjs).']); "
    + "} "
    + "if (! App\\Models\\ModerationAction::where('report_id',$z->getKey())->exists()) { "
    + "App\\Models\\ModerationAction::create(['moderator_id'=>$m,'report_id'=>$z->getKey(),"
    + "'target_type'=>$z->target_type,'target_id'=>$z->target_id,'action'=>'no_action',"
    + "'reason_code'=>'bez_podstaw','note'=>'Utworzone przez automat dostępności.']); "
    + "} echo $z->getKey();",
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA } })
    .toString().trim();

  return id === '' ? null : id;
})();

if (idZgloszenia === null) {
  console.error(`BŁĄD: nie udało się przygotować zgłoszenia dla „${KONTO_ZALOGOWANE}" — `
    + 'karta sprawy nie zostałaby sprawdzona (brak konta moderatora, tego konta albo cudzego wpisu w bazie).');
  zamknij();
  process.exit(1);
}

/*
 * Adres ekranu z listy: `adres` wprost albo `znajdz` do rozwiązania z bazy.
 *
 * `znajdz: 'przepis'` → dowolny opublikowany przepis z demo,
 * `znajdz: 'gotowanie'` → tryb gotowania tego samego przepisu,
 * `znajdz: 'wpis:carousel'` → wpis w tym trybie,
 * `znajdz: 'wpis:carousel:zdjecia'` → ekran kolejności i wyglądu tego wpisu,
 * `znajdz: 'odwolanie'` → decyzja moderacyjna przygotowana wyżej dla konta,
 *                        którym automat się loguje (`KONTO_ZALOGOWANE`),
 * `znajdz: 'zgloszenie'` → karta sprawy zgłoszenia złożonego przez to konto.
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

  if (ekran.znajdz === 'gotowanie') {
    return adresGotowania;
  }

  if (ekran.znajdz === 'odwolanie') {
    return `/odwolanie/${idOdwolania}`;
  }

  if (ekran.znajdz === 'zgloszenie') {
    return `/zgloszenia/${idZgloszenia}`;
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
    viewport: { width: wariant.szerokosc, height: 900 },
    /*
     * `reducedMotion: 'reduce'` NIE JEST tu kosmetyką ani przyspieszeniem.
     *
     * `.btn` ma `transition: background-color .15s`. Odkąd motyw ciemny
     * włącza się ATRYBUTEM (a nie `prefers-color-scheme` ustawionym przed
     * wczytaniem strony), przełączenie uruchamia to przejście — a axe czytał
     * kolory w jego trakcie i widział tło w POŁOWIE DROGI z białego do
     * ciemnego. Przy jasnym tekście dawało to sześć fałszywych naruszeń
     * kontrastu na `.btn-secondary`. Zmierzone: natychmiast po przełączeniu
     * `rgb(255,255,255)`, po 400 ms `rgb(42,36,30)` — czyli poprawna wartość
     * `--color-surface-raised`. Kontrast był poprawny cały czas; zły był
     * moment pomiaru.
     *
     * Arkusz honoruje `prefers-reduced-motion: reduce` (tokens.css) i skraca
     * wtedy przejścia do 0,01 ms, więc to ustawienie daje stan KOŃCOWY bez
     * czekania i bez zgadywania. Wstrzyknięcie `<style>` z `transition: none`
     * NIE WCHODZI W GRĘ: CSP jest wymuszające i słusznie je odrzuca (`style-src`
     * bez `unsafe-inline`) — automat dostał tym po palcach i dobrze.
     *
     * Efekt uboczny jest pożądany: mierzymy stronę tak, jak widzi ją osoba,
     * która w systemie poprosiła o ograniczenie animacji.
     */
    reducedMotion: 'reduce',
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

    const odpowiedz = await strona.goto(zamowiony, { waitUntil: 'domcontentloaded' });

    /*
     * KOD HTTP MUSI BYĆ 200 — I TO JEST OSOBNE SPRAWDZENIE NIŻ ŚCIEŻKA NIŻEJ.
     *
     * Strona 404 ma tę samą ścieżkę, o którą prosiliśmy, więc porównanie
     * ścieżek jej NIE łapie. A strona błędu to kilka wierszy tekstu i jeden
     * link: przechodzi każdy audyt dostępności, nie sprawdzając niczego —
     * dokładnie ta klasa fałszywej zieleni, przed którą ostrzega nagłówek
     * tego pliku i przez którą ekran przepisu raz już po cichu wypadł
     * z raportu.
     *
     * ZMIERZONE 7 września: `/tag/zupy` odpowiada 200, a `/tag/zupa` — 404,
     * czyli odwrotnie niż mówi notatka w `docs/HANDOVER.md` §7.2.2. Powód:
     * ten skrypt sieje SAMYM `DemoSeeder`-em, bez `TagSeeder`-a, więc słownika
     * tagów w bazie nie ma i `ResolveTagsForPost` nie ma czego scalać —
     * „zupy" zostaje tagiem kanonicznym. Scalenie do „zupa" z D-026 zachodzi
     * dopiero po `php artisan db:seed` z oboma seederami. Gdyby `DemoSeeder`
     * kiedyś przestał tworzyć ten tag, TO sprawdzenie o tym powie — samo
     * porównanie ścieżek milczało.
     */
    const kod = odpowiedz?.status() ?? 0;

    if (kod !== 200) {
      console.error(
        `BŁĄD: ekran „${ekran.nazwa}" (${sciezka}) odpowiedział kodem ${kod}. `
        + 'Raport badałby stronę błędu, która przechodzi audyt, nie sprawdzając niczego.',
      );
      process.exitCode = 1;
      continue;
    }

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

    // Motyw ciemny — WYŁĄCZNIE ten atrybut go włącza (docs/DECISIONS.md,
    // D-019). Kontekst przeglądarki (`colorScheme`) już nic by tu nie dał —
    // arkusz stylów celowo nie ogląda się na `prefers-color-scheme`.
    //
    // Przejścia CSS są tu wyłączone przez `reducedMotion` na kontekście —
    // patrz komentarz przy `ustawienia` wyżej.
    //
    // ALE TO NIE WYSTARCZA i to jest zmierzone, nie założone. Przy pełnym
    // przebiegu ten automat zgłaszał `color-contrast` na elemencie `<time>`
    // w karcie wpisu („Świeżo z Kuking / ciemny", 6 węzłów, waga serious).
    // Bezpośredni pomiar tego samego elementu dał kontrast 8,38:1 przy
    // wymaganym 4,5:1 (`rgb(201,190,176)` na `rgb(42,36,30)`), a izolowany
    // przebieg TEJ SAMEJ strony w TYM SAMYM wariancie nie zgłaszał niczego.
    // Czyli naruszenie zależało od kolejności ekranów w przebiegu, a nie od
    // palety — axe czytał kolory, zanim przeglądarka przemalowała stronę po
    // zmianie atrybutu.
    //
    // To już drugi raz, gdy ten automat oskarżył paletę o coś, czego w niej
    // nie ma (poprzedni raz: sześć naruszeń w motywie ciemnym, też artefakt
    // pomiaru). Fałszywy alarm z wagą „blokujące" jest gorszy niż brak
    // sprawdzenia, bo uczy ludzi ignorować wynik.
    //
    // Dlatego nie czekamy tu na sztywną liczbę milisekund, tylko na WARUNEK:
    // aż tło strony faktycznie zmieni wartość. Warunek nie zgaduje i nie
    // rozjedzie się na szybszej ani wolniejszej maszynie.
    if (wariant.motyw === 'dark') {
      const tloPrzed = await strona.evaluate(
        () => getComputedStyle(document.body).backgroundColor,
      );

      await strona.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));

      await strona.waitForFunction(
        (przed) => getComputedStyle(document.body).backgroundColor !== przed,
        tloPrzed,
        { timeout: 5000 },
      );

      // Dwa pełne obiegi klatki: pierwszy kończy przeliczanie stylów, drugi
      // daje pewność, że przemalowanie już się odbyło. Bez tego `waitForFunction`
      // potrafi wrócić w momencie, w którym styl JEST policzony, ale piksele
      // jeszcze nie.
      await strona.evaluate(() => new Promise((gotowe) => {
        requestAnimationFrame(() => requestAnimationFrame(() => gotowe(null)));
      }));
    }

    /*
     * OTWIERAMY KAŻDY `<details>` PRZED POMIAREM.
     *
     * Treść zamkniętego `<details>` nie ma `display: none` w arkuszu stylów
     * — ale przeglądarka i tak traktuje ją jak niewidoczną (`checkVisibility()`
     * zwraca `false`), bo tak każe robić specyfikacja HTML z zamkniętym
     * `<details>`. Axe pomija to, co niewidoczne, tak samo jak pomija tekst
     * `sr-only` odwrócony transformacją. Zmierzone wprost na ekranie „twoje
     * dane": axe analizuje 8 węzłów wewnątrz zamkniętego `<details>` (sam
     * `<summary>`), a 34, gdy jest otwarty — różnica to dokładnie hasło,
     * oba haczyki i przycisk „Usuń moje konto" formularza usunięcia konta
     * (D-022). Bez tego otwarcia automat NIGDY nie sprawdziłby etykiet ani
     * kontrastu w najważniejszym nieodwracalnym formularzu serwisu — zielony
     * wynik na tym ekranie nic by nie znaczył.
     */
    await strona.evaluate(() => {
      for (const el of document.querySelectorAll('details:not([open])')) {
        el.open = true;
      }
    });

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
    const opis = `${szerokosc} px${etykietaSkali(skala)}`;

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

      if (skala === PRZEGLADARKA_200) {
        // PRZED nawigacją, bo to ma być stan przeglądarki zastany przez
        // stronę, a nie zmiana doklejona po jej ułożeniu.
        const cdp = await kontekst.newCDPSession(strona);

        await cdp.send('Page.setFontSizes', {
          fontSizes: { standard: 2 * BAZOWA_CZCIONKA_PX, fixed: 2 * BAZOWA_CZCIONKA_PX },
        });
      }

      const odpowiedzUkladu = await strona.goto(
        sciezka.startsWith('http') ? sciezka : `${adres}${sciezka}`,
        { waitUntil: 'domcontentloaded' },
      );

      // Ten sam powód co w pętli axe wyżej: strona błędu nie przewija się
      // w bok, więc bez tego sprawdzenia zgłaszałaby się jako poprawna.
      const kodUkladu = odpowiedzUkladu?.status() ?? 0;

      if (kodUkladu !== 200) {
        console.error(
          `BŁĄD: ekran „${ekran.nazwa}" (${sciezka}) odpowiedział kodem ${kodUkladu} `
          + 'przy pomiarze układu.',
        );
        process.exitCode = 1;
        await strona.close();
        continue;
      }

      if (skala === PRZEGLADARKA_200) {
        // KONTROLA METODY POMIARU, nie ozdoba. Sprawdzamy OBIE własności,
        // bo to one odróżniają prawdziwą zmianę czcionki od jej podróbki
        // (uzasadnienie w komentarzu przy PRZEGLADARKA_200 na górze pliku).
        const stan = await strona.evaluate(() => ({
          korzen: Number.parseFloat(getComputedStyle(document.documentElement).fontSize),
          desktop: matchMedia('(min-width: 64rem)').matches,
        }));

        if (stan.korzen < 2 * BAZOWA_CZCIONKA_PX) {
          console.error(
            `BŁĄD: czcionka korzenia to ${stan.korzen} px zamiast `
            + `${2 * BAZOWA_CZCIONKA_PX} px na ekranie „${ekran.nazwa}". `
            + 'Bez tego wariant przechodziłby na zielono, nie mierząc niczego.',
          );
          process.exitCode = 1;
          await strona.close();
          continue;
        }

        if (stan.desktop) {
          console.error(
            `BŁĄD: przy podwojonej czcionce próg 64rem nadal się załapał na `
            + `ekranie „${ekran.nazwa}" (okno ${szerokosc} px). To znaczy, że `
            + 'zmiana nie dotknęła bazy media queries — mierzylibyśmy układ '
            + 'desktopowy z podwojonym tekstem, czyli stan, w którym żaden '
            + 'człowiek nie jest.',
          );
          process.exitCode = 1;
          await strona.close();
          continue;
        }
      } else if (skala) {
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

/* =============================================================================
   WYBÓR ZDJĘCIA BEZ JAVASCRIPTU (decyzja właściciela D-035)

   DLACZEGO TO NIE MOŻE BYĆ ANI TEST PHPUnit, ANI PRZEBIEG AXE
   Od D-035 natywne `<input type="file">` jest schowane dla oka, a klikalny
   jest duży obszar „Dodaj zdjęcie" — prawdziwy `<label for>`. Test w PHP
   sprawdza, że pole i etykieta są w HTML-u i że są ze sobą związane
   (`PoleWyboruZdjeciaTest`), i to wystarcza do złapania literówki w `for`.
   NIE sprawdzi dwóch rzeczy, które w tej decyzji są WARUNKIEM:

     1. czy pole, którego nie widać, dalej DA SIĘ ZŁAPAĆ KLAWISZEM TAB.
        `display: none` i `visibility: hidden` wyjmują je z kolejności
        tabulacji, a w kodzie strony wygląda to identycznie — różnicę widać
        dopiero na ułożonej stronie;
     2. czy po zatrzymaniu się na nim WIDAĆ, GDZIE SIĘ JEST. Pierścień fokusu
        rysuje się nie na polu, tylko na etykiecie obok
        (`.pole-zdjecia-input:focus-visible + .pole-zdjecia`), więc trzeba
        odczytać wyliczony styl innego elementu niż ten, który ma fokus.

   Axe tego nie złapie: pole ma etykietę i nazwę dostępną w OBU przypadkach,
   a `:focus-visible` nie jest regułą axe. To jest dokładnie ta sama klasa
   usterki, dla której powstał pomiar układu wyżej — drzewo dokumentu wygląda
   dobrze, a człowiek przed ekranem nie może zrobić tego, co miał zrobić.

   `javaScriptEnabled: false`, bo wybór zdjęcia ma działać bez skryptu
   (AGENTS.md §5). Fokus stawiamy KLAWISZEM, nie `element.focus()`:
   `:focus-visible` jest heurystyką przeglądarki i przy fokusie z kodu
   potrafi się nie włączyć — mierzylibyśmy wtedy coś innego niż to, co dostaje
   osoba idąca Tabem.
   ========================================================================== */
log('');
log('Wybór zdjęcia bez JavaScriptu:');

/** Ile razy najwyżej naciskamy Tab, zanim uznamy, że pola nie ma w kolejności. */
const MAKS_TABOW = 60;

const wyborZdjeciaBezJs = await (async () => {
  const kontekst = await przegladarka.newContext({
    viewport: { width: 360, height: 740 },
    javaScriptEnabled: false,
    storageState: stanZalogowany,
  });

  const strona = await kontekst.newPage();
  const odpowiedz = await strona.goto(`${adres}/dodaj/zdjecie`, { waitUntil: 'domcontentloaded' });
  const kod = odpowiedz?.status() ?? 0;

  // Ten sam powód co przy pomiarze układu: strona błędu nie ma pola wyboru
  // zdjęcia, więc bez tego sprawdzenia przebieg zgłaszałby „nie znalazłem"
  // zamiast „nie byłem na właściwej stronie".
  if (kod !== 200) {
    console.error(`BŁĄD: „/dodaj/zdjecie" odpowiedziało kodem ${kod} — wybór zdjęcia nie został sprawdzony.`);
    process.exitCode = 1;
    await kontekst.close();

    return null;
  }

  let krokow = 0;
  let aktywne = null;

  while (krokow < MAKS_TABOW) {
    await strona.keyboard.press('Tab');
    krokow++;

    aktywne = await strona.evaluate(() => {
      const el = document.activeElement;

      return el ? { id: el.id, typ: el.getAttribute('type') } : null;
    });

    if (aktywne?.id === 'f-photos') {
      break;
    }
  }

  const doszloTabem = aktywne?.id === 'f-photos' && aktywne?.typ === 'file';

  const pomiar = doszloTabem
    ? await strona.evaluate(() => {
      const pole = document.getElementById('f-photos');
      const etykieta = document.querySelector('label[for="f-photos"]');
      const stylPola = getComputedStyle(pole);
      const stylEtykiety = etykieta ? getComputedStyle(etykieta) : null;

      return {
        etykiet: document.querySelectorAll('label[for="f-photos"]').length,
        // Te dwie wartości są sednem D-035: pole wolno schować dla oka,
        // ale NIE WOLNO go schować przed klawiaturą i czytnikiem.
        display: stylPola.display,
        visibility: stylPola.visibility,
        obrys: stylEtykiety ? stylEtykiety.outlineStyle : null,
        gruboscObrysu: stylEtykiety ? Math.round(parseFloat(stylEtykiety.outlineWidth) || 0) : 0,
      };
    })
    : null;

  await kontekst.close();

  return {
    krokowTabem: krokow,
    doszloTabem,
    etykiet: pomiar?.etykiet ?? 0,
    display: pomiar?.display ?? null,
    visibility: pomiar?.visibility ?? null,
    obrys: pomiar?.obrys ?? null,
    gruboscObrysu: pomiar?.gruboscObrysu ?? 0,
    zostajeWDrzewie: pomiar !== null && pomiar.display !== 'none' && pomiar.visibility !== 'hidden',
    // Jedna etykieta, nie dwie: dwie na jedno pole to znany błąd
    // (axe `form-field-multiple-labels`), a przy tym wzorcu łatwo go dołożyć.
    jednaEtykieta: pomiar?.etykiet === 1,
    widocznyFokusNaObszarze: pomiar !== null && pomiar.obrys !== 'none' && pomiar.gruboscObrysu > 0,
  };
})();

if (wyborZdjeciaBezJs) {
  const dobrze = wyborZdjeciaBezJs.doszloTabem
    && wyborZdjeciaBezJs.zostajeWDrzewie
    && wyborZdjeciaBezJs.jednaEtykieta
    && wyborZdjeciaBezJs.widocznyFokusNaObszarze;

  log(`  ${dobrze ? '✓' : '✗'} Tab dochodzi do pola po ${wyborZdjeciaBezJs.krokowTabem} krokach`
    + ` (${wyborZdjeciaBezJs.doszloTabem ? 'tak' : 'NIE'})`
    + `, etykiet: ${wyborZdjeciaBezJs.etykiet}`
    + `, pole display: ${wyborZdjeciaBezJs.display} / visibility: ${wyborZdjeciaBezJs.visibility}`
    + `, obrys na obszarze: ${wyborZdjeciaBezJs.obrys} ${wyborZdjeciaBezJs.gruboscObrysu} px`);

  if (! dobrze) {
    process.exitCode = 1;
  }
}

/* =============================================================================
   WYRÓWNANIE BELKI DO SIATKI TREŚCI

   DLACZEGO TO NIE MOŻE BYĆ TEST PHPUnit
   Ta sama przyczyna co przy przepełnieniu wyżej: żeby stwierdzić, że logotyp
   stoi nad nawigacją, a nie 144 px na prawo od niej, trzeba ZMIERZYĆ ułożoną
   stronę. Drzewo dokumentu wygląda poprawnie w obu przypadkach, a arkusz
   stylów sam z siebie nie zdradza, że `.app-body` nigdy nie osiąga swojego
   `max-width` (jest elementem `flex` z `margin: 0 auto`, więc zwęża się do
   zawartości). Właśnie dlatego rozjazd przetrwał: liczby w CSS wyglądały
   sensownie, a ułożona strona wyglądała inaczej.

   CO DOKŁADNIE SPRAWDZAMY
   Nie „czy logotyp jest przy nawigacji" (to wymagałoby innego punktu odniesienia
   na każdym ekranie i przy każdej szerokości), tylko regułę ogólniejszą:

       wewnętrzne krawędzie belki == wewnętrzne krawędzie kolumny treści

   Kolumny siatki zaczynają się i kończą dokładnie na tych krawędziach, więc
   z tej jednej równości wynikają obie rzeczy naraz — logotyp nad nawigacją
   (albo nad treścią, gdy nawigacji nie ma) i akcje nad prawą szyną (albo nad
   prawą krawędzią treści, gdy szyna zeszła pod spód).

   CZYM JEST „KOLUMNA TREŚCI" — DWA PRZYPADKI, JEDNA REGUŁA
   Do 8 września istniał jeden: `.app-body`, czyli siatka ekranu. Od dziś
   strona powitalna stoi na PASACH — sekcjach na całą szerokość okna, w których
   szerokość treści pilnuje `.pas-wnetrze`. Na takiej stronie `.app-body` NIE
   JEST kolumną treści: rozciąga się od krawędzi do krawędzi okna, bo to pas
   ma własne tło i musi tam dojść.

   Punktem odniesienia jest więc `.pas-wnetrze`, gdy strona je ma, a `.app-body`
   w pozostałych przypadkach. To NIE JEST poluzowanie sprawdzenia — mierzona
   jest dokładnie ta sama rzecz (krawędź, przy której zaczyna się pierwsze
   słowo treści), tylko odczytana z elementu, który tę krawędź naprawdę
   wyznacza. Sprawdzenie dalej pada, gdy belka i treść się rozjadą.

   Zmierzone na przebiegu 168, PRZED tą poprawką: automat oczekiwał logotypu
   na 0 px przy każdej szerokości, bo `.app-body` zaczyna się teraz w zerze.
   Zgłosił rozjazd 24 px przy 1024, 144 px przy 1280 i 260 px przy 1512 —
   a to są DOKŁADNIE lewe krawędzie pasa przy tych szerokościach
   ((1512 − 1040) / 2 + 24 = 260). Belka licowała co do piksela; złe było
   odniesienie, nie układ.

   Poniżej 64rem nie mierzymy: nie ma tam ani nawigacji bocznej, ani szyny,
   a belce wolno zawijać akcje do drugiego wiersza (issue #80).

   DRUGA REGUŁA W TYM SAMYM POMIARZE: POLE „SZUKAJ" NAD KOLUMNĄ CZYTANIA
   Krawędzie zewnętrzne mogą się zgadzać przy złamanym środku — i tak było do
   8 września 2026. Belka była wierszem `flex` z `space-between`, więc logotyp
   i akcje licowały co do piksela, a pole „Szukaj" pomiędzy nimi miało własne
   `flex: 1 1 auto`, własny sufit 34 rem i własne marginesy. Stało nad tekstem,
   którego nie dotykało. Od dziś belka ma od 80rem tę samą trzykolumnową
   siatkę co treść, a to sprawdzenie tego pilnuje: krawędzie `.topbar-szukaj`
   == krawędzie `.app-main`.

   Liczone dopiero od 1280 px, bo tam włącza się i szyna, i ta siatka. Na
   ekranach gościa pomijane — gość nie ma pola „Szukaj" w belce.
   ========================================================================== */
log('');
log('Wyrównanie belki do siatki treści:');

const EKRANY_WYROWNANIA = [
  { nazwa: 'tablica (z szyną)', adres: '/home', zalogowany: true },
  { nazwa: 'zeszyt (bez szyny)', adres: '/zeszyt', zalogowany: true },
  { nazwa: 'powiadomienia (bez szyny)', adres: '/powiadomienia', zalogowany: true },
  { nazwa: 'Świeżo z Kuking (gość)', adres: '/odkryj' },
  // Strona powitalna ma OD 8 WRZEŚNIA układ pasów: `.app-body` idzie od
  // krawędzi do krawędzi okna, a szerokość treści wyznacza `.pas-wnetrze`.
  // To jest jedyny ekran o takim układzie i dlatego jedyny, który tę
  // gałąź pomiaru w ogóle wykonuje. Reguła spójności szerokości go nie
  // dotyczy (ta liczy wyłącznie ekrany zalogowanego); ta pozycja pilnuje
  // drugiej reguły: że logotyp i przyciski stoją dokładnie nad krawędziami
  // treści — czyli nad pierwszym i ostatnim znakiem w pasie.
  { nazwa: 'strona powitalna (gość)', adres: '/' },
];

// 1024 to próg nawigacji bocznej, 1280 progu szyny, 1512 typowy laptop —
// przy każdej z tych szerokości siatka liczy się inaczej, a rozjazd przed
// poprawką szedł raz w lewo, raz w prawo.
const SZEROKOSCI_WYROWNANIA = SZYBKO ? [1512] : [1024, 1280, 1512];

const rozjazdyBelki = [];

/** Krawędzie siatki pierwszego zmierzonego ekranu zalogowanego, per szerokość. */
const krawedzieZalogowanego = new Map();

/** Podstrony zalogowanego, które mają inną szerokość niż pierwsza zmierzona. */
const niespojneSzerokosci = [];

for (const szerokosc of SZEROKOSCI_WYROWNANIA) {
  const kontekstGoscia = await przegladarka.newContext({
    viewport: { width: szerokosc, height: 900 },
  });
  const kontekstZalogowanego = await przegladarka.newContext({
    viewport: { width: szerokosc, height: 900 },
    storageState: stanZalogowany,
  });

  for (const ekran of EKRANY_WYROWNANIA) {
    const kontekst = ekran.zalogowany ? kontekstZalogowanego : kontekstGoscia;
    const strona = await kontekst.newPage();

    await strona.goto(`${adres}${ekran.adres}`, { waitUntil: 'domcontentloaded' });

    const pomiar = await strona.evaluate(() => {
      // Kolumna treści: wnętrze pierwszego pasa, a gdy strona nie stoi na
      // pasach — siatka ekranu. Patrz „CZYM JEST KOLUMNA TREŚCI" wyżej.
      const body = document.querySelector('.pas-wnetrze') ?? document.querySelector('.app-body');
      const logotyp = document.querySelector('.wordmark');
      const akcje = document.querySelector('.topbar-actions');

      if (! body || ! logotyp || ! akcje) return null;

      const ramka = body.getBoundingClientRect();
      const styl = getComputedStyle(body);

      const stopka = document.querySelector('.site-footer-inner');
      const stylStopki = stopka ? getComputedStyle(stopka) : null;
      const ramkaStopki = stopka ? stopka.getBoundingClientRect() : null;

      // Pole „Szukaj" i kolumna czytania. Mierzymy je osobno od krawędzi
      // zewnętrznych, bo to jest inna reguła: nie „belka ma tę samą
      // szerokość co treść", tylko „pole stoi DOKŁADNIE nad tekstem, który
      // przeszukuje". Pierwsza może być spełniona przy złamanej drugiej —
      // i przez pół roku była.
      const szukaj = document.querySelector('.topbar-szukaj');
      const kolumna = document.querySelector('.app-main');
      const widoczne = szukaj !== null && getComputedStyle(szukaj).display !== 'none';
      const ramkaSzukaj = widoczne ? szukaj.getBoundingClientRect() : null;
      const ramkaKolumny = kolumna ? kolumna.getBoundingClientRect() : null;

      return {
        oczekiwanaLewa: Math.round(ramka.left + parseFloat(styl.paddingLeft)),
        oczekiwanaPrawa: Math.round(ramka.right - parseFloat(styl.paddingRight)),
        lewa: Math.round(logotyp.getBoundingClientRect().left),
        prawa: Math.round(akcje.getBoundingClientRect().right),
        stopkaLewa: ramkaStopki
          ? Math.round(ramkaStopki.left + parseFloat(stylStopki.paddingLeft))
          : null,
        stopkaPrawa: ramkaStopki
          ? Math.round(ramkaStopki.right - parseFloat(stylStopki.paddingRight))
          : null,
        szukajLewa: ramkaSzukaj && ramkaKolumny ? Math.round(ramkaSzukaj.left) : null,
        szukajPrawa: ramkaSzukaj && ramkaKolumny ? Math.round(ramkaSzukaj.right) : null,
        kolumnaLewa: ramkaSzukaj && ramkaKolumny ? Math.round(ramkaKolumny.left) : null,
        kolumnaPrawa: ramkaSzukaj && ramkaKolumny ? Math.round(ramkaKolumny.right) : null,
      };
    });

    await strona.close();

    if (! pomiar) {
      console.error(`BŁĄD: na ekranie „${ekran.nazwa}" brakuje kolumny treści (.pas-wnetrze albo .app-body), .wordmark albo .topbar-actions.`);
      process.exitCode = 1;
      continue;
    }

    // Jeden piksel tolerancji na zaokrąglenie — układ liczy się w ułamkach.
    const bladLewej = Math.abs(pomiar.lewa - pomiar.oczekiwanaLewa);
    const bladPrawej = Math.abs(pomiar.prawa - pomiar.oczekiwanaPrawa);
    const bladStopkiL = pomiar.stopkaLewa === null
      ? 0 : Math.abs(pomiar.stopkaLewa - pomiar.oczekiwanaLewa);
    const bladStopkiP = pomiar.stopkaPrawa === null
      ? 0 : Math.abs(pomiar.stopkaPrawa - pomiar.oczekiwanaPrawa);

    // Pole „Szukaj" liczy się dopiero od 1280 px: niżej belka jest wierszem
    // `flex` z zawijaniem, bo akcje muszą mieć prawo zejść do drugiego
    // wiersza (issue #80). Siatka trzykolumnowa włącza się razem z szyną.
    const mierzymySzukaj = pomiar.szukajLewa !== null && szerokosc >= 1280;
    const bladSzukajL = mierzymySzukaj ? Math.abs(pomiar.szukajLewa - pomiar.kolumnaLewa) : 0;
    const bladSzukajP = mierzymySzukaj ? Math.abs(pomiar.szukajPrawa - pomiar.kolumnaPrawa) : 0;

    if (bladLewej > 1 || bladPrawej > 1 || bladStopkiL > 1 || bladStopkiP > 1
      || bladSzukajL > 1 || bladSzukajP > 1) {
      rozjazdyBelki.push({
        ekran: ekran.nazwa, szerokosc, ...pomiar,
        bladLewej, bladPrawej, bladStopkiL, bladStopkiP, bladSzukajL, bladSzukajP,
      });
    }

    // DRUGA, OSOBNA REGUŁA: siatka ma stać w TYM SAMYM MIEJSCU na wszystkich
    // ekranach zalogowanego. To jest dokładnie to, co zgłosił właściciel —
    // nawigacja boczna przeskakiwała mu między podstronami, bo strona
    // z prawą szyną była szersza niż strona bez niej. Sprawdzenie wyżej tego
    // nie łapie: tam każda podstrona porównuje się sama ze sobą.
    if (! ekran.zalogowany) continue;

    const wzorzec = krawedzieZalogowanego.get(szerokosc);

    if (! wzorzec) {
      krawedzieZalogowanego.set(szerokosc, { ekran: ekran.nazwa, ...pomiar });
    } else if (
      Math.abs(wzorzec.oczekiwanaLewa - pomiar.oczekiwanaLewa) > 1
      || Math.abs(wzorzec.oczekiwanaPrawa - pomiar.oczekiwanaPrawa) > 1
    ) {
      niespojneSzerokosci.push({
        szerokosc,
        pierwszy: wzorzec.ekran,
        pierwszyOd: wzorzec.oczekiwanaLewa,
        pierwszyDo: wzorzec.oczekiwanaPrawa,
        drugi: ekran.nazwa,
        drugiOd: pomiar.oczekiwanaLewa,
        drugiDo: pomiar.oczekiwanaPrawa,
      });
    }
  }

  await kontekstGoscia.close();
  await kontekstZalogowanego.close();

  const zlych = rozjazdyBelki.filter((r) => r.szerokosc === szerokosc).length
    + niespojneSzerokosci.filter((r) => r.szerokosc === szerokosc).length;

  log(`  ${zlych === 0 ? '✓' : '✗'} ${szerokosc} px`
    + (zlych ? ` — ${zlych} niezgodności` : ''));
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
  wyborZdjeciaBezJs,
  wyrownanieBelki: {
    szerokosci: SZEROKOSCI_WYROWNANIA,
    rozjazdow: rozjazdyBelki.length,
    rozjazdy: rozjazdyBelki,
    niespojnychSzerokosci: niespojneSzerokosci.length,
    niespojneSzerokosci,
  },
}, null, 2));

log('');
log(`Wynik zapisany: storage/dostepnosc.json (naruszeń: ${wyniki.length}, `
  + `blokujących: ${blokujacych}, przepełnień w poziomie: ${przepelnienia.length}, `
  + `rozjazdów belki: ${rozjazdyBelki.length}, `
  + `niespójnych szerokości: ${niespojneSzerokosci.length})`);

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

if (rozjazdyBelki.length > 0) {
  log('');
  log('Belka nie licuje z siatką treści:');
  for (const r of rozjazdyBelki) {
    log(`  ${r.ekran} przy ${r.szerokosc} px:`);
    log(`      logotyp ${r.lewa} zamiast ${r.oczekiwanaLewa} (o ${r.bladLewej} px)`);
    log(`      akcje   ${r.prawa} zamiast ${r.oczekiwanaPrawa} (o ${r.bladPrawej} px)`);
    log(`      stopka  ${r.stopkaLewa}…${r.stopkaPrawa} zamiast `
      + `${r.oczekiwanaLewa}…${r.oczekiwanaPrawa} (o ${r.bladStopkiL}/${r.bladStopkiP} px)`);
    if (r.bladSzukajL > 1 || r.bladSzukajP > 1) {
      log(`      szukaj  ${r.szukajLewa}…${r.szukajPrawa} zamiast kolumny czytania `
        + `${r.kolumnaLewa}…${r.kolumnaPrawa} (o ${r.bladSzukajL}/${r.bladSzukajP} px)`);
    }
  }
}

if (niespojneSzerokosci.length > 0) {
  log('');
  log('Podstrony zalogowanego mają różną szerokość (nawigacja przeskakuje):');
  for (const n of niespojneSzerokosci) {
    log(`  przy ${n.szerokosc} px: „${n.pierwszy}" ${n.pierwszyOd}…${n.pierwszyDo}, `
      + `a „${n.drugi}" ${n.drugiOd}…${n.drugiDo}`);
  }
}

if (
  blokujacych > 0
  || przepelnienia.length > 0
  || rozjazdyBelki.length > 0
  || niespojneSzerokosci.length > 0
) {
  process.exit(1);
}

// `process.exitCode` mógł zostać ustawiony wyżej (nierozwiązany adres ekranu,
// karuzela nieprzechodząca bez JavaScriptu). Wychodzimy z nim, zamiast go
// zgubić — cichy kod 0 przy niesprawdzonym ekranie to dokładnie ta fałszywa
// zieleń, przed którą ten skrypt ma bronić.
process.exit(process.exitCode ?? 0);
