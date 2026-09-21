# Sonda wdrożenia — wiarygodność pomiarów #805–#808

## Zakres

Naprawa oprzyrządowania, bez zmiany aplikacji, schematu bazy, DNS ani
konfiguracji Railway. Test `tests/Unit/SondaWdrozeniaTest.php` wykonuje cały
`scripts/sprawdz-wdrozenie.sh` z funkcją zastępującą curl oraz rzeczywisty
blok kroku „Usuń środowisko PR” z `preview.yml` z atrapą Railway CLI.
Test wymaga znalezienia dokładnie jednego bloku. Każdy przypadek sprawdza
kod procesu i wypisany werdykt; poprawne przypadki wymagają sukcesu.

## Kontrakt kontroli

- **#806:** kod HTTP i nagłówki pochodzą z jednego HEAD. Sukces wymaga
  poprawnego transportu, zamkniętego bloku nagłówków, HTTP 405 oraz
  CF-Cache-Status BYPASS/DYNAMIC. Nagłówki pośrednie proxy są odrzucane.
  HIT/MISS nadal są błędem; brak pomiaru jest jawnym „nie sprawdzono”
  i kończy sondę kodem 1. Diagnostyka podaje kod curl/HTTP, bez ciasteczek.
- **#807:** operator podaje oczekiwaną nazwę `SESSION_COOKIE` zgodną
  z efektywną konfiguracją celu. Nazwa porównywana jest dosłownie;
  samodzielny atrybut Secure — bez rozróżniania wielkości liter.
  Flaga innego ciasteczka, podciąg w nazwie/wartości ani `Secure=false`
  nie wystarczają. Wszystkie wystąpienia ciasteczka sesji muszą mieć flagę.
- **#808:** sonda sprawdza pierwszy skok 301/308 na HTTPS i dokładny host
  wskazany argumentem. Nie podąża za łańcuchem. Pętla, HTTP, obca domena,
  pusta lokalizacja i awaria transportu nie zaliczają kontroli (kod 1).
- **#805 (kontrakt po decyzji właściciela z 21.09.2026 — „trzecia droga”):**
  krok pyta `railway environment list --json`, **zanim** cokolwiek skasuje.
  Powód: Railway CLI zwraca **kod 1 dla każdego błędu** — brak środowiska
  wygląda identycznie jak wygasły token, brak uprawnień, ratelimit czy awaria
  sieci. Dawne `|| true` przy `delete` zamieniało więc awarię tokenu w zielony
  job, a płatne środowisko dalej stało. Cztery ścieżki kroku:
  1. środowiska **nie ma** na liście — komunikat po polsku, kod **0**,
     **bez „Gotowe.”** i bez dotknięcia `delete`;
  2. **jest** i `delete` się udaje — kod **0** z „Gotowe.”;
  3. **jest** i `delete` pada — kod CLI leci wprost do joba (`|| true`
     usunięte), **bez „Gotowe.”**, komunikat CLI przepuszczony;
  4. padła sama **lista** — też czerwień, inaczej ukrywalibyśmy ten sam błąd
     piętro wyżej.
  Nadal nie parsujemy tekstu błędu nieprzypiętej wersji CLI i nie przypisujemy
  kodom znaczeń dostawcy — rozstrzyga obecność nazwy na liście, nie kod.
  Nazwy wyjmuje `jq` (obecne na `ubuntu-latest` i na puli self-hosted);
  porażka `jq` jest błędem kroku, a nie cichym „nie ma czego usuwać”.

## Pomiar własny przed poprawką

Baza: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, czyste drzewo
`gpt/sonda-wdrozenia`. Dołożono wyłącznie test i atrapę, bez zmiany sondy
ani workflow: **22 oblane, 8 zaliczonych, 57 asercji**.

| Zgłoszenie | Własna czerwień przed poprawką | Własna kontrola dodatnia |
|---|---|---|
| #806 | timeout nagłówków po 405: fałszywe „nie jest cache'owany”, kod 0 | 405 + BYPASS: sukces |
| #807 | nazwa z `secure` bez atrybutu: fałszywe „ma flagę Secure”, kod 0 | właściwa sesja z Secure: sukces |
| #808 | 301 na obcą domenę: fałszywe „na apex”, kod 0 | 301/308 na HTTPS oczekiwanego hosta: sukces |
| #805 | atrapa CLI zwraca 73: „Gotowe.” i kod 0 | atrapa CLI zwraca 0: „Gotowe.” i kod 0 |

Pierwszy pomiar po poprawce: **30 zaliczonych, 120 asercji**.
Zestaw rozszerzono następnie o nagłówki proxy, DYNAMIC, ucięty blok przy
kodzie transportu 0, brak oczekiwanej nazwy sesji, dwa ciasteczka sesji
i userinfo w celu przekierowania. HIT/MISS używają ponad 100 kB nagłówków,
żeby pomiar obejmował pułapkę SIGPIPE z potokiem do `grep -q`.

## Końcowa weryfikacja własna

- Pełny zwykły przebieg: **4429 zaliczonych, 83 836 asercji, 392,84 s**.
  Pominięto wyłącznie `ProbaOdtworzeniaTest` zgodnie z instrukcją floty
  o wspólnej bazie tego testu. Grupa dwóch połączeń pozostaje wyłączona
  przez standardową konfigurację projektu.
