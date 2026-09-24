#!/usr/bin/env bash
# WYMAGA: list kolejki pchania w /home/mateusz/flota/*.txt (WSL tej maszyny); poza nią nie zadziała — wartość niesie opisana w nagłówku pułapka z 'grep -v ... && mv'.
# Wpuszcza gałąź z powrotem do kolejki i zeruje jej licznik porażek.
# Używać, gdy porażki dotyczyły stanu SPRZED poprawki — inaczej gałąź
# odpada po trzech próbach za cudzą winę.
#
# PUŁAPKA, na którą już raz wdepnąłem: nie pisać
#   grep -v ... > plik.n && mv plik.n plik
# bo `grep -v`, gdy nic nie zostaje, zwraca kod 1 i `&&` blokuje `mv`.
set -uo pipefail
F=/home/mateusz/flota
B="${1:?podaj nazwę gałęzi}"
for f in "$F/nieudane.txt" "$F/pchniete.txt"; do
  [ -f "$f" ] || continue
  grep -vxF "$B" "$f" > "$f.n" || true
  mv "$f.n" "$f"
done
grep -qxF "$B" "$F/do-pchniecia.txt" || echo "$B" >> "$F/do-pchniecia.txt"
printf '%s: nieudane=%s pchniete=%s na liscie=%s\n' "$B" \
  "$(grep -cxF "$B" "$F/nieudane.txt" || true)" \
  "$(grep -cxF "$B" "$F/pchniete.txt" || true)" \
  "$(grep -cxF "$B" "$F/do-pchniecia.txt" || true)"
