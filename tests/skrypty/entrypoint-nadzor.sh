#!/usr/bin/env bash
# =============================================================================
#  Test regresyjny awarii z 5–6 września 2026 (3,5 godziny niedostępności).
# =============================================================================
#
#  CO SIĘ DZIAŁO
#  `queue:work --max-time=3600` kończy się CELOWO po godzinie, z kodem 0 —
#  tak Laravel walczy z wyciekami pamięci. Rola `all` czekała przez `wait -n`,
#  czyli „skończył się którykolwiek → zamykam kontener". Godzinę po wdrożeniu
#  worker zrobił to, do czego został zaprogramowany, i zabrał serwer WWW.
#  Kontener wyszedł z kodem 0, więc Railway uznał to za poprawne zakończenie
#  i nie wskrzesił serwisu.
#
#  DLACZEGO TEST W BASHU, A NIE W PHPUNICIE
#  Błąd nie był w kodzie PHP. Był w tym, jak skrypt powłoki rozróżnia
#  „proces się skończył" od „proces padł" — a tego żaden test Laravela
#  nie dotknie. Test musi mówić tym samym językiem, co naprawa.
#
#  Uruchomienie:  bash tests/skrypty/entrypoint-nadzor.sh
# =============================================================================

set -uo pipefail

KATALOG="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENTRYPOINT="${KATALOG}/docker/entrypoint.sh"

zdane=0
oblane=0

sprawdz() {
  local opis="$1" oczekiwane="$2" otrzymane="$3"
  if [[ "${oczekiwane}" == "${otrzymane}" ]]; then
    printf '  \033[0;32m✓\033[0m %s\n' "${opis}"
    zdane=$((zdane + 1))
  else
    printf '  \033[0;31m✗\033[0m %s\n     oczekiwano: %s\n     otrzymano:  %s\n' \
      "${opis}" "${oczekiwane}" "${otrzymane}"
    oblane=$((oblane + 1))
  fi
}

# Wyciągamy SAME FUNKCJE z entrypointu, bez uruchamiania go: plik na starcie
# wymaga APP_KEY, /app/artisan i całej reszty kontenera.
wczytaj_funkcje() {
  # shellcheck disable=SC2016
  sed -n '/^nadzoruj() {/,/^}/p' "${ENTRYPOINT}"
  echo 'log() { printf "[test] %s\n" "$*" >&2; }'
}

echo "── Nadzorca procesów (docker/entrypoint.sh) ──"

# Strażnik przed fałszywą zielenią. Bez tego test „proces padający natychmiast
# eskaluje" PRZECHODZI na wersji sprzed naprawy — bo funkcji `nadzoruj` tam
# nie ma, wywołanie kończy się błędem powłoki, a `if` interpretuje ten błąd
# jako „eskalował". Test przechodziłby, nie sprawdzając niczego.
if ! eval "$(wczytaj_funkcje)" 2>/dev/null || ! declare -f nadzoruj >/dev/null 2>&1; then
  printf '  \033[0;31m✗\033[0m docker/entrypoint.sh nie definiuje funkcji nadzoruj()\n'
  printf '\033[0;31mNie ma czego testować.\033[0m\n'
  exit 1
fi

# ---------------------------------------------------------------------------
# 1. SEDNO AWARII: proces, który kończy się planowo, ma zostać wskrzeszony,
#    a nie zabić nadzorcy.
# ---------------------------------------------------------------------------
wynik="$(
  eval "$(wczytaj_funkcje)"
  export NADZOR_MIN_CZAS=1 NADZOR_LIMIT=5
  licznik_pliku="$(mktemp)"
  echo 0 > "${licznik_pliku}"

  # Udaje `queue:work --max-time`: pracuje chwilę i kończy się z kodem 0.
  recykling() {
    local n; n=$(( $(cat "${licznik_pliku}") + 1 ))
    echo "${n}" > "${licznik_pliku}"
    sleep 1.2
    return 0
  }

  # Nadzorca ma pętlę nieskończoną — przerywamy go po czasie i patrzymy,
  # ILE RAZY zdążył wskrzesić proces.
  ( nadzoruj "kolejka" recykling ) &
  pid=$!
  sleep 4
  kill -TERM "${pid}" 2>/dev/null
  wait "${pid}" 2>/dev/null

  ile="$(cat "${licznik_pliku}")"
  rm -f "${licznik_pliku}"
  # Bez naprawy proces uruchomiłby się DOKŁADNIE RAZ i wszystko by się skończyło.
  if (( ile >= 2 )); then echo "wskrzeszony"; else echo "uruchomiony ${ile} raz"; fi
)"
sprawdz "planowe zakończenie procesu jest wskrzeszane, a nie eskalowane" "wskrzeszony" "${wynik}"

