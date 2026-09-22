#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — weryfikacja wdrożenia z zewnątrz
# =============================================================================
#
#  PO CO TO JEST
#  Konfiguracja Railway i Cloudflare to kilkanaście ekranów w dwóch panelach.
#  Większość pomyłek nie objawia się błędem — objawia się tym, że coś działa
#  INACZEJ, niż myślisz. Strona się otwiera, więc wygląda dobrze, a tryb debug
#  pokazuje zmienne środowiskowe, zdjęcia idą przez serwer aplikacji zamiast
#  przez CDN, albo `www` nie przekierowuje.
#
#  Ten skrypt zadaje te pytania za ciebie, jednym poleceniem, z zewnątrz —
#  tak jak widzi to przeglądarka użytkownika. Nie potrzebuje żadnych kluczy
#  ani dostępu do paneli.
#
#  UŻYCIE
#      SESSION_COOKIE=kuking-session ./scripts/sprawdz-wdrozenie.sh
#      SESSION_COOKIE=nazwa-sesji-staging ./scripts/sprawdz-wdrozenie.sh staging.kuking.pl
#  SESSION_COOKIE musi odpowiadać config/session.php badanego środowiska.
#
#  KOD WYJŚCIA
#      0 — wszystko przeszło
#      1 — coś jest BŁĘDEM (nie wolno tego zostawić)
#
#  Ostrzeżenia (żółte) nie ustawiają kodu błędu: dotyczą rzeczy, które mogą
#  być jeszcze w trakcie propagacji DNS albo świadomie wyłączone na tym etapie.
#
#  Źródło checklisty: docs/infra/DEPLOYMENT_RUNBOOK.md, KROK 11.3
# =============================================================================

set -uo pipefail

# Celowo BEZ `set -e`. Ten skrypt ma przejść wszystkie testy i pokazać pełny
# obraz, a nie zatrzymać się na pierwszym niepowodzeniu — inaczej naprawiałbyś
# konfigurację po jednym problemie na przebieg.

HOST="${1:-kuking.pl}"
CDN="${KUKING_CDN_HOST:-cdn.kuking.pl}"

CZERWONY=$'\033[0;31m'
ZIELONY=$'\033[0;32m'
ZOLTY=$'\033[0;33m'
SZARY=$'\033[0;90m'
KONIEC=$'\033[0m'

bledy=0
ostrzezenia=0

naglowek() { printf '\n%s── %s ──%s\n' "$ZOLTY" "$1" "$KONIEC"; }
ok()       { printf '%s✓%s %s\n' "$ZIELONY" "$KONIEC" "$1"; }
blad()     { printf '%s✗ %s%s\n' "$CZERWONY" "$1" "$KONIEC"; bledy=$((bledy + 1)); }
uwaga()    { printf '%s! %s%s\n' "$ZOLTY" "$1" "$KONIEC"; ostrzezenia=$((ostrzezenia + 1)); }
rada()     { printf '  %s%s%s\n' "$SZARY" "$1" "$KONIEC"; }

if ! command -v curl >/dev/null 2>&1; then
    printf '%sBrak curl — bez niego nic tu nie zadziała.%s\n' "$CZERWONY" "$KONIEC"
    exit 1
fi

# `--max-time` wszędzie: bez tego skrypt potrafi wisieć w nieskończoność, gdy
# domena wskazuje na adres, który po prostu nie odpowiada.
POBIERZ=(curl -q -sS --max-time 15)

# Kod i nagłówki pochodzą z jednej odpowiedzi. Nie wypisujemy nagłówków
# ani stderr curl: mogą zawierać ciasteczka. Kod curl wystarcza do diagnostyki.
pobierz_naglowki() {
    local odpowiedz url="$1"
    shift
    local -a metoda=(-I)
    # Bramka cache mierzy GET: HEAD nie dowodzi zachowania prawdziwej odsłony.
    if [ "${CACHE_GATE_GET:-0}" = 1 ]; then metoda=(-D - -o /dev/null); fi
    odpowiedz=$("${POBIERZ[@]}" "${metoda[@]}" -w $'\nKUKING_HTTP_CODE:%{http_code}\n' "$@" "$url" 2>/dev/null)
    kod_curl=$?
    odpowiedz="${odpowiedz//$'\r'/}"
    kod_odpowiedzi="${odpowiedz##*$'\n'KUKING_HTTP_CODE:}"
    naglowki_odpowiedzi="${odpowiedz%$'\n'KUKING_HTTP_CODE:*}"
    if [[ ! "$kod_odpowiedzi" =~ ^[0-9]{3}$ ]]; then
        kod_odpowiedzi="brak"
        return 1
    fi
    [ "$kod_curl" -eq 0 ] || return 1
    [[ "$naglowki_odpowiedzi" == *$'\n\n' ]] || return 1
    # CONNECT proxy i odpowiedzi informacyjne nie są nagłówkami endpointu.
    naglowki_odpowiedzi=$(awk '/^HTTP\// { block="" } { block=block $0 "\n" } END { printf "%s", block }' <<< "$naglowki_odpowiedzi")
    grep -qE "^HTTP/[^ ]+ $kod_odpowiedzi([[:space:]]|$)" <<< "$naglowki_odpowiedzi"
}

