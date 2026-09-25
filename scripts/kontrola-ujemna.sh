#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — przyrząd do KONTROLI UJEMNYCH (docs/PULAPKI_TESTOW.md §5)
# =============================================================================
#
#  PO CO TO ISTNIEJE
#  Kontrola ujemna to zdanie: „zepsułem to, czego test pilnuje, i test oblał".
#  Zdanie jest warte tyle, ile wart jest jego pierwszy człon. 19 września 2026
#  w czterech NIEZALEŻNYCH pakietach ten człon był nieprawdziwy: wzorzec
#  `sed` nie trafiał, plik zostawał nietknięty, test przechodził — a przebieg
#  wyglądał na poprawnie wykonaną kontrolę. Dowód był wart zero, a raport
#  twierdził coś przeciwnego.
#
#  Ten skrypt istnieje po to, żeby taki no-op był NIEMOŻLIWY, a nie „możliwy
#  do wychwycenia przy uważnej lekturze". Mutacja, która nie zmienia pliku,
#  kończy przebieg odmową — zanim w ogóle ruszy test.
#
#  CZTERY RZECZY, KTÓRYCH TEN PRZYRZĄD PILNUJE
#
#  1. MUTACJA NAPRAWDĘ WESZŁA. Nie „polecenie zwróciło zero", tylko: MD5
#     pliku PRZED różni się od MD5 PO. Identyczny plik → `ODMOWA_NO_OP`.
#
#  2. KONTROLA DODATNIA PRZED MUTACJĄ. Test musi PRZEJŚĆ na nietkniętym
#     źródle. Bez tego czerwień po mutacji nie dowodzi niczego — mogła być
#     czerwona od początku. To jest drugi no-op, rzadziej nazywany.
#
#  3. CZERWIEŃ Z WŁAŚCIWEGO POWODU. `--oczekuj` to wzorzec, który MUSI
#     wystąpić w wyjściu oblanego testu. Test, który pada z innego powodu
#     (literówka w mutacji, brak bazy, timeout), dostaje werdykt
#     `ZLA_PRZYCZYNA`, a nie `POTWIERDZONA`. To rozróżnienie jest sednem
#     czwartego wymagania: 19 września jedna kontrola dała czerwień
#     z niewłaściwej przyczyny i przez chwilę uchodziła za dowód.
#
#  4. ŹRÓDŁO WRACA ZAWSZE. Przywracanie siedzi w `trap` na EXIT, INT i TERM,
#     więc działa także wtedy, gdy test wywali się w połowie albo ktoś
#     przerwie przebieg Ctrl+C. Po przywróceniu PORÓWNUJEMY MD5 **i** mtime —
#     oba, nie tylko pierwsze. Do 20 września 2026 mtime był wyliczany
#     i nieporównywany, a komunikat i tak twierdził, że się zgadza.
#
#  UŻYCIE
#    scripts/kontrola-ujemna.sh \
#      --plik app/Support/PaginationLinks.php \
#      --zamien 'min($other->currentPage(), $other->lastPage())' \
#      --na '$other->currentPage()' \
#      --oczekuj 'page.*999' \
#      [--nazwa 'docięcie numeru strony'] \
#      [--json storage/kontrola-ujemna.json] \
#      -- vendor/bin/phpunit tests/Feature/ZeszytPaginacjaObuListTest.php
#
#  `--zamien` i `--na` są ZWYKŁYMI ŁAŃCUCHAMI, nie wyrażeniami regularnymi.
#  To jest decyzja, nie uproszczenie: połowa no-opów z 19 września wzięła się
#  z wyrażenia, które przestało pasować po niewinnym przeformatowaniu kodu.
#  Łańcuch albo jest w pliku, albo go nie ma, i nie ma trzeciej możliwości.
#
#  KODY WYJŚCIA
#    0  POTWIERDZONA            PASS → FAIL(z właściwego powodu) → PASS
#    2  ODMOWA_NO_OP            mutacja nie zmieniła pliku
#    3  STRAZNIK_NIE_STRZEZE    mutacja weszła, a test dalej przechodzi
#    4  ZLA_PRZYCZYNA           oblał, ale nie na tym, czego oczekiwano
#    5  BRAK_KONTROLI_DODATNIEJ test oblewał JUŻ PRZED mutacją
#    6  PRZYWROCENIE_NIEUDANE   plik nie wrócił do stanu sprzed przebiegu
#    7  BLAD_POLECENIA          polecenie SIĘ NIE WYKONAŁO (126/127) — awaria
#                               przyrządu, nigdy wynik próby
#    9  BLAD_UZYCIA             złe argumenty, brak pliku, brudny katalog
# =============================================================================

