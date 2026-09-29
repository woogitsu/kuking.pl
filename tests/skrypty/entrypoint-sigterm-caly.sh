#!/usr/bin/env bash
# =============================================================================
#  #713 (komentarz z 21.09.2026): zatrzymanie kontenera w trakcie pracy joba —
#  prawdziwy docker/entrypoint.sh, nie wyciągnięte funkcje.
# =============================================================================
#
#  PYTANIE
#  `entrypoint-nadzor.sh` sprawdza fragmenty: funkcje nadzorcy i shutdown()
#  wczytane z pliku. Zgłoszenie #713 zapisało lukę wprost: „test musi obejmować
#  faktyczny ENTRYPOINT, nie tylko wydobyte funkcje". Pytanie brzmi: czy proces
#  główny kontenera żyje, dopóki potomny PHP nie dokończy bieżącego zadania,
#  gdy przychodzi SIGTERM — tak jak wysyła go Railway przez `tini -g`, czyli do
#  CAŁEJ grupy procesów, a nie tylko do PID 1?
#
#  JAK
#  Cały skrypt (`docker/entrypoint.sh`, role `worker` i `all`) chodzi w
#  katalogu tymczasowym: jedyna zmiana to podmiana ścieżek `/app/` na katalog
#  testu (obraz ma tę ścieżkę na stałe). Atrapa `php` udaje `queue:work`
#  zajętego zadaniem: na TERM „dokańcza" przez ZAKONCZENIE sekund i dopiero
#  wtedy pisze znacznik końca. Atrapa `frankenphp` robi to samo dla WWW.
#  Sygnał idzie na dwa sposoby: do grupy (jak `tini -g`) i tylko do procesu
#  głównego (jak `docker stop` bez `-g`). Sprawdzamy: znacznik końca każdego
#  procesu jest zapisany PRZED wyjściem entrypointu, kod wyjścia to 0, czas
#  zamknięcia nie jest krótszy niż dokańczanie, w grupie nie zostaje żaden
#  proces.
#
#  KONTROLA UJEMNA W TYM SAMYM PLIKU
#  Kopia entrypointu bez `wait` w pułapce nadzorcy kolejek MUSI oblać scenariusz
#  workera — inaczej test nie pilnuje czekania.
#
#  CZEGO NIE DOWODZI
#  Prawdziwego kontenera, `tini` ani Railway (okres zamknięcia 30/120 s to
#  ustawienie wdrożenia, nie kodu) oraz prawdziwego `queue:work`: tu zadanie
#  jest atrapą. Krok dla właściciela: `docs/audits/WERYFIKACJA_713_2026_09_29.md`.
#
#  Uruchomienie:  bash tests/skrypty/entrypoint-sigterm-caly.sh
# =============================================================================

set -uo pipefail

KATALOG="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENTRYPOINT="${KATALOG}/docker/entrypoint.sh"
ZAKONCZENIE=1.5

TMP="$(mktemp -d)"
# Katalog musi być dostępny dla www-data: jako root entrypoint schodzi na ten
# rachunek (`setpriv`), tak jak w obrazie.
chmod 0777 "${TMP}"
GRUPY_DO_SPRZATNIECIA=()
sprzatanie() {
  local g
  for g in "${GRUPY_DO_SPRZATNIECIA[@]:-}"; do
    [[ -n "${g}" ]] && kill -KILL -- "-${g}" 2>/dev/null || true
  done
  rm -rf "${TMP}"
}
trap sprzatanie EXIT

zdane=0
oblane=0
TRYB=druk
NIEUDANE=0
ocen() {
  local opis="$1" warunek="$2"
  if [[ "${warunek}" == "tak" ]]; then
    if [[ "${TRYB}" == druk ]]; then printf '  \033[0;32m✓\033[0m %s\n' "${opis}"; zdane=$((zdane + 1)); fi
  else
    NIEUDANE=$((NIEUDANE + 1))
    if [[ "${TRYB}" == druk ]]; then printf '  \033[0;31m✗\033[0m %s\n' "${opis}"; oblane=$((oblane + 1)); fi
  fi
}

# Strażnik przed fałszywą zielenią: bez tych miejsc w źródle test mierzyłby nic.
for wzor in 'nadzoruj_kolejki() {' 'shutdown() {' 'trap shutdown SIGTERM SIGINT' 'wait || true; exit 0'; do
  if ! grep -qF -- "${wzor}" "${ENTRYPOINT}"; then
    printf '  \033[0;31m✗\033[0m docker/entrypoint.sh nie zawiera „%s” — nie ma czego testować\n' "${wzor}"
    exit 1
  fi
done

