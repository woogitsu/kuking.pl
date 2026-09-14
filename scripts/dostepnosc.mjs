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
import { przygotujKaruzele, zmierzKaruzele } from './fixtures/karuzela-mieszana.mjs';
import { AxeBuilder } from '@axe-core/playwright';
import { spawn, execFileSync } from 'node:child_process';
import { writeFileSync, mkdirSync, existsSync } from 'node:fs';

// Szybka regresja końcowego zapisu działa także przez check.sh i w CI,
// zanim kosztowny pomiar uruchomi przeglądarkę i przygotuje bazę.
execFileSync(process.execPath, ['--test', 'scripts/fixtures/karuzela-raport.test.mjs'], { stdio: 'inherit' });

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
 *
 * POPRAWKA Z 11 WRZEŚNIA (D-099): PIERWSZEŃSTWO MA PRZEGLĄDARKA PLAYWRIGHTA,
 * BO TO JEST TA, KTÓRĄ MIERZY CI.
 *
 * Kolejność była odwrotna i kosztowała cały wieczór szukania „usterki,
 * której u nas nie ma". Obraz deweloperski ma pod stałą ścieżką rewizję 1194
 * (Chromium 141), a `playwright` 1.63 przypina rewizję 1243 (Chrome 153) —
 * i to ją pobiera runner. Dopóki stała ścieżka wygrywała zawsze, gdy tylko
 * istniała, „u mnie zielone" i „na CI czerwone" mogło znaczyć wyłącznie tyle,
 * że to były DWIE RÓŻNE PRZEGLĄDARKI, i nie dało się tego rozstrzygnąć bez
 * ręcznego ustawiania zmiennej. Ten kontener ma dziś obie
 * (`/opt/pw-browsers/chromium-1243`), więc nie ma czego wybierać: bierzemy
 * tę, którą weźmie CI, a ścieżka z obrazu zostaje zapasem na wypadek obrazu
 * bez pobranej przeglądarki Playwrighta.
 *
 * `CHROMIUM_PATH` dalej przebija wszystko — po to jest, żeby dało się
 * zmierzyć TĘ SAMĄ rzecz drugą przeglądarką, gdy wynik jest podejrzany.
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

  /*
   * `executablePath()` rzuca, gdy paczka nie umie wskazać pliku (inna
   * platforma, brak instalacji). Brak przeglądarki Playwrighta nie jest
   * błędem — jest powodem, żeby sięgnąć po tę z obrazu.
   */
  try {
    const wlasna = chromium.executablePath();

    if (wlasna && existsSync(wlasna)) {
      return undefined;
    }
  } catch {
    // Idziemy dalej, do ścieżki z obrazu deweloperskiego.
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
/*
 * KONTO, KTÓRYM AUTOMAT SIĘ LOGUJE — jedna nazwa, czytana w trzech miejscach.
 *
 * `ania`, nie `basia`: „basia" jest jednocześnie personą treści zalążkowej,
 * a persony mają hasło LOSOWE i nie są logowalne (D-025). `DemoSeeder`
 * znajdował wtedy personę i nie ustawiał jej hasła demo, więc logowanie cicho
 * padało — automat mierzył ekrany GOŚCIA, będąc pewnym, że mierzy ekrany
 * zalogowanej osoby.
 *
 * Stała stoi w zasięgu modułu — I NAD LISTĄ `EKRANY`, nie pod nią, bo
 * lista czyta ją już przy wczytaniu pliku (własny profil `/@ania`).
 * Tę samą nazwę musi znać logowanie ORAZ trzy ekrany dostępne WYŁĄCZNIE
 * dla właściciela treści: własny profil, odwołanie od decyzji (dla osoby,
 * której decyzja dotyczy) i kolejność zdjęć we wpisie
 * (`PostPolicy::update` — tylko autor). Gdy te trzy miejsca rozjeżdżały się
 * na dwa różne konta, serwis odpowiadał 403, a automat wpisywał „✓": mierzył
 * stronę błędu, która przechodzi każdy audyt dostępności, nie sprawdzając
 * niczego. Zmierzone 7 września, po dodaniu sprawdzenia kodu HTTP niżej.
 */
const KONTO_ZALOGOWANE = 'ania';

/*
 * KONTO MODERATORA — OSOBNE OD `KONTO_ZALOGOWANE` I MUSI BYĆ OSOBNE.
 *
 * Panel moderacji stoi za dwoma zamkami: rolą (`EnsureUserIsModerator`, który
 * zwykłemu użytkownikowi odpowiada 404, żeby nie potwierdzać, że panel
 * istnieje) i weryfikacją dwuetapową (`EnsureModeratorHasTwoFactor`). Konto
 * `ania` nie ma roli, więc jego ciasteczkiem sesji panelu zmierzyć się NIE DA
 * — dostalibyśmy 404 i stronę błędu, która przechodzi każdy audyt, nie
 * sprawdzając niczego.
 *
 * `moderacja` to konto z `DemoSeeder` (`role: User::ROLE_MODERATOR`), z tym
 * samym hasłem demo co pozostałe.
 */
const KONTO_MODERATORA = 'moderacja';

/*
 * KONTO Z NAZWĄ NA PEŁNE 100 ZNAKÓW — issue #440.
 *
 * `display_name` ma w walidacji `max:100` i ani jednego ograniczenia na
 * długość pojedynczego SŁOWA. Ten automat mierzył dotąd profile o nazwach
 * „Basia" (5 znaków) i „Ania" (4) — czyli NAJŁATWIEJSZE warianty tego ekranu.
 * Zdanie „nic nie wyjeżdża w bok na profilu" dotyczyło więc czegoś, czego
 * ten skrypt nie sprawdził: najdłuższa nazwa, jaką człowiek może dziś wpisać,
 * nie weszła do próbki ani razu.
 *
 * To jest pułapka 5 z `docs/PULAPKI_TESTOW.md` od strony danych — narzędzie
 * melduje sukces, bo nie dostało tego, o co chodzi. Od pułapki 5 w zwykłej
 * postaci różni się tym, że tu nawet nie było pustego ekranu, po którym dałoby
 * się coś poznać: ekran był pełny, poprawny i łatwy.
 *
 * Konto zakłada `DemoSeeder` (nazwa ma tam dokładnie tyle znaków, ile wynosi
 * limit z `kuking.profil.dlugosc_nazwy` — od #467 jest to 40; najdłuższy
 * nieprzerwany ciąg — 55). Stała stoi tutaj, a nie w adresie wpisanym z ręki,
 * z tego samego powodu co `KONTO_ZALOGOWANE`: rozjazd nazwy dałby 404,
 * a strona błędu przechodzi każdy audyt dostępności, nie sprawdzając niczego.
 */
const KONTO_DLUGA_NAZWA = 'zofia_z_bieszczad';

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
  /*
   * WŁASNY PROFIL, WIDZIANY PRZEZ WŁAŚCICIELA — inny układ tej samej karty.
   *
   * Pozycja wyżej mierzy profil jako GOŚĆ, czyli wariant BEZ dużego przycisku
   * pod awatarem i bez trzech przycisków obsługi konta („Zmień swój profil",
   * „Dodaj zdjęcie", „Wyloguj się"). To właśnie ten drugi wariant ma
   * najwięcej okazji, żeby wypchnąć stronę w bok przy 320 px i czcionce
   * przeglądarki 200% — i do tej pory nie był mierzony wcale.
   *
   * `/@ania`, bo tym kontem loguje się ten automat (patrz KONTO_ZALOGOWANE).
   * Gdyby ta nazwa się rozjechała z logowaniem, `/@ania` byłoby profilem
   * CUDZYM i mierzyłoby dokładnie to samo, co pozycja wyżej — dlatego adres
   * składamy ze stałej, a nie wpisujemy go tu drugi raz z ręki.
   */
  { nazwa: 'profil (własny)', adres: `/@${KONTO_ZALOGOWANE}`, zalogowany: true },
  /*
   * TRZECI PROFIL: NAZWA NA PEŁNE 100 ZNAKÓW (issue #440).
   *
   * Dwie pozycje wyżej mierzą ten ekran z nazwą na cztery i pięć znaków.
   * Ta mierzy go z najdłuższą, jaką dopuszcza walidacja — i robi to przez
   * ten sam komplet szerokości (320/360/414/768/900/1280) i skal tekstu
   * (bez skali, 140%, czcionka przeglądarki 200%) co każdy inny ekran na
   * tej liście, bo lista jest jedna i pętla jest jedna.
   *
   * Mierzymy jako GOŚĆ, tak jak profil `basia` wyżej: główka profilu jest
   * dla gościa i dla zalogowanego ta sama, a nazwa stoi właśnie w niej.
   *
   * CO TRZYMA TEN EKRAN W RYZACH: `overflow-wrap: anywhere` na dzieciach
   * `.profil-tozsamosc` w `resources/css/ekran-profilu.css`. Zdjęcie tej
   * jednej deklaracji ma ten pomiar WYWALIĆ — jeśli nie wywala, próbka nie
   * mierzy tego, o co chodzi, i poprawiać należy próbkę, nie próg.
   */
  { nazwa: 'profil (najdłuższa dopuszczalna nazwa)', adres: `/@${KONTO_DLUGA_NAZWA}` },
  { nazwa: 'tablica', adres: '/home', zalogowany: true },
  { nazwa: 'dodaj zdjęcie', adres: '/dodaj/zdjecie', zalogowany: true },
  { nazwa: 'dodaj przepis', adres: '/dodaj/przepis', zalogowany: true },
  /*
   * ROZDROŻE USTAWIEŃ (#344) — ekran, na który od teraz prowadzi KAŻDY napis
   * „Ustawienia" w serwisie: pozycja nawigacji bocznej, przycisk w rzędzie
   * akcji własnego profilu i nowa pozycja w menu przy awatarze.
   *
   * Jest tu z tego samego powodu, dla którego D-171 kazało dopisywać nowe
   * ekrany od razu: ekran spoza tej listy niczego nie psuje — raport wygląda
   * na kompletny i świeci na zielono. Kształt treści jest przy tym dokładnie
   * tym ryzykownym: dziewięć pozycji, każda z nazwą i zdaniem opisu, czyli
   * gęsty ciąg tekstu, który przy 320 px i czcionce 200% najłatwiej wypycha
   * stronę w bok (issue #80).
   */
  { nazwa: 'ustawienia (rozdroże)', adres: '/ustawienia', zalogowany: true },
  { nazwa: 'czytelność', adres: '/ustawienia/czytelnosc', zalogowany: true },

  /*
   * ZDJĘCIE PROFILOWE (#344) — EKRAN, KTÓRY PĘKAŁ, A NIE BYŁ MIERZONY.
   *
   * 12 września wyszło, że przy 320 px i czcionce przeglądarki 200% ta strona
   * wyjeżdża w bok: `scrollWidth` 333 px w oknie 320 px. Automat nie mógł
   * tego złapać, bo tego adresu w tym pliku nie było — czyli znowu to samo,
   * co przy D-099 i przy `/ustawienia` wyżej: brak ekranu na liście niczego
   * nie psuje, raport wygląda na kompletny i świeci na zielono.
   *
   * DOPISANIE SAMEGO ADRESU NIC BY NIE DAŁO i dlatego idzie w parze ze
   * zmianą w `DemoSeeder`. Ekran ma trzy stany („to jest Twoje zdjęcie",
   * „zdjęcie się przygotowuje", „nie masz jeszcze zdjęcia") i różnią się nie
   * zdaniem, tylko układem: przy gotowym zdjęciu dochodzi obrazek 88 px obok
   * akapitu i CAŁA sekcja „Usunięcie zdjęcia" z przyciskiem potwierdzenia.
   * Dane demo nie dawały kontu `ania` żadnego zdjęcia, więc mierzony byłby
   * jedyny stan, w którym tego wszystkiego NIE MA — jedyny z trzech, który
   * nie przepełniał. Strażnik pilnujący nie tej rzeczy (pułapki 2 i 4
   * z `docs/PULAPKI_TESTOW.md`); dlatego `DemoSeeder` sieje temu kontu
   * PRAWDZIWY plik z wariantem `thumb`, a nie sam wiersz w `media`.
   *
   * Że ekran naprawdę stoi w stanie „to jest Twoje zdjęcie", a nie w stanie
   * zapasowym, sprawdza `stanEkranuZdjecia()` niżej — tym samym mechanizmem
   * co `TRESC_PANELU`.
   */
  { nazwa: 'zdjęcie profilowe', adres: '/ustawienia/zdjecie', zalogowany: true },
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
  /*
   * SPIS WSZYSTKICH TEMATÓW (#273, D-087) — mierzony jako GOŚĆ, bo taki
   * właśnie jest: to strona publiczna, jedno z niewielu miejsc, w które ma
   * sens trafić z wyszukiwarki.
   *
   * Dopisany 11 września (D-099). Powstał w #303 i przez dwie doby był
   * JEDYNĄ stroną publiczną serwisu, której ten automat nie oglądał — nie
   * z decyzji, tylko dlatego, że ten plik trzymały wtedy trzy gałęzie naraz
   * i nikt nie chciał go ruszać. Nikt tego nie zauważył, bo brak ekranu na
   * liście niczego nie psuje: raport wygląda na kompletny i świeci na
   * zielono. Kompletności tej listy pilnuje od teraz
   * `tests/Feature/PomiarDostepnosciObejmujeStronyPubliczneTest.php` —
   * nowa strona publiczna musi trafić albo tutaj, albo na wypisaną tam
   * listę świadomych wyjątków. „Ktoś będzie pamiętał" już raz nie zadziałało.
   *
   * Kształt treści jest tu ryzykiem sam w sobie: dwie sekcje gęstych rzędów
   * odnośników z licznikami, czyli dokładnie ten układ, który przy 320 px
   * i tekście 140% najłatwiej wypycha stronę w bok (issue #80).
   */
  { nazwa: 'spis tematów (gość)', adres: '/tagi' },
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

  /*
   * ADRES E-MAIL (issue #195). Trzy sekcje, dwa formularze i pole hasła obok
   * pola adresu — a przy 320 px i tekście 140% to jest dokładnie ten układ,
   * który najłatwiej wypycha stronę w bok. Ekran jest też jedyną drogą do
   * poprawienia adresu, na który idzie link do nowego hasła: pole bez
   * etykiety albo przycisk bez nazwy dostępnej kosztuje tu konto.
   */
  { nazwa: 'adres e-mail', adres: '/ustawienia/e-mail', zalogowany: true },

  // Zgłoszenie treści niezgodnej z prawem (DSA art. 16). Publiczny, bez
  // logowania — pole imienia jest od dziś opcjonalne, z nowym wyjaśnieniem
  // przy polu. Mierzymy jako gościa, bo dla gościa ten formularz istnieje.
  { nazwa: 'zgłoś treść niezgodną z prawem', adres: '/zglos-nielegalna-tresc' },

  /*
   * „Napisz do nas" — formularz kontaktu z operatorem.
   *
   * MIERZONY JAKO GOŚĆ, bo dla gościa przede wszystkim istnieje: najczęstsza
   * wiadomość na starcie brzmi „nie mogę się zalogować".
   *
   * Jest tu z konkretnego powodu, nie dla kompletu listy. To był pierwszy
   * kandydat na DYMEK PRZYKLEJONY DO ROGU EKRANU — a element o stałej pozycji
   * jest klasycznym źródłem przewijania w bok i zasłaniania treści przy
   * 320 px i przy czcionce przeglądarki podkręconej do 200% (WCAG 1.4.10
   * i 2.4.11; w tym repozytorium issues #80 i #162). Rozwiązaniem był zwykły
   * odnośnik w stopce i w nawigacji zamiast dymka — a to jest miejsce,
   * w którym ten wybór da się UTRZYMAĆ: gdyby ktoś kiedyś dołożył tu element
   * `position: fixed`, pomiar układu niżej to zobaczy.
   *
   * Ekran potwierdzenia (`/napisz-do-nas/dziekujemy`) świadomie NIE jest na
   * liście: bez wysłanego formularza nie ma czego pokazać poza zdaniem
   * „nie mamy jak odpisać", a pusty ekran przechodzi każdy test dostępności,
   * nie sprawdzając niczego (patrz nagłówek pliku).
   */
  { nazwa: 'napisz do nas (gość)', adres: '/napisz-do-nas' },

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
   * EKRANY PANELU MODERACJI (issue #294) — DOTĄD NIE BYŁO ICH TU ANI JEDNEGO.
   *
   * Lista wyżej ma `/zgloszenia`, czyli ekran ZGŁASZAJĄCEGO — adres wygląda
   * podobnie, a to inna strona, inny układ i inna rola. Przez to najbardziej
   * OSOBNA warstwa układu w tym serwisie — tryb panelu
   * (`.side-nav[data-tryb-panelu]`, własne reguły poniżej 64rem, własny pasek
   * dolny `.bottom-nav-panel`) — nie była mierzona nigdy: ani na przepełnienie
   * w poziomie, ani przy powiększonej czcionce.
   *
   * ZMIERZONE 10 września, PRZED poprawkami z tego issue: `/admin/uzytkownicy`
   * miało `scrollWidth` 1450 px przy oknie 900 px, a przy `data-text-scale="140"`
   * — 1801 px. Ta sama strona przewijała się w bok przy KAŻDEJ szerokości
   * z tej listy, 320 px (1360 px) i 1280 px (1656 px) włącznie. Nie była to
   * więc usterka „szerokiego telefonu": była to usterka ekranu, którego nikt
   * nie mierzył.
   *
   * DLACZEGO TE TRZY: `/admin/uzytkownicy` ma najbogatszy układ w panelu
   * (rząd zakładek, pasek filtrów, tabela) i to on pękał; `/admin/zgloszenia`
   * i `/admin/sygnaly` to dwie kolejki o różnym kształcie karty — razem
   * pokrywają wszystkie trzy rodzaje treści, jakie panel dziś ma.
   *
   * `moderator: true`, nie `zalogowany: true` — wejście wymaga konta z rolą
   * moderatora ORAZ potwierdzonej weryfikacji dwuetapowej
   * (`EnsureModeratorHasTwoFactor`). Automat przechodzi przez jedno i drugie
   * naprawdę, patrz `stanModeratora()` niżej; 2FA nie jest tu w żaden sposób
   * osłabiane ani omijane.
   */
  { nazwa: 'panel — użytkownicy', adres: '/admin/uzytkownicy', moderator: true },
  { nazwa: 'panel — zgłoszenia', adres: '/admin/zgloszenia', moderator: true },
  { nazwa: 'panel — sygnały automatu', adres: '/admin/sygnaly', moderator: true },

  /*
   * KOLAŻ NA POWITANIE — czwarty ekran panelu na tej liście i pierwszy, który
   * jest FORMULAREM WYBORU, a nie kolejką. Rząd pól wyboru z miniaturą, nazwą
   * autora i fragmentem wpisu w jednej linii to dokładnie ten układ, który
   * przy 320 px i tekście 140% ma najwięcej okazji, żeby wypchnąć stronę
   * w bok — a tego nie mierzy żaden z trzech ekranów wyżej.
   *
   * Wchodzi tu razem z wpisem w `TRESC_PANELU` niżej, bo bez niego ten ekran
   * ma dwa stany nie do odróżnienia w raporcie: lista zdjęć do wyboru i
   * zdanie „Nie ma jeszcze ani jednego publicznego zdjęcia". Oba odpowiadają
   * 200 pod tym samym adresem. Wymusza to
   * `PomiarDostepnosciSprawdzaTrescPaneluTest` i bardzo dobrze.
   */
  { nazwa: 'panel — kolaż na powitanie', adres: '/admin/kolaz-powitalny', moderator: true },

  /*
   * Trzy dokumenty prawne, przepisane dziś w całości (prywatność, regulamin,
   * zasady). Długie strony z tabelami — dokładnie ten kształt treści, który
   * przy 320 px i przy tekście 140% ma największą szansę wypchnąć całą
   * stronę w bok, gdy tabela nie ma własnego przewijania (issue #80).
   */
  { nazwa: 'polityka prywatności', adres: '/prywatnosc' },
  { nazwa: 'regulamin', adres: '/regulamin' },
  { nazwa: 'zasady', adres: '/zasady' },

  /* ===========================================================================
   * SIEDEM STRON PUBLICZNYCH DOPISANYCH 11 WRZEŚNIA — SPŁATA DŁUGU Z D-099
   * ===========================================================================
   *
   * Wypisał je sam skan `PomiarDostepnosciObejmujeStronyPubliczneTest` przy
   * pierwszym uruchomieniu i przez jeden PR stały na tamtejszej liście
   * `WYJATKI` jako **dług nazwany**: prawdziwe strony, których ten automat nie
   * oglądał ani razu. Autor D-099 nie dokładał ich w PR-ze odblokowującym
   * `main` z jednego powodu — „każdy nowy ekran może przynieść własne
   * znaleziska, a wtedy trzeba je naprawić, nie odłożyć, i robi się to na
   * zielonym CI, nie na czerwonym". `main` jest zielony, więc dług wraca tutaj.
   *
   * NAJWAŻNIEJSZA Z NICH JEST `/nie-pamietam-hasla`. To jest droga, którą
   * człowiek wchodzi dopiero WTEDY, GDY MU COŚ NIE WYSZŁO — a więc ten ekran,
   * na którym pole bez etykiety albo za mały przycisk kosztuje nie
   * niewygodę, tylko konto. Do dziś nie był sprawdzony ani razu.
   *
   * Wszystkie siedem to strony GOŚCIA (sześć z nich stoi w grupie `guest`
   * w `routes/web.php`, `/pomoc` i `/o-kuking` są całkiem otwarte), więc idą
   * bez `zalogowany: true` — poza jednym wyjątkiem opisanym przy nim niżej.
   */
  { nazwa: 'o Kuking', adres: '/o-kuking' },
  { nazwa: 'pomoc', adres: '/pomoc' },

  /*
   * ODWOŁANIE DLA GOŚCIA (#10, DSA art. 20) — INNY EKRAN NIŻ „odwołanie od
   * decyzji" WYŻEJ, mimo podobnej nazwy.
   *
   * Tamten (`/odwolanie/{action}`) widzi osoba ZALOGOWANA, której decyzja
   * dotyczy, i ma gotowy kontekst sprawy. Ten jest dla kogoś, komu zamknięto
   * konto i kto do serwisu nie wejdzie — więc zamiast kontekstu ma pola
   * „login" i „hasło" plus pole treści odwołania. Zmierzenie wariantu
   * zalogowanego nic o tym układzie nie mówi.
   */
  { nazwa: 'odwołanie (gość)', adres: '/odwolanie' },

  /*
   * NIE PAMIĘTAM HASŁA — najważniejszy ekran z tej siódemki, patrz wyżej.
   */
  { nazwa: 'nie pamiętam hasła', adres: '/nie-pamietam-hasla' },

  // Logowanie linkiem e-mail (#25, D-056) — dla naszej grupy droga
  // PODSTAWOWA, nie awaryjna, więc tym bardziej ma być zmierzona.
  { nazwa: 'logowanie linkiem', adres: '/logowanie/link' },

  /*
   * DRUGI KROK LOGOWANIA — KOD Z APLIKACJI (#12). JEDYNY Z SIÓDEMKI, KTÓRY
   * WYMAGA WŁASNEGO STANU PRZEGLĄDARKI.
   *
   * `TwoFactorChallengeController::show()` odsyła na `/login`, jeśli w sesji
   * nie ma `logowanie.2fa.user_id` — czyli śladu POPRAWNIE PODANEGO HASŁA.
   * Wejście na ten adres wprost daje więc przekierowanie, a to w tym skrypcie
   * jest BŁĘDEM (sprawdzenie ścieżki w pętli niżej), nie cichym pominięciem.
   *
   * Dlatego `przedKodem2FA: true`: ekran idzie w czwartym kontekście, którego
   * ciasteczko pochodzi z `stanPrzedKodem2FA()` — pierwszego kroku logowania
   * wykonanego naprawdę, formularzem. Niczego tu nie obchodzimy i nie
   * osłabiamy: sesja jest w dokładnie tym stanie, w którym jest sesja
   * człowieka trzymającego telefon z kodem.
   */
  { nazwa: 'logowanie kodem (2FA)', adres: '/logowanie/kod', przedKodem2FA: true },

  /*
   * COFNIĘCIE USUNIĘCIA KONTA (D-022) — ekran dla kogoś, kogo
   * `EnsureAccountIsActive` już wylogowało, więc publiczny z konieczności.
   * Jest to zarazem jedyna droga odwrotu z decyzji, której skutku po 30 dniach
   * nie da się cofnąć niczym: strona, na której trzeba trafić w przycisk
   * za pierwszym razem.
   */
  { nazwa: 'cofnij usunięcie konta', adres: '/cofnij-usuniecie-konta' },

  /* ===========================================================================
   * BEZPIECZEŃSTWO KONTA — TRZECI EKRAN WEJŚCIA KONTEM ZEWNĘTRZNYM (#345)
   * ===========================================================================
   *
   * Ekran, na którym stoi „Połącz konto Facebooka" — czyli JEDYNA bezpieczna
   * droga powiązania istniejącego konta z Facebookiem (D-098: adres
   * z Facebooka nie łączy kont, więc powiązanie musi zrobić ktoś, kto JUŻ
   * jest zalogowany). Nie był mierzony przez ten automat ani razu: nie ma go
   * ani w tej liście, ani w `EKRANY_UKLADU`, a test pilnujący kompletności
   * (`PomiarDostepnosciObejmujeStronyPubliczneTest`) pomija z założenia trasy
   * za `auth`, więc nie miał go jak wypisać. Milczące pominięcie, dokładnie
   * to, przed którym tamten test ostrzega — tylko po drugiej stronie
   * logowania.
   *
   * Kształt treści jest tu ryzykiem sam w sobie: rzędy „coś jest włączone"
   * plus przycisk obok tekstu, sekcja hasła, sekcja 2FA i — od #259 — sekcja
   * Facebooka ze znakiem marki w przycisku. Przy 320 px i tekście 140% to ten
   * układ, który najłatwiej wypycha stronę w bok (issue #80).
   *
   * `zalogowany: true`, bo dla gościa ten ekran nie istnieje. Sekcja
   * Facebooka pokazuje się dopiero, gdy droga działa — dlatego automat stawia
   * serwer z atrapami kluczy (`KLUCZE_DOSTAWCOW_DO_POMIARU`), a to, że sekcja
   * naprawdę wyszła, sprawdza `przeszkodaWWejsciachZewnetrznych()`.
   *
   * CZTERECH EKRANÓW ZA ZGODĄ DOSTAWCY TU NIE MA I NIE JEST TO PRZEOCZENIE.
   * `/wejdz/google/domknij`, `/wejdz/google/polacz` i odpowiedniki Facebooka
   * czytają z sesji tożsamość, którą zakłada WYŁĄCZNIE `callback()` po udanej
   * wymianie kodu u dostawcy — a adres wymiany jest stałą w kodzie
   * (`App\Support\Google::ADRES_TOKENU`), idzie z serwera i nie da się go
   * wskazać konfiguracją. Atrapa klucza otwiera przycisk; tamte ekrany
   * wymagają atrapy CAŁEGO DOSTAWCY, czyli osobnej pracy — issue #345
   * i stała `WYJATKI` w `PomiarDostepnosciObejmujeStronyPubliczneTest`.
   * Dopisanie ich tutaj bez tej atrapy dałoby przekierowanie na `/login`,
   * czyli pomiar ekranu logowania pod cudzą nazwą.
   */
  { nazwa: 'bezpieczeństwo konta', adres: '/ustawienia/bezpieczenstwo', zalogowany: true },
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
  /*
   * „Co chcesz dodać?" — rozdroże przed dodaniem wpisu albo przepisu.
   * Dopisane przy #205, kiedy ekran dostał treść w prawej szynie. Ekrany
   * `/dodaj/zdjecie` i `/dodaj/przepis` (czyli to, co jest ZA tym rozdrożem)
   * były mierzone od dawna, a samo rozdroże nie — więc nagłówek bloku szyny
   * mógł na nim rozepchnąć stronę i nikt by tego nie zobaczył. Dokładnie to
   * się stało przy pierwszej wersji tamtej zmiany, tyle że na „Napisz do nas":
   * `.szyna-tytul` był kontenerem flex bez zawijania, więc przy 320 px
   * i czcionce przeglądarki 200% najdłuższe słowo nagłówka dyktowało
   * szerokość całego dokumentu (330 px zamiast 320).
   */
  { nazwa: 'dodaj (rozdroże)', adres: '/dodaj', zalogowany: true },
  { nazwa: 'powiadomienia', adres: '/powiadomienia', zalogowany: true },
  // Ekran autora: kolejność zdjęć i wybór wyglądu (issue #92). Miniatura,
  // dwa przyciski „w górę / w dół" i trzy kafelki wyboru w jednym wierszu —
  // to jest układ, który przy 320 px i tekście 140% ma najwięcej okazji,
  // żeby wypchnąć stronę w bok.
  { nazwa: 'kolejność i wygląd zdjęć', adres: null, znajdz: 'wpis:carousel:zdjecia', zalogowany: true },
  // „Napisz do nas" widziane przez ZALOGOWANEGO — inny układ niż dla gościa
  // (jest nawigacja boczna, pasek dolny i o jedno pole mniej), więc inne
  // szanse na wypchnięcie strony w bok. Sam axe mierzy wariant gościa wyżej.
  { nazwa: 'napisz do nas (zalogowany)', adres: '/napisz-do-nas', zalogowany: true },
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
 *
 * 900 DOŁOŻONE PRZY issue #294 — JEDEN PUNKT, NIE CAŁA MACIERZ.
 * Między 768 a 1280 była dziura, w którą wpada cała klasa urządzeń, na których
 * układ liczy się INACZEJ niż na obu jej brzegach: telefon składany rozłożony
 * (zgłoszenie przyszło z Galaxy Fold), tablet postawiony poziomo na podstawce
 * (audyt 60+ wskazuje to jako realny scenariusz w naszej grupie) i okno
 * przeglądarki na pół ekranu laptopa. Wszystkie trzy są SZERSZE niż telefon,
 * a mimo to poniżej progu 64rem — czyli dostają układ telefonu na szerokim
 * ekranie, którego nikt nigdy nie zobaczył w pomiarze.
 *
 * Jeden dodatkowy punkt, a nie 800/900/1000/1100: ten job jest już najdłuższy
 * w CI, a szerokości między progami różnią się tylko liczbą kolumn w siatkach
 * `auto-fit` — 900 px daje ich najwięcej przed progiem 64rem, więc jest
 * najgorszym przypadkiem tego przedziału, a nie losowym punktem w nim.
 */
const SZEROKOSCI_UKLADU = SZYBKO ? [320, 360] : [320, 360, 414, 768, 900, 1280];
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


function log(...args) {
  console.log(...args);
}

/*
 * STEROWNIK POCZTY, KTÓRY DOSTARCZA — BO INACZEJ DWA EKRANY NIE MAJĄ
 * FORMULARZA, A AUTOMAT MIERZY DWA AKAPITY I MELDUJE „✓" (D-106).
 *
 * ZMIERZONE 11 WRZEŚNIA, przy dopisywaniu siedmiu stron publicznych z D-099.
 * `/nie-pamietam-hasla` i `/logowanie/link` mają PO DWA STANY, rozstrzygane
 * przez `App\Support\Poczta::dziala()` (czyli przez to, czy Laravel w ogóle
 * ma czym wysłać list):
 *
 *   poczta działa   → nagłówek, akapit, KARTA Z FORMULARZEM (pole adresu
 *                     z etykietą i podpowiedzią, Turnstile, dwa przyciski),
 *                     trzy bloki wyjaśnień pod spodem;
 *   poczta nie działa → nagłówek, jeden akapit „napisz do nas" i dwa przyciski.
 *
 * Zmierzone przy 320 px (Chromium 153) — liczy się OSTATNIA kolumna, nie
 * wielkość różnicy:
 *
 *     ekran                  stan               węzłów  znaków  PÓL
 *     /nie-pamietam-hasla    poczta działa          14     315    1
 *     /nie-pamietam-hasla    poczta nie działa       6     257    0
 *     /logowanie/link        poczta działa          21     891    1
 *     /logowanie/link        poczta nie działa       6     271    0
 *
 * W stanie zapasowym NIE MA ANI JEDNEGO POLA FORMULARZA, więc cała klasa
 * rzeczy, których ten automat pilnuje — etykieta pola, opis pod polem, nazwa
 * dostępna przycisku wysyłki, kontrast, rozmiar celu, zawijanie rzędu
 * przycisków przy 320 px — nie ma na czym zadziałać. Zielony wynik nad takim
 * ekranem nie mówi nic o formularzu, bo formularza tam nie było.
 *
 * A to jest ekran, na który człowiek trafia DOPIERO WTEDY, GDY MU COŚ NIE
 * WYSZŁO. Mierzenie jego stanu zapasowego i zapisywanie „✓" jest dokładnie tą
 * fałszywą zielenią, przed którą ostrzega nagłówek tego pliku: pusty ekran
 * przechodzi każdy audyt, nie sprawdzając niczego.
 *
 * `.env` deweloperski ma `MAIL_MAILER=log`, a job `dostepnosc` w CI —
 * `MAIL_MAILER=array`. OBA są dla `Poczta` niedostarczające, więc formularza
 * odzyskania hasła nie widział dotąd ani jeden przebieg, nigdzie.
 *
 * Dlatego serwer pod pomiar wstaje ze sterownikiem `smtp`, celującym
 * w `MAIL_HOST`/`MAIL_PORT` z konfiguracji. Nie jest to osłabienie niczego
 * i nie wysyła ani jednego listu: ten automat wykonuje wyłącznie żądania GET
 * oraz trzy formularze logowania i włączenie 2FA, a żadna z tych dróg poczty
 * nie rusza. `EsmtpTransport` powstaje lokalnie i otwiera gniazdo dopiero
 * przy pierwszym liście, którego tu nie ma.
 *
 * Ustawiamy to WYŁĄCZNIE dla serwera, który stawiamy sami. Przy `ADRES=…`
 * mierzymy cudzą instancję w stanie, w jakim ją zastaliśmy, i nie mamy prawa
 * jej przestawiać — dlatego niżej stoi sprawdzenie, a nie założenie.
 */
const STEROWNIK_POCZTY_DO_POMIARU = 'smtp';

/*
 * KONTROLA, ŻE STAN Z FORMULARZEM NAPRAWDĘ WSZEDŁ — NIE ZAŁOŻENIE.
 *
 * Bez niej zmiana `Poczta::dziala()`, inny sterownik w środowisku albo
 * zapamiętana konfiguracja (`config:cache`) po cichu wracają do wariantu
 * zapasowego, a raport dalej pokazuje dwa ✓ — czyli usterkę nie do odróżnienia
 * od poprawnego wyniku. To ten sam wzorzec, co sprawdzenie przeliczonego
 * układu przy skali tekstu (D-099) i sprawdzenie korzenia przy czcionce 200%:
 * niepowodzenie jest BŁĘDEM, nie pominięciem, bo pomiar w nieznanym stanie
 * jest gorszy niż jego brak.
 */
async function przeszkodaWFormularzachOdzyskania(adres) {
  const doSprawdzenia = [
    ['/nie-pamietam-hasla', 'formularz odzyskania hasła'],
    ['/logowanie/link', 'formularz „wyślij mi link do zalogowania”'],
  ];

  for (const [sciezka, opis] of doSprawdzenia) {
    const odpowiedz = await fetch(`${adres}${sciezka}`);
    const html = await odpowiedz.text();

    if (! html.includes('name="email"')) {
      return `${opis} (${sciezka}) nie ma pola adresu — serwis wydał wariant BEZ formularza, `
        + 'ten dla wyłączonej poczty. Automat zmierzyłby nagłówek i dwa przyciski, '
        + 'i zapisał „✓" dla ekranu, na który człowiek trafia dopiero wtedy, gdy mu coś '
        + 'nie wyszło. Sprawdź `App\\Support\\Poczta::dziala()` i sterownik poczty '
        + `(automat stawia serwer z „${STEROWNIK_POCZTY_DO_POMIARU}"; przy ADRES=… decyduje `
        + 'konfiguracja mierzonej instancji).';
    }
  }

  return null;
}

/*
 * ATRAPY KLUCZY DOSTAWCÓW TOŻSAMOŚCI — BO BEZ NICH RZĄD „WEJDŹ KONTEM
 * GOOGLE / FACEBOOKA" NIE ISTNIEJE NA ŻADNYM MIERZONYM EKRANIE (#345).
 *
 * ZMIERZONE 12 WRZEŚNIA. `components/wejscia-zewnetrzne.blade.php` pyta
 * `App\Support\Google::dziala()` i `App\Support\Facebook::dziala()`, a te
 * odpowiadają „nie", gdy nie ma kluczy — i wtedy NIE RENDERUJE SIĘ CAŁY
 * BLOK: nagłówek, zdanie „przeniesiemy Cię na stronę…", dwa przyciski
 * ze znakiem marki i zdanie o tym, czego nie bierzemy. `.env` deweloperski
 * ma te cztery zmienne puste, job `dostepnosc` w CI też — więc axe nie
 * widział tych przycisków ANI RAZU, na żadnym ekranie, nigdy.
 *
 * Liczby przy 320 px (Chromium 141), `<main>`:
 *
 *     ekran                        stan          węzłów  znaków  odnośników  SVG
 *     /login                       klucze są         40     923           6    2
 *     /login                       kluczy brak       25     499           4    0
 *     /register                    klucze są         58    1368           5    2
 *     /register                    kluczy brak       43     936           3    0
 *     /ustawienia/bezpieczenstwo   klucze są         48    1350           1    1
 *     /ustawienia/bezpieczenstwo   kluczy brak       38     908           0    0
 *
 * Liczy się ostatnia kolumna. Bez kluczy na tych ekranach nie ma ANI JEDNEGO
 * znaku marki — a znak marki w przycisku to dokładnie ta klasa rzeczy, której
 * axe pilnuje (nazwa dostępna przycisku, `aria-hidden` na ozdobie, kontrast
 * obrysu) i której nie sprawdzi nic innego. Zielony wynik nad `/login` nie
 * mówił więc nic o rzędzie wejść zewnętrznych, bo tego rzędu tam nie było.
 * To ten sam rodzaj fałszywej zieleni co wariant „poczta nie działa" wyżej
 * (D-106) — tyle że dotyczy ekranu, na który człowiek trafia PIERWSZY.
 *
 * DLACZEGO TO NIE JEST OBCHODZENIE NICZEGO. Wartość klucza nie wchodzi do
 * HTML-a: widok pyta wyłącznie o to, CZY klucze są, a adres przycisku to
 * `route('google.start')` na naszej własnej domenie. Renderowany kod jest
 * więc co do znaku ten sam, co na produkcji. Żadne żądanie do Google ani
 * do Meta z tego nie wychodzi: automat wykonuje na tych ekranach wyłącznie
 * GET-y i nie klika w te przyciski — a gdyby kliknął, dostałby
 * przekierowanie na ekran zgody dostawcy, czyli poza mierzony serwis.
 *
 * CZEGO TO NIE ZAŁATWIA — i nie udaje, że załatwia. Cztery ekrany ZA zgodą
 * dostawcy (`/wejdz/google/domknij`, `/wejdz/google/polacz` i odpowiedniki
 * Facebooka) dalej nie są mierzone: atrapa klucza otwiera przycisk, ale nie
 * zakłada w sesji potwierdzonej tożsamości, którą te ekrany czytają. Powód
 * i granica stoją w `tests/Feature/PomiarDostepnosciObejmujeStronyPubliczneTest.php`
 * (stała `WYJATKI`) oraz w issue #345 — to osobna praca: atrapa DOSTAWCY,
 * nie atrapa klucza.
 *
 * Ustawiamy to WYŁĄCZNIE dla serwera, który stawiamy sami — tak samo jak
 * sterownik poczty wyżej. Przy `ADRES=…` mierzymy cudzą instancję w stanie,
 * w jakim ją zastaliśmy, i nie mamy prawa jej przestawiać; dlatego niżej
 * stoi sprawdzenie, a nie założenie.
 */
const KLUCZE_DOSTAWCOW_DO_POMIARU = {
  GOOGLE_CLIENT_ID: 'atrapa-do-pomiaru-dostepnosci',
  GOOGLE_CLIENT_SECRET: 'atrapa-do-pomiaru-dostepnosci',
  FACEBOOK_CLIENT_ID: 'atrapa-do-pomiaru-dostepnosci',
  FACEBOOK_CLIENT_SECRET: 'atrapa-do-pomiaru-dostepnosci',
};

/*
 * KONTROLA, ŻE RZĄD WEJŚĆ ZEWNĘTRZNYCH NAPRAWDĘ SIĘ WYRENDEROWAŁ — NIE
 * ZAŁOŻENIE. Ten sam wzorzec i ten sam powód co przy formularzach
 * odzyskania wyżej: wyłącznik `KUKING_WEJSCIE_GOOGLE`, zapamiętana
 * konfiguracja (`config:cache`) albo zmiana w `dziala()` po cichu wracają
 * do wariantu BEZ przycisków, a raport dalej pokazuje ✓ nad ekranem,
 * na którym tych przycisków nie było.
 *
 * Szukamy NAPISU, nie klasy CSS: napis jest tym, co czyta człowiek, i tym,
 * czego pilnuje reguła „ikona nigdy sama" (AGENTS.md §5). Gdyby ktoś
 * zostawił sam znak marki bez tekstu, to sprawdzenie ma zapalić się jako
 * pierwsze.
 */
async function przeszkodaWWejsciachZewnetrznych(adres) {
  const doSprawdzenia = [
    ['/login', 'ekran logowania'],
    ['/register', 'ekran rejestracji'],
  ];

  for (const [sciezka, opis] of doSprawdzenia) {
    const odpowiedz = await fetch(`${adres}${sciezka}`);
    const html = await odpowiedz.text();

    const brakujace = ['Wejdź kontem Google', 'Wejdź kontem Facebooka']
      .filter((napis) => ! html.includes(napis));

    if (brakujace.length > 0) {
      return `${opis} (${sciezka}) nie ma przycisków: ${brakujace.join(', ')} — serwis wydał `
        + 'wariant BEZ rzędu wejść kontem zewnętrznym. Automat zmierzyłby sam formularz hasła '
        + 'i zapisał „✓" dla ekranu, na który człowiek trafia pierwszy, nie sprawdzając '
        + 'przycisków, którymi wchodzi większość. Sprawdź `App\\Support\\Google::dziala()` '
        + 'i `App\\Support\\Facebook::dziala()` oraz klucze dostawców (automat stawia serwer '
        + 'z atrapami kluczy; przy ADRES=… decyduje konfiguracja mierzonej instancji).';
    }
  }

  return null;
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
      env: {
        ...process.env,
        DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA,
        // Uzasadnienie i kontrola — przy `STEROWNIK_POCZTY_DO_POMIARU` wyżej.
        MAIL_MAILER: STEROWNIK_POCZTY_DO_POMIARU,
        // Uzasadnienie i kontrola — przy `KLUCZE_DOSTAWCOW_DO_POMIARU` wyżej.
        ...KLUCZE_DOSTAWCOW_DO_POMIARU,
      },
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

/**
 * Bieżący kod TOTP dla danego sekretu — liczony przez TĘ SAMĄ bibliotekę,
 * którą serwis sprawdza kody (`pragmarx/google2fa`, patrz
 * `App\Domain\Security\TwoFactorAuthenticator`).
 *
 * To jest odpowiednik aplikacji w telefonie, a nie obejście czegokolwiek:
 * kod przechodzi normalną weryfikację po stronie serwera, razem z ochroną
 * przed powtórzeniem (`verifyKeyNewer`). Gdybyśmy policzyli go sami w Node,
 * mierzylibyśmy własną implementację TOTP zamiast serwisu.
 */
function kodTotp(sekret) {
  const kod = execFileSync('php', ['artisan', 'tinker', '--execute',
    `echo (new PragmaRX\\Google2FA\\Google2FA)->getCurrentOtp('${sekret}');`,
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA } })
    .toString().trim();

  // Bez tego sprawdzenia komunikat błędu z `tinker` (albo puste wyjście)
  // wjechałby do pola „kod" i skrypt przewracałby się dopiero na
  // „Kod jest nieprawidłowy" — czyli w miejscu, które wskazuje na serwis,
  // a nie na to, że kodu w ogóle nie policzyliśmy.
  if (! /^\d{6}$/.test(kod)) {
    throw new Error(`Nie udało się policzyć kodu TOTP — dostałem: ${JSON.stringify(kod)}`);
  }

  return kod;
}

/*
 * SESJA MODERATORA Z PRAWDZIWĄ WERYFIKACJĄ DWUETAPOWĄ (issue #294).
 *
 * DLACZEGO OSOBNA FUNKCJA, A NIE PARAMETR `stanZalogowanego()`
 * Wejście do panelu to nie „to samo logowanie innym kontem". Za formularzem
 * stoją jeszcze dwa kroki, których żaden inny ekran w tym automacie nie ma:
 * włączenie 2FA (albo przejście przez ekran kodu przy logowaniu) i twarde
 * sprawdzenie, że panel naprawdę się otworzył.
 *
 * CZEGO TU NIE MA I DLACZEGO — TO JEST GRANICA, KTÓREJ NIE PRZEKRACZAMY
 * Nie ma tu ani wyłączenia middleware `moderator.2fa`, ani ustawienia
 * `two_factor_confirmed_at` w bazie „na skróty", ani konta z 2FA wsianego
 * przez seeder. `EnsureModeratorHasTwoFactor` chroni panel, który widzi dane
 * WSZYSTKICH ludzi w serwisie — automat dostępności nie jest powodem, żeby
 * ten zamek osłabiać choćby w środowisku testowym, bo obejście napisane „na
 * chwilę do testu" zostaje w repozytorium na zawsze i pokazuje następnej
 * osobie, że tak wolno.
 *
 * Zamiast tego automat robi DOKŁADNIE to, co człowiek: loguje się hasłem,
 * wchodzi na `/ustawienia/2fa/wlacz`, PRZEPISUJE sekret pokazany na ekranie
 * (jest tam jako zwykły tekst, bo nie każdy zeskanuje QR — patrz
 * `pages/settings/two_factor/enable.blade.php`), liczy z niego kod i wpisuje
 * go w formularz. Serwis sprawdza ten kod normalną drogą.
 *
 * DRUGA GAŁĄŹ — 2FA JUŻ WŁĄCZONE. Domyślnie skrypt sieje bazę od zera
 * (`migrate:fresh --seed`), więc konto moderatora zaczyna bez 2FA. Przy
 * uruchomieniu na gotowym serwerze (`ADRES=…`) 2FA może już być potwierdzone
 * i logowanie kończy się na ekranie kodu. Wtedy sekret bierzemy z bazy tego
 * samego serwisu (jest zaszyfrowany, odczytuje go model) i przechodzimy przez
 * ekran kodu tak samo jak człowiek z telefonem w ręce.
 *
 * NA KOŃCU: SPRAWDZENIE, ŻE PANEL SIĘ OTWORZYŁ. Bez tego wszystkie trzy
 * ekrany panelu odesłałyby na `/login` albo na ekran „wymagane 2FA", a pętle
 * niżej zgłosiłyby to jako trzy osobne błędy jednego ekranu — zamiast
 * powiedzieć raz, że automat nie umie wejść do panelu.
 */
async function stanModeratora(przegladarka, adres) {
  const kontekst = await przegladarka.newContext();
  const strona = await kontekst.newPage();

  await strona.goto(`${adres}/login`);
  await strona.fill('input[name="login"]', KONTO_MODERATORA);
  await strona.fill('input[name="password"]', 'haslo-testowe-123');
  await Promise.all([
    strona.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
    // NIE `button[type="submit"]`: dla ZALOGOWANEGO pierwszym takim
    // przyciskiem w dokumencie jest „Wyloguj się" z nawigacji bocznej
    // (formularz POST, `components/wyloguj.blade.php`). Na ekranie logowania
    // to bez znaczenia — ale na ekranie włączania 2FA niżej ten sam skrót
    // WYLOGOWAŁ automat i mierzył potem stronę logowania, meldując sukces.
    // Zmierzone przy pisaniu tego pomiaru; dlatego wszędzie tutaj celujemy
    // w przycisk po jego napisie.
    strona.getByRole('button', { name: 'Zaloguj się' }).first().click(),
  ]);

  if (new URL(strona.url()).pathname === '/logowanie/kod') {
    const sekret = execFileSync('php', ['artisan', 'tinker', '--execute',
      `echo App\\Models\\User::whereRelation('profile', 'username', '${KONTO_MODERATORA}')`
      + '->firstOrFail()->two_factor_secret;',
    ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA } })
      .toString().trim();

    await strona.fill('input[name="code"]', kodTotp(sekret));
    await Promise.all([
      strona.waitForURL((u) => u.pathname !== '/logowanie/kod', { timeout: 15000 }),
      strona.getByRole('button', { name: 'Zaloguj się' }).first().click(),
    ]);
  } else {
    await strona.goto(`${adres}/ustawienia/2fa/wlacz`);

    const sekret = (await strona.locator('.sekret-do-przepisania').innerText()).trim();

    await strona.fill('input[name="code"]', kodTotp(sekret));
    await Promise.all([
      strona.waitForURL((u) => ! u.pathname.endsWith('/wlacz'), { timeout: 15000 }),
      strona.getByRole('button', { name: 'Potwierdź i włącz' }).click(),
    ]);
  }

  // SPRAWDZENIE WEJŚCIA. `/admin/uzytkownicy`, bo to najbogatszy ekran panelu
  // i ten, przez który powstało issue #294.
  const odpowiedz = await strona.goto(`${adres}/admin/uzytkownicy`, { waitUntil: 'domcontentloaded' });
  const kod = odpowiedz?.status() ?? 0;
  const sciezka = new URL(strona.url()).pathname;

  if (kod !== 200 || sciezka !== '/admin/uzytkownicy') {
    throw new Error(
      `Automat nie wszedł do panelu moderacji: /admin/uzytkownicy odpowiedziało ${kod} `
      + `i wylądowało na ${sciezka}. Ekrany panelu nie zostałyby zmierzone. `
      + 'Sprawdź, czy DemoSeeder nadal tworzy konto '
      + `„${KONTO_MODERATORA}" z rolą moderatora i czy przeszło włączenie 2FA — `
      + 'NIE wyłączaj middleware `moderator.2fa`, żeby to obejść.',
    );
  }

  const stan = await kontekst.storageState();
  await kontekst.close();

  return stan;
}

/*
 * SESJA ZATRZYMANA MIĘDZY HASŁEM A KODEM — ŻEBY DAŁO SIĘ ZMIERZYĆ
 * `/logowanie/kod` (#12).
 *
 * PO CO OSOBNA FUNKCJA. Ekran drugiego kroku istnieje TYLKO w tej jednej
 * szczelinie: po poprawnym haśle, przed pełnym zalogowaniem. Jedynym śladem
 * pierwszego kroku jest klucz `logowanie.2fa.user_id` w sesji; bez niego
 * `TwoFactorChallengeController::show()` odsyła na `/login` — a ciasteczko
 * gościa tego klucza nie ma, ciasteczko zalogowanego już go nie ma. Żaden
 * z trzech istniejących stanów tego ekranu nie pokaże.
 *
 * CZEGO TU NIE MA — TA SAMA GRANICA CO PRZY `stanModeratora()` WYŻEJ.
 * Nie ustawiamy klucza sesji z zewnątrz, nie wyłączamy middleware'u i nie
 * podkładamy nic do bazy. Automat robi to, co człowiek: wysyła formularz
 * logowania z prawidłowym hasłem konta, które ma potwierdzone 2FA, i zostaje
 * tam, gdzie go serwis postawi. Gdyby kiedykolwiek dało się tu wejść inaczej,
 * byłaby to usterka warta znalezienia, a nie skrót dla pomiaru.
 *
 * DLACZEGO KONTO MODERATORA. To jedyne konto demo z 2FA — i ma je dlatego, że
 * `stanModeratora()` PRZED CHWILĄ je włączyło, przechodząc przez prawdziwy
 * formularz. Stąd twarde wymaganie kolejności: ta funkcja musi być wołana PO
 * tamtej. Gdyby kiedyś przestała, logowanie skończyłoby się na `/home`
 * i dostaniemy o tym zdanie niżej, a nie cichy pomiar strony głównej.
 *
 * SESJA NIE JEST ZUŻYWANA: `GET /logowanie/kod` tylko pokazuje widok, więc
 * ten sam stan obsłuży wszystkie warianty axe i wszystkie szerokości pomiaru
 * układu. Logujemy się raz, nie kilkadziesiąt razy — z tego samego powodu, dla
 * którego `stanZalogowanego()` oddaje ciasteczko zamiast logować się wszędzie
 * od nowa (koszyk `login_limits.para` to pięć prób na minutę).
 */
async function stanPrzedKodem2FA(przegladarka, adres) {
  const kontekst = await przegladarka.newContext();
  const strona = await kontekst.newPage();

  await strona.goto(`${adres}/login`);
  await strona.fill('input[name="login"]', KONTO_MODERATORA);
  await strona.fill('input[name="password"]', 'haslo-testowe-123');
  await Promise.all([
    strona.waitForURL((u) => ! u.pathname.endsWith('/login'), { timeout: 15000 }),
    // Po napisie, nie po `button[type="submit"]` — uzasadnienie przy
    // `stanModeratora()` wyżej.
    strona.getByRole('button', { name: 'Zaloguj się' }).first().click(),
  ]);

  const sciezka = new URL(strona.url()).pathname;

  if (sciezka !== '/logowanie/kod') {
    throw new Error(
      `Automat nie zatrzymał się na drugim kroku logowania: po haśle wylądował na ${sciezka} `
      + `zamiast na /logowanie/kod. Znaczy to, że konto „${KONTO_MODERATORA}" nie ma `
      + 'potwierdzonego 2FA — a więc `stanModeratora()` albo się nie wykonało, albo '
      + 'przestało je włączać. Ekran drugiego kroku nie zostałby zmierzony. '
      + 'NIE ustawiaj klucza `logowanie.2fa.user_id` z zewnątrz, żeby to obejść.',
    );
  }

  const stan = await kontekst.storageState();
  await kontekst.close();

  return stan;
}

/*
 * CZEKAMY, AŻ PRZEGLĄDARKA SKOŃCZY PODMIENIAĆ FONT — INACZEJ MIERZYMY
 * UKŁAD, KTÓREGO NIKT NIGDY NIE ZOBACZY.
 *
 * `resources/css/fonts.css` serwuje Inter Variable z `font-display: swap`,
 * czyli świadomie: tekst jest widoczny NATYCHMIAST w foncie systemowym
 * i podmienia się dopiero po pobraniu pliku (uzasadnienie tej decyzji stoi
 * w tamtym pliku i zostaje). Skutek dla POMIARU jest jednak taki, że między
 * `domcontentloaded` a końcem podmiany strona jest ułożona CZCIONKĄ
 * ZASTĘPCZĄ Z SYSTEMU — a `--font-sans` ma za Interem `-apple-system`,
 * `Segoe UI` i dalszą listę, więc ta czcionka jest INNA na każdej maszynie.
 *
 * Zmierzone: ten sam commit, ten sam skrypt, ta sama baza — u nas w obrazie
 * deweloperskim 0 naruszeń Focus Not Obscured, a na runnerze jedno
 * (odnośnik „Basia" na `/szukaj` przy 414 px i skali 140% w całości pod dolną
 * belką). Powtórzenie u nas na trzech świeżo wysianych bazach i na obu
 * wersjach Chromium (1194 z obrazu i 1243, którą bierze Playwright na CI):
 * dalej 0. Różnicą nie były więc ani dane, ani przeglądarka — tylko to, jaką
 * czcionką była ułożona strona w chwili pomiaru.
 *
 * To jest ta sama klasa błędu, którą ten skrypt łapał już dwa razy (kontrast
 * czytany w połowie przejścia motywu, `.skip-link` złapana w połowie ruchu)
 * i rozwiązanie jest to samo: mierzymy stan KOŃCOWY. `document.fonts.ready`
 * mówi wprost, kiedy on nastaje.
 *
 * Wyścig z limitem czasu, nie samo `await`: gdyby plik fontu kiedyś nie
 * doszedł, pomiar ma pojechać czcionką zastępczą i to zgłosić w liczbach,
 * a nie zawisnąć na trzydziestu ekranach po kolei.
 */
async function poczekajNaFonty(strona) {
  await strona.evaluate(async () => {
    if (! document.fonts) return;

    await Promise.race([
      document.fonts.ready,
      new Promise((gotowe) => setTimeout(gotowe, 5000)),
    ]);
  });
}

/*
 * WŁĄCZAMY NASZE USTAWIENIE „POWIĘKSZ TEKST" I CZEKAMY, AŻ STRONA NAPRAWDĘ
 * SIĘ NA NIE PRZELICZY — TO JEST TA USTERKA, KTÓRA TRZYMAŁA `main` NA
 * CZERWONO (D-099).
 *
 * CO SIĘ DZIAŁO. Produkt wydaje `data-text-scale` na `<html>` po stronie
 * serwera (`layout.blade.php` w. 165), więc strona człowieka jest ułożona
 * wielkim tekstem OD PIERWSZEGO ułożenia i nigdy się z tego powodu nie
 * przelicza. Ten automat robił odwrotnie: wczytywał stronę w rozmiarze
 * domyślnym, dopiero potem dokładał atrybut — i od razu zaczynał mierzyć.
 * Przeliczenie układu po zmianie atrybutu na korzeniu NIE JEST
 * natychmiastowe. Zmierzone w tym kontenerze (Chromium 153): pomiar zaraz
 * po `setAttribute` potrafił jeszcze zobaczyć układ SPRZED skalowania —
 * `--user-text-scale` czytało się już jako `1.4`, a `.bottom-nav` miała
 * dalej 66,6 px wysokości i stopka 128 px wypełnienia zamiast 179,2 px.
 *
 * DLACZEGO TO DAWAŁO NARUSZENIE 2.4.11, KTÓREGO W PRODUKCIE NIE MA. Strona
 * przy tekście 140% jest o mniej więcej jedną trzecią wyższa (zmierzone na
 * `/szukaj` przy 320 px: 2796 → 3744 px). Jeżeli przeliczenie wypadło
 * W TRAKCIE chodzenia Tabem, element, który przeglądarka przed chwilą
 * przewinęła nad belkę, zjeżdżał razem z rosnącą stroną w dół — a drugi raz
 * nikt go już nie przewija, bo fokus się nie zmienił. Element lądował pod
 * belką i automat notował FAIL. Zmierzone: przy przeliczeniu spóźnionym
 * o sześć kroków Taba wychodzą DOKŁADNIE te elementy, które zgłaszało CI
 * (odnośnik „Ania" na tablicy przy 360 i 414 px); przy spóźnieniu o trzy
 * i o dziesięć kroków — zero. Stąd brała się cała zmienność wyniku, łącznie
 * z przebiegami zielonymi.
 *
 * DLACZEGO NIE „POCZEKAĆ CHWILĘ". Czekanie na zegar jest zakładem o szybkość
 * maszyny, a runner GitHuba bywa wolniejszy od tego kontenera — czyli
 * dokładnie tym samym błędem, tylko z innym progiem. Czekamy więc na STAN:
 * aż UŁOŻONA strona pokaże wielkość pisma odpowiadającą żądanej skali.
 * A jeśli nie pokaże jej nigdy, kończymy BŁĘDEM — bo cichy pomiar strony
 * w nieznanej skali to dokładnie ta fałszywa zieleń, przed którą ten skrypt
 * ma bronić (ten sam wzorzec, co sprawdzenie korzenia przy wariancie
 * „czcionka przeglądarki 200%" niżej).
 *
 * Zwraca `true`, gdy skala jest już w układzie. `false` znaczy „nie udało
 * się" i wywołujący MA POMINĄĆ ten ekran, zamiast zapisać go jako zbadany.
 */
async function wlaczSkaleTekstu(strona, skala, gdzie) {
  await strona.evaluate(
    (s) => document.documentElement.setAttribute('data-text-scale', String(s)),
    skala,
  );

  /*
   * `body` ma `font-size: var(--text-body)`, czyli `1.125rem` mnożone przez
   * `--user-text-scale` (`tokens.css`). To jest wielkość pisma widoczna na
   * UŁOŻONEJ stronie — a nie sama wartość zmiennej, która potrafi być już
   * nowa wtedy, gdy układ jest jeszcze stary. Sprawdzanie samej zmiennej
   * przepuszczałoby dokładnie ten stan, przez który powstała ta funkcja.
   */
  const oczekiwana = 1.125 * BAZOWA_CZCIONKA_PX * (skala / 100);

  const zgadza = await strona.evaluate(async (cel) => {
    const teraz = () => Number.parseFloat(getComputedStyle(document.body).fontSize);
    const klatka = () => new Promise((dalej) => requestAnimationFrame(() => dalej()));

    // Trzydzieści klatek to około pół sekundy przy 60 Hz i kilka sekund na
    // maszynie zdławionej — a kończy się zgłoszeniem błędu, nie cichym
    // przejściem dalej.
    for (let proba = 0; proba < 30; proba++) {
      if (Math.abs(teraz() - cel) < 0.5) {
        return true;
      }

      await klatka();
    }

    return Math.abs(teraz() - cel) < 0.5;
  }, oczekiwana);

  if (! zgadza) {
    console.error(
      `BŁĄD: ${gdzie} — strona nie przeliczyła się na skalę tekstu ${skala}% `
      + `(oczekiwane ${oczekiwana} px pisma podstawowego). Pomiar w nieznanej `
      + 'skali jest gorszy niż jego brak, więc ten ekran zostaje pominięty.',
    );
    process.exitCode = 1;

    return false;
  }

  return true;
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

// Dwa ekrany odzyskania dostępu muszą mieć formularz, a nie wariant zapasowy
// „poczta nie działa" — pełne uzasadnienie przy tej funkcji wyżej.
const przeszkodaOdzyskania = await przeszkodaWFormularzachOdzyskania(adres);

if (przeszkodaOdzyskania !== null) {
  console.error(`BŁĄD: ${przeszkodaOdzyskania}`);
  zamknij();
  process.exit(1);
}

// Ekrany logowania i rejestracji muszą mieć rząd „Wejdź kontem Google /
// Facebooka", a nie wariant bez kluczy — pełne uzasadnienie przy tej funkcji
// wyżej (#345).
const przeszkodaWejsc = await przeszkodaWWejsciachZewnetrznych(adres);

if (przeszkodaWejsc !== null) {
  console.error(`BŁĄD: ${przeszkodaWejsc}`);
  zamknij();
  process.exit(1);
}

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/*
 * WERSJA PRZEGLĄDARKI W RAPORCIE — bo raz już kosztowała pół wieczoru.
 *
 * `znajdzChromium()` wyżej bierze przeglądarkę z obrazu deweloperskiego,
 * jeśli tam jest, a na runnerze zostawia wybór Playwrightowi. To znaczy, że
 * TE DWA ŚRODOWISKA MOGĄ MIERZYĆ INNĄ PRZEGLĄDARKĄ: obraz miał 1194,
 * a `playwright` 1.63 przypina 1243. Przy „u mnie zielone, na CI czerwone"
 * jest to pierwsza rzecz do sprawdzenia — i dopóki jej nie było w logu,
 * sprawdzało się ją ostatnią. Numer wersji nic nie kosztuje.
 */
const wersjaPrzegladarki = przegladarka.version();
log(`Chromium ${wersjaPrzegladarki}`
  + (CHROMIUM ? ` (${CHROMIUM})` : ' (z paczki `playwright`)'));
log('');

/* =============================================================================
 * JEDNOZNACZNY WYBÓR PRÓBKI — KAŻDY PRZEBIEG MA MIERZYĆ TEN SAM OBIEKT
 * =============================================================================
 *
 * Wszystko niżej, co wybiera z bazy JEDEN przepis, JEDEN wpis albo JEDNO
 * konto do zmierzenia, kończy się na `->orderBy('id')` — a tam, gdzie
 * porządek ma znaczyć „najnowsze", na `->orderByDesc('published_at')
 * ->orderByDesc('id')`, czyli dokładnie tak, jak sortuje feed (AGENTS.md §8).
 *
 * DLACZEGO TO NIE JEST PORZĄDKOWANIE KODU. `SELECT … LIMIT 1` bez `ORDER BY`
 * nie obiecuje w PostgreSQL NICZEGO o tym, który wiersz wróci. Wynik zależy
 * od planu zapytania, a plan zmienia się od liczby wierszy, od `VACUUM`,
 * od kolejności zapisu — czyli od rzeczy, których nie ma w tym repozytorium.
 * Ten sam kod, ta sama baza, inny dzień: inny mierzony wpis.
 *
 * Groźne jest to, jak taki przebieg WYGLĄDA. Raport nazywa ekran, nie próbkę,
 * więc czerwień na „wpis — kolaż" czyta się jako skutek czyjejś zmiany, choć
 * bywa skutkiem tego, że kolaż wylosował się dziś inny. Ktoś szuka pół dnia,
 * co zepsuł, i nie zepsuł nic. Odwrotnie jest gorzej: prawdziwa regresja
 * chowa się wtedy za zdaniem „a, to pewnie ta zmienność".
 *
 * PORZĄDKUJEMY PO `id`, NIE PO `created_at`. `id` jest UUID-em v7 (`HasUuids`),
 * czyli kluczem głównym: unikalnym z definicji i niezmiennym. `created_at`
 * ma rozdzielczość, w której dwa wiersze zasiane w tej samej sekundzie są
 * nierozróżnialne — a seeder właśnie tak je zapisuje.
 *
 * NIE DOTYCZY to zapytań po `username` (`profiles.username` ma UNIQUE, więc
 * wiersz jest co najwyżej jeden) ani `.first()` z Playwrighta, które chodzi
 * po kolejności dokumentu, nie po kolejności bazy.
 * ========================================================================== */

/*
 * Adres przepisu bierzemy z BAZY, nie ze strony.
 *
 * Pierwsza wersja szukała linku na „Świeżo z Kuking" — i nic nie znajdowała,
 * bo ta strona pokazuje wpisy, nie przepisy. Skutek był gorszy niż błąd:
 * ekran przepisu po cichu WYPADAŁ ze sprawdzania, a raport wyglądał
 * na kompletny. Zapytanie do bazy nie zależy od tego, która strona akurat
 * linkuje do przepisów.
 *
 * `->orderBy('id')` — patrz `JEDNOZNACZNY WYBÓR PRÓBKI` wyżej. Bez tego
 * PostgreSQL wolno oddać dowolny z przepisów demo, a raport wyglądałby
 * tak samo przy każdym z nich.
 */
const adresPrzepisu = (() => {
  const slug = execFileSync('php', ['artisan', 'tinker', '--execute',
    "echo optional(App\\Models\\Recipe::where('status','published')->where('visibility','public')"
    + "->orderBy('id')->first())->slug;",
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
// Własna rzeczywista próbka, także dla axe/układu: brak fixture jest błędem,
// nigdy powodem do powrotu do wygodnej karuzeli z samymi poziomymi zdjęciami.
const mieszanaKaruzela = przygotujKaruzele({ ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA });
process.once('exit', () => mieszanaKaruzela.sprzataj());
const wpisyPoTrybie = (() => {
  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute',
    "foreach (['normal','carousel','collage'] as $t) { "
    + "$w = App\\Models\\Post::where('display_mode', $t)->where('status','published')"
    + "->where('visibility','public')->has('media', '>=', 2)->orderBy('id')->first(); "
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

  mapa.carousel = mieszanaKaruzela.id;
  return mapa;
})();

/*
 * WPIS AUTORA O STUZNAKOWEJ NAZWIE — PRZYPIĘTY PO AUTORZE, NIE PO KOLEJNOŚCI.
 *
 * ZNALEZISKO Z 12 WRZEŚNIA, i jest to Sprawa 1 w czystej postaci. Karta wpisu
 * Zofii (`KONTO_DLUGA_NAZWA`) wchodziła do pomiaru fokusu BOCZNYMI DRZWIAMI:
 * na `EKRANY_FOCUS` nie ma jej ani razu, a mierzona była dlatego, że pozycja
 * „wpis (przykładowy)" rozwiązuje się przez `znajdz: 'wpis:normal'`, a wpis
 * Zofii był akurat tym, który oddawał `SELECT … LIMIT 1` bez `ORDER BY`.
 *
 * Dopisanie nowszego wpisu `DISPLAY_NORMAL` do `DemoSeeder` wystarczyłoby,
 * żeby ta próbka przestała pilnować WCAG 2.4.11 — i nie oblałby się przy tym
 * ani jeden test. Trzy naruszenia, które ta próbka znalazła (#440), po prostu
 * przestałyby być mierzone, a raport wyglądałby tak samo.
 *
 * Samo `->orderBy('id')` w `wpisyPoTrybie` wyżej czyni ten wybór POWTARZALNYM,
 * ale nie czyni go TRUDNYM: ustala tylko, że zawsze wchodzi ten sam wpis,
 * niezależnie od tego, czy jest to wpis o coś wart mierzenia. Dlatego karta
 * z długą nazwą dostaje własną pozycję i własne zapytanie, pytające o AUTORA
 * — a to jest cecha, której dopisanie wpisu nie zmienia.
 */
const wpisDlugiejNazwy = (() => {
  const id = execFileSync('php', ['artisan', 'tinker', '--execute',
    `$a = App\\Models\\Profile::where('username','${KONTO_DLUGA_NAZWA}')->value('user_id'); `
    + "if (! $a) { echo ''; exit; } "
    + "echo App\\Models\\Post::where('author_id',$a)->publiclyVisible()"
    + "->orderBy('id')->value('id') ?? '';",
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA } })
    .toString().trim();

  return id === '' ? null : id;
})();

if (wpisDlugiejNazwy === null) {
  console.error(`BŁĄD: konto „${KONTO_DLUGA_NAZWA}" nie ma publicznego wpisu — karta wpisu `
    + 'autora o najdłuższej dopuszczalnej nazwie wypadłaby z pomiaru fokusu (WCAG 2.2 AA 2.4.11), '
    + 'a raport wyglądałby tak samo jak przy pełnej próbce.');
  zamknij();
  process.exit(1);
}

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
    "$m = App\\Models\\User::where('role','moderator')->orderBy('id')->value('id'); "
    // `username` mieszka na `Profile` (klucz główny `user_id`), nie na
    // `User` — patrz komentarz w App\Models\User o danych publicznych.
    + `$b = App\\Models\\Profile::where('username','${KONTO_ZALOGOWANE}')->value('user_id'); `
    + "if (!$m || !$b) { echo ''; exit; } "
    + "$a = App\\Models\\ModerationAction::where('subject_user_id',$b)"
    + "->whereIn('action', App\\Models\\ModerationAction::ODWOLYWALNE)->orderBy('id')->first(); "
    + "if (!$a) { "
    + "$przepis = App\\Models\\Recipe::where('author_id',$b)->orderBy('id')->value('id'); "
    + "$wpis = $przepis ? null : App\\Models\\Post::where('author_id',$b)->orderBy('id')->value('id'); "
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
    + "$m = App\\Models\\User::where('role','moderator')->orderBy('id')->value('id'); "
    + "if (!$b || !$m) { echo ''; exit; } "
    + "$z = App\\Models\\Report::where('reporter_id',$b)->orderBy('id')->first(); "
    + "if (!$z) { "
    + "$cel = App\\Models\\Post::where('author_id','!=',$b)->orderBy('id')->value('id'); "
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
 * TRZY EKRANY PANELU MUSZĄ MIEĆ CO POKAZAĆ — INACZEJ MIERZYMY PUSTY STAN
 * I ZAPISUJEMY „✓" (issue #294, ta sama reguła co D-106).
 *
 * ZMIERZONE 11 WRZEŚNIA, na świeżo wysianej bazie demo, przy 900 px:
 *
 *     ekran                 co stało na ekranie              węzłów w <main>
 *     /admin/uzytkownicy    tabela czterech kont                        126
 *     /admin/zgloszenia     „Nic tu nie ma"                              19
 *     /admin/sygnaly        „Nic tu nie ma"                              18
 *
 * Dwie z trzech kolejek panelu były więc mierzone jako PUSTY STAN: nagłówek,
 * rząd zakładek z zerami i komponent `x-empty-state`. A cała rzecz, przez
 * którą panel wszedł do tego pomiaru — karta sprawy z formularzem decyzji
 * (`choice-grid`, dwa zestawy pól wyboru, pole terminu, lista podstaw
 * prawnych) i karta grupy automatu z paskiem podglądów — nie była na ekranie
 * ani raz. Pusty stan przechodzi każdy audyt dostępności, nie sprawdzając
 * niczego; to jest dokładnie ta pułapka, o której mówi nagłówek tego pliku
 * i pułapka 5 z `docs/PULAPKI_TESTOW.md`.
 *
 * `DemoSeeder` nie tworzy ANI JEDNEGO zgłoszenia (sprawdzone:
 * `grep -n 'Report::' database/seeders/DemoSeeder.php`, zero wyników),
 * a jedyne zgłoszenie, jakie ten automat zakładał (`idZgloszenia` wyżej), ma
 * status `rejected` i należy do konta zgłaszającego — kolejka moderatora
 * pokazuje domyślnie sprawy OTWARTE i wyłącznie te od ludzi
 * (`ModerationController::reports()`), więc go nie widzi. Kolejka automatu
 * czyta tylko wiersze `source = 'automat'`, których nie tworzył nikt.
 *
 * Dane dokładamy TUTAJ, tym samym mechanizmem co `idOdwolania`,
 * `idZgloszenia` i `tablicaDnia` wyżej — a nie w `DemoSeeder`, który jest
 * czyjąś cudzą, trwającą pracą. Sprawdzenie „czy już jest" przed zapisem
 * czyni to bezpiecznym także przy `ADRES=…`, czyli na serwerze postawionym
 * wcześniej.
 *
 * CO DOKŁADAMY I DLACZEGO WŁAŚNIE TO
 *  1. Sprawę OTWARTĄ ZE ŹRÓDŁA SPOŁECZNOŚCIOWEGO — jedyny stan, w którym
 *     karta zgłoszenia rozwija PEŁNY formularz decyzji. Sprawa rozstrzygnięta
 *     pokazuje sam wynik, czyli podzbiór tego układu.
 *  2. Sprawę OTWARTĄ Z DROGI PRAWNEJ, anonimową (DSA art. 16 ust. 2 lit. c) —
 *     inny kształt karty: zamiast nazwy zgłaszającego stoi tam ADRES
 *     zgłoszonej treści w `.kod-do-przepisania`, czyli jeden długi łańcuch
 *     bez spacji. To jest klasyczne źródło przepełnienia w poziomie przy
 *     320 px (WCAG 1.4.10) i do dziś nie było mierzone nigdzie.
 *  3. Trzy oznaczenia automatu w DWÓCH grupach, z czego jedna ma dwie
 *     pozycje — bo `/admin/sygnaly` grupuje po autorze treści i przy jednej
 *     pozycji w grupie nie pokazuje ani licznika w liczbie mnogiej, ani
 *     drugiego wiersza listy.
 *
 * ZGŁOSZENIE OTWARTE NIE ZMIENIA ŻADNEGO INNEGO MIERZONEGO EKRANU. Wiersz
 * w `reports` czytają wyłącznie dwa widoki panelu i ekran odwołań
 * (sprawdzone: `grep -rln 'Report::\|->reports' resources/views/`), a
 * oznaczenie automatu z definicji nie rusza treści, nie powiadamia autora
 * i nie zmienia niczego, co widzi czytelnik (`Report::SOURCE_AUTOMAT`).
 * Zgłaszającym jest przy tym konto INNE niż `KONTO_ZALOGOWANE`, więc lista
 * „twoje zgłoszenia" mierzona wyżej zostaje bez zmian.
 *
 * Oznaczenia automatu zakładamy PRAWDZIWĄ AKCJĄ (`OznaczDoPrzegladu`), a nie
 * `Report::create` — tą samą drogą, którą idzie wykrywacz sygnałów. Akcja
 * sama pilnuje „jedno oznaczenie na treść" i sama zapisuje wpis do dziennika,
 * więc drugie uruchomienie skryptu nie dokłada niczego, a mierzony ekran
 * stoi na danych o tym samym kształcie co w produkcie.
 */
const kolejkiPanelu = (() => {
  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute',
    "$m = App\\Models\\User::where('role','moderator')->orderBy('id')->value('id'); "
    + `$automat = App\\Models\\Profile::where('username','${KONTO_ZALOGOWANE}')->value('user_id'); `
    + "if (! $m || ! $automat) { echo ''; exit; } "
    // Zgłaszający INNY niż konto, którym loguje się ten automat — inaczej
    // zmieniłaby się lista „twoje zgłoszenia", mierzona wyżej.
    + "$zglaszajacy = App\\Models\\User::where('status','active')->whereKeyNot($automat)"
    + "->whereKeyNot($m)->orderBy('id')->value('id'); "
    + "$wpisy = App\\Models\\Post::publiclyVisible()"
    + "->orderByDesc('published_at')->orderByDesc('id')->get(); "
    + "if (! $zglaszajacy || $wpisy->count() < 2) { echo ''; exit; } "
    // Sprawa społecznościowa, OTWARTA — pełny formularz decyzji.
    + "$celA = $wpisy->first(fn ($p) => $p->author_id !== $zglaszajacy); "
    + "if ($celA && ! App\\Models\\Report::where('source', App\\Models\\Report::SOURCE_COMMUNITY)"
    + "->where('status', App\\Models\\Report::STATUS_OPEN)->exists()) { "
    + "App\\Models\\Report::create(['reporter_id'=>$zglaszajacy,'source'=>App\\Models\\Report::SOURCE_COMMUNITY,"
    + "'target_type'=>'post','target_id'=>$celA->getKey(),'reason'=>'spam',"
    + "'details'=>'Ten wpis to ogłoszenie sklepu z garnkami, wklejone po raz trzeci w tym tygodniu.',"
    + "'status'=>App\\Models\\Report::STATUS_OPEN]); } "
    // Sprawa z drogi prawnej, OTWARTA i anonimowa — druga postać karty,
    // z adresem treści w jednym długim łańcuchu bez spacji.
    + "$celB = $wpisy->last(); "
    + "if ($celB && ! App\\Models\\Report::where('source', App\\Models\\Report::SOURCE_LEGAL_NOTICE)"
    + "->where('status', App\\Models\\Report::STATUS_OPEN)->exists()) { "
    + "App\\Models\\Report::create(['reporter_id'=>null,'source'=>App\\Models\\Report::SOURCE_LEGAL_NOTICE,"
    + "'target_type'=>'post','target_id'=>$celB->getKey(),'target_url'=>url('/wpisy/'.$celB->getKey()),"
    + "'reason'=>'copyright','details'=>'Zdjęcie z tego wpisu pochodzi z mojej książki kucharskiej.',"
    + "'illegality_explanation'=>'Zdjęcie jest moim utworem w rozumieniu prawa autorskiego i zostało "
    + "opublikowane bez mojej zgody. Wnoszę o jego usunięcie.','good_faith_at'=>now(),"
    + "'status'=>App\\Models\\Report::STATUS_OPEN]); } "
    // Oznaczenia automatu: dwie pozycje jednego autora (grupa się zwija)
    // i jedna innego, żeby na ekranie stały DWIE grupy.
    + "$akcja = app(App\\Domain\\Moderation\\Actions\\OznaczDoPrzegladu::class); "
    + "$poAutorach = $wpisy->groupBy('author_id')->sortByDesc(fn ($g) => $g->count()); "
    + "$grupa = $poAutorach->first(); "
    + "$inna = $poAutorach->skip(1)->first(); "
    + "foreach ($grupa->take(2) as $p) { $akcja->handle($p, "
    + "[new App\\Domain\\Moderation\\Sygnaly\\Sygnal('automat_wzorzec', "
    + "'Treść pasuje do znanego wzorca ogłoszeń sklepowych: trzy odnośniki i słowo „promocja”.')]); } "
    + "if ($inna) { foreach ($inna->take(1) as $p) { $akcja->handle($p, "
    + "[new App\\Domain\\Moderation\\Sygnaly\\Sygnal('automat_model', "
    + "'Model wskazał tę treść do przejrzenia w kategorii „nękanie”.')]); } } "
    + "$ludzi = App\\Models\\Report::where('source','!=',App\\Models\\Report::SOURCE_AUTOMAT)"
    + "->where('status', App\\Models\\Report::STATUS_OPEN)->count(); "
    + "$grup = App\\Models\\Report::where('source', App\\Models\\Report::SOURCE_AUTOMAT)"
    + "->whereIn('status',['open','triage','reviewing'])->distinct('autor_tresci_id')->count('autor_tresci_id'); "
    + "echo $ludzi.'|'.$grup;",
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA } })
    .toString().trim();

  return wynik === '' ? null : wynik;
})();

if (kolejkiPanelu === null) {
  console.error('BŁĄD: nie udało się przygotować kolejek panelu moderacji — `/admin/zgloszenia` '
    + 'i `/admin/sygnaly` byłyby mierzone jako PUSTY STAN, a raport zapisałby „✓" dla ekranów, '
    + 'na których nic nie stało (brak konta moderatora, drugiego konta albo dwóch wpisów w bazie).');
  zamknij();
  process.exit(1);
}

log(`Kolejki panelu: ${kolejkiPanelu.split('|')[0]} otwarte sprawy od ludzi, `
  + `${kolejkiPanelu.split('|')[1]} grupy w kolejce automatu.`);

/*
 * TABLICA „kuKINGi na dziś" MUSI MIEĆ CO POKAZAĆ NA EKRANACH Z SZYNĄ
 * (issue #272).
 *
 * TO JEST DZIURA W POMIARZE, NIE OZDOBA. Tablica stoi w PRAWEJ SZYNIE na
 * `/home` i `/szukaj`, a w głównej kolumnie na `/` i `/odkryj`. Szyna ma
 * 352 px, główna kolumna 720 — i cała usterka z #272 (rząd trzech miniatur
 * `72 px` zawijający po jednej na wiersz) występuje WYŁĄCZNIE przy tej
 * węższej. Oba ekrany z szyną były na liście `EKRANY_UKLADU` od dawna,
 * więc przepełnienie było na nich mierzone — tylko nie na tablicy, bo dla
 * konta, którym ten automat się loguje, sekcja OSOBY była PUSTA.
 *
 * Dlaczego pusta: `DailyBoard::peopleToFollow()` wyklucza osoby, które widz
 * już obserwuje, a `DemoSeeder` daje `ania` obserwowanie `basia` i `marek`.
 * Zostawało konto moderatora, które nie ma ani jednego publicznego wpisu —
 * czyli zero osób. Automat wpisywał „✓" dla `/home`, nie mając w drzewie ani
 * jednego `.kuking-board-preview`.
 *
 * ROZWIĄZANIE: WYBÓR REDAKCYJNY NA DZIŚ, nie odbieranie `ania` obserwowanych.
 * `DailyBoard::fromCuratedPicks()` NIE wyklucza osób obserwowanych, więc
 * jeden wiersz w `daily_picks` stawia tablicę pełną także dla konta, które
 * obserwuje wszystkich. Odpięcie obserwowanych zrobiłoby coś gorszego:
 * opróżniłoby feed na `/home`, czyli zamieniłoby mierzony ekran na jego
 * pusty stan — dokładnie ta klasa fałszywej zieleni, o której mówi nagłówek
 * tego pliku.
 *
 * Dane dokładamy TUTAJ, tym samym mechanizmem co `idOdwolania`
 * i `idZgloszenia` wyżej, a nie w `DemoSeeder` (cudza, trwająca praca).
 * Sprawdzenie „czy już jest" przed zapisem czyni to bezpiecznym przy
 * `ADRES` wskazującym serwer postawiony wcześniej.
 *
 * WYBIERAMY TRZY POZYCJE, KAŻDA POD INNY KSZTAŁT KARTY:
 *   1. osobę z CO NAJMNIEJ TRZEMA gotowymi zdjęciami — to jest jedyny
 *      kształt, w którym pasek miniatur ma szansę się zawinąć;
 *   2. danie ZE zdjęciem — układ dwukolumnowy (zdjęcie 96 px + podpis);
 *   3. danie BEZ zdjęcia — od #272 nie renderuje pustego odnośnika, więc
 *      podpis ma stać przy tej samej krawędzi co reszta kart.
 * Bez pozycji 1 pomiar niżej nie ma czego mierzyć; bez 2 i 3 karta dania
 * jest mierzona tylko w jednym ze swoich dwóch stanów.
 */
const tablicaDnia = (() => {
  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute',
    `$konto = App\\Models\\Profile::where('username','${KONTO_ZALOGOWANE}')->value('user_id'); `
    + "$mod = App\\Models\\User::where('role','moderator')->orderBy('id')->value('id'); "
    + "if (! $konto) { echo ''; exit; } "
    // Osoba z paskiem miniatur. Liczymy tak samo, jak liczy widok:
    // zdjęcia gotowe z TRZECH ostatnich publicznych wpisów (kuking-board.blade.php).
    + "$osoba = null; "
    + "foreach (App\\Models\\User::where('status','active')->whereKeyNot($konto)->orderBy('id')->get() as $u) { "
    + "$ile = 0; "
    + "foreach (App\\Models\\Post::where('author_id',$u->getKey())->publiclyVisible()"
    + "->orderByDesc('published_at')->orderByDesc('id')->limit(3)->get() as $p) { "
    + "$ile += $p->media->filter(fn ($m) => $m->isReady())->count(); } "
    + "if ($ile >= 3) { $osoba = $u; break; } } "
    // Danie ze zdjęciem i danie bez zdjęcia — dwa różne kształty karty.
    + "$wpisy = App\\Models\\Post::publiclyVisible()->where('author_id','!=',$konto)"
    + "->orderByDesc('published_at')->orderByDesc('id')->with('media')->get(); "
    + "$zeZdjeciem = $wpisy->first(fn ($p) => $p->media->contains(fn ($m) => $m->isReady())); "
    + "$bezZdjecia = $wpisy->first(fn ($p) => ! $p->media->contains(fn ($m) => $m->isReady())); "
    + "if (! $osoba || ! $zeZdjeciem) { echo ''; exit; } "
    + "$dzien = App\\Support\\Czas::dzisiajData(); "
    + "$poz = 0; "
    + "foreach ([[App\\Models\\DailyPick::TYPE_USER, $osoba->getKey()], "
    + "[App\\Models\\DailyPick::TYPE_POST, $zeZdjeciem->getKey()], "
    + "[App\\Models\\DailyPick::TYPE_POST, $bezZdjecia?->getKey()]] as [$typ, $id]) { "
    + "if (! $id) { continue; } "
    + "App\\Models\\DailyPick::firstOrCreate("
    + "['shown_on' => $dzien, 'subject_type' => $typ, 'subject_id' => $id], "
    + "['position' => $poz, 'curator_id' => $mod, "
    // KRÓTKIE ZDANIE, NIE AKAPIT. W szynie blok tekstu karty osoby ma
    // 105 px z 310 (awatar i przycisk „Obserwuj" biorą resztę), więc długa
    // notka rozsypuje się tam na jedno słowo w wierszu i mierzony ekran
    // przestaje przypominać ten, który widzi człowiek. Notki gospodarza
    // w produkcie są krótkie („Halina pierwszy raz pokazała swój chleb") —
    // i ta ma być taka sama.
    + "'note' => 'Wybór automatu dostępności.']); $poz++; } "
    + "echo $osoba->getKey();",
  ], { env: { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA } })
    .toString().trim();

  return wynik === '' ? null : wynik;
})();

