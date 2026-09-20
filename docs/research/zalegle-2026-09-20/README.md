# Zaległości #732, #748, #749, #752, #765 — odbiór lokalny

Stan początkowy: czysty `gpt/zalegle`, `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`.
Worktree: `C:\Users\matma\Documents\kuking-flota\gpt-zalegle`.
Runtime: `/home/mateusz/flota/gpt-zalegle-run`, skopiowane zależności.
Baza: `kuking_flota_gpt-zalegle`, właściciel `kuking`, `127.0.0.1:55439`.
Nazwa runtime odpowiada istniejącemu katalogowi; podany w przekazaniu katalog
`zalegle` nie istnieje. Wspólne skrypty floty pozostały bez zmian.

## Lokalne commity kodu

- `424073e9` — jawna sonda PostgreSQL (#732).
- `643ba106` — powrót na istniejącą stronę listy relacji (#748).
- `452420bc` — ponowienie tego samego adresu offline (#749).
- `fa5af491` — dostosowanie istniejącego testu ekranu offline (#749).
- `8e05fb25` — wymiana odrzuconego zdjęcia i wydruk A4 (#752, #765).

## Co zmierzono samodzielnie

### #732 — sonda bazy

Nowy `tests/skrypty/check-postgres.sh` uruchamia rzeczywisty początek
`scripts/check.sh`, przechwytując sondę i zarządzanie klastrem atrapami.
Przed poprawką brakowało jawnych argumentów, były dwie sondy i próba
uruchomienia klastra. Brak parametrów oraz niedostępność nie zatrzymywały
kroku. To czerwień wykonana przed zmianą kodu, bez kontaktu z bazą.

Po poprawce sonda używa czterech jawnych parametrów; odmawia przy braku
któregokolwiek i przy porcie 5432, nie zarządza klastrem. Wymóg lokalnego
portu nie zmienia dynamicznego portu PostgreSQL w Actions. Komunikat jawnie
oddziela gotowość serwera od uwierzytelnienia i istnienia bazy. Rzeczywista
sonda `127.0.0.1:55439` odpowiedziała `accepting connections`; testy HTTP
poniżej rzeczywiście korzystały z własnej bazy.

### #748 — obie listy relacji

`ListaRelacjiPoUsunieciuTest`: 21 obserwowanych, GET strony drugiej, DELETE
ostatniej karty z refererem strony drugiej, ponowny GET. Przed poprawką
odpowiedź mówiła „Jeszcze nikogo nie obserwuje”, mimo 20 relacji w bazie.
Drugi test odtworzył pustą stronę 999 przy istniejącej osobie.

Po poprawce GET poza zakresem przekierowuje na ostatnią istniejącą stronę,
na podstawie paginatora już przefiltrowanego dla widza. Zachowuje komunikat
po DELETE przez `reflash()`. Nowe testy: 2/2, 18 asercji; dotychczasowe
`FollowListsTest`: 14/14, 304 asercje. Zachowano porządek i filtry blokad.

Zakres tego pakietu to dwie listy relacji wskazane w przekazaniu zadania.
Dodatkowe ekrany wymienione później w komentarzach issue (powiadomienia,
profil przepisów/wykonań, tagi, zeszyt, zgłoszenia) nie zostały tu naprawione
ani uznane za sprawdzone. Nie zamykać całego rozszerzonego issue na podstawie
samego tego pakietu.

### #749 — offline w prawdziwej przeglądarce

`node scripts/offline-ponowienie.test.mjs` instaluje rzeczywisty `sw.js`
w Chromium, odłącza kontekst od sieci i otwiera przepis oraz wyszukiwanie
z dwoma parametrami. Po włączeniu sieci klika „Spróbuj ponownie” i wymaga
tego samego URL oraz treści docelowej. Przed zmianą próba przepisu nie
dotarła do właściwego nagłówka; po zmianie obie ścieżki przechodzą.
Sprawdzono także odmowę POST offline i dokładną listę zasobów w cache:
wyłącznie dokument offline oraz dwie ikony, bez HTML odwiedzanych stron.

Pusty `href` ponawia aktualny adres bez dodatkowego JavaScriptu, więc nie
potrzebuje nowej zgody CSP ani zasobu pobieranego z niedziałającej sieci.
Podniesiono wersję cache. Regresja jest podłączona do joba dostępności CI.
To lokalny serwer scenariuszy i prawdziwy SW; nie pomiar telefonu ani produkcji.

### #752 — odrzucone zdjęcie przepisu

Przed poprawką HTTP własnego przepisu z odrzuconym zdjęciem zawierało radę
„Wpis możesz usunąć”. Po poprawce komponent dostaje kontekst przepisu
od rodzica i pokazuje odnośnik edycji, wyłącznie dla aktywnego konta z zgodą
`RecipePolicy::update`. Objęto zdjęcie główne, skan źródła, zdjęcie kroku
oraz tryb gotowania. Nie zmieniano samej Policy ani ścieżki serwowania zdjęć.

`OdrzuconeZdjeciePrzepisuTest`: 1/1, 16 asercji; również obcy widz,
zawieszone konto i przepis ukryty przez moderację. Dotychczasowe
`PrzygotowywanieZdjeciaWpisuTest`: 10/10, 68 asercji, w tym odrzucony
zwykły wpis i poprawne warianty zdjęć. Nie symulowano awarii prawdziwego kodera.

### #765 — wydruk A4

Chromium **153.0.8010.12**, `page.pdf`, A4, margines 12 mm, włączone drukowanie
tła. HTML pochodzi z rzeczywistych odpowiedzi HTTP Laravel na własnej bazie
(`WydrukPrzepisuFixtureTest`); CSS z `npm run build`. Nie jest to ręcznie
zbudowana makieta. Zależności pomiaru: Playwright oraz Poppler (`pdfinfo`,
`pdftotext`, do oglądu również `pdftoppm`). Bez biblioteki PDF w produkcie.

Fixture: 4 porcje, 50 minut, autor, adres zewnętrznego źródła, grupy
Ciasto/Farsz, `note`, `no_amount`, obraz główny i obraz kroku o proporcji
1600×1200. Obrazy są jawnymi grafikami kontrolnymi, nie zdjęciami użytkowników.
Krótki przepis: 6 składników, 3 kroki. Długi: 18 składników, 14 kroków;
krok piąty ma 55 powtórzeń zdania i przechodzi między stronami.

| Przepis | Motyw | Strony przed | Strony po | Widoczne kontrolki przed/po |
|---|---|---:|---:|---:|
| Krótki | jasny | 6 | 2 | 19 / 0 |
| Krótki | ciemny | 6 | 2 | 19 / 0 |
| Długi | jasny | 10 | 4 | 19 / 0 |
| Długi | ciemny | 10 | 4 | 19 / 0 |

Przed zmianą dolna stała nawigacja nakładała się na obraz/treść, drukowane
były formularze i obudowa strony, a ciemny motyw dawał ciemny papier.
Nie twierdzimy, że zaginął tekst kroku przed poprawką: wykazanym błędem
były nakładanie obudowy, kolor i drukowanie niedziałających na papierze akcji.
Pierwsza próba naprawy zostawiała ciemne marginesy; ogląd PNG to wykrył.
Końcowy wariant wymusza jasny schemat również na korzeniu dokumentu.

Po zmianie sprawdzono każdy składnik oraz pełny tekst każdego kroku
wyciągnięty z PDF, autorstwo, źródło, uwagi i „do smaku”. Obejrzano wszystkie
strony. Obrazy PNG wszystkich stron po zmianie są identyczne pikselowo
między motywem jasnym i ciemnym. Zdjęcia zachowano, ograniczając wysokość
na papierze do 40 mm. Reguły dotyczą wydruku istniejącego widoku, bez nowego
endpointu i bez obchodzenia Policy. Nie wykonano fizycznego wydruku ani
pomiarów Firefox/Safari. Przy zdjęciach o innych proporcjach i własnych
ustawieniach drukarki liczba stron może się różnić.

PDF-y porównawcze:

| Wariant | Przed | Po |
|---|---|---|
| Krótki jasny | [PDF](druk/przed/krotki-light.pdf) | [PDF](druk/po/krotki-light.pdf) |
| Krótki ciemny | [PDF](druk/przed/krotki-dark.pdf) | [PDF](druk/po/krotki-dark.pdf) |
| Długi jasny | [PDF](druk/przed/dlugi-light.pdf) | [PDF](druk/po/dlugi-light.pdf) |
| Długi ciemny | [PDF](druk/przed/dlugi-dark.pdf) | [PDF](druk/po/dlugi-dark.pdf) |

## Kontrole ujemne

Każda wykonała sekwencję PASS → FAIL z nazwanym powodem → PASS przez
`scripts/kontrola-ujemna.sh`, z potwierdzeniem zmiany bajtów oraz
przywrócenia MD5 i mtime. [Wyniki JSON](kontrole/):

- #732: usunięcie argumentu portu oraz osobno przywrócenie `pg_ctlcluster`;
- #748: wyłączenie korekty pustej strony;
- #749: przywrócenie `href="/home"`;
- #752: wyłączenie kontekstu przepisu w komponencie zdjęcia;
- #765: usunięcie importu arkusza druku, rzeczywisty ponowny build i druk.

Ograniczenie przyrządu: pole JSON `przywrocenie` zapisuje się przed trapem
i ma wartość „nie wykonane”, mimo późniejszego potwierdzenia w wyjściu.
Przy #732 pierwsza próba na dysku Windows wykryła utratę ułamka mtime;
czas przywrócono jawnie co do 100 ns i powtórzono kontrolę na izolowanej
kopii w systemie plików WSL. Przy #752 po mutacji trzeba było czyścić cache
Blade przed każdym testem: przywrócone starsze mtime nie unieważnia nowszej
skompilowanej mutacji. Po dodaniu `view:clear` pełny cykl przeszedł.

## Odtworzenie pomiarów

Przygotuj runtime wspólnym `przygotuj-runtime.sh gpt-zalegle` po zmianach.
W nim ustaw jawne parametry bazy jak na początku raportu, następnie:

```bash
bash tests/skrypty/check-postgres.sh
php artisan test --filter ListaRelacjiPoUsunieciuTest
php artisan test --filter OdrzuconeZdjeciePrzepisuTest
node scripts/offline-ponowienie.test.mjs
npm run build
PRINT_FIXTURES=1 php artisan test --filter WydrukPrzepisuFixtureTest
node scripts/druk-przepisu.mjs po
```

Skrypt drukowania zapisuje PDF-y oraz `pomiar.json` do
`output/playwright/druk765/po`. `przed` pomija asercje odbioru i służy tylko
do zapisu pomiaru bazowego, nie do zgłaszania gotowości poprawki.

## Kontrole końcowe i ograniczenia

Pint: 1157 plików, PASS. PHPStan: kod wyjścia 0. Build: PASS, również
72 pary kontrastu i 20 testów JavaScript uruchamianych przez build.
Końcowy pełny przebieg PHP z jawnym wykluczeniem opisanym niżej:
**4387 PASS, 83 534 asercje, 302,76 s**, kod wyjścia 0.

Pierwszy zakończony przebieg: 4386 PASS, 1 FAIL — istniejący
`SamodzielneEkranyMarkiTest` nadal wymagał `href="/home"` na ekranie offline.
Zaktualizowano tę asercję zgodnie z #749; zachowanie w przeglądarce sprawdza
niezależny scenariusz rzeczywistego service workera opisany wyżej.

Drugi zakończony przebieg: 4386 PASS, 1 FAIL —
`JobDostepnosciNieWolaAptaTest` wykrył niedozwoloną instalację pakietu
w nowym kroku druku. Usunięto instalację; krok wymaga gotowych `pdftotext`
i `pdfinfo` na runnerze i przy ich braku podaje instrukcję przygotowania.
Test strażnika po zmianie: 1 PASS, 7 asercji. Zdalnego CI nie uruchamiano.

`ProbaOdtworzeniaTest` pominięto jawnie, zgodnie z przekazaniem floty:
używa wspólnej bazy `kuking_zrodlo_proby_glowny`. Pierwszy pełny przebieg
zatrzymano przed dojściem do tej klasy; nie jest liczony jako zaliczony.
Grupa `dwa-polaczenia` pozostaje poza zwykłym przebiegiem zgodnie z phpunit.xml.
Nie wykonano całego `check.sh`, bo zawiera ten sam nieizolowany test oraz
dodatkowe przebiegi wymagające osobnych baz. Wykonane kroki podano osobno.

Wszystkie powyższe wyniki są własnymi pomiarami. Opisy issue były źródłem
scenariuszy, nie przejętym wynikiem testów. Nie wykonywano pomiarów produkcji,
pushu, PR-a, wdrożenia ani wysyłania wiadomości. Nie zmieniono schematu bazy.
Rollback: wycofanie lokalnych commitów, ponowny build; przy wycofaniu offline
należy nadać nową wersję cache SW, żeby klienci pobrali właściwy dokument.

Nie ma nierozstrzygniętej decyzji produktowej blokującej opisane poprawki.
Tryb druku bez zdjęć pozostałby osobną decyzją właściciela; tutaj zdjęć
nie usuwano. Wersja 0.68 wymaga uzgodnienia numeru przy szeregowym scalaniu,
jeśli inna gałąź zdąży wcześniej podnieść numer.
