#!/usr/bin/env bash
#
# Przygotowanie środowiska na start sesji agenta AI.
#
# Cel: agent, który dopiero wszedł do repozytorium, ma od razu móc puścić
# `php artisan test` — bez zgadywania, dlaczego baza nie odpowiada.
#
# Skrypt jest CELOWO odporny na błędy (`|| true`): brak Postgresa lokalnie
# nie może zablokować sesji, w której ktoś chce tylko poczytać dokumentację.

set -uo pipefail
cd "$(dirname "$0")/../.." || exit 0

echo "── Kuking: przygotowanie środowiska ──"

# 1. PostgreSQL. Testy Kuking NIE działają na SQLite (indeksy częściowe,
#    num_nonnulls, pg_trgm, unaccent), więc baza musi żyć.
if command -v pg_isready >/dev/null 2>&1; then
    if ! pg_isready -q 2>/dev/null; then
        for wersja in 18 17 16 15; do
            if [ -d "/usr/lib/postgresql/$wersja" ]; then
                pg_ctlcluster "$wersja" main start >/dev/null 2>&1 || true
                break
            fi
        done
        sleep 2
    fi

    if pg_isready -q 2>/dev/null; then
        echo "PostgreSQL: działa"
        su postgres -c "psql -tAc \"SELECT 1 FROM pg_roles WHERE rolname='kuking'\"" 2>/dev/null | grep -q 1 \
            || su postgres -c "psql -c \"CREATE ROLE kuking LOGIN PASSWORD 'kuking' SUPERUSER\"" >/dev/null 2>&1 || true

        # Baza testowa TEJ sesji (issue #66): "kuking_test" w głównym
        # katalogu, "kuking_test_<worktree>" w każdym `git worktree`. Nazwa
        # MUSI dokładnie odpowiadać temu, co liczy tests/bootstrap.php —
        # inaczej ten skrypt utworzyłby bazę, na którą testy i tak nie trafią.
        #
        # Liczymy ją PRZEZ PHP, wołając wprost
        # tests/Support/kuking_nazwa_testowej_bazy.php — TEN SAM plik, który
        # ładuje `tests/bootstrap.php`. Kiedyś ten skrypt miał WŁASNĄ
        # reimplementację tej reguły w Bashu; dwa niezależne miejsca liczące
        # to samo mogły się rozjechać przy pierwszej zmianie reguły w jednym
        # z nich (patrz historia tego pliku i `tests/bootstrap.php`). PHP w
        # hooku nie jest nową zależnością — ten sam hook niżej i tak
        # bezwarunkowo woła `php artisan key:generate` / `php artisan
        # migrate`.
        #
        # `tests/Support/kuking_nazwa_testowej_bazy.php` jest CELOWO plikiem
        # bez efektów ubocznych (brak `require vendor/autoload.php`) — można
        # go bezpiecznie wywołać w kroku 1., ZANIM krok 2. zainstaluje
        # `vendor/`.
        #
        # Jeśli PHP nie jest dostępny (albo wywołanie się nie powiedzie),
        # NIE milczymy — awaria hooka startowego po cichu jest gorsza niż
        # przybliżenie. Spadamy na przybliżenie policzone w samym Bashu, ale
        # GŁOŚNO o tym informujemy. To przybliżenie odtwarza TYLKO regułę dla
        # głównego checkoutu i zwykłego `git worktree` (jedyne konteksty, w
        # których ten hook w ogóle się uruchamia — sesja Claude Code zawsze
        # startuje w checkoucie albo w `git worktree`, nigdy w drzewie
        # skopiowanym bez `.git`; takie drzewa produkuje wyłącznie
        # `_wspolne/przygotuj-runtime.sh` do osobnego katalogu, w którym
        # żadna sesja nie startuje). Przybliżenie NIE obsługuje i nie musi
        # obsługiwać trzeciego przypadku dodanego w gałęzi
        # `naprawa/baza-proby-per-runtime` (drzewo skopiowane bez `.git`) —
        # ten przypadek nie dotyczy kontekstu, w którym chodzi ten hook.
        baza_testowa="kuking_test"
        wynik_php=""
        if command -v php >/dev/null 2>&1 && [ -f tests/Support/kuking_nazwa_testowej_bazy.php ]; then
            wynik_php="$(php -r '
                require $argv[1];
                echo kuking_nazwa_testowej_bazy($argv[2]);
            ' -- tests/Support/kuking_nazwa_testowej_bazy.php "$PWD" 2>/dev/null)" || wynik_php=""
        fi

        if [ -n "$wynik_php" ]; then
            baza_testowa="$wynik_php"
        else
            echo "UWAGA: nie udało się policzyć nazwy testowej bazy przez PHP (tests/Support/kuking_nazwa_testowej_bazy.php) — używam przybliżenia w Bashu. Przybliżenie NIE obsługuje drzew skopiowanych bez .git (patrz komentarz w tym pliku)."
            if [ -f .git ]; then
                wskaznik="$(sed -n 's/^gitdir:[[:space:]]*//p' .git)"
                if printf '%s' "$wskaznik" | grep -q '/\.git/worktrees/'; then
                    nazwa_worktree="$(basename "$wskaznik")"
                    sufiks="$(printf '%s' "$nazwa_worktree" | tr -c 'a-zA-Z0-9_' '_' | cut -c1-50)"
                    baza_testowa="kuking_test_${sufiks}"
                fi
            fi
        fi

        for db in kuking "$baza_testowa"; do
            su postgres -c "psql -tAc \"SELECT 1 FROM pg_database WHERE datname='$db'\"" 2>/dev/null | grep -q 1 \
                || su postgres -c "createdb -O kuking $db" >/dev/null 2>&1 || true
        done
        echo "Bazy: kuking, $baza_testowa"
    else
        echo "PostgreSQL: NIE DZIAŁA — testy nie przejdą. Uruchom: pg_ctlcluster 16 main start"
    fi
fi

# 2. Zależności PHP
if [ ! -d vendor ]; then
    echo "Instaluję zależności PHP…"
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-progress >/dev/null 2>&1 || true
fi

# 3. Plik .env i klucz aplikacji
if [ ! -f .env ]; then
    cp .env.example .env 2>/dev/null || true
    php artisan key:generate --force >/dev/null 2>&1 || true
    echo "Utworzono .env"
fi

# 4. Zależności front-endu
if [ ! -d node_modules ]; then
    echo "Instaluję zależności front-endu…"
    npm install --no-audit --no-fund >/dev/null 2>&1 || true
fi

# 5. Migracje
php artisan migrate --force >/dev/null 2>&1 && echo "Migracje: aktualne" || true

echo "── Gotowe. Przeczytaj AGENTS.md przed pierwszą zmianą. ──"
exit 0
