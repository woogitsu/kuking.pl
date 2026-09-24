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
#   ./scripts/check.sh --referrer   # dwa dokumenty i formularze; wymaga jawnego
#                                   # REFERRER_DB_DATABASE=kuking_port_* po migracji

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

SZYBKO=0
SPRAWDZ_DOSTEPNOSC=0
SPRAWDZ_WYDAJNOSC=0
SPRAWDZ_WYSCIGI=0
SPRAWDZ_REFERRER=0

# Pętla, a nie `[ "$1" = ... ]`: flagi mają działać w dowolnej kolejności
# i dowolnej liczbie. Poprzednia wersja czytała wyłącznie PIERWSZY argument,
# więc `--dostepnosc --szybko` po cichu gubiłoby drugą flagę.
for _arg in "$@"; do
    case "$_arg" in
        --szybko) SZYBKO=1 ;;
        --dostepnosc) SPRAWDZ_DOSTEPNOSC=1 ;;
        --wydajnosc) SPRAWDZ_WYDAJNOSC=1 ;;
        --wyscigi) SPRAWDZ_WYSCIGI=1 ;;
        --referrer) SPRAWDZ_REFERRER=1 ;;
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
elif ! bash tests/skrypty/preflight-bazy.sh >/dev/null 2>&1; then
    zle "Preflight bazy w entrypoincie oblewa — uruchom: bash tests/skrypty/preflight-bazy.sh"
elif ! bash tests/skrypty/php-ini-slady.sh >/dev/null 2>&1; then
    # Obraz FrankenPHP nie ma php.ini-production — bez tej dyrektywy w
    # docker/php.ini ślady wyjątków niosą prefiksy argumentów, także sekretów.
    zle "Ślady wyjątków w docker/php.ini niosą argumenty — uruchom: bash tests/skrypty/php-ini-slady.sh"
elif ! bash tests/skrypty/kopia-bazy.sh >/dev/null 2>&1; then
    # Kopia bazy to też skrypt powłoki, w obrazie bez PHP (decyzja D-043),
    # więc żaden test PHPUnit go nie dotknie. A jest to dziś JEDYNA planowana
    # kopia bazy — Railway na Free/Hobby nie robi żadnych.
    zle "Testy kopii bazy oblewają — uruchom: bash tests/skrypty/kopia-bazy.sh"
elif ! bash tests/skrypty/cache-assetow.sh >/dev/null 2>&1; then
    zle "Sonda cache oblewa — uruchom: bash tests/skrypty/cache-assetow.sh"
elif ! bash tests/skrypty/kontrola-ujemna.sh >/dev/null 2>&1; then
    # Przyrząd do kontroli ujemnych (`scripts/kontrola-ujemna.sh`) pilnuje,
    # żeby mutacja, która nie trafiła, nie udawała wykonanej kontroli. Sam bez
    # kontroli ujemnej byłby tym, co naprawia: narzędziem meldującym sukces bez
    # roboty (PULAPKI_TESTOW §5). Ten przebieg podaje mu m.in. mutację, która
    # NIE trafia, i sprawdza, że odmawia. Bez bazy, poniżej sekundy.
    zle "Przyrząd kontroli ujemnych oblewa — uruchom: bash tests/skrypty/kontrola-ujemna.sh"
elif ! bash tests/skrypty/kontrola-sondy-wdrozenia.sh >/dev/null 2>&1; then
    # Sondy testu dymnego po wdrożeniu (#1012, #1332) chodzą tylko w GitHub
    # Actions, na produkcji — tu sprawdzamy je na atrapach curl, bez sieci.
    zle "Sondy testu dymnego oblewają — uruchom: bash tests/skrypty/kontrola-sondy-wdrozenia.sh"
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

# Osobna, wcześniej zmigrowana baza kuking_port_*; bez domyślnego celu i kasowania danych.
krok "Sekretny adres i referrer (#1052)"
if [ "$SPRAWDZ_REFERRER" -ne 1 ]; then
    printf "  Pominięte: uruchom z --referrer i jawnym REFERRER_DB_DATABASE\n"
elif [ -z "${REFERRER_DB_DATABASE:-}" ]; then
    zle "Podaj REFERRER_DB_DATABASE własnej zmigrowanej bazy kuking_port_*"
elif DB_DATABASE="$REFERRER_DB_DATABASE" node scripts/referrer-sekret-browser.mjs; then
    ok "Dwa dokumenty, przechwycona analityka i formularze przechodzą"
