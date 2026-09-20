# Pomiary przygotowania #595 / #600 — 20 września 2026

Zakres: lokalna gałąź `gpt/rozbicie-uslug`, punkt wyjścia
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Nie jest to odbiór wdrożenia.
Instrukcja operacyjna: [rozdzielenie ról](ROZDZIELENIE_ROL_595_600.md).

## Pomiary własne

Testy PHP wykonano w izolowanym runtime WSL, na PostgreSQL
`127.0.0.1:55439`, baza `kuking_flota_gpt-rozbicie-uslug`, użytkownik `kuking`.
Runtime synchronizowano skryptem floty przed testami po zmianach kodu.

| Kontrola | Wynik |
| --- | --- |
| Nietknięte drzewo: `HarmonogramBezProcOpenTest` | 3 testy, 40 asercji, PASS |
| Nowy test regresyjny przed poprawką | 1 FAIL, 1 PASS, 10 asercji; drugi start podwoił wywołania `sprzataj-osierocone-zdjecia` i `policz-kolejki` |
| Test regresyjny po `onOneServer()` | 2 testy, 83 asercje, PASS |
| Pełny zestaw PHP z filtrem wyłączającym `ProbaOdtworzeniaTest` | 4395 testów, 83 775 asercji, PASS, 479,58 s |
| Pint całego drzewa | 1156 plików, PASS; wcześniej wykonano formatowanie nowych testów |
| `bash -n docker/entrypoint.sh` | PASS |
| `tests/skrypty/entrypoint-nadzor.sh` | 10 kontroli, PASS |
| Końcowe `npm run build` | 27 testów skryptów, PASS; build Vite PASS |
| Testy rzeczywistego manifestu Railway w powyższym buildzie | 7 testów: produkcja, staging, preview, drugi web, media, oba warianty i powrót do pojedynczego `all` |
| `git diff --check` | PASS |

Pełny zestaw PHP poprzedzał ostatnie rozszerzenie przełączników stagingu
w manifeście. Kod PHP później się nie zmienił; końcowy manifest przeszedł
ponownie wszystkie testy skryptów i build. Po kontrolach ujemnych ponownie
potwierdzono zgodność plików runtime z katalogiem roboczym przez `cmp`.

Test schedulera używa rzeczywistych 19 definicji z `routes/console.php`,
świeżych obiektów harmonogramu oraz `ScheduleRunCommand`, zamrożonego czasu
i blokad PostgreSQL. Podmienia wykonanie komend domenowych licznikiem:
nie wysyła listów i nie uruchamia nocnego sprzątania. Sprawdza dwa kolejne
starty w tej samej minucie oraz poprawne wykonanie w następnym terminie.
To nie jest pomiar dwóch jednoczesnych procesów ani dowód dokładnie jednego
skutku biznesowego po awarii. Osobne połączenie blokad wskazuje tę samą
izolowaną bazę; własny prefiks kluczy jest usuwany po teście.

## Kontrole ujemne i odtworzenie

- [Scheduler — surowy wynik](evidence/role595/scheduler-negative.json):
  PASS → usunięcie `onOneServer()` → FAIL z oczekiwaną przyczyną
  „Drugi scheduler” → odtworzenie → PASS.
- [Media — surowy wynik](evidence/role595/media-negative.json):
  PASS → przywrócenie `media` do kolejki workera ogólnego przy osobnym
  workerze zdjęć → FAIL z oczekiwaną przyczyną → odtworzenie → PASS.

Surowych raportów nie poprawiano: ich pole `przywrocenie` ma wartość
`nie wykonane`, chociaż dalszy komunikat końcowego trapu narzędzia potwierdził
odtworzenie MD5 i czasu modyfikacji. Samo to pole JSON nie dowodzi odtworzenia.
Niezależna kontrola bajtów po operacji jest zapisana w
[potwierdzeniu MD5](evidence/role595/restoration.json); późniejsza synchronizacja
runtime i `cmp` również potwierdziły zgodność obu źródeł. Nie zmieniano
narzędzia kontroli ujemnej w ramach tego zadania.

## Dane przejęte i granice

[pomiar cudzy: issue #598, pomiary z 17 i 19 września 2026]
`max_connections=500`, rezerwa 3, dostępne 497; FrankenPHP ma
`num_threads=4`, `max_threads=4`. Na tych założeniach wyliczono budżety
16 / 24 / 18 / 26 dla podstawy / drugiego web / media / obu zmian,
z nakładaniem wdrożeń i rezerwą operacyjną. Wyliczenie nie jest pomiarem
rzeczywistej liczby aktywnych połączeń ani zgodą na skalowanie.
Źródło: [#598](https://github.com/woogitsu/kuking.pl/issues/598).

Nie uruchomiono `ProbaOdtworzeniaTest`: użytkownik jawnie dopuścił pominięcie
ze względu na wspólną bazę próby. Domyślny zestaw PHPUnit nie obejmuje grupy
`dwa-polaczenia`; nie deklarujemy jej wykonania. Nie uruchamiano SQLite.

Nie wykonano Railway plan/apply, wdrożenia staging/produkcja, zmian zmiennych,
pomiaru bieżącego szczytu połączeń, kosztu ani czasu wycofania. Nie wykonano
push, PR ani zamknięcia zgłoszeń. Te czynności są poza zakresem autoryzacji.
10–20 minut powrotu do `all` w instrukcji jest szacunkiem z gotowym obrazem,
który trzeba zastąpić pomiarem próby stagingowej. PgBouncer nie jest dodany:
przejęte liczby nie uzasadniają jego kosztu i złożoności.
