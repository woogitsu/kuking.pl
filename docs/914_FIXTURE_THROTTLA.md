# #914 — świeży model w kontroli okna throttla

Źródło: `65327e69ddc3423279c7324ff20639ead64c6610` (`origin/main`,
potwierdzone przez `ls-remote`). Historia badanego pliku we wszystkich lokalnych
referencjach zawierała tylko `e65f297f` i `d291e4a6`, bez gotowej poprawki fixture.

`test_bez_wygasniecia_throttla_migawki_sa_identyczne` zapisywał znacznik wizyty
bezpośrednio w bazie, ale do `actingAs` przekazywał model ze starym `null`.
Tracker uznawał więc wizytę za pierwszą. Zapis w tej samej sekundzie maskował
błąd fixture, a przejście sekundy zmieniało pełną migawkę `users`.

Poprawka odświeża model po przygotowaniu bazy. Zegar jest jawnie zamrożony,
a przed żądaniem przesunięty o dwie sekundy: to nadal środek 15-minutowego okna,
ale zbędny zapis nie może już ukryć się pod identycznym czasem. Wszystkie
dotychczasowe asercje pozostają. Tracker, middleware i wykluczenia migawek
nie są zmieniane.

## Wykonana weryfikacja lokalna

- PostgreSQL `127.0.0.1:55439`, baza `kuking_flota_codex_914_fixture`, właściciel
  `kuking`; parametry i właściciel sprawdzone przed użyciem.
- Osobny runtime `/home/mateusz/flota/codex-914-fixture-run`, kopia `vendor`,
  bez dowiązań, bez kopiowania `node_modules`; środowiska #1016 i #1042 nietknięte.
- Cała klasa: **3 testy, 15 asercji, PASS**.
- `scripts/kontrola-ujemna.sh`: usunięcie wyłącznie `$admin->refresh();`
  daje **PASS → FAIL → PASS**. Przyczyna czerwieni to dokładnie
  `users[UUID].ostatnio_widziany_at` w pełnej migawce, nie błąd środowiska.
  Narzędzie porównało MD5 i mtime po przywróceniu pliku.
- Pint badanego pliku: PASS. Pełnego zestawu i CI nie uruchamiano.

Kontrolę można powtórzyć na izolowanej bazie:

```bash
php artisan test --filter=Sledztwo914PomiarPanoluOdwolanTest
bash scripts/kontrola-ujemna.sh --nazwa fixture-914-refresh \
  --plik tests/Feature/Sledztwo914PomiarPanoluOdwolanTest.php \
  --zamien '$admin->refresh();' --na '' \
  --oczekuj 'ostatnio_widziany_at' -- \
  php artisan test --filter=test_bez_wygasniecia_throttla_migawki_sa_identyczne
```

Rollback: cofnięcie tego commita przywraca wyłącznie poprzednie fixture i usuwa
ten opis. Nie ma migracji ani zmian danych produkcyjnych.
