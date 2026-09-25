#!/usr/bin/env bash
# =============================================================================
#  Regresja issue #732: krok „PostgreSQL" w scripts/check.sh.
# =============================================================================
#
#  Uruchamia RZECZYWISTY blok kroku, wycięty z scripts/check.sh (od
#  `krok "PostgreSQL"` do nagłówka kroku 2), z atrapami zamiast narzędzi:
#
#    - `pg_isready` zapisuje swoje argumenty i zwraca kod z ATRAPA_KOD,
#    - `pg_ctlcluster` zapisuje znacznik UNEXPECTED_CLUSTER_START i zwraca
#      błąd — sonda NIE MA prawa go wołać.
#
#  Żaden prawdziwy PostgreSQL nie jest dotykany. Bez sieci, poniżej sekundy.
#
#  Uruchomienie:  bash tests/skrypty/sonda-postgresql.sh
# =============================================================================

set -uo pipefail

KORZEN="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/sonda-pg.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

zdane=0
oblane=0

sprawdz() {
    local opis="$1" warunek="$2"
    if [ "$warunek" -eq 0 ]; then
        printf '  \033[0;32m✓\033[0m %s\n' "$opis"
        zdane=$((zdane + 1))
    else
        printf '  \033[0;31m✗\033[0m %s\n' "$opis"
        oblane=$((oblane + 1))
    fi
}

# --- atrapy ------------------------------------------------------------------
mkdir -p "$TMP/bin"
cat > "$TMP/bin/pg_isready" <<'ATRAPA'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$ATRAPA_LOG"
exit "${ATRAPA_KOD:-0}"
ATRAPA
cat > "$TMP/bin/pg_ctlcluster" <<'ATRAPA'
#!/usr/bin/env bash
printf 'UNEXPECTED_CLUSTER_START %s\n' "$*" >> "$ATRAPA_LOG"
exit 1
ATRAPA
chmod +x "$TMP/bin/pg_isready" "$TMP/bin/pg_ctlcluster"

# --- rzeczywisty blok kroku z check.sh ---------------------------------------
awk '/^krok "PostgreSQL"/{w=1} /^# --- 2\./{w=0} w' "$KORZEN/scripts/check.sh" > "$TMP/blok.sh"
[ -s "$TMP/blok.sh" ]
sprawdz "blok kroku PostgreSQL da się wyciąć z scripts/check.sh" $?

# Uruchamia blok w osobnym procesie, z katalogu repozytorium (blok źródłuje
# scripts/lib/...), z atrapami na początku PATH. Zmienne DB_* przekazuje
# wołający przez `env`.
uruchom_blok() {
    : > "$TMP/log"
    (
        cd "$KORZEN" || exit 99
        export PATH="$TMP/bin:$PATH" ATRAPA_LOG="$TMP/log"
        # Ta sama sygnatura funkcji co w check.sh; BLEDY liczy porażki.
        BLEDY=0
        krok() { printf '── %s ──\n' "$1"; }
        ok() { printf 'OK: %s\n' "$1"; }
        zle() { printf 'ZLE: %s\n' "$1"; BLEDY=$((BLEDY + 1)); }
        # shellcheck disable=SC1090
        . "$TMP/blok.sh"
        exit "$BLEDY"
    ) > "$TMP/wyjscie" 2>&1
    echo $? > "$TMP/kod"
}

# --- 1. endpoint gotowy ---------------------------------------------------------
env -u PGPORT -u PGHOST DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_test_sonda DB_USERNAME=kuking \
    DB_PASSWORD=tajne-haslo-sondy ATRAPA_KOD=0 bash -c "$(declare -f uruchom_blok); TMP='$TMP' KORZEN='$KORZEN'; uruchom_blok"
argumenty="$(cat "$TMP/log")"