# Przygotowuje drzewo `app/` i atrapy. $1 = katalog przebiegu, $2 = wyrażenie
# sed mutacji (puste = bez mutacji).
przygotuj() {
  local dir="$1" mutacja="${2:-}"
  mkdir -p "${dir}/app/docker" "${dir}/app/storage/app/public" "${dir}/app/storage/app/private" "${dir}/app/public" "${dir}/bin"
  : > "${dir}/app/artisan"
  cp "${KATALOG}/docker/klucz-preview.sh" "${dir}/app/docker/klucz-preview.sh"
  # Ścieżka obrazu `/app/` → katalog testu, tylko tam, gdzie jest ścieżką
  # (początek słowa), nie w środku `storage/app/public`.
  sed -E "s#(^|[[:space:]\"=])/app/#\1${dir}/app/#g" "${ENTRYPOINT}" > "${dir}/entrypoint.sh"
  if [[ -n "${mutacja}" ]]; then
    sed -i -E "${mutacja}" "${dir}/entrypoint.sh"
    if cmp -s "${dir}/entrypoint.sh" <(sed -E "s#(^|[[:space:]\"=])/app/#\1${dir}/app/#g" "${ENTRYPOINT}"); then
      echo "MUTACJA_NIE_ZMIENILA_ZRODLA"
      return 1
    fi
  fi
  chmod 0755 "${dir}/entrypoint.sh"

  cat > "${dir}/bin/php" <<'ATRAPA'
#!/usr/bin/env bash
# Atrapa `php`: queue:work to zajęty proces (na TERM dokańcza), reszta kończy się od razu.
case " $* " in
  *" queue:work "*)
    kolejka="${*#*--queue=}"; kolejka="${kolejka%% *}"
    echo "start ${kolejka}" >> "${SLAD}"
    command sleep 120 & tlo=$!
    trap 'kill "${tlo}" 2>/dev/null; echo "term ${kolejka}" >> "${SLAD}"; command sleep "${ZAKONCZENIE}"; echo "koniec ${kolejka}" >> "${SLAD}"; exit 0' TERM
    wait "${tlo}"
    ;;
  *" schedule:run "*) echo "harmonogram" >> "${SLAD}"; exit 0 ;;
  *) exit 0 ;;
esac
ATRAPA
  cat > "${dir}/bin/frankenphp" <<'ATRAPA'
#!/usr/bin/env bash
echo "start www" >> "${SLAD}"
command sleep 120 & tlo=$!
trap 'kill "${tlo}" 2>/dev/null; echo "term www" >> "${SLAD}"; command sleep 0.5; echo "koniec www" >> "${SLAD}"; exit 0' TERM
wait "${tlo}"
ATRAPA
  chmod 0755 "${dir}/bin/php" "${dir}/bin/frankenphp"
  chmod -R a+rwX "${dir}"
}

licz() { grep -c "^$1 " "$2" 2>/dev/null || true; }

# Jeden przebieg. $1 rola, $2 sposób sygnału (grupa|proces), $3 oczekiwana liczba
# procesów PHP, $4 mutacja. Wypisuje wyniki jako zmienne w pliku "${dir}/wynik".
przebieg() {
  local rola="$1" sposob="$2" oczekiwane="$3" mutacja="${4:-}"
  local dir; dir="$(mktemp -d "${TMP}/p.XXXXXX")"; chmod 0777 "${dir}"
  przygotuj "${dir}" "${mutacja}" || { echo "kod_mutacji=brak" > "${dir}/wynik"; echo "${dir}"; return 0; }
  export SLAD="${dir}/slad"; : > "${SLAD}"; chmod 0666 "${SLAD}"
  export ZAKONCZENIE
  local oczekiwane_www=0; [[ "${rola}" == all ]] && oczekiwane_www=1

  ( cd "${dir}" && PATH="${dir}/bin:${PATH}" APP_KEY=base64:dGVzt MIGRACJE_BRAMKA=0 PORT=18080 APP_ENV=testing \
      MIGRACJE_LIMIT_S=5 NADZOR_MIN_CZAS=30 \
      exec setsid "${dir}/entrypoint.sh" "${rola}" > "${dir}/log" 2>&1 ) &
  local pid=$!
  local i
  for i in $(seq 1 200); do
    (( $(licz start "${SLAD}") >= oczekiwane + oczekiwane_www )) && break
    kill -0 "${pid}" 2>/dev/null || break
    command sleep 0.1
  done
  local pgid; pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ')"
  [[ -n "${pgid}" ]] && GRUPY_DO_SPRZATNIECIA+=("${pgid}")
  local startow; startow="$(licz start "${SLAD}")"

  local t0; t0="$(date +%s.%N)"
  if [[ "${sposob}" == grupa ]]; then kill -TERM -- "-${pgid}" 2>/dev/null; else kill -TERM "${pid}" 2>/dev/null; fi
  local kod=0
  for i in $(seq 1 200); do
    kill -0 "${pid}" 2>/dev/null || break
    command sleep 0.1
  done
  if kill -0 "${pid}" 2>/dev/null; then kod=timeout; kill -KILL -- "-${pgid}" 2>/dev/null; else wait "${pid}" 2>/dev/null; kod=$?; fi
  local czas; czas="$(awk -v a="${t0}" -v b="$(date +%s.%N)" 'BEGIN{printf "%.2f", b-a}')"
  # Stan w chwili WYJŚCIA entrypointu: ile znaczników końca już istnieje.
  local konce; konce="$(licz koniec "${SLAD}")"
  command sleep 0.3
  # `sleep` z pętli harmonogramu bywa sierotą po sygnale tylko do PID 1 (do
  # końca swojego snu); kończy go zamknięcie kontenera, więc go nie liczymy.
  local zostalo; zostalo="$(ps -o comm= -g "${pgid}" 2>/dev/null | grep -vc -e '^sleep$' -e '^$' || true)"
  # Sierota po wyjściu entrypointu nie może przeżyć testu.
  kill -KILL -- "-${pgid}" 2>/dev/null || true

  {
    echo "startow=${startow}"; echo "konce=${konce}"; echo "kod=${kod}"; echo "czas=${czas}"; echo "zostalo=${zostalo}"
    echo "oczekiwane=$(( oczekiwane + oczekiwane_www ))"
  } > "${dir}/wynik"
  echo "${dir}"
}

