#!/usr/bin/env bash
# Uruchamiamy prawdziwy początek check.sh na atrapach, bez dostępu do bazy.
#
# Pilnujemy dwóch rzeczy naraz:
#  1. sonda pyta DOKŁADNIE o wskazany endpoint i nie podnosi klastra,
#  2. w `check.sh` NIE MA zaszytego portu — port przychodzi z `DB_PORT`,
#     z wartością zapasową 5432 (jak `.env.example`, `phpunit.xml`
#     i `tests/skrypty/proba-odtworzenia.sh`). Zaszyta liczba oznaczałaby
#     `exit 1` zamiast kontroli u każdego, kto nie stoi na tym stanowisku:
#     w CI (port losowy) i w każdym świeżym klonie.
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
# Nazwa bazy i użytkownik nadal OBOWIĄZKOWE: to one decydują, co skasuje
# `migrate:refresh`, więc brak którejkolwiek ma zatrzymać kontrolę PRZED sondą.
for missing in DB_DATABASE DB_USERNAME; do
    (unset "$missing"; run; test "$code" != 0 && test ! -s "$TRACE") || {
        echo "BŁĄD: brak $missing nie zatrzymał sondy"; failures=$((failures + 1));
    }
done

# --- Port NIE jest zaszyty -------------------------------------------------
# Kontrola dodatnia: inny port niż ten stanowiska ma PRZEJŚĆ i trafić do sondy.
# Gdyby ktoś wpisał liczbę z powrotem, ten przebieg oblewa.
(
    export DB_PORT=6543
    run
    test "$code" = 0 && grep -q -- '-p 6543 ' "$TRACE"
) || { echo "BŁĄD: inny port nie przeszedł kontroli"; failures=$((failures + 1)); }

# Port 5432 to zwykły port, nie port zakazany — świeży klon nie ma innego.
(
    export DB_PORT=5432
    run
    test "$code" = 0 && grep -q -- '-p 5432 ' "$TRACE"
) || { echo "BŁĄD: port 5432 nie przeszedł kontroli"; failures=$((failures + 1)); }

# Brak DB_PORT: wartość zapasowa 5432, ta sama co w reszcie repozytorium.
(
    unset DB_PORT
    run
    test "$code" = 0 && grep -q -- '-p 5432 ' "$TRACE"
) || { echo "BŁĄD: brak DB_PORT nie wziął wartości zapasowej 5432"; failures=$((failures + 1)); }

# Brak DB_HOST: wartość zapasowa 127.0.0.1.
(
    unset DB_HOST
    run
    test "$code" = 0 && grep -q -- '-h 127.0.0.1 ' "$TRACE"
) || { echo "BŁĄD: brak DB_HOST nie wziął wartości zapasowej 127.0.0.1"; failures=$((failures + 1)); }

# Kontrola ujemna, która ZOSTAJE: kontrola kasuje wskazaną bazę, więc nie wolno
# jej skierować poza pętlę zwrotną — i to musi paść PRZED dotknięciem sondy.
(
    export DB_HOST=baza.produkcja.example
    run
    test "$code" != 0 && test ! -s "$TRACE"
) || { echo "BŁĄD: nielokalny DB_HOST nie zatrzymał sondy"; failures=$((failures + 1)); }

# Kontrola ujemna sprawdzająca SAM PRZYRZĄD: gdyby `run` nie wykonywał
# prawdziwego `check.sh`, wszystkie powyższe przeszłyby na pusto.
(
    export DB_PORT=6543
    run
    grep -q -- '-p 55439 ' "$TRACE"
) && { echo "BŁĄD: sonda poszła na zaszyty port mimo DB_PORT=6543"; failures=$((failures + 1)); }
if [ "$failures" -gt 0 ]; then exit 1; fi
echo 'Gotowość, niedostępność, brak parametrów, port ze zmiennej i nielokalny host: poprawnie.'
