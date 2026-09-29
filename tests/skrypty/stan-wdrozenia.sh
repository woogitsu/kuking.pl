#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — test jednostkowy decyzji o stanie wdrożenia
#  (scripts/ci/stan-wdrozenia.sh, #611 etap 6)
# =============================================================================
#  Skrypt decyduje, czy stan `deployment_status` to alarm (czerwony job w
#  deploy.yml). Bez sieci i bez Railway: historia statusów idzie z pliku.
#
#  Cztery części:
#   1. TABELA: stan + historia (+ czas zdarzenia) -> oczekiwany kod wyjścia
#      (0 = bez alarmu, 1 = alarm, 2 = błąd wejścia). Dwa przypadki wprost
#      z zadania: in_progress -> inactive BEZ sukcesu = alarm;
#      success -> inactive = BEZ alarmu;
#   2. KOMUNIKATY: alarm mówi po polsku, co się stało i co zrobić; wartości
#      ze zdarzenia (nazwa środowiska) nie przenoszą znaków sterujących;
#   3. BRAK PLIKU HISTORII przy `inactive` = błąd wejścia, nie cichy sukces;
#   4. KONTROLA UJEMNA: kopia skryptu z jedną zepsutą regułą MUSI obleć
#      tabelę. Mutacja, która nie trafiła albo nie zmieniła pliku, kończy
#      test błędem (PULAPKI_TESTOW §5) — no-op nie udaje kontroli.
#
#  Użycie:  bash tests/skrypty/stan-wdrozenia.sh
#  Wyjście: 0 = wszystko przechodzi, 1 = coś oblało.
# =============================================================================

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

SKRYPT="scripts/ci/stan-wdrozenia.sh"
[ -f "$SKRYPT" ] || { echo "Brak ${SKRYPT}"; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# czas N -> znacznik ISO UTC (N = sekunda; większe N = później)
czas() { printf '2026-09-29T10:00:%02dZ' "$1"; }

# uruchom SKRYPT STAN "HISTORIA" [CZAS_ZDARZENIA] -> kod wyjścia; wyjście w ${TMP}/wyj
# HISTORIA: "N:stan N:stan ..." albo "-" (brak pliku) albo "pusta" (pusty plik).
uruchom() {
    local skrypt="$1" stan="$2" hist="$3" czas_zdarzenia="${4:-}" plik="${TMP}/historia" wpis kod
    rm -f "$plik"
    case "$hist" in
        -) plik="${TMP}/nie-ma-takiego-pliku" ;;
        pusta) : > "$plik" ;;
        *)
            : > "$plik"
            for wpis in $hist; do printf '%s %s\n' "$(czas "${wpis%%:*}")" "${wpis#*:}" >> "$plik"; done
            ;;
    esac
    STAN="$stan" HISTORIA_PLIK="$plik" SRODOWISKO="production" SHA="0123456789abcdef0123456789abcdef01234567" \
        STAN_CZAS="${czas_zdarzenia:+$(czas "$czas_zdarzenia")}" bash "$skrypt" > "${TMP}/wyj" 2>&1
    kod=$?
    echo "$kod"
}

# Tabela: stan|historia|czas zdarzenia (puste = bez)|oczekiwany kod|opis
TABELA=$(cat <<'KONIEC'
inactive|1:queued 2:in_progress 3:inactive||1|in_progress -> inactive bez sukcesu = ALARM
inactive|1:in_progress 2:inactive||1|in_progress -> inactive, bez queued = ALARM
inactive|3:inactive 2:in_progress 1:queued||1|kolejność wierszy dowolna = ALARM
inactive|1:in_progress 2:success 3:inactive||0|success -> inactive (zastąpione nowszym) = BEZ alarmu
inactive|3:inactive 2:success 1:in_progress||0|success -> inactive, wiersze od najnowszego = BEZ alarmu
inactive|1:queued 2:inactive||0|zastąpione przed startem = BEZ alarmu (ostrzeżenie)
inactive|1:in_progress 2:inactive 3:success|2|1|późniejszy success nie wybiela porażki = ALARM
inactive|1:in_progress 2:success 3:inactive|3|0|success przed zdarzeniem, czas zdarzenia podany = BEZ alarmu
failure|1:in_progress 2:failure||1|failure bez sukcesu = ALARM
error|1:in_progress 2:error||1|error bez sukcesu = ALARM
failure|1:in_progress 2:success 3:failure||1|failure po sukcesie = ALARM (wersja, która stała, padła)
error|1:queued 2:error||1|error bez startu = ALARM
failure|-||1|failure nie potrzebuje historii = ALARM
inactive|-||2|inactive bez pliku historii = błąd wejścia
inactive|pusta||2|inactive z pustą historią = błąd wejścia
success|1:in_progress 2:success||0|success — ten job nie ocenia
in_progress|1:in_progress||0|in_progress — nie terminalny
queued|1:queued||0|queued — nie terminalny
pending|1:pending||0|pending — nie terminalny
KONIEC
)

# Śmieciowy stan zdarzenia: błąd wejścia (osobno, bo `stan` z pustego i obcego pola).
TABELA_STANOW=$(cat <<'KONIEC'
removed
|
Success
inactive; touch /tmp/x
KONIEC
)

