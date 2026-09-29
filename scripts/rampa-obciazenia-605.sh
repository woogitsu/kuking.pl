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
#  Od 29.09.2026 rampa zna KRYTERIUM NASYCENIA i POWRÓT DO NORMY (issue #605:
#  „stopniowo zwiększać … aż do zauważalnego punktu degradacji", „czy system
#  szybko wraca do normy"):
#
#    * po każdym czystym stopniu `scripts/nasycenie-605.mjs stopien` ocenia go
#      jawnym, z góry ustalonym kryterium (ZDROWY / DEGRADACJA / NASYCONY);
#    * po każdym stopniu (po przerwie) sonda `generator … powrot` czeka, aż
#      serwis odpowie na lekkie żądania trzy razy z rzędu w budżecie czasu;
#    * serwis, który NIE WRÓCIŁ, kończy rampę (kod 3): następny stopień mierzyłby
#      zaległość poprzednika, nie serwer — pomiar 20.09.2026 właśnie tak
#      zniekształcił stopnie 60 i 120. Nie „przeczekujemy" w nieskończoność
#      i niczego nie restartujemy: restart kontenera to decyzja człowieka;
#    * po pierwszym stopniu NASYCONYM (i zmierzonym powrocie) rampa staje
#      (`PO_NASYCENIU=stop`, domyślnie); `PO_NASYCENIU=dalej` mierzy też wyższe
#      stopnie, żeby zobaczyć kształt degradacji.
#
#  Zdjęcia: rampa ODMAWIA startu bez `KORPUS_ZDJEC` (jawny korpus fotografii,
#  docs/obciazenie/KORPUS_605.md). Bez niego upload to jeden syntetyczny plik
#  12 Mpx z GD — nie spełnia wymogu „prawdziwe zdjęcia 12/24/48 MP" z #605.
#  Świadomy pomiar na syntetycznych: `ZDJECIA_SYNTETYCZNE=tak` (zapisze się
#  w rampa.log).
#
#  Nie zatrzymuje ani nie usypia żadnego cudzego procesu.
#
#  Użycie:
#      scripts/rampa-obciazenia-605.sh <katalog-wynikow> <manifest>
#  Sterowanie: CZAS (150), STOPNIE ("5 10 20 35 50 75 110 160"), MAKS_PROB (3),
#              PRZERWA (30), PO_NASYCENIU (stop|dalej), MAKS_POWROTU_S (900),
#              BUDZET_POWROTU_MS (1000), POWROT_CO_MS (5000), KORPUS_ZDJEC,
#              PROG_RDZENI, PROG_PSI, SPOKOJ_S, MAKS_CZEKANIA_S
#  Kody wyjścia: 0 = rampa zakończona, 2 = zły start (brak korpusu),
#                3 = serwis NIE WRÓCIŁ do normy — rampa przerwana.
# =============================================================================
set -u

KATALOG="${1:?katalog wyników}"
MANIFEST="${2:?manifest z sesjami}"
CZAS="${CZAS:-150}"
STOPNIE="${STOPNIE:-5 10 20 35 50 75 110 160}"
MAKS_PROB="${MAKS_PROB:-3}"
PRZERWA="${PRZERWA:-30}"
PO_NASYCENIU="${PO_NASYCENIU:-stop}"
MAKS_POWROTU_S="${MAKS_POWROTU_S:-900}"
BUDZET_POWROTU_MS="${BUDZET_POWROTU_MS:-1000}"
POWROT_CO_MS="${POWROT_CO_MS:-5000}"
BAZA_APLIKACJI="${BAZA_APLIKACJI:-http://127.0.0.1:8605}"
# Podmieniane wyłącznie przez testy przyrządu (atrapy bez bazy i kontenera).
SERIA_605="${SERIA_605:-scripts/seria-obciazenia-605.sh}"

cd "$(dirname "$0")/.."
mkdir -p "$KATALOG"
PODSUMOWANIE="$KATALOG/rampa.log"
: > "$PODSUMOWANIE"

case "$PO_NASYCENIU" in
  stop|dalej) ;;
  *) echo "PO_NASYCENIU musi być 'stop' albo 'dalej', jest: $PO_NASYCENIU" >&2; exit 2 ;;
esac

if [ -z "${KORPUS_ZDJEC:-}" ] && [ "${ZDJECIA_SYNTETYCZNE:-}" != "tak" ]; then
  echo "Brak KORPUS_ZDJEC: bez jawnego korpusu fotografii upload to jeden syntetyczny plik 12 Mpx," | tee -a "$PODSUMOWANIE" >&2
  echo "a #605 wymaga prawdziwych zdjęć 12/24/48 MP. Podaj KORPUS_ZDJEC=<korpus.json>" | tee -a "$PODSUMOWANIE" >&2
  echo "(docs/obciazenie/KORPUS_605.md) albo świadomie ZDJECIA_SYNTETYCZNE=tak." | tee -a "$PODSUMOWANIE" >&2
  exit 2
