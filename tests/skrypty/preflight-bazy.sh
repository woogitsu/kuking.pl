#!/usr/bin/env bash
# =============================================================================
#  Test regresyjny #1356: preflight bazy w docker/entrypoint.sh.
# =============================================================================
#
#  `config/database.php` czyta `DB_URL` albo osobne `DB_HOST`/`DB_DATABASE`/
#  `DB_USERNAME`. Samo `DATABASE_URL` (które Railway daje naturalnie) NIE
#  zasila połączenia — a dawny preflight uznawał je za wystarczające i milczał.
#
#  Test wyciąga z entrypointu samą funkcję `sprawdz_konfiguracje_bazy` i woła
#  ją na syntetycznych wartościach. Hasło w URL-ach jest znacznikiem: żaden
#  komunikat nie może go zawierać.
#
#  Uruchomienie:  bash tests/skrypty/preflight-bazy.sh
# =============================================================================

set -uo pipefail

KATALOG="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENTRYPOINT="${KATALOG}/docker/entrypoint.sh"
HASLO='haslo_testowe_1356'
URL="postgresql://kuking:${HASLO}@db.example.invalid:5432/kuking"

zdane=0
oblane=0

sprawdz() {
  local opis="$1" warunek="$2"
  if [[ "${warunek}" == "tak" ]]; then
    printf '  \033[0;32m✓\033[0m %s\n' "${opis}"
    zdane=$((zdane + 1))
  else
    printf '  \033[0;31m✗\033[0m %s\n' "${opis}"
    oblane=$((oblane + 1))
  fi
}

wczytaj_funkcje() {
  sed -n '/^sprawdz_konfiguracje_bazy() {/,/^}/p' "${ENTRYPOINT}"
  echo 'log() { printf "[test] %s\n" "$*" >&2; }'
}

# Strażnik przed fałszywą zielenią: bez funkcji każdy przypadek „diagnozy"
# przeszedłby na błędzie powłoki.
if ! eval "$(wczytaj_funkcje)" 2>/dev/null || ! declare -f sprawdz_konfiguracje_bazy >/dev/null 2>&1; then
  printf '  \033[0;31m✗\033[0m docker/entrypoint.sh nie definiuje sprawdz_konfiguracje_bazy()\n'
  exit 1
fi

# Uruchamia funkcję w czystym podpowłoce z podanymi zmiennymi.
# Wypisuje: "<kod wyjścia>|<komunikaty>".
przebieg() {
  local wynik kod
  wynik="$(
    unset DB_URL DATABASE_URL DB_HOST DB_DATABASE DB_USERNAME
    for przypisanie in "$@"; do export "${przypisanie?}"; done
    eval "$(wczytaj_funkcje)"
    sprawdz_konfiguracje_bazy 2>&1
  )"
  kod=$?
  printf '%s|%s' "${kod}" "${wynik}"
}

echo "── Preflight bazy (docker/entrypoint.sh) ──"

w="$(przebieg "DATABASE_URL=${URL}")"
sprawdz "samo DATABASE_URL → diagnoza (kod ≠ 0)" "$([[ "${w%%|*}" != 0 ]] && echo tak || echo nie)"
sprawdz "samo DATABASE_URL → komunikat nazywa DB_URL" "$([[ "${w}" == *'czyta DB_URL'* ]] && echo tak || echo nie)"
sprawdz "samo DATABASE_URL → komunikat bez hasła" "$([[ "${w}" != *"${HASLO}"* ]] && echo tak || echo nie)"

w="$(przebieg "DB_URL=${URL}")"
sprawdz "samo DB_URL → przechodzi bez komunikatu" "$([[ "${w}" == '0|' ]] && echo tak || echo nie)"

w="$(przebieg DB_HOST=db.example.invalid DB_DATABASE=kuking DB_USERNAME=kuking)"
sprawdz "komplet DB_HOST/DB_DATABASE/DB_USERNAME → przechodzi" "$([[ "${w}" == '0|' ]] && echo tak || echo nie)"

w="$(przebieg DB_HOST=db.example.invalid)"
sprawdz "sam DB_HOST bez reszty → diagnoza" "$([[ "${w%%|*}" != 0 && "${w}" == *'brak konfiguracji bazy'* ]] && echo tak || echo nie)"

w="$(przebieg)"
sprawdz "brak obu dróg → diagnoza" "$([[ "${w%%|*}" != 0 && "${w}" == *'brak konfiguracji bazy'* ]] && echo tak || echo nie)"

# Entrypoint działa pod `set -e`: diagnoza ma ostrzec, nie zabić startu,
# i musi stać przed przebudową cache konfiguracji.
wywolanie="$(grep -n '^sprawdz_konfiguracje_bazy || true$' "${ENTRYPOINT}" | cut -d: -f1)"
cache="$(grep -n 'artisan config:clear' "${ENTRYPOINT}" | head -1 | cut -d: -f1)"
sprawdz "wywołanie nie przerywa startu i stoi przed config:cache" \
  "$([[ -n "${wywolanie}" && -n "${cache}" && "${wywolanie}" -lt "${cache}" ]] && echo tak || echo nie)"

echo
if (( oblane > 0 )); then
  printf '\033[0;31m%d oblanych, %d zdanych.\033[0m\n' "${oblane}" "${zdane}"
  exit 1
fi
printf '\033[0;32mWszystkie %d zdane.\033[0m\n' "${zdane}"