if (tablicaDnia === null) {
  console.error('BŁĄD: nie udało się przygotować wyboru redakcyjnego na dziś — tablica '
    + '„kuKINGi na dziś" byłaby w prawej szynie pusta, a pasek miniatur (issue #272) '
    + 'nie zostałby zmierzony na żadnym ekranie z szyną.');
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
 * `znajdz: 'zgloszenie'` → karta sprawy zgłoszenia złożonego przez to konto,
 * `znajdz: 'wpis-dluga-nazwa'` → wpis autora o najdłuższej dopuszczalnej nazwie.
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

  if (ekran.znajdz === 'wpis-dluga-nazwa') {
    return `/wpisy/${wpisDlugiejNazwy}`;
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
const stanModeratorem = await stanModeratora(przegladarka, adres);
// KOLEJNOŚĆ JEST WYMAGANA, nie przypadkowa: 2FA na koncie moderatora włącza
// dopiero linijka wyżej — patrz komentarz przy `stanPrzedKodem2FA()`.
const stanPoHasle = await stanPrzedKodem2FA(przegladarka, adres);

/*
 * CO NAPRAWDĘ STOI NA TRZECH EKRANACH PANELU — SPRAWDZENIE, NIE ZAŁOŻENIE
 * (issue #294, ta sama reguła co D-106 i ta sama co przy formularzach
 * odzyskania hasła wyżej).
 *
 * PO CO TO JEST, SKORO PĘTLE NIŻEJ SPRAWDZAJĄ JUŻ KOD HTTP I ŚCIEŻKĘ
 * Bo oba te sprawdzenia przechodzą nad PUSTYM STANEM. `/admin/zgloszenia`
 * z zerem spraw odpowiada 200 pod własnym adresem i wygląda w raporcie
 * dokładnie tak samo jak kolejka z kartą sprawy — a jest wtedy nagłówkiem,
 * rzędem zakładek z zerami i komponentem `x-empty-state`. Dokładnie to
 * pokazał pierwszy pomiar tego issue: 19 i 18 węzłów w `<main>` na dwóch
 * z trzech ekranów panelu, przy 126 na trzecim.
 *
 * Poprzedni PR (#323) ustalił to samo jednym zdaniem, które zostaje tu jako
 * reguła: samo dopisanie adresu do listy ekranów NIE WYSTARCZA — trzeba
 * sprawdzić, że automat widzi ekran W TYM STANIE, o który chodzi, a nie
 * wariant zapasowy.
 *
 * KAŻDY WARUNEK JEST TU DLATEGO, ŻE MIERZY CO INNEGO:
 *  - `.empty-state` musi NIE być na ekranie — to jednoznaczny ślad pustej
 *    kolejki i jedyna rzecz, której obecność sama w sobie unieważnia pomiar;
 *  - selektor treści musi trafić co najmniej raz — pusty stan nie ma ani
 *    wiersza tabeli, ani formularza decyzji, ani pozycji w grupie automatu;
 *  - liczba węzłów w `<main>` musi przekroczyć próg — bo to jest jedyna
 *    liczba, która łapie stan pośredni: ekran, który ma już coś poza pustym
 *    stanem, ale nie ma na sobie tego układu, przez który tu jest.
 *
 * KAŻDY PRÓG STOI MIĘDZY DWIEMA ZMIERZONYMI LICZBAMI, a nie tuż pod tą
 * dobrą. Liczby z 11 września, świeża baza demo, 900 px — kolumna „pusto" to
 * ten sam ekran z opróżnioną kolejką (kontrola ujemna tego pomiaru):
 *
 *     ekran                 pełny   pusto   próg
 *     /admin/uzytkownicy      126      —      60
 *     /admin/zgloszenia       186     19      60
 *     /admin/sygnaly           73     18      40
 *
 * Taki próg nie psuje się od dopisania kolumny w tabeli ani od innej liczby
 * kont w demo i nie przepuszcza pustej kolejki. `/admin/uzytkownicy` nie ma
 * kolumny „pusto", bo `DemoSeeder` zawsze sieje konta — pusta tabela kont
 * znaczyłaby brak danych demo, co łapią pozycje wyżej w tym pliku.
 *
 * Niepowodzenie jest tu BŁĘDEM CAŁEGO PRZEBIEGU z nazwanym ekranem, a nie
 * pominięciem jednej pozycji: pomiar w nieznanym stanie jest gorszy niż jego
 * brak, bo wygląda identycznie jak pomiar udany.
 */
const TRESC_PANELU = [
  {
    nazwa: 'panel — użytkownicy',
    sciezka: '/admin/uzytkownicy',
    // Wiersz tabeli kont, nie sama tabela: `<thead>` stoi na ekranie także
    // wtedy, gdy nie ma ani jednego konta do wypisania.
    wybor: 'main table.tabela-kont tbody tr',
    czego: 'ani jednego wiersza w tabeli kont',
    progWezlow: 60,
  },
  {
    nazwa: 'panel — zgłoszenia',
    sciezka: '/admin/zgloszenia',
    // Lista podstaw decyzji jest w formularzu, który rozwija się WYŁĄCZNIE
    // przy sprawie otwartej — czyli w tym jednym stanie, o który tu chodzi.
    wybor: 'main select[name="reason_code"]',
    czego: 'ani jednej karty sprawy z formularzem decyzji',
    progWezlow: 60,
  },
  {
    nazwa: 'panel — sygnały automatu',
    sciezka: '/admin/sygnaly',
    wybor: 'main article.card ul.stack-tight li',
    czego: 'ani jednego oznaczenia automatu',
    progWezlow: 40,
  },
  {
    nazwa: 'panel — kolaż na powitanie',
    sciezka: '/admin/kolaz-powitalny',
    // Pole wyboru przy konkretnym zdjęciu, nie sam formularz: nagłówki,
    // zdanie o licencji i przycisk „Zapisz" stoją na ekranie także wtedy,
    // gdy nie ma ani jednego publicznego zdjęcia do wskazania.
    wybor: 'main input[name="zdjecia[]"]',
    czego: 'ani jednego zdjęcia do wyboru',
    progWezlow: 60,
  },
];

/**
 * Sprawdza wszystkie trzy ekrany panelu i zwraca opis pierwszej przeszkody
 * albo `null`. Liczby wypisuje ZA KAŻDYM PRZEBIEGIEM, także udanym — bo
 * „ile węzłów widzi automat" jest tym, co odróżnia zmierzony ekran od pustego
 * stanu, i ma stać w logu, a nie w cudzej pamięci.
 */
async function przeszkodaWPanelu(przegladarka, adres, stan) {
  const kontekst = await przegladarka.newContext({
    viewport: { width: 900, height: 900 },
    storageState: stan,
  });
  const strona = await kontekst.newPage();

  try {
    for (const ekran of TRESC_PANELU) {
      const odpowiedz = await strona.goto(`${adres}${ekran.sciezka}`, { waitUntil: 'domcontentloaded' });
      const kod = odpowiedz?.status() ?? 0;
      const sciezka = new URL(strona.url()).pathname;

      if (kod !== 200 || sciezka !== ekran.sciezka) {
        return `ekran „${ekran.nazwa}" (${ekran.sciezka}) odpowiedział kodem ${kod} i wylądował `
          + `na ${sciezka}. Automat nie jest w panelu — mierzyłby stronę logowania albo błędu, `
          + 'która przechodzi każdy audyt, nie sprawdzając niczego.';
      }

      const stanEkranu = await strona.evaluate((wybor) => {
        const main = document.querySelector('main');

        return {
          wezlow: main ? main.querySelectorAll('*').length : 0,
          trafien: main ? main.querySelectorAll(wybor).length : 0,
          pustych: main ? main.querySelectorAll('.empty-state').length : 0,
        };
      }, ekran.wybor);

      log(`  ${ekran.nazwa}: ${stanEkranu.wezlow} węzłów w <main>, `
        + `${stanEkranu.trafien} × „${ekran.wybor}"`);

      if (stanEkranu.pustych > 0) {
        return `ekran „${ekran.nazwa}" (${ekran.sciezka}) pokazuje PUSTY STAN `
          + `(${stanEkranu.wezlow} węzłów w <main>). Automat zmierzyłby nagłówek i rząd zakładek `
          + 'z zerami, i zapisał „✓" dla kolejki, w której nic nie stało. Sprawdź `kolejkiPanelu` '
          + 'wyżej w tym pliku — to on dokłada sprawy do obu kolejek.';
      }

      if (stanEkranu.trafien === 0) {
        return `ekran „${ekran.nazwa}" (${ekran.sciezka}) nie ma ${ekran.czego} `
          + `(szukane: „${ekran.wybor}", ${stanEkranu.wezlow} węzłów w <main>). Mierzony byłby `
          + 'inny stan tego ekranu niż ten, przez który wszedł do pomiaru.';
      }

      if (stanEkranu.wezlow < ekran.progWezlow) {
        return `ekran „${ekran.nazwa}" (${ekran.sciezka}) ma ${stanEkranu.wezlow} węzłów `
          + `w <main>, a układ, przez który tu jest, ma ich co najmniej ${ekran.progWezlow}. `
          + 'Na ekranie stoi mniej, niż powinno — sprawdź, co zniknęło, zanim uwierzysz w „✓".';
      }
    }

    return null;
  } finally {
    await kontekst.close();
  }
}

log('Panel moderacji — co widzi automat:');

const przeszkodaPanelu = await przeszkodaWPanelu(przegladarka, adres, stanModeratorem);

if (przeszkodaPanelu !== null) {
  console.error(`BŁĄD: ${przeszkodaPanelu}`);
  zamknij();
  process.exit(1);
}

log('');

/*
 * `/ustawienia/zdjecie` MUSI STAĆ W STANIE „TO JEST TWOJE ZDJĘCIE" (#344).
 *
 * Ta sama reguła co przy `TRESC_PANELU` wyżej i ten sam powód: kod 200 i ten
 * sam adres dostaje się także w stanie „nie masz jeszcze swojego zdjęcia",
 * a tamten stan jest o dwie rzeczy UBOŻSZY — nie ma obrazka 88 px obok
 * akapitu i nie ma całej sekcji „Usunięcie zdjęcia". Właśnie tych dwóch
 * rzeczy dotyczyło przepełnienie z 12 września, więc pomiar bez nich
 * meldowałby „✓" o ekranie, którego trudnej połowy nie widział.
 *
 * DWA WARUNKI, BO MIERZĄ CO INNEGO:
 *  - `img.avatar` w bloku stanu — dowód, że zdjęcie jest GOTOWE. Przy zdjęciu
 *    bez wariantu albo w przetwarzaniu widok stawia tam `<span class="avatar">`
 *    z inicjałem, czyli element o tej samej klasie i innym wpływie na układ;
 *  - `.danger-zone` — sekcja, która istnieje wyłącznie wtedy, gdy zdjęcie
 *    w ogóle jest.
 *
 * Próg węzłów stoi MIĘDZY DWIEMA ZMIERZONYMI LICZBAMI, a nie tuż pod tą
 * dobrą (ta sama zasada co przy progach `TRESC_PANELU`). Zmierzone 12 września,
 * świeża baza demo, 900 px — kolumna „bez zdjęcia" to ten sam ekran po
 * wyzerowaniu `profiles.avatar_media_id`, czyli kontrola ujemna tego progu:
 *
 *     stan ekranu                węzłów w <main>
 *     „to jest Twoje zdjęcie"          33
 *     „nie masz jeszcze zdjęcia"       22
 *
 * Próg 28 nie przepuszcza stanu pustego i nie psuje się od dopisania jednego
 * zdania do pełnego.
 */
const EKRAN_ZDJECIA = {
  nazwa: 'zdjęcie profilowe',
  sciezka: '/ustawienia/zdjecie',
  progWezlow: 28,
};

async function przeszkodaNaEkranieZdjecia(przegladarka, adres, stan) {
  const kontekst = await przegladarka.newContext({
    viewport: { width: 900, height: 900 },
    storageState: stan,
  });
  const strona = await kontekst.newPage();

  try {
    const odpowiedz = await strona.goto(`${adres}${EKRAN_ZDJECIA.sciezka}`, { waitUntil: 'domcontentloaded' });
    const kod = odpowiedz?.status() ?? 0;
    const sciezka = new URL(strona.url()).pathname;

    if (kod !== 200 || sciezka !== EKRAN_ZDJECIA.sciezka) {
      return `ekran „${EKRAN_ZDJECIA.nazwa}" (${EKRAN_ZDJECIA.sciezka}) odpowiedział kodem ${kod} `
        + `i wylądował na ${sciezka}. Automat mierzyłby stronę logowania albo błędu, `
        + 'która przechodzi każdy audyt, nie sprawdzając niczego.';
    }

    const stanEkranu = await strona.evaluate(() => {
      const main = document.querySelector('main');

      return {
        wezlow: main ? main.querySelectorAll('*').length : 0,
        zdjec: main ? main.querySelectorAll('.zdjecie-profilowe-stan img.avatar').length : 0,
        usuniec: main ? main.querySelectorAll('.danger-zone').length : 0,
      };
    });

    log(`  ${EKRAN_ZDJECIA.nazwa}: ${stanEkranu.wezlow} węzłów w <main>, `
      + `${stanEkranu.zdjec} × gotowe zdjęcie, ${stanEkranu.usuniec} × sekcja usunięcia`);

    if (stanEkranu.zdjec === 0 || stanEkranu.usuniec === 0) {
      return `ekran „${EKRAN_ZDJECIA.nazwa}" (${EKRAN_ZDJECIA.sciezka}) stoi w stanie BEZ `
        + `gotowego zdjęcia (${stanEkranu.zdjec} × „.zdjecie-profilowe-stan img.avatar", `
        + `${stanEkranu.usuniec} × „.danger-zone", ${stanEkranu.wezlow} węzłów w <main>). `
        + 'To jedyny z trzech stanów tego ekranu, który NIE przepełniał — mierzenie go '
        + 'byłoby pilnowaniem nie tej rzeczy. Sprawdź `nadajZdjecieProfilowe()` '
        + 'w `database/seeders/DemoSeeder.php`.';
    }

    if (stanEkranu.wezlow < EKRAN_ZDJECIA.progWezlow) {
      return `ekran „${EKRAN_ZDJECIA.nazwa}" (${EKRAN_ZDJECIA.sciezka}) ma ${stanEkranu.wezlow} `
        + `węzłów w <main>, a stan „to jest Twoje zdjęcie" ma ich co najmniej `
        + `${EKRAN_ZDJECIA.progWezlow}. Na ekranie stoi mniej, niż powinno — sprawdź, `
        + 'co zniknęło, zanim uwierzysz w „✓".';
    }

    return null;
  } finally {
    await kontekst.close();
  }
}

log('Zdjęcie profilowe — co widzi automat:');

const przeszkodaZdjecia = await przeszkodaNaEkranieZdjecia(przegladarka, adres, stanZalogowany);

if (przeszkodaZdjecia !== null) {
  console.error(`BŁĄD: ${przeszkodaZdjecia}`);
  zamknij();
  process.exit(1);
}

log('');

/*
 * KTÓRE EKRANY NAPRAWDĘ ZOSTAŁY ZBADANE — A NIE KTÓRE ZADEKLAROWALIŚMY
 * (issue #294).
 *
 * Pułapka, którą ten plik opisuje w kilku miejscach, ma jeszcze jedną,
 * najgorszą wersję: automat, który nie zbadał ŻADNEGO ekranu z całej nowej
 * grupy, i wyszedł z kodem 0. Wystarczy, żeby wejście do panelu przestało
 * działać (2FA, zmiana roli w seederze, przeniesiona trasa) — każdy ekran
 * panelu wypada wtedy na `continue`, a raport pokazuje same ✓ z pozostałych
 * ekranów i wygląda na kompletny.
 *
 * Dlatego zbieramy nazwy ekranów, na których pomiar SIĘ ODBYŁ, i na końcu
 * porównujemy je z listą zadeklarowaną. Ekran zadeklarowany i niezmierzony
 * jest błędem — dokładnie tak samo jak naruszenie.
 */
const zbadanePrzezAxe = new Set();
const zmierzoneUkladem = new Set();

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

  /* TRZECI KONTEKST — MODERATOR Z PANELEM (issue #294). Osobny, bo ciasteczko
     sesji `ani` do panelu nie wchodzi (nie ma roli), a moderator ma w menu coś,
     czego nie ma nikt inny: tryb panelu. Jeden kontekst dla obu ról znaczyłby,
     że wszystkie ekrany zalogowanego są mierzone z menu moderatora, czyli nie
     tak, jak widzi je zwykły człowiek. */
  const kontekstModeratoraAxe = await przegladarka.newContext({
    ...ustawienia,
    storageState: stanModeratorem,
  });

  /* CZWARTY KONTEKST — SESJA MIĘDZY HASŁEM A KODEM (#12). Osobny, bo jest to
     jedyny stan, w którym istnieje ekran `/logowanie/kod`: gość dostaje tam
     przekierowanie na `/login`, a zalogowany nie ma już po co tam wracać.
     Powód i granica są przy `stanPrzedKodem2FA()` wyżej. */
  const kontekstPoHasleAxe = await przegladarka.newContext({
    ...ustawienia,
    storageState: stanPoHasle,
  });

  const stronaGoscia = await kontekstGosciaAxe.newPage();
  const stronaZalogowanego = await kontekstZalogowanegoAxe.newPage();
  const stronaModeratora = await kontekstModeratoraAxe.newPage();
  const stronaPoHasle = await kontekstPoHasleAxe.newPage();

  for (const ekran of EKRANY) {
    const strona = ekran.przedKodem2FA
      ? stronaPoHasle
      : (ekran.moderator
        ? stronaModeratora
        : (ekran.zalogowany ? stronaZalogowanego : stronaGoscia));
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

    await poczekajNaFonty(strona);

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

    /*
     * OTWIERAMY KAŻDY `<details>` — I ROBIMY TO PRZED ZMIANĄ MOTYWU I SKALI,
     * A NIE PO NIEJ.
     *
     * PO CO W OGÓLE OTWIERAĆ. Treść zamkniętego `<details>` nie ma
     * `display: none` w arkuszu stylów — ale przeglądarka i tak traktuje ją
     * jak niewidoczną (`checkVisibility()` zwraca `false`), bo tak każe robić
     * specyfikacja HTML z zamkniętym `<details>`. Axe pomija to, co
     * niewidoczne, tak samo jak pomija tekst `sr-only` odwrócony
     * transformacją. Zmierzone wprost na ekranie „twoje dane": axe analizuje
     * 8 węzłów wewnątrz zamkniętego `<details>` (sam `<summary>`), a 34, gdy
     * jest otwarty — różnica to dokładnie hasło, oba haczyki i przycisk
     * „Usuń moje konto" formularza usunięcia konta (D-022). Bez tego
     * otwarcia automat NIGDY nie sprawdziłby etykiet ani kontrastu
     * w najważniejszym nieodwracalnym formularzu serwisu — zielony wynik na
     * tym ekranie nic by nie znaczył.
     *
     * DLACZEGO PRZED, A NIE PO — TO JEST TRZECIE MIGOTANIE KONTRASTU W TYM
     * PLIKU I MA INNĄ PRZYCZYNĘ NIŻ DWA POPRZEDNIE (#454).
     *
     * Objaw: automat meldował „[serious] color-contrast —
     * label[for=\"f-password\"]" na ekranie usuwania konta RAZ NA KILKASET
     * przebiegów, przy nietkniętej palecie. Zmierzone liczby z takiego
     * przebiegu (wariant „ciemny", 1280 px):
     *
     *     tekst  #2b241d   ← `--color-ink` z motywu JASNEGO
     *     tło    #1e1a16   ← `--color-surface` z motywu CIEMNEGO
     *     kontrast 1.13:1  przy wymaganym 4.5:1
     *
     * Te dwie wartości nie występują razem w żadnym motywie: pomiar
     * zestawiał tekst sprzed przełączenia z tłem po przełączeniu. Tak samo
     * wyglądały trzy pozostałe węzły tego naruszenia (`.meta`,
     * `#f-password-help`, akapit `.mt-3`) — WSZYSTKIE wewnątrz `<details>`
     * i ani jeden poza nim.
     *
     * Przyczyna: zamknięty `<details>` jest w Chromium poddrzewem
     * pominiętym w przeliczaniu stylu. Zmiana `data-theme` na `<html>`
     * NIE przelicza go — przeliczy się dopiero przy pierwszym pełnym obiegu
     * klatki po otwarciu. `getComputedStyle` tego nie wymusza, więc dopóki
     * otwarcie stało tu, tuż przed `analyze()`, axe potrafił przeczytać
     * z tego poddrzewa kolory sprzed przełączenia motywu.
     *
     * DOWÓD, nie uzasadnienie — trzy pomiary na tym ekranie:
     *
     *   A. otwarcie i odczyt w JEDNYM zadaniu (klatka nie ma jak wejść
     *      pomiędzy): `getComputedStyle(label).color` = `rgb(43, 36, 29)`,
     *      czyli #2b241d, przy `body` już ciemnym — 100 razy na 100.
     *      Po dwóch obiegach klatki ta sama etykieta ma `rgb(245, 239, 230)`,
     *      czyli poprawne #f5efe6.
     *   B. ten sam odczyt, ale `<details>` otwarty PRZED włączeniem motywu:
     *      `rgb(245, 239, 230)` od razu — poddrzewo bierze udział w zwykłym
     *      przeliczeniu i nie ma czemu być nieaktualnym.
     *   C. motyw jasny, ta sama kolejność co w A: żadnego rozjazdu, bo nie
     *      ma zmiany, którą można by przegapić.
     *
     * Czyli winna jest KOLEJNOŚĆ, nie paleta i nie szybkość maszyny.
     * Dlatego otwarcie stoi teraz przed `data-text-scale` i `data-theme`:
     * gdy te atrybuty się zmieniają, całe drzewo jest już widoczne i
     * przelicza się razem. To samo chroni pomiar wielkości pisma w wariancie
     * „tekst 140%" — nieaktualna wielkość przestawiałaby próg kontrastu
     * z 4.5:1 na 3:1 i tym razem CICHO PRZEPUSZCZAŁA naruszenie.
     *
     * Dwa obiegi klatki po otwarciu: pierwszy kończy przeliczanie stylu
     * poddrzewa, drugi daje pewność, że przemalowanie już się odbyło — ten
     * sam mechanizm i ten sam powód co po zmianie motywu niżej.
     */
    await strona.evaluate(() => {
      for (const el of document.querySelectorAll('details:not([open])')) {
        el.open = true;
      }
    });

    await strona.evaluate(() => new Promise((gotowe) => {
      requestAnimationFrame(() => requestAnimationFrame(() => gotowe(null)));
    }));

    if (wariant.skalaTekstu) {
      // Czekamy na PRZELICZONY układ, nie na sam atrybut — uzasadnienie
      // stoi przy `wlaczSkaleTekstu` wyżej.
      const przeliczone = await wlaczSkaleTekstu(
        strona,
        wariant.skalaTekstu,
        `ekran „${ekran.nazwa}" (${wariant.nazwa})`,
      );

      // Strona jest tu WSPÓLNA dla wszystkich ekranów wariantu (dwie na cały
      // przebieg — gościa i zalogowanego), więc jej NIE zamykamy: zamknięcie
      // zabrałoby resztę listy razem z tym jednym ekranem.
      if (! przeliczone) {
        continue;
      }
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


    // Zapisujemy ten sam wyrenderowany ekran, który bada axe.
    // Artefakt pozwala odebrać wizualnie wszystkie rodziny, także panel i błędy.
    if (wariant.nazwa === 'jasny' || wariant.nazwa === '320 px') {
      mkdirSync('storage/port-projektu/ekrany', { recursive: true });
      const nazwaPliku = String(EKRANY.indexOf(ekran)).padStart(2, '0') + '-' + wariant.szerokosc;
      const zrzut = await strona.screenshot({
        path: 'storage/port-projektu/ekrany/' + nazwaPliku + '.jpg',
        type: 'jpeg', quality: 60,
      });
      if (wariant.nazwa === '320 px' && ['strona powitalna', 'przepis', 'dodaj przepis', 'logowanie', 'czytelność'].includes(ekran.nazwa)) {
        log('PORT_SCREEN_' + nazwaPliku + ' ' + zrzut.toString('base64'));
      }
    }
    const ile = wynik.violations.length;
    zbadanePrzezAxe.add(ekran.nazwa);
    log(`  ${ile === 0 ? '✓' : '✗'} ${ekran.nazwa} (${wariant.nazwa})${ile ? ` — ${ile}` : ''}`);
  }

  await kontekstGosciaAxe.close();
  await kontekstZalogowanegoAxe.close();
  await kontekstModeratoraAxe.close();
  await kontekstPoHasleAxe.close();
}

/* Ekran zadeklarowany, a nigdy niezbadany — patrz komentarz przy
   `zbadanePrzezAxe`. Nazwy wypisujemy wszystkie: „3 ekrany" nie mówi, których
   szukać. */
const pominietePrzezAxe = EKRANY.filter((e) => ! zbadanePrzezAxe.has(e.nazwa));

if (pominietePrzezAxe.length > 0) {
  console.error(
    `BŁĄD: axe nie zbadał ${pominietePrzezAxe.length} z ${EKRANY.length} zadeklarowanych ekranów: `
    + pominietePrzezAxe.map((e) => `„${e.nazwa}"`).join(', ')
    + '. Raport BEZ tego sprawdzenia wyglądałby na kompletny.',
  );
  process.exitCode = 1;
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
    // Trzeci kontekst — panel moderacji (issue #294). Powód osobnego stoi
    // przy `kontekstModeratoraAxe` wyżej.
    const kontekstModeratora = await przegladarka.newContext({
      viewport: { width: szerokosc, height: 740 },
      storageState: stanModeratorem,
    });
    // Czwarty kontekst — sesja między hasłem a kodem (#12). Powód osobnego
    // stoi przy `kontekstPoHasleAxe` wyżej i przy `stanPrzedKodem2FA()`.
    const kontekstPoHasle = await przegladarka.newContext({
      viewport: { width: szerokosc, height: 740 },
      storageState: stanPoHasle,
    });

    let zlych = 0;

    for (const ekran of EKRANY_UKLADU) {
      const sciezka = sciezkaEkranu(ekran);

      if (! sciezka) {
        console.error(`BŁĄD: brak adresu dla ekranu „${ekran.nazwa}".`);
        process.exitCode = 1;
        continue;
      }

      const kontekst = ekran.przedKodem2FA
        ? kontekstPoHasle
        : (ekran.moderator
          ? kontekstModeratora
          : (ekran.zalogowany ? kontekstZalogowanego : kontekstGoscia));
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
      await poczekajNaFonty(strona);

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
        const przeliczone = await wlaczSkaleTekstu(
          strona,
          skala,
          `ekran „${ekran.nazwa}" (${opis}, pomiar układu)`,
        );

        if (! przeliczone) {
          await strona.close();
          continue;
        }
      }

      const uklad = await zmierzUklad(strona);
      await strona.close();
      zmierzoneUkladem.add(ekran.nazwa);

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
    await kontekstModeratora.close();
    await kontekstPoHasle.close();

    log(`  ${zlych === 0 ? '✓' : '✗'} ${opis}${zlych ? ` — ${zlych} z ${EKRANY_UKLADU.length} ekranów` : ''}`);
  }
}

/* Tak samo jak przy axe wyżej: ekran zadeklarowany i ani razu niezmierzony
   jest BŁĘDEM. Bez tego wiersz „✓ 900 px" znaczyłby „żaden z zadeklarowanych
   ekranów panelu nie dał się otworzyć, a pozostałe są w porządku" — i nikt by
   tego nie odróżnił od „wszystko zmierzone i czyste". */
const pominieteWUkladzie = EKRANY_UKLADU.filter((e) => ! zmierzoneUkladem.has(e.nazwa));

if (pominieteWUkladzie.length > 0) {
  console.error(
    `BŁĄD: pomiar układu nie objął ${pominieteWUkladzie.length} z ${EKRANY_UKLADU.length} `
    + 'zadeklarowanych ekranów: '
    + pominieteWUkladzie.map((e) => `„${e.nazwa}"`).join(', ')
    + '. Przewijanie w bok na tych ekranach nie zostało sprawdzone w ogóle.',
  );
  process.exitCode = 1;
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

const karuzelaBezJs = { wyniki: [], blad: null };
try {
  await zmierzKaruzele({
    browser: przegladarka, adres, path: mieszanaKaruzela.path, executablePath: CHROMIUM,
    wyniki: karuzelaBezJs.wyniki,
  });
  const wynikiKaruzeli = karuzelaBezJs.wyniki;
  if (wynikiKaruzeli.length !== 8 || wynikiKaruzeli.some(w => w.status !== 'ok')) {
    throw new Error('Karuzela431: niepełny raport pomiaru');
  }
  log(`  ✓ mieszana próbka: ${wynikiKaruzeli.length} wariantów, kliknięcia i Tab/Enter, ramka D-191, kontrolki 48px`);
} catch (error) {
  karuzelaBezJs.blad = error?.message ?? String(error);
  console.error(error);
  process.exitCode = 1;
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

  // Bez JS DOMContentLoaded nie czeka na CSS. Opóźnienie arkusza odtwarza
  // wyścig z CI; Tab wolno zacząć dopiero po załadowaniu dokumentu i zasobów.
  const oczekiwaniaNaCss = [];
  await kontekst.route('**/build/assets/*.css', (route) => {
    const oczekiwanie = new Promise((resolve) => setTimeout(resolve, 3000))
      .then(() => route.continue());
    oczekiwaniaNaCss.push(oczekiwanie);
    return oczekiwanie;
  });
  const strona = await kontekst.newPage();
  const odpowiedz = await strona.goto(`${adres}/dodaj/zdjecie`, { waitUntil: 'load' });
  const kod = odpowiedz?.status() ?? 0;

  await poczekajNaFonty(strona);

  // Ten sam powód co przy pomiarze układu: strona błędu nie ma pola wyboru
  // zdjęcia, więc bez tego sprawdzenia przebieg zgłaszałby „nie znalazłem"
  // zamiast „nie byłem na właściwej stronie".
  if (kod !== 200) {
    console.error(`BŁĄD: „/dodaj/zdjecie" odpowiedziało kodem ${kod} — wybór zdjęcia nie został sprawdzony.`);
    process.exitCode = 1;
    await Promise.all(oczekiwaniaNaCss);
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

  await Promise.all(oczekiwaniaNaCss);
  await kontekst.close();

  return {
    opoznionychArkuszy: oczekiwaniaNaCss.length,
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
  const dobrze = wyborZdjeciaBezJs.opoznionychArkuszy > 0
    && wyborZdjeciaBezJs.doszloTabem
    && wyborZdjeciaBezJs.zostajeWDrzewie
    && wyborZdjeciaBezJs.jednaEtykieta
    && wyborZdjeciaBezJs.widocznyFokusNaObszarze;

  log(`  ${dobrze ? '✓' : '✗'} Tab dochodzi do pola po ${wyborZdjeciaBezJs.krokowTabem} krokach`
    + ` (${wyborZdjeciaBezJs.doszloTabem ? 'tak' : 'NIE'})`
    + `, opóźnionych arkuszy CSS: ${wyborZdjeciaBezJs.opoznionychArkuszy}`
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
  // Gość NA EKRANIE Z SZYNĄ (D-122). Od 80rem jego siatka jest szersza niż
  // na ekranie bez szyny, a belka i stopka biorą tę szerokość z osobnej
  // reguły (`.uklad-solo-z-szyna`) — czyli z drugiego miejsca, które może
  // zostać w tyle. Ten wiersz pilnuje, żeby oba miejsca mówiły tę samą liczbę.
  { nazwa: 'napisz do nas (gość, z szyną)', adres: '/napisz-do-nas' },
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


/* Nowy projekt ma niezależną, szerszą belkę. Sprawdzamy jej geometrię,
   padding i zawartość oraz wspólną ramę stron — z tolerancją 1 px. */
function zmierzRameMarki() {
  if (!document.body.hasAttribute('data-marka')) return null;
  const bledy = [];
  const near = (nazwa, actual, expected) => {
    if (Math.abs(actual - expected) > 1) bledy.push(nazwa + ': ' + actual + ' zamiast ' + expected);
  };
  const belka = document.querySelector('.marka-topbar');
  const wnetrze = document.querySelector('.topbar-inner');
  const rama = document.querySelector('.marka-rama');
  const stopka = document.querySelector('.site-footer-inner');
  const logo = document.querySelector('.wordmark');
  const akcje = document.querySelector('.topbar-actions');
  if (![belka, wnetrze, rama, stopka, logo, akcje].every(Boolean)) {
    return { marka: true, bledy: ['Brak elementu ramy marki'], oczekiwanaLewa: null, oczekiwanaPrawa: null };
  }
  const b = belka.getBoundingClientRect();
  const w = wnetrze.getBoundingClientRect();
  const r = rama.getBoundingClientRect();
  const f = stopka.getBoundingClientRect();
  const ws = getComputedStyle(wnetrze);
  const fs = getComputedStyle(stopka);
  near('szerokość belki', b.width, Math.min(1220, innerWidth - 24));
  near('wyśrodkowanie belki', (b.left + b.right) / 2, innerWidth / 2);
  near('padding belki lewy', parseFloat(ws.paddingLeft), 20);
  near('padding belki prawy', parseFloat(ws.paddingRight), 20);
  near('lewa krawędź logo', logo.getBoundingClientRect().left, w.left + 20);
  near('prawa krawędź akcji', akcje.getBoundingClientRect().right, w.right - 20);
  near('szerokość ramy', r.width, Math.min(1120, innerWidth - 24));
  near('wyśrodkowanie ramy', (r.left + r.right) / 2, innerWidth / 2);
  near('szerokość stopki', f.width, Math.min(1120, innerWidth - 32));
  near('wyśrodkowanie stopki', (f.left + f.right) / 2, innerWidth / 2);
  near('padding stopki lewy', parseFloat(fs.paddingLeft), 24);
  near('padding stopki prawy', parseFloat(fs.paddingRight), 24);
  for (const element of [...wnetrze.children, ...wnetrze.querySelectorAll('.marka-nawigacja > a')]) {
    const e = element.getBoundingClientRect();
    if (!e.width || !e.height || getComputedStyle(element).visibility === 'hidden') continue;
    if (e.left < w.left + 19 || e.right > w.right - 19) bledy.push('Zawartość wystaje z belki: ' + element.className);
  }
  return { marka: true, bledy, oczekiwanaLewa: r.left, oczekiwanaPrawa: r.right };
}
let sprawdzonoUjemnieRameMarki = false;

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

    await poczekajNaFonty(strona);

    let pomiar = await strona.evaluate(() => {
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


    const marka = await strona.evaluate(zmierzRameMarki);
    if (marka) {
      pomiar = marka;
      if (!sprawdzonoUjemnieRameMarki) {
        for (const [css, oczekiwanyBlad] of [
          ['[data-marka] .marka-topbar { width: 80px !important; }', 'szerokość belki'],
          ['[data-marka] .marka-topbar { transform: translateX(20px) !important; }', 'wyśrodkowanie belki'],
          ['[data-marka] .topbar-inner { padding-left: 0 !important; }', 'padding belki lewy'],
          ['[data-marka] .site-footer-inner { width: 80px !important; }', 'szerokość stopki'],
        ]) {
          const styl = await strona.evaluateHandle((tresc) => {
            const nonce = document.querySelector('script[nonce], style[nonce]')?.nonce;
            if (!nonce) throw new Error('Brak nonce do kontroli ujemnej CSS');
            const element = document.createElement('style');
            element.nonce = nonce;
            element.textContent = tresc;
            document.head.append(element);
            return element;
          }, css);
          try {
            const ujemny = await strona.evaluate(zmierzRameMarki);
            if (!ujemny.bledy.some((blad) => blad.startsWith(oczekiwanyBlad))) {
              throw new Error('Kontrola ujemna ramy nie wykryła: ' + oczekiwanyBlad);
            }
            log('  Kontrola ujemna ramy: wykryto ' + oczekiwanyBlad);
          } finally {
            await styl.evaluate((element) => element.remove());
          }
        }
        sprawdzonoUjemnieRameMarki = true;
        pomiar = await strona.evaluate(zmierzRameMarki);
      }
    }
    await strona.close();

    if (! pomiar) {
      console.error(`BŁĄD: na ekranie „${ekran.nazwa}" brakuje kolumny treści (.pas-wnetrze albo .app-body), .wordmark albo .topbar-actions.`);
      process.exitCode = 1;
      continue;
    }

    if (pomiar.marka) {
      if (pomiar.bledy.length) rozjazdyBelki.push({ ekran: ekran.nazwa, szerokosc, ...pomiar });
    } else {
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

/* =============================================================================
   FOCUS NIE ZASŁONIĘTY PRZEZ NAKŁADKĘ (WCAG 2.2 AA — 2.4.11
   Focus Not Obscured (Minimum))

   Zewnętrzny audyt (docs/research/AUDYT_60_PLUS.md, ranking napraw pkt 1 —
   najmocniejsza rekomendacja) wskazał lukę: `.bottom-nav` jest
   `position: fixed` i ma `flex-wrap: wrap` (patrz komentarz przy tej klasie
   wyżej w `app.css`) — przy dużym tekście belka może urosnąć do dwóch albo
   trzech wierszy. Rezerwa na końcu dokumentu (dolne wypełnienie
   `.site-footer`) była jednak STAŁĄ wartością `--spacing-20` (80 px), nie
   wynikającą z rzeczywistej wysokości belki. Gdy belka urośnie powyżej tej
   rezerwy, treść przewija się POD nią — a fokus klawiaturowy, który
   przeglądarka sama przewija w widok, może wylądować w całości za paskiem.

   CO TO SPRAWDZENIE ZASTAŁO, A CO PILNUJE PO POPRAWCE. Zmierzone tym
   skryptem, ekrany niżej, warianty niżej:

       przed poprawką   242 kontrolki zasłonięte w 100% (1 pod `.bottom-nav`,
                        241 pod `.topbar` przy czcionce przeglądarki 200%)
       po poprawce      0

   Obie belki zostają w tym pomiarze BLOKUJĄCE — także `.topbar`, bo została
   naprawiona razem z dolną, a nie odłożona. Naprawy są dwie i obie stoją
   w `app.css`: rezerwa `--rezerwa-pod-belka` policzona pod zmierzone
   wysokości dolnej belki oraz odpięcie `.topbar` przy `max-width: 15rem`,
   czyli tam, gdzie przypięty pasek zabierałby ponad trzecią część ekranu.
   Uzasadnienia obu (z liczbami) stoją przy tych regułach.

   DLACZEGO ELEMENTFROMPOINT, A NIE SAMO PRZECIĘCIE PROSTOKĄTÓW
   Sam przecinający się prostokąt nie znaczy "zasłonięty": `.skip-link` ma
   `z-index: 50`, wyżej niż `.topbar` (20) i `.bottom-nav` (30), więc po
   skupieniu na nim geometrycznie leży nad topbarem, ale WIDAĆ go, bo
   przeglądarka maluje go na wierzchu. Prosta matematyka prostokątów
   zgłosiłaby to jako FAIL, którego naprawdę nie ma — a to jest dokładnie
   fałszywy alarm, przed którym ostrzega zlecenie audytu. Próbkujemy więc
   siatkę punktów wewnątrz prostokąta fokusu przez `elementFromPoint` —
   to jest pytanie "co PRZEGLĄDARKA NAPRAWDĘ renderuje w tym miejscu", a nie
   "czy dwa prostokąty się nakładają na papierze". Stan uwzględnia więc
   z-index, zaokrąglone rogi i wszystko inne, co realnie wpływa na to, co
   widzi człowiek.

   FAIL DOPIERO PRZY PEŁNYM (100%) POKRYCIU — to jest dosłowne minimum AA
   z Understanding 2.4.11 ("not entirely hidden"). Częściowe pokrycie to
   osobne, PRODUKTOWE ostrzeżenie: sygnał do poprawy, ale nie naruszenie
   WCAG i nie powód, żeby ten skrypt oblewał przebieg.

   Kontrolki, które są potomkami samej belki (linki nawigacji, pole
   wyszukiwania w topbarze), są z tej reguły wyłączone: to one SĄ tą belką,
   nie treścią, którą belka miałaby zasłaniać.

   DOSŁOWNY KONTRAKT Z AUDYTU (§„Co dopisać do scripts/dostepnosc.mjs", pkt 1)
   Ekrany: `/home`, `/szukaj`, przykładowy wpis, `/ustawienia/profil`.
   Szerokości: 320/360/414 px. Skale: te same trzy co w pomiarze układu
   wyżej (`SKALE_UKLADU` — bez skali, tekst 140%, czcionka przeglądarki
   200%), tym samym mechanizmem (CDP `Page.setFontSizes` dla przeglądarki,
   atrybut `data-text-scale` dla naszego ustawienia).

   „Przykładowy wpis" mierzymy jako ZALOGOWANY, nie gość: `.bottom-nav`
   istnieje wyłącznie w layoucie zalogowanego (`@auth` w `layout.blade.php`),
   a to właśnie ta belka jest ryzykiem — mierzenie wpisu jako gość
   nigdy nie mogłoby złapać naruszenia, którego to sprawdzenie szuka.

   JAK PRZECHODZIMY WSZYSTKIE WIDOCZNE KONTROLKI
   Naciskamy Tab, aż `document.activeElement` wróci do `<body>` (naturalny
   koniec kolejności tabulacji w przeglądarce bez paska adresu) albo trafi
   na element już odwiedzony w tym przebiegu (pętla fokusu, np. pułapka
   modala). Odwiedzone elementy znaczymy tymczasowym atrybutem danych, żeby
   rozpoznać powrót bez porównywania referencji przez granicę `evaluate`.
   Limit kroków jest bezpiecznikiem, nie oczekiwaną wartością — jego
   wyczerpanie bez naturalnego końca jest BŁĘDEM (niepełne sprawdzenie),
   nie cichym zaliczeniem ekranu.
   ========================================================================== */
log('');
log('Focus Not Obscured (WCAG 2.2 2.4.11):');

const EKRANY_FOCUS = [
  { nazwa: 'tablica', adres: '/home', zalogowany: true },
  { nazwa: 'szukaj', adres: '/szukaj?q=rosol', zalogowany: true },
  { nazwa: 'wpis (przykładowy)', adres: null, znajdz: 'wpis:normal', zalogowany: true },
  /*
   * KARTA WPISU AUTORA O STUZNAKOWEJ NAZWIE — PRZYPIĘTA JAWNIE (#440).
   *
   * Pozycja wyżej bierze „jakiś wpis w trybie zwykłym" i dopóki tym wpisem
   * był akurat wpis Zofii, ta próbka pilnowała najtrudniejszego wariantu
   * przez przypadek. Zmierzone przy #440, dojściem Tabem, 320 px / tekst 140%:
   *
   *     autor „Basia" (5 znaków)   menu „Więcej" na 314 px     0% zakryte
   *     autor o 100 znakach        menu „Więcej" na   1 px   100% ZAKRYTE
   *
   * Różnicę robi wyłącznie długość nazwy autora, więc ta karta musi wchodzić
   * do pomiaru z NAZWY, a nie z kolejności wierszy. Uzasadnienie i sposób
   * wyboru: `wpisDlugiejNazwy` wyżej w tym pliku.
   */
  { nazwa: 'wpis (autor o najdłuższej dopuszczalnej nazwie)', adres: null, znajdz: 'wpis-dluga-nazwa', zalogowany: true },
  { nazwa: 'ustawienia profilu', adres: '/ustawienia/profil', zalogowany: true },
];

const SZEROKOSCI_FOCUS = SZYBKO ? [320] : [320, 360, 414];

// Bezpiecznik, nie oczekiwana wartość — patrz komentarz wyżej.
const MAKS_KROKOW_FOCUS = 400;

// Próg PEŁNEGO pokrycia. Rzadko wychodzi dokładnie 1 przez zaokrąglenia
// próbek na krawędzi elementu, dlatego liczy się już "prawie wszystkie".
const PROG_CALKOWITEGO_PRZYKRYCIA = 0.99;

const naruszeniaFocus = [];
const ostrzezeniaFocus = [];

/* =============================================================================
   ILE EKRANU ZABIERA DOLNA BELKA (issue #295)

   DLACZEGO TO JEST OSOBNA LICZBA, A NIE SKUTEK UBOCZNY POMIARU FOKUSU
   Sprawdzenie „focus not obscured" wyżej pilnuje tego, GDZIE LĄDUJE FOKUS,
   i po rezerwie `--rezerwa-pod-belka` (PR #269) jest zielone także wtedy, gdy
   przypięta belka zajmuje połowę telefonu: treść da się wyprowadzić spod niej
   przewijaniem, więc kryterium 2.4.11 jest spełnione. Zmierzone przed
   poprawką issue #295 na `/home`, okno 320 × 740 px, czcionka przeglądarki
   200%: belka 376,2 px, czyli 51% ekranu — i ani jednego naruszenia.

   Czyli: usterka, której ŻADEN pomiar w tym pliku nie umiał zobaczyć, bo
   wszystkie pytały o naruszenia WCAG, a to jest usterka produktowa —
   nawigacja większa niż treść. Stąd ta liczba: wysokość belki dzielona przez
   wysokość okna, w tym samym stanie przeglądarki, w którym mierzymy fokus.

   PRÓG 1/3 OKNA. Zmierzone po poprawce, trzy szerokości i trzy skale: 9%
   bez powiększania, 14,3% przy naszym „tekst 140%", 24,5% przy czcionce
   przeglądarki 200%. Próg 33,3% leży nad najgorszym zmierzonym wynikiem
   z zapasem 65 px na dalszy wzrost tekstu i grubo pod stanem sprzed
   poprawki (50,8%), więc oblewa dokładnie wtedy, gdy belka wraca do
   zabierania ekranu. To NIE jest tolerancja dla naruszenia 2.4.11 — tamto
   ma swój własny, twardy próg wyżej i ten pomiar go nie dotyka.

   POZYCJI MUSI BYĆ PIĘĆ — to jest kontrola dodatnia wpisana w pomiar.
   Najprostszy sposób obniżenia belki to wyrzucenie z niej pozycji, a wtedy
   liczba wyżej spadnie i wyglądałoby to na naprawę. Nawigacja mobilna ma
   pięć pozycji (`AGENTS.md` §5) i ten pomiar tego pilnuje, więc „naprawa"
   przez okrojenie nawigacji oblewa przebieg zamiast go zazielenić.
   ========================================================================== */
const UDZIAL_BELKI_MAKS = 1 / 3;
const POZYCJI_W_BELCE = 5;
const wysokosciBelki = [];
const zaWysokaBelka = [];

/**
 * Wysokość dolnej belki i jej udział w oknie. `null` znaczy „nie ma takiego
 * elementu" i jest BŁĘDEM u zalogowanego — nie cichym pominięciem.
 */
async function zmierzBelke(strona) {
  return strona.evaluate(() => {
    const belka = document.querySelector('.bottom-nav');

    if (! belka) return null;

    const styl = getComputedStyle(belka);

    if (styl.display === 'none' || styl.visibility === 'hidden') {
      return { ukryta: true };
    }

    const ramka = belka.getBoundingClientRect();
    const pozycje = [...belka.querySelectorAll('.bottom-nav-item')];

    return {
      wysokosc: Math.round(ramka.height * 10) / 10,
      okno: innerHeight,
      udzial: ramka.height / innerHeight,
      pozycji: pozycje.length,
      // Ile WIERSZY zajmują pozycje — po unikalnych współrzędnych górnej
      // krawędzi. To ta liczba tłumaczy wysokość: 51% ekranu brało się
      // z trzech wierszy po 120 px, nie z wielkości pisma.
      wierszy: new Set(pozycje.map((p) => Math.round(p.getBoundingClientRect().top))).size,
      pismoPodpisu: pozycje[0]
        ? Number.parseFloat(getComputedStyle(pozycje[0]).fontSize)
        : null,
    };
  });
}

/**
 * Naciska Tab aż do naturalnego końca albo pętli i dla każdej odwiedzonej
 * kontrolki mierzy realne pokrycie przez `.topbar` i `.bottom-nav`
 * (0 = w ogóle niezasłonięta, 1 = w całości zasłonięta, `null` = na tym
 * ekranie nie ma takiej nakładki albo jest ukryta).
 */
async function przejdzTabemIZmierzFocus(strona) {
  const kroki = [];

  for (let krok = 0; krok < MAKS_KROKOW_FOCUS; krok++) {
    await strona.keyboard.press('Tab');

    const stan = await strona.evaluate(() => {
      const el = document.activeElement;

      if (!el || el === document.body || el === document.documentElement) {
        return { koniec: true };
      }

      if (el.dataset.a11yFokusWidziany === '1') {
        return { petla: true };
      }
      el.dataset.a11yFokusWidziany = '1';

      const ramka = el.getBoundingClientRect();

      function pokrycieNakladki(selektor) {
        const nakladka = document.querySelector(selektor);
        if (!nakladka) return null;

        const styl = getComputedStyle(nakladka);
        if (styl.display === 'none' || styl.visibility === 'hidden') return null;

        // Kontrolka jest częścią samej belki — to nie jest "zasłonięcie
        // treści", to sama belka.
        if (nakladka.contains(el)) return 0;

        if (ramka.width === 0 || ramka.height === 0) return 0;

        /*
         * SIATKĘ ROZKŁADAMY NA CZĘŚCI WIDOCZNEJ, NIE NA CAŁYM PROSTOKĄCIE.
         *
         * Pierwsza wersja rozkładała 4×4 punkty na całej ramce kontrolki
         * i POMIJAŁA te, które wypadły poza okno. Przy kontrolce WYŻSZEJ
         * NIŻ OKNO zostawał z tego jeden rząd próbek w zupełnie przypadkowym
         * miejscu. Zmierzone na `/home` przy 320 px i czcionce przeglądarki
         * 200%: odnośnik „Dodaj zdjęcie tego dnia" ma tam 2087,1 px wysokości
         * (ramka od -882 do 1205,1 px), a z czterech rzędów siatki w oknie
         * leżał JEDEN — ten na wysokości 422,4 px, czyli wewnątrz dolnej
         * belki. Wynik: „zasłonięte w 100%" dla kontrolki, której 364 px
         * widać nad belką jak na dłoni. To jest FAŁSZYWY ALARM, i to
         * dokładnie tej klasy, przed którą ostrzega zlecenie audytu:
         * kryterium 2.4.11 mówi „not entirely hidden", a kontrolka wyższa
         * od okna nie może być schowana w całości pod belką, która zajmuje
         * część okna.
         *
         * Przycięcie ramki do okna PRZED rozłożeniem siatki daje próbki
         * reprezentatywne dla tego, co człowiek naprawdę widzi, niezależnie
         * od wysokości kontrolki. Część poza oknem to inny problem
         * (przewinięcie poza widok) i pozostaje poza zakresem tego
         * sprawdzenia — tak jak dotąd.
         */
        const lewa = Math.max(ramka.left, 0);
        const gora = Math.max(ramka.top, 0);
        const prawa = Math.min(ramka.right, innerWidth);
        const dol = Math.min(ramka.bottom, innerHeight);

        // Kontrolka w całości poza oknem — nie ma czego mierzyć.
        if (prawa <= lewa || dol <= gora) return null;

        // Siatka 4×4: dość gęsto, żeby złapać częściowe pokrycie, dość
        // rzadko, żeby nie mnożyć kosztu `elementFromPoint` na setkach
        // kroków Taba.
        const SIATKA = 4;
        let zaslonietych = 0;

        for (let iy = 0; iy < SIATKA; iy++) {
          for (let ix = 0; ix < SIATKA; ix++) {
            const x = lewa + ((prawa - lewa) * (ix + 0.5)) / SIATKA;
            const y = gora + ((dol - gora) * (iy + 0.5)) / SIATKA;

            const trafiony = document.elementFromPoint(x, y);

            if (trafiony && nakladka.contains(trafiony) && !el.contains(trafiony)) {
              zaslonietych++;
            }
          }
        }

        // Wszystkie próbki leżą teraz w oknie, więc mianownik jest stały —
        // licznik odrzuconych punktów, który stał tu wcześniej, nie miałby
        // już czego liczyć.
        return zaslonietych / (SIATKA * SIATKA);
      }

      const prostokat = (element) => element ? element.getBoundingClientRect().toJSON() : null;
      const geometria = {
        klasa: el.className,
        rodzic: el.parentElement?.className,
        ramka: prostokat(el),
        topbar: prostokat(document.querySelector('.topbar')),
        bottomNav: prostokat(document.querySelector('.bottom-nav')),
        scrollPaddingTop: getComputedStyle(document.documentElement).scrollPaddingTop,
        scrollPaddingBottom: getComputedStyle(document.documentElement).scrollPaddingBottom,
      };

      const opis = `${el.tagName.toLowerCase()}`
        + (el.id ? `#${el.id}` : '')
        + (el.getAttribute('aria-label') ? ` [aria-label="${el.getAttribute('aria-label')}"]` : '')
        + (el.textContent?.trim() ? ` „${el.textContent.trim().slice(0, 40)}"` : '');

      return {
        opis,
        geometria,
        pokrycieTopbar: pokrycieNakladki('.topbar'),
        pokrycieBottomNav: pokrycieNakladki('.bottom-nav'),
      };
    });

    if (stan.koniec || stan.petla) {
      return { kroki, pelnyPrzebieg: true };
    }

    kroki.push(stan);
  }

  return { kroki, pelnyPrzebieg: false };
}

for (const szerokosc of SZEROKOSCI_FOCUS) {
  for (const skala of SKALE_UKLADU) {
    const opis = `${szerokosc} px${etykietaSkali(skala)}`;

    const ustawieniaFocus = {
      viewport: { width: szerokosc, height: 740 },
      // Ten sam powód co przy motywie ciemnym wyżej: `.skip-link` ma
      // `transition: top 120ms`, a pomiar tuż po naciśnięciu Tab złapałby
      // ją w połowie ruchu, dając niestabilny (raz taki, raz inny) wynik
      // pokrycia zamiast stanu końcowego.
      reducedMotion: 'reduce',
    };

    const kontekstGosciaFocus = await przegladarka.newContext(ustawieniaFocus);
    const kontekstZalogowanegoFocus = await przegladarka.newContext({
      ...ustawieniaFocus,
      storageState: stanZalogowany,
    });

    let zlych = 0;

    for (const ekran of EKRANY_FOCUS) {
      const sciezka = sciezkaEkranu(ekran);

      if (! sciezka) {
        console.error(`BŁĄD: brak adresu dla ekranu „${ekran.nazwa}" (focus not obscured).`);
        process.exitCode = 1;
        continue;
      }

      const kontekst = ekran.zalogowany ? kontekstZalogowanegoFocus : kontekstGosciaFocus;
      const strona = await kontekst.newPage();

      if (skala === PRZEGLADARKA_200) {
        // PRZED nawigacją — patrz uzasadnienie przy identycznym bloku
        // w pomiarze układu wyżej.
        const cdp = await kontekst.newCDPSession(strona);

        await cdp.send('Page.setFontSizes', {
          fontSizes: { standard: 2 * BAZOWA_CZCIONKA_PX, fixed: 2 * BAZOWA_CZCIONKA_PX },
        });
      }

      const odpowiedzFocus = await strona.goto(
        sciezka.startsWith('http') ? sciezka : `${adres}${sciezka}`,
        { waitUntil: 'domcontentloaded' },
      );
      await poczekajNaFonty(strona);

      const kodFocus = odpowiedzFocus?.status() ?? 0;

      if (kodFocus !== 200) {
        console.error(
          `BŁĄD: ekran „${ekran.nazwa}" (${sciezka}) odpowiedział kodem ${kodFocus} `
          + 'przy pomiarze focus not obscured.',
        );
        process.exitCode = 1;
        await strona.close();
        continue;
      }

      if (skala && skala !== PRZEGLADARKA_200) {
        const przeliczone = await wlaczSkaleTekstu(
          strona,
          skala,
          `ekran „${ekran.nazwa}" (${opis}, focus not obscured)`,
        );

        if (! przeliczone) {
          await strona.close();
          continue;
        }
      }

      if (skala === PRZEGLADARKA_200) {
        // Ta sama kontrola metody co w pomiarze układu wyżej — bez niej
        // wariant potrafi przejść na zielono, nie zmierzywszy niczego.
        const stanCzcionki = await strona.evaluate(
          () => Number.parseFloat(getComputedStyle(document.documentElement).fontSize),
        );

        if (stanCzcionki < 2 * BAZOWA_CZCIONKA_PX) {
          console.error(
            `BŁĄD: czcionka korzenia to ${stanCzcionki} px zamiast `
            + `${2 * BAZOWA_CZCIONKA_PX} px na ekranie „${ekran.nazwa}" (focus not obscured).`,
          );
          process.exitCode = 1;
          await strona.close();
          continue;
        }
      }

      /*
       * Belkę mierzymy TU, przed chodzeniem Tabem: strona stoi dokładnie
       * w tym stanie, w którym stanęła u człowieka (czcionka przeglądarki
       * ustawiona przed nawigacją, nasza skala potwierdzona na ułożonym
       * dokumencie wyżej), a Tab jeszcze niczego nie przewinął.
       */
      const belka = await zmierzBelke(strona);

      if (belka === null) {
        console.error(
          `BŁĄD: na ekranie „${ekran.nazwa}" (${opis}) nie ma elementu `
          + '`.bottom-nav`. Wszystkie ekrany tego pomiaru są ekranami '
          + 'zalogowanego, więc belka MUSI tam być — jej brak znaczy, że '
          + 'mierzymy stronę gościa albo stronę błędu.',
        );
        process.exitCode = 1;
      } else if (! belka.ukryta) {
        // `skala` w zapisie, nie tylko w opisie: grupowanie po napisie
        // wariantu łapało wszystko dla wariantu „bez powiększania", bo jego
        // etykieta jest pustym napisem, a `endsWith('')` jest zawsze prawdą.
        wysokosciBelki.push({ ekran: ekran.nazwa, wariant: opis, skala: String(skala), ...belka });

        /* Liczone OSOBNO od `zlych`, bo `zlych` opisuje naruszenia 2.4.11
           w wierszu logu wyżej — a za wysoka belka naruszeniem WCAG nie
           jest i podpisanie jej tak byłoby nieprawdą w raporcie. */
        if (belka.udzial > UDZIAL_BELKI_MAKS) {
          zaWysokaBelka.push({ ekran: ekran.nazwa, wariant: opis, ...belka });
        }

        if (belka.pozycji !== POZYCJI_W_BELCE) {
          console.error(
            `BŁĄD: belka na ekranie „${ekran.nazwa}" (${opis}) ma `
            + `${belka.pozycji} pozycji zamiast ${POZYCJI_W_BELCE}. Niska belka `
            + 'okupiona wyrzuceniem pozycji nie jest naprawą (AGENTS.md §5).',
          );
          process.exitCode = 1;
        }
      }

      const { kroki, pelnyPrzebieg } = await przejdzTabemIZmierzFocus(strona);
      await strona.close();

      if (! pelnyPrzebieg) {
        console.error(
          `BŁĄD: na ekranie „${ekran.nazwa}" (${opis}) Tab nie doszedł do końca kolejności `
          + `po ${MAKS_KROKOW_FOCUS} krokach — sprawdzenie jest niepełne.`,
        );
        process.exitCode = 1;
      }

      if (kroki.length === 0) {
        console.error(`BŁĄD: na ekranie „${ekran.nazwa}" (${opis}) Tab nie znalazł ani jednej kontrolki.`);
        process.exitCode = 1;
        continue;
      }

      for (const krok of kroki) {
        for (const [nakladka, pokrycie] of [
          ['.topbar', krok.pokrycieTopbar],
          ['.bottom-nav', krok.pokrycieBottomNav],
        ]) {
          if (pokrycie === null || pokrycie === 0) continue;

          console.log('FOCUS_GEOMETRIA ' + JSON.stringify({ ekran: ekran.nazwa, wariant: opis, nakladka, pokrycie, ...krok.geometria }));

          if (pokrycie >= PROG_CALKOWITEGO_PRZYKRYCIA) {
            zlych++;
            naruszeniaFocus.push({
              ekran: ekran.nazwa, wariant: opis, nakladka, kontrolka: krok.opis,
            });
          } else {
            ostrzezeniaFocus.push({
              ekran: ekran.nazwa, wariant: opis, nakladka, kontrolka: krok.opis, pokrycie,
            });
          }
        }
      }
    }

    await kontekstGosciaFocus.close();
    await kontekstZalogowanegoFocus.close();

    log(`  ${zlych === 0 ? '✓' : '✗'} ${opis}${zlych ? ` — ${zlych} naruszeń` : ''}`);
  }
}

/* =============================================================================
   TABLICA DNIA W PRAWEJ SZYNIE: RZĄD MINIATUR I DWIE KOLUMNY DANIA
   (issue #272)

   DLACZEGO OSOBNY POMIAR, A NIE SAM POMIAR PRZEPEŁNIENIA WYŻEJ
   Bo przepełnienie i ten pomiar łapią BŁĘDY W PRZECIWNE STRONY, i każdy
   z nich osobno przechodzi w złym stanie.

   Stan ze zrzutu właściciela — trzy miniatury jedna pod drugą w szynie —
   NIE przewija strony w bok. Wręcz odwrotnie: zawijanie jest sposobem,
   w jaki przeglądarka unika przewijania. Pomiar przepełnienia świecił więc
   na zielono przez cały czas trwania tej usterki. Odwrotnie też: gdyby ktoś
   „naprawił" ten rząd, zdejmując `flex-wrap: wrap`, to sprawdzenie
   przeszłoby, a strona zaczęłaby się przewijać w bok przy powiększonej
   czcionce — i to złapałby tamten pomiar. Sprawdzone przez zdjęcie tej
   linijki: `scrollWidth` 321 px przy oknie 320 px na `/home` i `/szukaj`
   (na ekranach, gdzie tablica stoi w głównej kolumnie, było to 37 px —
   pomiar w komentarzu przy `.kuking-board-preview` w `app.css`).

   Dopiero razem pilnują jednego i drugiego: TU rząd przy normalnej czcionce,
   TAM brak przepełnienia przy podkręconej. Trzecia część tego sprawdzenia
   (czy reguła CSS istnieje i czy HTML stawia pasek tam, gdzie ona działa)
   nie potrzebuje przeglądarki i stoi w
   `tests/Feature/SzynaTablicaDniaUkladTest.php`.

   MIERZYMY WYŁĄCZNIE PRZY NORMALNEJ CZCIONCE i tylko na szerokościach,
   przy których szyna naprawdę jest kolumną (od 80rem = 1280 px). Przy
   podkręconej czcionce zawinięty pasek jest POPRAWNYM wynikiem, nie
   usterką — wymaganie „jeden wiersz" byłoby tam wymaganiem przewijania
   strony w bok.
   ========================================================================== */
log('');
log('Tablica dnia (rząd miniatur w szynie):');

const EKRANY_TABLICY = [
  { nazwa: 'tablica /home (szyna)', adres: '/home' },
  { nazwa: 'szukaj (szyna)', adres: '/szukaj?q=rosol' },
];

// 1280 to sam próg szyny (80rem), 1512 typowy laptop właściciela. Poniżej
// 1280 tablica ląduje pod treścią na całą szerokość i nie ma tu czego mierzyć.
const SZEROKOSCI_TABLICY = SZYBKO ? [1512] : [1280, 1512];

const rozjazdyTablicy = [];

for (const szerokosc of SZEROKOSCI_TABLICY) {
  const kontekst = await przegladarka.newContext({
    viewport: { width: szerokosc, height: 900 },
    storageState: stanZalogowany,
  });

  for (const ekran of EKRANY_TABLICY) {
    const strona = await kontekst.newPage();
    const odpowiedz = await strona.goto(`${adres}${ekran.adres}`, { waitUntil: 'domcontentloaded' });
    const kod = odpowiedz?.status() ?? 0;

    if (kod !== 200) {
      console.error(`BŁĄD: ekran „${ekran.nazwa}" (${ekran.adres}) odpowiedział kodem ${kod} `
        + 'przy pomiarze tablicy dnia.');
      process.exitCode = 1;
      await strona.close();
      continue;
    }

    const pomiar = await strona.evaluate(() => {
      const gora = (el) => Math.round(el.getBoundingClientRect().top);
      const lewa = (el) => Math.round(el.getBoundingClientRect().left);
      const szer = (el) => Math.round(el.getBoundingClientRect().width);

      const osoba = [...document.querySelectorAll('.kuking-board-person')]
        .find((li) => li.querySelector('.kuking-board-preview img'));

      const pasek = osoba?.querySelector('.kuking-board-preview') ?? null;
      const miniatury = pasek ? [...pasek.querySelectorAll('img')] : [];

      const danieZeZdjeciem = [...document.querySelectorAll('.kuking-board-post')]
        .find((li) => li.querySelector('.kuking-board-post-photo img'));
      const danieBezZdjecia = [...document.querySelectorAll('.kuking-board-post')]
        .find((li) => ! li.querySelector('.kuking-board-post-photo'));

      const zdjecieDania = danieZeZdjeciem?.querySelector('.kuking-board-post-photo') ?? null;
      const podpisDania = danieZeZdjeciem?.querySelector('.kuking-board-post-body') ?? null;
      const podpisBezZdjecia = danieBezZdjecia?.querySelector('.kuking-board-post-body') ?? null;

      return {
        // Pasek W OGÓLE JEST — bez tego wszystko niżej przeszłoby na pustym
        // drzewie, czyli nie sprawdziłoby niczego.
        maPasek: pasek !== null,
        miniatur: miniatury.length,
        // BEZPOŚREDNIE DZIECKO RZĘDU. `flex-basis: 100%` opisuje pasek jako
        // element `.kuking-board-person`; przeniesiony z powrotem do bloku
        // tekstu zostawiłby regułę w arkuszu, a rząd rozsypałby się na nowo.
        paskiemJestDzieckoRzedu: pasek !== null
          && pasek.parentElement?.classList.contains('kuking-board-person') === true,
        goryMiniatur: [...new Set(miniatury.map(gora))],

        // PASEK MA MIEĆ CAŁY WIERSZ, NIE TYLE, ILE MU ZOSTAŁO.
        // To sprawdza `flex-basis: 100%`, a nie sam rząd miniatur: bez tej
        // deklaracji rozmiar bazowy paska liczy się z jego treści (232 px
        // przy trzech miniaturach), więc o tym, czy pasek trafi na własny
        // wiersz, decyduje suma szerokości awatara, opisu i przycisku
        // „Obserwuj" — czyli przypadek. Zmierzone bez `flex-basis` przy
        // oknie 1280 px i czcionce przeglądarki 200 %: pasek lądował
        // W TYM SAMYM wierszu, na prawo od „Obserwuj" (`x` 959 zamiast 73).
        szerokoscPaska: pasek ? szer(pasek) : null,
        szerokoscRzedu: osoba ? szer(osoba) : null,
        lewaPaska: pasek ? lewa(pasek) : null,
        lewaRzedu: osoba ? lewa(osoba) : null,

        maDanieZeZdjeciem: zdjecieDania !== null && podpisDania !== null,
        goraZdjecia: zdjecieDania ? gora(zdjecieDania) : null,
        goraPodpisu: podpisDania ? gora(podpisDania) : null,
        lewaZdjecia: zdjecieDania ? lewa(zdjecieDania) : null,
        lewaPodpisu: podpisDania ? lewa(podpisDania) : null,

        // Danie bez zdjęcia: podpis ma licować z krawędzią karty, a nie
        // stać o `gap` w prawo od nieistniejącego zdjęcia.
        maDanieBezZdjecia: podpisBezZdjecia !== null,
        lewaKartyBezZdjecia: danieBezZdjecia ? lewa(danieBezZdjecia) : null,
        lewaPodpisuBezZdjecia: podpisBezZdjecia ? lewa(podpisBezZdjecia) : null,
      };
    });

    await strona.close();

    const bledy = [];

    if (! pomiar.maPasek) {
      bledy.push('brak paska miniatur w tablicy — nie ma czego mierzyć '
        + '(sprawdź wybór redakcyjny przygotowany przez `tablicaDnia`)');
    } else {
      if (pomiar.miniatur < 2) {
        bledy.push(`pasek ma ${pomiar.miniatur} miniaturę — o jednej nie da się `
          + 'powiedzieć, czy stoi w rzędzie');
      }

      if (! pomiar.paskiemJestDzieckoRzedu) {
        bledy.push('pasek miniatur nie jest bezpośrednim dzieckiem '
          + '`.kuking-board-person`, więc `flex-basis: 100%` na nim nic nie robi');
      }

      if (pomiar.goryMiniatur.length > 1) {
        bledy.push(`miniatury stoją w ${pomiar.goryMiniatur.length} wierszach `
          + `(górne krawędzie: ${pomiar.goryMiniatur.join(', ')} px) przy pasku `
          + `szerokim na ${pomiar.szerokoscPaska} px — to jest stan ze zrzutu z #272`);
      }

      if (Math.abs(pomiar.szerokoscPaska - pomiar.szerokoscRzedu) > 1
        || Math.abs(pomiar.lewaPaska - pomiar.lewaRzedu) > 1) {
        bledy.push(`pasek miniatur nie zajmuje całego wiersza karty: `
          + `${pomiar.lewaPaska}…${pomiar.lewaPaska + pomiar.szerokoscPaska} px `
          + `przy karcie ${pomiar.lewaRzedu}…${pomiar.lewaRzedu + pomiar.szerokoscRzedu} px. `
          + 'Bez `flex-basis: 100%` o miejscu paska decyduje suma szerokości awatara, '
          + 'opisu i przycisku „Obserwuj", a nie decyzja układu');
      }
    }

    if (! pomiar.maDanieZeZdjeciem) {
      bledy.push('brak dania ze zdjęciem — układ dwukolumnowy karty dania nie został sprawdzony');
    } else if (Math.abs(pomiar.goraZdjecia - pomiar.goraPodpisu) > 2) {
      bledy.push(`podpis dania stoi w innym wierszu niż zdjęcie (zdjęcie ${pomiar.goraZdjecia} px, `
        + `podpis ${pomiar.goraPodpisu} px) — zawinął się zamiast stanąć obok`);
    } else if (pomiar.lewaPodpisu <= pomiar.lewaZdjecia) {
      bledy.push('podpis dania nie stoi PO PRAWEJ od zdjęcia '
        + `(zdjęcie ${pomiar.lewaZdjecia} px, podpis ${pomiar.lewaPodpisu} px)`);
    }

    if (pomiar.maDanieBezZdjecia
      && Math.abs(pomiar.lewaPodpisuBezZdjecia - pomiar.lewaKartyBezZdjecia) > 1) {
      bledy.push('danie bez zdjęcia ma podpis odsunięty od krawędzi karty '
        + `(karta ${pomiar.lewaKartyBezZdjecia} px, podpis ${pomiar.lewaPodpisuBezZdjecia} px) — `
        + 'wrócił pusty odnośnik zabierający swój `gap`');
    }

    if (bledy.length > 0) {
      rozjazdyTablicy.push({ ekran: ekran.nazwa, szerokosc, bledy, ...pomiar });
    }

    log(`  ${bledy.length === 0 ? '✓' : '✗'} ${ekran.nazwa} przy ${szerokosc} px`
      + (pomiar.maPasek ? ` — miniatur ${pomiar.miniatur} w ${pomiar.goryMiniatur.length} wierszu/ach` : '')
      + (bledy.length ? ` — ${bledy.length} niezgodności` : ''));
  }

  await kontekst.close();
}

/* D-210: jeden zestaw pięciu rzeczywistych liczników pod ciemnym nagłówkiem,
   poza nagłówkiem i szyną, na każdej mierzonej szerokości. */
log('');
log('Liczby profilu: pojedynczy pas pod nagłówkiem (D-210):');

// BEGIN POMIAR_LICZB_PROFILU — te same funkcje wykonuje wąska regresja źródła.
function pomiarLiczbProfilu() {
  const visible = el => {
    if (!el || !el.getClientRects().length) return false;
    for (let p = el; p; p = p.parentElement) {
      const css = getComputedStyle(p);
      if (css.visibility !== 'visible' || Number(css.opacity) === 0) return false;
    }
    return true;
  };
  const lists = [...document.querySelectorAll('.profil-liczniki')];
  const list = lists[0];
  const band = document.querySelector('.marka-profil-statystyki');
  const header = document.querySelector('.marka-profil-kompozycja');
  const archive = document.querySelector('.marka-profil-dol');
  const fields = [...(list?.querySelectorAll('.profil-licznik-pole') ?? [])];
  return {
    copies: lists.length,
    visible: visible(list) && visible(band),
    placement: !!list && !!band && !!header && !!archive && band.contains(list)
      && !list.closest('.marka-profil-kompozycja, .app-rail, .marka-profil-szyna')
      && header.nextElementSibling === band && band.nextElementSibling === archive,
    geometry: !!band && !!header && !!archive
      && band.getBoundingClientRect().top >= header.getBoundingClientRect().bottom - 1
      && archive.getBoundingClientRect().top >= band.getBoundingClientRect().bottom - 1,
    fields: fields.map(el => ({
      visible: visible(el) && visible(el.querySelector('.stat-value')) && visible(el.querySelector('.stat-label')),
      value: el.querySelector('.stat-value')?.textContent.trim() ?? '',
      label: el.querySelector('.stat-label')?.textContent.trim() ?? '',
      href: el.getAttribute('href'),
      background: getComputedStyle(el.closest('.profil-licznik')).backgroundColor,
    })),
    path: location.pathname,
    headerBackground: header ? getComputedStyle(header).backgroundColor : null,
  };
}
function bledyLiczbProfilu(p) {
  const errors = [];
  if (p.copies !== 1) errors.push('PROFILE_COUNTER_COPIES ' + p.copies);
  if (!p.visible) errors.push('PROFILE_COUNTER_HIDDEN');
  if (!p.placement || !p.geometry) errors.push('PROFILE_COUNTER_POSITION');
  const labels = [/^wpis/, /^przepis/, /Ugotowa\u0142em/, /^obserwuj/, /^obserwowan/];
  if (p.fields.length !== 5 || p.fields.some((f, i) => !f.visible || !/^\d+$/.test(f.value) || !labels[i]?.test(f.label))) errors.push('PROFILE_COUNTER_CONTENT');
  if (p.fields.slice(3).some((f, i) => !f.href || new URL(f.href, 'http://localhost').pathname !== p.path + ['/obserwujacy', '/obserwowani'][i])) errors.push('PROFILE_COUNTER_LINKS');
  if (p.fields.some(f => f.background !== 'rgb(255, 255, 255)') || p.headerBackground === 'rgb(255, 255, 255)') errors.push('PROFILE_COUNTER_SURFACE');
  return errors;
}
// END POMIAR_LICZB_PROFILU

const EKRANY_LICZB = [
  { nazwa: 'profil cudzy (gość)', adres: '/@basia', zalogowany: false },
  { nazwa: 'profil cudzy (zalogowany)', adres: '/@basia', zalogowany: true },
  { nazwa: 'profil własny', adres: `/@${KONTO_ZALOGOWANE}`, zalogowany: true },
];

// 360 to telefon, 1280 sam próg szyny (80rem), 1512 laptop właściciela —
// czyli szerokość ze zgłoszenia.
const SZEROKOSCI_LICZB = SZYBKO ? [360, 1512] : [360, 1280, 1512];

const rozjazdyLiczb = [];

for (const szerokosc of SZEROKOSCI_LICZB) {
  for (const ekran of EKRANY_LICZB) {
    const kontekst = await przegladarka.newContext({
      viewport: { width: szerokosc, height: 900 },
      ...(ekran.zalogowany ? { storageState: stanZalogowany } : {}),
    });

    const strona = await kontekst.newPage();
    const odpowiedz = await strona.goto(`${adres}${ekran.adres}`, { waitUntil: 'domcontentloaded' });
    const kod = odpowiedz?.status() ?? 0;

    if (kod !== 200) {
      console.error(`BŁĄD: ekran „${ekran.nazwa}" (${ekran.adres}) odpowiedział kodem ${kod} `
        + 'przy pomiarze liczb o osobie.');
      process.exitCode = 1;
      await strona.close();
      await kontekst.close();
      continue;
    }

    await strona.evaluate(() => { document.documentElement.dataset.theme = 'light'; });
    const pomiar = await strona.evaluate(pomiarLiczbProfilu);
    const bledy = bledyLiczbProfilu(pomiar);
    log(`  ${ekran.nazwa} przy ${szerokosc} px: kopii ${pomiar.copies}, pól ${pomiar.fields.length}, `
      + `pas ${pomiar.visible ? 'widoczny' : 'niewidoczny'}, pozycja ${pomiar.placement && pomiar.geometry ? 'poprawna' : 'błędna'}`);

    if (bledy.length > 0) {
      rozjazdyLiczb.push({ ekran: ekran.nazwa, szerokosc, bledy });
    }

    await strona.close();
    await kontekst.close();
  }
}

if (rozjazdyLiczb.length > 0) {
  log('');
  log('Liczby o osobie: niezgodność z D-210:');
  for (const r of rozjazdyLiczb) {
    log(`  ${r.ekran} przy ${r.szerokosc} px:`);
    for (const blad of r.bledy) {
      log(`      ${blad}`);
    }
  }
}

/* ==========================================================================
   SZYNA GOŚCIA STOI OBOK TREŚCI, NIE POD NIĄ (D-122)

   DLACZEGO TO JEST POMIAR, A NIE TEST PHP
   Testy sprawdzają, że dokument gościa dostaje klasę układu
   (`SzynaGosciaTest`). Tego, czy szyna NAPRAWDĘ stoi obok treści, dokument
   nie zdradza: to wynik trzech reguł w dwóch plikach (`app.css`, `tokens.css`)
   i progu 80rem, a każda z nich może zniknąć osobno, nie ruszając HTML-a.

   ZGŁOSZENIE WŁAŚCICIELA, KTÓRE TO ZAMYKA: „niektóre podstrony jak napisz do
   nas jest bardzo wąskie, gdzie po prawej i lewej można coś dodać na kompie".
   Zmierzone 11 września, okno 1920 px, GOŚĆ, PRZED poprawką: treść 720 px
   w ramce 768 px, a pierwszy blok szyny („Nie możesz się zalogować") na
   y = 1964 px, czyli dwa ekrany niżej. Po poprawce: y = 96 px, obok treści.

   TRZY RZECZY NARAZ, BO KAŻDA PSUJE SIĘ OSOBNO:
    1. ekran gościa Z SZYNĄ przy 1280+ ma ją OBOK treści i po jej PRAWEJ,
    2. ten sam ekran na telefonie ma ją POD treścią (inaczej blok wjechałby
       nad treść, po którą człowiek przyszedł),
    3. ekran gościa BEZ SZYNY ma dokładnie JEDNĄ kolumnę i stoi na środku —
       rezerwacja pustej kolumny to ta sama usterka, którą właściciel zgłosił
       przy panelu moderacji („po prawej duży pusty obszar").
   ========================================================================== */
log('');
log('Szyna gościa (D-122):');

/*
 * `zSzyna` ZNACZY „MA `<aside class="app-rail">`", NIE „MA KOLUMNĘ SZYNY".
 * To nie jest to samo od 11 września: „Świeżo z Kuking" używa kolumny szyny
 * OD ŚRODKA `<main>` (`.odkryj-uklad`, zgłoszenie „prawa kolumna pusta
 * wszystko na środku"), tak samo jak strona przepisu. Dla pomiaru wyżej to
 * dalej ekran „bez szyny": ma mieć JEDNĄ kolumnę siatki i stać na środku —
 * dwie kolumny `.app-body` znaczyłyby, że dostał do tego pustą kolumnę po
 * prawej. Blok, który tę kolumnę zajmuje od środka, mierzy `szynaWTresci`
 * niżej.
 */
const EKRANY_SZYNY_GOSCIA = [
  { nazwa: 'napisz do nas (gość)', adres: '/napisz-do-nas', zSzyna: true },
  { nazwa: 'szukaj (gość)', adres: '/szukaj?q=zupa', zSzyna: true },
  { nazwa: 'Świeżo z Kuking (gość)', adres: '/odkryj', zSzyna: false, szynaWTresci: '.odkryj-szyna' },
];

// 360 to telefon (szyna MA być pod treścią), 1280 sam próg szyny, 1512 laptop
// właściciela — czyli szerokość ze zgłoszenia.
const SZEROKOSCI_SZYNY_GOSCIA = SZYBKO ? [360, 1512] : [360, 1280, 1512];

const rozjazdySzynyGoscia = [];

for (const szerokosc of SZEROKOSCI_SZYNY_GOSCIA) {
  const kontekst = await przegladarka.newContext({
    viewport: { width: szerokosc, height: 900 },
  });

  for (const ekran of EKRANY_SZYNY_GOSCIA) {
    const strona = await kontekst.newPage();
    const odpowiedz = await strona.goto(`${adres}${ekran.adres}`, { waitUntil: 'domcontentloaded' });
    const kod = odpowiedz?.status() ?? 0;

    if (kod !== 200) {
      console.error(`BŁĄD: ekran „${ekran.nazwa}" (${ekran.adres}) odpowiedział kodem ${kod} `
        + 'przy pomiarze szyny gościa.');
      process.exitCode = 1;
      await strona.close();
      continue;
    }

    await poczekajNaFonty(strona);

    const pomiar = await strona.evaluate((selektorWTresci) => {
      const body = document.querySelector('.app-body');
      const main = document.querySelector('.app-main');
      const rail = document.querySelector('.app-rail');

      if (! body || ! main) return null;

      /*
       * BLOK, KTÓRY ZAJMUJE KOLUMNĘ SZYNY OD ŚRODKA `<main>`.
       *
       * Mierzymy go WZGLĘDEM KOLUMNY CZYTANIA, a nie względem `<main>`:
       * `<main>` obejmuje tu obie kolumny, więc „szyna po prawej stronie
       * main" byłoby nieprawdą zawsze, a „szyna w main" prawdą zawsze.
       * Kolumnę czytania reprezentuje pierwsza karta wpisu albo pusty stan —
       * czyli to, co człowiek na tym ekranie czyta.
       */
      const wTresci = selektorWTresci ? document.querySelector(selektorWTresci) : null;
      const czytanie = document.querySelector('.app-main .post-card, .app-main .empty-state');
      const rw = (wTresci && wTresci.getClientRects().length > 0) ? wTresci.getBoundingClientRect() : null;
      const rc = czytanie ? czytanie.getBoundingClientRect() : null;

      const rb = body.getBoundingClientRect();
      const rm = main.getBoundingClientRect();
      const rr = rail ? rail.getBoundingClientRect() : null;
      const kolumny = getComputedStyle(body).gridTemplateColumns;

      return {
        // `none` znaczy „siatka wyłączona" (poniżej 64rem) — jedna kolumna.
        kolumn: kolumny === 'none' ? 1 : kolumny.trim().split(/\s+/).length,
        maNawigacje: document.querySelector('.side-nav') !== null,
        // Szyna z treścią, nie sam kontener: pusty `<aside>` ma zerową
        // wysokość i „stoi obok" wszystkiego, czego się nie mierzy.
        szynaZTrescia: rail !== null && rail.getClientRects().length > 0 && rr.height > 0,
        szynaObokTresci: rr !== null ? rr.top < rm.bottom - 40 : null,
        szynaPoPrawej: rr !== null ? Math.round(rr.left) >= Math.round(rm.right) : null,
        trescSzerokosc: Math.round(rm.width),
        szynaGora: rr !== null ? Math.round(rr.top) : null,
        // Wyśrodkowanie siatki: tyle samo miejsca z lewej co z prawej.
        marginesLewy: Math.round(rb.left),
        marginesPrawy: Math.round(window.innerWidth - rb.right),

        // Szyna od środka `<main>` — mierzona tylko na ekranie, który ją deklaruje.
        wTresciJest: rw !== null,
        wTresciOczekiwana: selektorWTresci !== undefined && selektorWTresci !== null,
        wTresciObokCzytania: (rw && rc) ? Math.round(rw.left) >= Math.round(rc.right) : null,
        wTresciNadCzytaniem: (rw && rc) ? Math.round(rw.top) <= Math.round(rc.top) : null,
        wTresciGora: rw ? Math.round(rw.top) : null,
      };
    }, ekran.szynaWTresci ?? null);

    await strona.close();

    if (! pomiar) {
      console.error(`BŁĄD: na ekranie „${ekran.nazwa}" brakuje siatki (.app-body) albo kolumny treści (.app-main).`);
      process.exitCode = 1;
      continue;
    }

    const bledy = [];

    if (pomiar.maNawigacje) {
      bledy.push('gość dostał nawigację boczną — mierzony jest zły stan ekranu');
    }

    if (ekran.zSzyna && ! pomiar.szynaZTrescia) {
      bledy.push('szyna jest pusta albo jej nie ma, a ten ekran ma ją mieć');
    }

    if (ekran.zSzyna && pomiar.szynaZTrescia && szerokosc >= 1280) {
      if (pomiar.kolumn !== 2) {
        bledy.push(`siatka ma ${pomiar.kolumn} kolumn zamiast dwóch (treść + szyna)`);
      }

      if (! pomiar.szynaObokTresci) {
        bledy.push(`szyna zjechała pod treść (jej góra: ${pomiar.szynaGora} px)`);
      }

      if (! pomiar.szynaPoPrawej) {
        bledy.push('szyna nie stoi po prawej stronie treści');
      }
    }

    // Na telefonie szyna MA być pod treścią — patrz `.app-rail` w app.css.
    if (ekran.zSzyna && pomiar.szynaZTrescia && szerokosc < 1280 && pomiar.szynaObokTresci) {
      bledy.push('szyna stoi obok treści na wąskim ekranie — zepchnęła treść w bok');
    }

    if (! ekran.zSzyna && pomiar.kolumn !== 1) {
      bledy.push(`ekran bez szyny ma ${pomiar.kolumn} kolumny — po prawej stoi pusta kolumna`);
    }

    if (! ekran.zSzyna && Math.abs(pomiar.marginesLewy - pomiar.marginesPrawy) > 1) {
      bledy.push(`treść nie stoi na środku (${pomiar.marginesLewy} px z lewej, `
        + `${pomiar.marginesPrawy} px z prawej)`);
    }

    /*
     * SZYNA OD ŚRODKA `<main>` — ta sama reguła co dla `.app-rail`, tylko
     * liczona względem kolumny czytania. Dokument tego nie zdradza (blok jest
     * w drzewie tam, gdzie był na telefonie), rozstrzyga wyłącznie siatka.
     */
    if (pomiar.wTresciOczekiwana) {
      if (! pomiar.wTresciJest) {
        bledy.push(`nie ma bloku „${ekran.szynaWTresci}", który ma zajmować kolumnę szyny`);
      } else if (pomiar.wTresciObokCzytania === null) {
        bledy.push('nie ma czego zmierzyć w kolumnie czytania (ani karty wpisu, ani pustego stanu)');
      } else if (szerokosc >= 1280 && ! pomiar.wTresciObokCzytania) {
        bledy.push(`blok szyny został w kolumnie czytania (jego góra: ${pomiar.wTresciGora} px) `
          + '— po prawej stronie treści zostaje pusty pas');
      } else if (szerokosc < 1280 && ! pomiar.wTresciNadCzytaniem) {
        bledy.push('na wąskim ekranie blok szyny zjechał pod wpisy — a w kodzie stoi przed nimi');
      }
    }

    log(`  ${ekran.nazwa} przy ${szerokosc} px: kolumn ${pomiar.kolumn}, `
      + `treść ${pomiar.trescSzerokosc} px, `
      + `szyna ${pomiar.szynaZTrescia ? (pomiar.szynaObokTresci ? `obok (y=${pomiar.szynaGora})` : `pod treścią (y=${pomiar.szynaGora})`) : 'brak'}`
      + (pomiar.wTresciOczekiwana
        ? `, szyna w treści ${pomiar.wTresciJest ? (pomiar.wTresciObokCzytania ? `obok czytania (y=${pomiar.wTresciGora})` : `nad czytaniem (y=${pomiar.wTresciGora})`) : 'BRAK'}`
        : ''));

    if (bledy.length > 0) {
      rozjazdySzynyGoscia.push({ ekran: ekran.nazwa, szerokosc, bledy });
    }
  }

  await kontekst.close();
}

if (rozjazdySzynyGoscia.length > 0) {
  log('');
  log('Szyna gościa stoi w złym miejscu (D-122):');
  for (const r of rozjazdySzynyGoscia) {
    log(`  ${r.ekran} przy ${r.szerokosc} px:`);
    for (const blad of r.bledy) {
      log(`      ${blad}`);
    }
  }
}

await przegladarka.close();
zamknij();

mkdirSync('storage', { recursive: true });
writeFileSync('storage/dostepnosc.json', JSON.stringify({
  data: new Date().toISOString(),
  chromium: wersjaPrzegladarki,
  warianty: WARIANTY.map((w) => w.nazwa),
  naruszen: wyniki.length,
  blokujacych,
  // Ile ekranów ZADEKLAROWANO i ile naprawdę zbadano. Dwie różne liczby
  // w raporcie, bo tylko one odróżniają „zero naruszeń" od „zero zbadanych".
  ekranow: EKRANY.length,
  zbadanych: zbadanePrzezAxe.size,
  wyniki,
  uklad: {
    szerokosci: SZEROKOSCI_UKLADU,
    skale: SKALE_UKLADU,
    ekranow: EKRANY_UKLADU.length,
    zmierzonych: zmierzoneUkladem.size,
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
  focusNotObscured: {
    szerokosci: SZEROKOSCI_FOCUS,
    skale: SKALE_UKLADU,
    naruszen: naruszeniaFocus.length,
    naruszenia: naruszeniaFocus,
    ostrzezen: ostrzezeniaFocus.length,
    ostrzezenia: ostrzezeniaFocus,
  },
  /* Ile ekranu zabiera dolna belka (issue #295). Pełna lista pomiarów, nie
     tylko przekroczenia: bez niej nie da się po przebiegu odpowiedzieć na
     pytanie „a ile ta belka ma teraz", czyli na to jedno pytanie, po które
     się tu przychodzi. */
  wysokoscBelki: {
    szerokosci: SZEROKOSCI_FOCUS,
    skale: SKALE_UKLADU,
    prog: UDZIAL_BELKI_MAKS,
    zmierzonych: wysokosciBelki.length,
    przekroczen: zaWysokaBelka.length,
    przekroczenia: zaWysokaBelka,
    pomiary: wysokosciBelki,
  },
  tablicaDnia: {
    szerokosci: SZEROKOSCI_TABLICY,
    rozjazdow: rozjazdyTablicy.length,
    rozjazdy: rozjazdyTablicy,
  },
  /*
   * LICZBY O OSOBIE (D-210, wcześniej D-091) — kontrola, którą kod wyjścia respektował od
   * początku, a artefakt przemilczał. Brakowało jej dokładnie tutaj, czyli
   * w trzecim z trzech miejsc zbiorczych tego pliku (sekcja 14.5
   * przekazania). Skutek nie był groźny — CI oblewało poprawnie — ale
   * `dostepnosc.json` jest jedynym miejscem, z którego da się odczytać
   * PRZYCZYNĘ po skończonym przebiegu, więc jego niekompletność jest cichym
   * kosztem płaconym przy każdej diagnozie.
   */
  liczbyProfilu: {
    szerokosci: SZEROKOSCI_LICZB,
    rozjazdow: rozjazdyLiczb.length,
    rozjazdy: rozjazdyLiczb,
  },
  /* Szyna gościa (D-122) — ta sama zasada co przy `liczbyProfilu` wyżej:
     kod wyjścia to respektuje, więc artefakt musi umieć powiedzieć DLACZEGO. */
  szynaGoscia: {
    szerokosci: SZEROKOSCI_SZYNY_GOSCIA,
    rozjazdow: rozjazdySzynyGoscia.length,
    rozjazdy: rozjazdySzynyGoscia,
  },
}, null, 2));

log('');
log(`Wynik zapisany: storage/dostepnosc.json (naruszeń: ${wyniki.length}, `
  + `blokujących: ${blokujacych}, przepełnień w poziomie: ${przepelnienia.length}, `
  + `rozjazdów belki: ${rozjazdyBelki.length}, `
  + `niespójnych szerokości: ${niespojneSzerokosci.length}, `
  + `rozjazdów tablicy dnia: ${rozjazdyTablicy.length}, `
  + `focus zasłonięty w 100%: ${naruszeniaFocus.length}, `
  + `focus częściowo zasłonięty: ${ostrzezeniaFocus.length}, `
  + `belka ponad ${Math.round(UDZIAL_BELKI_MAKS * 100)}% okna: ${zaWysokaBelka.length}, `
  + `liczb o osobie w złym miejscu: ${rozjazdyLiczb.length}, `
  + `szyny gościa w złym miejscu: ${rozjazdySzynyGoscia.length})`);
// Liczby zbadanych ekranów W TYM SAMYM wierszu co wynik, a nie tylko w pliku:
// „przepełnień: 0" znaczy coś innego przy 36 zmierzonych ekranach i przy 33.
log(`Zbadane ekrany — axe: ${zbadanePrzezAxe.size}/${EKRANY.length}, `
  + `układ: ${zmierzoneUkladem.size}/${EKRANY_UKLADU.length}.`);

if (naruszeniaFocus.length > 0) {
  log('');
  log('Focus zasłonięty w 100% przez nakładkę (WCAG 2.2 AA — 2.4.11 Focus Not Obscured, FAIL):');
  for (const n of naruszeniaFocus) {
    log(`  ${n.ekran} / ${n.wariant}: ${n.kontrolka} pod ${n.nakladka}`);
  }
}

if (ostrzezeniaFocus.length > 0) {
  log('');
  log('Focus częściowo zasłonięty (ostrzeżenie produktowe — nie jest to naruszenie WCAG):');
  for (const o of ostrzezeniaFocus) {
    log(`  ${o.ekran} / ${o.wariant}: ${o.kontrolka} pod ${o.nakladka} (${Math.round(o.pokrycie * 100)}%)`);
  }
}

/* Najwyższa zmierzona belka W KAŻDYM wariancie skali, także gdy wszystkie są
   pod progiem. „Zero przekroczeń" nie mówi, czy belka ma 25% ekranu, czy 33% —
   a to jest ta liczba, która w issue #295 była całą treścią zgłoszenia. */
if (wysokosciBelki.length > 0) {
  log('');
  log(`Dolna belka — najwyższa zmierzona w każdej skali (próg: ${Math.round(UDZIAL_BELKI_MAKS * 100)}% okna):`);

  for (const skala of SKALE_UKLADU) {
    const etykieta = etykietaSkali(skala) || ' / bez powiększania';
    const najwyzsza = wysokosciBelki
      .filter((b) => b.skala === String(skala))
      .sort((a, b) => b.udzial - a.udzial)[0];

    if (! najwyzsza) continue;

    log(`  ${najwyzsza.udzial > UDZIAL_BELKI_MAKS ? '✗' : '✓'}${etykieta}: `
      + `${najwyzsza.wysokosc} px z ${najwyzsza.okno} px okna `
      + `(${Math.round(najwyzsza.udzial * 1000) / 10}%), `
      + `${najwyzsza.pozycji} pozycji w ${najwyzsza.wierszy} wierszach, `
      + `podpis ${najwyzsza.pismoPodpisu} px — ${najwyzsza.ekran} / ${najwyzsza.wariant}`);
  }
}

if (zaWysokaBelka.length > 0) {
  log('');
  log('Dolna belka zabiera za dużo ekranu (issue #295 — nie jest to naruszenie WCAG):');
  for (const b of zaWysokaBelka) {
    log(`  ${b.ekran} / ${b.wariant}: ${b.wysokosc} px z ${b.okno} px okna `
      + `(${Math.round(b.udzial * 1000) / 10}%), ${b.pozycji} pozycji w ${b.wierszy} wierszach`);
  }
}

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
    if (r.marka) {
      for (const blad of r.bledy) log('      ' + blad);
      continue;
    }
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

if (rozjazdyTablicy.length > 0) {
  log('');
  log('Tablica dnia rozjeżdża się w prawej szynie (issue #272):');
  for (const r of rozjazdyTablicy) {
    log(`  ${r.ekran} przy ${r.szerokosc} px:`);
    for (const blad of r.bledy) {
      log(`      ${blad}`);
    }
  }
}

if (
  blokujacych > 0
  || przepelnienia.length > 0
  || rozjazdyBelki.length > 0
  || niespojneSzerokosci.length > 0
  || naruszeniaFocus.length > 0
  || rozjazdyTablicy.length > 0
  || rozjazdyLiczb.length > 0
  || rozjazdySzynyGoscia.length > 0
  || zaWysokaBelka.length > 0
) {
  process.exit(1);
}

// `process.exitCode` mógł zostać ustawiony wyżej (nierozwiązany adres ekranu,
// karuzela nieprzechodząca bez JavaScriptu). Wychodzimy z nim, zamiast go
// zgubić — cichy kod 0 przy niesprawdzonym ekranie to dokładnie ta fałszywa
// zieleń, przed którą ten skrypt ma bronić.
process.exit(process.exitCode ?? 0);