set -uo pipefail

PLIK=""; ZAMIEN=""; NA=""; OCZEKUJ=""; NAZWA=""; JSON=""
POLECENIE=()

while [ $# -gt 0 ]; do
    case "$1" in
        --plik)    PLIK="${2:-}"; shift 2 ;;
        --zamien)  ZAMIEN="${2:-}"; shift 2 ;;
        --na)      NA="${2:-}"; shift 2 ;;
        --oczekuj) OCZEKUJ="${2:-}"; shift 2 ;;
        --nazwa)   NAZWA="${2:-}"; shift 2 ;;
        --json)    JSON="${2:-}"; shift 2 ;;
        --)        shift; POLECENIE=("$@"); break ;;
        *) printf 'Nieznany argument: %s\n' "$1" >&2; exit 9 ;;
    esac
done

blad_uzycia() { printf '\033[0;31mBŁĄD UŻYCIA: %s\033[0m\n' "$1" >&2; exit 9; }

[ -n "$PLIK" ]    || blad_uzycia '--plik jest wymagany.'
[ -n "$ZAMIEN" ]  || blad_uzycia '--zamien jest wymagany.'
[ -n "$OCZEKUJ" ] || blad_uzycia '--oczekuj jest wymagany. Kontrola bez oczekiwanej przyczyny nie odróżnia dowodu od awarii środowiska.'
[ ${#POLECENIE[@]} -gt 0 ] || blad_uzycia 'Brak polecenia po `--`.'
[ -f "$PLIK" ]    || blad_uzycia "Plik nie istnieje: $PLIK"
[ -n "$NAZWA" ]   || NAZWA="$PLIK"

# `--na` wolno być pustym łańcuchem (skasowanie fragmentu), ale wtedy musi być
# podany jawnie — inaczej literówka w nazwie argumentu kasowałaby kod po cichu.
if [ -z "${NA+x}" ]; then blad_uzycia '--na jest wymagany (może być pustym łańcuchem).'; fi

# Nie mutujemy pliku, który ktoś właśnie edytuje: po przebiegu przywracamy go
# do stanu SPRZED, a przy niezatwierdzonych zmianach „stan sprzed" jest cudzą
# pracą w toku. Lepiej odmówić, niż grać czyimś buforem edytora.
if git -C "$(dirname "$PLIK")" rev-parse --git-dir >/dev/null 2>&1; then
    if ! git diff --quiet -- "$PLIK" 2>/dev/null; then
        blad_uzycia "Plik ma niezatwierdzone zmiany: $PLIK. Zatwierdź je albo odłóż, zanim uruchomisz kontrolę ujemną."
    fi
fi

CZERWONY='\033[0;31m'; ZIELONY='\033[0;32m'; ZOLTY='\033[0;33m'; RESET='\033[0m'
krok() { printf "${ZOLTY}── %s ──${RESET}\n" "$1"; }
ok()   { printf "${ZIELONY}✓ %s${RESET}\n" "$1"; }
zle()  { printf "${CZERWONY}✗ %s${RESET}\n" "$1"; }

suma() { md5sum "$1" | cut -d' ' -f1; }
czas() { date -r "$1" +%s.%N 2>/dev/null || stat -c '%Y' "$1"; }

# Jedyne miejsce, w którym uruchamiamy cudze polecenie. Ustawia WYJSCIE i KOD.
#
# 126 I 127 TO AWARIA PRZYRZĄDU, NIE WYNIK PRÓBY. Powłoka zwraca 127, gdy
# polecenia nie ma, i 126, gdy jest, ale bez bitu wykonywalności (w worktree
# na Windows ten bit ginie nagminnie). Obie liczby są niezerowe, więc naiwny
# przyrząd czyta je jako „test oblał" — a przy tym „plik jest nietknięty" też
# jest prawdą, bo nic się nie wykonało. Wychodzi komplet zielonych sprawdzeń
# wokół próby, która nigdy nie ruszyła: fałszywa zieleń WEWNĄTRZ narzędzia
# budowanego przeciw fałszywym zieleniom.
# SKOMPILOWANE WIDOKI BLADE — pulapka zglaszana przez trzy sesje.
#
# Laravel rekompiluje szablon tylko wtedy, gdy zrodlo jest NOWSZE od kompilatu
# (`Illuminate/View/Compilers/Compiler::isExpired()`:
# `lastModified($path) >= lastModified($compiled)`). Kolejnosc zdarzen przy
# kontroli ujemnej na pliku `.blade.php` jest wiec zabojcza:
#
#   1. zrodlo ma mtime T0, kompilat powstaje w T1 > T0;
#   2. mutacja ustawia mtime na TERAZ (T2 > T1) -> Laravel rekompiluje
#      ZMUTOWANY widok, kompilat ma T3;
#   3. przywrocenie `cp -p` cofa mtime zrodla do T0, czyli PONIZEJ T3
#      -> `isExpired()` zwraca false i Laravel dalej serwuje ZMUTOWANY
#      kompilat. Zrodlo jest czyste, MD5 sie zgadza, a widok klamie.
#
# Najgorsze jest to, ze skutek PRZEZYWA przebieg: zatruwa kazdy nastepny test
# w tej kopii, dopoki ktos nie wyczysci kompilatow recznie. Dlatego naprawa
# siedzi TUTAJ, a nie u kazdego wolajacego — trzy sesje potknely sie o to
# osobno, co znaczy, ze wolajacy o tym nie wiedza i wiedziec nie musza.
#
# Kasujemy KOMPILATY, nie zrodla. Laravel odtworzy je przy nastepnym renderze.
unieważnij_kompilaty() {
    case "$PLIK" in *.blade.php) ;; *) return 0 ;; esac
    [ -d storage/framework/views ] || return 0
    local ile
    ile="$(find storage/framework/views -maxdepth 1 -name '*.php' -type f -print -delete 2>/dev/null | wc -l)"
    [ "$ile" -gt 0 ] && printf '  (skasowano %s skompilowanych widokow — mutacja pliku Blade)
' "$ile"
    return 0
}

