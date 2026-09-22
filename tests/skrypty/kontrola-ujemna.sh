#!/usr/bin/env bash
# =============================================================================
#  Kontrola ujemna PRZYRZĄDU do kontroli ujemnych (scripts/kontrola-ujemna.sh)
# =============================================================================
#
#  Przyrząd, który pilnuje, żeby kontrola ujemna nie była no-opem, sam bez
#  kontroli ujemnej byłby dokładnie tym, co naprawia: narzędziem, które melduje
#  sukces, nie zrobiwszy nic. Ten skrypt podaje mu sześć sytuacji i sprawdza,
#  że każda kończy się WŁAŚCIWYM werdyktem — w tym dwie, w których przyrząd
#  ma ODMÓWIĆ.
#
#  Najważniejsza jest próba 1: mutacja, która NIE TRAFIA. Dokładnie ona
#  przeszła 19 września cztery razy w czterech pakietach i za każdym razem
#  wyglądała na poprawnie wykonaną kontrolę.
#
#  Wszystko dzieje się na pliku tymczasowym i na atrapach testu — bez bazy,
#  bez Laravela, deterministycznie, poniżej sekundy.
#
#  Uruchomienie:  bash tests/skrypty/kontrola-ujemna.sh
# =============================================================================

set -uo pipefail

KORZEN="$(cd "$(dirname "$0")/../.." && pwd)"
PRZYRZAD="$KORZEN/scripts/kontrola-ujemna.sh"

CZERWONY='\033[0;31m'; ZIELONY='\033[0;32m'; RESET='\033[0m'
zdane=0; oblane=0

sprawdz() {
    local opis="$1" oczekiwany="$2" faktyczny="$3"
    if [ "$oczekiwany" = "$faktyczny" ]; then
        printf "  ${ZIELONY}✓${RESET} %s\n" "$opis"
        zdane=$((zdane + 1))
    else
        printf "  ${CZERWONY}✗${RESET} %s\n     oczekiwano kodu %s, otrzymano %s\n" "$opis" "$oczekiwany" "$faktyczny"
        oblane=$((oblane + 1))
    fi
}

PRACA="$(mktemp -d -t kontrola-ujemna-test.XXXXXX)"
trap 'rm -rf "$PRACA"' EXIT

# Plik „źródłowy" pod mutację. CELOWO BEZ ZNAKU NOWEJ LINII NA KOŃCU —
# to jest pułapka, na której narzędzia liniowe (`awk`, `sed`) dokładają znak
# i produkują różnicę MD5 tam, gdzie żadnej podmiany nie było. Gdyby przyrząd
# jej nie omijał, próba 1 niżej zameldowałaby udaną mutację.
printf 'BRAMKA=wlaczona' > "$PRACA/zrodlo.txt"
MD5_WZORCOWY="$(md5sum "$PRACA/zrodlo.txt" | cut -d' ' -f1)"

# Atrapa testu: przechodzi, dopóki w źródle stoi `BRAMKA=wlaczona`.
cat > "$PRACA/test-dobry.sh" <<'EOF'
#!/usr/bin/env bash
if grep -q 'BRAMKA=wlaczona' "$1"; then exit 0; fi
echo "BRAMKA_ZDJETA: wartownik nie znalazł włączonej bramki"
exit 1
EOF

# Atrapa testu, która przechodzi ZAWSZE — udaje wartownika, który nie strzeże.
cat > "$PRACA/test-zawsze-zielony.sh" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF

# Atrapa testu, która oblewa ZAWSZE i z innego powodu niż badany.
cat > "$PRACA/test-zawsze-czerwony.sh" <<'EOF'
#!/usr/bin/env bash
echo "SQLSTATE[08006]: brak połączenia z bazą"
exit 1
EOF

# Atrapa, która przechodzi na czystym źródle, a po mutacji ginie z innej
# przyczyny niż oczekiwana — to jest przypadek „czerwień z niewłaściwego
# powodu", który 19 września przez chwilę uchodził za dowód.
cat > "$PRACA/test-czerwony-nie-z-tego-powodu.sh" <<'EOF'
#!/usr/bin/env bash
if grep -q 'BRAMKA=wlaczona' "$1"; then exit 0; fi
echo "Fatal error: Allowed memory size exhausted"
exit 255
EOF

