#!/usr/bin/env bash
set -Eeuo pipefail
SP="$(cd "$(dirname "$0")" && pwd)"
fail() { printf '%s\n' "$1" >&2; exit 2; }
[[ $# -eq 4 || $# -eq 5 ]] || fail 'Użycie: build_scale.sh kuking_bench_NAZWA osoby wpisy przepisy [--reset=kuking_bench_NAZWA]'
DB="$1"; NU="$2"; NP="$3"; NR="$4"
[[ "$DB" =~ ^kuking_bench_[a-z0-9_]{1,40}$ ]] || fail 'Dozwolona jest wyłącznie izolowana nazwa kuking_bench_[a-z0-9_].'
[[ ${PGHOST:-} == 127.0.0.1 || ${PGHOST:-} == localhost ]] || fail 'Ustaw jawnie lokalny PGHOST.'
[[ ${PGPORT:-} =~ ^[0-9]{4,5}$ ]] && (( 10#$PGPORT >= 1024 && 10#$PGPORT <= 65535 && 10#$PGPORT != 5432 )) || fail 'Ustaw jawnie izolowany PGPORT (nie 5432).'
[[ ${PGUSER:-} =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || fail 'Ustaw jawnie PGUSER właściciela bazy benchmarku.'
[[ -z ${PGHOSTADDR:-}${PGSERVICE:-}${PGSERVICEFILE:-} ]] || fail 'Usuń alternatywne PGHOSTADDR/PGSERVICE/PGSERVICEFILE.'
for count in "$NU" "$NP" "$NR"; do
    [[ "$count" =~ ^[1-9][0-9]{0,8}$ ]] || fail 'Liczności muszą być dodatnimi liczbami całkowitymi.'
done
[[ $# -eq 4 || $5 == "--reset=$DB" ]] || fail 'Zgoda resetu musi wskazywać dokładnie nazwę przygotowywanej bazy.'
CONNECTION=(-X --no-password -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -v ON_ERROR_STOP=1)
exists=$(psql "${CONNECTION[@]}" -qAt -d postgres -v "bench_db=$DB" <<'SQL'
SELECT count(*) FROM pg_database WHERE datname = :'bench_db';
SQL
)
[[ $exists == 0 || $exists == 1 ]] || fail 'Nie udało się jednoznacznie sprawdzić istnienia bazy.'
if [[ $exists == 1 ]]; then
    [[ $# -eq 5 ]] || fail 'Baza istnieje. Odmawiam resetu bez --reset=DOKLADNA_NAZWA.'
    psql "${CONNECTION[@]}" -q -d postgres -v "bench_db=$DB" <<'SQL'
SELECT format('DROP DATABASE %I', :'bench_db') \gexec
SQL
fi
psql "${CONNECTION[@]}" -q -d postgres -v "bench_db=$DB" -v "bench_owner=$PGUSER" <<'SQL'
SELECT format('CREATE DATABASE %I OWNER %I', :'bench_db', :'bench_owner') \gexec
SQL
psql "${CONNECTION[@]}" -q -d "$DB" -f "$SP/schema.sql" >/dev/null
psql "${CONNECTION[@]}" -q -d "$DB" -v nusers="$NU" -v nposts="$NP" -v nrecipes="$NR" -v nfollows=1 -f "$SP/gen.sql" >/dev/null
psql "${CONNECTION[@]}" -q -d "$DB" -v nusers="$NU" -f "$SP/follows.sql" >/dev/null
psql "${CONNECTION[@]}" -q -d "$DB" -v nusers="$NU" -f "$SP/probes.sql" >/dev/null
psql "${CONNECTION[@]}" -d "$DB" -tAc "select 'users='||(select count(*) from users)||' posts='||(select count(*) from posts)||' recipes='||(select count(*) from recipes)||' comments='||(select count(*) from comments)||' follows='||(select count(*) from follows)||' rozmiar='||pg_size_pretty(pg_database_size(current_database()))"