else
    zle "Pomiar referrera nie przeszedł — brak przeglądarki też jest błędem"
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
else
    _phpstan_log=$(mktemp "${TMPDIR:-/tmp}/kuking-check-phpstan.XXXXXX")
    if vendor/bin/phpstan analyse --no-progress --error-format=raw >"$_phpstan_log" 2>&1; then
        rm -f "$_phpstan_log"
        ok "PHPStan bez zastrzeżeń"
    else
        printf 'Wynik PHPStana zapisano w: %s\n' "$_phpstan_log"
        tail -n 80 "$_phpstan_log"
        zle "PHPStan zgłasza problemy — uruchom: vendor/bin/phpstan analyse"
    fi
fi

# --- 5. Testy -------------------------------------------------------------
# KUKING_TESTY_ROWNOLEGLE=N puszcza baterię na N procesach. Domyślnie PUSTE,
# czyli szeregowo — i tak ma zostać. Równoległość jest świadomym wyborem
# stanowiska, które wie, ile rdzeni ma wolnych, a nie zachowaniem domyślnym.
#
# Po co: zmierzone 21.09.2026 na 4402 testach — 310 s szeregowo, 105 s na
# sześciu procesach. Bateria zajmowała 82% czasu bramki i chodziła na JEDNYM
# rdzeniu z 24.
#
# Czego NIE wolno zapomnieć: przy zwykłym przeciążeniu obowiązuje reguła
# „fałszywa czerwień, nigdy fałszywa zieleń" — przebieg zielony pod obciążeniem
# jest wiarygodny. Równoległość tę regułę OSŁABIA: test zależny od kolejności
# albo współdzielonego stanu może się pod nią zachować inaczej. W pomiarze
# z 21.09 liczba testów zgadzała się co do jednego (4402), ale asercji było
# 83722 szeregowo i 83721 równolegle. Ta jedna różnica jest nadal niewyjaśniona.
# Dlatego domyślnie szeregowo, a równolegle tylko tam, gdzie liczy się czas
# i ktoś ten kompromis podjął świadomie.
#
# --recreate-databases jest konieczne, nie kosmetyczne: bazy robocze
# <baza>_test_N przeżywają między przebiegami, a kolejne gałęzie mają różne
# migracje. Bez tego druga gałąź dostałaby schemat pierwszej.
krok "Testy"
_test_log=$(mktemp "${TMPDIR:-/tmp}/kuking-check-tests.XXXXXX")
_test_polecenie=(php artisan test)
_test_podpowiedz='php artisan test'
if [ -n "${KUKING_TESTY_ROWNOLEGLE:-}" ]; then
    if [ ! -x vendor/bin/paratest ]; then
        # Cicha ucieczka do szeregowych byłaby najgorsza z możliwych: bramka
        # trwałaby trzy razy dłużej, nikt by nie wiedział czemu, a przyczyną
        # byłby brakujący pakiet. Mówimy wprost.
        zle "KUKING_TESTY_ROWNOLEGLE ustawione, a brak vendor/bin/paratest — uruchom: composer install"
    fi
    _test_polecenie=(php artisan test --parallel \
        --processes="$KUKING_TESTY_ROWNOLEGLE" --recreate-databases)
    _test_podpowiedz="php artisan test --parallel --processes=$KUKING_TESTY_ROWNOLEGLE"
    printf '  Bateria na %s procesach (KUKING_TESTY_ROWNOLEGLE)\n' "$KUKING_TESTY_ROWNOLEGLE"
fi
if "${_test_polecenie[@]}" >"$_test_log" 2>&1; then
    rm -f "$_test_log"
    ok "Testy przechodzą"
else
    printf 'Pełny wynik testów zapisano w: %s\n' "$_test_log"
    printf '%s\n' 'Ostatnie 160 wierszy wyniku:'
    tail -n 160 "$_test_log"
    zle "Testy nie przechodzą — uruchom: $_test_podpowiedz"
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
_migrate_log=$(mktemp "${TMPDIR:-/tmp}/kuking-check-migrate.XXXXXX")
if php artisan migrate:refresh --force --env=testing --no-interaction >"$_migrate_log" 2>&1; then
    rm -f "$_migrate_log"
    ok "Migracje cofają się i wracają"
else
    printf 'Wynik migracji zapisano w: %s\n' "$_migrate_log"
    tail -n 80 "$_migrate_log"
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
