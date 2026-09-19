#!/usr/bin/env bash
# =============================================================================
#  Próbnik stanu podczas serii obciążeniowej — #605
# =============================================================================
#
#  Raz na sekundę zapisuje jedną linię JSON z:
#    * obciążeniem HOSTA (load average) — bo maszyna jest współdzielona
#      z runnerem CI i worktree innych agentów, a bez tej liczby nie da się
#      odróżnić degradacji aplikacji od cudzego hałasu,
#    * CPU i RSS kontenera (cgroup v2),
#    * CPU i RSS OSOBNO dla serwera WWW (frankenphp) i workera kolejki
#      (`queue:work`) — w topologii `all` siedzą w jednym kontenerze i to
#      właśnie ich wzajemne wypieranie się jest przedmiotem pomiaru,
#    * połączeniami do bazy (`pg_stat_activity`, tylko `client backend`),
#      oczekiwaniami na blokady, głębokością kolejki i wiekiem najstarszego
#      zadania.
#
#  Próbnik NIE łączy się z niczym zdalnym i nie dotyka cudzych baz.
#
#  Użycie:
#      scripts/probnik-obciazenia-605.sh <plik-wyjsciowy.jsonl> [nazwa-kontenera] [baza]
#  Zatrzymanie: SIGTERM/SIGINT.
# =============================================================================
set -u

WYJSCIE="${1:?Podaj plik wyjściowy .jsonl}"
KONTENER="${2:-kuking-b605-app}"
BAZA="${3:-kuking_b605_obciazenie}"
# Odstęp próbkowania w sekundach. 1 s wystarcza do serii, ale NIE do pomiaru
# szczytu RSS przy jednym zdjęciu 48 Mpx: przetworzenie trwa rząd sekundy,
# więc próbka co sekundę potrafi minąć się ze szczytem. Do mediów: 0.2.
INTERWAL="${4:-1}"

PGHOST=127.0.0.1
PGPORT=55439
PGUSER=kuking
export PGPASSWORD=kuking
# Własna nazwa aplikacji, żeby próbnik nie liczył sam siebie jako połączenia
# aplikacji — inaczej każdy odczyt zawyżałby wynik o jeden.
export PGAPPNAME=b605-probnik

if [ "$PGPORT" = "5432" ]; then echo 'Port 5432 jest zabroniony.' >&2; exit 1; fi

ID="$(docker inspect -f '{{.Id}}' "$KONTENER")" || exit 1
CG="/sys/fs/cgroup/system.slice/docker-$ID.scope"
[ -d "$CG" ] || { echo "Nie znalazłem cgroup: $CG" >&2; exit 1; }

TAKTY="$(getconf CLK_TCK)"
RDZENI="$(nproc)"

# ---------------------------------------------------------------------------
#  OBCE OBCIĄŻENIE — najważniejsza liczba w tym pliku po p95.
#
#  Ta maszyna jest wspólnym hostem CI pięciu projektów (17 runnerów). Zajętość
#  nie pochodzi od tego zadania i nie da się jej wyłączyć, więc trzeba ją
#  MIERZYĆ przy każdej próbce, a nie odnotować raz na początku serii.
#
#  Liczymy tak: rdzenie zajęte na całym hoście (z /proc/stat, bez idle
#  i iowait) MINUS rdzenie zjedzone przez własne stanowisko (kontener
#  aplikacji + proces generatora + ten próbnik). Reszta jest cudza.
#
#  `/proc/pressure/cpu` dokłada drugą, niezależną miarę: ile czasu zadania
#  CZEKAŁY na procesor. Wolne rdzenie i brak czekania to dwie różne rzeczy
#  i przy bramkowaniu potrzebne są obie.
# ---------------------------------------------------------------------------
czytaj_cpu_hosta() { awk '/^cpu /{s=0; for(i=2;i<=NF;i++) s+=$i; print s-$5-$6; exit}' /proc/stat; }

# Ile runnerów `kuking` ma PRZYDZIELONE ZADANIE. To jedyna część hałasu na tej
# maszynie, która pochodzi z NASZYCH pushy — i dlatego jedyna, którą wolno nam
# brać pod uwagę przy decyzji „mierzyć czy czekać”. Runnery lockstate/metro/
# osadale należą do cudzych projektów; są tłem, nie warunkiem.
#
# Liczy się `Runner.Worker`, a NIE nazwa katalogu: `Runner.Listener` każdego
# runnera stoi nieprzerwanie jako usługa i wzorzec po ścieżce pokazywałby
# cztery runnery zawsze, także wtedy, gdy nie robią nic.
ile_runnerow_kuking() {
  ps -eo args 2>/dev/null | grep 'Runner\.Worker' \
    | grep -cE 'actions-runner-kuking-0[0-9]' || true
}

