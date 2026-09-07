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
# 0. ZEJŚCIE Z ROOTA — ale dopiero PO przygotowaniu woluminu.
#
#  Kontener startuje jako root wyłącznie po to, żeby przekazać katalog
#  z plikami użytkowników na własność `www-data`, i natychmiast schodzi
#  na `www-data`. Aplikacja NIE działa jako root.
#
#  DLACZEGO NIE `USER www-data` W DOCKERFILE
#  Railway montuje świeży wolumin jako `root:root`. Obraz startujący od razu
#  jako `www-data` nie ma wtedy prawa nawet założyć podkatalogu — `mkdir`
#  pada na „Permission denied", `set -e` zabija start, kontener wpada w pętlę
#  i CAŁA STRONA znika. Dokładnie to się stało przy podpinaniu woluminu:
#  produkcja poszła w 404, choć problem dotyczył wyłącznie zdjęć.
#
#  Kolejność jest tu jedyną rzeczą, która się liczy: najpierw `chown`, potem
#  zejście z uprawnień, dopiero na końcu cokolwiek innego.
# -----------------------------------------------------------------------------
if [[ "$(id -u)" == "0" ]]; then
  mkdir -p /app/storage/app/public /app/storage/app/private

  # Bez `-R`: pliki w środku i tak zapisał `www-data`, a rekurencja po
  # woluminie z tysiącami zdjęć wydłużałaby każdy start kontenera.
  chown www-data:www-data \
    /app/storage/app \
    /app/storage/app/public \
    /app/storage/app/private

  # `/app/public` też, i to nie jest dokładka „na wszelki wypadek".
  # `storage:link` zakłada w tym katalogu symlink `storage`. Katalog przyszedł
  # z obrazu jako `root:root`, więc `www-data` nie miał prawa nic w nim
  # utworzyć — link nie powstawał, a KAŻDE zdjęcie zwracało 404 przy stronie
  # oddającej HTTP 200. Awaria była niewidoczna dla monitoringu i widoczna
  # dla człowieka jako ikona zepsutego obrazka.
  #
  # Bez `-R`: zmieniamy właściciela samego katalogu, a nie assetów w środku.
  chown www-data:www-data /app/public

  if command -v setpriv >/dev/null 2>&1; then
    exec setpriv --reuid=www-data --regid=www-data --clear-groups "$0" "$@"
  fi

  # Świadomie NIE przerywamy startu. Strona działająca z nadmiarem uprawnień
  # jest lepsza niż strona, której nie ma — a ten komunikat jest na tyle
  # głośny, żeby nie został przeoczony.
  log 'OSTRZEŻENIE: brak setpriv w obrazie — aplikacja zostaje jako root.'
  log "  To jest nadmiar uprawnień, nie awaria. Do naprawy w Dockerfile."
fi

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

# -----------------------------------------------------------------------------
#  DYSK LOKALNY NA PLIKI UŻYTKOWNIKÓW
#
#  Katalogi MUSZĄ powstać PRZED `storage:link`. Symlink wskazujący na
#  nieistniejący katalog jest martwy, a serwer zwraca wtedy 404 na każdy plik
#  — mimo że `storage:link` „się udał".
#
#  Dokładnie to zdarzyło się na produkcji: wgrane zdjęcie przetwarzało się
#  poprawnie (ProcessUploadedImage kończył się DONE), a w interfejsie była
#  ikona zepsutego obrazka. `/storage/cokolwiek` zwracało 404, podczas gdy
#  `/build/manifest.json` dawało 200 — czyli serwer działał, tylko ta jedna
#  ścieżka prowadziła donikąd.
#
#  Wcześniej sekcja wyżej tworzyła `storage/framework/*` i `storage/logs`,
#  ale nie `storage/app/*`, bo projekt zakłada docelowo R2. Założenie jest
#  słuszne, tylko dopóki R2 nie działa, dysk lokalny musi być sprawny.
#
#  `private` to korzeń dysku `local` w Laravelu 11+ (oryginały zdjęć, audyt
#  A02), `public` — dysku `public` (warianty publikowane na stronie).
#
#  WOLUMIN, A NIE DYSK KONTENERA
#
#  `/app/storage/app` jest punktem montowania woluminu Railway. Bez niego
#  katalog żyje na dysku kontenera, który Railway kasuje przy KAŻDYM wdrożeniu
#  i restarcie — i dokładnie to się stało: wszystkie wgrane zdjęcia przepadły,
#  a strona dalej zwracała HTTP 200, bo baza była cała. Widać to było tylko
#  jako połamane obrazki.
#
#  Kontener chodzi jako `www-data` (Dockerfile), a świeży wolumin bywa
#  zamontowany jako `root:root`. Wtedy `mkdir` poniżej NIE MA PRAWA zapisu.
#  Z `set -e` wywaliłoby to cały start i dałoby 502 zamiast działającej strony
#  bez wgrywania zdjęć — czyli awaria większa niż problem. Dlatego sprawdzamy
#  osobno i mówimy wprost, co jest nie tak.
# -----------------------------------------------------------------------------
mkdir -p /app/storage/app/public /app/storage/app/private 2>/dev/null || true