WYJSCIE=""; KOD=0
uruchom_polecenie() {
    WYJSCIE="$("${POLECENIE[@]}" 2>&1)"
    KOD=$?
    if [ "$KOD" -eq 126 ] || [ "$KOD" -eq 127 ]; then
        zle "POLECENIE SIĘ NIE WYKONAŁO (kod $KOD) — to NIE jest wynik próby."
        if [ "$KOD" -eq 127 ]; then
            zle "127 = nie znaleziono polecenia: ${POLECENIE[0]}"
        else
            zle "126 = brak bitu wykonywalności na: ${POLECENIE[0]}"
            zle "Napraw: chmod +x ${POLECENIE[0]}"
            zle "a w repozytorium: git update-index --chmod=+x ${POLECENIE[0]}"
        fi
        printf '%s
' "$WYJSCIE" | tail -5 | sed 's/^/    /'
        WERDYKT="BLAD_POLECENIA"
        zapisz_json "$WERDYKT"
        exit 7
    fi
}

KOPIA="$(mktemp -t kontrola-ujemna.XXXXXX)"
cp -p "$PLIK" "$KOPIA"
MD5_PRZED="$(suma "$PLIK")"
MTIME_PRZED="$(czas "$PLIK")"
WERDYKT="PRZERWANA"
PRZYWROCENIE="nie wykonane"

