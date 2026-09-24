#!/usr/bin/env bash
# =============================================================================
#  Kontrola czekania na środowisko preview (scripts/czekaj-na-preview.sh)
# =============================================================================
#
#  Bez sieci: funkcja `gh` niżej zastępuje każde `gh api` w bibliotece
#  i odpowiada według zmiennej SCENARIUSZ oraz numeru wywołania. Kontrola
#  DODATNIA (queued/in_progress -> success przechodzi) i ujemne (każda
#  fałszywa gotowość i każdy cichy brak preview z issue #1389 oblewa).
#
#  Uruchomienie:  bash tests/skrypty/kontrola-czekania-preview.sh
# =============================================================================

set -uo pipefail

KORZEN="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../../scripts/czekaj-na-preview.sh
source "$KORZEN/scripts/czekaj-na-preview.sh"

CZERWONY='\033[0;31m'; ZIELONY='\033[0;32m'; RESET='\033[0m'
zdane=0; oblane=0

SHA=0123456789abcdef0123456789abcdef01234567
INNY=fedcba9876543210fedcba9876543210fedcba98
REPO=woogitsu/kuking.pl

# Licznik zapytań o statusy w pliku, bo `$(...)` odpala funkcję w podpowłoce.
LICZNIK="$(mktemp -t czekaj-preview-licznik.XXXXXX)"
trap 'rm -f "$LICZNIK"' EXIT

json_lub_null() { if [ -n "$1" ]; then printf '"%s"' "$1"; else printf 'null'; fi; }

deployment() { # <id> <sha> <środowisko> [web_url] [environment_url]
    printf '{"id":%s,"sha":"%s","environment":"%s","payload":{%s},"environment_url":%s}' \
        "$1" "$2" "$3" "${4:+\"web_url\":\"$4\"}" "$(json_lub_null "${5:-}")"
}

status() { # <stan> [environment_url]
    printf '[{"state":"%s","environment_url":%s}]' "$1" "$(json_lub_null "${2:-}")"
}

gh() {
    local url="${2:-}" n
    case "$url" in
        "repos/${REPO}/deployments?sha=${SHA}&per_page=20")
            case "$SCENARIUSZ" in
                brak)          echo '[]' ;;
                api-pada)      return 1 ;;
                inny-sha)      echo "[$(deployment 7 "$INNY" pr-12 https://inny.invalid)]" ;;
                inne-srodowisko) echo "[$(deployment 7 "$SHA" production https://kuking.pl)]" ;;
                projekt-slash) echo "[$(deployment 9 "$SHA" "ideal-exploration / pr-12" https://slash.invalid)]" ;;
                sukces-bez-url) echo "[$(deployment 9 "$SHA" pr-12)]" ;;
                *)             echo "[$(deployment 9 "$SHA" pr-12 https://adres-z-deploymentu.invalid/)]" ;;
            esac ;;
        "repos/${REPO}/deployments/9/statuses?per_page=1")
            n=$(( $(cat "$LICZNIK") + 1 )); echo "$n" > "$LICZNIK"
            case "$SCENARIUSZ" in
                # Adres jest od początku, a gotowość dopiero w trzecim odczycie.
                do-sukcesu)     case "$n" in 1) status queued ;; 2) status in_progress https://pr-12.invalid ;;
                                             *) status success https://pr-12.invalid/ ;; esac ;;
                do-porazki)     if [ "$n" -le 1 ]; then status in_progress https://pr-12.invalid
                                else status failure; fi ;;
                blad)           status error ;;
                nieaktywny)     status inactive ;;
                wiecznie-buduje) status in_progress https://pr-12.invalid ;;
                bez-statusu)    echo '[]' ;;
                sukces-bez-url) status success ;;
                projekt-slash)  status success ;;
                sukces-url-dep) status success ;;
                *)              status success https://pr-12.invalid ;;
            esac ;;
        *) echo "atrapa gh: nieoczekiwane wywołanie $*" >&2; return 99 ;;
    esac
}

sprawdz() {
    local opis="$1" oczekiwany="$2"; shift 2
    local wyjscie kod
    echo 0 > "$LICZNIK"
    wyjscie="$("$@" 2>&1)" && kod=0 || kod=$?
    if [ "$kod" = "$oczekiwany" ]; then
        printf "  ${ZIELONY}✓${RESET} %s\n" "$opis"; zdane=$((zdane + 1))
    else
        printf "  ${CZERWONY}✗${RESET} %s\n     oczekiwano kodu %s, otrzymano %s\n%s\n" \
            "$opis" "$oczekiwany" "$kod" "$wyjscie"; oblane=$((oblane + 1))
    fi
}

