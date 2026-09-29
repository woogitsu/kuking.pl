#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — test jednostkowy bramki testu dymnego preview
#  (scripts/ci/preview-bramka.sh, #611 etap 7)
# =============================================================================
#  Skrypt decyduje, czy `smoke` w preview.yml ma chodzić, i ZAWSZE zostawia
#  powód (podsumowanie joba + wyjścia), zamiast cicho pomijać job.
#
#  Cztery części:
#   1. TABELA: zdarzenie + wartość zmiennej -> kod wyjścia, uruchom, poziom
#      adnotacji (notice/warning/error);
#   2. UZASADNIENIE: pominięcie zapisuje powód do GITHUB_STEP_SUMMARY i do
#      GITHUB_OUTPUT (`powod`), po polsku, z instrukcją włączenia;
#   3. WARTOŚĆ ZMIENNEJ nie trafia do komunikatu (tylko długość);
#   4. KONTROLA UJEMNA: kopia skryptu z jedną zepsutą regułą MUSI obleć
#      tabelę. Mutacja, która nie trafiła albo nie zmieniła pliku, kończy
#      test błędem (PULAPKI_TESTOW §5) — no-op nie udaje kontroli.
#
#  Użycie:  bash tests/skrypty/preview-bramka.sh
#  Wyjście: 0 = wszystko przechodzi, 1 = coś oblało.
# =============================================================================

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

