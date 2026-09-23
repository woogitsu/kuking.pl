#!/bin/bash
# WYMAGA: tylko klonu repozytorium (ścieżka windowsowa albo /mnt/c/...) i gita — jedyny skrypt z tego katalogu, który da się uruchomić gdziekolwiek po podmianie stałej REPO; niczego nie zmienia.
# Straznik kolejki: sprawdza galezie czekajace na scalenie PRZECIW SOBIE,
# a nie przeciw main. Powstal 21.09.2026 po trzech kolizjach tego samego dnia,
# z ktorych kazda zapalala dopiero PO scaleniu drugiej galezi.
#
# Uzycie:  bash kolizje-w-kolejce.sh [plik-z-lista-galezi]
# Domyslnie czyta /home/mateusz/flota/do-pchniecia.txt (pierwsza kolumna).
#
# NIE zmienia niczego. Tylko czyta i wypisuje.

set -u
REPO="/c/Users/matma/Documents/Codex/kuking.pl"
[ -d "$REPO" ] || REPO="/mnt/c/Users/matma/Documents/Codex/kuking.pl"
cd "$REPO" || { echo "Nie widze repozytorium kanonicznego."; exit 1; }

LISTA="${1:-/home/mateusz/flota/do-pchniecia.txt}"
ROBOCZY=$(mktemp -d)
trap 'rm -rf "$ROBOCZY"' EXIT

# --- ustal galezie do zbadania -------------------------------------------
if [ -f "$LISTA" ]; then
  awk '{print $1}' "$LISTA" | sort -u > "$ROBOCZY/nazwy.txt"
else
  echo "Nie ma $LISTA — biore wszystkie galezie zdalne poza main."
  git branch -r --list 'origin/*' | sed 's|^ *origin/||' | grep -v '^main$' | grep -v ' -> ' | sort -u > "$ROBOCZY/nazwy.txt"
fi

# zostaw tylko te, ktore naprawde istnieja; --verify --quiet, bo goly
# rev-parse wypisuje nieznany ref na STDOUT i $(...) lapie smiec
: > "$ROBOCZY/galezie.txt"
while read -r g; do
  [ -z "$g" ] && continue
  s=$(git rev-parse --verify --quiet "refs/remotes/origin/$g") \
    || s=$(git rev-parse --verify --quiet "refs/heads/$g") || true
  [ -n "${s:-}" ] && printf '%s\t%s\n' "$g" "$s" >> "$ROBOCZY/galezie.txt"
done < "$ROBOCZY/nazwy.txt"

ILE=$(wc -l < "$ROBOCZY/galezie.txt")
echo "Badam $ILE galezi wobec origin/main = $(git rev-parse --short origin/main)"
echo

# --- zbierz fakty per galaz ----------------------------------------------
: > "$ROBOCZY/decyzje.txt"      # numer <TAB> galaz
: > "$ROBOCZY/migracje.txt"     # nazwa-pliku <TAB> galaz <TAB> hasz
: > "$ROBOCZY/definicje.txt"    # sygnatura <TAB> galaz <TAB> plik

while IFS=$'\t' read -r g sha; do
  # numery decyzji DODANE przez galaz
  # TYLKO dodane NAGLOWKI `## D-xxx`, nie kazde wystapienie numeru.
  # Pierwsza wersja liczyla wystapienia i zglosila D-052 jako sporne przez
  # trzy galezie — a D-052 stoi na main od dawna, galezie tylko sie do niego
  # ODWOLYWALY (`### Uzupelnienie D-052/D-055 ...`). Trzy IDENTYCZNE liczby
  # odwolan (39, 39, 39) byly sygnalem, ze to falszywy alarm.
  # DRUGI raz ten sam blad: `grep -oE` na dopasowanej linii lapie TEZ numery
  # WSPOMNIANE w tytule naglowka (np. "D-225 ... (zastepuje D-224)" dawalo
  # falszywa kolizje D-224). Nadany jest TYLKO ten na poczatku — stad `sed`.
  git diff "origin/main" "$sha" -- docs/DECISIONS.md 2>/dev/null \
    | grep -E '^\+#{1,6} D-[0-9]{3}' | sed -E 's/^\+#+ (D-[0-9]{3}).*$/\1/' | sort -u \
    | while read -r n; do printf '%s\t%s\n' "$n" "$g" >> "$ROBOCZY/decyzje.txt"; done

  # pliki migracji DODANE przez galaz, z haszem tresci
  git diff --name-only --diff-filter=A "origin/main" "$sha" -- 'database/migrations/*' 2>/dev/null \
    | while read -r f; do
        h=$(git rev-parse --verify --quiet "$sha:$f") || continue
        printf '%s\t%s\t%s\n' "$(basename "$f")" "$g" "$h" >> "$ROBOCZY/migracje.txt"
      done

  # definicje funkcji/klas/stalych DODANE przez galaz
  git diff "origin/main" "$sha" 2>/dev/null \
    | grep -E '^\+' \
    | grep -oE '^\+[[:space:]]*(function [a-zA-Z_][a-zA-Z0-9_]*|(public|private|protected)[[:space:]]+function [a-zA-Z_][a-zA-Z0-9_]*|class [A-Z][a-zA-Z0-9_]*|const [A-Z_][A-Z0-9_]*|[a-zA-Z_][a-zA-Z0-9_]*\(\)[[:space:]]*\{)' \
    | sed -e 's/^\+[[:space:]]*//' -e 's/[[:space:]]*{$//' -e 's/[[:space:]]\+/ /g' | sort -u \
    | while read -r d; do printf '%s\t%s\n' "$d" "$g" >> "$ROBOCZY/definicje.txt"; done
