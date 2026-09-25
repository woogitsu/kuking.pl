#!/usr/bin/env bash
# =============================================================================
#  Test regresyjny #1357: ślady wyjątków w obrazie produkcyjnym bez argumentów.
# =============================================================================
#
#  Obraz FrankenPHP nie wgrywa php.ini-production, więc jedynym źródłem
#  ustawień jest docker/php.ini. Bez `zend.exception_ignore_args=On` ślad
#  wyjątku pokazuje początek każdego argumentu — także sekretu.
#
#  Test uruchamia PHP z SAMYM docker/php.ini (`-n -c`), rzuca wyjątek z funkcji
#  przyjmującej syntetyczny znacznik i sprawdza ślad. Kontrola dodatnia: ten
#  sam przebieg na kopii pliku BEZ dyrektywy musi pokazać prefiks znacznika —
#  inaczej test nie odróżniłby naprawy od jej braku.
#
#  Uruchomienie:  bash tests/skrypty/php-ini-slady.sh
# =============================================================================

set -uo pipefail

KATALOG="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_INI="${KATALOG}/docker/php.ini"
ZNACZNIK='ZNACZNIK_TESTOWY_1357_NIE_SEKRET'
PREFIKS="${ZNACZNIK:0:15}"

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

zawiera() { [[ "$1" == *"$2"* ]] && echo tak || echo nie; }
nie_zawiera() { [[ "$1" != *"$2"* ]] && echo tak || echo nie; }

slad_z_ini() {
  php -n -c "$1" -r '
    function przyjmij_poswiadczenie($wartosc) { throw new RuntimeException("próba"); }
    try { przyjmij_poswiadczenie($argv[1]); }
    catch (RuntimeException $e) { echo $e->getTraceAsString(); }
  ' "${ZNACZNIK}" 2>/dev/null
}

echo "── Ślady wyjątków (docker/php.ini) ──"

if ! command -v php >/dev/null 2>&1; then
  printf '  \033[0;31m✗\033[0m brak php w PATH — nie ma czym testować\n'
  exit 1
fi

slad="$(slad_z_ini "${PHP_INI}")"
sprawdz "ślad nie zawiera znacznika ani jego prefiksu" "$(nie_zawiera "${slad}" "${PREFIKS}")"
sprawdz "ślad nadal podaje nazwę funkcji" "$(zawiera "${slad}" 'przyjmij_poswiadczenie()')"
sprawdz "ślad nadal podaje linię" "$(zawiera "${slad}" 'Command line code(')"

# Kontrola dodatnia: bez dyrektywy PHP wraca do domyślnego Off i ujawnia prefiks.
kopia="$(mktemp)"
trap 'rm -f "${kopia}"' EXIT
grep -v '^[[:space:]]*zend\.exception_ignore_args' "${PHP_INI}" > "${kopia}"
slad_bez="$(slad_z_ini "${kopia}")"
sprawdz "kontrola dodatnia: bez dyrektywy ślad ujawnia prefiks znacznika" \
  "$(zawiera "${slad_bez}" "${PREFIKS}")"

echo
if (( oblane > 0 )); then
  printf '\033[0;31m%d oblanych, %d zdanych.\033[0m\n' "${oblane}" "${zdane}"
  exit 1
fi
printf '\033[0;32mWszystkie %d zdane.\033[0m\n' "${zdane}"
