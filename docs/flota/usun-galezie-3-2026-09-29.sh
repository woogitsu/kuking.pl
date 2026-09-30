#!/usr/bin/env bash
# usun-galezie-3.sh — sprzątanie gałęzi na origin (woogitsu/kuking.pl), część 3.
#
# Co robi: usuwa ze zdalnego repozytorium przestarzałe gałęzie, które COŚ wnoszą (scalenie
# z main dałoby różnicę), ale właściciel zdecydował 29.09.2026, że je porzuca. Dlatego przed
# skasowaniem każdej gałęzi skrypt zachowuje ją jako TAG ARCHIWALNY na origin:
#   archiwum/<nazwa gałęzi z "/" zamienionymi na "-">   (np. claude/x -> archiwum/claude-x)
# Tag wskazuje dokładnie na SHA zapisany poniżej, więc da się wrócić (instrukcja na końcu).
#
# Weryfikacja przy uruchomieniu (dla każdej gałęzi): gałąź istnieje na origin i jej SHA jest
# RÓWNY zapisanemu w LISTA (jeśli ktoś coś dopisał — pomijamy). Pomijane są też gałęzie
# chronione (main, paczki), gałęzie z otwartym PR (gdy jest `gh`) i takie, dla których nie
# udało się utworzyć/wypchnąć tagu (wtedy gałęzi NIE kasujemy).
# Lokalnie powstają tylko tagi archiwalne (poza `git fetch --prune`).
#
# Uruchomienie (w swoim klonie repo):
#   bash usun-galezie-3.sh             # podgląd: nic nie tworzy, nie wypycha, nie kasuje
#   bash usun-galezie-3.sh --wykonaj   # tag + push tagów + kasowanie, partiami po 20
#
# Data sporządzenia: 2026-09-29
set -euo pipefail

WYKONAJ=0
[ "${1:-}" = "--wykonaj" ] && WYKONAJ=1

git rev-parse --git-dir >/dev/null 2>&1 || { echo "Uruchom w klonie repo." >&2; exit 1; }
git fetch --prune origin