if [ "${2:-}" = '--cache-gate' ]; then
    source "$(dirname "${BASH_SOURCE[0]}")/lib/cache-gate.sh"
    cache_gate
    exit $?
fi

printf '%sSprawdzam: %s%s\n' "$SZARY" "$HOST" "$KONIEC"

# -----------------------------------------------------------------------------
naglowek "Aplikacja"
# -----------------------------------------------------------------------------

zdrowie=$("${POBIERZ[@]}" "https://$HOST/health" 2>&1)
if grep -q '"status":"ok"' <<< "$zdrowie"; then
    ok "Healthcheck: aplikacja i baza odpowiadają"
elif grep -q '"status":"degraded"' <<< "$zdrowie"; then
    blad "Healthcheck zwraca „degraded” — aplikacja żyje, ale coś w środku nie działa"
    rada "Pełna odpowiedź: $zdrowie"
else
    blad "Healthcheck nie odpowiada poprawnie"
    rada "Dostałem: ${zdrowie:0:200}"
    rada "Jeśli to błąd DNS lub TLS — domena jeszcze się nie aktywowała."
fi

kod=$("${POBIERZ[@]}" -o /dev/null -w '%{http_code}' "https://$HOST/" 2>/dev/null)
if [ "$kod" = "200" ]; then
    ok "Strona główna: HTTP 200"
else
    blad "Strona główna zwraca HTTP $kod (oczekiwano 200)"
fi

# BRAMKA — nie idziemy dalej, jeśli serwis w ogóle nie odpowiada.
#
# To nie jest kosmetyka. Każdy test niżej pyta „czy w odpowiedzi JEST coś
# złego". Gdy odpowiedzi nie ma wcale, brak złej rzeczy wygląda identycznie
# jak jej brak z powodu poprawnej konfiguracji — i skrypt melduje
# „✓ Tryb debug wyłączony" o serwisie, którego nie ma.
#
# Fałszywa zieleń w teście bezpieczeństwa jest gorsza niż brak testu: brak
# testu każe sprawdzić samemu, a fałszywa zieleń każe przestać sprawdzać.
if [ "$kod" = "000" ]; then
    printf '\n%sSerwis nie odpowiada pod adresem https://%s%s\n' "$CZERWONY" "$HOST" "$KONIEC"
    printf 'Przerywam — dalsze testy pytają o zawartość odpowiedzi, a odpowiedzi nie ma.\n'
    printf 'Zameldowałyby „w porządku" o czymś, czego nie sprawdziły.\n\n'
    rada "Najczęstsze przyczyny na tym etapie:"
    rada "  • domena nie jest jeszcze podpięta w Railway (KROK 10.1),"
    rada "  • rekord DNS nie istnieje albo się nie rozpropagował (KROK 10.2),"
    rada "  • certyfikat Cloudflare jeszcze się wystawia — potrafi potrwać kilkanaście minut."
    exit 1
fi

# -----------------------------------------------------------------------------
naglowek "HTTPS i domena"
# -----------------------------------------------------------------------------

