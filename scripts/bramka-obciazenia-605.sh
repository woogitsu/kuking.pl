#!/usr/bin/env bash
# =============================================================================
#  Bramka obciążenia — wpuszcza stopień rampy dopiero, gdy host jest dość cichy
# =============================================================================
#
#  DLACZEGO TO ISTNIEJE
#  Ta maszyna jest wspólnym hostem CI pięciu projektów (17 runnerów). Zajętość
#  nie pochodzi od tego zadania, nie wolno jej wyłączać i NIE MINIE sama —
#  jest strukturalna, nie chwilowa. Czekanie „aż będzie cicho" nigdy by się
#  nie skończyło. Zamiast czekać, bramkujemy: stopień startuje tylko wtedy,
#  gdy obce obciążenie utrzyma się pod progiem przez pełne okno spokoju.
#
#  CZEGO TA BRAMKA NIE ROBI: nie zatrzymuje, nie usypia i nie dotyka żadnego
#  cudzego procesu. Czyta /proc i czeka. Jeśli nie doczeka — zwraca 1
#  i stopień zostaje zapisany jako NIEWYKONANY z powodem. Wiersz „nie udało
#  się zmierzyć przy 110 rps, trzy próby skażone" jest wynikiem.
#  Zmyślony punkt nasycenia nie jest.
#
#  Użycie:
#      scripts/bramka-obciazenia-605.sh [prog_rdzeni] [prog_psi] [spokoj_s] [max_czekania_s]
#  Kod wyjścia: 0 = otwarta, 1 = nie doczekała.
# =============================================================================
set -u

PROG_RDZENI="${1:-18.0}"
PROG_PSI="${2:-25.0}"
SPOKOJ="${3:-60}"
MAKS="${4:-900}"
KONTENER="${KONTENER:-kuking-b605-app}"

ID="$(docker inspect -f '{{.Id}}' "$KONTENER" 2>/dev/null || echo '')"
CG="/sys/fs/cgroup/system.slice/docker-$ID.scope"
HZ="$(getconf CLK_TCK)"
RDZENI="$(nproc)"

czytaj_cpu_hosta() { awk '/^cpu /{s=0; for(i=2;i<=NF;i++) s+=$i; print s-$5-$6; exit}' /proc/stat; }
czytaj_cpu_stanowiska() { awk '/^usage_usec/{print $2}' "$CG/cpu.stat" 2>/dev/null || echo 0; }

# ---------------------------------------------------------------------------
#  TRZECI WARUNEK: czy pracują runnery `kuking`.
#
#  Progi CPU i PSI opisują hałas jako zjawisko. Ten warunek opisuje jego
#  JEDYNĄ część, na którą MAMY WPŁYW: runnery `kuking-01..04` chodzą dlatego,
#  że ktoś z nas wypchnął gałąź. Runnery `lockstate`, `metro` i `osadale`
#  należą do cudzych projektów — ich nie zatrzymujemy, nie czekamy na nie
#  w nieskończoność i traktujemy jak pogodę.
#
#  Warunek jest osobny od progu CPU, bo runner potrafi mieć PRZERWĘ MIĘDZY
#  ZADANIAMI: obciążenie na chwilę spada pod próg, bramka by się otworzyła,
#  a trzy minuty później startuje „Panel marki" (~17 min) i rozjeżdża stopień.
#  Dokładnie tak przepadły `r005-p1` i `r005-p2`.
#
#  Czytamy tylko listę procesów. Niczego nie zatrzymujemy.
#
#  UWAGA DO SPOSOBU WYKRYWANIA — prosty wzorzec po nazwie katalogu NIE DZIAŁA.
#  `ps -eo args | grep -oE 'actions-runner-kuking-0[0-9]' | sort -u` zwraca
#  WSZYSTKIE CZTERY ZAWSZE, bo `Runner.Listener` każdego runnera stoi
#  nieprzerwanie jako usługa i ma tę ścieżkę w linii poleceń. Sprawdzone:
#  przy dwóch pracujących runnerach ta komenda i tak pokazywała cztery.
#
#  Rozstrzyga `Runner.Worker` — ten proces istnieje WYŁĄCZNIE wtedy, gdy
#  runner ma przydzielone zadanie. Pusto = żaden runner `kuking` nie pracuje.
# ---------------------------------------------------------------------------
pracujace_runnery_kuking() {
  ps -eo args 2>/dev/null \
    | grep 'Runner\.Worker' \
    | grep -oE 'actions-runner-kuking-0[0-9]' \
    | sort -u \
    | tr '\n' ' '
}