# "gałąź SHA" — SHA z chwili sporządzenia listy
LISTA=(
  "gpt-pwa-push b71c44300cf8ac41ff422689e655f67d62cde5d9"
  "gpt-openai-granice 7fd9aa853a200ad3430eda3cfaedb55e0d5036c6"
  "gpt-moderacja-ai 33ebfd047273048829a69af89d83a05be4433b2c"
  "gpt-zdjecia-limity 03d19682a68432e70b28c5f06cbd05bcac8c5f68"
  "bramka-startowa 482e68f43382e6a82b4de34b1124e011b95ff249"
  "straznik-format 4a2c03b71dbe138a32e5c17ec4d1f14d798b1617"
  "claude/new-session-zpc41g-02-eksport 2b4813ecb8691c8c45f061a632a342536181fcda"
  "claude/new-session-cylbus 3801ddc1f36e13e2fb7cd2e1b0da8f7cb3d155dd"
  "robota/bazy-stanowisk 9ce50b79e4822532eb3e6a95867e9bc1645f2cee"
  "codex/2065-gallery-alt-negative cc1b84348ea62bf55d3cde0ca7c65756810fb664"
  "codex/2112-nutrition-moderation-negative 53b2a5abe867c998e6e052cff63756c5ba15a573"
  "naprawa/klient-pg18-w-ci 021264c694170b200594fdc6ab532e8f8bd2588c"
  "codex/2059-kontrola-ujemna d89aa61697e71bcd23ede36f561854bfc96ecc71"
  "claude/1040-korelacja-bledu a289ffe848d0d4bfdcb0e14aed941a8a575cb6af"
  "claude/kopia-2130 ab3182474ed2b02dd0e312c387f5ed43f4b07b93"
  "claude/larastan-test-zamiaru-ugotowania 62bfa57835989b8d03f12ebed9ba4f200bee0e09"
  "codex/1991-puste-skladniki-podglad a21582dec88c1aa0280d00252e0e65545a86fa68"
  "claude/1334-przepis-do-wpisu 66a825703460d0de4ce00c6ef089dcb3a66b5956"
  "claude/934-kolejnosc-zdjec-po-bledzie 9b243c3e124019d83445fe344c5f3ca293d27253"
  "claude/naprawa-main-tagi-scalenie-853 6a5a03e295a4f2475fd78a3eb6cf6c14589958b6"
  "codex/1984-porcje-tryb-gotowania 799036178e917664c562efbc8a1e57f645a10689"
  "codex/2014-date-modified-jsonld 77657ba421b0adbfa684d3d55d5d757960385c49"
  "codex/2042-audyt-blednego-2fa f61d869e2cfb42cfc3e9c29d279acff7fb45d79d"
  "codex/2058-zamiar-ugotowania 5df43027f8d2c4bf9c4be3dd47c20e0bd5a26f04"
  "codex/issue-825 e65fa5e05ab7c9d46e84714bddd10c5c7691a3eb"
  "claude/666-liczniki-zdarzen 1b44670e8628a03b0ff15d80e0b69f0ddec04cfc"
  "claude/838-842-843-kontakt 7929af2d85b99ea05a46bb6dbb30f0792b808eeb"
  "claude/880-list-po-wypisaniu 7f73d767de50e00f1bdf8ed1e84c7279422a20dd"
  "claude/888-bezpieczenstwo e79b80d028f8dde78d741c621b34cb29b44ebcc3"
  "claude/998-sprzatanie-spraw-partiami d296ea1871ac4e3880370be931139f9278cd9530"
  "flota/dsa-odwolania d291e4a6bcf830aa25872e5e8aebf89daba99655"
  "flota/zdjecia-formularze 6c5345ab35a24e34afda06b2b47376c3b501c73d"
  "flota/ekran-zeszytu-2209-rozdzielenie 420a512ffdac0adbcaca44e01ba8f47dfda5e9e9"
  "naprawa/775-zakres-usuwania-z-zeszytu 0a4630bec533f4820b137b9c945ad7df6f4357c5"
  "flota/straznik-wersji-changelog a372b53c94fd9aace7f8e177bc948aaa66bb0785"
  "flota/wersja-068-i-bramka fead667a880bdba17f87544046a1b75a4c28ac7c"
  "claude/zglaszajacy-dostaje-odpowiedz 27ce1311985d5538032851dc305b694062f34e5e"
  "claude/laughing-edison-sz4k69 f63a68a4b5fe725a5224646f0b848dcc92106f9b"
  "claude/1387-kreator-krok3 94fb7ed79f525dbf297fe377d133c0b038b2da13"
  "claude/priorytet-w-kolejce-moderacji d989a16dfec6093373c6202ca5a147627d725f2f"
)

CHRONIONE_RE='^(main|gh-pages|production|prod|produkcja|release.*|claude/paczka-.*)$'

OTWARTE=""
if command -v gh >/dev/null 2>&1; then
  OTWARTE="$(gh pr list --state open --limit 500 --json headRefName --jq '.[].headRefName' || true)"
else
  echo "UWAGA: brak polecenia gh — nie sprawdzam otwartych PR-ów w chwili uruchomienia."
fi

DO_USUNIECIA=()   # "gałąź SHA"
POMINIETE=()

pomin() { echo "POMIJAM $1 — $2"; POMINIETE+=("$1"); }
nazwa_tagu() { local n="$1"; echo "archiwum/${n//\//-}"; }

