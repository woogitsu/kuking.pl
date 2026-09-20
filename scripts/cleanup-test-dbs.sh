#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — sprzątanie porzuconych baz testowych (issue #66, #736)
# =============================================================================
#
#  PO CO TO JEST
#  Każda kopia robocza ma własną bazę `kuking_test_<katalog>_<skrót-ścieżki>`
#  (reguła: `tests/nazwa-bazy.php`). Rozwiązuje to kolizję równoległych
#  przebiegów `php artisan test`, ale wprowadza mniejszy problem: kiedy kopia
#  robocza zostaje skasowana, jej baza ZOSTAJE na dysku. Sto takich baz to
#  nowy bałagan w miejsce starego.
#
#  ── NAJWAŻNIEJSZA ZASADA TEGO SKRYPTU ───────────────────────────────────────
#
#  KIERUNEK POMYŁKI JEST ZAWSZE TEN SAM: NIE WIEM = ZOSTAWIAM.
#
#  Skasowanie bazy, na której ktoś akurat pracuje, jest nieodwracalne i kosztuje
#  cudzy dzień. Niesprzątnięta baza kosztuje kilkaset megabajtów. To nie są
#  porównywalne szkody, więc skrypt NIE kasuje bazy dlatego, że nie znalazł
#  dowodu życia. Kasuje wyłącznie bazę, dla której ma DOWÓD ŚMIERCI:
#
#      wpis w rejestrze (`~/.kuking-bazy-testowe/<nazwa-bazy>`) wskazuje
#      katalog, którego NIE MA na dysku.
#
#  Wpis zakłada każdy przebieg testów (`tests/bootstrap.php`) i hook startu
#  sesji. Baza bez wpisu — bo powstała przed tą zmianą, bo rejestr stoi
#  w innym `$HOME`, bo ktoś założył ją ręcznie — jest RAPORTOWANA i ZOSTAJE.
#
#  Drugi, niezależny bezpiecznik: baza z JAKIMKOLWIEK otwartym połączeniem
#  (`pg_stat_activity`) nie jest kasowana, choćby rejestr mówił co innego.
#  Żywy przebieg testów trzyma połączenie przez cały czas trwania.
#
#  Dlaczego nie da się tego zrobić bez rejestru: nazwa zawiera SKRÓT ścieżki,
#  a skrótu nie da się odwrócić. Poprzednia wersja skryptu czytała
#  `git worktree list` — to działa dla worktree i dalej jest tu używane jako
#  DODATKOWE źródło życia, ale nie widzi zwykłych klonów, a to one były
#  źródłem awarii z 19 września.
#
#  UŻYCIE
#      ./scripts/cleanup-test-dbs.sh            # pokaż i usuń osierocone bazy
#      ./scripts/cleanup-test-dbs.sh --sprawdz  # tylko pokaż, nic nie usuwaj
#
#  Bezpiecznie uruchamiać wielokrotnie.
# =============================================================================

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

# shellcheck source=scripts/port-bazy.sh
. "$(dirname "$0")/port-bazy.sh"

TRYB_SPRAWDZ=0
for _arg in "$@"; do
    case "$_arg" in
        --sprawdz) TRYB_SPRAWDZ=1 ;;
        *) printf "Nieznana opcja: %s\n" "$_arg" >&2; exit 2 ;;
    esac
done

ZOLTY=$'\033[0;33m'; ZIELONY=$'\033[0;32m'; CZERWONY=$'\033[0;31m'; RESET=$'\033[0m'

kuking_ustal_dostep_do_bazy . || exit 1

if ! pg_isready -q -h "$KUKING_DB_HOST" -p "$KUKING_DB_PORT" 2>/dev/null; then
    printf "${CZERWONY}PostgreSQL nie odpowiada na %s — nie ma czego sprzątać.${RESET}\n" \
        "$(kuking_opis_bazy)" >&2
    exit 1
fi

export PGPASSWORD="${PGPASSWORD:-kuking}"
PSQL=(psql -tA -U "$KUKING_DB_UZYTKOWNIK" -h "$KUKING_DB_HOST" -p "$KUKING_DB_PORT" -d postgres)

# Katalog rejestru pytamy PHP, a nie liczymy tu drugi raz — to ten sam powód,
# dla którego nazwa bazy też przychodzi z PHP. Dwie kopie reguły rozjadą się.
REJESTR="$(php -r 'require "tests/nazwa-bazy.php"; echo kuking_katalog_rejestru_baz();' 2>/dev/null)"
REJESTR="${REJESTR:-${KUKING_REJESTR_BAZ:-${HOME:-/tmp}/.kuking-bazy-testowe}}"

printf "${ZOLTY}── Sprzątanie baz testowych (%s, rejestr: %s) ──${RESET}\n" \
    "$(kuking_opis_bazy)" "$REJESTR"