rowne() {
    local opis="$1" oczekiwane="$2" otrzymane="$3"
    if [ "$oczekiwane" = "$otrzymane" ]; then
        printf "  ${ZIELONY}✓${RESET} %s\n" "$opis"; zdane=$((zdane + 1))
    else
        printf "  ${CZERWONY}✗${RESET} %s\n     oczekiwano '%s', otrzymano '%s'\n" "$opis" "$oczekiwane" "$otrzymane"
        oblane=$((oblane + 1))
    fi
}

z() { SCENARIUSZ="$1"; shift; "$@"; }

export PREVIEW_ODSTEP=0 PREVIEW_PROBY=5

echo "czekaj_na_preview (#1389)"
sprawdz "queued -> in_progress -> success przechodzi (kontrola dodatnia)" 0 z do-sukcesu      czekaj_na_preview "$REPO" "$SHA"
sprawdz "SHA wielkimi literami przechodzi"                                0 z do-sukcesu      czekaj_na_preview "$REPO" "${SHA^^}"
sprawdz "środowisko „<projekt> / pr-12” jest rozpoznane"                  0 z projekt-slash   czekaj_na_preview "$REPO" "$SHA"
sprawdz "adres przy in_progress to NIE gotowość: in_progress -> failure oblewa" 1 z do-porazki czekaj_na_preview "$REPO" "$SHA"
sprawdz "adres bez końca budowania oblewa po limicie"                     1 z wiecznie-buduje czekaj_na_preview "$REPO" "$SHA"
sprawdz "status error oblewa"                                             1 z blad            czekaj_na_preview "$REPO" "$SHA"
sprawdz "status inactive oblewa"                                          1 z nieaktywny      czekaj_na_preview "$REPO" "$SHA"
sprawdz "deployment bez żadnego statusu oblewa po limicie"                1 z bez-statusu     czekaj_na_preview "$REPO" "$SHA"
sprawdz "brak deploymentu oblewa (nie cichy zielony)"                     1 z brak            czekaj_na_preview "$REPO" "$SHA"
sprawdz "niedostępne API oblewa po limicie"                               1 z api-pada        czekaj_na_preview "$REPO" "$SHA"
sprawdz "deployment innego commita nie jest brany"                        1 z inny-sha        czekaj_na_preview "$REPO" "$SHA"
sprawdz "deployment produkcji nie jest brany"                             1 z inne-srodowisko czekaj_na_preview "$REPO" "$SHA"
sprawdz "success bez żadnego adresu oblewa"                               1 z sukces-bez-url  czekaj_na_preview "$REPO" "$SHA"
sprawdz "skrót zamiast pełnego SHA oblewa"                                1 z do-sukcesu      czekaj_na_preview "$REPO" "${SHA:0:7}"

# Wynik: adres ze statusu ma pierwszeństwo, bez końcowego ukośnika.
echo 0 > "$LICZNIK"; SCENARIUSZ=do-sukcesu czekaj_na_preview "$REPO" "$SHA" >/dev/null
rowne "adres ze statusu success, bez końcowego ukośnika" "https://pr-12.invalid" "$PREVIEW_URL"
rowne "gotowość dopiero w trzecim odczycie statusu" "3" "$(cat "$LICZNIK")"
rowne "PREVIEW_DEPLOYMENT to id wybranego deploymentu" "9" "$PREVIEW_DEPLOYMENT"

echo 0 > "$LICZNIK"; SCENARIUSZ=sukces-url-dep czekaj_na_preview "$REPO" "$SHA" >/dev/null
rowne "bez adresu w statusie — adres tego samego deploymentu" "https://adres-z-deploymentu.invalid" "$PREVIEW_URL"

echo 0 > "$LICZNIK"; SCENARIUSZ=do-porazki czekaj_na_preview "$REPO" "$SHA" >/dev/null
rowne "porażka nie zostawia adresu" "" "$PREVIEW_URL"
rowne "porażka podaje stan" "failure" "$PREVIEW_STAN"
rowne "failure kończy czekanie od razu, bez wyczerpania prób" "2" "$(cat "$LICZNIK")"

echo 0 > "$LICZNIK"; SCENARIUSZ=wiecznie-buduje czekaj_na_preview "$REPO" "$SHA" >/dev/null
rowne "limit prób: dokładnie PREVIEW_PROBY odczytów statusu" "$PREVIEW_PROBY" "$(cat "$LICZNIK")"
rowne "po limicie brak adresu mimo adresu przy in_progress" "" "$PREVIEW_URL"

echo ""
echo "Zdane: $zdane, oblane: $oblane"
[ "$oblane" -eq 0 ]
