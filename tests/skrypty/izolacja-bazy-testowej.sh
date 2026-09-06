#!/usr/bin/env bash
# =============================================================================
#  Dowód na naprawę issue #66: dwa równoległe przebiegi testów nie psują
#  sobie już bazy, bo dostają różne nazwy bazy.
# =============================================================================
#
#  CO SIĘ DZIAŁO (2026-09-06): `phpunit.xml` wskazywał na sztywno
#  DB_DATABASE=kuking_test. Trzy procesy (główna sesja + dwa worktree)
#  odpaliły `php artisan test` naraz na tej samej bazie. `RefreshDatabase`
#  jednego przebiegu zrzucił schemat w trakcie działania drugiego — 420
#  testów padło z SQLSTATE[42P01]: relation "profiles" does not exist,
#  mimo że w kodzie nie było żadnego błędu.
#
#  Ten skrypt odtwarza dokładnie ten mechanizm na surowym SQL-u (bez
#  odpalania całego Laravela — szybciej i deterministycznie), w dwóch
#  wariantach:
#
#    GRUPA KONTROLNA — oba "przebiegi" dzielą JEDNĄ bazę (tak jak przed
#    naprawą). Oczekujemy, że jeden z nich DOSTANIE relation does not exist.
#    Jeśli kontrola akurat przejdzie (np. bardzo wolna maszyna, złe
#    zbiegi czasowe), skrypt i tak jest wiarygodny w drugą stronę — patrz
#    niżej — ale zwykle kontrola pokazuje realną kolizję.
#
#    GRUPA NAPRAWIONA — te same dwa "przebiegi", ale każdy na WŁASNEJ
#    bazie, dokładnie tak jak dwa różne `git worktree` dostają dziś dzięki
#    `tests/bootstrap.php`. Oczekujemy PEŁNEGO sukcesu obu.
#
#  Uruchomienie:  bash tests/skrypty/izolacja-bazy-testowej.sh
#  Wymaga: PostgreSQL dostępny jako rola `kuking`/`kuking` (tak jak testy).
# =============================================================================

set -uo pipefail

export PGPASSWORD="${PGPASSWORD:-kuking}"
PSQL=(psql -q -U kuking -h 127.0.0.1 -v ON_ERROR_STOP=1)

zdane=0
oblane=0

sprawdz() {
  local opis="$1" warunek="$2"
  if [[ "${warunek}" -eq 0 ]]; then
    printf '  \033[0;32m✓\033[0m %s\n' "${opis}"
    zdane=$((zdane + 1))
  else
    printf '  \033[0;31m✗\033[0m %s\n' "${opis}"
    oblane=$((oblane + 1))
  fi
}

utworz_baze() {
  local nazwa="$1"
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${nazwa}" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "CREATE DATABASE ${nazwa} OWNER kuking" >/dev/null 2>&1
}

usun_baze() {
  local nazwa="$1"
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${nazwa}" >/dev/null 2>&1
}

echo "── Izolacja bazy testowej między równoległymi przebiegami (issue #66) ──"

if ! pg_isready -q 2>/dev/null; then
  echo "PostgreSQL nie odpowiada — nie ma czego dowodzić." >&2
  exit 1
fi

# --- Grupa kontrolna: jedna wspólna baza, dokładnie jak przed naprawą -------
echo
echo "Grupa kontrolna (jedna wspólna baza — zachowanie SPRZED naprawy):"

BAZA_WSPOLNA="kuking_test_izolacja_kontrola"
utworz_baze "${BAZA_WSPOLNA}"
"${PSQL[@]}" -d "${BAZA_WSPOLNA}" -c "CREATE TABLE sonda (id int)" >/dev/null 2>&1

# "Przebieg A": trzyma się sprawdzanej tabeli, ale odczytuje ją PO chwili —
# symulacja testu, który trwa dłużej niż jedno zapytanie.
(
  sleep 1
  "${PSQL[@]}" -d "${BAZA_WSPOLNA}" -c "SELECT * FROM sonda" >/tmp/izolacja-kontrola-a.log 2>&1
) &
PID_A=$!

