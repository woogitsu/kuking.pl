#!/usr/bin/env bash
# usun-galezie.sh — sprzątanie gałęzi na origin (woogitsu/kuking.pl).
#
# Co robi: usuwa ze zdalnego repozytorium gałęzie, które są bezpieczne do skasowania:
#   A = w pełni scalone do origin/main,
#   B = cała treść jest już w main (squash/cherry-pick; `git cherry` bez linii „+”).
# Przed skasowaniem KAŻDEJ gałęzi weryfikacja jest robiona ponownie; gałęzie, które nie
# przejdą weryfikacji, nie istnieją albo mają otwarty PR (gdy jest `gh`), są pomijane.
# Lokalnie nic nie jest usuwane (poza `git fetch --prune`). Gałęzie C (niescalone,
# z unikalną treścią) NIE są tu ujęte — to decyzja właściciela.
#
# Uruchomienie (w swoim klonie repo):
#   bash usun-galezie.sh             # podgląd, nic nie kasuje
#   bash usun-galezie.sh --wykonaj   # kasowanie partiami po 20
#
# Data sporządzenia: 2026-09-29
set -euo pipefail

WYKONAJ=0
[ "${1:-}" = "--wykonaj" ] && WYKONAJ=1

git rev-parse --git-dir >/dev/null 2>&1 || { echo "Uruchom w klonie repo." >&2; exit 1; }
git fetch --prune origin

