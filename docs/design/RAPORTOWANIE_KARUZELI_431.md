# Naprawa końcowego JSON po CI540

## Aktualizacja odbioru — 14 września 2026

Pakiet PR #540 jest scalony i wdrożony jako `4c537b2`. CI PR i main: 10/10 success; Railway 6439273567 i Deploy 34859662013: success. [Potwierdzony odbiór produkcji Alfa 0.27](ODBIOR_PRODUKCJI_ALFA_027.md) rozdziela ogląd produkcji od lokalnych prób oraz opisuje ograniczenia. Poniższe informacje o przygotowaniu i pierwszym CI są historią prac, nie bieżącym statusem.


Problem z86ff08d: osiem wariantów karuzeli przechodziło, ale lokalny `const wynikiKaruzeli` znikał po try. Końcowy writer nadal używał nieistniejącego `karuzelaBezJs`, więc `storage/dostepnosc.json` nie powstawał.

Poprawka obejmuje trzy pliki:

- scripts/dostepnosc.mjs
- scripts/fixtures/karuzela-mieszana.mjs
- scripts/fixtures/karuzela-raport.test.mjs (nowy)

Klucz JSON `karuzelaBezJs` pozostaje. Zawiera teraz `wyniki` i `blad`. Wspólny bufor żyje poza try; każdy rozpoczęty wariant trafia do niego przed otwarciem kontekstu przeglądarki. Zakończony ma status ok i pełne kroki, przerwany status blad oraz przyczynę. Warianty ukończone przed błędem nie znikają. Komplet wymaga dokładnie8 zakończonych wariantów;7 lub dowolny przerwany oznacza kod1. Nie znaleziono konsumenta starego kształtu pól poza przechowywaniem artefaktuCI.

Nowa regresja uruchamia rzeczywisty blok pomiaru i cały rzeczywisty writer z dostepnosc.mjs w VM. Zapisuje i odczytuje prawdziwy plik JSON. Atrapy obejmują kosztowne pomiary oraz pozostałe sekcje raportu, nie serializację. Na starcie dostepnosc.mjs uruchamiane jest node --test, więc istniejące check.sh i CI nie omijają regresji; trwa około0.3s, bez bazy/przeglądarki.

Cztery przypadki PASS: pełne8, przerwanie po części wyników, niepełne7 bez wyjątku, rozpoczęty wariant zachowany przez prawdziwy moduł po awarii tworzenia kontekstu. Trzy fizyczne negatywy źródła, każdy kod1: źródło86ff08d odtwarza dokładnie `karuzelaBezJs is not defined`; writer podmieniony na pusty wynik oblewa porównanie; usunięcie zapisu rozpoczętego wariantu oblewa test prawdziwego modułu. Po każdym przywróceniu4/4PASS, MD5 i mtime identyczne. Kopie `/tmp/kuking-report431-backup-tf_7cjsh/`; pełne dane w negative-results.json.

Dodatkowe wąskie wykonanie Chromium153 na własnej bazie kuking_proof431: rzeczywiste8 wariantów/40 stanów przez poprawiony moduł i rzeczywisty końcowy writer PASS (`real.log`, `real-final.json`). Następnie rzeczywiste pierwsze2 warianty i kontrolowana odmowa otwarcia trzeciego kontekstu: zapis2 zakończonych i1 przerwanego, kod1 (`partial-real.log`, `partial-real-final.json`). Tych JSON nie należy przedstawiać jako pełnego odbioru a11y: tylko sekcja karuzeli zawiera wykonany pomiar przeglądarkowy, pozostałe sekcje są kontrolowanymi danymi testu integracji.

Składnia obu modułów i git diff --check PASS. Pełnego PHP, pełnego a11y ani portu nie uruchamiano. Własne fixture posprzątane, serwer zamknięty. Brak zmian PHP/CSS/Blade, commitów/push. Kopia wykonawcza samej regresji `/tmp/kuking-report431-7hOmzb` oraz wcześniejsza kopia aplikacji431 pozostają dostępne.
ROOT ponowił cztery regresje integracji na końcowych źródłach: 4/4 PASS. Pierwszy CI: https://github.com/woogitsu/kuking.pl/actions/runs/34850145604, job 103995791493; wynik failure z ReferenceError. Nie traktujemy tego przebiegu jako odbioru dostępności.
