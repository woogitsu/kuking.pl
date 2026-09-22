#!/usr/bin/env bash
# odswiez-spis.sh — generator SPIS_TRESCI.md dla floty Kuking.pl.
#
# Cel: SPIS_TRESCI.md ma zawsze mówić prawdę o aktualnym stanie dysku i gita,
# nigdy pisany ręcznie. Ten skrypt go w całości ODTWARZA (nadpisuje), nigdy
# nie dopisuje. Uruchomienie: bash odswiez-spis.sh
#
# Zasady bezpieczeństwa tego skryptu (na podstawie dzisiejszych strat czasu):
#   - BEZ `set -e` i BEZ `set -o pipefail` — jedna brakująca rzecz (katalog,
#     gałąź, gh) nie może wywalić całego spisu. Brak danych -> "nieustalone".
#   - Nigdy `printf '%s' "$x" | grep -q "$y"` — pod pipefail SIGPIPE potrafi
#     dać wynik FAŁSZYWY mimo trafienia. Zamiast tego używamy here-stringów:
#     `grep -q "$y" <<<"$x"`.
#   - Nigdy `n=$(grep -c ... ) || echo 0` — `grep -c` przy zerze trafień i tak
#     drukuje "0" i zwraca kod 1, więc `|| echo 0` dokleja drugie "0" i psuje
#     liczbę. Liczby zawsze czyścimy przez `tr -cd '0-9'` i podstawiamy 0, gdy
#     wynik jest pusty.

set -u

# --- Ścieżki, wykrywane względem położenia tego skryptu, nie na sztywno ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"                       # .../kuking-flota
WSPOLNE="$ROOT/_wspolne"
PROMPTY="$ROOT/_prompty"
SKRZYNKA="$WSPOLNE/skrzynka"
OUT="$WSPOLNE/SPIS_TRESCI.md"

# Kanoniczne repozytorium: siostrzany katalog Codex/kuking.pl obok kuking-flota.
CANON=""
for kandydat in "$ROOT/../Codex/kuking.pl" "$ROOT/../../Codex/kuking.pl"; do
  if [ -d "$kandydat/.git" ]; then
    CANON="$(cd "$kandydat" && pwd)"
    break
  fi
done

# Kolejka pchania (WSL) — dostępna tylko, gdy skrypt biegnie wewnątrz WSL.
PUSH_DIR=""
if [ -d "/home/mateusz/flota" ]; then
  PUSH_DIR="/home/mateusz/flota"
fi

# gh CLI — najpierw PATH, potem znana ścieżka instalacji Windows.
GH=""
if command -v gh >/dev/null 2>&1; then
  GH="gh"
elif [ -x "/mnt/c/Program Files/GitHub CLI/gh.exe" ]; then
  GH="/mnt/c/Program Files/GitHub CLI/gh.exe"
elif [ -x "/c/Program Files/GitHub CLI/gh.exe" ]; then
  GH="/c/Program Files/GitHub CLI/gh.exe"
fi

# --- Pomocnicze: liczba zawsze liczbą, nigdy pustym stringiem ani śmieciem ---
czysta_liczba() {
  local surowe="${1:-}"
  surowe="$(tr -cd '0-9' <<<"$surowe")"
  if [ -z "$surowe" ]; then
    echo 0
  else
    echo "$surowe"
  fi
}

# Pierwszy nagłówek "# ..." lub "## ..." w pliku — jako jednozdaniowy opis.
pierwszy_naglowek() {
  local plik="$1"
  local linia
  linia="$(grep -m1 -E '^#{1,3} ' "$plik" 2>/dev/null || true)"
  if [ -z "$linia" ]; then
    echo "(brak nagłówka)"
  else
    sed -E 's/^#{1,3} +//' <<<"$linia"
  fi
}

rozmiar_kb() {
  local plik="$1"
  if [ -f "$plik" ]; then
    local b
    b="$(wc -c < "$plik" 2>/dev/null || echo 0)"
    b="$(czysta_liczba "$b")"
    echo "$(( (b + 1023) / 1024 )) KB"
  else
    echo "brak pliku"
  fi
}

TERAZ="$(date '+%Y-%m-%d %H:%M:%S %Z' 2>/dev/null || date)"

