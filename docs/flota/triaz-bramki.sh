#!/usr/bin/env bash
# Triaż ostatniej porażki bramki: nazwa gałęzi, czym padła, ile razy.
# Powstało, bo ręczne grzebanie w logu po każdej porażce kosztowało trzy
# polecenia i dwie minuty, a porażek w nocy jest kilkanaście.
set -uo pipefail
L=/home/mateusz/flota/kolejka9.log
ILE="${1:-1}"

grep -E "^########## " "$L" | tail -n $((ILE+1)) | head -n "$ILE" | while read -r _ g _ st; do
  echo "══════ $g  (start $st)"
  blok=$(awk -v g="$g" -v s="$st" 'index($0,"########## " g "  start " s){f=1; next} /^########## /{f=0} f' "$L" | sed 's/\x1b\[[0-9;]*m//g')
  printf '%s\n' "$blok" | grep -E "^  HEAD:" | head -1
  printf '%s\n' "$blok" | grep -E "✗ " | head -4
  printf '%s\n' "$blok" | grep -oE "FAILED  [^>]+> [^…]*" | sed 's/^/   /' | head -6
  printf '%s\n' "$blok" | grep -E "Tests: |Problemów do" | head -2
  printf '%s\n' "$blok" | grep -E "push exit=" | head -1
  echo "   prob nieudanych dotad: $(grep -cxF "$g" /home/mateusz/flota/nieudane.txt 2>/dev/null)"
done