# sprawdz_tabele SKRYPT -> na stdout liczba oblanych wierszy
sprawdz_tabele() {
    local skrypt="$1" stan hist czas_zd oczek opis fakt zle=0 s
    while IFS='|' read -r stan hist czas_zd oczek opis; do
        [ -n "$stan" ] || continue
        fakt="$(uruchom "$skrypt" "$stan" "$hist" "$czas_zd")"
        [ "$fakt" = "$oczek" ] || { zle=$((zle + 1)); echo "  OBLANE: ${opis} (kod ${fakt}, oczekiwano ${oczek})" >&2; }
    done <<< "$TABELA"
    while IFS= read -r s; do
        fakt="$(STAN="$s" HISTORIA_PLIK="${TMP}/nie-ma" bash "$skrypt" >/dev/null 2>&1; echo $?)"
        [ "$fakt" = 2 ] || { zle=$((zle + 1)); echo "  OBLANE: śmieciowy stan '${s}' dał kod ${fakt}, oczekiwano 2" >&2; }
    done <<< "$TABELA_STANOW"
    echo "$zle"
}

# --- 1. Tabela ---------------------------------------------------------------
zle="$(sprawdz_tabele "$SKRYPT")"
[ "$zle" = 0 ] || { echo "Tabela stanów wdrożenia: ${zle} oblanych wierszy"; exit 1; }
echo "Tabela: wszystkie przypadki zgodne"

# --- 2. Komunikaty -----------------------------------------------------------
uruchom "$SKRYPT" inactive "1:in_progress 2:inactive" >/dev/null
grep -q '^::error title=Wdrożenie zniknęło bez sukcesu::' "${TMP}/wyj" || { echo "Alarm inactive nie ma polskiego tytułu"; exit 1; }
grep -q 'Sprawdź w panelu Railway' "${TMP}/wyj" || { echo "Alarm inactive nie mówi, co zrobić"; exit 1; }
grep -q 'wersja 0123456789ab' "${TMP}/wyj" || { echo "Alarm nie podaje skróconego SHA"; exit 1; }
uruchom "$SKRYPT" failure - >/dev/null
grep -q '^::error title=Wdrożenie zakończone porażką::' "${TMP}/wyj" || { echo "Alarm failure nie ma polskiego tytułu"; exit 1; }
uruchom "$SKRYPT" inactive "1:in_progress 2:success 3:inactive" >/dev/null
grep -q '^::error' "${TMP}/wyj" && { echo "success -> inactive nie może wypisać ::error"; exit 1; }
# Nazwa środowiska ze znakiem nowej linii nie trafia do komunikatu.
STAN=failure SRODOWISKO=$'production\n::error::podszyte' SHA=abc1234 bash "$SKRYPT" > "${TMP}/wyj" 2>&1
[ "$(grep -c '^::error' "${TMP}/wyj")" = 1 ] || { echo "Nazwa środowiska z nową linią wstrzyknęła drugie polecenie"; exit 1; }
echo "Komunikaty: po polsku, z instrukcją, bez wstrzyknięć"

# --- 3. Brak pliku historii ----------------------------------------------------
[ "$(uruchom "$SKRYPT" inactive -)" = 2 ] || { echo "Brak pliku historii nie dał kodu 2"; exit 1; }
grep -q '^::error title=Brak historii wdrożenia::' "${TMP}/wyj" || { echo "Brak historii bez komunikatu"; exit 1; }
printf 'to nie jest wiersz historii\n' > "${TMP}/zla"
STAN=inactive HISTORIA_PLIK="${TMP}/zla" bash "$SKRYPT" >/dev/null 2>&1
[ "$?" = 2 ] || { echo "Wiersz historii o złym kształcie nie dał kodu 2"; exit 1; }
echo "Brak i zła historia: kod 2, nie cichy sukces"

# --- 4. Kontrola ujemna ----------------------------------------------------------
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

mutuj "success -> inactive alarmuje" 'if [ "$byl_sukces" -eq 1 ]; then' 'if [ "$byl_sukces" -eq 9 ]; then' || exit 1
mutuj "inactive po in_progress nie alarmuje" 'if [ "$byl_start" -eq 1 ]; then' 'if [ "$byl_start" -eq 9 ]; then' || exit 1
mutuj "failure/error nie alarmuje" 'if [ "$stan" = failure ] || [ "$stan" = error ]; then' 'if [ "$stan" = failure ] && [ "$stan" = error ]; then' || exit 1
mutuj "późny success wybiela" '[[ "$ts" > "$czas" ]]' '[[ "$ts" > "9999" ]]' || exit 1
mutuj "brak historii = sukces" '  exit 2
fi

byl_sukces=0' '  exit 0
fi

byl_sukces=0' || exit 1
mutuj "pusta historia = sukces" 'if [ "$policzone" -eq 0 ]; then' 'if [ "$policzone" -eq 99 ]; then' || exit 1
mutuj "przed startem alarmuje" 'to nie jest alarm."
exit 0' 'to nie jest alarm."
exit 1' || exit 1

echo "Kontrola ujemna: wszystkie reguły pilnowane"
echo "Stan wdrożenia: OK"
