#!/usr/bin/env bash
# Uruchamiamy prawdziwy początek check.sh na atrapach, bez dostępu do bazy.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TASK="$(mktemp -d)"
trap 'rm -rf "$TASK"' EXIT
mkdir -p "$TASK/scripts" "$TASK/bin"
awk '/# --- 2\. Formatowanie/{exit} {print}' "$ROOT/scripts/check.sh" > "$TASK/scripts/check.sh"
test -s "$TASK/scripts/check.sh"
cat > "$TASK/bin/pg_isready" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$TRACE"
exit "${READY_STATUS:-0}"
EOF
cat > "$TASK/bin/pg_ctlcluster" <<'EOF'
#!/usr/bin/env bash
echo UNEXPECTED_CLUSTER_START >> "$TRACE"
exit 1
EOF
chmod +x "$TASK/bin/"*
export PATH="$TASK/bin:$PATH" TRACE="$TASK/trace"
export DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_probe DB_USERNAME=kuking
export PGHOST=wrong PGPORT=1 PGDATABASE=wrong PGUSER=wrong
failures=0
check() {
    if "$@"; then return; fi
    echo "BŁĄD: $*"
    failures=$((failures + 1))
}
run() {
    : > "$TRACE"
    code=0
    bash "$TASK/scripts/check.sh" > "$TASK/output" 2>&1 || code=$?
}
run
check test "$code" = 0
check grep -q -- '-h 127.0.0.1 -p 55439 -d kuking_flota_probe -U kuking' "$TRACE"
check test "$(wc -l < "$TRACE")" = 1
export READY_STATUS=1
run
check test "$code" != 0
check grep -q '127.0.0.1:55439' "$TASK/output"
check test "$(grep -c UNEXPECTED_CLUSTER_START "$TRACE" || true)" = 0
unset READY_STATUS
for missing in DB_HOST DB_PORT DB_DATABASE DB_USERNAME; do
    (unset "$missing"; run; test "$code" != 0 && test ! -s "$TRACE") || {
        echo "BŁĄD: brak $missing nie zatrzymał sondy"; failures=$((failures + 1));
    }
done
DB_PORT=5432 run
check test "$code" != 0
check test ! -s "$TRACE"
if [ "$failures" -gt 0 ]; then exit 1; fi
echo 'Gotowość, niedostępność, brak parametrów i niedozwolony port: poprawnie.'
