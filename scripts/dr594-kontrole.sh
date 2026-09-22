#!/usr/bin/env bash
# Kontrola miernika na własnej odtworzonej bazie; nigdy na produkcji.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
target="${1:?Podaj bazę z receipt.json}"
[[ "$target" =~ ^proba_odtworzenia_gpt_dr_baza_[0-9]{8}t[0-9]{6}z$ ]] || exit 2
[[ "${PGHOST:-}" == 127.0.0.1 && "${PGPORT:-}" == 55439 && "${PGUSER:-}" == kuking ]] || exit 2
[[ "${DB_HOST:-}" == 127.0.0.1 && "${DB_PORT:-}" == 55439 ]] || exit 2
unset PGSERVICE PGSERVICEFILE PGHOSTADDR
export PGTZ=UTC PGOPTIONS='-c timezone=UTC' PGCONNECT_TIMEOUT=10
source scripts/proba-odtworzenia.sh
DSN_ZRODLA='postgresql://kuking@127.0.0.1:55439/kuking_flota_gpt_dr_baza_source'
DSN_PROBNY="postgresql://kuking@127.0.0.1:55439/$target"
SCISLE=1
KATALOG_ROBOCZY="$(mktemp -d)"
removed=0
restore_row() {
  if ((removed)); then
    psql -X --no-password "$DSN_PROBNY" -v ON_ERROR_STOP=1 -c 'COPY public.comments FROM STDIN' < "$KATALOG_ROBOCZY/row.tsv"
    removed=0
  fi
}
cleanup() {
  restore_row
  rm -rf -- "$KATALOG_ROBOCZY"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
identity="$(psql -X --no-password "$DSN_PROBNY" -Atc "SELECT current_database()||'|'||current_user||'|'||host(inet_server_addr())||'|'||inet_server_port()")"
[[ "$identity" == "$target|kuking|127.0.0.1|55439" ]] || exit 2
echo 'DODATNIA: pełna kopia przed uszkodzeniem'
porownaj_wszystkie_tabele
psql -X --no-password "$DSN_PROBNY" -v ON_ERROR_STOP=1 -q -c \
  "COPY (SELECT * FROM public.comments WHERE id=md5('dr594:comment:1')::uuid) TO STDOUT" > "$KATALOG_ROBOCZY/row.tsv"
[[ "$(wc -l < "$KATALOG_ROBOCZY/row.tsv")" == 1 ]] || exit 3
psql -X --no-password "$DSN_PROBNY" -v ON_ERROR_STOP=1 -c \
  "DELETE FROM public.comments WHERE id=md5('dr594:comment:1')::uuid"
removed=1
echo 'UJEMNA: brakuje jednego komentarza'
set +e
(porownaj_wszystkie_tabele) > "$KATALOG_ROBOCZY/negative.txt" 2>&1
code=$?
set -e
cat "$KATALOG_ROBOCZY/negative.txt"
[[ "$code" == 63 ]] || { echo "Oczekiwano 63, otrzymano $code"; exit 4; }
grep -q 'comments:' "$KATALOG_ROBOCZY/negative.txt" || exit 4
echo 'DODATNIA: przywrócenie dokładnego wiersza'
restore_row
porownaj_wszystkie_tabele
echo 'KONTROLA ZALICZONA: zielony -> jeden brakujący wiersz / kod 63 -> zielony.'
