#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — test jednostkowy bramki `zakres` (scripts/ci/zakres.sh, #611)
# =============================================================================
#  Bramka to skrypt, który z listy zmienionych plików wylicza sześć wyjść
#  joba `zakres`: kod, widok, dokumenty, obraz, obciazenie, wyscigi. Ten test
#  puszcza PRAWDZIWY skrypt (tryb testowy ZAKRES_LISTA_PLIK — lista z pliku
#  zamiast z polecenia diff) na tabeli przypadków i porównuje wszystkie
#  sześć wyjść naraz.
#
#  Cztery części:
#   1. TABELA: zdarzenie + lista plików -> oczekiwane sześć wyjść;
#   2. ŚCIEŻKI BEZ LISTY Z PLIKU: brak bazy i nieosiągalna baza dają pełny
#      zestaw (baza = HEAD, czyli pusty diff -> same `false`, sprawdza
#      `tests/Feature/BramkaZakresuSkryptTest.php`);
#   3. DUŻY DIFF: lista 450 kB nie gubi trafienia przez SIGPIPE;
#   4. KONTROLA UJEMNA NA KAŻDYM Z SZEŚCIU WYJŚĆ: kopia skryptu z jednym
#      zepsutym wyjściem MUSI obleć tabelę. Mutacja, która nie trafiła albo
#      nie zmieniła pliku, kończy test błędem (PULAPKI_TESTOW §5) — no-op nie
#      udaje kontroli. Kontrola dodatnia: tabela przechodzi na skrypcie
#      nietkniętym (część 1).
#
#  Użycie:  bash tests/skrypty/zakres.sh
#  Wyjście: 0 = wszystko przechodzi, 1 = coś oblało.
# =============================================================================

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