# Atrapa, która w połowie przebiegu zabija samą siebie — sprawdza `trap`.
cat > "$PRACA/test-ginie-w-polowie.sh" <<'EOF'
#!/usr/bin/env bash
if grep -q 'BRAMKA=wlaczona' "$1"; then exit 0; fi
kill -TERM $$
EOF

chmod +x "$PRACA"/test-*.sh

uruchom() {
    local plik="$1"; shift
    ( cd "$PRACA" && "$PRZYRZAD" --plik "$plik" "$@" ) >"$PRACA/wyjscie.log" 2>&1
    echo $?
}

printf '\n── Przyrząd do kontroli ujemnych: sześć prób ──\n\n'

# --- 1. SEDNO: mutacja, która NIE TRAFIA ------------------------------------
# Szukamy łańcucha, którego w pliku nie ma. Przyrząd ma ODMÓWIĆ (kod 2),
# a nie uruchomić testu i zameldować sukces.
kod="$(uruchom zrodlo.txt --zamien 'BRAMKA=WLACZONA' --na 'BRAMKA=wylaczona' \
        --oczekuj 'BRAMKA_ZDJETA' -- ./test-dobry.sh zrodlo.txt)"
sprawdz 'mutacja, która nie trafia → ODMOWA_NO_OP (2), nie „sukces”' 2 "$kod"
if ! grep -q 'ODMOWA' "$PRACA/wyjscie.log"; then
    printf "  ${CZERWONY}✗${RESET} komunikat odmowy nie nazywa rzeczy po imieniu\n"; oblane=$((oblane + 1))
else
    printf "  ${ZIELONY}✓${RESET} komunikat mówi wprost, że łańcucha nie ma w pliku\n"; zdane=$((zdane + 1))
fi
sprawdz 'plik po odmowie jest nietknięty (MD5)' "$MD5_WZORCOWY" "$(md5sum "$PRACA/zrodlo.txt" | cut -d' ' -f1)"

# --- 2. Mutacja trafia, test ją wykrywa, przyczyna właściwa ------------------
kod="$(uruchom zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' \
        --oczekuj 'BRAMKA_ZDJETA' -- ./test-dobry.sh zrodlo.txt)"
sprawdz 'mutacja trafia i test ją łapie → POTWIERDZONA (0)' 0 "$kod"
sprawdz 'źródło przywrócone po udanej kontroli (MD5)' "$MD5_WZORCOWY" "$(md5sum "$PRACA/zrodlo.txt" | cut -d' ' -f1)"

# --- 3. Wartownik, który nie strzeże ----------------------------------------
kod="$(uruchom zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' \
        --oczekuj 'BRAMKA_ZDJETA' -- ./test-zawsze-zielony.sh zrodlo.txt)"
sprawdz 'mutacja weszła, test dalej zielony → STRAZNIK_NIE_STRZEZE (3)' 3 "$kod"

# --- 4. Czerwień z niewłaściwego powodu -------------------------------------
kod="$(uruchom zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' \
        --oczekuj 'BRAMKA_ZDJETA' -- ./test-czerwony-nie-z-tego-powodu.sh zrodlo.txt)"
sprawdz 'oblał, ale nie z oczekiwanej przyczyny → ZLA_PRZYCZYNA (4)' 4 "$kod"
if grep -q 'NIE z oczekiwanego powodu' "$PRACA/wyjscie.log"; then
    printf "  ${ZIELONY}✓${RESET} raport odróżnia „oblał z tego powodu” od „oblał z jakiegokolwiek”\n"; zdane=$((zdane + 1))
else
    printf "  ${CZERWONY}✗${RESET} raport nie odróżnia przyczyny oblania\n"; oblane=$((oblane + 1))
fi

# --- 5. Brak kontroli dodatniej ---------------------------------------------
# Test czerwony JUŻ PRZED mutacją. Czerwień po mutacji nie dowodziłaby niczego.
kod="$(uruchom zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' \
        --oczekuj 'SQLSTATE' -- ./test-zawsze-czerwony.sh zrodlo.txt)"
