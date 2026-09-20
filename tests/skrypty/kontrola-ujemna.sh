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

# Atrapa CELOWO BEZ bitu wykonywalności — odtwarza to, co w worktree na
# Windows dzieje się samo. Powłoka zwraca 126, a naiwny przyrząd czyta to
# jako „test oblał" i melduje kontrolę wokół próby, która nigdy nie ruszyła.
cp "$PRACA/test-dobry.sh" "$PRACA/test-bez-bitu.sh"
chmod -x "$PRACA/test-bez-bitu.sh"

# Kontrola dodatnia dla samych atrap: gdyby któraś straciła bit wykonywalności
# (a w worktree na Windows to reguła, nie wyjątek), połowa prób niżej mierzyłaby
# 126 zamiast tego, co miała mierzyć. Ta pętla jest tu po to, żeby taka utrata
# była WIDOCZNA, a nie żeby cicho pozieleniła wynik.
brak_bitu=0
for atrapa in "$PRACA"/test-dobry.sh "$PRACA"/test-zawsze-zielony.sh "$PRACA"/test-zawsze-czerwony.sh "$PRACA"/test-czerwony-nie-z-tego-powodu.sh "$PRACA"/test-ginie-w-polowie.sh; do
    [ -x "$atrapa" ] || { printf "  ${CZERWONY}x${RESET} atrapa bez bitu wykonywalnosci: %s
" "$(basename "$atrapa")"; brak_bitu=$((brak_bitu + 1)); }
done
if [ "$brak_bitu" -eq 0 ]; then
    printf "  ${ZIELONY}v${RESET} wszystkie atrapy testu sa wykonywalne (inaczej mierzylyby 126)
"
    zdane=$((zdane + 1))
else
    oblane=$((oblane + brak_bitu))
fi

uruchom() {
    local plik="$1"; shift
    ( cd "$PRACA" && "$PRZYRZAD" --plik "$plik" "$@" ) >"$PRACA/wyjscie.log" 2>&1
    echo $?
}

printf '\n── Przyrząd do kontroli ujemnych: dziewięć prób ──\n\n'

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

# --- 8. Polecenie bez bitu wykonywalnosci -> 126 -----------------------------
# SEDNO: 126 jest niezerowe, wiec naiwny przyrzad przeczyta je jako oblany
# test. Przy tym plik nietkniety tez bedzie prawda, bo nic sie nie wykonalo —
# i wyjdzie komplet zielonych sprawdzen wokol proby, ktora nigdy nie ruszyla.
# To jest falszywa zielen WEWNATRZ narzedzia budowanego przeciw falszywym.
kod="$(uruchom zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' --oczekuj 'BRAMKA_ZDJETA' -- ./test-bez-bitu.sh zrodlo.txt)"
sprawdz 'polecenie bez bitu wykonywalnosci (126) -> BLAD_POLECENIA (7), nie wynik proby' 7 "$kod"
if grep -q 'git update-index --chmod=+x' "$PRACA/wyjscie.log"; then
    printf '  %s
' 'v komunikat podaje, jak naprawic bit takze w repozytorium'; zdane=$((zdane + 1))
else
    printf '  %s
' 'x komunikat nie mowi, jak naprawic bit w repozytorium'; oblane=$((oblane + 1))
fi

# --- 9. Polecenia w ogole nie ma -> 127 --------------------------------------
kod="$(uruchom zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' --oczekuj 'BRAMKA_ZDJETA' -- ./polecenia-nie-ma.sh)"
sprawdz 'polecenia nie ma (127) -> BLAD_POLECENIA (7), nie BRAK_KONTROLI_DODATNIEJ' 7 "$kod"
sprawdz 'przy niewykonanym poleceniu plik nietkniety (MD5)' "$MD5_WZORCOWY" "$(md5sum "$PRACA/zrodlo.txt" | cut -d' ' -f1)"

# --- 10. Tryb wsadowy nie gubi wpisow po cichu -------------------------------
# Runner wsadowy, ktory po cichu pominie wpis, policzy mniej mutacji i nazwie
# to sukcesem. Katalog nizej ma TRZY bloki o znanych werdyktach: zabita,
# przezyla, wadliwa (no-op). Sprawdzamy liczbe i kazdy werdykt z osobna.
cat > "$PRACA/katalog.txt" <<'KAT'
# komentarz ma byc pominiety, a pusta linia nizej tez

nazwa: mutacja zabita przez wartownika
plik: zrodlo.txt
zamien: BRAMKA=wlaczona
na: BRAMKA=wylaczona
oczekuj: BRAMKA_ZDJETA
polecenie: ./test-dobry.sh zrodlo.txt
---
nazwa: mutacja ktora przezyla
plik: zrodlo.txt
zamien: BRAMKA=wlaczona
na: BRAMKA=wylaczona
oczekuj: BRAMKA_ZDJETA
polecenie: ./test-zawsze-zielony.sh zrodlo.txt
---
nazwa: mutacja ktora nie trafia
plik: zrodlo.txt
zamien: BRAMKA=NIE_MA_TAKIEJ
na: x
oczekuj: BRAMKA_ZDJETA
polecenie: ./test-dobry.sh zrodlo.txt
KAT
( cd "$PRACA" && "$KORZEN/scripts/mutacje.sh" katalog.txt ) >"$PRACA/wsad.log" 2>&1
kod=$?
sprawdz 'tryb wsadowy konczy sie kodem 2, gdy jakas proba byla WADLIWA' 2 "$kod"
sprawdz 'tryb wsadowy policzyl wszystkie trzy wpisy i zdal z nich sprawe' 'mutacji=3 zabite=1 przezyly=1 wadliwe=1' "$(grep -o 'mutacji=[0-9]* zabite=[0-9]* przezyly=[0-9]* wadliwe=[0-9]*' "$PRACA/wsad.log" | head -1)"
# Numeracja prob. Defekt z 19.09: patch skasowal `numer=$((numer + 1))` razem
# z sasiednimi liniami, tabela pokazywala 0 w kazdym wierszu, a liczniki
# zbiorcze byly poprawne — wiec nic nie swiecilo na czerwono. W narzedziu,
# ktorego jedynym produktem jest wiarygodny wydruk, to nie jest kosmetyka.
sprawdz 'kazda proba ma wlasny numer w naglowku' '[01]' "$(grep -o '\[0[0-9]\]' "$PRACA/wsad.log" | head -1)"
sprawdz 'trzecia proba ma numer 3, a nie 0' '[03]' "$(grep -o '\[0[0-9]\]' "$PRACA/wsad.log" | tail -1)"
if grep -q 'dziura w teście' "$PRACA/wsad.log"; then
    printf '  %s
' 'v tabela zada ROZSTRZYGNIECIA dziura-czy-mutacja-bez-znaczenia'; zdane=$((zdane + 1))
else
    printf '  %s
' 'x tabela nie zada rozstrzygniecia przy przezywajacej mutacji'; oblane=$((oblane + 1))
fi

# --- 11. Mutacja pliku Blade nie zostawia zmutowanego kompilatu -------------
# Laravel rekompiluje szablon tylko gdy zrodlo jest NOWSZE od kompilatu
# (Compiler::isExpired). Po przywroceniu mtime zrodlo jest STARSZE niz
# kompilat zmutowanego widoku, wiec bez tej poprawki Laravel dalej serwowalby
# mutacje — i to JUZ PO zakonczeniu przebiegu, w kazdym nastepnym tescie.
mkdir -p "$PRACA/storage/framework/views" "$PRACA/resources/views"
printf '<p>BRAMKA=wlaczona</p>' > "$PRACA/resources/views/probny.blade.php"
MD5_BLADE="$(md5sum "$PRACA/resources/views/probny.blade.php" | cut -d' ' -f1)"
# Udajemy kompilat: plik NOWSZY od zrodla, dokladnie jak po rekompilacji.
printf '<?php /* skompilowany ZMUTOWANY widok */ ?>' > "$PRACA/storage/framework/views/abc123.php"
touch -d '+1 hour' "$PRACA/storage/framework/views/abc123.php"
uruchom resources/views/probny.blade.php --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' --oczekuj 'BRAMKA_ZDJETA' -- ./test-dobry.sh resources/views/probny.blade.php >/dev/null
if [ -f "$PRACA/storage/framework/views/abc123.php" ]; then
    printf '  %s
' 'x zmutowany kompilat PRZEZYL przebieg — nastepny test dostanie mutacje'; oblane=$((oblane + 1))
else
    printf '  %s
' 'v skompilowany widok uniewazniony po mutacji pliku Blade'; zdane=$((zdane + 1))
fi
sprawdz 'zrodlo Blade przywrocone bajt w bajt (MD5)' "$MD5_BLADE" "$(md5sum "$PRACA/resources/views/probny.blade.php" | cut -d' ' -f1)"

# Kontrola dodatnia: plik NIE-Blade nie rusza kompilatow (zadnego nadmiaru).
printf '<?php /* nietykalny */ ?>' > "$PRACA/storage/framework/views/zostaje.php"
uruchom zrodlo.txt --zamien 'BRAMKA=wlaczona' --na 'BRAMKA=wylaczona' --oczekuj 'BRAMKA_ZDJETA' -- ./test-dobry.sh zrodlo.txt >/dev/null
if [ -f "$PRACA/storage/framework/views/zostaje.php" ]; then
    printf '  %s
' 'v mutacja pliku nie-Blade nie kasuje kompilatow'; zdane=$((zdane + 1))
else
    printf '  %s
' 'x mutacja pliku nie-Blade skasowala kompilaty — nadmiar'; oblane=$((oblane + 1))
fi

printf '\n'
if [ "$oblane" -eq 0 ]; then
    printf "${ZIELONY}Przyrząd do kontroli ujemnych: %s/%s prób zdanych.${RESET}\n" "$zdane" "$zdane"
    exit 0
fi
printf "${CZERWONY}Przyrząd do kontroli ujemnych: %s oblanych z %s.${RESET}\n" "$oblane" "$((zdane + oblane))"
exit 1
