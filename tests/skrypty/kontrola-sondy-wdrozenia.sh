#!/usr/bin/env bash
# =============================================================================
#  Kontrola sond testu dymnego po wdrożeniu (scripts/sonda-wdrozenia.sh)
# =============================================================================
#
#  Bez sieci: funkcja `curl` niżej zastępuje każde wywołanie curl w bibliotece
#  i odpowiada według zmiennej SCENARIUSZ. Każda sonda dostaje kontrolę
#  DODATNIĄ (poprawny stan przechodzi) i ujemne (każda fałszywa zieleń z issue
#  #1012 i #1332 oblewa). Sama kontrola ujemna nie wystarcza: sonda, która
#  zawsze oblewa, też by ją zdała.
#
#  Uruchomienie:  bash tests/skrypty/kontrola-sondy-wdrozenia.sh
# =============================================================================

set -uo pipefail

KORZEN="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=../../scripts/sonda-wdrozenia.sh
source "$KORZEN/scripts/sonda-wdrozenia.sh"

CZERWONY='\033[0;31m'; ZIELONY='\033[0;32m'; RESET='\033[0m'
zdane=0; oblane=0

WDRAZANY=0123456789abcdef0123456789abcdef01234567
NOWSZY=fedcba9876543210fedcba9876543210fedcba98

# Licznik wywołań w pliku, bo `$(...)` odpala funkcję w podpowłoce i zmienna
# nie przeżyłaby powrotu.
LICZNIK="$(mktemp -t sonda-wdrozenia-licznik.XXXXXX)"
trap 'rm -f "$LICZNIK"' EXIT

curl() {
    local url="${!#}" n
    n=$(( $(cat "$LICZNIK" 2>/dev/null || echo 0) + 1 ))
    echo "$n" > "$LICZNIK"

    case "$url" in
        https://example.invalid/wydanie\?sonda=*)
            case "$SCENARIUSZ" in
                zgodny)        printf '{"commit":"%s"}' "$WDRAZANY" ;;
                zgodny-wielkie) printf '{"commit": "%s"}' "${WDRAZANY^^}" ;;
                nowszy)        printf '{"commit":"%s"}' "$NOWSZY" ;;
                null)          printf '{"commit":null}' ;;
                html)          printf '<html>wydanie 0123456</html>' ;;
                niedostepny)   return 28 ;;
                # Stare wydanie jeszcze przez dwie próby, potem nowe.
                przelaczenie)  if [ "$n" -le 2 ]; then printf '{"commit":"%s"}' "$NOWSZY"
                               else printf '{"commit":"%s"}' "$WDRAZANY"; fi ;;
            esac ;;
        http://example.invalid/)
            case "$SCENARIUSZ" in
                https-301)     printf '301 https://example.invalid/' ;;
                https-308)     printf '308 https://example.invalid/' ;;
                https-http)    printf '301 http://example.invalid/' ;;
                https-obcy)    printf '301 https://obcy.invalid/' ;;
                https-prefiks) printf '301 https://example.invalid.obcy.invalid/' ;;
                https-userinfo) printf '301 https://example.invalid@obcy.invalid/' ;;
                https-port)    printf '301 https://example.invalid:8443/' ;;
                https-302)     printf '302 https://example.invalid/' ;;
                https-bez-celu) printf '301 ' ;;
                https-200)     printf '200 ' ;;
                https-cisza)   printf '000 '; return 28 ;;
            esac ;;
        *) echo "atrapa curl: nieoczekiwany adres $url" >&2; return 99 ;;
    esac
}

sprawdz() {
    local opis="$1" oczekiwany="$2"; shift 2
    local wyjscie kod
    echo 0 > "$LICZNIK"
    wyjscie="$("$@" 2>&1)" && kod=0 || kod=$?
    if [ "$kod" = "$oczekiwany" ]; then
        printf "  ${ZIELONY}✓${RESET} %s\n" "$opis"
        zdane=$((zdane + 1))
    else
        printf "  ${CZERWONY}✗${RESET} %s\n     oczekiwano kodu %s, otrzymano %s\n%s\n" \
            "$opis" "$oczekiwany" "$kod" "$wyjscie"
        oblane=$((oblane + 1))
    fi
}

zawiera() {
    local opis="$1" wzorzec="$2"; shift 2
    local wyjscie
    echo 0 > "$LICZNIK"
    wyjscie="$("$@" 2>&1)"
    if [[ "$wyjscie" == *"$wzorzec"* ]]; then
        printf "  ${ZIELONY}✓${RESET} %s\n" "$opis"
        zdane=$((zdane + 1))
    else
        printf "  ${CZERWONY}✗${RESET} %s\n     brak '%s' w:\n%s\n" "$opis" "$wzorzec" "$wyjscie"
        oblane=$((oblane + 1))
    fi
}

z() { SCENARIUSZ="$1"; shift; "$@"; }

export SONDA_ODSTEP=0 SONDA_PROBY=4