# Kategoria A — scalone do main
LISTA_A=(
  "claude/1000-fonty-podzbior"
  "claude/1000-pomiar"
  "claude/1046-sesja-testy-wspolbiezne"
  "claude/1280-lastmod-przepisu"
  "claude/1306-caddy-zaufane-proxy"
  "claude/1310-1326-warianty-zdjec"
  "claude/1387-autozapis-kreatora"
  "claude/1387-mapowanie-publikacji"
  "claude/1387-markup-krokow"
  "claude/1387-markup-podgladu"
  "claude/1387-nawigacja-kreatora"
  "claude/1387-pola-w-formularzu"
  "claude/1387-przepis-form"
  "claude/1387-stan-kreatora"
  "claude/1387-walidacja-krokow"
  "claude/1387-zdjecia-kreatora"
  "claude/1687-domkniecie"
  "claude/1687-etap2"
  "claude/1687-etap4"
  "claude/1687-etap5"
  "claude/1687-etap6"
  "claude/1687-etap7"
  "claude/1731-etap5-na-f"
  "claude/1731-phpstan-poziom-2"
  "claude/1731-phpstan-poziom-4-etap1"
  "claude/1731-phpstan-poziom-4-etap2"
  "claude/1731-phpstan-poziom-4-etap3"
  "claude/1731-phpstan-poziom-4-etap4"
  "claude/1731-phpstan-poziom-4-etap5"
  "claude/1751-forma-decyzja"
  "claude/1751-forma-zwracania"
  "claude/1752-forma-ustawienie"
  "claude/1753-forma-teksty"
  "claude/1816-polityka-zbiorcza"
  "claude/1818-protokol-badania"
  "claude/1860-luki-po-zamknietych"
  "claude/1932-wersja-po-healthchecku"
  "claude/1952-odkryj-limit"
  "claude/1958-spizarnia-limit-dwa-polaczenia"
  "claude/1969-kolizje-rdzeni"
  "claude/1985-import-etap2"
  "claude/1985-import-etap3"
  "claude/1985-podglad-paczki-eksportu"
  "claude/1991-puste-skladniki-podglad"
  "claude/1996-kalorie-jsonld"
  "claude/1997-zakresy-czasu"
  "claude/2000-udostepnianie-zeszytu"
  "claude/2009-dependabot-major"
  "claude/2013-budzet-miesiac"
  "claude/2014-date-modified-jsonld"
  "claude/2016-sync-gotowania"
  "claude/2017-wykonanie-wyscig-dostepu"
  "claude/2024-historia-wersji"
  "claude/2025-brama-ci"
  "claude/2028-zapis-po-rejestracji"
  "claude/2030-zeszyty-koszt"
  "claude/2031-komunikat-zgody"
  "claude/2031-zgoda-zrodla-ai"
  "claude/2037-planer-dodaj-do-dnia"
  "claude/2038-wymazanie-po-odtworzeniu"
  "claude/2042-audyt-blednego-2fa"
  "claude/2044-migracje-przed-workerem"
  "claude/2049-dmarc-rua"
  "claude/2050-zdjecia-livewire"
  "claude/2057-2fa-kody-wyscig"
  "claude/2060-pusty-stan-powiadomien"
  "claude/2068-zeszyty-po-skladnikach"
  "claude/2070-szukaj-w-ugotowanych"
  "claude/2071-wykonanie-kto-zobaczy"
  "claude/2073-eksport-sprzatanie-wyscig"
  "claude/2083-sezonowosc-docs"
  "claude/2086-zamek-rozstrzygniecia"
  "claude/2130-import-uciete-pliki"
  "claude/2130-wersja-slownika"
  "claude/2130-znacznik-slownika-odzywczego"
  "claude/2149-cykl-modulow"
  "claude/2149-cykl-modulow-etap2"
  "claude/2149-cykl-modulow-etap3"
  "claude/2154-straznik-decyzji"
  "claude/2178-livewire-tmp-reszta"
  "claude/2189-publikacja-po-sankcji"
  "claude/2190-usuwanie-komentarza-po-sankcji"
  "claude/2199-audyt-2fa-api"
  "claude/2205-wycofanie-udzialu-powiadomienia"
  "claude/27-pomiar-planera"
  "claude/28-import-pdf-etap2"
  "claude/28-mikrodane"
  "claude/35-test-kryterium"
  "claude/372-jsonld-odpowiedzi"
  "claude/492-metryki-marka"
  "claude/581-panel-moderacji-etap"
  "claude/581-panel-moderacji-marka"
  "claude/599-wolna-strona-glowna"
  "claude/605-mixed-load-nasycenie"
  "claude/611-ci-etap4"
  "claude/611-ci-etap5"
  "claude/611-ci-etap6"
  "claude/611-ci-etap7"
  "claude/611-ci-etap8"
  "claude/611-uproszczenie-ci"
  "claude/611-uproszczenie-ci-etap3"
  "claude/617-dr-zdjec"
  "claude/684-wyglad-mysz"
  "claude/713-dlug-weryfikacyjny"
  "claude/841-wyszukiwanie-wiadomosci"
  "claude/870-wyszukiwanie-pytan"
  "claude/957-zadanie-przegladarka"
  "claude/970-2fa-zaproszenia"
  "claude/970-domena-bez-http"
  "claude/970-domena-bez-http-2"
  "claude/970-kontroler"
  "claude/970-krok4"
  "claude/970-krok5"
  "claude/970-krok6"
  "claude/970-krok7"
  "claude/970-onboarding"
  "claude/970-postcontroller"
  "claude/970-profil"
  "claude/970-transakcje"
  "claude/970-ugotowalem"
  "claude/970-ugotowalem-na-g"
  "claude/970-zgloszenia"
  "claude/988-komunikat-odmowy"
  "claude/audyt-a-potwierdzenie-wyroznienia"
  "claude/audyt-w-transakcji-g7"
  "claude/drobne-po-recenzji"
  "claude/dwa-zeszyt-decyzja-request"
  "claude/g3-eksport-po-wymazaniu"
  "claude/health-kontrakt"
  "claude/kopia-1533"
  "claude/kopia-1567"
  "claude/kopia-1596"
  "claude/kopia-1603"
  "claude/kopia-1608"
  "claude/kopia-1621"
  "claude/kopia-1627"
  "claude/kopia-1629"
  "claude/kopia-1653"
  "claude/kopia-1710"
  "claude/kopia-1711"
  "claude/kopia-1725"
  "claude/kopia-1771"
  "claude/kopia-1780"
  "claude/kopia-1804"
  "claude/kopia-1827"
  "claude/kopia-1849"
  "claude/kopia-1872"
  "claude/kopia-1879"
  "claude/kopia-2013"
  "claude/kopia-2014"
  "claude/kopia-2031"
  "claude/kopia-2037"
  "claude/kopia-2068"
  "claude/kopia-2077"
  "claude/kopia-2148"
  "claude/kopia-2170"
  "claude/kroki-wlasciciela-2909"
  "claude/pakiet-e-5-formularz-dsa"
  "claude/pakiet-e-6-nazwy-pol"
  "claude/railway-pro-wykorzystanie"
  "claude/raporty-audytu-2509"
  "claude/seo-1032-964-1280"
  "claude/tagsuggester-normalizacja"
  "claude/triaz-28-30-602-614"
  "claude/v2-import-ocr-wip"
  "claude/v2-import-url-wip"
  "claude/v2-odblokowanie"
  "claude/v2-wartosci-odzywcze-wip"
  "claude/wydanie-075"
  "claude/wydanie-076"
  "codex/2052-kontrola-ujemna"
  "codex/2130-kontrola-ujemna"
  "codex/2178-livewire-tmp-cleanup"
  "codex/integracja-nastepna-20260928"
  "codex/integracja-pilot-2075"
  "codex/integracja-po-073-20260928"
  "codex/integracja-po-074-20260928"
  "flota/1376-2fa-wymaga-hasla"
  "flota/1408-moderator-wlasna-sprawa"
  "flota/lokalna-moderacja-niepubliczne"
  "naprawa/minutnik-poprzedniego-kroku"
)

