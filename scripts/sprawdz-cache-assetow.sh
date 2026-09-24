#!/usr/bin/env bash
# Funkcja działa w podpowłoce, żeby sprzątanie plików nie zmieniało pułapek sondy.
sprawdz_cache_assetow() (
    local temp status html tags attributes attribute url path type cache errors=0 css=0 js=0
    temp=$(mktemp -d) || return 1
    trap 'rm -rf "$temp"' EXIT
    if ! status=$("${POBIERZ[@]}" -o "$temp/page" -w '%{http_code}' "https://$HOST/" 2>/dev/null) || [[ "$status" != 200 ]]; then
        blad "Nie można odczytać strony do kontroli CSS i JS. Sprawdź dostępność strony głównej."
        return 1
    fi
    html=$(tr '\n' ' ' < "$temp/page")
    tags=$(grep -Eoi '<(link|script)[[:space:]][^>]*>' <<< "$html" || true)
    attributes=$(grep -Eoi "[[:space:]](src|href)[[:space:]]*=[[:space:]]*[\"'][^\"']+[\"']" <<< "$tags" || true)
    declare -A seen=()
    while IFS= read -r attribute; do
        [[ -n "$attribute" ]] || continue
        url="${attribute#*=}"
        url="${url#"${url%%[![:space:]]*}"}"
        url="${url:1:${#url}-2}"
        path="${url#https://$HOST}"
        # Tylko własne źródło i hashowane ścieżki Vite. Bez przekierowań,
        # zapytań, kodowania procentowego, '..' i interpretacji jako polecenie.
        [[ "$path" =~ ^/build/assets/[a-zA-Z0-9_/-]+-[a-zA-Z0-9_-]{8,}\.(css|js)$ ]] || continue
        [[ -z "${seen[$path]+present}" ]] || continue
        seen[$path]=1
        type="${BASH_REMATCH[1]}"
        if [[ "$type" == css ]]; then css=$((css + 1)); else js=$((js + 1)); fi
        if ! status=$("${POBIERZ[@]}" -D "$temp/headers" -o "$temp/body" -w '%{http_code}' "https://$HOST$path" 2>/dev/null) || [[ "$status" != 200 ]]; then
            blad "Nie udało się pobrać $path (HTTP ${status:-000}). Sprawdź plik we wdrożeniu."
            errors=$((errors + 1)); continue
        fi
        if [[ ! -s "$temp/body" ]] || { [[ "$type" == css ]] && ! grep -qiE '^content-type:[[:space:]]*text/css([;[:space:]]|$)' "$temp/headers"; } || { [[ "$type" == js ]] && ! grep -qiE '^content-type:[[:space:]]*(text/javascript|application/javascript|application/x-javascript)([;[:space:]]|$)' "$temp/headers"; }; then
            blad "$path nie zawiera oczekiwanego CSS/JS. Sprawdź zawartość i Content-Type."
            errors=$((errors + 1)); continue
        fi
        # Białe znaki po dwukropku są opcjonalne; łączymy też powtórzone nagłówki.
        cache=$(awk 'tolower($0) ~ /^cache-control:/ { sub(/^[^:]*:/, ""); gsub(/[[:space:]]/, ""); printf "%s,", tolower($0) }' "$temp/headers")
        if [[ ",$cache" != *,immutable,* || ",$cache" != *,max-age=31536000,* || ",$cache" =~ ,(private|no-store|no-cache)(,|=) ]]; then
            blad "$path nie ma rocznego cache immutable. Sprawdź regułę dla /build/assets/."
            errors=$((errors + 1)); continue
        fi
        ok "CSS/JS: $path — HTTP 200, właściwy typ, roczny cache immutable"
    done <<< "$attributes"
    if ((css == 0 || js == 0)); then
        blad "Nie znaleziono własnego hashowanego CSS i JS na stronie. Sprawdź odnośniki /build/assets/."
        errors=$((errors + 1))
    fi
    # Manifest bez hasha nie jest dowodem dostępności plików i nie wymaga immutable.
    # Zakres pomiaru: wyłącznie wskazane wyżej zasoby, nie cały build ani importy JS.
    ((errors == 0))
)
