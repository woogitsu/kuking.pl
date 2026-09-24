#!/usr/bin/env bash
# =============================================================================
#  Rampa do nasycenia — #605
# =============================================================================
#
#  Puszcza stopnie SZEREGOWO, każdy przez bramkę obciążenia
#  (`scripts/seria-obciazenia-605.sh`), i stosuje politykę powtórzeń:
#
#    * stopień SKAŻONY  → powtarzany, do `MAKS_PROB` razy; wszystkie próby
#      zostają w dowodach razem z powodem,
#    * bramka nie doczekała → stopień zapisany jako NIEWYKONANY z powodem
#      i rampa idzie dalej; nie zgadujemy, co by wyszło.
#
#  Nie zatrzymuje ani nie usypia żadnego cudzego procesu.
#
#  Użycie:
#      scripts/rampa-obciazenia-605.sh <katalog-wynikow> <manifest>
#  Sterowanie: CZAS (150), STOPNIE ("5 10 20 35 50 75 110 160"), MAKS_PROB (3),
#              PROG_RDZENI, PROG_PSI, SPOKOJ_S, MAKS_CZEKANIA_S
# =============================================================================
set -u

KATALOG="${1:?katalog wyników}"
MANIFEST="${2:?manifest z sesjami}"
CZAS="${CZAS:-150}"
STOPNIE="${STOPNIE:-5 10 20 35 50 75 110 160}"
MAKS_PROB="${MAKS_PROB:-3}"
PRZERWA="${PRZERWA:-30}"

cd "$(dirname "$0")/.."
mkdir -p "$KATALOG"
PODSUMOWANIE="$KATALOG/rampa.log"
: > "$PODSUMOWANIE"

for RPS in $STOPNIE; do
  STOPIEN="$(printf 'r%03d' "$RPS")"
  CZYSTA=''
  for PROBA in $(seq 1 "$MAKS_PROB"); do
    NAZWA="$STOPIEN-p$PROBA"
    echo "=== $NAZWA ($RPS rps, ${CZAS}s), próba $PROBA/$MAKS_PROB — $(date -u +%H:%M:%SZ)" | tee -a "$PODSUMOWANIE"

    # Znacznik pozycji w dzienniku PostgreSQL: wolne zapytania tego stopnia
    # wycinamy potem po bajtach, bo dziennik klastra jest współdzielony.
    stat -c %s /home/mateusz/kuking-local/pg55439-restart.log \
      > "$KATALOG/pglog-offset-$NAZWA.txt" 2>/dev/null || true

    bash scripts/seria-obciazenia-605.sh "$NAZWA" "$RPS" "$CZAS" "$KATALOG" "$MANIFEST"
    KOD=$?

    if [ "$KOD" = "2" ]; then
      echo "  -> NIEWYKONANY: bramka nie doczekała" | tee -a "$PODSUMOWANIE"
      break
    fi

    W=$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['werdykt'])" "$KATALOG/werdykt-$NAZWA.json" 2>/dev/null || echo BRAK)
    if [ "$W" = "CZYSTY" ]; then
      CZYSTA="$NAZWA"
      python3 - "$KATALOG/seria-$NAZWA.json" "$KATALOG/werdykt-$NAZWA.json" <<'PY' | tee -a "$PODSUMOWANIE"
import json, sys
w = json.load(open(sys.argv[1])); v = json.load(open(sys.argv[2]))
r = w['razem']; o = v['obce_obciazenie_rdzenie']
print(f"  -> CZYSTY: {r['przepustowosc_rps']} rps ok, p50={r['p50']} p95={r['p95']} p99={r['p99']}, "
      f"błąd={r['blad_procent']}%, w locie szczyt={w['w_locie_szczyt']}, "
      f"generator={w['koszt_generatora']['cpu_rdzenie_srednio']} rdzenia, "
      f"obce mediana={o['mediana']} max={o['max']} rdzeni")
PY
      break
    fi

    echo "  -> SKAŻONY (zostaje w dowodach), powtarzam" | tee -a "$PODSUMOWANIE"
    sleep "$PRZERWA"
  done

  if [ -z "$CZYSTA" ]; then
    echo "  == stopień $STOPIEN NIEWYKONANY CZYSTO po $MAKS_PROB próbach" | tee -a "$PODSUMOWANIE"
  fi

  # Przerwa między stopniami: kolejka i cache mają wrócić do spoczynku,
  # inaczej następny stopień zaczyna od zaległości poprzedniego.
  sleep "$PRZERWA"
done

echo "=== rampa zakończona — $(date -u +%H:%M:%SZ)" | tee -a "$PODSUMOWANIE"
