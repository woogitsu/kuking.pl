#!/usr/bin/env bash
# =============================================================================
#  Test regresyjny #2044: worker i scheduler nie startują na starym schemacie.
# =============================================================================
#
#  CO SIĘ DZIAŁO (topologia split, .railway/railway.ts)
#  Migracje uruchamia tylko web, w pre-deploy. Railway nie ma bramki między
#  usługami, więc `worker` i `scheduler` z tego samego commita startowały
#  równolegle z migracją web — nowy kod na schemacie sprzed migracji.
#
#  CO PILNUJE TEN TEST
#  `czekaj_na_migracje()` z docker/entrypoint.sh, wołane w `start_worker`
#  i `start_scheduler` PRZED uruchomieniem procesu. Test wczytuje z entrypointu
#  same funkcje i podstawia atrapy: `php` (odpowiedzi `migrate:status`),
#  `sleep` (przesuwa SECONDS, więc test nie czeka naprawdę), `log`
#  i procesy roli. Nic nie dotyka bazy ani sieci.
#
#  KONTROLA UJEMNA W TYM SAMYM PLIKU
#  Te same scenariusze uruchamiamy na SZEŚCIU zepsutych kopiach funkcji
#  (bramka zawsze „gotowa", timeout zwraca 0, worker bez bramki, scheduler
#  ignoruje porażkę, wyciek surowego komunikatu bazy, brak limitu). Każda
#  zepsuta kopia MUSI oblać co najmniej jedną asercję — inaczej test nie
#  pilnuje niczego. Mutacja, która nie zmienia źródła, kończy przebieg odmową.
#
#  Uruchomienie:  bash tests/skrypty/bramka-migracji.sh
# =============================================================================

set -uo pipefail

KATALOG="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENTRYPOINT="${KATALOG}/docker/entrypoint.sh"
SEKRET='haslo_testowe_2044'

# Katalog atrap tworzy powłoka główna: przebieg() chodzi w $(...), a log
# czytają potem asercje w powłoce głównej.
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

zdane=0
oblane=0
TRYB=druk
NIEUDANE=0

# W trybie `druk` raportuje każdą asercję; w trybie `cichy` (mutacje) tylko
# liczy niepowodzenia.
ocen() {
  local opis="$1" warunek="$2"
  if [[ "${warunek}" == "tak" ]]; then
    if [[ "${TRYB}" == druk ]]; then
      printf '  \033[0;32m✓\033[0m %s\n' "${opis}"
      zdane=$((zdane + 1))
    fi
  else
    NIEUDANE=$((NIEUDANE + 1))
    if [[ "${TRYB}" == druk ]]; then
      printf '  \033[0;31m✗\033[0m %s\n' "${opis}"
      oblane=$((oblane + 1))
    fi
  fi
}

funkcje_zrodlo() {
  sed -n '/^czekaj_na_migracje() {/,/^}/p' "${ENTRYPOINT}"
  sed -n '/^start_worker() {/,/^}/p' "${ENTRYPOINT}"
  sed -n '/^start_scheduler() {/,/^}/p' "${ENTRYPOINT}"
}

# Strażnik przed fałszywą zielenią: bez funkcji każdy scenariusz „nie startuje"
# przeszedłby na błędzie powłoki.
for f in czekaj_na_migracje start_worker start_scheduler; do
  if ! grep -q "^${f}() {" "${ENTRYPOINT}"; then
    printf '  \033[0;31m✗\033[0m docker/entrypoint.sh nie definiuje %s()\n' "${f}"
    exit 1
  fi
done

