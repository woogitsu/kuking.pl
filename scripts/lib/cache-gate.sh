#!/usr/bin/env bash
# Rozszerzenie sondy #806. Transport i parser odpowiedzi pozostają wspólne.
cache_header() {
    awk -v key="$1" 'tolower($0) ~ "^" key ":" { sub(/^[^:]*:[[:space:]]*/, ""); print }' <<< "$naglowki_odpowiedzi"
}

cache_private() {
    local control status
    control=$(cache_header cache-control)
    status=$(cache_header cf-cache-status)
    # Wymagamy obu dyrektyw i jednoznacznego statusu. Brak pomiaru to odmowa.
    [[ "$control" != *$'\n'* ]] && [[ "$status" =~ ^(BYPASS|DYNAMIC)$ ]] \
        && grep -qiE '(^|,)[[:space:]]*private[[:space:]]*(,|$)' <<< "$control" \
        && grep -qiE '(^|,)[[:space:]]*no-store[[:space:]]*(,|$)' <<< "$control" \
        && ! grep -qiE '(^|,)[[:space:]]*(public|s-maxage)([[:space:]=,]|$)' <<< "$control" \
        && ! grep -qiE '^(cdn-cache-control|cloudflare-cdn-cache-control|surrogate-control):' <<< "$naglowki_odpowiedzi"
}

cache_public() {
    local control status location lifetime ttl
    control=$(cache_header cache-control)
    status=$(cache_header cf-cache-status)
    if [ "$CACHE_KIND" = media ]; then
        location=$(cache_header location)
        [[ "$location" == https://* ]] || return 1
        [[ "$location" =~ [\?\&]X-Amz-Signature=[a-fA-F0-9]+ ]] || return 1
        [[ "$location" =~ [\?\&]X-Amz-Date=[0-9]{8}T[0-9]{6}Z ]] || return 1
        [[ "$location" =~ [\?\&]X-Amz-Expires=([1-9][0-9]{0,4})($|\&) ]] || return 1
        lifetime="${BASH_REMATCH[1]}"
        [[ "$control" =~ (^|,)[[:space:]]*max-age=([1-9][0-9]{0,4})($|[[:space:],]) ]] || return 1
        ttl="${BASH_REMATCH[2]}"
        [ "$lifetime" -le 3600 ] && [ "$ttl" -lt "$lifetime" ] && [ "$ttl" -le 1800 ] || return 1
    fi
    [[ "$control" != *$'\n'* ]] && [[ "$status" =~ ^(MISS|HIT)$ ]] \
        && ! grep -qi '^set-cookie:' <<< "$naglowki_odpowiedzi" \
        && grep -qiE '(^|,)[[:space:]]*public[[:space:]]*(,|$)' <<< "$control" \
        && grep -qiE '(^|,)[[:space:]]*(s-maxage|max-age)=[1-9][0-9]*[[:space:]]*(,|$)' <<< "$control" \
        && ! grep -qiE '(^|,)[[:space:]]*(private|no-store|no-cache)([[:space:]=,]|$)' <<< "$control" \
        && ! grep -qiE '^(cdn-cache-control|cloudflare-cdn-cache-control|surrogate-control):' <<< "$naglowki_odpowiedzi"
}

cache_gate() {
    local path expected attempt denied
    CACHE_GATE_GET=1
    if [[ ! "$HOST" =~ ^[a-zA-Z0-9.-]+$ ]] \
        || [[ ! "${CACHE_KIND:-}" =~ ^(media|html)$ ]] \
        || [ ! -s "${CACHE_COOKIE_FILE:-}" ]; then
        blad 'ODMOWA CACHE: podaj host, CACHE_KIND=media/html i lokalny CACHE_COOKIE_FILE z sesją konta testowego.'
        return 1
    fi
    for path in "${CACHE_PUBLIC_PATH:-}" "${CACHE_PRIVATE_PATH:-}"; do
        if [[ ! "$path" =~ ^/[^[:space:]\?\#]*$ ]] || [[ "$path" == //* ]]; then
            blad 'ODMOWA CACHE: podaj dwie ścieżki bez domeny, zapytania i fragmentu.'
            return 1
        fi
    done
    # Ochrona przed zaliczeniem pomiaru z wygasłym albo błędnym cookie.
    if ! pobierz_naglowki "https://$HOST/ustawienia" \
        || [[ ! "$kod_odpowiedzi" =~ ^30[23]$ ]] || ! cache_private; then
        blad 'ODMOWA CACHE: anonim musi zostać odesłany z ustawień, z zakazem cache.'
        return 1
    fi
    if ! pobierz_naglowki "https://$HOST/ustawienia" --cookie "$CACHE_COOKIE_FILE" \
        || [ "$kod_odpowiedzi" != 200 ] || ! cache_private; then
        blad 'ODMOWA CACHE: nie potwierdzono działającej sesji i zakazu cache ustawień.'
        return 1
    fi
    expected=200
    [ "$CACHE_KIND" = media ] && expected=302
    # Najpierw zalogowany, potem anonim: nie ogrzewamy cache przed ochroną.
    for path in "$CACHE_PUBLIC_PATH" "$CACHE_PRIVATE_PATH"; do
        if ! pobierz_naglowki "https://$HOST$path" --cookie "$CACHE_COOKIE_FILE" \
            || [ "$kod_odpowiedzi" != "$expected" ] || ! cache_private; then
            blad 'ODMOWA CACHE: odpowiedź zalogowanego nie spełnia kontraktu private/no-store i BYPASS/DYNAMIC.'
            return 1
        fi
    done
    # Przepis prywatny pod bieżącym adresem odmawia gościowi 403 (świadomie,
    # RecipeController::show), zdjęcie — 404. Obie odmowy muszą być no-store.
    denied='^404$'
    [ "$CACHE_KIND" = html ] && denied='^40[34]$'
    if ! pobierz_naglowki "https://$HOST$CACHE_PRIVATE_PATH" \
        || [[ ! "$kod_odpowiedzi" =~ $denied ]] || ! cache_private; then
        blad 'ODMOWA CACHE: anonim nie dostał bezpiecznej odmowy prywatnej treści.'
        return 1
    fi
    for attempt in 1 2; do
        if ! pobierz_naglowki "https://$HOST$CACHE_PUBLIC_PATH" \
            || [ "$kod_odpowiedzi" != "$expected" ] || ! cache_public; then
            blad 'ODMOWA CACHE: publiczna odpowiedź musi mieć dodatni TTL, brak Set-Cookie i MISS/HIT.'
            return 1
        fi
    done
    if [ "$(cache_header cf-cache-status)" != HIT ]; then
        blad 'ODMOWA CACHE: drugie pobranie nie dało HIT. Nie potwierdzono działania reguły.'
        return 1
    fi
    # Ogrzany cache nie może ominąć sesji przy tym samym adresie.
    if ! pobierz_naglowki "https://$HOST$CACHE_PUBLIC_PATH" --cookie "$CACHE_COOKIE_FILE" \
        || [ "$kod_odpowiedzi" != "$expected" ] || ! cache_private; then
        blad 'ODMOWA CACHE: ogrzany cache nie ominął sesji. Wyłącz regułę i wyczyść cache.'
        return 1
    fi
    ok 'Bramka cache przeszła: anonim publiczny HIT, sesja i treść prywatna bez cache.'
}