sprawdz 'test czerwony już przed mutacją → BRAK_KONTROLI_DODATNIEJ (5)' 5 "$kod"
sprawdz 'przy braku kontroli dodatniej plik nietknięty (MD5)' "$MD5_WZORCOWY" "$(md5sum "$PRACA/zrodlo.txt" | cut -d' ' -f1)"

# --- 6. Przywrócenie, gdy test ginie w połowie ------------------------------
uruchom zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' \
        --oczekuj 'cokolwiek' -- ./test-ginie-w-polowie.sh zrodlo.txt >/dev/null
sprawdz 'źródło wraca, choć test zginął w połowie (MD5)' "$MD5_WZORCOWY" "$(md5sum "$PRACA/zrodlo.txt" | cut -d' ' -f1)"

# --- 7. Kontrola dodatnia wymogu `--oczekuj` --------------------------------
( cd "$PRACA" && "$PRZYRZAD" --plik zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'x' \
    -- ./test-dobry.sh zrodlo.txt ) >/dev/null 2>&1
sprawdz 'brak --oczekuj → BLAD_UZYCIA (9), a nie domyślne „byle czerwień”' 9 "$?"

printf '\n'
# --- 8. mtime wraca CO DO UŁAMKA SEKUNDY ------------------------------------
# Do 20 września 2026 przyrząd twierdził „MD5 i mtime zgodne", nie porównując
# mtime w ogóle, a `touch -d` dodatkowo UCINAŁ część podsekundową, którą
# `cp -p` już poprawnie przywróciło. Ten przypadek by to złapał: przed
# poprawką mtime po przebiegu różnił się od mtime sprzed ułamkiem sekundy.
mtime_przed="$(date -r "$PRACA/zrodlo.txt" +%s.%N)"
uruchom zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' \
        --oczekuj 'BRAMKA' -- ./test-dobry.sh zrodlo.txt >/dev/null
sprawdz 'mtime wraca co do ułamka sekundy, nie tylko co do sekundy' \
        "$mtime_przed" "$(date -r "$PRACA/zrodlo.txt" +%s.%N)"

# --- 9. REGRESJA: JSON i konsola muszą meldować to samo przywrócenie -------
# Do 21 września 2026 pole "przywrocenie" w JSON było liczone w INNYM
# miejscu niż komunikat na konsolę: JSON zapisywał się w głównym biegu
# skryptu, ZANIM `trap` na EXIT zdążył naprawdę przywrócić plik, więc
# zostawał przy wartości startowej „nie wykonane" — mimo że przywrócenie
# się udało i konsola poprawnie meldowała „Źródło przywrócone". Ten sam
# fakt liczony dwa razy w dwóch miejscach dawał dwie różne odpowiedzi.
JSON_9="$PRACA/wynik-9.json"
rm -f "$JSON_9"
( cd "$PRACA" && "$PRZYRZAD" --plik zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' \
    --oczekuj 'BRAMKA_ZDJETA' --json "$JSON_9" -- ./test-dobry.sh zrodlo.txt ) >"$PRACA/wyjscie.log" 2>&1
if [ -f "$JSON_9" ] && grep -qF '"przywrocenie": "ok' "$JSON_9"; then
    printf "  ${ZIELONY}✓${RESET} JSON zgadza się z konsolą: przywrocenie zapisane jako „ok”, nie „nie wykonane”\n"; zdane=$((zdane + 1))
else
    printf "  ${CZERWONY}✗${RESET} JSON rozjeżdża się z konsolą — pole \"przywrocenie\" nie mówi „ok”\n"
    [ -f "$JSON_9" ] && grep '"przywrocenie"' "$JSON_9" | sed 's/^/     /'
    oblane=$((oblane + 1))
fi

if [ "$oblane" -eq 0 ]; then
    printf "${ZIELONY}Przyrząd do kontroli ujemnych: %s/%s prób zdanych.${RESET}\n" "$zdane" "$zdane"
    exit 0
fi
printf "${CZERWONY}Przyrząd do kontroli ujemnych: %s oblanych z %s.${RESET}\n" "$oblane" "$((zdane + oblane))"
exit 1