# Przebieg jednej roli z atrapami. Wypisuje "<kod>|<ile php>|<start>|<ile sleep>"
# i zostawia log w "${TMP}/log".
#   $1 rola (start_worker | start_scheduler)
#   $2 odpowiedzi atrapy php po spacji: ready|pending|brak|baza (ostatnia się powtarza)
#   $3 mutacja: "stare<TAB>nowe" albo pusta
przebieg() {
  local rola="$1" odpowiedzi="$2" mutacja="${3:-}"
  : > "${TMP}/log"; : > "${TMP}/php"; : > "${TMP}/sleep"; : > "${TMP}/start"
  local zrodlo; zrodlo="$(funkcje_zrodlo)"
  if [[ -n "${mutacja}" ]]; then
    local stare="${mutacja%%$'\t'*}" nowe="${mutacja#*$'\t'}"
    local po="${zrodlo//"${stare}"/"${nowe}"}"
    if [[ "${po}" == "${zrodlo}" ]]; then
      echo "ODMOWA_NO_OP: mutacja nie zmieniła źródła: ${stare}" >&2
      exit 2
    fi
    zrodlo="${po}"
  fi

  local kod
  (
    export TMP ODPOWIEDZI="${odpowiedzi}" SEKRET
    log() { printf '%s\n' "$*" >> "${TMP}/log"; }
    # Atrapa `php /app/artisan migrate:status ...`: n-ta odpowiedź z listy.
    php() {
      local n; n=$(( $(wc -l < "${TMP}/php") + 1 ))
      echo x >> "${TMP}/php"
      local -a lista; read -r -a lista <<< "${ODPOWIEDZI}"
      local i=$(( n - 1 )); (( i >= ${#lista[@]} )) && i=$(( ${#lista[@]} - 1 ))
      case "${lista[$i]}" in
        ready)   echo ' INFO  No pending migrations.'; return 0 ;;
        pending) printf ' Migration name .. Batch / Status\n 2026_09_28_x_nowa_kolumna .. Pending\n'; return 1 ;;
        brak)    echo ' ERROR  Migration table not found.'; return 1 ;;
        baza)    echo "SQLSTATE[08006] connection to server at \"db.example.invalid\" failed: password=${SEKRET}"; return 1 ;;
      esac
    }
    # Czas symulowany; odcięcie pętli bez końca (mutacja „brak limitu").
    sleep() {
      echo "$1" >> "${TMP}/sleep"
      if (( $(wc -l < "${TMP}/sleep") > 400 )); then echo "pętla bez końca" >> "${TMP}/log"; exit 99; fi
      SECONDS=$(( SECONDS + $1 ))
    }
    nadzoruj_kolejki() { echo "kolejka $*" >> "${TMP}/start"; }
    petla_harmonogramu() { echo "harmonogram" >> "${TMP}/start"; }
    eval "${zrodlo}"
    "${rola}"
  )
  kod=$?
  local start=nie; [[ -s "${TMP}/start" ]] && start=tak
  printf '%s|%s|%s|%s' "${kod}" "$(wc -l < "${TMP}/php" | tr -d ' ')" "${start}" "$(wc -l < "${TMP}/sleep" | tr -d ' ')"
}

log_zawiera() { grep -qF -- "$1" "${TMP}/log" && echo tak || echo nie; }

# Wszystkie scenariusze; $1 = mutacja (pusta = kod produkcyjny).
scenariusze() {
  local mut="${1:-}" w

  w="$(przebieg start_worker 'ready' "${mut}")"
  ocen "worker: schemat aktualny → startuje od razu, bez czekania" \
    "$([[ "${w}" == '0|1|tak|0' ]] && echo tak || echo nie)"

  w="$(MIGRACJE_ODSTEP_S=5 przebieg start_worker 'pending pending ready' "${mut}")"
  ocen "worker: oczekująca migracja → czeka i startuje dopiero po jej wykonaniu (3 próby)" \
    "$([[ "${w}" == '0|3|tak|2' ]] && echo tak || echo nie)"
  ocen "worker: log mówi, że czeka na migracje web" \
    "$(log_zawiera 'czekam na migracje serwisu web')"

  w="$(MIGRACJE_LIMIT_S=20 MIGRACJE_ODSTEP_S=5 przebieg start_worker 'pending' "${mut}")"
  ocen "worker: migracja nie nadchodzi → po limicie kod 1 i BRAK startu na starym schemacie" \
    "$([[ "${w%%|*}" == 1 && "${w}" == *'|nie|'* ]] && echo tak || echo nie)"
  ocen "worker: limit jest respektowany (4–5 prób, nie w nieskończoność)" \
    "$([[ "${w}" == 1'|'[45]'|nie|'* ]] && echo tak || echo nie)"
  ocen "worker: log po limicie mówi, co sprawdzić (pre-deploy serwisu web)" \
    "$([[ "$(log_zawiera 'nie startuję na starym schemacie')" == tak && "$(log_zawiera 'pre-deploy serwisu web')" == tak ]] && echo tak || echo nie)"

  w="$(MIGRACJE_LIMIT_S=10 MIGRACJE_ODSTEP_S=5 przebieg start_worker 'baza' "${mut}")"
  ocen "worker: baza niedostępna → też czeka i po limicie nie startuje" \
    "$([[ "${w%%|*}" == 1 && "${w}" == *'|nie|'* ]] && echo tak || echo nie)"
  ocen "worker: surowy komunikat z bazy (z hasłem) NIE trafia do logu" \
    "$([[ "$(log_zawiera "${SEKRET}")" == nie && "$(log_zawiera 'db.example.invalid')" == nie ]] && echo tak || echo nie)"
  ocen "worker: log nazywa przyczynę (baza / tabela migracji)" \
    "$(log_zawiera 'czekam na bazę i tabelę migracji')"

  w="$(przebieg start_worker 'brak brak ready' "${mut}")"
  ocen "worker: brak tabeli migrations (świeża baza) → czeka, potem startuje" \
    "$([[ "${w}" == '0|3|tak|2' ]] && echo tak || echo nie)"

  w="$(MIGRACJE_BRAMKA=0 przebieg start_worker 'pending' "${mut}")"
  ocen "worker: MIGRACJE_BRAMKA=0 → awaryjne wyłączenie, start bez pytania bazy" \
    "$([[ "${w}" == '0|0|tak|0' ]] && echo tak || echo nie)"
  ocen "worker: wyłączona bramka jest ogłoszona w logu" \
    "$(log_zawiera 'bramka migracji WYŁĄCZONA')"

  w="$(MIGRACJE_LIMIT_S=abc przebieg start_worker 'ready' "${mut}")"
  ocen "zła wartość MIGRACJE_LIMIT_S → ostrzeżenie i domyślny limit, start nie pada" \
    "$([[ "${w}" == '0|1|tak|0' && "$(log_zawiera 'MIGRACJE_LIMIT_S nie jest liczbą')" == tak ]] && echo tak || echo nie)"

  w="$(przebieg start_scheduler 'ready' "${mut}")"
  ocen "scheduler: schemat aktualny → startuje od razu" \
    "$([[ "${w}" == '0|1|tak|0' ]] && echo tak || echo nie)"

  w="$(MIGRACJE_ODSTEP_S=5 przebieg start_scheduler 'pending ready' "${mut}")"
  ocen "scheduler: oczekująca migracja → czeka i startuje po jej wykonaniu" \
    "$([[ "${w}" == '0|2|tak|1' ]] && echo tak || echo nie)"

  w="$(MIGRACJE_LIMIT_S=20 MIGRACJE_ODSTEP_S=5 przebieg start_scheduler 'pending' "${mut}")"
  ocen "scheduler: migracja nie nadchodzi → po limicie kod 1 i BRAK startu harmonogramu" \
    "$([[ "${w%%|*}" == 1 && "${w}" == *'|nie|'* ]] && echo tak || echo nie)"
}

