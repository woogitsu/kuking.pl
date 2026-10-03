# Odbiór kolejnych poprawek N–U — 3 października 2026

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