if [[ ! -w /app/storage/app ]] \
  || [[ ! -d /app/storage/app/public ]] \
  || [[ ! -d /app/storage/app/private ]]; then
  log "OSTRZEŻENIE: /app/storage/app nie jest zapisywalne dla $(id -un)."
  log "  Wgrywanie zdjęć będzie padać, a już wgrane będą zwracać 404."
  log "  Najczęstsza przyczyna: świeży wolumin Railway zamontowany jako root,"
  log "  podczas gdy obraz chodzi jako www-data (USER w Dockerfile)."
  log "  Sprawdź stan przez /health — pole checks.media mówi, co dokładnie padło."
  log "  Nie zatrzymuję startu: strona bez wgrywania zdjęć jest lepsza niż 502."
fi

# Błąd NIE jest już połykany — ale samo „nie zadziałał" też okazało się
# za mało. Log mówił, ŻE się nie udało, i nie mówił DLACZEGO, więc przyczyna
# (brak prawa zapisu do /app/public) wyszła na jaw dopiero z `/health`,
# długo po wdrożeniu. Wyjście polecenia idzie teraz do logu.
if ! WYNIK_LINKU="$(php /app/artisan storage:link --no-interaction 2>&1)"; then
  log "OSTRZEŻENIE: storage:link nie zadziałał — pliki z dysku lokalnego będą zwracać 404."
  log "  Powód podany przez Laravel: ${WYNIK_LINKU//$'\n'/ }"
  log "  Nie zatrzymuję startu: przy FILESYSTEM_DISK=r2 ten link nie jest potrzebny."
fi

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

