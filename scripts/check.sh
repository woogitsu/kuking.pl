#!/usr/bin/env bash
#
# Kuking — pełna kontrola przed wysłaniem zmian.
#
# Bazowe kontrole uruchamiane także przez pre-push. CI ma dodatkowe
# obowiązkowe zadania, m.in. pełny port marki, zoom i kontrole ujemne.
# Sukces hooka nie zastępuje wyniku CI ani odbioru wdrożenia.
#
#   ./scripts/check.sh          # pełna kontrola
#   ./scripts/check.sh --szybko # bez budowania assetów (szybsze przy pracy nad PHP)
#   ./scripts/check.sh --dostepnosc # dodatkowo aktualna macierz axe i układu
#                                   # oraz pomiar układu przy 320/360/414/768 px
#   ./scripts/check.sh --wydajnosc  # dodatkowo Lighthouse (wydajność + SEO)
#                                   # na 8 stronach publicznych (issue #26)
#   ./scripts/check.sh --wyscigi    # dodatkowo grupa `dwa-polaczenia`: testy
#                                   # na dwóch połączeniach (D-105)

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

SZYBKO=0
SPRAWDZ_DOSTEPNOSC=0
SPRAWDZ_WYDAJNOSC=0
SPRAWDZ_WYSCIGI=0

# Pętla, a nie `[ "$1" = ... ]`: flagi mają działać w dowolnej kolejności
# i dowolnej liczbie. Poprzednia wersja czytała wyłącznie PIERWSZY argument,
# więc `--dostepnosc --szybko` po cichu gubiłoby drugą flagę.
for _arg in "$@"; do
    case "$_arg" in
        --szybko) SZYBKO=1 ;;
        --dostepnosc) SPRAWDZ_DOSTEPNOSC=1 ;;
        --wydajnosc) SPRAWDZ_WYDAJNOSC=1 ;;
        --wyscigi) SPRAWDZ_WYSCIGI=1 ;;
        *) printf "Nieznana opcja: %s\n" "$_arg" >&2; exit 2 ;;
    esac
