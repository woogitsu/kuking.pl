#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — sprzątanie porzuconych baz testowych po worktree (issue #66)
# =============================================================================
#
#  PO CO TO JEST
#  Od naprawy issue #66 każdy `git worktree` dostaje własną bazę testową
#  `kuking_test_<nazwa-worktree>` (patrz tests/bootstrap.php) — to rozwiązuje
#  kolizję dwóch równoległych `php artisan test`, ale wprowadza nowy,
#  mniejszy problem: kiedy worktree zostaje usunięty (`git worktree remove`
#  albo po prostu `rm -rf` katalogu), jego baza testowa ZOSTAJE na dysku.
#  Sto takich baz to nowy bałagan w miejsce starego.
#
#  Ten skrypt usuwa WYŁĄCZNIE bazy `kuking_test_<coś>`, których worktree
#  faktycznie już nie istnieje (git go nie zna) — nigdy `kuking_test` samo
#  w sobie i nigdy bazy żywych worktree'ów.
#
#  UŻYCIE
#      ./scripts/cleanup-test-dbs.sh            # pokaż i usuń osierocone bazy
#      ./scripts/cleanup-test-dbs.sh --sprawdz   # tylko pokaż, nic nie usuwaj
#
#  Bezpiecznie uruchamiać wielokrotnie — baza żywego worktree nigdy nie jest
#  kandydatem do usunięcia, więc nie da się tym przypadkiem skasować bazy,
#  na której ktoś akurat pracuje.
# =============================================================================

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

TRYB_SPRAWDZ=0
for _arg in "$@"; do
    case "$_arg" in
        --sprawdz) TRYB_SPRAWDZ=1 ;;
        *) printf "Nieznana opcja: %s\n" "$_arg" >&2; exit 2 ;;
    esac
done

ZOLTY=$'\033[0;33m'; ZIELONY=$'\033[0;32m'; CZERWONY=$'\033[0;31m'; RESET=$'\033[0m'

if ! pg_isready -q 2>/dev/null; then
    printf "${CZERWONY}PostgreSQL nie odpowiada — nie ma czego sprzątać.${RESET}\n" >&2
    exit 1
fi

export PGPASSWORD="${PGPASSWORD:-kuking}"

# Worktree, które Git ZNA — jedyne dozwolone sufiksy nazw baz. Nazwa worktree
# w Gicie to ostatni segment ścieżki po "worktrees/" (to samo źródło, którego
# używa tests/bootstrap.php do budowania nazwy bazy), po tej samej zamianie
# znaków niebezpiecznych na "_", jakiej dokonuje bootstrap.
zywe_worktree="$(git worktree list --porcelain 2>/dev/null \
    | awk '/^worktree /{print $2}' \
    | xargs -n1 basename \
    | sed 's/[^a-zA-Z0-9_]/_/g')"

# Wszystkie bazy pasujące do wzorca nazw z tests/bootstrap.php:
# "kuking_test_<coś>" (ale NIE samo "kuking_test").
wszystkie_bazy="$(psql -tAc "SELECT datname FROM pg_database WHERE datname LIKE 'kuking\_test\_%' ESCAPE '\\'" \
    -U kuking -h 127.0.0.1 -d postgres 2>/dev/null)"

if [ -z "$wszystkie_bazy" ]; then
    printf "${ZIELONY}Brak baz kuking_test_* — nie ma czego sprzątać.${RESET}\n"
    exit 0
fi

osierocone=""
while IFS= read -r baza; do
    [ -z "$baza" ] && continue
    sufiks="${baza#kuking_test_}"

    if grep -qxF "$sufiks" <<< "$zywe_worktree"; then
        printf "  ${ZIELONY}zostaje${RESET}  %s  (worktree istnieje)\n" "$baza"
    else
        printf "  ${ZOLTY}osierocona${RESET}  %s  (worktree już nie istnieje)\n" "$baza"
        osierocone="$osierocone $baza"
    fi
done <<< "$wszystkie_bazy"

# Baza z axe-core (scripts/dostepnosc.mjs, `check.sh --dostepnosc`) używa
# TEJ SAMEJ konwencji przedrostka, ale to nie jest baza per-worktree — nie
# ma jej co porównywać z listą worktree'ów, więc nigdy jej nie ruszamy.
osierocone="$(tr ' ' '\n' <<< "$osierocone" | grep -vx 'kuking_test_a11y' | tr '\n' ' ')"

# Bazy "kuking_test_kopia_<katalog>_<skrót>" należą do drzew SKOPIOWANYCH poza
# Gitem (runtime'y testowe: rsync bez `.git` — patrz `kuking_nazwa_testowej_bazy()`
# w tests/bootstrap.php). Git o takich drzewach nie wie, więc każda z tych baz
# wygląda stąd na osieroconą — także ta, na której ktoś właśnie odpala testy.
# Kasowanie ich byłoby dokładnie tą awarią, którą ten skrypt ma nie powodować,
# więc ich NIE RUSZAMY: sprząta je ten, kto runtime postawił, bo tylko on wie,
# czy katalog jeszcze istnieje.
kopie="$(tr ' ' '\n' <<< "$osierocone" | grep -x 'kuking_test_kopia_.*' || true)"
if [ -n "${kopie// /}" ]; then
    printf "\n${ZOLTY}Pomijam bazy kopii drzewa (runtime'y bez .git) — Git ich nie zna, więc nie da się stąd sprawdzić, czy żyją:${RESET}\n"
    while IFS= read -r _b; do [ -n "$_b" ] && printf "  pomijam  %s\n" "$_b"; done <<< "$kopie"
fi
osierocone="$(tr ' ' '\n' <<< "$osierocone" | grep -vx 'kuking_test_kopia_.*' | tr '\n' ' ')"

if [ -z "${osierocone// /}" ]; then
    printf "\n${ZIELONY}Wszystkie bazy kuking_test_* należą do żywych worktree — nic do usunięcia.${RESET}\n"
    exit 0
fi

if [ "$TRYB_SPRAWDZ" -eq 1 ]; then
    printf "\n${ZOLTY}Tryb --sprawdz: nic nie usunięto.${RESET}\n"
    exit 0
fi

printf "\nUsuwam osierocone bazy:\n"
for baza in $osierocone; do
    if psql -U kuking -h 127.0.0.1 -d postgres -c "DROP DATABASE IF EXISTS \"$baza\"" >/dev/null 2>&1; then
        printf "  ${ZIELONY}✓${RESET} usunięto %s\n" "$baza"
    else
        printf "  ${CZERWONY}✗${RESET} nie udało się usunąć %s (ktoś jest podłączony?)\n" "$baza"
    fi
done
