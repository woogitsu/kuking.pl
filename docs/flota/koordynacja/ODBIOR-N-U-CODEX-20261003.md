# Odbiór kolejnych poprawek N–U — 3 października 2026

## Aktualizacja bramki CI — 3.10.2026, 09:50 UTC

Pełny przebieg `37109655500` starego heada `1bd18ae5a` zakończył się
odmową. Nie scalono tego PR-a. Trzy rzeczywiste przyczyny naprawiono:

- `96c9c779f`: dziecko `artisan test` w przyrządzie przeglądarkowym #2857
  jawnie używa `APP_ENV=testing`. Na własnym PostgreSQL 18 odtworzono FAIL
  przy odziedziczonym `local` i PASS 1/8 po zmianie tylko środowiska dziecka.
  CSRF aplikacji i testów nie zmieniono.
- `24320e388`: #2811 odczytuje dokładny JUnit swojej klasy i metody zamiast
  szukać JSON w zwykłym podsumowaniu PHPUnita. Odrzuca skip/error/obcy test
  i marker wyłącznie w logu. Mechanizm: 12 przypadków PASS i właściwa fizyczna
  kontrola; rzeczywisty PostgreSQL 18: PASS 1/15 → FAIL własnego markera
  `DOLACZENIE_2811_RYWAL_CZEKA_PRZED_MEDIA` → restore PASS 1/15.
- `6c3a2b5bc`: wzorzec usunięcia kursora zna też oba nowe własne markery #2856.
  Nadal odmawia obcej asercji, wyjątku i markera wyłącznie w logu. Przyrząd:
  37 PASS → cofnięcie wzorca FAIL właściwego markera → restore 37 PASS.
  Faktyczny mutant kontrolera oblał 4/8, mutant miary 1/8; oba dostały
  `POTWIERDZONA`, a po odtworzeniu cała klasa 8 PASS.
- `12235acfe`: filtr wyścigów obejmuje oba rzeczywiste importy przyrządu
  #2811, `kontrola_przyczyny.py` i `zawezenie_testow.py`. Stary skrypt oblał
  oba nowe przypadki tabeli; poprawiony zaliczył 45 przypadków i wszystkie
  fizyczne kontrole wyjść, w tym osobne usunięcie każdego importu.

Włączono zwykłym merge świeżą integrację C `0f5085e2` (zawiera małe P1 kont).
Wspólna kopia Linux/PHP 8.4/PostgreSQL 18.6, własna baza
`kuking_test_o_20261003`, host `127.0.0.1`, port `55488`, rola
`kuking_pg18_owner`: 31/6467 Feature, 12+7+37 Python, oba mutanty kursora,
pełna kontrola #2811 oraz pełny PHPStan zero. Źródła kontroli zachowane
co do bajtów i czasu modyfikacji. Kontrola zakresu to osobny pomiar skryptu
Bash, bez wykonywania kodu produktu.

**Pozostałe bramki:** normalny pełny push nowego heada, pełne terminalne CI
niedraftowego #2865 na aktualnej bazie, przegląd i scalenie do C, końcowy PR
wydania z CodeQL, CI push main oraz trzy Railway SUCCESS dokładnego SHA
zgodnego z `/wydanie` i `/health` 200. Naprawa środowiska dziecka sama nie
jest dowodem całego nowego przebiegu Chromium. Zachować ręczne kryteria
pilota 50+, prawa, kopii, R2/CDN i paneli.


## Zakres i kolejność

Istniejący PR #2865 zbiera odebrane poprawki N–U. Dawne gałęzie P/Q/R/T/U
zachowują historię i dowody; nie tworzymy dla tej samej pracy kolejnych PR-ów.
Osobna mała paczka P1 kont #2851/#2854/#2861/#2862 ma pierwszeństwo wydania.
Nowe prace #2847/#2872/#2873/#2875 należą do następnej paczki, nie tej głowy.

## Rzeczywisty odbiór zmian

Każda poprawka ma wcześniejszy przegląd aplikacji i test odtwarzający błąd,
fizyczną kontrolę ujemną oraz przywrócenie plików. Dokumenty wcześniejszych
odbiorów: [P](ODBIOR-P-CODEX-20261003.md),
[Q](ODBIOR-Q-CODEX-20261003.md), [R](ODBIOR-R-CODEX-20261003.md).