echo "── Bramka migracji workera i schedulera (docker/entrypoint.sh, #2044) ──"
TRYB=druk
scenariusze ""

# Migracje wykonuje WYŁĄCZNIE web. Trzy migratory to wyścig o blokady.
gate_i_role="$(funkcje_zrodlo)"
ocen "worker/scheduler nie uruchamiają migracji (tylko migrate:status)" \
  "$([[ "${gate_i_role}" != *'artisan migrate '* && "${gate_i_role}" != *'migruj-pod-blokada'* && "${gate_i_role}" == *'migrate:status'* ]] && echo tak || echo nie)"

echo
echo "── Kontrola ujemna: te same scenariusze na zepsutych kopiach ──"
TAB=$'\t'
MUTACJE=(
  "bramka zawsze uznaje schemat za gotowy${TAB}if (( kod == 0 )); then${TAB}if (( 1 )); then"
  "po limicie kod 0 zamiast 1${TAB}      return 1"$'\n'"    fi${TAB}      return 0"$'\n'"    fi"
  "worker bez bramki${TAB}  czekaj_na_migracje worker || exit 1${TAB}"
  "scheduler ignoruje porażkę bramki${TAB}czekaj_na_migracje scheduler || exit 1${TAB}czekaj_na_migracje scheduler || true"
  "log wypisuje surowy komunikat z bazy${TAB}log \"\${rola}: czekam na bazę i tabelę migracji (${TAB}log \"\${rola}: \${wynik} czekam na bazę i tabelę migracji ("
  "limit czekania ignorowany${TAB}if (( SECONDS - start >= limit )); then${TAB}if (( 0 )); then"
)
TRYB=cichy
for m in "${MUTACJE[@]}"; do
  opis="${m%%$'\t'*}"; reszta="${m#*$'\t'}"
  # Mutacja, która nie zmienia źródła, nie jest kontrolą ujemną (PULAPKI §5).
  _zr="$(funkcje_zrodlo)"; _st="${reszta%%$'\t'*}"; _no="${reszta#*$'\t'}"
  if [[ "${_zr//"${_st}"/"${_no}"}" == "${_zr}" ]]; then
    printf '  \033[0;31m✗\033[0m ODMOWA_NO_OP: mutacja „%s" nie zmienia entrypointu\n' "${opis}"
    exit 2
  fi
  NIEUDANE=0
  scenariusze "${reszta}"
  TRYB=druk
  ocen "zepsute: ${opis} → test OBLEWA (${NIEUDANE} asercji)" "$([[ "${NIEUDANE}" -gt 0 ]] && echo tak || echo nie)"
  TRYB=cichy
done
TRYB=druk

echo
if (( oblane > 0 )); then
  printf '\033[0;31m%d oblanych, %d zdanych.\033[0m\n' "${oblane}" "${zdane}"
  exit 1
fi
printf '\033[0;32mWszystkie %d zdane.\033[0m\n' "${zdane}"
