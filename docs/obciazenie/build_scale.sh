#!/usr/bin/env bash
set -Eeuo pipefail
SP="$(cd "$(dirname "$0")" && pwd)"
DB="$1"; NU="$2"; NP="$3"; NR="$4"
export PGHOST=127.0.0.1 PGPORT=5432 PGUSER=kuking PGPASSWORD=kuking
psql -q -d kuking -c "DROP DATABASE IF EXISTS $DB" >/dev/null
psql -q -d kuking -c "CREATE DATABASE $DB" >/dev/null
psql -q -d "$DB" -v ON_ERROR_STOP=1 -f "$SP/schema.sql" >/dev/null
psql -q -d "$DB" -v ON_ERROR_STOP=1 -v nusers="$NU" -v nposts="$NP" -v nrecipes="$NR" -v nfollows=1 -f "$SP/gen.sql" >/dev/null
psql -q -d "$DB" -v ON_ERROR_STOP=1 -v nusers="$NU" -f "$SP/follows.sql" >/dev/null
psql -q -d "$DB" -v ON_ERROR_STOP=1 -v nusers="$NU" -f "$SP/probes.sql" >/dev/null
psql -d "$DB" -tAc "select 'users='||(select count(*) from users)||' posts='||(select count(*) from posts)||' recipes='||(select count(*) from recipes)||' comments='||(select count(*) from comments)||' follows='||(select count(*) from follows)||' rozmiar='||pg_size_pretty(pg_database_size('$DB'))"
