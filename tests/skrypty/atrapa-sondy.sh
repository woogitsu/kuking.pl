#!/usr/bin/env bash
# Bez sieci: funkcja zastępuje KAŻDE wywołanie curl w rzeczywistym skrypcie.
set -euo pipefail
curl() {
    local url="${!#}" code=200 headers=$'HTTP/2 200\r\n' target='' format='' output='' dump='' body=''
    local -a args=("$@")
    local i
    for ((i=0; i<${#args[@]}; i++)); do
        case "${args[i]}" in
            -w) format="${args[i+1]}" ;;
            -o) output="${args[i+1]}" ;;
            -D) dump="${args[i+1]}" ;;
        esac
    done
    case "$url" in
        https://example.invalid/health) printf '{"status":"ok"}'; return ;;
        https://example.invalid/nie-ma-takiej-strony-12345) printf 'Nie znaleziono strony'; return ;;
        http://example.invalid/) code=301; target='https://example.invalid/' ;;
        https://www.example.invalid/)
            code=301; target='https://example.invalid/'
            case "$SCENARIO" in
                www-308) code=308 ;;
                www-port) target='https://example.invalid:443/' ;;
                www-case) target='HTTPS://EXAMPLE.INVALID/przepisy' ;;
                www-loop) target='https://www.example.invalid/' ;;
                www-other) target='https://other.invalid/' ;;
                www-prefix) target='https://example.invalid.other.invalid/' ;;
                www-userinfo) target='https://example.invalid@other.invalid/' ;;
                www-http) target='http://example.invalid/' ;;
                www-empty) target='' ;;
                www-200) code=200; target='' ;;
                www-timeout) printf '301 https://example.invalid/'; return 28 ;;
            esac ;;
        https://example.invalid/login)
            headers+=$'Set-Cookie: secure-fixture-session=TAJNA_WARTOSC; Path=/; HttpOnly'
            case "$SCENARIO" in
                cookie-name) ;;
                cookie-value) headers=$'HTTP/2 200\r\nSet-Cookie: secure-fixture-session=secure_TAJNA_WARTOSC; HttpOnly' ;;
                cookie-duplicate) headers+=$'; Secure\r\nSet-Cookie: secure-fixture-session=TAJNA_WARTOSC; Path=/inna' ;;
                cookie-other) headers+=$'\r\nSet-Cookie: other=TAJNA_WARTOSC; Secure' ;;
                cookie-assignment) headers+='; Secure=false' ;;
                cookie-mixed) headers+='; sEcUrE' ;;
                cookie-missing) headers=$'HTTP/2 200\r\nSet-Cookie: other=TAJNA_WARTOSC; Secure' ;;
                cookie-timeout) return 28 ;;
                cookie-partial) printf '%s' "$headers"; printf '\r\n\r\n\nKUKING_HTTP_CODE:200\n'; return 18 ;;
                *) headers+='; Secure' ;;
            esac
            headers+=$'\r\n\r\n' ;;
        https://example.invalid/livewire/update)
            code=405
            headers=$'HTTP/2 405\r\ncf-cache-status: BYPASS\r\n\r\n'
            case "$SCENARIO" in
                livewire-dynamic) headers=$'HTTP/2 405\r\nCF-Cache-Status: DYNAMIC\r\n\r\n' ;;
                livewire-proxy) headers=$'HTTP/1.1 200 Connection established\r\ncf-cache-status: HIT\r\n\r\n'"$headers" ;;
                livewire-truncated) headers=$'HTTP/2 405\r\ncf-cache-status: BYPASS\r\n' ;;
                livewire-timeout) [[ "$format" == '%{http_code}' ]] && { printf 405; return; }; return 28 ;;
                livewire-partial) printf '%s\nKUKING_HTTP_CODE:405\n' "$headers"; return 18 ;;
                livewire-empty) headers='' ;;
                livewire-404) code=404; headers=$'HTTP/2 404\r\n\r\n' ;;
                livewire-500) code=500; headers=$'HTTP/2 500\r\n\r\n' ;;
                livewire-no-cache-header) headers=$'HTTP/2 405\r\n\r\n' ;;
                livewire-HIT|livewire-MISS)
                    headers=$'HTTP/2 405\r\ncf-cache-status: '; headers+="${SCENARIO#livewire-}"; headers+=$'\r\n'
                    printf -v headers '%sx-padding: %100000s\r\n\r\n' "$headers" 'x' ;;
            esac ;;
        https://example.invalid/build/manifest.json) headers+=$'cache-control: immutable\r\n\r\n' ;;
        # Hashowane zasoby Vite wskazane przez stronę główną (#809). Domyślnie
        # poprawne: HTTP 200, właściwy Content-Type, roczny cache immutable.
        # Scenariusze `asset-*` psują dokładnie jedną z tych rzeczy naraz.
        https://example.invalid/build/assets/app-fixture123.css)
            headers+=$'content-type: text/css; charset=utf-8\r\ncache-control: public,max-age=31536000,immutable\r\n\r\n'
            body='.kuking{color:#151714}'
            case "$SCENARIO" in
                asset-css-404) code=404; headers=$'HTTP/2 404\r\n\r\n'; body='' ;;
                asset-css-typ) headers=$'HTTP/2 200\r\ncontent-type: text/html\r\ncache-control: public,max-age=31536000,immutable\r\n\r\n' ;;
                asset-css-cache) headers=$'HTTP/2 200\r\ncontent-type: text/css\r\ncache-control: no-store\r\n\r\n' ;;
                asset-css-pusty) body='' ;;
            esac ;;
        https://example.invalid/build/assets/app-fixture456.js)
            headers+=$'content-type: text/javascript; charset=utf-8\r\ncache-control: public,max-age=31536000,immutable\r\n\r\n'
            body='console.log(1)' ;;
        https://example.invalid/)
            headers+=$'cf-ray: fixture\r\nx-content-type-options: nosniff\r\nx-frame-options: DENY\r\n\r\n'
            body='<html><head><link rel="stylesheet" href="/build/assets/app-fixture123.css"><script src="/build/assets/app-fixture456.js"></script></head><body>Kuking</body></html>'
            case "$SCENARIO" in
                strona-bez-assetow) body='<html><head></head><body>Kuking</body></html>' ;;
            esac ;;
        https://cdn.example.invalid/) code=404 ;;
        *) printf 'NIEZNANE ŻĄDANIE ATRAPY\n' >&2; return 99 ;;
    esac
    [[ -z "$dump" ]] || printf '%s' "$headers" > "$dump"
    if [[ -n "$output" && "$output" != /dev/null ]]; then
        # Prawdziwy curl kładzie do pliku z `-o` TREŚĆ, nie nagłówki.
        printf '%s' "$body" > "$output"
    elif [[ "$output" != /dev/null && -z "$dump" ]]; then
        printf '%s' "$headers"
    fi
    format="${format//\%\{http_code\}/$code}"
    format="${format//\%\{redirect_url\}/$target}"
    printf '%b' "$format"
}
export -f curl
bash "$1" example.invalid