Dodatkowe trzy poprawki U (#2870, #2876, #2857) root sprawdził wspólnie na
własnym Linux/PG18, jawny host 127.0.0.1, port 55488, własna rola i baza
`kuking_test_2857_u20261003`, locale C.utf8:

- 132 testy i 2538 asercji PASS, całe wybrane klasy, także polskie szukanie
  w „Moich wpisach”, wydruk zeszytu i liczba sztuk;
- trzy właściwe fizyczne mutanty w rejestrze kontroli rzeczywiście oblały
  swoje markery; źródła przywrócone dokładnie, testy ponownie zielone;
- budowanie assetów PASS i pełny PHPStan bez błędów.

#2857: rzeczywisty Laravel HTML i Chromium zachowały wpisane pola oraz
FileList; kliknięcie wysłania zawierało nazwę i bajty pliku w multipart.
POST był przechwycony, więc ten odbiór nie dowodzi zapisu wykonania na
serwerze. Zastąpienie własnego tekstu wymaga jawnego wyboru, bez modułu
pozostaje widoczna instrukcja ręcznego skopiowania. Pomiar 320 CSS px
oraz repozytoryjna emulacja dużego tekstu przeszedł. Emulacji CSS zoom lub
pinch nie nazywamy ręcznym zoomem przeglądarki ani pomiarem telefonu.

#2876: cztery rzeczywiste pliki PDF A4 z odpowiedzi izolowanego Laravel
sprawdzono tekstowo i optycznie po renderowaniu Popplerem. Cały zeszyt i
wybór zachowują „24 szt. (pierogi)” bez porcji oraz osobne
„4 porcje · 12 szt. (bułki) · Czas: 30 min”. Opcje zdjęć i notatek działają;
etykiety na obejrzanych kartkach nie są ucięte. Zdjęcie w tym pomiarze
zastąpiono syntetycznym obrazem. To techniczny odbiór Chromium/PDF, nie
produkcja, fizyczna drukarka ani pilot 50+.

## Konkretna odmowa pełnej bramki

Sześć normalnych lokalnych pushów ujawniło dwa błędy przyrządów. Fixture
Git tymczasowej bazy dziedziczyła GIT_DIR z haka i zmieniała core.bare
wywołującego własnego registry. Filtr wyścigów pomijał rzeczywisty plik
`tests/skrypty/kontrola-negatywna-2783-cel.py` czytany przez job.
Root zatrzymał wyłącznie własne procesy tych czerwonych pushów, zachował
logi i kopie configów, naprawił tylko własne core.bare. HEAD-y pozostały
zgodne, drzewa czyste. Agent naprawia oba przyrządy w osobnej kopii.
Nie oznaczono tej pełnej bramki jako zielonej i nie anulowano CI GitHuba.

Po przyjęciu poprawki przyrządów wymagane są zwykły pełny pre-push,
pełne terminalne CI dokładnego heada PR #2865, merge z expectedHeadSha,
jedno końcowe wydanie, zielony push main, trzy Railway SUCCESS dokładnego
SHA i zgodne /wydanie oraz /health. Do tego czasu poprawki nie są wydane.

## Wycofanie i otwarte kryteria

Wycofanie kodu przywraca opisane błędy; nie cofamy zgód, historii,
poświadczeń ani semantycznych wyborów w bazie. Zmiany schematu z N zachowują
własne migracje, testy i opisane odmowy niebezpiecznego rollbacku.
Pilot 50+, rzeczywiste telefony, przegląd prawny i panelowe #2025/#2708/#2713
pozostają otwarte. Nie zamykać issues samym CI.


## Pełny lokalny hook: martwy odnośnik handoveru

Zwykły pełny pre-push na `05a0f8695d73285a8b80e533c5116786f59a22fb`
zakończył się kodem 1. Jedyny FAIL: `DokumentyMdNieMajaMartwychOdnosnikowTest`
wskazał odnośnik STAN do odbioru V, którego N–U celowo nie zawiera.
Nie wykonano pusha ani nie pominięto hooka. Odnośnik zastąpiono wyraźnym
opisem lokalizacji na osobnej gałęzi V; pozostała treść handoveru zachowana.
Pełny hook nowego heada należy uruchomić ponownie; wcześniejsza porażka
nie jest zielenią ani dowodem pełnego CI.
