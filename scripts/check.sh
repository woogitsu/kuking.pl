#!/usr/bin/env bash
#
# Kuking — pełna kontrola przed wysłaniem zmian.
#
# To jest DOKŁADNIE to samo, co robi CI. Uruchamiane lokalnie nie kosztuje
# ani minuty GitHub Actions, a łapie te same błędy.
#
#   ./scripts/check.sh          # pełna kontrola
#   ./scripts/check.sh --szybko # bez budowania assetów (szybsze przy pracy nad PHP)

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

SZYBKO=0
[ "${1:-}" = "--szybko" ] && SZYBKO=1

CZERWONY='\033[0;31m'; ZIELONY='\033[0;32m'; ZOLTY='\033[0;33m'; RESET='\033[0m'
BLEDY=0

krok() { printf "\n${ZOLTY}── %s ──${RESET}\n" "$1"; }
ok()   { printf "${ZIELONY}✓ %s${RESET}\n" "$1"; }
zle()  { printf "${CZERWONY}✗ %s${RESET}\n" "$1"; BLEDY=$((BLEDY + 1)); }

# --- 1. Baza danych -------------------------------------------------------
krok "PostgreSQL"
if ! pg_isready -q 2>/dev/null; then
    printf "Baza nie odpowiada — próbuję ją uruchomić…\n"
    for wersja in 18 17 16 15; do
        [ -d "/usr/lib/postgresql/$wersja" ] && { pg_ctlcluster "$wersja" main start >/dev/null 2>&1; break; }
    done
    sleep 2
fi

if pg_isready -q 2>/dev/null; then
    ok "PostgreSQL działa"
else
    zle "PostgreSQL nie działa — testy Kuking nie chodzą na SQLite"
    printf "  Uruchom: pg_ctlcluster 16 main start\n"
fi

# --- 2. Formatowanie ------------------------------------------------------
krok "Formatowanie (Pint)"
if vendor/bin/pint --test >/dev/null 2>&1; then
    ok "Kod sformatowany"
else
    zle "Kod wymaga sformatowania — uruchom: vendor/bin/pint"
fi

# --- 3. Składnia migracji i konfiguracji ----------------------------------
krok "Składnia PHP"
if find app config database routes tests -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null 2>&1; then
    ok "Brak błędów składni"
else
    zle "Błąd składni PHP — szczegóły: find app -name '*.php' | xargs -n1 php -l"
fi

# --- 4. Analiza statyczna (opcjonalna) ------------------------------------
krok "Analiza statyczna (PHPStan)"
if [ -x vendor/bin/phpstan ]; then
    if vendor/bin/phpstan analyse --no-progress --error-format=raw >/dev/null 2>&1; then
        ok "PHPStan bez zastrzeżeń"
    else
        zle "PHPStan zgłasza problemy — uruchom: vendor/bin/phpstan analyse"
    fi
else
    printf "  Pominięte: brak vendor/bin/phpstan (composer install)\n"
fi

# --- 5. Testy -------------------------------------------------------------
krok "Testy"
if php artisan test >/dev/null 2>&1; then
    ok "Testy przechodzą"
else
    zle "Testy nie przechodzą — uruchom: php artisan test"
fi

# --- 6. Odwracalność migracji --------------------------------------------
krok "Odwracalność migracji"
if php artisan migrate:refresh --force --env=testing --no-interaction >/dev/null 2>&1; then
    ok "Migracje cofają się i wracają"
else
    zle "Migracja nie ma działającego down() — nie da się jej wycofać podczas awarii"
fi

# --- 7. Assety ------------------------------------------------------------
if [ "$SZYBKO" -eq 0 ]; then
    krok "Build assetów"
    if npm run build >/dev/null 2>&1; then
        ok "Assety się budują"
    else
        zle "Build assetów nie przechodzi — uruchom: npm run build"
    fi
fi

# --- Podsumowanie ---------------------------------------------------------
printf "\n"
if [ "$BLEDY" -eq 0 ]; then
    printf "${ZIELONY}Wszystko w porządku. Można wysyłać.${RESET}\n"
    exit 0
fi

printf "${CZERWONY}Problemów do naprawienia: %s${RESET}\n" "$BLEDY"
exit 1
