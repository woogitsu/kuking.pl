#!/usr/bin/env bash
# usun-galezie-2.sh — sprzątanie gałęzi na origin (woogitsu/kuking.pl), część 2.
#
# Co robi: usuwa ze zdalnego repozytorium gałęzie kategorii C, które po ponownym przeglądzie
# okazały się niczego nie wnosić: scalenie gałęzi z origin/main daje DOKŁADNIE to samo drzewo
# co origin/main (`git merge-tree --write-tree`), bez konfliktów. Ich commity (kontrole
# ujemne, testy, poprawki) są już w main inną drogą (squash / kopia / ponowna implementacja).
# Przed skasowaniem KAŻDEJ gałęzi weryfikacja jest robiona ponownie tą metodą; gałęzie, które
# jej nie przejdą, nie istnieją, mają otwarty PR (gdy jest `gh`) albo są chronione, są pomijane.
# Lokalnie nic nie jest usuwane (poza `git fetch --prune`). Gałęzie, które coś wnoszą, NIE są
# tu ujęte — to decyzja właściciela (patrz raport).
#
# Wymaga git >= 2.38 (merge-tree --write-tree).
#
# Uruchomienie (w swoim klonie repo):
#   bash usun-galezie-2.sh             # podgląd, nic nie kasuje
#   bash usun-galezie-2.sh --wykonaj   # kasowanie partiami po 20
#
# Data sporządzenia: 2026-09-29
set -euo pipefail

WYKONAJ=0
[ "${1:-}" = "--wykonaj" ] && WYKONAJ=1

git rev-parse --git-dir >/dev/null 2>&1 || { echo "Uruchom w klonie repo." >&2; exit 1; }
git fetch --prune origin

# Kategoria C0 — scalenie z origin/main nie zmienia drzewa main (nic nie wnoszą)
LISTA=(
  "claude/2050-zdjecia-po-bledzie"
  "codex/2054-empty-cooked-negative"
  "codex/2062-notification-action-negative"
  "codex/2063-recipe-thumbnail-negative"
  "codex/2066-urgent-alert-negative"
  "codex/hide-expiry-local-date"
)

CHRONIONE_RE='^(main|gh-pages|production|prod|produkcja|release.*|claude/paczka-.*)$'

OTWARTE=""
if command -v gh >/dev/null 2>&1; then
  OTWARTE="$(gh pr list --state open --limit 500 --json headRefName --jq '.[].headRefName' || true)"
else
  echo "UWAGA: brak polecenia gh — nie sprawdzam otwartych PR-ów w chwili uruchomienia (lista była filtrowana z góry)."
fi

DRZEWO_MAIN="$(git rev-parse 'origin/main^{tree}')"

DO_USUNIECIA=()
POMINIETE=()

pomin() { echo "POMIJAM $1 — $2"; POMINIETE+=("$1"); }

sprawdz() { # $1 = gałąź
  local g="$1" wynik drzewo
  if [[ "$g" =~ $CHRONIONE_RE ]]; then pomin "$g" "gałąź chroniona"; return; fi
  if ! git show-ref --verify --quiet "refs/remotes/origin/$g"; then pomin "$g" "nie istnieje na origin"; return; fi
  if [ -n "$OTWARTE" ] && printf '%s\n' "$OTWARTE" | grep -qxF "$g"; then pomin "$g" "ma otwarty PR"; return; fi
  if ! wynik="$(git merge-tree --write-tree "origin/main" "origin/$g" 2>/dev/null)"; then
    pomin "$g" "scalenie z main daje konflikty (coś wnosi)"; return
  fi
  drzewo="$(printf '%s\n' "$wynik" | head -1)"
  if [ "$drzewo" != "$DRZEWO_MAIN" ]; then pomin "$g" "scalenie zmienia drzewo main (coś wnosi)"; return; fi
  echo "OK $g (drzewo scalenia == drzewo origin/main)"
  DO_USUNIECIA+=("$g")
}

for g in "${LISTA[@]}"; do sprawdz "$g"; done

echo
echo "Do usunięcia: ${#DO_USUNIECIA[@]}, pominięte: ${#POMINIETE[@]}"

USUNIETE=0
if [ "$WYKONAJ" -eq 1 ]; then
  i=0
  while [ "$i" -lt "${#DO_USUNIECIA[@]}" ]; do
    partia=("${DO_USUNIECIA[@]:i:20}")
    echo "Usuwam partię (${#partia[@]}): ${partia[*]}"
    git push origin --delete "${partia[@]}"
    USUNIETE=$((USUNIETE + ${#partia[@]}))
    i=$((i + 20))
  done
else
  echo "Tryb podglądu — nic nie usunięto. Aby skasować: bash usun-galezie-2.sh --wykonaj"
fi

echo
echo "PODSUMOWANIE: usunięte: $USUNIETE, pominięte: ${#POMINIETE[@]}, kandydaci: ${#DO_USUNIECIA[@]}"
