#!/usr/bin/env bash
# WYMAGA: runtime'ów w /home/mateusz/flota/*-run (WSL tej maszyny); poza nią nie zadziała — to kontrola dodatnia do kontraktu nazw baz próbnych.
# Kontrola dodatnia: czy dwa rozne runtime'y dostaja ROZNE nazwy baz.
set -uo pipefail
for K in /home/mateusz/flota/stan-sesji-2-run /home/mateusz/flota/gpt-monitoring-ODZYSK-run; do
  [ -d "$K" ] || { echo "$K: brak"; continue; }
  STARY="_glowny"
  NOWY="_$(printf '%s' "$K" | cksum | cut -d' ' -f1)"
  printf '%-52s stary=%s  nowy=%s\n' "$(basename "$K")" "kuking_zrodlo_proby${STARY}" "kuking_zrodlo_proby${NOWY}"
done
echo "--- czy sufiks jest stabilny przy powtorzeniu ---"
K=/home/mateusz/flota/stan-sesji-2-run
a="$(printf '%s' "$K" | cksum | cut -d' ' -f1)"; b="$(printf '%s' "$K" | cksum | cut -d' ' -f1)"
[ "$a" = "$b" ] && echo "stabilny ($a)" || echo "NIESTABILNY"
