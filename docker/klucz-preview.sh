# shellcheck shell=bash
# =============================================================================
#  Kuking.pl — własny APP_KEY dla efemerycznego środowiska PR (issue #975)
# =============================================================================
#  Ten plik jest DOŁĄCZANY (`source`) przez docker/entrypoint.sh. Sam nic nie
#  uruchamia — definiuje dwie funkcje, żeby dało się je sprawdzić testem
#  powłoki bez stawiania kontenera (tests/skrypty/klucz-preview.sh).
#
#  PROBLEM
#  `APP_KEY` produkcji i staginu jest w Railway zapieczętowany (`Sealed`).
#  Railway NIE kopiuje zmiennych zapieczętowanych do PR Environments ani przy
#  `railway environment new ... --copy staging`. Preview startował więc bez
#  klucza, a entrypoint słusznie kończył go kodem 1. „Naprawa" przez zdjęcie
#  pieczęci dałaby kodowi z gałęzi PR klucz trwałego środowiska.
#
#  CO ROBIMY
#  Gdy `APP_KEY` jest pusty ORAZ kontener stoi w potwierdzonym środowisku PR,
#  generujemy losowy klucz TEGO KONTENERA (32 bajty z `random_bytes`). Klucz:
#    * nie jest kluczem produkcji ani staginu — powstaje z CSPRNG, niczego
#      nie kopiuje i niczego nie wymaga odpieczętowywać;
#    * żyje tyle, co proces: restart albo nowy deploy daje nowy klucz,
#      więc sesje i ciasteczka preview się unieważniają. Przyjęte świadomie —
#      preview to jeden kontener (APP_ROLE=all) do oglądania zmiany,
#      nie środowisko z trwałymi danymi zaszyfrowanymi kluczem;
#    * nie trafia do logów ani do GitHuba — nie wypisujemy go nigdzie.
#
#  CO ZNACZY „POTWIERDZONE ŚRODOWISKO PR" — WSZYSTKIE warunki naraz:
#    1. Railway wstrzyknął `RAILWAY_ENVIRONMENT_ID` (kontener naprawdę stoi
#       w Railway, a nie na czyimś laptopie z przypadkową nazwą);
#    2. `RAILWAY_ENVIRONMENT_NAME` ma kształt PR: `pr-<numer>` (ścieżka
#       ręczna z preview.yml) albo `<coś>-pr-<numer>` (natywne PR Environments);
#    3. `APP_ENV` nie jest `production`;
#    4. `APP_URL` nie wskazuje domeny trwałego środowiska.
#  Nazwa środowiska nadaje Railway albo operator — kod z gałęzi PR jej nie
#  zmienia. Warunki 3 i 4 to druga linia: nawet źle nazwane środowisko
#  z konfiguracją produkcji albo staginu NIE dostanie klucza z powietrza.
#
#  Poza tym wąskim przypadkiem pusty `APP_KEY` dalej zatrzymuje start —
#  produkcja i staging bez klucza nie wystartują nigdy.
# =============================================================================

# Zwraca 0, gdy kontener stoi w potwierdzonym środowisku PR (patrz wyżej).
kuking_srodowisko_pr() {
  local nazwa="${RAILWAY_ENVIRONMENT_NAME:-}"
  local url="${APP_URL:-}"

  [[ -n "${RAILWAY_ENVIRONMENT_ID:-}" ]] || return 1
  [[ "${nazwa}" =~ ^([a-z0-9._-]+-)?pr-[0-9]+$ ]] || return 1
  [[ "${APP_ENV:-}" != "production" ]] || return 1

  # Host z APP_URL: bez schematu, ścieżki i portu, małymi literami.
  local host="${url#*://}"
  host="${host%%/*}"
  host="${host%%:*}"
  host="${host,,}"
  case "${host}" in
    kuking.pl | www.kuking.pl | staging.kuking.pl) return 1 ;;
  esac

  return 0
}

# Ustawia i eksportuje APP_KEY, gdy jest pusty, a środowisko to potwierdzony
# PR. Zwraca 0, gdy klucz jest (był albo powstał), 1 — gdy go nie ma i nie
# wolno go wygenerować. Samego klucza NIE wypisuje.
kuking_klucz_preview() {
  [[ -n "${APP_KEY:-}" ]] && return 0
  kuking_srodowisko_pr || return 1

  local klucz
  klucz="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')" || return 1
  [[ "${klucz}" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || return 1

  APP_KEY="${klucz}"
  export APP_KEY
  return 0
}
