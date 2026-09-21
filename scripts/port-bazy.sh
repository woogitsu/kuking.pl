#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — JEDNO miejsce, w którym skrypty ustalają, GDZIE stoi baza
# =============================================================================
#
#  PO CO TO ISTNIEJE
#  `scripts/check.sh` meldował „PostgreSQL działa" po gołym `pg_isready -q`.
#  Gołe `pg_isready` pyta o port 5432 i o gniazdo miejscowe — czyli o klaster,
#  który na maszynie dewelopera należy do INNEGO projektu. Testy Kukinga
#  chodzą tymczasem na porcie z `DB_PORT` (u nas 55439). Kontrola przed
#  wysłaniem zmian mówiła więc „baza działa", patrząc na cudzą bazę, a push
#  dawał 3737 porażek z `password authentication failed … Port: 5432`.
#
#  Zielone „działa" o niewłaściwym porcie jest gorsze niż brak kontroli:
#  człowiek dostaje zapewnienie, na którym polega.
#
#  KOLEJNOŚĆ USTALANIA — DOKŁADNIE TA SAMA, CO W PHP
#  1. zmienna środowiskowa `DB_PORT` / `DB_HOST` (bije wszystko, bo Dotenv
#     Laravela jest niemutowalny i nie nadpisuje tego, co już jest w otoczeniu),
#  2. `.env` tej kopii roboczej,
#  3. wartości domyślne z `config/database.php` (127.0.0.1:5432).
#
#  `phpunit.xml` świadomie NIE przypina już `DB_PORT` — patrz komentarz przy
#  `DB_HOST` w tamtym pliku.
#
#  UŻYCIE
#      . "$(dirname "$0")/port-bazy.sh"     # albo ścieżka względna do scripts/
#      kuking_ustal_dostep_do_bazy
#      echo "$KUKING_DB_HOST:$KUKING_DB_PORT"
#      kuking_postgres_odpowiada || echo "nie ma bazy"
# =============================================================================

# Ustawia KUKING_DB_HOST i KUKING_DB_PORT. Idempotentne.
kuking_ustal_dostep_do_bazy() {
    local katalog_repo="${1:-.}"
    local plik_env="${katalog_repo}/.env"

    KUKING_DB_HOST="${DB_HOST:-}"
    KUKING_DB_PORT="${DB_PORT:-}"

    if [ -f "$plik_env" ]; then
        # `sed`, a nie `source`: `.env` to nie skrypt powłoki i nie wolno go
        # wykonywać — jedna linia z `$(…)` w haśle uruchomiłaby polecenie.
        # Bierzemy OSTATNIE wystąpienie, bo tak samo robi Dotenv.
        [ -z "$KUKING_DB_HOST" ] && KUKING_DB_HOST="$(sed -n 's/^[[:space:]]*DB_HOST[[:space:]]*=[[:space:]]*//p' "$plik_env" | tr -d '"'\''' | tail -n1)"
        [ -z "$KUKING_DB_PORT" ] && KUKING_DB_PORT="$(sed -n 's/^[[:space:]]*DB_PORT[[:space:]]*=[[:space:]]*//p' "$plik_env" | tr -d '"'\''' | tail -n1)"
    fi

    KUKING_DB_HOST="${KUKING_DB_HOST:-127.0.0.1}"
    KUKING_DB_PORT="${KUKING_DB_PORT:-5432}"

    # Port musi być liczbą — inaczej `pg_isready -p` i `psql -p` milczą albo
    # łączą się nie tam, gdzie trzeba. Śmieć w `.env` ma być widoczny od razu.
    if ! printf '%s' "$KUKING_DB_PORT" | grep -qE '^[0-9]+$'; then
        printf 'DB_PORT="%s" nie jest liczbą — popraw .env albo wyeksportuj DB_PORT.\n' \
            "$KUKING_DB_PORT" >&2
        return 1
    fi

    KUKING_DB_UZYTKOWNIK="${DB_USERNAME:-kuking}"

    export KUKING_DB_HOST KUKING_DB_PORT KUKING_DB_UZYTKOWNIK
}

# Czy baza, NA KTÓREJ NAPRAWDĘ POJADĄ TESTY, odpowiada.
kuking_postgres_odpowiada() {
    kuking_ustal_dostep_do_bazy "${1:-.}" || return 1

    pg_isready -q -h "$KUKING_DB_HOST" -p "$KUKING_DB_PORT" 2>/dev/null
}

# Jednolity opis „gdzie patrzyliśmy" — do komunikatów. Bez tego człowiek
# czyta „PostgreSQL nie działa" i uruchamia klaster, który i tak nie jest tym,
# o który chodzi.
kuking_opis_bazy() {
    printf '%s:%s' "${KUKING_DB_HOST:-?}" "${KUKING_DB_PORT:-?}"
}