# ============================================================================
{
echo "# Spis treści floty Kuking.pl"
echo
echo "**Odświeżono automatycznie: ${TERAZ}**"
echo
echo "Ten plik jest w całości generowany przez \`_wspolne/odswiez-spis.sh\`."
echo "Nie edytuj go ręcznie — zostanie nadpisany przy następnym uruchomieniu."
echo "Jeśli jakaś sekcja mówi »nieustalone«, oznacza to, że źródło danych"
echo "(katalog, gałąź, \`gh\`) było niedostępne w chwili generowania, a nie że"
echo "dana rzecz nie istnieje."
echo
echo "---"
echo

# --- 1. Dokumenty stanu ---------------------------------------------------
echo "## Dokumenty stanu (STAN_SESJI)"
echo
echo "| Plik | Rozmiar (_wspolne) | Rozmiar (_prompty) | O czym (pierwszy nagłówek) |"
echo "|---|---|---|---|"
DOKI_STANU="STAN_SESJI.md STAN_SESJI_CZESC2.md STAN_SESJI_CZESC3.md STAN_SESJI_CZESC4.md STAN_SESJI_CZESC5.md STAN_SESJI_CZESC6.md STAN_SESJI_CZESC7.md STAN_SESJI_CZESC8.md"
for f in $DOKI_STANU; do
  wp="$WSPOLNE/$f"
  pp="$PROMPTY/$f"
  if [ -f "$wp" ]; then
    opis="$(pierwszy_naglowek "$wp")"
    rw="$(rozmiar_kb "$wp")"
  elif [ -f "$pp" ]; then
    opis="$(pierwszy_naglowek "$pp")"
    rw="brak"
  else
    opis="(plik nie istnieje w żadnym z dwóch miejsc)"
    rw="brak"
  fi
  rp="$(rozmiar_kb "$pp")"
  echo "| $f | $rw | $rp | $opis |"
done
echo
if [ -f "$WSPOLNE/STAN_SESJI.md" ] && [ -f "$PROMPTY/STAN_SESJI.md" ]; then
  rw_b="$(wc -c < "$WSPOLNE/STAN_SESJI.md" 2>/dev/null || echo 0)"
  rp_b="$(wc -c < "$PROMPTY/STAN_SESJI.md" 2>/dev/null || echo 0)"
  if [ "$rw_b" != "$rp_b" ]; then
    echo "> UWAGA: \`_wspolne/STAN_SESJI.md\` i \`_prompty/STAN_SESJI.md\` mają różny rozmiar (${rw_b} B vs ${rp_b} B) — to dwie rozjeżdżające się kopie, nie jeden plik."
    echo
  fi
fi

# --- 2. Pozostałe dokumenty w _wspolne i _prompty -------------------------
echo "## Pozostałe dokumenty (.md)"
echo
echo "### W _wspolne"
echo
echo "| Plik | Rozmiar | O czym (pierwszy nagłówek) |"
echo "|---|---|---|"
if [ -d "$WSPOLNE" ]; then
  found_any=0
  while IFS= read -r plik; do
    nazwa="$(basename "$plik")"
    case "$nazwa" in
      STAN_SESJI*.md|SPIS_TRESCI.md) continue ;;
    esac
    found_any=1
    echo "| $nazwa | $(rozmiar_kb "$plik") | $(pierwszy_naglowek "$plik") |"
  done < <(find "$WSPOLNE" -maxdepth 1 -iname '*.md' | sort)
  [ "$found_any" -eq 0 ] && echo "| (brak) | - | - |"
else
  echo "| _wspolne nieustalone | - | - |"
fi
echo
echo "### W _prompty (dokumenty spoza STAN_SESJI i zleceń numerowanych)"
echo
echo "| Plik | Rozmiar | O czym (pierwszy nagłówek) |"
echo "|---|---|---|"
if [ -d "$PROMPTY" ]; then
  found_any=0
  while IFS= read -r plik; do
    nazwa="$(basename "$plik")"
    case "$nazwa" in
      STAN_SESJI*.md) continue ;;
    esac
    found_any=1
    echo "| $nazwa | $(rozmiar_kb "$plik") | $(pierwszy_naglowek "$plik") |"
  done < <(find "$PROMPTY" -maxdepth 1 -iname '*.md' | sort)
  [ "$found_any" -eq 0 ] && echo "| (brak) | - | - |"
else
  echo "| _prompty nieustalone | - | - |"
fi
echo

