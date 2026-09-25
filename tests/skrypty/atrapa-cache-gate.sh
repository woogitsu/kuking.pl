#!/usr/bin/env bash
set -euo pipefail
curl() {
    local url="${!#}" code=200 control='private, no-store' cf=BYPASS cookie='' auth=0
    local location=$'Location: https://storage.invalid/fixture?X-Amz-Signature=abcdef&X-Amz-Date=20260920T120000Z&X-Amz-Expires=3600\r\n'
    [[ " $* " == *' --cookie '* ]] && auth=1
    case "$url" in
        https://example.invalid/ustawienia)
            [ "$auth" = 1 ] || code=302
            [ "$SCENARIO" != expired ] || code=302 ;;
        https://example.invalid/private)
            code=302; [ "$CACHE_KIND" != html ] || code=200
            [ "$auth" = 1 ] || code=404
            # Prywatny przepis odmawia gościowi 403 (#610); zdjęcie — 404.
            [ "$auth" = 1 ] || [[ "$SCENARIO" != *-403 ]] || code=403 ;;
        https://example.invalid/public)
            code=302
            [ "$CACHE_KIND" != html ] || code=200
            if [ "$auth" = 0 ]; then
                printf x >> "$CACHE_COUNT_FILE"
                control='public, max-age=1800'; cf=HIT
                case "$SCENARIO" in
                    html-610*) control='public, max-age=0, s-maxage=120' ;;
                    cookie) cookie=$'Set-Cookie: fixture=TAJNA_WARTOSC\r\n' ;;
                    no-control) control='' ;;
                    no-public) control='max-age=1800' ;;
                    zero-ttl) control='public, max-age=0' ;;
                    private-public) control='public, private, max-age=1800' ;;
                    no-hit) cf=MISS ;;
                    duplicate) control=$'public, max-age=1800\r\nCache-Control: private' ;;
                    timeout) return 28 ;;
                    truncated) printf 'HTTP/2 302\r\nCache-Control: public, max-age=1800\nKUKING_HTTP_CODE:302\n'; return ;;
                    unsigned) location=$'Location: https://storage.invalid/fixture\r\n' ;;
                    cache-longer-than-signature) control='public, max-age=3600' ;;
                esac
            else
                case "$SCENARIO" in
                    auth-public) control='public, max-age=1800' ;;
                    auth-private-only) control='private' ;;
                    auth-hit) cf=HIT ;;
                    auth-miss) cf=MISS ;;
                    auth-unknown) cf='' ;;
                    auth-cdn) cookie=$'Cloudflare-CDN-Cache-Control: public, max-age=600\r\n' ;;
                    auth-error) code=500 ;;
                    auth-after-hit) [ "$(wc -c < "$CACHE_COUNT_FILE")" -lt 2 ] || cf=HIT ;;
                esac
            fi ;;
        *) return 99 ;;
    esac
    printf 'HTTP/2 %s\r\nCache-Control: %s\r\nCF-Cache-Status: %s\r\n%s%s\r\n\nKUKING_HTTP_CODE:%s\n' "$code" "$control" "$cf" "$location" "$cookie" "$code"
}
export -f curl
bash "$1" example.invalid --cache-gate
