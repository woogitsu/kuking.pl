# Weryfikacja lokalna — 20 września 2026

Własne uruchomienia na izolowanym runtime
`/home/mateusz/flota/gpt-ai-piloty-run`, z kopii gałęzi `gpt/ai-piloty`.
Przed testami uruchomiono wskazany przez właściciela helper przygotowania.
PostgreSQL: `127.0.0.1:55439`, baza `kuking_flota_gpt-ai-piloty`.

| Kontrola | Wynik |
|---|---|
| Nietknięte drzewo: SearchTest | 12 testów, 34 asercje, PASS |
| Nietknięte drzewo: SzukajWidocznoscTest | 9 testów, 24 asercje, PASS |
| AiPilotContractTest | 30 przypadków, 86 asercji, PASS |
| AiPilotSearchBenchmarkTest | 2 testy, 7 asercji, PASS |
| Cały zestaw z jawnym wyłączeniem poniżej | **4425 testów, 83 785 asercji, PASS**, 412,40 s |
| Kontrole ujemne: pokrycie, pola, bajty, budżet | Każda PASS → oczekiwany FAIL → PASS |
| Formatowanie nowych plików PHP: vendor/bin/pint | 7 plików, wykonano; poprawiono styl w 3 |
| Końcowe vendor/bin/pint --test w całym runtime | 1162 pliki, PASS |

Z całego zestawu wyłączono **ProbaOdtworzeniaTest**, zgodnie z wyraźną
instrukcją właściciela dotyczącą wspólnej bazy `kuking_zrodlo_proby_glowny`.
Nie uruchamiano tego testu i nie przypisuje się mu własnego wyniku.
Nie było innych pominięć ani porażek w wykonanym zestawie.

Polecenie helpera: `testuj.sh gpt-ai-piloty --exclude-filter ProbaOdtworzeniaTest
--compact --log-junit /mnt/c/Users/matma/Documents/kuking-flota/gpt-ai-piloty/storage/ai-pilots/full-suite.xml`.
JUnit potwierdza: tests=4425, assertions=83785, errors=0, failures=0,
skipped=0. Wyłączony filtrem test nie jest liczony jako skipped przez JUnit.
Czas sumowany w XML: 410,608649 s, czas końcowego raportu konsoli: 412,40 s.

Surowy JUnit pozostaje lokalnie w `storage/ai-pilots/full-suite.xml`.
SHA-256: `fd952ea5e3e20cb18e1159ee981977c13f76375e473a9359a2dd5001a31a05b0`.
Zwięzłe dowody kontroli ujemnych: `evidence/control-*.json`;
pełny przebieg powtórzenia: `evidence/controls-output.txt`.

Brak zmian schematu, aplikacji i assetów; nie wykonywano wdrożenia,
produkcyjnej migracji ani pomiaru w produkcyjnej bazie. Żądania do modelu:
zero. Weryfikacja nie rozstrzyga jakości modelu ani użyteczności dla ludzi.