# --- 3. Zlecenia w _prompty (pliki numerowane NN-nazwa.txt) ---------------
echo "## Zlecenia w _prompty (pliki numerowane)"
echo
echo "| Nr | Stanowisko | Rozmiar |"
echo "|---|---|---|"
if [ -d "$PROMPTY" ]; then
  liczba_zlecen=0
  while IFS= read -r plik; do
    nazwa="$(basename "$plik" .txt)"
    nr="${nazwa%%-*}"
    stanowisko="${nazwa#*-}"
    echo "| $nr | $stanowisko | $(rozmiar_kb "$plik") |"
    liczba_zlecen=$((liczba_zlecen + 1))
  done < <(find "$PROMPTY" -maxdepth 1 -type f -regextype posix-extended -iregex '.*/[0-9]+-.*\.txt' | sort -t/ -k1)
  if [ "$liczba_zlecen" -eq 0 ]; then
    echo "| - | (brak plików numerowanych) | - |"
  fi
else
  echo "| - | _prompty nieustalone | - |"
fi
echo

# --- 4. Skrzynka: które stanowiska mają zlecenia / meldunki / uwagi -------
echo "## Skrzynka — stan per stanowisko"
echo
echo "| Stanowisko | Zlecenie | Meldunek | Uwaga | Ostatnio pisało |"
echo "|---|---|---|---|---|"
if [ -d "$SKRZYNKA" ]; then
  STANOWISKA="$(
    { find "$SKRZYNKA/zlecenia" -maxdepth 1 -type f -iname '*.md' 2>/dev/null;
      find "$SKRZYNKA/meldunki" -maxdepth 1 -type f -iname '*.md' 2>/dev/null;
      find "$SKRZYNKA/uwagi" -maxdepth 1 -type f -iname '*.md' 2>/dev/null; } \
    | xargs -n1 basename 2>/dev/null | sed 's/\.md$//' | sort -u
  )"
  if [ -z "$STANOWISKA" ]; then
    echo "| (brak wpisów w skrzynce) | - | - | - | - |"
  else
    while IFS= read -r st; do
      [ -z "$st" ] && continue
      zl="brak"; me="brak"; uw="brak"
      [ -f "$SKRZYNKA/zlecenia/$st.md" ] && zl="jest"
      [ -f "$SKRZYNKA/meldunki/$st.md" ] && me="jest"
      [ -f "$SKRZYNKA/uwagi/$st.md" ] && uw="jest"
      ostatnio="nieustalone"
      najnowszy=""
      for kandydat in "$SKRZYNKA/zlecenia/$st.md" "$SKRZYNKA/meldunki/$st.md" "$SKRZYNKA/uwagi/$st.md"; do
        if [ -f "$kandydat" ]; then
          if [ -z "$najnowszy" ] || [ "$kandydat" -nt "$najnowszy" ]; then
            najnowszy="$kandydat"
          fi
        fi
      done
      if [ -n "$najnowszy" ]; then
        ostatnio="$(date -r "$najnowszy" '+%Y-%m-%d %H:%M' 2>/dev/null || echo nieustalone)"
      fi
      echo "| $st | $zl | $me | $uw | $ostatnio |"
    done <<<"$STANOWISKA"
  fi
else
  echo "| - | - | - | - | skrzynka nieustalona |"
fi
echo

# --- 5. Gałęzie -------------------------------------------------------------
echo "## Gałęzie"
echo
if [ -n "$CANON" ]; then
  LOKALNE="$(git -C "$CANON" branch --format='%(refname:short)' 2>/dev/null | wc -l)"
  ORIGIN="$(git -C "$CANON" branch -r --format='%(refname:short)' 2>/dev/null | wc -l)"
  LOKALNE="$(czysta_liczba "$LOKALNE")"
  ORIGIN="$(czysta_liczba "$ORIGIN")"
else
  LOKALNE="nieustalone (brak kanonicznego repo Codex/kuking.pl)"
  ORIGIN="nieustalone"
fi
echo "- Gałęzie lokalne (kanoniczne repo, może być nieodświeżone bez \`fetch\`): **$LOKALNE**"
echo "- Gałęzie na origin wg lokalnego gita (może być nieodświeżone bez \`fetch\`): **$ORIGIN**"
if [ -n "$GH" ] && [ -n "$CANON" ]; then
  ORIGIN_GH="$(czysta_liczba "$("$GH" api "repos/$(git -C "$CANON" config --get remote.origin.url 2>/dev/null | sed -E 's#.*github.com[:/]##; s#\.git$##')/branches" --paginate -q '.[].name' 2>/dev/null | wc -l)")"
  echo "- Gałęzie na GitHubie wg \`gh api .../branches\` (źródło prawdy, nie wymaga lokalnego fetch): **$ORIGIN_GH**"