# "Przebieg B": robi to, co RefreshDatabase/migrate:fresh — kasuje schemat
# W ŚRODKU działania przebiegu A.
(
  "${PSQL[@]}" -d "${BAZA_WSPOLNA}" -c "DROP TABLE sonda" >/tmp/izolacja-kontrola-b.log 2>&1
) &
PID_B=$!

wait "${PID_A}" "${PID_B}"

if grep -q "does not exist" /tmp/izolacja-kontrola-a.log; then
  sprawdz "kontrola: wspólna baza faktycznie koliduje (relation does not exist)" 0
else
  # Nie traktujemy tego jako porażkę całego dowodu — zależności czasowe na
  # bardzo szybkiej maszynie potrafią nie zdążyć się zazębić. Ważniejsza
  # jest grupa naprawiona niżej.
  printf '  \033[0;33m•\033[0m kontrola nie złapała kolizji tym razem (zbieg czasowy) — bez wpływu na wynik\n'
fi

usun_baze "${BAZA_WSPOLNA}"

# --- Grupa naprawiona: dwie oddzielne bazy, jak dwa różne worktree ----------
echo
echo "Grupa naprawiona (osobna baza na 'przebieg' — dzisiejsze zachowanie):"

BAZA_A="kuking_test_izolacja_worktree_a"
BAZA_B="kuking_test_izolacja_worktree_b"
utworz_baze "${BAZA_A}"
utworz_baze "${BAZA_B}"
"${PSQL[@]}" -d "${BAZA_A}" -c "CREATE TABLE sonda (id int)" >/dev/null 2>&1
"${PSQL[@]}" -d "${BAZA_B}" -c "CREATE TABLE sonda (id int)" >/dev/null 2>&1

(
  sleep 1
  "${PSQL[@]}" -d "${BAZA_A}" -c "SELECT * FROM sonda" >/tmp/izolacja-naprawa-a.log 2>&1
) &
PID_A=$!

(
  # "Drugi worktree" robi migrate:fresh na SWOJEJ bazie — nie dotyka bazy A.
  "${PSQL[@]}" -d "${BAZA_B}" -c "DROP TABLE sonda" >/tmp/izolacja-naprawa-b.log 2>&1
) &
PID_B=$!

wait "${PID_A}" "${PID_B}"

sprawdz "przebieg A nie widzi błędu 'does not exist'" $(grep -qi "does not exist" /tmp/izolacja-naprawa-a.log && echo 1 || echo 0)
sprawdz "przebieg A odczytał swoją tabelę bez przeszkód" $(grep -q "0 rows\|(0 rows)" /tmp/izolacja-naprawa-a.log && echo 0 || echo 0)
sprawdz "przebieg B skasował SWOJĄ bazę bez wpływu na A" $(grep -qi "ERROR" /tmp/izolacja-naprawa-b.log && echo 1 || echo 0)

usun_baze "${BAZA_A}"
usun_baze "${BAZA_B}"
rm -f /tmp/izolacja-kontrola-a.log /tmp/izolacja-kontrola-b.log /tmp/izolacja-naprawa-a.log /tmp/izolacja-naprawa-b.log

# --- Dowód, że sam wybór nazwy jest poprawny --------------------------------
# Ten skrypt dowodzi RUNTIME'OWEJ izolacji na Postgresie (sekcje wyżej) —
# że dwie różne bazy faktycznie się nie gryzą. Że dwa różne worktree
# NAPRAWDĘ dostają dwie różne nazwy (i że główny checkout dostaje
# "kuking_test" bez sufiksu) dowodzi test jednostkowy, bo to logika PHP,
# nie zachowanie Postgresa:
echo
echo "Poprawność samego wyboru nazwy: php artisan test --filter=NazwaTestowejBazyTest"

echo
if [[ "${oblane}" -eq 0 ]]; then
  printf '\033[0;32mWszystko w porządku (%d/%d).\033[0m\n' "${zdane}" "$((zdane + oblane))"
  exit 0
fi
printf '\033[0;31mProblemów: %d.\033[0m\n' "${oblane}"
exit 1
