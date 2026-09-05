#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — entrypoint kontenera
# =============================================================================
#  Jeden obraz, cztery role. Rolę wybiera pierwszy argument albo APP_ROLE:
#
#    web        — serwer HTTP (FrankenPHP/Caddy). Tylko ten ma domenę publiczną.
#    worker     — php artisan queue:work (przetwarzanie zdjęć, maile, eksporty)
#    scheduler  — php artisan schedule:work (Laravel scheduler, co minutę)
#    all        — web + worker + scheduler w jednym kontenerze.
#                 UŻYWAĆ TYLKO na staging/preview, żeby nie płacić za 3 serwisy.
#                 NIGDY na produkcji: jeden crash zabija wszystko, a skalowanie
#                 web pociągnęłoby za sobą duplikaty schedulera.
#
#  Użycie:  kuking-entrypoint web | worker | scheduler | all
# =============================================================================

set -Eeuo pipefail

ROLE="${1:-${APP_ROLE:-web}}"
PORT="${PORT:-8080}"

log() { printf '[entrypoint] %s\n' "$*" >&2; }
die() { printf '[entrypoint] BŁĄD: %s\n' "$*" >&2; exit 1; }

# -----------------------------------------------------------------------------
# 1. Walidacja wstępna — lepiej padnąć czytelnie tu, niż zwrócić białą stronę.
# -----------------------------------------------------------------------------
[[ -f /app/artisan ]] || die "brak /app/artisan — obraz zbudowany niepoprawnie"

if [[ -z "${APP_KEY:-}" ]]; then
  die "APP_KEY jest pusty. Wygeneruj go raz: 'php artisan key:generate --show' \
i wklej jako zmienną środowiskową w Railway. Bez APP_KEY nie da się odszyfrować \
sesji ani ciasteczek — aplikacja wywali 500 na każdym requeście."
fi

if [[ -z "${DB_URL:-}${DATABASE_URL:-}" ]]; then
  log "OSTRZEŻENIE: ani DB_URL, ani DATABASE_URL nie jest ustawione."
  log "             Sprawdź referencję do serwisu Postgres w zmiennych Railway."
fi

# -----------------------------------------------------------------------------
# 2. Katalogi scratch. Filesystem jest ulotny, więc po każdym restarcie
#    odtwarzamy strukturę storage/. Zdjęcia trwałe siedzą w R2, nie tutaj.
# -----------------------------------------------------------------------------
mkdir -p \
  /app/storage/framework/cache/data \
  /app/storage/framework/sessions \
  /app/storage/framework/views \
  /app/storage/logs \
  /app/bootstrap/cache

# -----------------------------------------------------------------------------
# 3. Cache Laravela — w RUNTIME, nie w buildzie.
#
#    `php artisan optimize` = config:cache + event:cache + route:cache +
#    view:cache. Robimy to tutaj, bo config:cache zapisuje AKTUALNE wartości
#    env() do pliku. W buildzie Railway nie ma jeszcze sekretów produkcyjnych,
#    więc zapiekłby puste DB_URL i klucze R2.
#
#    Najpierw czyścimy, żeby restart po zmianie zmiennej faktycznie ją podniósł.
# -----------------------------------------------------------------------------
log "rola=${ROLE} env=${APP_ENV:-?} port=${PORT}"
log "przebudowa cache konfiguracji..."

# Czyszczenie plikowe — nie dotyka bazy, więc musi się udać.
php /app/artisan config:clear --no-interaction >/dev/null
php /app/artisan route:clear  --no-interaction >/dev/null
php /app/artisan view:clear   --no-interaction >/dev/null
php /app/artisan event:clear  --no-interaction >/dev/null

# -----------------------------------------------------------------------------
#  `cache:clear` JEST INNY: przy CACHE_STORE=database uderza w tabelę `cache`.
#
#  Wcześniej stało tu `optimize:clear`, które woła cache:clear w środku.
#  Przy `set -Eeuo pipefail` wyjątek z bazy kończył cały skrypt, więc kontener
#  padał — i wstawał, i padał, w pętli. Zaobserwowane na produkcji przy
#  pierwszym wdrożeniu, zanim wykonały się migracje:
#
#      SQLSTATE[42P01]: Undefined table: relation "cache" does not exist
#
#  Aplikacja nie wstawała nawet po to, żeby pokazać, co jest nie tak.
#  Healthcheck nie miał czego odpytać, a w panelu było samo „CRASHED".
#
#  Niedostępny cache NIE JEST powodem, żeby nie uruchomić serwisu. Cache jest
#  z definicji odtwarzalny — najgorsze, co się stanie, to wolniejsze pierwsze
#  żądania. Dlatego to jedno polecenie ma prawo się nie udać, ale musi
#  o tym GŁOŚNO powiedzieć w logu.
# -----------------------------------------------------------------------------
if ! php /app/artisan cache:clear --no-interaction >/dev/null 2>&1; then
  log "OSTRZEŻENIE: nie udało się wyczyścić cache aplikacji."
  log "  Najczęstsza przyczyna: brak tabeli 'cache', czyli niewykonane migracje."
  log "  Startuję dalej — cache jest odtwarzalny, a serwis ma wstać i dać się zdiagnozować."
fi

# `optimize` zostaje BEZ tolerancji na błąd. Tu jest odwrotnie niż wyżej:
# nieudane zapieczenie konfiguracji, tras i widoków znaczy, że aplikacja
# naprawdę nie działa. Wtedy kontener MA paść, żeby healthcheck zatrzymał
# deploy, zamiast wpuszczać ruch na coś zepsutego.
php /app/artisan optimize --no-interaction

