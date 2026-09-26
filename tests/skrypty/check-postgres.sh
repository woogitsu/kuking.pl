#!/usr/bin/env bash
# Regresja #732: uruchamiamy PRAWDZIWY krok „PostgreSQL” z `scripts/check.sh`
# na atrapach `pg_isready` i `pg_ctlcluster` — bez dotykania żadnej bazy.
#
# Pilnujemy:
#  1. sonda pyta o host i port ze zmiennych DB_HOST / DB_PORT, a bez nich
#     o te same wartości co dotąd (127.0.0.1:5432) — nic nie trzeba eksportować;
#  2. w `check.sh` nie ma zaszytego portu stanowiska;
#  3. lokalny start klastra działa jak dotąd dla portu domyślnego,
#     a dla innego portu (cudza instancja) klaster nie jest ruszany;
#  4. komunikat o niedostępności nazywa sprawdzany adres.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TASK="$(mktemp -d)"
trap 'rm -rf "$TASK"' EXIT
mkdir -p "$TASK/scripts" "$TASK/bin" "$TASK/pglib/18"
awk '/# --- 2\. Formatowanie/{exit} {print}' "$ROOT/scripts/check.sh" > "$TASK/scripts/check.sh"
grep -q 'krok "PostgreSQL"' "$TASK/scripts/check.sh"
# `sleep 2` po starcie klastra nie ma tu nic do czekania.
cat > "$TASK/bin/sleep" <<'EOS'
#!/usr/bin/env bash
exit 0
EOS
cat > "$TASK/bin/pg_isready" <<'EOS'
#!/usr/bin/env bash
printf 'SONDA %s\n' "$*" >> "$TRACE"
exit "${READY_STATUS:-0}"
EOS
cat > "$TASK/bin/pg_ctlcluster" <<'EOS'
#!/usr/bin/env bash
printf 'START %s\n' "$*" >> "$TRACE"
exit 0
EOS
chmod +x "$TASK/bin/"*
export PATH="$TASK/bin:$PATH" TRACE="$TASK/trace" KUKING_PG_LIB="$TASK/pglib"
# Zmienne libpq nie mogą decydować o celu sondy.
export PGHOST=zly PGPORT=1
unset DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_URL READY_STATUS || true

failures=0
blad() { echo "BŁĄD: $1"; failures=$((failures + 1)); }
run() {
    : > "$TRACE"
    code=0
    bash "$TASK/scripts/check.sh" > "$TASK/output" 2>&1 || code=$?
}

# 1. Bez żadnej zmiennej: domyślne 127.0.0.1:5432, bez startu klastra.
run
[ "$code" = 0 ] || blad "bez zmiennych krok kończy się kodem $code"
grep -qx 'SONDA -q -h 127.0.0.1 -p 5432' "$TRACE" || blad "bez zmiennych sonda nie pyta o 127.0.0.1:5432: $(cat "$TRACE")"
grep -q '^START' "$TRACE" && blad "klaster uruchomiony, choć baza odpowiadała"
grep -q '✓ PostgreSQL odpowiada na 127.0.0.1:5432' "$TASK/output" || blad "brak komunikatu o gotowości z adresem"

# 2. Port ze zmiennej trafia do sondy.
(export DB_PORT=55439; run; grep -qx 'SONDA -q -h 127.0.0.1 -p 55439' "$TRACE") || blad "DB_PORT=55439 nie trafił do sondy"
(export DB_PORT=6543 DB_HOST=127.0.0.1; run; grep -qx 'SONDA -q -h 127.0.0.1 -p 6543' "$TRACE") || blad "DB_PORT=6543 nie trafił do sondy"

# 3. Port domyślny niedostępny: start klastra JAK DOTĄD.
(
    export READY_STATUS=1
    run
    grep -qx 'START 18 main start' "$TRACE" && grep -q '✗ PostgreSQL nie odpowiada na 127.0.0.1:5432' "$TASK/output"
) || blad "przy niedostępnym 5432 nie było próby startu lokalnego klastra albo komunikatu z adresem"

# 4. Inny port niedostępny: cudzej instancji nie ruszamy, komunikat nazywa adres.
(
    export READY_STATUS=1 DB_PORT=55439
    run
    ! grep -q '^START' "$TRACE" && grep -q '✗ PostgreSQL nie odpowiada na 127.0.0.1:55439' "$TASK/output"
) || blad "przy niedostępnym 55439 skrypt ruszył klaster albo nie nazwał adresu"

# 5. Portu stanowiska nie ma w skrypcie.
grep -q '55439' <(grep -v '^[[:space:]]*#' "$ROOT/scripts/check.sh") && blad "check.sh ma zaszyty port 55439"

# Kontrola przyrządu: sonda bez portu MUSI zostać wykryta. Mutujemy kopię.
sed -i 's/ -p "\$_pg_port"//' "$TASK/scripts/check.sh"
(export DB_PORT=6543; run; grep -q -- '-p 6543' "$TRACE") && blad "przyrząd nie wykrywa sondy bez portu"

if [ "$failures" -gt 0 ]; then exit 1; fi
echo 'Sonda PostgreSQL: port i host ze zmiennych, domyślne 127.0.0.1:5432, start klastra tylko dla portu domyślnego — poprawnie.'