# --- Źródło życia nr 1: worktree, które Git zna ------------------------------
# Nazwę bazy liczy TA SAMA funkcja, co w testach — nie przepisana tu drugi raz.
# Bez `vendor/`, bo `tests/nazwa-bazy.php` jest od niego wolny.
zywe_nazwy=""
while IFS= read -r katalog; do
    [ -z "$katalog" ] && continue
    [ -d "$katalog" ] || continue
    nazwa="$(php -r 'require "tests/nazwa-bazy.php"; echo kuking_nazwa_testowej_bazy($argv[1]);' "$katalog" 2>/dev/null)"
    [ -n "$nazwa" ] && zywe_nazwy="${zywe_nazwy}${nazwa}"$'\n'
done < <(git worktree list --porcelain 2>/dev/null | awk '/^worktree /{print substr($0, 10)}')

# --- Źródło życia nr 2: bazy z otwartym połączeniem --------------------------
zajete="$("${PSQL[@]}" -c \
    "SELECT DISTINCT datname FROM pg_stat_activity WHERE datname LIKE 'kuking\\_test\\_%'" 2>/dev/null)"

# --- Kandydaci: wszystkie bazy pasujące do wzorca ----------------------------
wszystkie_bazy="$("${PSQL[@]}" -c \
    "SELECT datname FROM pg_database WHERE datname LIKE 'kuking\\_test\\_%' ORDER BY datname" 2>/dev/null)"

if [ -z "$wszystkie_bazy" ]; then
    printf "${ZIELONY}Brak baz kuking_test_* — nie ma czego sprzątać.${RESET}\n"
    exit 0
fi

osierocone=""
while IFS= read -r baza; do
    [ -z "$baza" ] && continue

    # Bezpiecznik 1: ktoś jest podłączony. Koniec rozważań.
    if [ -n "$zajete" ] && grep -qxF "$baza" <<< "$zajete"; then
        printf "  ${ZIELONY}zostaje${RESET}     %s  (ktoś jest podłączony — trwa przebieg)\n" "$baza"
        continue
    fi

    # Bezpiecznik 2: to baza żywego worktree tej kopii.
    if [ -n "$zywe_nazwy" ] && grep -qxF "$baza" <<< "$zywe_nazwy"; then
        printf "  ${ZIELONY}zostaje${RESET}     %s  (worktree istnieje)\n" "$baza"
        continue
    fi

    wpis="${REJESTR}/${baza}"

    # Bezpiecznik 3 — TEN JEST SEDNEM: bez wpisu w rejestrze nie wiemy, czyja
    # to baza, więc jej nie ruszamy. Dotyczy też baz sprzed tej zmiany
    # i baz zakładanych ręcznie (np. kuking_test_a11y z scripts/dostepnosc.mjs).
    if [ ! -f "$wpis" ]; then
        printf "  ${ZOLTY}zostaje${RESET}     %s  (brak wpisu w rejestrze — nie wiem, czyja)\n" "$baza"
        continue
    fi

    katalog_kopii="$(head -n1 "$wpis" 2>/dev/null)"

    if [ -z "$katalog_kopii" ]; then
        printf "  ${ZOLTY}zostaje${RESET}     %s  (pusty wpis w rejestrze)\n" "$baza"
        continue
    fi

    if [ -d "$katalog_kopii" ]; then
        printf "  ${ZIELONY}zostaje${RESET}     %s  (kopia robocza istnieje: %s)\n" "$baza" "$katalog_kopii"
        continue
    fi

    printf "  ${ZOLTY}osierocona${RESET}  %s  (kopia robocza %s już nie istnieje)\n" "$baza" "$katalog_kopii"
    osierocone="${osierocone}${baza}"$'\n'
done <<< "$wszystkie_bazy"

if [ -z "${osierocone//[$'\n' ]/}" ]; then
    printf "\n${ZIELONY}Nic do usunięcia — żadna baza nie ma dowodu osierocenia.${RESET}\n"
    exit 0
fi

if [ "$TRYB_SPRAWDZ" -eq 1 ]; then
    printf "\n${ZOLTY}Tryb --sprawdz: nic nie usunięto.${RESET}\n"
    exit 0
fi

printf "\nUsuwam osierocone bazy:\n"
while IFS= read -r baza; do
    [ -z "$baza" ] && continue
    # Świadomie BEZ `WITH (FORCE)`: jeśli między sprawdzeniem a tą chwilą ktoś
    # zdążył się podłączyć, DROP ma się NIE UDAĆ, a nie zerwać cudze
    # połączenie. Zostanie na następny raz — to jest tańsze niż pomyłka.
    if "${PSQL[@]}" -c "DROP DATABASE IF EXISTS \"$baza\"" >/dev/null 2>&1; then
        rm -f "${REJESTR}/${baza}"
        printf "  ${ZIELONY}✓${RESET} usunięto %s\n" "$baza"
    else
        printf "  ${CZERWONY}✗${RESET} nie udało się usunąć %s (ktoś się podłączył?)\n" "$baza"
    fi
done <<< "$osierocone"
