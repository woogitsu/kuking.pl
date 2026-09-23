#!/usr/bin/env bash
# WYMAGA: WSL, klonu /home/mateusz/flota/push-run, bazy kuking_flota_push na 127.0.0.1:55439 i zdalnego 'github9'; poza tą maszyną nie zadziała — zapis tego, jak pchano szeregowo.
# Kolejka pchania v11 — chodzi W CALOSCI w WSL, w klonie /home/mateusz/flota/push-run.
#
# Dlaczego nie z katalogow windowsowych: hook pre-push odpala ./scripts/check.sh,
# a stanowiska windowsowe nie maja vendor/. Bateria padala wtedy strukturalnie
# ("Failed opening required vendor/autoload.php"), czyli bramka byla czerwona
# niezaleznie od jakosci kodu. To fałszywa czerwień — gorsza niz brak bramki,
# bo wyglada na werdykt o kodzie.
#
# Uczciwosc raportu: wpis do "pchniete" powstaje wylacznie po porownaniu SHA
# lokalnego ze zdalnym PO pchnieciu, nigdy na podstawie kodu wyjscia.
set -uo pipefail
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_PREFIX GIT_COMMON_DIR
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"

# Baza WLASNA, na wlasnym klastrze. Port 5432 jest wspoldzielony i zabroniony;
# .env klonu niesie 5432, wiec nadpisujemy go srodowiskiem, tak jak testuj.sh.
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_push DB_USERNAME=kuking DB_PASSWORD=kuking
export PGHOST=127.0.0.1 PGPORT=55439 PGUSER=kuking PGPASSWORD=kuking

# Remote GitHuba. UWAGA: w tym klonie `origin` to LOKALNE repozytorium kanoniczne,
# a nie GitHub. Pchanie i weryfikacja przez `origin` dawaly FALSZYWE "OK":
# push padal, a porownanie SHA i tak sie zgadzalo, bo patrzylo na lokalny klon.
ZDALNY=github9

RT=/home/mateusz/flota/push-run
KAT=/mnt/c/Users/matma/Documents/kuking-flota/_wspolne
LISTA="${1:-$KAT/kolejka11-lista.txt}"
LOG="$KAT/kolejka11.log"
OK="$KAT/kolejka11-pchniete.txt"
ZLE="$KAT/kolejka11-padlo.txt"
touch "$OK" "$ZLE"
log() { printf '%s %s\n' "$(date '+%H:%M:%S')" "$*" >> "$LOG"; }

cd "$RT" || { log "BRAK $RT"; exit 1; }
psql -d postgres -tc "SELECT 1 FROM pg_database WHERE datname='kuking_flota_push'" | grep -q 1 || createdb kuking_flota_push
git remote get-url "$ZDALNY" >/dev/null 2>&1 || { log "BRAK remote $ZDALNY"; exit 1; }
log "=== start v11, pozycji: $(wc -l < "$LISTA")"

while IFS=$'\t' read -r galaz zrodlo sha; do
  [ -n "${galaz:-}" ] || continue
  grep -qxF "$galaz" "$OK" && { log "POMIJAM $galaz"; continue; }
  log "--- $galaz (zrodlo $zrodlo, oczekiwane $sha)"

  if ! git fetch "$zrodlo" "refs/heads/$galaz:refs/kolejka/$galaz" --force -q 2>>"$LOG"; then
    log "PADLO $galaz: fetch"; printf '%s\tfetch\n' "$galaz" >> "$ZLE"; continue
  fi
  pobrane=$(git rev-parse --verify --quiet "refs/kolejka/$galaz")
  if [ "$pobrane" != "$sha" ]; then
    log "UWAGA $galaz: pobrano $pobrane, spodziewano $sha (pracowano dalej po zbudowaniu listy)"
  fi
  if ! git checkout -q -B "$galaz" "refs/kolejka/$galaz" 2>>"$LOG"; then
    log "PADLO $galaz: checkout"; printf '%s\tcheckout\n' "$galaz" >> "$ZLE"; continue
  fi

  wynik=$(git push "$ZDALNY" "$galaz:refs/heads/$galaz" 2>&1); kod=$?
  log "push kod=$kod"
  printf '%s\n' "$wynik" | tail -25 >> "$LOG"

  git fetch "$ZDALNY" -q "refs/heads/$galaz:refs/remotes/$ZDALNY/$galaz" --force 2>/dev/null
  zdal=$(git rev-parse --verify --quiet "refs/remotes/$ZDALNY/$galaz")
  lok=$(git rev-parse --verify --quiet HEAD)
  if [ "$zdal" = "$lok" ]; then
    log "OK $galaz -> $lok"; printf '%s\n' "$galaz" >> "$OK"
  else
    log "PADLO $galaz: lokalny=$lok zdalny=${zdal:-BRAK} kod=$kod"
    printf '%s\tkod=%s\tzdalny=%s\n' "$galaz" "$kod" "${zdal:-BRAK}" >> "$ZLE"
  fi
done < "$LISTA"

log "=== koniec v11. pchnietych: $(wc -l < "$OK"), padlo: $(wc -l < "$ZLE")"