# Kod wyjścia jest ARGUMENTEM, bo od niego zależy, czy Railway wskrzesi
# kontener. Zatrzymanie sygnałem (deploy, skalowanie) to kod 0 — wszystko
# w porządku, nie restartuj. Śmierć serwera WWW to kod 1 — to jest awaria
# i kontener MA wrócić. Wcześniej obie sytuacje kończyły się zerem, więc
# awaria wyglądała dla Railway jak zaplanowane wyłączenie.
shutdown() {
  local kod="${1:-0}"
  log "zamykam procesy potomne..."
  for pid in "${CHILD_PIDS[@]:-}"; do
    [[ -n "${pid}" ]] && kill -TERM "${pid}" 2>/dev/null || true
  done
  wait || true
  log "zamknięte (kod ${kod})."
  exit "${kod}"
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

# -----------------------------------------------------------------------------
#  NADZORCA PROCESU, KTÓRY MA PRAWO SIĘ SKOŃCZYĆ
#
#  AWARIA 5–6 WRZEŚNIA 2026, 3,5 GODZINY NIEDOSTĘPNOŚCI — przeczytaj, zanim
#  uprościsz cokolwiek poniżej.
#
#  `queue:work --max-time=3600` kończy się CELOWO po godzinie i wychodzi
#  z kodem 0. Tak działa Laravel: długożyjący proces PHP puchnie w pamięci,
#  więc worker planowo popełnia samobójstwo i ma zostać wskrzeszony.
#
#  Rola `all` czekała na potomków przez `wait -n`, czyli „skończył się
#  KTÓRYKOLWIEK → zamykam kontener". Dokładnie godzinę po wdrożeniu worker
#  zrobił to, do czego został zaprogramowany, i zabrał ze sobą serwer WWW:
#
#      Worker STOPPED  Maximum run time exceeded
#      [entrypoint] jeden z procesów potomnych zakończył się — zamykam kontener
#      shutdown complete  exit_code=0
#
#  Drugi błąd dołożył się do pierwszego: kontener wychodził z kodem 0, więc
#  polityka restartu Railway uznała to za „zakończone poprawnie" i NIE
#  wskrzesiła serwisu. Cloudflare oddawał 502 przez trzy i pół godziny.
#
#  Wniosek, który jest tu prawdziwą naprawą: „proces się skończył" i „proces
#  padł" to DWIE RÓŻNE RZECZY. Wcześniej entrypoint traktował je tak samo —
#  i dlatego naprawa harmonogramu (proc_open, PR #60) nie objęła kolejki,
#  mimo że pułapka była ta sama.
#
#  Nadzorca: restartuje proces w miejscu, a eskaluje dopiero wtedy, gdy proces
#  pada NATYCHMIAST i wielokrotnie — bo to już nie jest recykling, tylko
#  awaria (padła baza, zły APP_KEY). Kontener wychodzi wtedy z kodem 1,
#  żeby Railway zobaczył porażkę i zrestartował, zamiast uznać ciszę za sukces.
# -----------------------------------------------------------------------------
nadzoruj() {
  local nazwa="$1"; shift
  local minimalny_czas_zycia="${NADZOR_MIN_CZAS:-30}"
  local limit_szybkich_smierci="${NADZOR_LIMIT:-5}"
  local szybkie_smierci=0

  while true; do
    local start; start="$(date +%s)"
    "$@" || true
    local przezyl=$(( $(date +%s) - start ))

    if (( przezyl >= minimalny_czas_zycia )); then
      # Normalny recykling — worker po --max-time, harmonogram po przebiegu.
      # Licznik zerujemy, bo poprzednie potknięcia już się nie liczą.
      szybkie_smierci=0
      log "${nazwa}: zakończył się po ${przezyl} s — uruchamiam ponownie"
    else
      szybkie_smierci=$(( szybkie_smierci + 1 ))
      log "OSTRZEŻENIE: ${nazwa} padł po ${przezyl} s (${szybkie_smierci}/${limit_szybkich_smierci})"

      if (( szybkie_smierci >= limit_szybkich_smierci )); then
        log "BŁĄD: ${nazwa} pada natychmiast ${limit_szybkich_smierci} razy z rzędu — to nie jest recykling."
        return 1
      fi

      # Odstęp rośnie z każdą porażką: 2, 4, 8, 16, 32 s. Bez tego pętla
      # zalewa logi i bazę przy awarii, która i tak potrwa dłużej.
      sleep $(( 2 ** szybkie_smierci ))
    fi
  done
}

start_worker() {
  # --max-time=3600   → worker sam się kończy po godzinie; NADZORCA go wskrzesza.
  #                     Zapobiega wyciekom pamięci w długożyjącym PHP.
  # --max-jobs=500    → to samo, ale liczone jobami.
  # --memory=700      → zabij workera, gdy przekroczy 700 MB.
  #
  #                     BYŁO 384 I TO BYŁA ZA MAŁA LICZBA. Zmierzone szczyty
  #                     RSS przy przetwarzaniu jednego zdjęcia (gd, warianty
  #                     thumb/feed/large, PHP 8.4):
  #
  #                         12 Mpx → 161 MB, 1,3 s
  #                         24 Mpx → 254 MB, 2,4 s
  #                         50 Mpx → 452 MB, 4,6 s   ← limit z config/kuking.php
  #
  #                     Przy 384 MB worker restartował się po KAŻDYM dużym
  #                     zdjęciu — nie dlatego, że coś przeciekało, tylko dlatego,
  #                     że próg stał poniżej normalnego kosztu jednego zadania.
  #                     700 MB leży nad najgorszym przypadkiem i pod limitem
  #                     kontenera (1024 MB, .railway/railway.ts).
  # --tries=3         → 3 próby, potem failed_jobs; ProcessUploadedImage musi
  #                     być idempotentny (patrz docs/MEDIA_PIPELINE.md).
  # --backoff=10,60,300 → rosnące opóźnienie między próbami.
  # --queue           → kolejność priorytetów: interakcje użytkownika przed
  #                     ciężkim przetwarzaniem obrazów.
  # Worker dostaje wyższy limit pamięci niż web. php.ini nie umie wartości
  # domyślnych, więc podajemy to flagą -d.
  #
  # UWAGA: `memory_limit` PHP NIE OBEJMUJE BUFORÓW GD. Zmierzone: przy zdjęciu
  # 50 Mpx licznik PHP pokazuje 28 MB, a RSS procesu 452 MB — libgd alokuje
  # bitmapę poza licznikiem PHP. Ten limit chroni więc kod PHP, a przed
  # wyczerpaniem pamięci przy dekodowaniu obrazu chroni `--memory` wyżej
  # i limit kontenera, nie ta wartość.
  log "start queue:work (memory_limit=${PHP_WORKER_MEMORY_LIMIT:-512M})"

  # Pętla także w roli OSOBNEGO serwisu, nie tylko w `all`. Bez niej kontener
  # workera wychodzi co godzinę z kodem 0 i jego powrót zależy od tego, jak
  # ustawiona jest polityka restartu w panelu — czyli od czegoś, czego nie ma
  # w repozytorium i o czym nikt nie pamięta. Kolejka ma działać niezależnie
  # od tego ustawienia.
  nadzoruj "kolejka" jeden_przebieg_kolejki
}

jeden_przebieg_kolejki() {
  php -d "memory_limit=${PHP_WORKER_MEMORY_LIMIT:-512M}" /app/artisan queue:work \
    --queue="${QUEUE_NAMES:-high,default,media,low}" \
    --tries="${QUEUE_TRIES:-3}" \
    --backoff="${QUEUE_BACKOFF:-10,60,300}" \
    --max-time="${QUEUE_MAX_TIME:-3600}" \
    --max-jobs="${QUEUE_MAX_JOBS:-500}" \
    --memory="${QUEUE_MEMORY:-700}" \
    --sleep=1 \
    --no-interaction
}

start_scheduler() {
  # Pętla wokół `schedule:run` zamiast `schedule:work` — patrz uzasadnienie
  # w harmonogram_raz(). Nie Railway Cron, bo ten ma minimalną granulację
  # 5 minut, a Laravel scheduler musi być odpytywany CO MINUTĘ, żeby
  # everyMinute()/everyFiveMinutes() działały zgodnie z definicją.
  # Źródło: https://docs.railway.com/cron-jobs (sekcja "Frequency")
  #
  # WAŻNE: dokładnie 1 replika. Dwie repliki = podwójne maile z digestem.
  log "start harmonogramu (schedule:run co 60 s)"

  while true; do
    harmonogram_raz
    sleep 60
  done
}

# -----------------------------------------------------------------------------
#  DLACZEGO PĘTLA, A NIE `schedule:work`
#
#  `docker/php.ini` wyłącza `proc_open` (hardening — AGENTS.md zabrania go
#  osłabiać). `schedule:work` uruchamia `schedule:run` przez Symfony Process,
#  który tej funkcji wymaga, więc na produkcji kończył się natychmiast:
#
#      The Process class relies on proc_open, which is not available
#      on your PHP installation.
#
#  W roli `all` śmierć któregokolwiek procesu potomnego kończy cały kontener
#  (`wait -n`), więc harmonogram kładł CAŁY SERWIS co uruchomienie. Objawiało
#  się to jako losowe 502 w trakcie normalnej pracy.
#
#  Ta pętla robi to, co robiłby cron systemowy, i nie potrzebuje proc_open.
#  Same zadania są zdefiniowane jako `Schedule::call()` (routes/console.php),
#  więc wykonują się w tym samym procesie PHP — też bez proc_open.
#
#  Błąd pojedynczego przebiegu NIE zatrzymuje pętli: nieudane sprzątanie
#  eksportów nie jest powodem, żeby wyłączyć serwis. Za to trafia do logu.
# -----------------------------------------------------------------------------
harmonogram_raz() {
  if ! php /app/artisan schedule:run --no-interaction; then
    log "OSTRZEŻENIE: przebieg harmonogramu zakończył się błędem — próbuję dalej za minutę."
  fi
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
    log "tryb ALL — web + worker + scheduler w jednym kontenerze"
    trap shutdown SIGTERM SIGINT

    # Kolejka i harmonogram idą pod NADZORCĄ, bo oba KOŃCZĄ SIĘ PLANOWO:
    # worker po --max-time, harmonogram po każdym przebiegu. Wcześniej ich
    # normalne zakończenie kładło cały serwis — patrz opis przy nadzoruj().
    nadzoruj "kolejka" jeden_przebieg_kolejki &
    PID_KOLEJKI="$!"
    CHILD_PIDS+=("${PID_KOLEJKI}")

    # NIE `schedule:work`: ten wymaga proc_open, wyłączonego w docker/php.ini.
    # Uzasadnienie przy harmonogram_raz().
    ( while true; do harmonogram_raz; sleep 60; done ) &
    PID_HARMONOGRAMU="$!"
    CHILD_PIDS+=("${PID_HARMONOGRAMU}")

    export SERVER_NAME=":${PORT}"
    frankenphp run --config /etc/frankenphp/Caddyfile &
    PID_WWW="$!"
    CHILD_PIDS+=("${PID_WWW}")

    # ---------------------------------------------------------------------
    #  CZEKAMY NA SERWER WWW, NIE NA „KTÓREGOKOLWIEK" (`wait -n`).
    #
    #  Serwer WWW jest jedynym procesem, który NIE MA PRAWA się skończyć:
    #  jego wyjście zawsze znaczy awarię. Kolejka i harmonogram kończą się
    #  planowo i wracają same, więc ich zakończenie nie może zamykać serwisu.
    #
    #  `wait -n` nie odróżniał tych dwóch sytuacji i dlatego dokładnie
    #  godzinę po każdym wdrożeniu recykling workera gasił całą stronę.
    # ---------------------------------------------------------------------
    wait "${PID_WWW}"
    KOD_WWW=$?
    log "serwer WWW zakończył się (kod ${KOD_WWW}) — zamykam kontener"

    # Kod NIEZEROWY jest tu istotny: przy zerowym Railway uznaje, że kontener
    # „skończył pracę poprawnie", i nie restartuje go. Tak właśnie trzy i pół
    # godziny niedostępności zaczęło się od procesu, który wyszedł z kodem 0.
    shutdown 1
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
