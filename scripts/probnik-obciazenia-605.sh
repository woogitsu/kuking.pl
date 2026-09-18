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

trap 'exit 0' TERM INT

while true; do
  TERAZ="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  MONO="$(awk '{print $1}' /proc/uptime)"

  CPU_USEC="$(awk '/^usage_usec/{print $2}' "$CG/cpu.stat" 2>/dev/null || echo 0)"
  MEM_B="$(cat "$CG/memory.current" 2>/dev/null || echo 0)"
  MEM_SZCZYT="$(cat "$CG/memory.peak" 2>/dev/null || echo 0)"
  LOAD="$(awk '{print $1}' /proc/loadavg)"

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
  if [ -n "$poprzedni_czas" ]; then
    DT="$(awk -v a="$MONO" -v b="$poprzedni_czas" 'BEGIN{printf "%.4f", a-b}')"
    RDZENIE_KONT="$(awk -v a="$CPU_USEC" -v b="$poprzedni_cpu" -v dt="$DT" 'BEGIN{if(dt>0)printf "%.3f",(a-b)/1e6/dt; else print 0}')"
    RDZENIE_WEB="$(awk -v a="$WEB_T" -v b="$poprzedni_web" -v dt="$DT" -v hz="$TAKTY" 'BEGIN{if(dt>0)printf "%.3f",(a-b)/hz/dt; else print 0}')"
    RDZENIE_WRK="$(awk -v a="$WRK_T" -v b="$poprzedni_worker" -v dt="$DT" -v hz="$TAKTY" 'BEGIN{if(dt>0)printf "%.3f",(a-b)/hz/dt; else print 0}')"
  fi
  poprzedni_cpu="$CPU_USEC"; poprzedni_web="$WEB_T"; poprzedni_worker="$WRK_T"; poprzedni_czas="$MONO"

  printf '{"t":"%s","load1":%s,"kontener_rdzenie":%s,"kontener_rss_mb":%s,"kontener_rss_szczyt_mb":%s,"web_rdzenie":%s,"web_rss_mb":%s,"worker_rdzenie":%s,"worker_rss_mb":%s,"db_polaczenia":%s,"db_aktywne":%s,"db_czeka_na_blokade":%s,"kolejka":%s,"najstarsze_zadanie_s":%s,"nieudane_zadania":%s}\n' \
    "$TERAZ" "$LOAD" "$RDZENIE_KONT" "$((MEM_B/1048576))" "$((MEM_SZCZYT/1048576))" \
    "$RDZENIE_WEB" "$((WEB_RSS/1024))" "$RDZENIE_WRK" "$((WRK_RSS/1024))" \
    "$POL" "$AKT" "$LOCK" "$KOLEJKA" "$NAJSTARSZE" "$NIEUDANE" >> "$WYJSCIE"

  sleep "$INTERWAL"
done