done

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
for _skrypt in docker/entrypoint.sh docker/kopia/*.sh scripts/*.sh tests/skrypty/*.sh; do
    [ -f "$_skrypt" ] || continue
    bash -n "$_skrypt" 2>/dev/null || _bledy_bash="$_bledy_bash $_skrypt"
done

if [ -n "$_bledy_bash" ]; then
    zle "Błąd składni w:$_bledy_bash"
elif ! bash tests/skrypty/entrypoint-nadzor.sh >/dev/null 2>&1; then
    zle "Testy entrypointu oblewają — uruchom: bash tests/skrypty/entrypoint-nadzor.sh"
elif ! bash tests/skrypty/kopia-bazy.sh >/dev/null 2>&1; then
    # Kopia bazy to też skrypt powłoki, w obrazie bez PHP (decyzja D-043),
    # więc żaden test PHPUnit go nie dotknie. A jest to dziś JEDYNA planowana
    # kopia bazy — Railway na Free/Hobby nie robi żadnych.
    zle "Testy kopii bazy oblewają — uruchom: bash tests/skrypty/kopia-bazy.sh"
else
    ok "Składnia i testy skryptów powłoki przechodzą"
fi

# --- 3c. Przyrząd do testu obciążeniowego (#605) ---------------------------
# Regresje NARZĘDZIA POMIAROWEGO, nie produktu. Bez bazy, bez PHP, bez sieci
# poza własnym serwerem scenariuszy na porcie przydzielanym dynamicznie.
# Pilnuje usterki z 18.09.2026: żądanie, którego odpowiedź została urwana po
# nagłówkach, nie kończyło pomiaru, a limit mierzył bezczynność gniazda zamiast
# czasu żądania. Odtworzenie:
# docs/infra/evidence/obciazenie605/ODTWORZENIE_ZAWIESZENIA.md
krok "Przyrząd obciążeniowy (#605)"
if ! command -v node >/dev/null 2>&1; then
    zle "Brak node — nie sprawdzono przyrządu #605 (to jest brak kontroli, nie sukces)"
elif node scripts/przyrzad-605.test.mjs >/dev/null 2>&1; then
    ok "Regresje i kontrole ujemne przyrządu przechodzą"
else
    zle "Przyrząd #605 oblewa — uruchom: node scripts/przyrzad-605.test.mjs"
fi

# --- 3c. Dostępność (opcjonalna) -------------------------------------------
# Automat axe łapie około 30% problemów z dostępnością — ale dokładnie te,
# które najłatwiej wprowadzić przypadkiem: pole bez etykiety, przycisk bez
# nazwy dostępnej, kontrast zepsuty jedną „drobną poprawką" koloru.
#
# DOMYŚLNIE POMIJANY, bo podnosi przeglądarkę i bazę: to jest kilkadziesiąt
# sekund, a `check.sh` chodzi przed każdym commitem. Uruchamiaj przy zmianach
# w widokach i w CSS-ie:  ./scripts/check.sh --dostepnosc
#
# Ten sam przebieg mierzy też UKŁAD (issue #80): czy strona nie przewija się
# w bok przy 320, 360, 414 i 768 px, także przy powiększonym tekście. Reflow
# nie jest regułą axe — trzeba zmierzyć ułożoną stronę — a bez tego pomiaru
# belka górna wychodziła poza ekran telefonu na każdej stronie serwisu
# i żaden automat tego nie zgłaszał.
krok "Dostępność (axe) i układ na wąskim ekranie"
if [ "$SPRAWDZ_DOSTEPNOSC" -ne 1 ]; then
    printf "  Pominięte: uruchom './scripts/check.sh --dostepnosc' przy zmianach w widokach\n"
elif [ ! -d node_modules/@axe-core ]; then
    printf "  Pominięte: brak @axe-core/playwright (npm install)\n"
elif DB_DATABASE=kuking_test_a11y node scripts/dostepnosc.mjs >/dev/null 2>&1; then
    ok "Zero naruszeń critical i serious, strona nie przewija się w bok"
else
    zle "Naruszenia dostępności albo przewijanie w bok — szczegóły: node scripts/dostepnosc.mjs (i storage/dostepnosc.json)"
fi

# --- 3d. Wydajność i SEO (opcjonalna) --------------------------------------
# Lighthouse na 8 stronach publicznych (issue #26, druga połowa). Mierzy
# WYŁĄCZNIE `performance` i `seo` — dostępność już liczy krok wyżej (axe-core),
# a Lighthouse pod spodem użyłby dokładnie tego samego silnika, tylko wolniej.
# Progi i pełne uzasadnienie: nagłówek `scripts/wydajnosc.mjs`.
#
# DOMYŚLNIE POMIJANY z tego samego powodu co axe: podnosi przeglądarkę i bazę,
# a do tego CZEKA na throttling sieci przy każdym ekranie — kilkadziesiąt
# sekund więcej niż sam axe. Uruchamiaj przy zmianach w widokach:
#   ./scripts/check.sh --wydajnosc
krok "Wydajność i SEO (Lighthouse)"
if [ "$SPRAWDZ_WYDAJNOSC" -ne 1 ]; then
    printf "  Pominięte: uruchom './scripts/check.sh --wydajnosc' przy zmianach w widokach\n"
elif [ ! -d node_modules/lighthouse ]; then
    printf "  Pominięte: brak paczki 'lighthouse' (npm install — do zrobienia przez właściciela)\n"
elif DB_DATABASE=kuking_test_wydajnosc node scripts/wydajnosc.mjs >/dev/null 2>&1; then
    ok "Wydajność i SEO powyżej progów na wszystkich mierzonych ekranach"
else
    zle "Wydajność albo SEO poniżej progu — szczegóły: node scripts/wydajnosc.mjs (i storage/wydajnosc.json)"
fi

# --- 4. Analiza statyczna --------------------------------------------------
# Issue #32 zamknięte: `phpstan.neon` istnieje (poziom i uzasadnienie —
# komentarz na górze tego pliku), więc ten krok PRZESTAJE być opcjonalny.
# Brak `vendor/bin/phpstan` albo brakująca konfiguracja to teraz BŁĄD
# kontroli, nie ciche pominięcie — inaczej ten sam krok znowu potrafiłby
# zniknąć bez wiadomości, tak jak zanim powstało issue #32.
krok "Analiza statyczna (PHPStan)"
if [ ! -x vendor/bin/phpstan ]; then
    zle "Brak vendor/bin/phpstan — uruchom: composer install"
elif ! { [ -f phpstan.neon ] || [ -f phpstan.neon.dist ] || [ -f phpstan.dist.neon ]; }; then
    zle "Brak konfiguracji PHPStana (phpstan.neon) — patrz issue #32"
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

# --- 5b. Wyścigi na dwóch połączeniach (opcjonalne) ------------------------
# Grupa `dwa-polaczenia` (D-105): jedyne testy w tym repozytorium, które chodzą
# na DWÓCH połączeniach do PostgreSQL i widzą zakleszczenia. Zwykły `php artisan
# test` ich nie uruchamia i tak ma zostać — nie używają `RefreshDatabase`,
# zatwierdzają dane naprawdę i potrzebują własnej bazy `kuking_race_*`.
#
# DOMYŚLNIE POMIJANE, bo zakładają i migrują tę bazę, a `check.sh` chodzi przed
# każdym commitem. Uruchamiaj przy zmianach w kolejności blokad — czyli wszędzie
# tam, gdzie w grę wchodzi `ZamekPary`, `ZamekKonta`, `EraseAccountData` albo
# nowy `lockForUpdate()`:  ./scripts/check.sh --wyscigi
krok "Wyścigi na dwóch połączeniach"
if [ "$SPRAWDZ_WYSCIGI" -ne 1 ]; then
    printf "  Pominięte: uruchom './scripts/check.sh --wyscigi' przy zmianach w kolejności blokad\n"
elif ./scripts/testy-dwa-polaczenia.sh >/dev/null 2>&1; then
    ok "Grupa dwa-polaczenia przechodzi"
else
    zle "Grupa dwa-polaczenia oblewa — szczegóły: ./scripts/testy-dwa-polaczenia.sh"
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