echo "sonda_wydanie (#1012)"
sprawdz "zgodny SHA przechodzi (kontrola dodatnia)"          0 z zgodny         sonda_wydanie https://example.invalid "$WDRAZANY"
sprawdz "zgodny SHA wielkimi literami przechodzi"            0 z zgodny-wielkie sonda_wydanie https://example.invalid/ "${WDRAZANY^^}"
sprawdz "przełączenie w trakcie oczekiwania przechodzi"      0 z przelaczenie   sonda_wydanie https://example.invalid "$WDRAZANY"
sprawdz "nowszy/inny SHA pod adresem oblewa"                 1 z nowszy         sonda_wydanie https://example.invalid "$WDRAZANY"
sprawdz "commit null (brak sygnału) oblewa"                  1 z null           sonda_wydanie https://example.invalid "$WDRAZANY"
sprawdz "sam skrót w HTML-u nie jest sygnałem"               1 z html           sonda_wydanie https://example.invalid "$WDRAZANY"
sprawdz "niedostępna odpowiedź oblewa"                       1 z niedostepny    sonda_wydanie https://example.invalid "$WDRAZANY"
sprawdz "skrót zamiast pełnego oczekiwanego SHA oblewa"      1 z zgodny         sonda_wydanie https://example.invalid "${WDRAZANY:0:7}"
sprawdz "pusty oczekiwany SHA oblewa"                        1 z zgodny         sonda_wydanie https://example.invalid ""
zawiera "porażka podaje oczekiwany SHA"   "oczekiwano: $WDRAZANY" z nowszy sonda_wydanie https://example.invalid "$WDRAZANY"
zawiera "porażka podaje otrzymany SHA"    "otrzymano:  $NOWSZY"   z nowszy sonda_wydanie https://example.invalid "$WDRAZANY"
zawiera "brak odpowiedzi jest nazwany"    "brak odpowiedzi (curl 28)" z niedostepny sonda_wydanie https://example.invalid "$WDRAZANY"

# Limit prób działa: przy stałej niezgodności dokładnie SONDA_PROBY wywołań.
echo 0 > "$LICZNIK"; SCENARIUSZ=nowszy sonda_wydanie https://example.invalid "$WDRAZANY" >/dev/null 2>&1
if [ "$(cat "$LICZNIK")" = "$SONDA_PROBY" ]; then
    printf "  ${ZIELONY}✓${RESET} ponawia dokładnie SONDA_PROBY razy, potem się poddaje\n"; zdane=$((zdane + 1))
else
    printf "  ${CZERWONY}✗${RESET} liczba prób %s, oczekiwano %s\n" "$(cat "$LICZNIK")" "$SONDA_PROBY"; oblane=$((oblane + 1))
fi

# SONDA_POTWIERDZONY ustawia się wyłącznie po zgodności.
SCENARIUSZ=zgodny sonda_wydanie https://example.invalid "$WDRAZANY" >/dev/null
potw_ok="$SONDA_POTWIERDZONY"
SCENARIUSZ=nowszy sonda_wydanie https://example.invalid "$WDRAZANY" >/dev/null
potw_zle="$SONDA_POTWIERDZONY"
if [ "$potw_ok" = "$WDRAZANY" ] && [ -z "$potw_zle" ]; then
    printf "  ${ZIELONY}✓${RESET} SONDA_POTWIERDZONY tylko po zgodności\n"; zdane=$((zdane + 1))
else
    printf "  ${CZERWONY}✗${RESET} SONDA_POTWIERDZONY: zgodny='%s', niezgodny='%s'\n" "$potw_ok" "$potw_zle"; oblane=$((oblane + 1))
fi

echo "sonda_https (#1332)"
sprawdz "301 -> https://host/ przechodzi (kontrola dodatnia)" 0 z https-301      sonda_https example.invalid
sprawdz "308 -> https://host/ przechodzi"                     0 z https-308      sonda_https EXAMPLE.invalid
sprawdz "przekierowanie na http:// oblewa"                    1 z https-http     sonda_https example.invalid
sprawdz "przekierowanie na obcy host oblewa"                  1 z https-obcy     sonda_https example.invalid
sprawdz "host jako prefiks obcego oblewa"                     1 z https-prefiks  sonda_https example.invalid
sprawdz "host jako userinfo oblewa"                           1 z https-userinfo sonda_https example.invalid
sprawdz "inny port oblewa"                                    1 z https-port     sonda_https example.invalid
sprawdz "302 (tymczasowe) oblewa — runbook: 301/308"          1 z https-302      sonda_https example.invalid
sprawdz "30x bez Location oblewa"                             1 z https-bez-celu sonda_https example.invalid
sprawdz "200 po HTTP (brak wymuszenia) oblewa"                1 z https-200      sonda_https example.invalid
sprawdz "brak odpowiedzi oblewa"                              1 z https-cisza    sonda_https example.invalid

echo ""
echo "Zdane: $zdane, oblane: $oblane"
[ "$oblane" -eq 0 ]
