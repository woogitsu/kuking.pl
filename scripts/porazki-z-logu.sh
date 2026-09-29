#!/usr/bin/env bash
#
# Kuking — pokazuje PORAŻKI z logu testów, żeby nie szukać ich w ogonie.
#
# Woła to `scripts/check.sh` po nieudanym kroku „Testy" (#611, etap 4).
# Sam ogon logu (`tail`) potrafi pokazać tylko podsumowanie i ostatni test,
# a właściwa porażka jest kilkaset wierszy wyżej. Skrypt niczego nie
# uruchamia ani nie zmienia kodu wyjścia bramki — tylko czyta log.
#
#   scripts/porazki-z-logu.sh <plik-logu> [maks-wierszy]

set -uo pipefail

_log="${1:-}"
_maks="${2:-60}"

if [ -z "$_log" ] || [ ! -r "$_log" ]; then
    printf 'Brak czytelnego logu testów: %s\n' "${_log:-<nie podano>}" >&2
    exit 2
fi

# Znaczniki porażek: Collision (`FAIL`, `⨯`), PHPUnit (`Failed asserting`,
# `There w… failure`), podsumowanie (`Tests:`) i JSON reportera (`"failed"`).
_wzorzec='^[[:space:]]*(FAIL|⨯|✕|ERROR)|Failed asserting|Tests:[[:space:]]|"failed"|There (was|were) [0-9]+ (failure|error)'
_trafienia=$(grep -E "$_wzorzec" "$_log" | head -n "$_maks")

if [ -z "$_trafienia" ]; then
    printf 'Nie znaleziono w logu wiersza z porażką — pełny wynik: %s\n' "$_log"
    exit 0
fi

printf '%s\n' "$_trafienia"
exit 0
