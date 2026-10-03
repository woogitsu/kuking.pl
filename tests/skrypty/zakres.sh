#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — test jednostkowy bramki `zakres` (scripts/ci/zakres.sh, #611)
# =============================================================================
#  Bramka to skrypt, który z listy zmienionych plików wylicza siedem wyjść
#  joba `zakres`: kod, widok, dokumenty, obraz, obciazenie, wyscigi i (od
#  2.10.2026) pelny — `false` tylko na draft PR-ze. Ten test
#  puszcza PRAWDZIWY skrypt (tryb testowy ZAKRES_LISTA_PLIK — lista z pliku
#  zamiast z polecenia diff) na tabeli przypadków i porównuje wszystkie
#  siedem wyjść naraz.
#
#  Cztery części:
#   1. TABELA: zdarzenie (z opcjonalnym `@draft`/`@nie-draft`) + lista plików
#      -> oczekiwane siedem wyjść;
#   2. ŚCIEŻKI BEZ LISTY Z PLIKU: brak bazy i nieosiągalna baza dają pełny
#      zestaw (baza = HEAD, czyli pusty diff -> same `false`, sprawdza
#      `tests/Feature/BramkaZakresuSkryptTest.php`);
#   3. DUŻY DIFF: lista 450 kB nie gubi trafienia przez SIGPIPE;
#   4. KONTROLA UJEMNA NA KAŻDYM Z SIEDMIU WYJŚĆ: kopia skryptu z jednym
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

KLUCZE="kod widok dokumenty obraz obciazenie wyscigi pelny"