else
  echo "- Gałęzie na GitHubie wg \`gh api .../branches\`: nieustalone (gh CLI niedostępny albo brak kanonicznego repozytorium)"
fi
if [ -n "$PUSH_DIR" ]; then
  DO_PCHNIECIA="nieustalone"
  PCHNIETE="nieustalone"
  NIEUDANE="nieustalone"
  NIEODZYSKANE="nieustalone"
  WSTRZYMANE="nieustalone"
  [ -f "$PUSH_DIR/do-pchniecia.txt" ] && DO_PCHNIECIA="$(czysta_liczba "$(wc -l < "$PUSH_DIR/do-pchniecia.txt" 2>/dev/null)")"
  [ -f "$PUSH_DIR/pchniete.txt" ] && PCHNIETE="$(czysta_liczba "$(wc -l < "$PUSH_DIR/pchniete.txt" 2>/dev/null)")"
  [ -f "$PUSH_DIR/nieudane.txt" ] && NIEUDANE="$(czysta_liczba "$(wc -l < "$PUSH_DIR/nieudane.txt" 2>/dev/null)")"
  [ -f "$PUSH_DIR/nieodzyskane.txt" ] && NIEODZYSKANE="$(czysta_liczba "$(wc -l < "$PUSH_DIR/nieodzyskane.txt" 2>/dev/null)")"
  [ -f "$PUSH_DIR/wstrzymane.txt" ] && WSTRZYMANE="$(czysta_liczba "$(wc -l < "$PUSH_DIR/wstrzymane.txt" 2>/dev/null)")"
  echo "- W kolejce do pchnięcia (\`do-pchniecia.txt\`): **$DO_PCHNIECIA**"
  echo "- Już pchnięte (\`pchniete.txt\`): **$PCHNIETE**"
  echo "- Nieudane próby (\`nieudane.txt\`): **$NIEUDANE**"
  echo "- Nieodzyskane po awarii (\`nieodzyskane.txt\`): **$NIEODZYSKANE**"
  echo "- Wstrzymane, czekają na decyzję właściciela (\`wstrzymane.txt\`): **$WSTRZYMANE**"
else
  echo "- Kolejka pchania (WSL /home/mateusz/flota): nieustalone (skrypt nie biegnie w WSL albo katalog zniknął)"
fi
echo

# --- 5b. Runtime WSL (/home/mateusz/flota) ---------------------------------
echo "## Runtime WSL (\`/home/mateusz/flota\`)"
echo
if [ -n "$PUSH_DIR" ]; then
  LICZBA_RUN="$(czysta_liczba "$(find "$PUSH_DIR" -maxdepth 1 -type d -iname '*-run' 2>/dev/null | wc -l)")"
  ROZMIAR_RUN="$(du -sh --apparent-size "$PUSH_DIR" 2>/dev/null | cut -f1)"
  [ -z "$ROZMIAR_RUN" ] && ROZMIAR_RUN="nieustalone"
  echo "- Katalogów \`*-run\`: **$LICZBA_RUN**"
  echo "- Zajętość \`$PUSH_DIR\` (\`du -sh --apparent-size\`): **$ROZMIAR_RUN**"
else
  echo "nieustalone (skrypt nie biegnie w WSL albo katalog zniknął)."
fi
echo

# --- 5c. Narzędzia we _wspolne (pliki .sh) ---------------------------------
echo "## Narzędzia we \`_wspolne\` (pliki .sh)"
echo
echo "| Plik | Rozmiar |"
echo "|---|---|"
if [ -d "$WSPOLNE" ]; then
  liczba_narzedzi=0
  while IFS= read -r plik; do
    nazwa="$(basename "$plik")"
    echo "| $nazwa | $(rozmiar_kb "$plik") |"
    liczba_narzedzi=$((liczba_narzedzi + 1))
  done < <(find "$WSPOLNE" -maxdepth 1 -type f -iname '*.sh' | sort)
  [ "$liczba_narzedzi" -eq 0 ] && echo "| (brak) | - |"