sprawdz() { # $1 = gałąź, $2 = zapisany SHA
  local g="$1" sha="$2" teraz
  if [[ "$g" =~ $CHRONIONE_RE ]]; then pomin "$g" "gałąź chroniona"; return; fi
  if ! git show-ref --verify --quiet "refs/remotes/origin/$g"; then pomin "$g" "nie istnieje na origin"; return; fi
  teraz="$(git rev-parse "refs/remotes/origin/$g")"
  if [ "$teraz" != "$sha" ]; then pomin "$g" "SHA się zmienił (jest $teraz, zapisano $sha) — ktoś coś dopisał"; return; fi
  if [ -n "$OTWARTE" ] && printf '%s\n' "$OTWARTE" | grep -qxF "$g"; then pomin "$g" "ma otwarty PR"; return; fi
  local tag; tag="$(nazwa_tagu "$g")"
  if git show-ref --verify --quiet "refs/tags/$tag" && [ "$(git rev-parse "refs/tags/$tag^{commit}")" != "$sha" ]; then
    pomin "$g" "tag $tag już istnieje i wskazuje inny commit"; return
  fi
  echo "OK $g @ ${sha:0:9} -> tag $tag"
  DO_USUNIECIA+=("$g $sha")
}

for w in "${LISTA[@]}"; do sprawdz "${w% *}" "${w#* }"; done

echo
echo "Do zarchiwizowania i usunięcia: ${#DO_USUNIECIA[@]}, pominięte: ${#POMINIETE[@]}"

USUNIETE=0
BLEDY=0
if [ "$WYKONAJ" -eq 1 ]; then
  i=0
  while [ "$i" -lt "${#DO_USUNIECIA[@]}" ]; do
    partia=("${DO_USUNIECIA[@]:i:20}")
    tagi=(); galezie=()
    for w in "${partia[@]}"; do
      g="${w% *}"; sha="${w#* }"; tag="$(nazwa_tagu "$g")"
      git tag "$tag" "$sha" 2>/dev/null || git show-ref --verify --quiet "refs/tags/$tag"
      tagi+=("refs/tags/$tag"); galezie+=("$g")
    done
    echo "Wypycham tagi archiwalne (${#tagi[@]})"
    if ! git push origin "${tagi[@]}"; then
      echo "BŁĄD: nie wypchnięto tagów tej partii — gałęzi z niej NIE kasuję: ${galezie[*]}" >&2
      BLEDY=$((BLEDY + ${#galezie[@]})); i=$((i + 20)); continue
    fi
    # Upewnij się, że tagi są na origin, zanim cokolwiek skasujemy.
    ok=()
    for idx in "${!galezie[@]}"; do
      g="${galezie[$idx]}"; sha="${partia[$idx]#* }"; tag="$(nazwa_tagu "$g")"
      if [ "$(git ls-remote origin "refs/tags/$tag^{}" "refs/tags/$tag" | awk '{print $1}' | tail -1)" = "$sha" ]; then
        ok+=("$g")
      else
        echo "BŁĄD: tagu $tag nie widać na origin — gałęzi $g NIE kasuję." >&2; BLEDY=$((BLEDY + 1))
      fi
    done
    if [ "${#ok[@]}" -gt 0 ]; then
      echo "Usuwam partię (${#ok[@]}): ${ok[*]}"
      git push origin --delete "${ok[@]}"
      USUNIETE=$((USUNIETE + ${#ok[@]}))
    fi
    i=$((i + 20))
  done
else
  echo "Tryb podglądu — nic nie utworzono, nie wypchnięto ani nie usunięto. Aby wykonać: bash usun-galezie-3.sh --wykonaj"
fi

echo
echo "PODSUMOWANIE: usunięte: $USUNIETE, pominięte: ${#POMINIETE[@]}, błędy tagów: $BLEDY, kandydaci: ${#DO_USUNIECIA[@]}"
cat <<'KONIEC'

JAK PRZYWRÓCIĆ GAŁĄŹ Z TAGU (przykład dla claude/x-y, tag archiwum/claude-x-y):
  git fetch origin 'refs/tags/archiwum/*:refs/tags/archiwum/*'
  git push origin 'refs/tags/archiwum/claude-x-y^{commit}:refs/heads/claude/x-y'
  # albo lokalnie: git switch -c claude/x-y archiwum/claude-x-y
Lista tagów: git ls-remote --tags origin 'archiwum/*'
KONIEC
