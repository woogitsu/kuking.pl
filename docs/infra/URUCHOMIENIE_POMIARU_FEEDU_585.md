# Uruchomienie pomiaru feedu #585

Status: procedura lokalna w odbiorze. Wyniki i ograniczenia: [POMIAR_FEEDU_585.md](POMIAR_FEEDU_585.md).

## Środowisko

Osobna kopia wykonawcza Linux/WSL, PHP zgodne z composer.lock, Python3, program patch, zależności Composer oraz PostgreSQL18. Nie uruchamiać na produkcji ani w kopii obsługującej równocześnie serwer lub inne testy. Runner chwilowo zmienia prawdziwy FollowingFeed i przywraca go po pomiarze. flock chroni tylko przed drugim egzemplarzem tego runnera.

Baza musi już istnieć: kuking_585_benchmark na127.0.0.1:55439, właściciel kuking, strefa UTC. Nie używać portu5432. Generator nie usuwa ani nie zastępuje istniejących danych. Przy ponownym odbiorze zachować poprzednią bazę i dowody; nie wykonywać migrate:fresh na zbiorze, który ma pozostać.

## Kolejność

W osobnej powłoce ustaw PHP_BIN na bezwzględną ścieżkę PHP i przejdź do kopii wykonawczej. APP_KEY musi należeć do tej lokalnej instancji; nie kopiować klucza produkcji. Hasło bazy przekazać lokalnym środowiskiem, nie zapisywać go w raporcie.

```bash
set -euo pipefail
export APP_BASE_PATH="$PWD"
export APP_ENV=testing
export DB_CONNECTION=pgsql
export DB_HOST=127.0.0.1
export DB_PORT=55439
export DB_DATABASE=kuking_585_benchmark
export DB_USERNAME=kuking
export DB_URL=''
export CACHE_STORE=array
export SESSION_DRIVER=array
export MAIL_MAILER=array

"$PHP_BIN" artisan migrate --force
"$PHP_BIN" scripts/dane-pomiaru-feedu.php > /wybrany/nowy/katalog/fixture.json
"$PHP_BIN" scripts/wzbogac-dane-feedu.php > /wybrany/nowy/katalog/enrichment.json
"$PHP_BIN" scripts/media-pomiaru-feedu.php > /wybrany/nowy/katalog/media.json
"$PHP_BIN" scripts/sprawdz-dane-feedu.php
psql -h 127.0.0.1 -p 55439 -U kuking -d kuking_585_benchmark -c ANALYZE
python3 scripts/porownaj-feed.py --php "$PHP_BIN" \
  --manifest /wybrany/nowy/katalog/fixture.json \
  --output /wybrany/nowy/katalog/wynik-jit-default.json
```

Każdy etap musi zakończyć się kodem0 przed następnym. Puste JSON po błędzie nie jest manifestem. Ścieżki przykładowe trzeba zastąpić istniejącym nowym katalogiem; nie nadpisywać wcześniejszych wyników. Generator może trwać kilka minut z powodu fabryk kont i hashowania haseł.

Porównanie JIT odbywa się tylko dla procesów pomiarowych:

```bash
PGOPTIONS='-c jit=off' python3 scripts/porownaj-feed.py --php "$PHP_BIN" \
  --manifest /wybrany/nowy/katalog/fixture.json \
  --output /wybrany/nowy/katalog/wynik-jit-off.json
```

Po tym uruchomić wariant domyślny do kolejnego nowego pliku i porównać database_settings. Nie ustawiać ALTER SYSTEM ani zmiennych Railway w ramach tego testu.

## Kontrola wyniku

Sprawdzić liczbę prób (domyślnie36), niepuste i zgodne wiersze/liczniki/kursory, following_count, hashe źródeł, ustawienia PostgreSQL oraz przywrócenie pliku. Wynik zawiera kopię manifestu i source_manifest; nie zawiera env ani sekretów. Replay PDO i hydratacja są oddzielnymi próbkami po paginate, nie rozbiciem wcześniejszego czasu. Pomiar nie obejmuje sieci, pobierania zdjęć ani pełnego renderu strony.

Jeśli proces zostanie zabity sygnałem, który uniemożliwia finally, nie uruchamiać kolejnego benchmarku w ciemno. Sprawdzić FollowingFeed przeciwko baseline_sha256 z variants.json oraz zachowaną kopię kuking-feed-backup-* poza repo. Nie nadpisywać cudzych zmian. Raport nie może uznać takiego przebiegu za zakończony.
