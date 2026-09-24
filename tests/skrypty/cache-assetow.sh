#!/usr/bin/env bash
# Wykonuje rzeczywisty blok sondy; transport jest atrapą, bez sieci.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BLOCK="$(sed -n '/^naglowek "Cache i CDN"/,/^# Ten test jest ważniejszy/{ /^# Ten test jest ważniejszy/d; p; }' "$ROOT/scripts/sprawdz-wdrozenie.sh")"
[[ "$BLOCK" == *manifest* || "$BLOCK" == *sprawdz-cache-assetow* ]] || { echo 'Skan nie czyta sondy'; exit 1; }
ok() { echo "OK: $*"; }
blad() { echo "BLAD: $*"; bledy=$((bledy + 1)); }
uwaga() { echo "UWAGA: $*"; }
rada() { :; }
naglowek() { :; }
curl() {
    local url="${!#}" headers='' output='' write='' head=0 status=200 body='asset' type='application/javascript'
    while (($#)); do
        case "$1" in
            -D) headers="$2"; shift;;
            -o) output="$2"; shift;;
            -w) write="$2"; shift;;
            -I) head=1;;
        esac
        shift
    done
    echo "$url" >> "$REQUESTS"
    case "$url" in
        https://sonda.test/) body='<link rel="stylesheet" href="/build/assets/app-ABCDEFGH.css"><script src="https://sonda.test/build/assets/app-IJKLMNOP.js"></script>'; type='text/html';;
        */manifest.json) body='{"resources/js/app.js":{"file":"assets/app-IJKLMNOP.js","css":["assets/app-ABCDEFGH.css"]}}'; type='application/json';;
        *.css) type='text/css';;
    esac
    case "$CASE:$url" in
        404:*/build/*|missing:*.js) status=404;;
        500:*/build/*) status=500;;
        timeout:*/build/*) return 28;;
        html:*.js) type='text/html'; body='<html>Brak pliku</html>';;
        empty:*.js) body='';;
        foreign:https://sonda.test/) body='<link href="https://obcy.test/build/assets/app-ABCDEFGH.css"><script src="//obcy.test/build/assets/app-IJKLMNOP.js"></script>';;
        injection:https://sonda.test/) body='<link href="/build/assets/$(touch PWNED)-ABCDEFGH.css"><script src="/build/assets/../app-IJKLMNOP.js"></script>';;
        data_only:https://sonda.test/) body='<div href="/build/assets/app-ABCDEFGH.css"></div><script data-src="/build/assets/app-IJKLMNOP.js"></script>';;
    esac
    local cache='public, max-age=31536000, immutable'
    [[ "$CASE" != no_cache || "$url" != *.js ]] || cache='no-cache'
    [[ "$CASE" != private || "$url" != *.js ]] || cache='private,max-age=31536000,immutable'
    local response
    response=$(printf 'HTTP/2 %s\r\nContent-Type: %s\r\nCache-Control:%s\r\n\r\n' "$status" "$type" "$cache")
    [[ -z "$headers" ]] || printf '%s' "$response" > "$headers"
    if ((head)); then printf '%s' "$response"; return; fi
    if [[ -n "$output" ]]; then printf '%s' "$body" > "$output"; else printf '%s' "$body"; fi
    [[ -z "$write" ]] || printf '%s' "$status"
}
export -f curl ok blad uwaga rada naglowek
TEMP=$(mktemp -d)
trap 'rm -rf "$TEMP"' EXIT
export REQUESTS="$TEMP/requests"
failed=0
for CASE in good 404 500 timeout missing html empty no_cache private foreign injection data_only; do
    export CASE
    : > "$REQUESTS"
    if result=$(bash -c 'set -uo pipefail; bledy=0; HOST=sonda.test; POBIERZ=(curl -sS --max-time 15); eval "$1"; ((bledy == 0))' "$ROOT/scripts/sprawdz-wdrozenie.sh" "$BLOCK"); then code=0; else code=$?; fi
    if [[ "$CASE" == good ]]; then
        if ((code != 0)) || ! grep -q 'OK:.*app-ABCDEFGH.css' <<< "$result" || ! grep -q 'OK:.*app-IJKLMNOP.js' <<< "$result"; then
            echo "FAIL $CASE: brak dowodu CSS i JS: $result"; failed=1
        fi
    elif grep -q 'OK:' <<< "$result" && ! grep -q 'BLAD:' <<< "$result"; then
        echo "FAIL $CASE: fałszywy sukces: $result"; failed=1
    elif ! grep -q 'BLAD:' <<< "$result"; then
        echo "FAIL $CASE: brak jawnej porażki: $result"; failed=1
    fi
    if [[ "$CASE" != good ]] && ((code == 0)); then echo "FAIL $CASE: kod sondy nie sygnalizuje błędu"; failed=1; fi
    case "$CASE" in
        404|500|timeout|missing|html|empty|no_cache)
            if ! grep -q '/build/assets/app-IJKLMNOP.js' "$REQUESTS"; then echo "FAIL $CASE: sonda nie pyta o JS"; failed=1; fi;;
    esac
    if grep -v '^https://sonda.test/' "$REQUESTS"; then echo "FAIL $CASE: obce źródło"; failed=1; fi
    echo "Sprawdzono: $CASE"
done
exit "$failed"