SKRYPT="scripts/ci/zakres.sh"
[ -f "$SKRYPT" ] || { echo "Brak ${SKRYPT}"; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

KLUCZE="kod widok dokumenty obraz obciazenie wyscigi"

# wyjscia SKRYPT ZDARZENIE PLIK... -> "kod=t widok=f ..." (pierwsza litera wartości)
wyjscia() {
    local skrypt="$1" zdarzenie="$2" lista="${TMP}/lista" wyjscie="${TMP}/wyjscie" k v wynik="" kod_wyjscia
    shift 2
    : > "$lista"
    for plik in "$@"; do printf '%s\n' "$plik" >> "$lista"; done
    : > "$wyjscie"
    if [ -n "$zdarzenie" ]; then
        ZDARZENIE="$zdarzenie" GITHUB_OUTPUT="$wyjscie" ZAKRES_LISTA_PLIK="$lista" bash "$skrypt" >/dev/null 2>&1
    else
        env -u ZDARZENIE GITHUB_OUTPUT="$wyjscie" ZAKRES_LISTA_PLIK="$lista" bash "$skrypt" >/dev/null 2>&1
    fi
    kod_wyjscia=$?
    [ "$kod_wyjscia" -eq 0 ] || { echo "SKRYPT-PADL(${kod_wyjscia})"; return; }
    for k in $KLUCZE; do
        # Wyjście ma być zapisane dokładnie raz — brak albo dubel to błąd.
        [ "$(grep -c "^${k}=" "$wyjscie")" = 1 ] || { echo "WYJSCIE-${k}-NIE-RAZ"; return; }
        v="$(grep "^${k}=" "$wyjscie" | cut -d= -f2)"
        wynik="${wynik}${k}=${v:0:1} "
    done
    echo "${wynik% }"
}

# Tabela: zdarzenie|pliki (spacją)|oczekiwane wyjścia (t/f).
# Kolejność: kod widok dokumenty obraz obciazenie wyscigi.
# Wartości wyliczone na wersji sprzed wyniesienia skryptu (różnicowo: 2187
# porównań starego i nowego skryptu, jedyna różnica to `scripts/ci/`)
# i przejrzane ręcznie.
TABELA=$(cat <<'KONIEC'
pull_request|docs/PRODUCT.md|fftfff
pull_request|README.md|fftfff
pull_request|docs/A.md docs/B.md|fftfff
pull_request|docs/DEPLOYMENT.md|tftfff
pull_request|docs/design/references/wzorzec.html|tttfff
pull_request|app/Models/Recipe.php|ttffft
pull_request|app/Providers/AppServiceProvider.php|ttftft
pull_request|routes/web.php|ttffft
pull_request|resources/css/app.css|ttffff
pull_request|tests/Feature/ZmianaObokTest.php|tfffff
pull_request|tests/Dwa/Scenariusz.php|tfffft
pull_request|scripts/dostepnosc.mjs|ttffff
pull_request|scripts/przegladarka/kolaz-lcp.test.mjs|ttffff
pull_request|scripts/generator-obciazenia-605.mjs|tffftf
pull_request|scripts/fixtures/obciazenie605/dane.json|ttfftf
pull_request|scripts/testy-dwa-polaczenia.sh|tfffft
pull_request|scripts/kontrola-negatywna-2402.py|tfffft
pull_request|scripts/kontrola-negatywna-2403.py|tfffft
pull_request|scripts/kontrola-negatywna-2404.py|tfffft
pull_request|Dockerfile|tfftff
pull_request|composer.lock|ttftft
pull_request|pint.json|tfffff
pull_request|docker/Caddyfile|ttftff
pull_request|.github/workflows/ci.yml|ttfttt
pull_request|.github/actions/php/action.yml|ttffft
pull_request|scripts/ci/zakres.sh|ttfttt
pull_request|scripts/ci/nowy-krok.sh|ttfttt
pull_request|docs/PRODUCT.md app/Models/Recipe.php|tttfft
push|docs/PRODUCT.md|fttttt
push|tests/Feature/ZmianaObokTest.php|ttfttt
push|pint.json|ttfttt
push|scripts/ci/zakres.sh|ttfttt
KONIEC
)

# sprawdz_tabele SKRYPT [glosno] -> na stdout liczba oblanych wierszy
sprawdz_tabele() {
    local skrypt="$1" zdarzenie pliki oczekiwane fakt oczek k i zle=0
    while IFS='|' read -r zdarzenie pliki oczekiwane; do
        [ -n "$zdarzenie" ] || continue
        # shellcheck disable=SC2086
        fakt="$(wyjscia "$skrypt" "$zdarzenie" $pliki)"
        i=0
        oczek=""
        for k in $KLUCZE; do
            oczek="${oczek}${k}=${oczekiwane:$i:1} "
            i=$((i + 1))
        done
        oczek="${oczek% }"
        if [ "$fakt" != "$oczek" ]; then
            zle=$((zle + 1))
            if [ "${2:-}" = "glosno" ]; then
                echo "  OBLANE: [${zdarzenie}] ${pliki} — jest: ${fakt}; ma być: ${oczek}" >&2
            fi
        fi
    done <<< "$TABELA"
    echo "$zle"
}

# --- 1. Tabela ------------------------------------------------------------------
liczba_wierszy="$(printf '%s\n' "$TABELA" | grep -c '|')"
# Zero albo garść wierszy to pomyłka przyrządu, nie sukces (PULAPKI_TESTOW §2).
[ "$liczba_wierszy" -ge 16 ] || { echo "Tabela ma tylko ${liczba_wierszy} wierszy — pomyłka w przyrządzie"; exit 1; }
zle="$(sprawdz_tabele "$SKRYPT" glosno)"
if [ "$zle" != 0 ]; then
    echo "Bramka zakres: ${zle} z ${liczba_wierszy} przypadków tabeli oblało"
    exit 1
fi
echo "Tabela: ${liczba_wierszy} przypadków zgodnych"

# --- 2. Ścieżki bez listy z pliku ------------------------------------------------
pelny="kod=true widok=true dokumenty=true obraz=true obciazenie=true wyscigi=true"

odczyt_wyjsc() {
    local w="$1" k wynik=""
    for k in $KLUCZE; do wynik="${wynik}${k}=$(grep "^${k}=" "$w" | cut -d= -f2) "; done
    echo "${wynik% }"
}

# baza_daje NAZWA BAZA OCZEKIWANE — skrypt bez trybu testowego, z podaną bazą.
baza_daje() {
    local nazwa="$1" baza="$2" oczekiwane="$3" w="${TMP}/wb"
    : > "$w"
    env -u ZAKRES_LISTA_PLIK ZDARZENIE=pull_request BAZA="$baza" GITHUB_OUTPUT="$w" bash "$SKRYPT" >/dev/null 2>&1 \
        || { echo "Skrypt padł: ${nazwa}"; exit 1; }
    [ "$(odczyt_wyjsc "$w")" = "$oczekiwane" ] || { echo "Zły wynik: ${nazwa}"; exit 1; }
}

baza_daje "pusta baza" "" "$pelny"
baza_daje "baza z samych zer" "0000000000000000000000000000000000000000" "$pelny"
baza_daje "nieosiągalna baza" "1111111111111111111111111111111111111111" "$pelny"
echo "Ścieżki bez listy: pełny zestaw przy braku i nieosiągalności bazy"
# Ścieżkę z prawdziwą bazą (pusty diff = same false) sprawdza
# `BramkaZakresuSkryptTest`.

# --- 3. Duży diff (SIGPIPE) ------------------------------------------------------
{ echo "app/Livewire/RecipeWizard.php"; for _ in $(seq 1 10000); do echo "docs/dlugi-niezmieniajacy-interfejsu-opis.md"; done; } > "${TMP}/duza"
: > "${TMP}/w5"
ZDARZENIE=pull_request GITHUB_OUTPUT="${TMP}/w5" ZAKRES_LISTA_PLIK="${TMP}/duza" bash "$SKRYPT" >/dev/null 2>&1 \
    || { echo "Skrypt padł na dużej liście"; exit 1; }
[ "$(grep -c '^widok=true$' "${TMP}/w5")" = 1 ] || { echo "Duża lista: widok=true zgubiony (SIGPIPE?)"; exit 1; }
echo "Duży diff: widok=true nie ginie"

# --- 4. Kontrola ujemna na każdym z sześciu wyjść --------------------------------
# mutuj WYJŚCIE STARY NOWY — STARY podmieniany dosłownie (pierwsze wystąpienie).
mutuj() {
    local wyjscie="$1" stary="$2" nowy="$3" tresc kopia="${TMP}/zakres-mutant.sh" zle
    tresc="$(cat "$SKRYPT"; echo x)"
    tresc="${tresc%x}"
    case "$tresc" in
        *"$stary"*) ;;
        *) echo "MUTACJA-NIE-TRAFILA (${wyjscie}): brak fragmentu w ${SKRYPT}"; return 1 ;;
    esac
    printf '%s' "${tresc/"$stary"/"$nowy"}" > "$kopia"
    if cmp -s "$SKRYPT" "$kopia"; then echo "MUTACJA-NO-OP (${wyjscie})"; return 1; fi
    zle="$(sprawdz_tabele "$kopia")"
    if [ "$zle" = 0 ]; then
        echo "KONTROLA-UJEMNA-NIE-ZAPALILA (${wyjscie}): tabela przeszła na zepsutym skrypcie"
        return 1
    fi
    echo "  wyjście ${wyjscie}: mutacja zapaliła tabelę (${zle} oblanych wierszy)"
}

mutuj kod 'if [ -z "${POZA}" ]; then' 'if [ -n "${POZA}" ]; then' || exit 1
mutuj widok "grep -qE '^(app/|routes/|config/|" "grep -qE '^(routes/|config/|" || exit 1
mutuj dokumenty '[ "${POZA}" != "${ZMIENIONE}" ]' '[ "${POZA}" = "${ZMIENIONE}" ]' || exit 1
mutuj obraz "ciezki obraz '^(Dockerfile\$|" "ciezki obraz '^(" || exit 1
mutuj obciazenie "ciezki obciazenie '^(scripts/" "ciezki obciazenie '^(|scripts/" || exit 1
mutuj obciazenie 'scripts/fixtures/obciazenie605/|' '' || exit 1
mutuj wyscigi 'tests/(Dwa/|Support/|' 'tests/(Support/|' || exit 1
mutuj wyscigi '240[234]' '9999' || exit 1

echo "Kontrola ujemna: wszystkie sześć wyjść pilnowanych"
echo "Bramka zakres: OK"