czytaj() { grep "^$2=" "$1/wynik" | cut -d= -f2; }

echo "── SIGTERM w trakcie pracy: prawdziwy docker/entrypoint.sh ──"

for rola in worker all; do
  oczekiwane=4; [[ "${rola}" == all ]] && oczekiwane=2
  for sposob in grupa proces; do
    dir="$(przebieg "${rola}" "${sposob}" "${oczekiwane}")"
    etykieta="rola ${rola}, sygnał do: ${sposob}"
    ocen "${etykieta}: wszystkie procesy wystartowały ($(czytaj "${dir}" startow)/$(czytaj "${dir}" oczekiwane))" \
      "$( [[ "$(czytaj "${dir}" startow)" == "$(czytaj "${dir}" oczekiwane)" ]] && echo tak || echo nie )"
    ocen "${etykieta}: każdy proces dokończył (znacznik końca) PRZED wyjściem entrypointu ($(czytaj "${dir}" konce)/$(czytaj "${dir}" oczekiwane))" \
      "$( [[ "$(czytaj "${dir}" konce)" == "$(czytaj "${dir}" oczekiwane)" ]] && echo tak || echo nie )"
    ocen "${etykieta}: kod wyjścia 0 (planowe zatrzymanie, nie awaria) [kod=$(czytaj "${dir}" kod)]" \
      "$( [[ "$(czytaj "${dir}" kod)" == 0 ]] && echo tak || echo nie )"
    ocen "${etykieta}: zamknięcie trwało co najmniej tyle, co dokańczanie (${ZAKONCZENIE} s) [czas=$(czytaj "${dir}" czas) s]" \
      "$( awk -v c="$(czytaj "${dir}" czas)" -v z="${ZAKONCZENIE}" 'BEGIN{print (c+0 >= z-0.1) ? "tak" : "nie"}' )"
    ocen "${etykieta}: w grupie nie zostaje żaden proces (zostało: $(czytaj "${dir}" zostalo))" \
      "$( [[ "$(czytaj "${dir}" zostalo)" == 0 ]] && echo tak || echo nie )"
  done
done

# --- Kontrola ujemna: pułapka nadzorcy kolejek bez `wait` ---------------------
# Bez czekania entrypoint wychodzi, zanim PHP dokończy — Railway zabiłby zadanie
# SIGKILL-em po okresie zamknięcia.
echo "── Kontrola ujemna: nadzorca kolejek bez czekania na PHP ──"
TRYB=cichy; NIEUDANE=0
for sposob in grupa proces; do
  dir="$(przebieg worker "${sposob}" 4 's/wait \|\| true; exit 0/exit 0/')"
  if [[ "$(czytaj "${dir}" kod_mutacji)" == brak ]]; then
    TRYB=druk; ocen "mutacja bez wait zmieniła źródło entrypointu" nie; TRYB=cichy; continue
  fi
  ocen "" "$( [[ "$(czytaj "${dir}" konce)" == "$(czytaj "${dir}" oczekiwane)" ]] && echo tak || echo nie )"
done
TRYB=druk
ocen "kopia bez wait w pułapce nadzorcy OBLEWA scenariusz (mutacja wykryta w ${NIEUDANE}/2 sygnałach)" \
  "$( (( NIEUDANE >= 1 )) && echo tak || echo nie )"

echo
if (( oblane > 0 )); then
  printf '\033[0;31m%d oblanych\033[0m, %d zdanych\n' "${oblane}" "${zdane}"
  exit 1
fi
printf '\033[0;32mWszystkie %d asercji przeszły.\033[0m\n' "${zdane}"
