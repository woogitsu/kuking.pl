#!/usr/bin/env bash
#
# Kuking — grupa testów `dwa-polaczenia` (D-105).
#
# Jedno polecenie, które przygotowuje WŁASNĄ bazę wyścigów i uruchamia na niej
# testy chodzące na dwóch połączeniach do PostgreSQL.
#
#   ./scripts/testy-dwa-polaczenia.sh          # jeden przebieg
#   ./scripts/testy-dwa-polaczenia.sh 20       # dwadzieścia przebiegów pod rząd
#                                              # (licznik przejść na końcu)
#
# ── DLACZEGO OSOBNY SKRYPT, A NIE `php artisan test --group=...` ──
#
# Bo ta grupa potrzebuje bazy, której zwykły przebieg NIE MA: `kuking_race*`,
# osobnej od `kuking_test*`. Testy nie używają `RefreshDatabase` — dane
# zatwierdzają naprawdę — więc równoległy zwykły przebieg zrzuciłby im schemat
# w trakcie działania. To jest issue #66 widziane z drugiej strony i zdarzyło
# się naprawdę, więc baza jest tu warunkiem, nie zaleceniem. Nazwę liczy
# `kuking_nazwa_bazy_wyscigow()` z `tests/bootstrap.php` — tą samą metodą, co
# nazwę bazy testowej, żeby nie było w repozytorium dwóch reguł nazywania baz.
#
# Klasa bazowa `Tests\Dwa\TestDwochPolaczen` i tak odmawia startu, gdy
# połączenie nie wskazuje na `kuking_race*` — ten skrypt jest wygodą, a nie
# zabezpieczeniem.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

PRZEBIEGI="${1:-1}"

if ! [[ "$PRZEBIEGI" =~ ^[0-9]+$ ]] || [ "$PRZEBIEGI" -lt 1 ]; then
    printf "Ile przebiegów? Podaj liczbę dodatnią, np.: %s 20\n" "$0" >&2
    exit 2
fi

CZERWONY='\033[0;31m'; ZIELONY='\033[0;32m'; ZOLTY='\033[0;33m'; RESET='\033[0m'

# Nazwa bazy liczona TĄ SAMĄ funkcją co w testach — nie przepisana tutaj
# drugi raz, bo dwie kopie tej samej reguły rozjeżdżają się przy pierwszej
# zmianie.
BAZA="$(php -r 'require "tests/bootstrap.php"; echo kuking_nazwa_bazy_wyscigow(__DIR__);')"

if [[ "$BAZA" != kuking_race* ]]; then
    printf "${CZERWONY}Nazwa bazy wyścigów wyszła jako \"%s\", a musi zaczynać się od kuking_race.${RESET}\n" "$BAZA" >&2
    exit 1
fi

printf "${ZOLTY}── Baza wyścigów: %s ──${RESET}\n" "$BAZA"

if ! pg_isready -q 2>/dev/null; then
    printf "Baza nie odpowiada — próbuję ją uruchomić…\n"
    for wersja in 18 17 16 15; do
        [ -d "/usr/lib/postgresql/$wersja" ] && { pg_ctlcluster "$wersja" main start >/dev/null 2>&1; break; }
    done
    sleep 2
fi

# Założenie bazy i migracje idą przez PHP, a nie przez `createdb`: na runnerze
# CI `postgresql-client` bywa nieobecny, a jeśli jest, to łączy się inaczej niż
# aplikacja. Szczegóły: nagłówek `tests/Dwa/bin/przygotuj-baze.php`.
if ! APP_BASE_PATH="$(pwd)" php tests/Dwa/bin/przygotuj-baze.php; then
    printf "${CZERWONY}Nie udało się przygotować bazy wyścigów %s.${RESET}\n" "$BAZA" >&2
    exit 1
fi

PRZESZLO=0

for (( i = 1; i <= PRZEBIEGI; i++ )); do
    if [ "$PRZEBIEGI" -gt 1 ]; then
        printf "\n${ZOLTY}── Przebieg %s z %s ──${RESET}\n" "$i" "$PRZEBIEGI"
    fi

    # APP_BASE_PATH: w worktree z dowiązanym `vendor` bez tego Laravel
    # czyta trasy i konfigurację z głównego katalogu (AGENTS.md §10).
    if APP_BASE_PATH="$(pwd)" DB_DATABASE="$BAZA" \
        php artisan test --group=dwa-polaczenia; then
        PRZESZLO=$((PRZESZLO + 1))
    fi
done

printf "\n"

if [ "$PRZESZLO" -eq "$PRZEBIEGI" ]; then
    printf "${ZIELONY}Przebiegów zielonych: %s z %s.${RESET}\n" "$PRZESZLO" "$PRZEBIEGI"
    exit 0
fi

# NIESTABILNOŚĆ JEST WYNIKIEM, NIE SZUMEM. Migający test w tym repozytorium
# jest gorszy niż jego brak, bo uczy ludzi ignorować czerwone (D-105, rozdział
# 7 audytu blokad). Dlatego skrypt mówi wprost, ile razy na ile przeszło.
printf "${CZERWONY}Przebiegów zielonych: %s z %s — grupa MIGA.${RESET}\n" "$PRZESZLO" "$PRZEBIEGI"
printf "Co z tym zrobić: D-105 w docs/DECISIONS.md, sekcja \"Gdy grupa zacznie migać\".\n"
exit 1