# PID generatora szukamy po pełnej ścieżce skryptu — czytamy /proc, niczego
# nie zabijamy. Brak generatora (np. przy pomiarze mediów) to nie błąd.
znajdz_generator() {
  local pid
  for pid in /proc/[0-9]*; do
    [ -r "$pid/cmdline" ] || continue
    if tr '\0' ' ' < "$pid/cmdline" 2>/dev/null | grep -qF 'generator-obciazenia-605.mjs'; then
      basename "$pid"
      return
    fi
  done
  echo 0
}

# Odczyt CPU procesu w taktach (utime+stime) i RSS w kB.
proces_stat() {
  local pid="${1:-0}" t=0 rss=0
  if [ -r "/proc/$pid/stat" ]; then
    t="$(awk '{print $14+$15}' "/proc/$pid/stat" 2>/dev/null)"
    rss="$(awk '/^VmRSS:/{print $2}' "/proc/$pid/status" 2>/dev/null)"
  fi
  echo "${t:-0} ${rss:-0}"
}

# PID szukamy WYŁĄCZNIE wśród procesów tego kontenera (`cgroup.procs`).
# `pgrep -f` po wzorcu trafiłby w procesy innych agentów na tej maszynie —
# a to jest ta sama pomyłka, przed którą ostrzega zakaz `pkill` po wzorcu.
znajdz_pid() {
  local wzorzec="$1" pid
  while read -r pid; do
    [ -r "/proc/$pid/cmdline" ] || continue
    if tr '\0' ' ' < "/proc/$pid/cmdline" | grep -qF -- "$wzorzec"; then
      echo "$pid"
      return
    fi
  done < "$CG/cgroup.procs"
  echo 0
}

SQL="
select
  (select count(*) from pg_stat_activity
     where datname = current_database() and backend_type = 'client backend'
       and coalesce(application_name,'') <> 'b605-probnik')                     as polaczenia,
  (select count(*) from pg_stat_activity
     where datname = current_database() and backend_type = 'client backend'
       and state = 'active' and coalesce(application_name,'') <> 'b605-probnik') as aktywne,
  (select count(*) from pg_stat_activity
     where datname = current_database() and wait_event_type = 'Lock')           as czeka_na_blokade,
  (select count(*) from jobs)                                                   as kolejka,
  (select coalesce(max(extract(epoch from now()) - available_at), 0)::int
     from jobs)                                                                 as najstarsze_zadanie_s,
  (select count(*) from failed_jobs)                                            as nieudane
"

poprzedni_cpu=""
poprzedni_web=""
poprzedni_worker=""
poprzedni_czas=""
poprzedni_host=""
poprzedni_gen=""
poprzedni_sam=""

trap 'exit 0' TERM INT