# Kategoria B — treść już w main (bez scalenia)
LISTA_B=(
  "claude/1806-regula-doboru-tresci"
  "claude/1807-rotacja-autorow-odkrywanie"
  "claude/2064-ocr-ponowienie-wyscig"
  "claude/audyt-raporty-a1"
  "claude/audyt-raporty-a2"
  "claude/audyt-raporty-a3"
  "claude/audyt-raporty-a5"
  "claude/audyt-raporty-b1"
  "claude/audyt-raporty-b10"
  "claude/audyt-raporty-b2"
  "claude/audyt-raporty-b3"
  "claude/audyt-raporty-b4"
  "claude/audyt-raporty-b5"
  "claude/audyt-raporty-b6"
  "claude/audyt-raporty-b7"
  "claude/audyt-raporty-b8"
  "claude/audyt-raporty-b9"
  "claude/kopia-1997"
  "claude/new-session-zpc41g-04-monitoring"
  "claude/nieograniczone-get-g10"
  "fix/m4-przeplyw-pomiar"
  "jedna-droga"
  "naprawa/jedna-regula-nazw-baz"
)

CHRONIONE_RE='^(main|gh-pages|production|prod|produkcja|release.*)$'

OTWARTE=""
if command -v gh >/dev/null 2>&1; then
  OTWARTE="$(gh pr list --state open --limit 500 --json headRefName --jq '.[].headRefName' || true)"
else
  echo "UWAGA: brak polecenia gh — nie sprawdzam otwartych PR-ów w chwili uruchomienia (lista była filtrowana z góry)."
fi

DO_USUNIECIA=()
POMINIETE=()

pomin() { echo "POMIJAM $1 — $2"; POMINIETE+=("$1"); }

sprawdz() { # $1 = A|B, $2 = gałąź
  local typ="$1" g="$2"
  if [[ "$g" =~ $CHRONIONE_RE ]]; then pomin "$g" "gałąź chroniona"; return; fi
  if ! git show-ref --verify --quiet "refs/remotes/origin/$g"; then pomin "$g" "nie istnieje na origin"; return; fi
  if [ -n "$OTWARTE" ] && printf '%s\n' "$OTWARTE" | grep -qxF "$g"; then pomin "$g" "ma otwarty PR"; return; fi
  if [ "$typ" = A ]; then
    if ! git merge-base --is-ancestor "origin/$g" origin/main; then pomin "$g" "nie jest scalona do main"; return; fi
  else
    if git cherry origin/main "origin/$g" | grep -q '^+'; then pomin "$g" "ma commity bez odpowiednika w main"; return; fi
  fi
  echo "OK [$typ] $g"
  DO_USUNIECIA+=("$g")
}

for g in "${LISTA_A[@]}"; do sprawdz A "$g"; done
for g in "${LISTA_B[@]}"; do sprawdz B "$g"; done

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
  echo "Tryb podglądu — nic nie usunięto. Aby skasować: bash usun-galezie.sh --wykonaj"
fi

echo
echo "PODSUMOWANIE: usunięte: $USUNIETE, pominięte: ${#POMINIETE[@]}, kandydaci: ${#DO_USUNIECIA[@]}"