# storage:link tworzy public/storage → storage/app/public.
# Przy FILESYSTEM_DISK=r2 nie jest potrzebny, ale nic nie kosztuje i ratuje
# sytuację, gdy ktoś zapisze coś na dysk lokalny.
php /app/artisan storage:link --no-interaction >/dev/null 2>&1 || true

# -----------------------------------------------------------------------------
# 4. Graceful shutdown.
#    Railway wysyła SIGTERM przy deployu/restarcie. Chcemy, żeby:
#      * Caddy dokończył obsługiwane requesty (robi to sam),
#      * queue:work dokończył bieżący job i nie porzucił go w połowie
#        przetwarzania zdjęcia (do tego potrzebne jest rozszerzenie pcntl —
#        jest w obrazie).
#    Dla roli "all" musimy przekazać sygnał dzieciom ręcznie.
# -----------------------------------------------------------------------------
CHILD_PIDS=()

shutdown() {
  log "otrzymano sygnał zatrzymania — zamykam procesy potomne..."
  for pid in "${CHILD_PIDS[@]:-}"; do
    [[ -n "${pid}" ]] && kill -TERM "${pid}" 2>/dev/null || true
  done
  wait || true
  log "zamknięte."
  exit 0
}

# -----------------------------------------------------------------------------
# 5. Uruchomienie roli
# -----------------------------------------------------------------------------
start_web() {
  # FrankenPHP nasłuchuje na tym, co poda SERVER_NAME. Railway wstrzykuje PORT
  # i używa tej samej wartości do healthchecku, więc muszą się zgadzać.
  export SERVER_NAME=":${PORT}"
  log "start FrankenPHP na ${SERVER_NAME}"
  exec frankenphp run --config /etc/frankenphp/Caddyfile
}

start_worker() {
  # --max-time=3600   → worker sam się kończy po godzinie; Railway go wskrzesza.
  #                     Zapobiega wyciekom pamięci w długożyjącym PHP.
  # --max-jobs=500    → to samo, ale liczone jobami.
  # --memory=384      → zabij workera, gdy przekroczy 384 MB (limit RAM serwisu).
  # --tries=3         → 3 próby, potem failed_jobs; ProcessUploadedImage musi
  #                     być idempotentny (patrz docs/MEDIA_PIPELINE.md).
  # --backoff=10,60,300 → rosnące opóźnienie między próbami.
  # --queue           → kolejność priorytetów: interakcje użytkownika przed
  #                     ciężkim przetwarzaniem obrazów.
  # Worker dostaje wyższy limit pamięci niż web — dekodowanie zdjęcia 24 Mpx
  # w gd potrzebuje ~4 bajty na piksel. php.ini nie umie wartości domyślnych,
  # więc podajemy to flagą -d.
  log "start queue:work (memory_limit=${PHP_WORKER_MEMORY_LIMIT:-512M})"
  exec php -d "memory_limit=${PHP_WORKER_MEMORY_LIMIT:-512M}" /app/artisan queue:work \
    --queue="${QUEUE_NAMES:-high,default,media,low}" \
    --tries="${QUEUE_TRIES:-3}" \
    --backoff="${QUEUE_BACKOFF:-10,60,300}" \
    --max-time="${QUEUE_MAX_TIME:-3600}" \
    --max-jobs="${QUEUE_MAX_JOBS:-500}" \
    --memory="${QUEUE_MEMORY:-384}" \
    --sleep=1 \
    --no-interaction
}

start_scheduler() {
  # schedule:work zamiast crona systemowego ani Railway Cron:
  #   * Railway Cron ma minimalną granulację 5 minut, a Laravel scheduler
  #     musi być odpytywany CO MINUTĘ, żeby everyMinute()/everyFiveMinutes()
  #     działały zgodnie z definicją.
  #     Źródło: https://docs.railway.com/cron-jobs (sekcja "Frequency")
  #   * schedule:work to oficjalny, długożyjący odpowiednik crona w Laravelu.
  # WAŻNE: dokładnie 1 replika. Dwie repliki = podwójne maile z digestem.
  log "start schedule:work"
  exec php /app/artisan schedule:work --no-interaction
}

case "${ROLE}" in
  web)
    start_web
    ;;

  worker)
    start_worker
    ;;

  scheduler)
    start_scheduler
    ;;

  all)
    log "tryb ALL (staging/preview) — web + worker + scheduler w jednym kontenerze"
    trap shutdown SIGTERM SIGINT

    php /app/artisan queue:work \
      --queue="${QUEUE_NAMES:-high,default,media,low}" \
      --tries=3 --max-time=3600 --memory=256 --sleep=1 --no-interaction &
    CHILD_PIDS+=("$!")

    php /app/artisan schedule:work --no-interaction &
    CHILD_PIDS+=("$!")

    export SERVER_NAME=":${PORT}"
    frankenphp run --config /etc/frankenphp/Caddyfile &
    CHILD_PIDS+=("$!")

    # Jeśli PADNIE KTÓRYKOLWIEK proces, kończymy cały kontener — Railway
    # zrestartuje go zgodnie z restart policy. Lepsze niż cichy kontener
    # bez workera, który zdaje healthcheck.
    wait -n
    log "jeden z procesów potomnych zakończył się — zamykam kontener"
    shutdown
    ;;

  # Role pomocnicze, uruchamiane ręcznie przez `railway ssh` albo lokalnie.
  migrate)
    log "migracje (uruchomienie ręczne)"
    exec php /app/artisan migrate --force --no-interaction
    ;;

  shell)
    exec bash
    ;;

  *)
    die "nieznana rola '${ROLE}'. Dozwolone: web, worker, scheduler, all, migrate, shell"
    ;;
esac