while true; do
  TERAZ="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  MONO="$(awk '{print $1}' /proc/uptime)"

  CPU_USEC="$(awk '/^usage_usec/{print $2}' "$CG/cpu.stat" 2>/dev/null || echo 0)"
  MEM_B="$(cat "$CG/memory.current" 2>/dev/null || echo 0)"
  MEM_SZCZYT="$(cat "$CG/memory.peak" 2>/dev/null || echo 0)"
  LOAD="$(awk '{print $1}' /proc/loadavg)"
  HOST_T="$(czytaj_cpu_hosta)"
  PSI="$(awk -F'avg10=' '/^some/{split($2,a," "); print a[1]; exit}' /proc/pressure/cpu 2>/dev/null)"
  PSI="${PSI:-0}"
  RUNNERY_KUKING="$(ile_runnerow_kuking)"
  RUNNERY_KUKING="${RUNNERY_KUKING:-0}"
  PID_GEN="$(znajdz_generator)"
  read -r GEN_T GEN_RSS <<<"$(proces_stat "$PID_GEN")"
  read -r SAM_T SAM_RSS <<<"$(proces_stat "$$")"

  PID_WEB="$(znajdz_pid 'frankenphp run')"
  PID_WORKER="$(znajdz_pid 'artisan queue:work')"
  read -r WEB_T WEB_RSS <<<"$(proces_stat "${PID_WEB:-0}")"
  read -r WRK_T WRK_RSS <<<"$(proces_stat "${PID_WORKER:-0}")"

  # Przy próbkowaniu gęstszym niż sekunda pytanie do bazy kosztuje więcej niż
  # jest warte (własne połączenie co próbkę) — wtedy zbieramy tylko procesy.
  if [ "${INTERWAL%.*}" -ge 1 ] 2>/dev/null; then
    DB="$(psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d "$BAZA" -At -F' ' -c "$SQL" 2>/dev/null)"
  else
    DB=''
  fi
  set -- ${DB:-0 0 0 0 0 0}
  POL="${1:-0}"; AKT="${2:-0}"; LOCK="${3:-0}"; KOLEJKA="${4:-0}"; NAJSTARSZE="${5:-0}"; NIEUDANE="${6:-0}"

  RDZENIE_KONT=0; RDZENIE_WEB=0; RDZENIE_WRK=0
  RDZENIE_HOST=0; RDZENIE_GEN=0; RDZENIE_STAN=0; RDZENIE_OBCE=0
  if [ -n "$poprzedni_czas" ]; then
    DT="$(awk -v a="$MONO" -v b="$poprzedni_czas" 'BEGIN{printf "%.4f", a-b}')"
    RDZENIE_KONT="$(awk -v a="$CPU_USEC" -v b="$poprzedni_cpu" -v dt="$DT" 'BEGIN{if(dt>0)printf "%.3f",(a-b)/1e6/dt; else print 0}')"
    RDZENIE_WEB="$(awk -v a="$WEB_T" -v b="$poprzedni_web" -v dt="$DT" -v hz="$TAKTY" 'BEGIN{if(dt>0)printf "%.3f",(a-b)/hz/dt; else print 0}')"
    RDZENIE_WRK="$(awk -v a="$WRK_T" -v b="$poprzedni_worker" -v dt="$DT" -v hz="$TAKTY" 'BEGIN{if(dt>0)printf "%.3f",(a-b)/hz/dt; else print 0}')"
    RDZENIE_HOST="$(awk -v a="$HOST_T" -v b="$poprzedni_host" -v dt="$DT" -v hz="$TAKTY" 'BEGIN{if(dt>0)printf "%.2f",(a-b)/hz/dt; else print 0}')"
    RDZENIE_GEN="$(awk -v a="$GEN_T" -v b="$poprzedni_gen" -v dt="$DT" -v hz="$TAKTY" 'BEGIN{if(dt>0 && a>=b)printf "%.3f",(a-b)/hz/dt; else print 0}')"
    SAMO="$(awk -v a="$SAM_T" -v b="$poprzedni_sam" -v dt="$DT" -v hz="$TAKTY" 'BEGIN{if(dt>0)printf "%.3f",(a-b)/hz/dt; else print 0}')"
    RDZENIE_STAN="$(awk -v k="$RDZENIE_KONT" -v g="$RDZENIE_GEN" -v s="$SAMO" 'BEGIN{printf "%.3f", k+g+s}')"
    # Obce = wszystko, co zajęte na hoście, minus własne stanowisko.
    # Zacinamy na zerze: różnica dwóch niezależnych liczników potrafi na
    # pojedynczej próbce wyjść minimalnie ujemna i ujemne „obce” byłoby bzdurą.
    RDZENIE_OBCE="$(awk -v h="$RDZENIE_HOST" -v s="$RDZENIE_STAN" 'BEGIN{o=h-s; if(o<0)o=0; printf "%.2f", o}')"
  fi
  poprzedni_cpu="$CPU_USEC"; poprzedni_web="$WEB_T"; poprzedni_worker="$WRK_T"; poprzedni_czas="$MONO"
  poprzedni_host="$HOST_T"; poprzedni_gen="$GEN_T"; poprzedni_sam="$SAM_T"

  printf '{"t":"%s","load1":%s,"rdzeni_hosta":%s,"rdzenie_zajete_host":%s,"rdzenie_stanowiska":%s,"rdzenie_obce":%s,"psi_cpu_some_avg10":%s,"runnery_kuking_pracujace":%s,"generator_rdzenie":%s,"kontener_rdzenie":%s,"kontener_rss_mb":%s,"kontener_rss_szczyt_mb":%s,"web_rdzenie":%s,"web_rss_mb":%s,"worker_rdzenie":%s,"worker_rss_mb":%s,"db_polaczenia":%s,"db_aktywne":%s,"db_czeka_na_blokade":%s,"kolejka":%s,"najstarsze_zadanie_s":%s,"nieudane_zadania":%s}\n' \
    "$TERAZ" "$LOAD" "$RDZENI" "$RDZENIE_HOST" "$RDZENIE_STAN" "$RDZENIE_OBCE" "$PSI" "$RUNNERY_KUKING" "$RDZENIE_GEN" \
    "$RDZENIE_KONT" "$((MEM_B/1048576))" "$((MEM_SZCZYT/1048576))" \
    "$RDZENIE_WEB" "$((WEB_RSS/1024))" "$RDZENIE_WRK" "$((WRK_RSS/1024))" \
    "$POL" "$AKT" "$LOCK" "$KOLEJKA" "$NAJSTARSZE" "$NIEUDANE" >> "$WYJSCIE"

  sleep "$INTERWAL"
done