PH="$(czytaj_cpu_hosta)"; PS="$(czytaj_cpu_stanowiska)"; PT="$(awk '{print $1}' /proc/uptime)"
sleep 1

CISZA=0
UPLYW=0
SUMA_OBCE=0
PROB=0

while [ "$UPLYW" -lt "$MAKS" ]; do
  H="$(czytaj_cpu_hosta)"; S="$(czytaj_cpu_stanowiska)"; T="$(awk '{print $1}' /proc/uptime)"
  PSI="$(awk -F'avg10=' '/^some/{split($2,a," "); print a[1]; exit}' /proc/pressure/cpu 2>/dev/null)"
  PSI="${PSI:-0}"
  DT="$(awk -v a="$T" -v b="$PT" 'BEGIN{printf "%.4f", a-b}')"
  OBCE="$(awk -v h="$H" -v ph="$PH" -v s="$S" -v ps="$PS" -v dt="$DT" -v hz="$HZ" \
    'BEGIN{if(dt<=0){print 0; exit} z=(h-ph)/hz/dt; w=(s-ps)/1e6/dt; o=z-w; if(o<0)o=0; printf "%.2f", o}')"
  PH="$H"; PS="$S"; PT="$T"

  PROB=$((PROB + 1))
  SUMA_OBCE="$(awk -v a="$SUMA_OBCE" -v b="$OBCE" 'BEGIN{printf "%.2f", a+b}')"
  RUNNERY="$(pracujace_runnery_kuking)"

  if [ -z "$RUNNERY" ] && awk -v o="$OBCE" -v p="$PSI" -v po="$PROG_RDZENI" -v pp="$PROG_PSI" \
       'BEGIN{exit !(o <= po && p <= pp)}'; then
    CISZA=$((CISZA + 1))
  else
    if [ "$CISZA" -gt 0 ]; then
      printf 'bramka: cisza przerwana po %ss (obce %s rdzeni, PSI %s, runnery kuking: %s)\n' \
        "$CISZA" "$OBCE" "$PSI" "${RUNNERY:-brak}" >&2
    fi
    CISZA=0
  fi

  if [ "$CISZA" -ge "$SPOKOJ" ]; then
    printf 'bramka OTWARTA po %ss: obce <= %s rdzeni, PSI <= %s i ZERO pracujących runnerów kuking przez %ss\n' \
      "$UPLYW" "$PROG_RDZENI" "$PROG_PSI" "$SPOKOJ" >&2
    exit 0
  fi

  UPLYW=$((UPLYW + 1))
  if [ $((UPLYW % 30)) -eq 0 ]; then
    printf 'bramka: %ss/%ss, obce %s rdzeni z %s, PSI %s, runnery kuking: %s, cisza %ss/%ss\n' \
      "$UPLYW" "$MAKS" "$OBCE" "$RDZENI" "$PSI" "${RUNNERY:-brak}" "$CISZA" "$SPOKOJ" >&2
  fi
  sleep 1
done

SREDNIE="$(awk -v s="$SUMA_OBCE" -v n="$PROB" 'BEGIN{if(n>0)printf "%.2f", s/n; else print 0}')"
printf 'bramka NIE DOCZEKAŁA: %ss przy progu %s rdzeni / PSI %s; średnie obce obciążenie %s rdzeni z %s\n' \
  "$MAKS" "$PROG_RDZENI" "$PROG_PSI" "$SREDNIE" "$RDZENI" >&2
exit 1