else
  echo "| _wspolne nieustalone | - |"
fi
echo

# --- 5d. Runnery obcych projektów na tej maszynie (systemd) ----------------
echo "## Runnery obcych projektów (systemd, \`disabled\` vs \`enabled\`)"
echo
if command -v systemctl >/dev/null 2>&1; then
  RUNNERY="$(systemctl list-unit-files --all --no-pager --plain 2>/dev/null | grep -iE 'lockstate|osadale|metro' || true)"
  if [ -z "$RUNNERY" ]; then
    echo "nieustalone (brak jednostek pasujących do lockstate/osadale/metro albo \`systemctl\` nie odpowiedział)."
  else
    echo '```'
    echo "$RUNNERY"
    echo '```'
  fi
else
  echo "nieustalone (\`systemctl\` niedostępny — skrypt prawdopodobnie nie biegnie w WSL/Linuksie)."
fi
echo

# --- 5e. Stan main -----------------------------------------------------------
echo "## Stan \`main\` w kanonicznym repozytorium"
echo
if [ -n "$CANON" ]; then
  MAIN_SHA="$(git -C "$CANON" rev-parse --short HEAD 2>/dev/null || true)"
  [ -z "$MAIN_SHA" ] && MAIN_SHA="nieustalone"
  echo "- HEAD kanonicznego repo (\`$CANON\`): **$MAIN_SHA**"
else
  echo "nieustalone (brak kanonicznego repo Codex/kuking.pl)."
fi
if [ -n "$GH" ] && [ -n "$CANON" ]; then
  MERGED_JSON="$("$GH" pr list --state merged --limit 30 --json number,mergedAt -R "$(git -C "$CANON" config --get remote.origin.url 2>/dev/null | sed -E 's#.*github.com[:/]##; s#\.git$##')" 2>/dev/null)"
  if [ -n "$MERGED_JSON" ] && [ "$MERGED_JSON" != "null" ]; then
    echo "- Scalone PR-y widoczne przez \`gh pr list --state merged\` (ostatnie 30, sprawdź \`mergedAt\` ręcznie które \"z nocy\"): **$(czysta_liczba "$(grep -o '"number":' <<<"$MERGED_JSON" | wc -l)")**"
  else
    echo "- Scalone PR-y: nieustalone (gh nie zwrócił danych)."
  fi
fi
echo

# --- 6. Otwarte PR-y ---------------------------------------------------------
echo "## Otwarte pull requesty"
echo
if [ -n "$GH" ] && [ -n "$CANON" ]; then
  PR_JSON="$("$GH" pr list --state open --limit 200 --json number,title,headRefName -R "$(git -C "$CANON" config --get remote.origin.url 2>/dev/null | sed -E 's#.*github.com[:/]##; s#\.git$##')" 2>/dev/null)"
  if [ -z "$PR_JSON" ] || [ "$PR_JSON" = "null" ]; then
    echo "nieustalone (gh nie zwrócił danych — sprawdź uwierzytelnienie)."
  else
    echo "| Nr | Gałąź | Tytuł |"
    echo "|---|---|---|"
    # gh sortuje klucze JSON alfabetycznie: headRefName, number, title.
    echo "$PR_JSON" | grep -oE '"headRefName":"[^"]*"|"number":[0-9]+|"title":"[^"]*"' \
      | paste -d'|' - - - \
      | while IFS='|' read -r a b c; do
          gal="$(sed -E 's/"headRefName":"//; s/"$//' <<<"$a")"
          nr="$(sed -E 's/"number"://' <<<"$b")"
          tyt="$(sed -E 's/"title":"//; s/"$//' <<<"$c")"
          echo "| $nr | $gal | $tyt |"
        done
    LICZBA_PR="$(czysta_liczba "$(grep -o '"number":' <<<"$PR_JSON" | wc -l)")"
    echo
    echo "Razem otwartych PR: **$LICZBA_PR**"
  fi
else
  echo "nieustalone (gh CLI niedostępny albo brak kanonicznego repozytorium)."
fi
echo

echo "---"
echo
echo "_Koniec spisu. Wygenerowano przez \`odswiez-spis.sh\` o ${TERAZ}._"
} > "$OUT.tmp"

mv -f "$OUT.tmp" "$OUT"
echo "Zapisano: $OUT"
