#!/usr/bin/env bash
# Regresja #732: uruchamiamy PRAWDZIWY krok „PostgreSQL” z `scripts/check.sh`
# na atrapach `pg_isready` i `pg_ctlcluster` — bez dotykania żadnej bazy.
#
# Pilnujemy:
#  1. sonda pyta o host i port ze zmiennych DB_HOST / DB_PORT, a bez nich
#     o te same wartości co dotąd (127.0.0.1:5432) — nic nie trzeba eksportować;
#  2. w `check.sh` nie ma zaszytego portu stanowiska;
#  3. skrypt NIGDY nie uruchamia klastra (`pg_ctlcluster`) — ani dla portu
#     domyślnego, ani dla innego: to administracja cudzą usługą (#732);
#  4. komunikat o niedostępności nazywa sprawdzany adres;
#  5. baza i użytkownik z DB_DATABASE / DB_USERNAME trafiają do sondy tylko
#     wtedy, gdy są ustawione, a hasło nigdy nie trafia do wyjścia.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TASK="$(mktemp -d)"
trap 'rm -rf "$TASK"' EXIT
mkdir -p "$TASK/scripts" "$TASK/bin" "$TASK/pglib/18"
awk '/# --- 2\. Formatowanie/{exit} {print}' "$ROOT/scripts/check.sh" > "$TASK/scripts/check.sh"
grep -q 'krok "PostgreSQL"' "$TASK/scripts/check.sh"
cat > "$TASK/bin/pg_isready" <<'EOS'
#!/usr/bin/env bash
printf 'SONDA %s\n' "$*" >> "$TRACE"
exit "${READY_STATUS:-0}"
EOS
cat > "$TASK/bin/pg_ctlcluster" <<'EOS'
#!/usr/bin/env bash
printf 'UNEXPECTED_CLUSTER_START %s\n' "$*" >> "$TRACE"
exit 0
EOS
chmod +x "$TASK/bin/"*
export PATH="$TASK/bin:$PATH" TRACE="$TASK/trace" KUKING_PG_LIB="$TASK/pglib"
# Zmienne libpq nie mogą decydować o celu sondy.
export PGHOST=zly PGPORT=1
unset DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_URL READY_STATUS || true

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
grep -q 'UNEXPECTED_CLUSTER_START' "$TRACE" && blad "klaster uruchomiony, choć baza odpowiadała"
grep -q '✓ PostgreSQL odpowiada na 127.0.0.1:5432' "$TASK/output" || blad "brak komunikatu o gotowości z adresem"

# 2. Port ze zmiennej trafia do sondy.
(export DB_PORT=55439; run; grep -qx 'SONDA -q -h 127.0.0.1 -p 55439' "$TRACE") || blad "DB_PORT=55439 nie trafił do sondy"
(export DB_PORT=6543 DB_HOST=127.0.0.1; run; grep -qx 'SONDA -q -h 127.0.0.1 -p 6543' "$TRACE") || blad "DB_PORT=6543 nie trafił do sondy"

# 3. Port domyślny niedostępny: klastra NIE ruszamy, komunikat z adresem (kod wyjścia ustawia dopiero koniec check.sh, poza wyciętym krokiem).
(
    export READY_STATUS=1
    run
    ! grep -q 'UNEXPECTED_CLUSTER_START' "$TRACE" \
        && grep -q '✗ PostgreSQL nie odpowiada na 127.0.0.1:5432' "$TASK/output" \
        && grep -q 'niczego nie uruchamia' "$TASK/output"
) || blad "przy niedostępnym 5432 skrypt ruszył klaster, albo nie nazwał adresu"

# 4. Inny port niedostępny: to samo, komunikat nazywa adres.
(
    export READY_STATUS=1 DB_PORT=55439
    run
    ! grep -q 'UNEXPECTED_CLUSTER_START' "$TRACE" \
        && grep -q '✗ PostgreSQL nie odpowiada na 127.0.0.1:55439' "$TASK/output"
) || blad "przy niedostępnym 55439 skrypt ruszył klaster, albo nie nazwał adresu"

# 5. Portu stanowiska nie ma w skrypcie.
grep -q '55439' <(grep -v '^[[:space:]]*#' "$ROOT/scripts/check.sh") && blad "check.sh ma zaszyty port 55439"

# 6. Baza i użytkownik, gdy są podane, trafiają do sondy; hasło — nigdzie.
(
    export DB_PORT=55439 DB_DATABASE=kuking_test_zadanie DB_USERNAME=kuking DB_PASSWORD=tajne-haslo-732
    run
    grep -qx 'SONDA -q -h 127.0.0.1 -p 55439 -d kuking_test_zadanie -U kuking' "$TRACE" \
        && ! grep -q 'tajne-haslo-732' "$TRACE" "$TASK/output" \
        && grep -q 'nie sprawdza hasła' "$TASK/output"
) || blad "DB_DATABASE/DB_USERNAME nie trafiły do sondy, hasło wyciekło albo brak zastrzeżenia o haśle"
(
    export READY_STATUS=1 DB_PORT=55439 DB_PASSWORD=tajne-haslo-732
    run
    ! grep -q 'tajne-haslo-732' "$TRACE" "$TASK/output"
) || blad "przy niedostępnej bazie hasło trafiło do wyjścia"

# Kontrola przyrządu: sonda bez portu MUSI zostać wykryta. Mutujemy kopię.
sed -i 's/ -p "\$_pg_port"//' "$TASK/scripts/check.sh"
(export DB_PORT=6543; run; grep -q -- '-p 6543' "$TRACE") && blad "przyrząd nie wykrywa sondy bez portu"

# Kontrola przyrządu nr 2: przywrócony start klastra MUSI zostać wykryty
# (świeża kopia kroku + dopisana próba startu, jak w wersji sprzed #732).
awk '/# --- 2\. Formatowanie/{exit} {print}' "$ROOT/scripts/check.sh" > "$TASK/scripts/check.sh"
python3 - "$TASK/scripts/check.sh" <<'EOPY'
import sys
p = sys.argv[1]
s = open(p).read()
cel = "if sonda_pg; then\n    ok"
assert s.count(cel) == 1, "przyrząd: nie ma miejsca na mutację"
s = s.replace(cel, "if ! sonda_pg; then pg_ctlcluster 18 main start; fi\n" + cel)
open(p, 'w').write(s)
EOPY
grep -q 'pg_ctlcluster 18 main start' "$TASK/scripts/check.sh" || blad "przyrząd: mutacja startu klastra nie weszła"
(export READY_STATUS=1; run; grep -q 'UNEXPECTED_CLUSTER_START' "$TRACE") || blad "przyrząd nie wykrywa przywróconego startu klastra"

if [ "$failures" -gt 0 ]; then exit 1; fi
echo 'Sonda PostgreSQL: port i host ze zmiennych, domyślne 127.0.0.1:5432, bez uruchamiania klastra — poprawnie.'
