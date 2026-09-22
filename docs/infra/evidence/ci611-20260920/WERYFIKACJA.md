# Weryfikacja lokalna #611 / #614 — 20.09.2026

Podstawa: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
gałąź `gpt/ci-architektura`. Wszystkie wyniki w tej sekcji są własne;
przejęte pomiary produkcyjne oznaczono w [mapie](../../MAPA_CI_611.md)
i [uzgodnieniu architektury](../../PLAN_TECHNICZNY_614.md).

## Pomiary przed i po

| Kontrola | Wynik |
|---|---|
| Niezmienione drzewo, `testuj.sh gpt-ci-architektura --filter Ci` | PASS: 1548 testów, 11329 asercji, 198,50 s. Filtr obejmuje również nazwy metod spoza CI; nie jest pełnym zestawem. |
| Nowy test przed poprawką CI | Oczekiwany FAIL: 1 failed, 6 passed, 93 asercje. Przyczyną jest pełny checkout `port_marki`, nie awaria runtime. [Wyjście](test-przed.txt). |
| Ten sam test po poprawce | PASS: 7 testów, 105 asercji. [Wyjście](test-po.txt). |
| `vendor/bin/pint tests/Feature/PortMarkiMaWlasnaBramkeCiTest.php` | PASS: 1 plik. |
| Porównanie sparsowanego YAML z `git show 4c811cc7:.github/workflows/ci.yml` | PASS: po usunięciu dokładnie trzech słowników `with: {fetch-depth: 0}` struktury są identyczne. Wszystkie cztery YAML się parsują; CI ma 13 jobów, całość 20. |
| Płytki checkout i indeks | PASS: repozytorium kontrolne ma dwa commity; klon `--depth 1` ma jeden i `is-shallow-repository=true`. `git ls-files --error-unmatch` rozpoznaje istniejący plik, a brakujący odrzuca. |
| actionlint 1.7.7, cztery workflowy, `-shellcheck= -pyflakes=` | **FAIL odtworzony na bazie**: osiem uwag `property access ... string ... number` dla `job.services.postgres.ports[5432]`. Oryginalny plik daje identyczne osiem diagnoz. [Przed](actionlint-przed.txt), [po](actionlint-po.txt). Nie zgłaszamy zielonego actionlint ani przeprowadzonego ShellCheck/Pyflakes. |
| Pełny zwykły zestaw PHPUnit, poza `ProbaOdtworzeniaTest` | 4394 PASS, 1 FAIL, 83710 asercji, 481,95 s. Jedyny błąd: odnośnik do tego raportu, którego plik nie był jeszcze w kopii wykonawczej. [Końcówka przebiegu](pelny-zestaw-pierwszy.txt). |
| Po utworzeniu raportu i odświeżeniu runtime: odnośniki, YAML, port CI, smoke-test | **17 PASS, 176 asercji, 1,64 s**. Obejmuje naprawiony test odnośników. [Pełne wyjście](kontrole-koncowe.txt). Nie przedstawiamy poprzedniego pełnego przebiegu jako całego zielonego. |
| Kontrole ujemne nowych zabezpieczeń | **5/5**: osobno pełna historia w każdym z trzech jobów, brak checkoutu i brak pełnej historii w `zakres`. Każda mutacja rzeczywiście zmieniała plik i oblewała na oczekiwanej asercji. Bajty i mtime przywrócono; końcowo 7 PASS. [Wynik](kontrole-ujemne.txt). |
| `git diff --check` | PASS. |

## Środowisko i powtarzanie

Runtime przygotowany przez `_wspolne/przygotuj-runtime.sh`; fizyczne kopie
zależności, `.github` przeniesione do runtime, klucz aplikacji zapewniony
przez skrypt. Każda zmiana kodu została przeniesiona przed testem.
PostgreSQL wyłącznie `127.0.0.1:55439`, użytkownik `kuking`, baza
`kuking_flota_gpt-ci-architektura`. Nie używano SQLite ani wspólnego 5432.

Polecenia z Git Bash na Windows:

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-ci-architektura
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-ci-architektura --filter PortMarkiMaWlasnaBramkeCiTest
```

Pełny przebieg w powłoce WSL wywołuje `testuj.sh` z filtrem
`'^(?!.*ProbaOdtworzeniaTest)'`. Wyjątek wskazał użytkownik: klasa używa
wspólnej bazy źródłowej `kuking_zrodlo_proby_glowny`. Nie uruchomiono jej.
Grupa `dwa-polaczenia` jest osobnym zestawem wyłączonym przez `phpunit.xml`
i także nie była w tym pomiarze. Ta zmiana nie dotyka zachowania bazy.

Przy pierwszym przygotowaniu runtime rsync zgłosił dwa błędy odczytu PNG
„Cannot allocate memory”. Ponowne przygotowanie zakończyło się poprawnie.
Nie jest to wynik testu ani dowód braku pamięci hosta. Wcześniejsze próby
przekazania wieloczłonowego filtra przez WSL zawiodły na interpretacji `|`;
nie wliczono ich do żadnego wyniku PASS/FAIL aplikacji.

## Granice dowodu

- [Stan GitHub](github-stan.txt) pochodzi z własnych odczytów; nie zmieniano
  zmiennych, ochrony gałęzi ani reguł.
- Nie uruchomiono nowych GitHub Actions, `act`, obrazów Docker, pomiarów
  przeglądarkowych ani zdalnego wdrożenia. Porównanie struktury potwierdza
  zachowanie definicji kroków; nie jest pomiarem czasu na runnerze.
- Zgodnie z poleceniem: brak pusha, PR-a, wiadomości, operacji na produkcji,
  zmiany schematu i kasowania gałęzi/worktree.
- Zalecenia architektoniczne uzgodniono przez odczyt kodu, historii,
  dokumentów i zgłoszeń. Nie ponowiono produkcyjnych odbiorów innych autorów.