przekierowanie=$("${POBIERZ[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' "http://$HOST/" 2>/dev/null)
kod_http="${przekierowanie%% *}"
cel="${przekierowanie#* }"
if [[ "$kod_http" =~ ^(301|308)$ ]] && [[ "$cel" == https://* ]]; then
    ok "HTTP przekierowuje na HTTPS ($kod_http)"
else
    blad "HTTP nie przekierowuje na HTTPS (dostałem: $przekierowanie)"
    rada "Cloudflare → SSL/TLS → Edge Certificates → Always Use HTTPS"
fi

www=$("${POBIERZ[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' "https://www.$HOST/" 2>/dev/null)
www_curl=$?
# Sprawdzamy jeden skok. Host to składnik URL, nie jego prefiks ani userinfo.
www_cel="${www#* }"
www_host="${www_cel,,}"
www_host="${www_host#https://}"
www_host="${www_host%%[/?#]*}"
if [[ "$www_host" =~ ^([^:@]+)(:[0-9]+)?$ ]]; then
    www_host="${BASH_REMATCH[1]}"
else
    www_host=""
fi
if [ "$www_curl" -ne 0 ]; then
    blad "Nie sprawdzono przekierowania www (curl: $www_curl). Ponów sondę."
elif [[ "${www%% *}" =~ ^(301|308)$ ]] && [[ "${www_cel,,}" == https://* ]] && [[ "$www_host" == "${HOST,,}" ]]; then
    ok "www przekierowuje na apex (${www%% *})"
else
    blad "www nie przekierowuje na apex HTTPS w jednym skoku. Sprawdź regułę przekierowania."
    rada "Cloudflare → Rules → Redirect Rules: cel https://$HOST/ (301 lub 308)."
fi

naglowki=$("${POBIERZ[@]}" -I "https://$HOST/" 2>/dev/null)

if grep -qi '^cf-ray:' <<< "$naglowki"; then
    ok "Ruch idzie przez Cloudflare (nagłówek cf-ray)"
else
    uwaga "Brak nagłówka cf-ray — ruch omija Cloudflare"
    rada "Rekord DNS musi być pomarańczowy (proxied), nie szary (DNS only)."
fi

# -----------------------------------------------------------------------------
naglowek "Bezpieczeństwo"
# -----------------------------------------------------------------------------

# NAJWAŻNIEJSZY TEST W CAŁYM SKRYPCIE.
#
# Przy APP_DEBUG=true strona błędu Laravela pokazuje zawartość zmiennych
# środowiskowych — czyli hasło do bazy, APP_KEY i klucze R2 — każdemu, kto
# wejdzie na nieistniejący adres. Nic tego nie sygnalizuje: strona główna
# działa normalnie.
strona_bledu=$("${POBIERZ[@]}" "https://$HOST/nie-ma-takiej-strony-12345" 2>/dev/null)
if [ -z "$strona_bledu" ]; then
    # Druga bramka, ta sama zasada co wyżej: pusta odpowiedź nie dowodzi, że
    # tryb debug jest wyłączony. Dowodzi tylko, że nic nie przyszło.
    uwaga "Nie udało się pobrać strony błędu — NIE potwierdziłem, że debug jest wyłączony"
    rada "Sprawdź ręcznie: curl -s https://$HOST/nie-ma-takiej-strony-12345 | head"
elif grep -qi 'ignition\|whoops\|APP_KEY\|vendor/laravel' <<< "$strona_bledu"; then
    blad "TRYB DEBUG WŁĄCZONY — strona błędu ujawnia zmienne środowiskowe"
    rada "Ustaw APP_DEBUG=false w Railway i zredeployuj. NATYCHMIAST."
    rada "Potem potraktuj wszystkie sekrety jako ujawnione i zrotuj je."
else
    ok "Tryb debug wyłączony"
fi

if grep -qi '^x-content-type-options:.*nosniff' <<< "$naglowki"; then
    ok "Nagłówek X-Content-Type-Options: nosniff"
else
    uwaga "Brak nagłówka X-Content-Type-Options"
fi

if grep -qi '^x-frame-options:' <<< "$naglowki"; then
    ok "Nagłówek X-Frame-Options obecny"
else
    uwaga "Brak nagłówka X-Frame-Options"
fi

# Ciasteczko sesji bez flagi Secure daje się przechwycić przy pierwszym
# żądaniu po http. Sprawdzamy na stronie logowania, bo tam sesja powstaje.
if [ -z "${SESSION_COOKIE:-}" ]; then
    blad "Nie sprawdzono flagi Secure. Podaj SESSION_COOKIE zgodne z konfiguracją badanego środowiska."
elif ! pobierz_naglowki "https://$HOST/login" || [ "$kod_odpowiedzi" != "200" ]; then
    blad "Nie sprawdzono flagi Secure (curl: $kod_curl, HTTP: $kod_odpowiedzi). Ponów sondę i sprawdź /login."
else
    # Nazwa jest porównywana dosłownie, bez interpolowania jej do regexu.
    znalezione=0
    niezabezpieczone=0
    while IFS= read -r naglowek_ciastka; do
        [[ "${naglowek_ciastka,,}" == set-cookie:* ]] || continue
        ciasteczko="${naglowek_ciastka#*:}"
        ciasteczko="${ciasteczko#"${ciasteczko%%[![:space:]]*}"}"
        [ "${ciasteczko%%=*}" = "$SESSION_COOKIE" ] || continue
        znalezione=$((znalezione + 1))
        if ! grep -qiE ';[[:space:]]*secure[[:space:]]*(;|$)' <<< "$ciasteczko"; then
            niezabezpieczone=$((niezabezpieczone + 1))
        fi
    done <<< "$naglowki_odpowiedzi"
    if [ "$znalezione" -eq 0 ]; then
        blad "Nie sprawdzono flagi Secure. Sprawdź SESSION_COOKIE i wystawianie sesji na /login."
    elif [ "$niezabezpieczone" -eq 0 ]; then
        ok "Ciasteczko sesji ma flagę Secure"
    else
        blad "Ciasteczko sesji BEZ flagi Secure"
        rada "Ustaw SESSION_SECURE_COOKIE=true w Railway."
    fi
fi

# -----------------------------------------------------------------------------
naglowek "Cache i CDN"
# -----------------------------------------------------------------------------

manifest=$("${POBIERZ[@]}" -I "https://$HOST/build/manifest.json" 2>/dev/null)
if grep -qi 'cache-control:.*immutable' <<< "$manifest"; then
    ok "Assety Vite cache'owane na długo (immutable)"
else
    uwaga "Assety Vite bez „immutable” w Cache-Control"
    rada "Cloudflare → Rules → Cache Rules dla /build/*"
fi

# Ten test jest ważniejszy, niż wygląda: zacache'owany endpoint Livewire
# oznacza, że jeden użytkownik dostaje odpowiedź wygenerowaną dla innego.
# Endpoint przyjmuje POST, więc na HEAD/GET odpowiada 405 — i to jest dowód,
# że istnieje. Przy 404 nie orzekamy nic: zacache'owana strona „nie znaleziono"
# ma dokładnie te same nagłówki co zacache'owany endpoint, więc test
# meldowałby awarię, której nie sprawdził.
if ! pobierz_naglowki "https://$HOST/livewire/update" || [ "$kod_odpowiedzi" != "405" ]; then
    blad "Nie sprawdzono cache Livewire (curl: $kod_curl, HTTP: $kod_odpowiedzi). Ponów sondę i sprawdź endpoint."
elif grep -qiE '^cf-cache-status:[[:space:]]*(HIT|MISS)[[:space:]]*$' <<< "$naglowki_odpowiedzi"; then
    blad "Endpoint Livewire jest cache'owany przez Cloudflare"
    rada "Dodaj Cache Rule: /livewire/* → Bypass cache."
    rada "Inaczej odpowiedź wygenerowana dla jednej osoby trafi do drugiej."
elif grep -qiE '^cf-cache-status:[[:space:]]*(BYPASS|DYNAMIC)[[:space:]]*$' <<< "$naglowki_odpowiedzi"; then
    ok "Endpoint Livewire nie jest cache'owany"
else
    blad "Nie sprawdzono cache Livewire. Sprawdź nagłówek CF-Cache-Status i regułę /livewire/* w Cloudflare."
fi

kod_cdn=$("${POBIERZ[@]}" -o /dev/null -w '%{http_code}' "https://$CDN/" 2>/dev/null)
if [ -n "$kod_cdn" ] && [ "$kod_cdn" != "000" ]; then
    ok "Domena CDN ($CDN) odpowiada — HTTP $kod_cdn"
    rada "404 przy pustym buckecie jest w porządku. Chodzi o to, że DNS i TLS działają."
else
    uwaga "Domena CDN ($CDN) nie odpowiada"
    rada "Cloudflare → R2 → bucket → Settings → Custom Domain."
fi

# -----------------------------------------------------------------------------
naglowek "Podsumowanie"
# -----------------------------------------------------------------------------

if [ "$bledy" -gt 0 ]; then
    printf '%sBłędów: %d, ostrzeżeń: %d.%s\n' "$CZERWONY" "$bledy" "$ostrzezenia" "$KONIEC"
    printf 'Błędów nie zostawiaj — każdy z nich jest widoczny dla użytkownika\n'
    printf 'albo dla kogoś, kto szuka dziury.\n'
    exit 1
fi

if [ "$ostrzezenia" -gt 0 ]; then
    printf '%sBez błędów, ostrzeżeń: %d.%s\n' "$ZOLTY" "$ostrzezenia" "$KONIEC"
    printf 'Ostrzeżenia mogą wynikać z propagacji DNS — sprawdź ponownie za kilkanaście minut.\n'
    exit 0
fi

printf '%sWszystko przeszło.%s\n' "$ZIELONY" "$KONIEC"
printf '\nZostały rzeczy, których nie da się sprawdzić z zewnątrz — zrób je ręcznie\n'
printf '(DEPLOYMENT_RUNBOOK.md, punkty 11–20):\n'
printf '  • rejestracja i e-mail aktywacyjny,\n'
printf '  • upload zdjęcia z TELEFONU (nie z komputera),\n'
printf '  • adres zdjęcia zaczyna się od https://%s/,\n' "$CDN"
printf '  • %szdjęcie nie zawiera GPS%s — exiftool <plik> | grep GPS\n' "$CZERWONY" "$KONIEC"
printf '\nPunkt ostatni nie podlega negocjacji: zdjęcie z kuchni zawiera\n'
printf 'współrzędne czyjegoś domu.\n'
printf '\nDopiero gdy to wszystko jest zielone — włącz HSTS (KROK 11.4).\n'