fi
if [ -n "${KORPUS_ZDJEC:-}" ]; then
  echo "zdjęcia: korpus jawny $KORPUS_ZDJEC" | tee -a "$PODSUMOWANIE"
else
  echo "zdjęcia: SYNTETYCZNE (ZDJECIA_SYNTETYCZNE=tak) — wynik nie mówi nic o prawdziwych fotografiach" | tee -a "$PODSUMOWANIE"
fi

NASYCONY=''

for RPS in $STOPNIE; do
  STOPIEN="$(printf 'r%03d' "$RPS")"
  CZYSTA=''
  OSTATNIA=''
  for PROBA in $(seq 1 "$MAKS_PROB"); do
    NAZWA="$STOPIEN-p$PROBA"
    OSTATNIA="$NAZWA"
    echo "=== $NAZWA ($RPS rps, ${CZAS}s), próba $PROBA/$MAKS_PROB — $(date -u +%H:%M:%SZ)" | tee -a "$PODSUMOWANIE"

    # Znacznik pozycji w dzienniku PostgreSQL: wolne zapytania tego stopnia
    # wycinamy potem po bajtach, bo dziennik klastra jest współdzielony.
    stat -c %s /home/mateusz/kuking-local/pg55439-restart.log \
      > "$KATALOG/pglog-offset-$NAZWA.txt" 2>/dev/null || true

    bash "$SERIA_605" "$NAZWA" "$RPS" "$CZAS" "$KATALOG" "$MANIFEST"
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
      # KRYTERIUM NASYCENIA — jawne, z góry, w scripts/nasycenie-605.mjs.
      OCENA="$(node scripts/nasycenie-605.mjs stopien "$KATALOG" "$NAZWA")"
      KOD_OCENY=$?
      echo "  -> ocena: $OCENA" | tee -a "$PODSUMOWANIE"
      if [ "$KOD_OCENY" = "3" ]; then
        NASYCONY="$NAZWA"
        echo "  == NASYCENIE: stopień $STOPIEN ($RPS rps) spełnia kryterium nasycenia" | tee -a "$PODSUMOWANIE"
      fi
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

  # POWRÓT DO NORMY — bramka przed następnym stopniem. Stała przerwa nie mówi,
  # czy serwis odpowiada; sonda mówi. Pomija stopień, którego bramka nie
  # doczekała (nic nie leciało, więc nie ma z czego wracać).
  if [ -n "$OSTATNIA" ] && [ -f "$KATALOG/seria-$OSTATNIA.json" ]; then
    echo "--- powrót do normy po $OSTATNIA (limit ${MAKS_POWROTU_S}s, budżet ${BUDZET_POWROTU_MS} ms) ---" | tee -a "$PODSUMOWANIE"
    node scripts/generator-obciazenia-605.mjs powrot --baza "$BAZA_APLIKACJI" \
      --wynik "$KATALOG/powrot-$OSTATNIA.json" --limit "$MAKS_POWROTU_S" \
      --budzet_ms "$BUDZET_POWROTU_MS" --co "$POWROT_CO_MS" > /dev/null
    KOD_POWROTU=$?
    if [ "$KOD_POWROTU" != "0" ]; then
      echo "  == SERWIS NIE WRÓCIŁ DO NORMY (kod $KOD_POWROTU) po $OSTATNIA — rampa przerwana." | tee -a "$PODSUMOWANIE"
      echo "     Następny stopień mierzyłby zaległość poprzedniego, nie serwer. Nie restartuję niczego:" | tee -a "$PODSUMOWANIE"
      echo "     restart kontenera to decyzja człowieka; wynik odczytu: powrot-$OSTATNIA.json" | tee -a "$PODSUMOWANIE"
      node scripts/nasycenie-605.mjs analiza "$KATALOG" | tee "$KATALOG/nasycenie.txt" | tee -a "$PODSUMOWANIE"
      exit 3
    fi
    echo "  == serwis wrócił do normy po $OSTATNIA" | tee -a "$PODSUMOWANIE"
  fi

  if [ -n "$NASYCONY" ] && [ "$PO_NASYCENIU" = "stop" ]; then
    echo "  == nasycenie osiągnięte i powrót zmierzony — kończę (PO_NASYCENIU=stop)" | tee -a "$PODSUMOWANIE"
    break
  fi
done

echo "=== rampa zakończona — $(date -u +%H:%M:%SZ)" | tee -a "$PODSUMOWANIE"
node scripts/nasycenie-605.mjs analiza "$KATALOG" | tee "$KATALOG/nasycenie.txt" | tee -a "$PODSUMOWANIE"