# wyjscia SKRYPT ZDARZENIE PLIK... -> "kod=t widok=f ..." (pierwsza litera wartości)
# ZDARZENIE może mieć przyrostek `@draft` (DRAFT=true) albo `@nie-draft`
# (DRAFT=false); bez przyrostka zmiennej DRAFT w środowisku NIE MA.
wyjscia() {
    local skrypt="$1" zdarzenie="$2" lista="${TMP}/lista" wyjscie="${TMP}/wyjscie" k v wynik="" kod_wyjscia draft=""
    shift 2
    case "$zdarzenie" in
        *@draft) draft=true; zdarzenie="${zdarzenie%@draft}" ;;
        *@nie-draft) draft=false; zdarzenie="${zdarzenie%@nie-draft}" ;;
    esac
    : > "$lista"
    for plik in "$@"; do printf '%s\n' "$plik" >> "$lista"; done
    : > "$wyjscie"
    if [ -n "$zdarzenie" ] && [ -n "$draft" ]; then
        env -u DRAFT ZDARZENIE="$zdarzenie" DRAFT="$draft" GITHUB_OUTPUT="$wyjscie" ZAKRES_LISTA_PLIK="$lista" bash "$skrypt" >/dev/null 2>&1
    elif [ -n "$zdarzenie" ]; then
        env -u DRAFT ZDARZENIE="$zdarzenie" GITHUB_OUTPUT="$wyjscie" ZAKRES_LISTA_PLIK="$lista" bash "$skrypt" >/dev/null 2>&1
    else
        env -u ZDARZENIE -u DRAFT GITHUB_OUTPUT="$wyjscie" ZAKRES_LISTA_PLIK="$lista" bash "$skrypt" >/dev/null 2>&1
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
# Kolejność: kod widok dokumenty obraz obciazenie wyscigi pelny.
# Wartości wyliczone na wersji sprzed wyniesienia skryptu (różnicowo: 2187
# porównań starego i nowego skryptu, jedyna różnica to `scripts/ci/`)
# i przejrzane ręcznie.
TABELA=$(cat <<'KONIEC'
pull_request|docs/PRODUCT.md|fftffft
pull_request|README.md|fftffft
pull_request|docs/A.md docs/B.md|fftffft
pull_request|docs/DEPLOYMENT.md|tftffft
pull_request|docs/design/references/wzorzec.html|tttffft
pull_request|app/Models/Recipe.php|ttffftt
pull_request|app/Providers/AppServiceProvider.php|ttftftt
pull_request|routes/web.php|ttffftt
pull_request|resources/css/app.css|ttfffft
pull_request|tests/Feature/ZmianaObokTest.php|tffffft
pull_request|tests/Dwa/Scenariusz.php|tfffftt
pull_request|scripts/dostepnosc.mjs|ttfffft
pull_request|scripts/przegladarka/kolaz-lcp.test.mjs|ttfffft
pull_request|scripts/generator-obciazenia-605.mjs|tffftft
pull_request|scripts/fixtures/obciazenie605/dane.json|ttfftft
pull_request|scripts/testy-dwa-polaczenia.sh|tfffftt
pull_request|scripts/kontrola_przyczyny.py|tfffftt
pull_request|scripts/zawezenie_testow.py|tfffftt
pull_request|scripts/kontrola-negatywna-2402.py|tfffftt
pull_request|scripts/kontrola-negatywna-2403.py|tfffftt
pull_request|scripts/kontrola-negatywna-2404.py|tfffftt
pull_request|scripts/kontrola-negatywna-2427.py|tfffftt
pull_request|scripts/kontrola-negatywna-2437.py|tfffftt
pull_request|scripts/kontrola-negatywna-2551.py|tfffftt
pull_request|Dockerfile|tfftfft
pull_request|composer.lock|ttftftt
pull_request|pint.json|tffffft
pull_request|docker/Caddyfile|ttftfft
pull_request|.github/workflows/ci.yml|ttftttt
pull_request|.github/actions/php/action.yml|ttffftt
pull_request|scripts/ci/zakres.sh|ttftttt
pull_request|scripts/ci/nowy-krok.sh|ttftttt
pull_request|docs/PRODUCT.md app/Models/Recipe.php|tttfftt
push|docs/PRODUCT.md|ftttttt
push|tests/Feature/ZmianaObokTest.php|ttftttt
push|pint.json|ttftttt
push|scripts/ci/zakres.sh|ttftttt
pull_request@draft|app/Models/Recipe.php|ttffftf
pull_request@draft|.github/workflows/ci.yml|ttftttf
pull_request@draft|docs/PRODUCT.md|fftffff
pull_request@nie-draft|app/Models/Recipe.php|ttffftt
pull_request@nie-draft|.github/workflows/ci.yml|ttftttt
push@draft|app/Models/Recipe.php|ttftttt
workflow_dispatch@draft|app/Models/Recipe.php|ttftttt
workflow_dispatch|docs/PRODUCT.md|ftttttt
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
pelny="kod=true widok=true dokumenty=true obraz=true obciazenie=true wyscigi=true pelny=true"
pelny_draft="kod=true widok=true dokumenty=true obraz=true obciazenie=true wyscigi=true pelny=false"

odczyt_wyjsc() {
    local w="$1" k wynik=""
    for k in $KLUCZE; do wynik="${wynik}${k}=$(grep "^${k}=" "$w" | cut -d= -f2) "; done
    echo "${wynik% }"
}

# baza_daje NAZWA BAZA OCZEKIWANE [DRAFT] — skrypt bez trybu testowego, z podaną bazą.
baza_daje() {
    local nazwa="$1" baza="$2" oczekiwane="$3" draft="${4:-}" w="${TMP}/wb"
    : > "$w"
    env -u ZAKRES_LISTA_PLIK -u DRAFT ${draft:+DRAFT="$draft"} ZDARZENIE=pull_request BAZA="$baza" GITHUB_OUTPUT="$w" bash "$SKRYPT" >/dev/null 2>&1 \
        || { echo "Skrypt padł: ${nazwa}"; exit 1; }
    # Każde wyjście dokładnie raz — także `pelny` na ścieżce wczesnego wyjścia.
    for k in $KLUCZE; do
        [ "$(grep -c "^${k}=" "$w")" = 1 ] || { echo "Wyjście ${k} nie raz: ${nazwa}"; exit 1; }
    done
    [ "$(odczyt_wyjsc "$w")" = "$oczekiwane" ] || { echo "Zły wynik: ${nazwa}"; exit 1; }
}

baza_daje "pusta baza" "" "$pelny"
baza_daje "baza z samych zer" "0000000000000000000000000000000000000000" "$pelny"
baza_daje "nieosiągalna baza" "1111111111111111111111111111111111111111" "$pelny"
# Draft bez punktu odniesienia: ciężkie wyjścia pełne, ale `pelny=false`.
baza_daje "pusta baza na drafcie" "" "$pelny_draft" true
baza_daje "nieosiągalna baza na drafcie" "1111111111111111111111111111111111111111" "$pelny_draft" true
baza_daje "pusta baza, PR nie-draft" "" "$pelny" false
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
mutuj wyscigi '|2427' '' || exit 1
mutuj wyscigi '|2437' '' || exit 1
mutuj wyscigi 'kontrola_przyczyny\.py|' '' || exit 1
mutuj wyscigi 'zawezenie_testow\.py|' '' || exit 1

# `pelny` (2.10.2026): odwrócony warunek, zgubione sprawdzenie zdarzenia
# (push z DRAFT=true musiałby dać pełny) i zgubione sprawdzenie draftu.
mutuj pelny '[ "${DRAFT:-}" = "true" ]; then' '[ "${DRAFT:-}" != "true" ]; then' || exit 1
mutuj pelny 'if [ "${ZDARZENIE:-}" = "pull_request" ] && [ "${DRAFT:-}"' 'if [ "${DRAFT:-}"' || exit 1
mutuj pelny '[ "${ZDARZENIE:-}" = "pull_request" ] && [ "${DRAFT:-}" = "true" ]' '[ "${ZDARZENIE:-}" = "pull_request" ]' || exit 1

echo "Kontrola ujemna: wszystkie siedem wyjść pilnowanych"
echo "Bramka zakres: OK"