# --- Przywracanie: trap, nie „na końcu skryptu" -----------------------------
# Różnica jest cała: `trap` łapie też przerwanie w połowie testu i Ctrl+C.
# Kontrola ujemna, która przy awarii zostawia zmutowane źródło, jest gorsza
# od braku kontroli — następny przebieg mierzy wtedy zepsuty kod i nikt
# o tym nie wie.
przywroc() {
    local kod=$?
    if [ -f "$KOPIA" ]; then
        # `cp -p` przywraca mtime z pełną dokładnością. Wcześniejsze
        # `touch -d "@${MTIME_PRZED%.*}"` tę dokładność ODBIERAŁO, ucinając
        # część podsekundową — czyli krok „przywracający" psuł to, co `cp -p`
        # już zrobiło dobrze.
        cp -p "$KOPIA" "$PLIK"
        local md5_po mtime_po
        md5_po="$(suma "$PLIK")"
        mtime_po="$(czas "$PLIK")"
        if [ "$md5_po" != "$MD5_PRZED" ]; then
            zle "PRZYWRÓCENIE NIEUDANE: MD5 po przywróceniu ($md5_po) ≠ MD5 sprzed przebiegu ($MD5_PRZED)."
            zle "Kopia zostaje w $KOPIA — przywróć ręcznie i sprawdź, zanim cokolwiek uruchomisz."
            PRZYWROCENIE="NIEUDANE"
            zapisz_json "PRZYWROCENIE_NIEUDANE"
            exit 6
        fi
        # Do 20 września 2026 `mtime_po` było WYLICZANE I NIGDY NIEPORÓWNYWANE,
        # a komunikat mimo to twierdził „MD5 i mtime zgodne". Przyrząd budowany
        # przeciw meldunkom bez pokrycia sam taki meldunek wypisywał.
        if [ "$mtime_po" != "$MTIME_PRZED" ]; then
            zle "PRZYWRÓCENIE NIEUDANE: mtime po przywróceniu ($mtime_po) ≠ mtime sprzed przebiegu ($MTIME_PRZED)."
            zle "Treść wróciła (MD5 się zgadza), ale znacznik czasu nie — narzędzia patrzące na mtime zobaczą plik jako zmieniony."
            zle "Kopia zostaje w $KOPIA."
            PRZYWROCENIE="NIEUDANE"
            zapisz_json "PRZYWROCENIE_NIEUDANE"
            exit 6
        fi
        # Krok krytyczny: po cofnieciu mtime kompilat zmutowanego widoku
        # jest NOWSZY od zrodla i Laravel dalej by go serwowal. Patrz
        # uzasadnienie przy `unieważnij_kompilaty` wyzej.
        unieważnij_kompilaty
        PRZYWROCENIE="ok (MD5 $md5_po, mtime $mtime_po)"
        rm -f "$KOPIA"
        # JEDNO ŹRÓDŁO PRAWDY: do 21 września 2026 ten fakt był liczony w
        # dwóch miejscach osobno. Komunikat na konsolę powstawał TU, po
        # naprawdę wykonanym porównaniu MD5 i mtime. JSON zapisywał go
        # wcześniej — jawnym wywołaniem `zapisz_json` w głównym biegu skryptu,
        # zanim ten `trap` (uruchamiany na EXIT) w ogóle zdążył przywrócić
        # plik. Pole „przywrocenie” w JSON zastawało więc swoją wartość
        # startową „nie wykonane" i takie zostawało, mimo że przywrócenie
        # faktycznie się powiodło — konsola i JSON mówiły o tym samym fakcie
        # co innego, bo mierzyły go w dwóch różnych momentach.
        # Naprawa: przepisujemy JSON TERAZ, PO realnym przywróceniu, tym
        # samym `$WERDYKT`, którym posłużył się główny bieg. Trap na EXIT
        # kończy się zawsze jako ostatni, więc ten zapis jest ostatnim i
        # jedynym wiarygodnym stanem na dysku — nie ma już dwóch ścieżek
        # liczących to samo.
        zapisz_json "$WERDYKT"
        ok "Źródło przywrócone: MD5 i mtime PORÓWNANE ze stanem sprzed przebiegu."
    fi
    exit "$kod"
}

