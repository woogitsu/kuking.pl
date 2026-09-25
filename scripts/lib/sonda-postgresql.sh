#!/usr/bin/env bash
# Krok „PostgreSQL" z scripts/check.sh (issue #732).
#
# CO BYŁO ŹLE
# Sonda to było gołe `pg_isready -q` — bez hosta, portu, bazy i użytkownika.
# Pytała więc o połączenie domyślne libpq (albo o to, co akurat stało w PG*),
# a nie o bazę, na której chodzą testy: `DB_HOST`/`DB_PORT` Laravela nie były
# jej argumentami. Komunikat „PostgreSQL działa" nie dowodził niczego o bazie
# testowej. Do tego po porażce skrypt sam wołał `pg_ctlcluster <wersja> main
# start` — administracyjną operację na WSPÓŁDZIELONYM klastrze systemowym —
# i połykał jej wyjście.
#
# CO JEST TERAZ
#   - sonda pyta WYŁĄCZNIE o jawnie podany endpoint: DB_HOST, DB_PORT,
#     DB_DATABASE i DB_USERNAME muszą być ustawione (lokalnie np.
#     127.0.0.1:55439 z własną bazą zadania); brak któregoś to odmowa
#     z listą brakujących zmiennych;
#   - żadnego uruchamiania klastrów — przy niedostępności komunikat nazywa
#     sprawdzany endpoint i mówi, co zrobić, bez hasła i bez DSN;
#   - „serwer przyjmuje połączenia" nie jest nazywane „działa": `pg_isready`
#     nie sprawdza hasła ani istnienia bazy. To sprawdzą testy.
#
# TRYB --szybko (decyzja właściciela z 25.09.2026): hook pre-push woła
# `check.sh --szybko`, więc brak DB_* w tym trybie daje OSTRZEŻENIE i pomija
# sondę, zamiast blokować push. Pełne `./scripts/check.sh` nadal wymaga
# jawnej bazy (odmowa, kod 2). CI tej sondy nie używa — ma własną usługę
# PostgreSQL z jawnymi zmiennymi.
#
# Wymaga funkcji `ok` i `zle` od wołającego (check.sh je definiuje).
# Argument: 1 = tryb --szybko, 0 (domyślnie) = pełna kontrola.
# Kod powrotu: 0 — endpoint odpowiada, 1 — nie odpowiada, 2 — brak parametrów
# (pełna kontrola), 3 — brak parametrów w trybie --szybko: sonda pominięta.

krok_postgresql() {
    local szybko="${1:-0}" brakuje=() zmienna
    for zmienna in DB_HOST DB_PORT DB_DATABASE DB_USERNAME; do
        [ -n "${!zmienna:-}" ] || brakuje+=("$zmienna")
    done

    if [ "${#brakuje[@]}" -gt 0 ] && [ "$szybko" = 1 ]; then
        printf '! Pomijam sondę PostgreSQL (--szybko): brak %s\n' "${brakuje[*]}"
        printf '  Pełne ./scripts/check.sh wymaga jawnej bazy testowej (DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME).\n'
        return 3
    fi

    if [ "${#brakuje[@]}" -gt 0 ]; then
        zle "Brak jawnych parametrów bazy testowej: ${brakuje[*]}"
        printf '  Podaj izolowaną bazę tego zadania, np.:\n'
        printf '  DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_test_<zadanie> DB_USERNAME=kuking ./scripts/check.sh\n'
        printf '  Skrypt nie zgaduje domyślnego portu ani nazwy — w środowisku współdzielonym to może być cudza baza.\n'
        return 2
    fi

    local endpoint="${DB_HOST}:${DB_PORT}, baza ${DB_DATABASE}, użytkownik ${DB_USERNAME}"

    if pg_isready -q -h "$DB_HOST" -p "$DB_PORT" -d "$DB_DATABASE" -U "$DB_USERNAME" 2>/dev/null; then
        ok "Serwer PostgreSQL przyjmuje połączenia (${endpoint})"
        printf '  To nie sprawdza hasła ani tego, czy baza istnieje — sprawdzą to testy.\n'
        return 0
    fi

    zle "Serwer PostgreSQL nie odpowiada (${endpoint}) — testy Kuking nie chodzą na SQLite"
    printf '  Uruchom własną bazę testową na tym hoście i porcie albo podaj inne DB_HOST/DB_PORT.\n'
    printf '  Ten skrypt nie uruchamia klastra systemowego i nie dotyka innych baz.\n'
    return 1
}