done < "$ROBOCZY/galezie.txt"

# --- raport ---------------------------------------------------------------
ZNALEZIONO=0

naglowek() { echo "=============================================================="; echo "$1"; echo "=============================================================="; }

naglowek "1. TEN SAM NUMER DECYZJI W DWOCH GALEZIACH"
if [ -s "$ROBOCZY/decyzje.txt" ]; then
  cut -f1 "$ROBOCZY/decyzje.txt" | sort | uniq -d > "$ROBOCZY/dup-d.txt"
  if [ -s "$ROBOCZY/dup-d.txt" ]; then
    while read -r n; do
      echo "  $n zajmuja:"
      grep -P "^$n\t" "$ROBOCZY/decyzje.txt" | cut -f2 | sort -u | while read -r g; do
        s=$(grep -P "^$g\t" "$ROBOCZY/galezie.txt" | cut -f2)
        ile=$(git grep -c "$n" "$s" -- . 2>/dev/null | awk -F: '{s+=$NF} END {print s+0}')
        printf '    %-40s %s odwolan w kodzie\n' "$g" "$ile"
      done
      echo "    -> numer zostaje tam, gdzie odwolan jest WIECEJ (kryterium kosztu)"
      ZNALEZIONO=$((ZNALEZIONO+1))
    done < "$ROBOCZY/dup-d.txt"
  else echo "  (nic)"; fi
else echo "  (zadna galaz nie dodaje numeru)"; fi
echo

naglowek "2. TA SAMA NAZWA MIGRACJI PRZY ROZNEJ TRESCI  <-- twarda awaria"
if [ -s "$ROBOCZY/migracje.txt" ]; then
  cut -f1 "$ROBOCZY/migracje.txt" | sort | uniq -d > "$ROBOCZY/dup-m.txt"
  if [ -s "$ROBOCZY/dup-m.txt" ]; then
    while read -r f; do
      hasze=$(grep -P "^\Q$f\E\t" "$ROBOCZY/migracje.txt" | cut -f3 | sort -u | wc -l)
      if [ "$hasze" -gt 1 ]; then
        echo "  !! $f — ROZNA TRESC w:"
        grep -P "^\Q$f\E\t" "$ROBOCZY/migracje.txt" | awk -F'\t' '{printf "       %-40s %s\n", $2, substr($3,1,8)}'
        echo "       -> wejscie obu = twarda awaria migracji. Wybrac JEDNA."
        ZNALEZIONO=$((ZNALEZIONO+1))
      else
        echo "  ok $f — ta sama tresc w kilku galeziach (kopia, nie kolizja)"
      fi
    done < "$ROBOCZY/dup-m.txt"
  else echo "  (nic)"; fi
else echo "  (zadna galaz nie dodaje migracji)"; fi
echo

naglowek "3. TA SAMA DEFINICJA DODANA PRZEZ DWIE GALEZIE  <-- ciche zderzenie"
if [ -s "$ROBOCZY/definicje.txt" ]; then
  cut -f1 "$ROBOCZY/definicje.txt" | sort | uniq -d > "$ROBOCZY/dup-f.txt"
  if [ -s "$ROBOCZY/dup-f.txt" ]; then
    while read -r d; do
      ile=$(grep -Pc "^\Q$d\E\t" "$ROBOCZY/definicje.txt")
      echo "  \"$d\" — dodana przez $ile galezi:"
      grep -P "^\Q$d\E\t" "$ROBOCZY/definicje.txt" | cut -f2 | sort -u | sed 's/^/       /'
      ZNALEZIONO=$((ZNALEZIONO+1))
    done < "$ROBOCZY/dup-f.txt"
    echo
    echo "  UWAGA: to jest sygnal, nie wyrok. Dwie galezie moga dodawac te sama"
    echo "  definicje, bo jedna jest przodkiem drugiej — sprawdz --is-ancestor"
    echo "  w OBU kierunkach, zanim uznasz to za kolizje."
  else echo "  (nic)"; fi
else echo "  (nie znalazlem zadnych definicji — sprawdz wzorzec, to podejrzane)"; fi
echo

naglowek "PODSUMOWANIE"
echo "  Zgloszen: $ZNALEZIONO"
echo "  Zbadanych galezi: $ILE"
echo
echo "  Czego ten skrypt NIE sprawdza: konfliktow tresci (od tego jest"
echo "  git merge-tree), zgodnosci testow, ani tego, czy galaz w ogole dziala."
echo "  Sprawdza wylacznie kolizje, ktorych git NIE zglasza przy czystym scaleniu."
