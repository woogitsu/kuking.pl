#!/usr/bin/env bash
# =============================================================================
#  Jedna seria pomiarowa #605 — generator + próbnik + zapis otoczenia
# =============================================================================
#
#  Po co osobny skrypt, skoro to trzy komendy: bo trzecia z nich jest tą,
#  o której najłatwiej zapomnieć. Maszyna jest współdzielona (self-hosted
#  runner CI, worktree innych agentów), więc KAŻDA seria musi mieć zapisane,
#  co jeszcze na niej chodziło. Bez tego nie da się odróżnić degradacji
#  aplikacji od cudzego hałasu — a to jest różnica między pomiarem a liczbą.
#
#  Skrypt uruchamia serie POJEDYNCZO. Nie wolno go puszczać równolegle.
#
#  Użycie:
#      scripts/seria-obciazenia-605.sh <nazwa> <rps> <sekundy> <katalog-wynikow> <manifest>
# =============================================================================
set -eu

NAZWA="${1:?nazwa serii}"
RPS="${2:?rps}"
CZAS="${3:?czas w sekundach}"
KATALOG="${4:?katalog wyników}"
MANIFEST="${5:?manifest z sesjami}"
KONTENER="${KONTENER:-kuking-b605-app}"
BAZA="${BAZA_POMIARU:-kuking_b605_obciazenie}"

cd "$(dirname "$0")/.."
mkdir -p "$KATALOG"

OTOCZENIE="$KATALOG/otoczenie-$NAZWA.txt"
PROBNIK="$KATALOG/probnik-$NAZWA.jsonl"
WYNIK="$KATALOG/seria-$NAZWA.json"

{
  echo "# Otoczenie serii $NAZWA — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo
  echo '## uptime przed serią'
  uptime
  echo
  echo '## procesy powyżej 2% CPU (poza tą serią)'
  ps -eo pid,pcpu,pmem,etime,comm --sort=-pcpu | awk 'NR==1 || $2+0>2' | head -25
  echo
  echo '## nasłuchujące porty aplikacji innych agentów'
  ss -ltn 2>/dev/null | awk 'NR==1 || /:80[0-9][0-9]|:86[0-9][0-9]|:34555/' | head -20
  echo
  echo '## kontenery'
  docker ps --format '{{.Names}} {{.Image}} {{.Status}}'
} > "$OTOCZENIE"

bash scripts/probnik-obciazenia-605.sh "$PROBNIK" "$KONTENER" "$BAZA" 1 &
PID_PROBNIKA=$!
trap 'kill "$PID_PROBNIKA" 2>/dev/null || true' EXIT
sleep 2

node scripts/generator-obciazenia-605.mjs seria \
  --manifest "$MANIFEST" --baza http://127.0.0.1:8605 \
  --nazwa "$NAZWA" --rps "$RPS" --czas "$CZAS" --wynik "$WYNIK" > /dev/null

# POWRÓT DO NORMY — to jest osobne pytanie z #605 („czy i jak szybko system
# wraca do normy”), więc próbnik chodzi jeszcze 60 s PO zdjęciu obciążenia.
# Te 60 sekund są w tym samym pliku .jsonl, rozpoznasz je po `t`.
sleep 60
kill "$PID_PROBNIKA" 2>/dev/null || true
wait "$PID_PROBNIKA" 2>/dev/null || true
trap - EXIT

{
  echo
  echo '## uptime po serii i po 60 s wybiegu'
  uptime
} >> "$OTOCZENIE"

echo "seria $NAZWA: $WYNIK / $PROBNIK / $OTOCZENIE"