zapisz_json() {
    [ -n "$JSON" ] || return 0
    mkdir -p "$(dirname "$JSON")"
    cat > "$JSON" <<JSONEOF
{
  "nazwa": $(printf '%s' "$NAZWA" | sed 's/\\/\\\\/g; s/"/\\"/g; s/^/"/; s/$/"/'),
  "plik": "$PLIK",
  "werdykt": "$1",
  "md5_przed": "$MD5_PRZED",
  "md5_po_mutacji": "${MD5_PO_MUTACJI:-}",
  "mutacja_weszla": ${MUTACJA_WESZLA:-false},
  "liczba_podmian": ${LICZBA_PODMIAN:-0},
  "kontrola_dodatnia_przed": "${KD_PRZED:-nie wykonana}",
  "wynik_po_mutacji": "${WYNIK_PO:-nie wykonany}",
  "oczekiwana_przyczyna": $(printf '%s' "$OCZEKUJ" | sed 's/\\/\\\\/g; s/"/\\"/g; s/^/"/; s/$/"/'),
  "przyczyna_potwierdzona": ${PRZYCZYNA_OK:-false},
  "kontrola_dodatnia_po_przywroceniu": "${KD_PO:-nie wykonana}",
  "przywrocenie": "$PRZYWROCENIE",
  "data": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSONEOF
}

trap przywroc EXIT INT TERM

printf '\n'
krok "Kontrola ujemna: $NAZWA"
printf '  plik:     %s\n  MD5 przed: %s\n  oczekuję:  %s\n\n' "$PLIK" "$MD5_PRZED" "$OCZEKUJ"

# --- 1. Kontrola dodatnia PRZED mutacją -------------------------------------
krok "1/4 — czy test w ogóle przechodzi na nietkniętym źródle"
uruchom_polecenie
if [ "$KOD" -ne 0 ]; then
    KD_PRZED="OBLANY"
    zle "Test oblewa JUŻ PRZED mutacją. Czerwień po mutacji nie dowiodłaby niczego."
    # `$WYJSCIE`, nie `$WYJSCIE_PRZED`: od czasu przejscia na `uruchom_polecenie`
    # ta druga nazwa nie jest juz nigdzie ustawiana. Pod `set -u` rozwiniecie
    # padalo w podpowloce potoku, wiec SAM SKRYPT szedl dalej i konczyl sie
    # poprawnym kodem 5 — a jedyne, co ginelo, to wypis mowiacy, DLACZEGO test
    # byl czerwony. Werdykt bez uzasadnienia to dokladnie ta klasa usterki,
    # przeciw ktorej ten przyrzad powstal.
    printf '%s\n' "$WYJSCIE" | tail -15
    WERDYKT="BRAK_KONTROLI_DODATNIEJ"
    zapisz_json "$WERDYKT"
    exit 5
fi
KD_PRZED="PASS"
ok "Test przechodzi na nietkniętym źródle."

# --- 2. Mutacja i DOWÓD, że weszła ------------------------------------------
krok "2/4 — mutacja i dowód, że weszła"
# Podmiana przez PHP na CAŁYM pliku jako łańcuchu, nie liniami i nie `sed`-em.
# Trzy powody, każdy z siniaka:
#  * `str_replace` bierze zwykły łańcuch — żadnych znaków specjalnych wyrażeń
#    regularnych, żadnego cichego niedopasowania po przeformatowaniu kodu;
#  * `file_put_contents` zapisuje bajt w bajt — narzędzia liniowe (`awk`,
#    `sed -n p`) DOKŁADAJĄ znak nowej linii na końcu pliku, który go nie miał.
#    Wtedy MD5 różni się mimo braku podmiany i no-op udaje udaną mutację —
#    czyli dokładnie ta awaria, przed którą ten przyrząd ma bronić;
#  * PHP zwraca LICZBĘ podmian, więc dowodem jest liczba, a nie domysł.
LICZBA_PODMIAN="$(ZAMIEN="$ZAMIEN" NA="$NA" php -r '
    $zrodlo = file_get_contents($argv[1]);
    $ile = 0;
    $wynik = str_replace(getenv("ZAMIEN"), getenv("NA"), $zrodlo, $ile);
    if ($ile > 0) { file_put_contents($argv[2], $wynik); }
    echo $ile;
' "$KOPIA" "$PLIK" 2>/dev/null)"

if ! grep -qE '^[0-9]+$' <<< "$LICZBA_PODMIAN"; then
    zle "Nie udało się wykonać podmiany (PHP nie zwrócił liczby). Nic nie zmieniono."
    WERDYKT="BLAD_UZYCIA"
    zapisz_json "$WERDYKT"
    exit 9
fi

MD5_PO_MUTACJI="$(suma "$PLIK")"
if [ "$LICZBA_PODMIAN" -eq 0 ] || [ "$MD5_PO_MUTACJI" = "$MD5_PRZED" ]; then
    MUTACJA_WESZLA=false
    zle "ODMOWA: mutacja nie weszła — liczba podmian: $LICZBA_PODMIAN, MD5 bez zmian."
    zle "Szukany łańcuch nie występuje w pliku, więc nie ma czego zepsuć:"
    printf '    %s
' "$ZAMIEN"
    zle "To jest dokładnie ten no-op, przed którym ten przyrząd istnieje (PULAPKI_TESTOW §5)."
    zle "Nie uruchamiam testu — jego wynik nie byłby dowodem niczego."
    WERDYKT="ODMOWA_NO_OP"
    zapisz_json "$WERDYKT"
    exit 2
fi
MUTACJA_WESZLA=true
unieważnij_kompilaty
ok "Mutacja weszła: $LICZBA_PODMIAN podmian, MD5 $MD5_PRZED → $MD5_PO_MUTACJI."
if command -v diff >/dev/null 2>&1; then
    printf '  różnica:
'
    diff -u "$KOPIA" "$PLIK" | sed -n '4,14p' | sed 's/^/    /'
fi

# --- 3. Test na zmutowanym źródle -------------------------------------------
krok "3/4 — czy test to wykrywa, i czy z właściwego powodu"
uruchom_polecenie
WYJSCIE_PO="$WYJSCIE"; KOD_PO="$KOD"

if [ "$KOD_PO" -eq 0 ]; then
    WYNIK_PO="PASS"
    zle "Mutacja weszła, a test DALEJ PRZECHODZI — ten test nie pilnuje tego, co zepsuliśmy."
    printf '%s\n' "$WYJSCIE_PO" | tail -10
    WERDYKT="STRAZNIK_NIE_STRZEZE"
    zapisz_json "$WERDYKT"
    exit 3
fi
WYNIK_PO="FAIL (kod $KOD_PO)"

# NIE `printf ... | grep -q`. Wyglada niewinnie i jest pulapka: `grep -q`
# konczy sie na PIERWSZYM trafieniu i zamyka potok, `printf` dostaje SIGPIPE
# i wychodzi z kodem 141, a przy `set -o pipefail` statusem CALEGO potoku
# jest wlasnie 141 — mimo ze wzorzec ZOSTAL znaleziony.
#
# Objawia sie WYLACZNIE przy duzym wyjsciu: gdy printf zdazy zapisac calosc,
# zanim grep wyjdzie, SIGPIPE nie ma. Przy krotkich atrapach w kontroli
# ujemnej bylo wiec zielono, a przy prawdziwym pakiecie (158 kB wyjscia
# PHPUnita) przyrzad meldowal ZLA_PRZYCZYNA dla kontroli, ktore byly
# POPRAWNE. Kierunek bledu lagodniejszy niz falszywa zielen, skutek gorszy
# niz wyglada: czlowiek zaczyna ROZLUZNIAC wzorzec --oczekuj, zeby „w koncu
# trafil" — czyli sam kasuje rozroznienie, dla ktorego to pole istnieje.
#
# `<<<` nie tworzy potoku, wiec nie ma SIGPIPE i nie ma czego psuc.
if grep -qE "$OCZEKUJ" <<< "$WYJSCIE_PO"; then
    PRZYCZYNA_OK=true
    ok "Test oblał Z OCZEKIWANEGO POWODU — wzorzec „$OCZEKUJ” wystąpił w wyjściu."
    grep -E "$OCZEKUJ" <<< "$WYJSCIE_PO" | head -3 | sed 's/^/    /'
else
    PRZYCZYNA_OK=false
    zle "Test oblał, ale NIE z oczekiwanego powodu — wzorca „$OCZEKUJ” nie ma w wyjściu."
    zle "Czerwień bywa awarią środowiska, literówką w mutacji albo innym testem."
    zle "To NIE jest dowód. Sprawdź wyjście i popraw --oczekuj albo mutację:"
    printf '%s\n' "$WYJSCIE_PO" | tail -15 | sed 's/^/    /'
    WERDYKT="ZLA_PRZYCZYNA"
    zapisz_json "$WERDYKT"
    exit 4
fi

# --- 4. Kontrola dodatnia PO przywróceniu -----------------------------------
# Przywracamy tu jawnie, żeby zdążyć jeszcze raz uruchomić test; `trap`
# i tak zrobi to powtórnie na wyjściu i sprawdzi sumę.
krok "4/4 — czy po przywróceniu źródła test znów przechodzi"
cp -p "$KOPIA" "$PLIK"
# Bez `touch`: `cp -p` wyżej oddaje mtime dokładnie, a ucięcie do pełnych sekund byłoby cofnięciem tej dokładności.
if "${POLECENIE[@]}" >/dev/null 2>&1; then
    KD_PO="PASS"
    ok "Test znów przechodzi. Pełny przebieg: PASS → FAIL → PASS."
else
    KD_PO="OBLANY"
    zle "Po przywróceniu test NIE przechodzi — drzewo nie wróciło do stanu wyjściowego."
    WERDYKT="PRZYWROCENIE_NIEUDANE"
    zapisz_json "$WERDYKT"
    exit 6
fi

WERDYKT="POTWIERDZONA"
zapisz_json "$WERDYKT"
printf '\n'
ok "KONTROLA UJEMNA POTWIERDZONA — $NAZWA"
[ -n "$JSON" ] && printf '  zapis: %s\n' "$JSON"
exit 0