SKRYPT="scripts/ci/preview-bramka.sh"
[ -f "$SKRYPT" ] || { echo "Brak ${SKRYPT}"; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# uruchom SKRYPT ZDARZENIE FLAGA -> "kod uruchom poziom"; pełne wyjścia w ${TMP}/{wyj,out,sum}
# FLAGA "-" = zmienna nieustawiona (pusta); ZDARZENIE "-" = brak.
uruchom() {
    local skrypt="$1" zd="$2" flaga="$3" kod uruchom poziom
    [ "$zd" = - ] && zd=""
    [ "$flaga" = - ] && flaga=""
    : > "${TMP}/out"; : > "${TMP}/sum"
    ZDARZENIE="$zd" FLAGA="$flaga" GITHUB_OUTPUT="${TMP}/out" GITHUB_STEP_SUMMARY="${TMP}/sum" \
        bash "$skrypt" > "${TMP}/wyj" 2>&1
    kod=$?
    uruchom="$(sed -n 's/^uruchom=//p' "${TMP}/out")"
    poziom="$(sed -n 's/^::\(notice\|warning\|error\) .*/\1/p' "${TMP}/wyj" | head -n1)"
    echo "${kod} ${uruchom:--} ${poziom:--}"
}

# zdarzenie|flaga|oczekiwane "kod uruchom poziom"|opis
TABELA=$(cat <<'KONIEC'
pull_request|true|0 true notice|flaga true = uruchom
pull_request|-|0 false notice|zmienna nieustawiona = pominięcie z powodem (nie cicho)
pull_request|false|0 false notice|jawne false = pominięcie z powodem
pull_request|TRUE|0 false warning|TRUE (zła wielkość liter) = pominięcie GŁOŚNE
pull_request|1|0 false warning|1 = pominięcie GŁOŚNE
pull_request|yes|0 false warning|yes = pominięcie GŁOŚNE
pull_request|true |0 false warning|true ze spacją = pominięcie GŁOŚNE
workflow_dispatch|true|0 false notice|nie pull_request = nie dotyczy, nawet przy true
push|-|0 false notice|push bez flagi = nie dotyczy
-|true|2 - error|brak zdarzenia = błąd wejścia
KONIEC
)

# sprawdz_tabele SKRYPT -> na stdout liczba oblanych wierszy
sprawdz_tabele() {
    local skrypt="$1" zd flaga oczek opis fakt zle=0
    while IFS='|' read -r zd flaga oczek opis; do
        [ -n "$zd" ] || continue
        fakt="$(uruchom "$skrypt" "$zd" "$flaga")"
        [ "$fakt" = "$oczek" ] || { zle=$((zle + 1)); echo "  OBLANE: ${opis} (fakt '${fakt}', oczekiwano '${oczek}')" >&2; }
    done <<< "$TABELA"
    echo "$zle"
}

# --- 1. Tabela ---------------------------------------------------------------
zle="$(sprawdz_tabele "$SKRYPT")"
[ "$zle" = 0 ] || { echo "Tabela bramki preview: ${zle} oblanych wierszy"; exit 1; }
echo "Tabela: wszystkie przypadki zgodne"

# --- 2. Uzasadnienie pominięcia ---------------------------------------------
uruchom "$SKRYPT" pull_request - >/dev/null
grep -q '^powod=.*POMINIĘTY.*KUKING_DEPLOY_ENABLED' "${TMP}/out" || { echo "Wyjście powod nie tłumaczy pominięcia"; exit 1; }
grep -q 'Decyzja: \*\*pominięty\*\*' "${TMP}/sum" || { echo "Podsumowanie joba nie mówi, że test pominięto"; exit 1; }
grep -q 'Powód: .*nic nie sprawdzono\|Powód: .*nie jest zielony' "${TMP}/sum" || { echo "Podsumowanie nie mówi, że to nie jest zielony wynik"; exit 1; }
grep -q 'Variables → KUKING_DEPLOY_ENABLED = true' "${TMP}/sum" || { echo "Podsumowanie nie mówi, jak włączyć"; exit 1; }
grep -q '^::notice title=Preview pominięty::' "${TMP}/wyj" || { echo "Brak polskiej adnotacji o pominięciu"; exit 1; }
uruchom "$SKRYPT" pull_request true >/dev/null
grep -q 'Decyzja: \*\*uruchomiony\*\*' "${TMP}/sum" || { echo "Podsumowanie nie mówi, że test uruchomiono"; exit 1; }
# Poza Actions (bez plików wyjścia) skrypt też działa.
ZDARZENIE=pull_request FLAGA=true env -u GITHUB_OUTPUT -u GITHUB_STEP_SUMMARY bash "$SKRYPT" >/dev/null 2>&1 \
  || { echo "Skrypt bez GITHUB_OUTPUT/GITHUB_STEP_SUMMARY nie działa"; exit 1; }
echo "Uzasadnienie: powód w wyjściu, podsumowaniu i adnotacji"

# --- 3. Wartość zmiennej nie wycieka do komunikatu --------------------------
uruchom "$SKRYPT" pull_request $'zle\n::error::podszyte' >/dev/null
[ "$(grep -c '^::error' "${TMP}/wyj")" = 0 ] || { echo "Wartość zmiennej z nową linią wstrzyknęła polecenie"; exit 1; }
grep -q 'podszyte' "${TMP}/wyj" && { echo "Wartość zmiennej trafiła do komunikatu"; exit 1; }
uruchom "$SKRYPT" $'push\n::error::x' true >/dev/null
[ "$(grep -c '^::error' "${TMP}/wyj")" = 0 ] || { echo "Nazwa zdarzenia z nową linią wstrzyknęła polecenie"; exit 1; }
echo "Wstrzyknięcia: brak"

# --- 4. Kontrola ujemna ----------------------------------------------------
# mutuj OPIS STARY NOWY — STARY podmieniany dosłownie (pierwsze wystąpienie).
mutuj() {
    local opis="$1" stary="$2" nowy="$3" tresc kopia="${TMP}/mutant.sh" zle
    tresc="$(cat "$SKRYPT"; echo x)"
    tresc="${tresc%x}"
    case "$tresc" in
        *"$stary"*) ;;
        *) echo "MUTACJA-NIE-TRAFILA (${opis}): brak fragmentu w ${SKRYPT}"; return 1 ;;
    esac
    printf '%s' "${tresc/"$stary"/"$nowy"}" > "$kopia"
    if cmp -s "$SKRYPT" "$kopia"; then echo "MUTACJA-NO-OP (${opis})"; return 1; fi
    zle="$(sprawdz_tabele "$kopia" 2>/dev/null)"
    if [ "$zle" = 0 ]; then
        echo "KONTROLA-UJEMNA-NIE-ZAPALILA (${opis}): tabela przeszła na zepsutym skrypcie"
        return 1
    fi
    echo "  ${opis}: mutacja zapaliła tabelę (${zle} oblanych wierszy)"
}

mutuj "flaga true nie uruchamia" 'elif [ "$flaga" = true ]; then' 'elif [ "$flaga" = zawsze-nie ]; then' || exit 1
mutuj "brak flagi uruchamia" 'elif [ -z "$flaga" ]; then' 'elif [ -n "$flaga" ]; then
  uruchom=true
elif [ -z "$flaga" ]; then' || exit 1
mutuj "zła wartość bez ostrzeżenia" 'poziom=warning' 'poziom=notice' || exit 1
mutuj "inne zdarzenie niż PR uruchamia" 'if [ "$zdarzenie" != pull_request ]; then' 'if [ "$zdarzenie" = nic ]; then' || exit 1
mutuj "brak zdarzenia = sukces" '  exit 2
fi

uruchom=false' '  exit 0
fi

uruchom=false' || exit 1
mutuj "pominięcie bez wyjścia uruchom" 'echo "uruchom=${uruchom}"' 'echo "uruchom=true"' || exit 1

echo "Kontrola ujemna: wszystkie reguły pilnowane"
echo "Bramka preview: OK"