grep -q -- '-h 127.0.0.1' <<< "$argumenty"
sprawdz "gotowość: pg_isready dostaje jawny host 127.0.0.1" $?
grep -q -- '-p 55439' <<< "$argumenty" || printf '    BLAD_ARGUMENTOW: brak -p 55439 w wywołaniu pg_isready: %s\n' "$argumenty"
grep -q -- '-p 55439' <<< "$argumenty"
sprawdz "gotowość: pg_isready dostaje jawny port 55439" $?
grep -q -- '-d kuking_test_sonda' <<< "$argumenty"
sprawdz "gotowość: pg_isready dostaje DB_DATABASE" $?
grep -q -- '-U kuking' <<< "$argumenty"
sprawdz "gotowość: pg_isready dostaje DB_USERNAME" $?
[ "$(cat "$TMP/kod")" = 0 ]
sprawdz "gotowość: krok kończy się bez błędu" $?
! grep -q 'UNEXPECTED_CLUSTER_START' "$TMP/log" || printf '    UNEXPECTED_CLUSTER_START: sonda uruchamia klaster\n'
! grep -q 'UNEXPECTED_CLUSTER_START' "$TMP/log"
sprawdz "gotowość: zero wywołań pg_ctlcluster" $?
! grep -q 'działa"' "$TMP/wyjscie" && grep -q 'nie sprawdza hasła' "$TMP/wyjscie"
sprawdz "gotowość: komunikat nie utożsamia gotowości serwera z działającą bazą" $?

# --- 2. endpoint niedostępny ------------------------------------------------------
env DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_test_sonda DB_USERNAME=kuking \
    DB_PASSWORD=tajne-haslo-sondy ATRAPA_KOD=2 bash -c "$(declare -f uruchom_blok); TMP='$TMP' KORZEN='$KORZEN'; uruchom_blok"

[ "$(cat "$TMP/kod")" != 0 ]
sprawdz "niedostępność: krok zgłasza błąd (kod odmowy)" $?
! grep -q 'UNEXPECTED_CLUSTER_START' "$TMP/log" || printf '    UNEXPECTED_CLUSTER_START: sonda uruchamia klaster\n'
! grep -q 'UNEXPECTED_CLUSTER_START' "$TMP/log"
sprawdz "niedostępność: zero wywołań pg_ctlcluster" $?
[ "$(grep -c '' "$TMP/log")" -eq 1 ]
sprawdz "niedostępność: dokładnie jedna sonda, bez ponawiania po próbie naprawy" $?
grep -q '127.0.0.1:55439' "$TMP/wyjscie"
sprawdz "niedostępność: komunikat nazywa sprawdzany endpoint" $?
! grep -q 'tajne-haslo-sondy' "$TMP/wyjscie"
sprawdz "niedostępność: komunikat nie wypisuje hasła" $?

# --- 3. brak parametrów -------------------------------------------------------------
env -u DB_PORT -u DB_DATABASE DB_HOST=127.0.0.1 DB_USERNAME=kuking ATRAPA_KOD=0 \
    bash -c "$(declare -f uruchom_blok); TMP='$TMP' KORZEN='$KORZEN'; uruchom_blok"

[ "$(cat "$TMP/kod")" != 0 ]
sprawdz "brak parametrów: kontrola kończy się odmową" $?
grep -q 'DB_PORT' "$TMP/wyjscie" && grep -q 'DB_DATABASE' "$TMP/wyjscie"
sprawdz "brak parametrów: komunikat wymienia brakujące zmienne" $?
[ ! -s "$TMP/log" ]
sprawdz "brak parametrów: pg_isready nie jest wołany z domyślnym połączeniem" $?

# --- 4. check.sh sam z siebie nie wraca do starej sondy ------------------------------
! grep -q 'pg_ctlcluster' "$TMP/blok.sh"
sprawdz "blok kroku w check.sh nie zawiera pg_ctlcluster" $?
! grep -qE 'pg_isready[[:space:]]+-q[[:space:]]*(2>|$)' "$KORZEN/scripts/check.sh"
sprawdz "check.sh nie ma gołego 'pg_isready -q' bez endpointu" $?

printf '\nZdane: %d, oblane: %d\n' "$zdane" "$oblane"
[ "$oblane" -eq 0 ]