- Po pełnym przebiegu odświeżono runtime z końcowymi zmianami diagnostyki,
  rozpoznawania hosta i rozszerzeniem testów: **39 zaliczonych, 159 asercji**
  w `SondaWdrozeniaTest`. Pełnej puli po tych wąskich zmianach nie powtarzano.
- `vendor/bin/pint --test`: **1156 plików zaliczonych**; po końcowych zmianach
  także `vendor/bin/pint tests/Unit/SondaWdrozeniaTest.php`: zaliczony,
  bez różnicy między plikiem runtime a worktree. Składnia Bash i `git diff --check`
  poprawne.
- Cztery osobne kontrole ujemne przez `scripts/kontrola-ujemna.sh`:
  **PASS → FAIL → PASS**. Każda mutacja miała dokładnie jedną podmianę,
  zmieniony MD5 i oczekiwaną przyczynę porażki w swojej grupie testów.
  Wyniki: [805](evidence/sonda805-808/805.json),
  [806](evidence/sonda805-808/806.json),
  [807](evidence/sonda805-808/807.json),
  [808](evidence/sonda805-808/808.json).

**Granica samych plików JSON:** wspólny przyrząd zapisuje je przed końcowym
`trap`, dlatego pole `przywrocenie` pozostaje „nie wykonane”. Nie jest ono
dowodem przywrócenia. Komunikat końcowego trap potwierdził porównanie MD5
i mtime; dodatkowo po wszystkich mutacjach samodzielnie porównano runtime
z nietkniętym przez mutacje worktree:

| Plik | MD5 obu kopii | mtime obu kopii (+02:00) |
|---|---|---|
| sonda | `1963dcf6fe8ce1e1a233f44618220e29` | `2026-09-20 19:14:37.742673800` |
| preview.yml | `4f24d57a6b9184e3e1db0843b105d0a7` | `2026-09-20 19:07:19.289117900` |

Powtórzenie (Git Bash, po przygotowaniu runtime skryptem floty):

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-sonda-wdrozenia --filter SondaWdrozeniaTest
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /home/mateusz/flota/gpt-sonda-wdrozenia-run/tests/skrypty/kontrola-sondy.sh
```

Własna baza pełnego przebiegu: `kuking_flota_gpt-sonda-wdrozenia`, użytkownik
`kuking`, host `127.0.0.1`, port `55439`. Nowe testy sondy nie korzystają
z bazy; przechodzą przez ten sam uzgodniony skrypt uruchomieniowy.

## Ograniczenia i wycofanie

Nie wykonano sondy przeciw produkcji ani prawdziwego Railway CLI.
Nie zmierzono konfiguracji produkcyjnej, uprawnień tokenu, kosztów preview,
ani końcowego stanu prawdziwego środowiska po usunięciu. Testy CLI mierzą
obsługę kodów procesu, a nie API Railway. Zgłoszenia przeczytano jako opis
problemu; wyniki w tabeli są własnymi pomiarami, nie przepisanymi dowodami.

Zmiana nie wymaga decyzji produktowej. Ewentualne automatyczne zaliczanie
braku środowiska wymaga osobnej weryfikacji kontraktu konkretnej wersji CLI.
Wycofanie: cofnąć lokalny commit poprawki przez `git revert`; brak migracji.
Przywróci to również opisane fałszywe sukcesy, więc nie zaliczać na tej
podstawie odbioru wdrożenia.

## Zmiana kontraktu #805 — 21 września 2026

Właściciel rozstrzygnął, że krok ma **sprawdzać istnienie środowiska przed
kasowaniem**. Ostatni akapit sekcji wyżej („automatyczne zaliczanie braku
środowiska wymaga osobnej weryfikacji kontraktu CLI”) został tą decyzją
zamknięty: wersję CLI rozstrzyga nie kod wyjścia, tylko lista środowisk.

Czym to NIE jest: nie jest to przypisanie kodom wyjścia znaczeń dostawcy.
Kod 1 dalej znaczy „coś padło” i nadal kończy job czerwienią — także wtedy,
gdy padła sama lista.

Zestaw przypadków testu przebudowany pod dwa wywołania CLI. Syntetyczne
kody 73 i 28 wypadły: w tym CLI nie istnieją, a przepisanie ich pod nowy krok
utrwalałoby fikcję, że krok rozpoznaje przyczynę po kodzie wyjścia.

Pomiary własne (runtime floty `gpt-sonda-wdrozenia-ODZYSK`, load average 3–6):

| Co | Wynik |
|---|---|
| `--filter SondaWdrozeniaTest` przed poprawką | **3 oblane, 36 zaliczonych, 153 asercje** |
| `--filter SondaWdrozeniaTest` po poprawce | **39 zaliczonych, 164 asercje** |
| to samo na gałęzi `gpt-cloudflare-cache` | **39 zaliczonych, 164 asercje** |
| `tests/skrypty/kontrola-sondy.sh` | 5 kontroli ujemnych POTWIERDZONYCH (PASS → FAIL → PASS) |

Kontrola dodatnia jest w `tests/skrypty/kontrola-sondy.sh`: dwie mutacje
(`|| true` przy `delete` oraz `|| true` przy `environment list`) muszą zapalić
test. Oczekiwana przyczyna mutacji #805 zmieniła się z kodu 73 na kod 1 —
inaczej kontrola przechodziłaby dlatego, że jej wzorzec nigdy nie pada.
