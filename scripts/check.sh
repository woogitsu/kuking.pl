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

# --- 3b. Skrypty powłoki ---------------------------------------------------
# Entrypoint kontenera to kod, który decyduje o tym, czy serwis w ogóle żyje —
# a żaden test PHPUnit go nie dotknie. Awaria z 5–6 września 2026 (3,5 godziny
# niedostępności) siedziała dokładnie tam: w tym, jak skrypt powłoki odróżnia
# „proces się skończył" od „proces padł".
krok "Skrypty powłoki"
_bledy_bash=""
for _skrypt in docker/entrypoint.sh scripts/*.sh tests/skrypty/*.sh; do
    [ -f "$_skrypt" ] || continue
    bash -n "$_skrypt" 2>/dev/null || _bledy_bash="$_bledy_bash $_skrypt"
done

if [ -n "$_bledy_bash" ]; then
    zle "Błąd składni w:$_bledy_bash"
elif bash tests/skrypty/entrypoint-nadzor.sh >/dev/null 2>&1; then
    ok "Składnia i testy entrypointu przechodzą"
else
    zle "Testy entrypointu oblewają — uruchom: bash tests/skrypty/entrypoint-nadzor.sh"
fi

# --- 4. Analiza statyczna (opcjonalna) ------------------------------------
# UWAGA na warunek: `ls a b c` kończy się niezerowo, gdy brakuje
# KTÓREGOKOLWIEK z plików, a nie dopiero gdy brakuje wszystkich. Użycie `ls`
# pomijałoby analizę także po dodaniu poprawnej konfiguracji.
#
# Bez pliku konfiguracyjnego PHPStan nie ma czego analizować i kończy się
# błędem „At least one path must be specified". Zgłaszanie tego jako problemu
# do naprawienia sprawiłoby, że kontrola NIGDY nie jest zielona — a wtedy
# przestaje cokolwiek znaczyć. Konfigurację dokłada issue #32; do tego czasu
# krok jest świadomie pomijany (tak samo jak job `static-analysis` w CI).
krok "Analiza statyczna (PHPStan)"
if [ ! -x vendor/bin/phpstan ]; then
    printf "  Pominięte: brak vendor/bin/phpstan (composer install)\n"
elif ! { [ -f phpstan.neon ] || [ -f phpstan.neon.dist ] || [ -f phpstan.dist.neon ]; }; then
    printf "  Pominięte: brak konfiguracji PHPStana — issue #32\n"
elif vendor/bin/phpstan analyse --no-progress --error-format=raw >/dev/null 2>&1; then
    ok "PHPStan bez zastrzeżeń"
else
    zle "PHPStan zgłasza problemy — uruchom: vendor/bin/phpstan analyse"
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