# ---------------------------------------------------------------------------
# 2. DRUGA STRONA: proces padający NATYCHMIAST to awaria, nie recykling.
#    Nadzorca musi się poddać, żeby kontener zgłosił porażkę.
# ---------------------------------------------------------------------------
wynik="$(
  eval "$(wczytaj_funkcje)"
  export NADZOR_MIN_CZAS=5 NADZOR_LIMIT=2
  pada_od_razu() { return 1; }

  if nadzoruj "kolejka" pada_od_razu 2>/dev/null; then
    echo "wrocil-zero"
  else
    echo "eskalowal"
  fi
)"
sprawdz "proces padający natychmiast eskaluje po przekroczeniu limitu" "eskalowal" "${wynik}"

# ---------------------------------------------------------------------------
# 3. Licznik szybkich śmierci ZERUJE SIĘ po udanym przebiegu. Bez tego
#    worker chodzący tygodniami uzbierałby limit z pojedynczych potknięć
#    i sam się wyłączył — po kilku dniach, bez związku z czymkolwiek.
# ---------------------------------------------------------------------------
wynik="$(
  eval "$(wczytaj_funkcje)"
  export NADZOR_MIN_CZAS=1 NADZOR_LIMIT=2
  stan="$(mktemp)"
  echo 0 > "${stan}"

  # padnij, pożyj, padnij, pożyj... — przy zerowaniu licznika to nigdy
  # nie osiągnie limitu 2.
  na_przemian() {
    local n; n=$(( $(cat "${stan}") + 1 ))
    echo "${n}" > "${stan}"
    if (( n % 2 == 1 )); then return 1; fi
    sleep 1.2
    return 0
  }

  ( nadzoruj "kolejka" na_przemian ) &
  pid=$!
  sleep 6
  zyje="nie"
  kill -0 "${pid}" 2>/dev/null && zyje="tak"
  kill -TERM "${pid}" 2>/dev/null; wait "${pid}" 2>/dev/null
  rm -f "${stan}"
  echo "${zyje}"
)"
sprawdz "pojedyncze potknięcia nie sumują się do wyłączenia" "tak" "${wynik}"

# ---------------------------------------------------------------------------
# 4. Kod wyjścia. Railway restartuje kontener po KODZIE NIEZEROWYM; przy
#    zerze uznaje, że praca się skończyła. Awaria musi więc wychodzić 1.
# ---------------------------------------------------------------------------
if grep -q 'shutdown 1' "${ENTRYPOINT}"; then
  sprawdz "śmierć serwera WWW zamyka kontener kodem niezerowym" "tak" "tak"
else
  sprawdz "śmierć serwera WWW zamyka kontener kodem niezerowym" "tak" "nie"
fi

# ---------------------------------------------------------------------------
# 5. `wait -n` NIE MOŻE WRÓCIĆ do roli `all`. To jest ta jedna konstrukcja,
#    która spowodowała awarię: nie odróżnia procesu, który ma prawo się
#    skończyć, od tego, który go nie ma.
# ---------------------------------------------------------------------------
# Komentarze w tym bloku CELOWO wspominają `wait -n` — opisują, co poszło
# nie tak. Sprawdzamy więc kod, a nie prozę: linie zaczynające się od `#`
# lecą do kosza przed dopasowaniem. Pierwsza wersja tego testu tego nie
# robiła i oblewała na własnym komentarzu z naprawy.
if sed -n '/^  all)/,/^    ;;/p' "${ENTRYPOINT}" | sed 's/[[:space:]]*#.*$//' | grep -q 'wait -n'; then
  sprawdz "rola 'all' nie czeka przez 'wait -n'" "brak" "jest"
else
  sprawdz "rola 'all' nie czeka przez 'wait -n'" "brak" "brak"
fi

echo
if (( oblane > 0 )); then
  printf '\033[0;31mOblane: %d, zdane: %d\033[0m\n' "${oblane}" "${zdane}"
  exit 1
fi
printf '\033[0;32mWszystkie %d testów nadzorcy przechodzi.\033[0m\n' "${zdane}"
