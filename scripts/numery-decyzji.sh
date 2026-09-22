#!/usr/bin/env bash
#
# STRAŻNIK NUMERACJI DECYZJI — ZAPALA SIĘ PRZED SCALENIEM, NIE PO (D-233).
#
# ─────────────────────────────────────────────────────────────────────────
#  PO CO TO ISTNIEJE, SKORO JEST `NumeryDecyzjiMajaWpisyTest`
# ─────────────────────────────────────────────────────────────────────────
#
# Bo tamten test czyta JEDEN plik: `docs/DECISIONS.md` w drzewie, na którym
# akurat stoi. Dwie gałęzie, z których każda dopisuje własny wpis pod tym samym
# numerem, są wtedy OBIE zielone — duplikat powstaje dopiero w chwili, gdy
# druga z nich zostanie scalona. Czyli mechanizm działa, tylko o jedno
# scalenie za późno: numer jest już w kodzie, odnośniki już go cytują,
# a przenumerowanie kosztuje przepięcie wszystkich.
#
# UWAGA NA DIAGNOZĘ: ten sam numer na kilkunastu gałęziach to zwykle TA SAMA
# decyzja rozniesiona przez scalenia, a nie spór. Numeracja w tym repozytorium
# jest UZGODNIONA, nie rozjechana (D-235: D-223 kaskada, D-227 #1164,
# D-228 #966, D-229 #1180, D-230 #1168). Skrypt liczy więc wyłącznie numery
# dołożone PONAD `origin/main` po obu stronach — tylko to jest rezerwacja,
# a nie ślad po scaleniu.
#
# Kolizja zmierzona 22 września: #1222 (`naprawa/skladnik-bez-ilosci-jeden-kontrakt`)
# wziął D-227, należący do #1164 (`flota/prog-postgresa`). Osobno obie gałęzie
# są zielone. Poprawia to strona, która wzięła numer cudzy.
#
# Ten skrypt patrzy szerzej: na `origin/main` ORAZ na wszystkie gałęzie
# zdalne. Odpowiada na pytanie, którego pojedynczy test zadać nie może —
# „czy numer, który właśnie biorę, jest już czyjąś rezerwacją".
#
# ─────────────────────────────────────────────────────────────────────────
#  CO ZGŁASZA
# ─────────────────────────────────────────────────────────────────────────
#
#  1. DUPLIKAT LOKALNY — dwa nagłówki `## D-NNN` pod jednym numerem w pliku.
#     To samo co test, tylko szybciej i bez bazy danych.
#  2. KOLIZJA MIĘDZY GAŁĘZIAMI — numer dopisany na tej gałęzi (nie ma go na
#     `origin/main`) występuje też na innej gałęzi zdalnej, która też go tam
#     nie ma z `main`. To jest ta awaria, po którą ten plik powstał.
#  3. NAGŁÓWEK POZA FORMATEM — wiersz, który wygląda na wpis dziennika, a nie
#     jest `## D-NNN`: „## D-1009-ROBOCZA", „## Uzupełnienie #369". Oba stały
#     na `main` 22 września. Taki wpis jest NIEWIDOCZNY dla strażników —
#     nie liczy się ani jako istniejący, ani jako duplikat.
#
# ─────────────────────────────────────────────────────────────────────────
#  CZEGO NIE ROBI — I TO JEST DECYZJA, NIE BRAK
# ─────────────────────────────────────────────────────────────────────────
#
# Nie przenumerowuje niczego sam i nie rusza CUDZYCH gałęzi. Przy kolizji
# mówi, kto jeszcze ma ten numer, i zostawia rozstrzygnięcie człowiekowi —
# bo „kto ustępuje" zależy od tego, ile odnośników stoi już po której
# stronie, a tego skrypt nie wyceni (tak właśnie rozstrzygnięto D-228).
#
# Bez dostępu do gałęzi zdalnych (płytki klon, brak sieci) NIE UDAJE, że
# sprawdził: kontrola 2 kończy się wtedy jawnym ostrzeżeniem i kodem 0,
# a kontrole 1 i 3 chodzą dalej. Cicha zieleń jest gorsza niż brak kontroli.
#
#   ./scripts/numery-decyzji.sh                  # pełna kontrola
#   ./scripts/numery-decyzji.sh --nastepny-wolny # podpowiedz numer do wzięcia
#   ./scripts/numery-decyzji.sh --kontrola-ujemna # sprawdź, że strażnik wykrywa

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

DZIENNIK="docs/DECISIONS.md"
BAZOWA="${GALAZ_BAZOWA:-origin/main}"

CZERWONY='\033[0;31m'; ZIELONY='\033[0;32m'; ZOLTY='\033[0;33m'; RESET='\033[0m'
BLEDY=0

ok()  { printf "${ZIELONY}✓ %s${RESET}\n" "$1"; }
zle() { printf "${CZERWONY}✗ %s${RESET}\n" "$1"; BLEDY=$((BLEDY + 1)); }
uwaga() { printf "${ZOLTY}! %s${RESET}\n" "$1"; }

# Numery wpisów z treści podanej na wejściu standardowym.
numery() { grep -oE '^## D-[0-9]{3}\b' | sed 's/^## //' | sort -u; }

numery_z_ref() { git show "$1:$DZIENNIK" 2>/dev/null | numery; }

# ---------------------------------------------------------------------------
# --nastepny-wolny: O JEDEN WYŻEJ NIŻ NAJWYŻSZY UŻYTY GDZIEKOLWIEK.
#
# Kusiło, żeby oddawać najniższy numer, którego nigdzie nie widać — luki
# w numeracji wyglądają jak marnotrawstwo. Zmierzone: taka reguła oddaje
# tu **D-067**, czyli numer ZAREZERWOWANY, o którym piszą przekazania pracy
# i który `NumeryDecyzjiMajaWpisyTest` wymienia wprost jako legalnie pusty.
# Obok niego tak samo stoją D-070, D-073, D-074 (rezerwacje), D-084, D-086,
# D-094 (puste świadomie i na stałe) oraz D-108…D-112 (odstęp od numeracji
# systemu projektowego v3.1).
#
# Luka jest więc NOŚNIKIEM INFORMACJI, a nie miejscem do zapełnienia, i tego
# skrypt z samego pliku nie odróżni. „O jeden wyżej niż maksimum" jest regułą,
# która nie wymaga od nikogo pamiętania listy wyjątków — a to jest tu jedyna
# waluta, bo listę wyjątków raz już zestarzała się w tym repozytorium.
#
# Patrzymy na WSZYSTKIE gałęzie zdalne, nie na `main`: numer wzięty wczoraj
# na cudzej gałęzi jest zajęty, choć na `main` go jeszcze nie ma.
# ---------------------------------------------------------------------------
if [ "${1:-}" = "--nastepny-wolny" ]; then
    NAJWYZSZY=$(
        { numery < "$DZIENNIK"
          for ref in $(git for-each-ref --format='%(refname:short)' refs/remotes 2>/dev/null); do
              numery_z_ref "$ref"
          done
        } | sed 's/^D-//' | sort -n | tail -1
    )

    if [ -z "$NAJWYZSZY" ]; then
        zle "Nie znalazłem ANI JEDNEGO numeru — to usterka strażnika, nie dziennika."
        exit 1
    fi

    printf 'D-%03d\n' "$((10#$NAJWYZSZY + 1))"
    exit 0
fi

# ---------------------------------------------------------------------------
# --kontrola-ujemna: strażnik, którego nikt nie sprawdził, jest opinią.
#
# Podstawiamy dziennik z jawnie zepsutą numeracją i wymagamy, żeby skrypt
# to ZGŁOSIŁ. Bez tego zielony wynik nie mówi „numeracja jest w porządku",
# tylko „coś się wykonało" (docs/PULAPKI_TESTOW.md, pułapka 2).
# ---------------------------------------------------------------------------
if [ "${1:-}" = "--kontrola-ujemna" ]; then
    KATALOG=$(mktemp -d)
    trap 'rm -rf "$KATALOG"' EXIT
    mkdir -p "$KATALOG/docs" "$KATALOG/scripts"
    cp "$0" "$KATALOG/scripts/"
    {
        printf '## D-501 — pierwsza\n\ntresc\n\n'
        printf '## D-501 — druga, ten sam numer\n\ntresc\n\n'
        printf '## D-5009-ROBOCZA — poza formatem\n\ntresc\n'
        printf '## Uzupelnienie #369 — bez numeru\n\ntresc\n'
    } > "$KATALOG/docs/DECISIONS.md"

    WYNIK=$(cd "$KATALOG" && bash "scripts/$(basename "$0")" 2>&1)
    KOD=$?

    if [ "$KOD" -eq 0 ]; then
        printf "${CZERWONY}✗ Kontrola ujemna: strażnik PRZEPUŚCIŁ zepsutą numerację.${RESET}\n"
        printf '%s\n' "$WYNIK"
        exit 1
    fi

    for szukane in 'D-501' 'D-5009-ROBOCZA' 'Uzupelnienie'; do
        if ! printf '%s' "$WYNIK" | grep -q "$szukane"; then
            printf "${CZERWONY}✗ Kontrola ujemna: strażnik nie nazwał usterki „%s\".${RESET}\n" "$szukane"
            printf '%s\n' "$WYNIK"
            exit 1
        fi
    done

    printf "${ZIELONY}✓ Kontrola ujemna: strażnik wykrył i NAZWAŁ wszystkie trzy usterki.${RESET}\n"
    exit 0
fi

if [ ! -f "$DZIENNIK" ]; then
    zle "Nie ma $DZIENNIK — strażnik nie ma czego pilnować."
    exit 1
fi

# --- 1. Duplikat w samym pliku --------------------------------------------
DUPLIKATY=$(grep -oE '^## D-[0-9]{3}\b' "$DZIENNIK" | sed 's/^## //' | sort | uniq -d)

if [ -n "$DUPLIKATY" ]; then
    zle "W $DZIENNIK ten sam numer ma więcej niż jeden wpis: $(echo "$DUPLIKATY" | tr '\n' ' ')"
    printf '  Kod odsyłający do tego numeru trafia w dwie decyzje naraz.\n'
    printf '  Nadaj jednemu z wpisów numer z `%s --nastepny-wolny` i przepnij odnośniki.\n' "$0"
else
    ok "Brak duplikatów wewnątrz dziennika"
fi

# --- 2. Nagłówki poza formatem --------------------------------------------
#
# Szukamy nagłówków drugiego poziomu, które NIE są `## D-NNN`, a wyglądają na
# wpis dziennika: zaczynają się od „D-" w innym kształcie albo od słowa
# „Uzupełnienie". Reszta nagłówków (spis treści, sekcje wprowadzające) ma
# prawo istnieć i jej nie ruszamy.
POZA_FORMATEM=$(grep -nE '^## (D-[^ ]*|Uzupe[łl]nienie)' "$DZIENNIK" \
    | grep -vE '^[0-9]+:## D-[0-9]{3}( |$)' || true)

if [ -n "$POZA_FORMATEM" ]; then
    zle 'Nagłówki poza formatem "## D-NNN" — strażniki ich NIE WIDZĄ:'
    printf '%s\n' "$POZA_FORMATEM" | sed 's/^/    /'
    printf '  Wpis w takim kształcie nie liczy się ani jako istniejący, ani jako\n'
    printf '  duplikat, a odnośnik do niego z kodu byłby martwy.\n'
else
    ok 'Wszystkie nagłówki wpisów mają format "## D-NNN"'
fi

# --- 3. Kolizja z innymi gałęziami -----------------------------------------
if ! git rev-parse --verify --quiet "$BAZOWA" >/dev/null; then
    uwaga "Nie widzę $BAZOWA — kontroli międzygałęziowej NIE WYKONANO."
    printf '  Pobierz gałęzie (`git fetch --filter=blob:none origin "+refs/heads/*:refs/remotes/origin/*"`)\n'
    printf '  i uruchom ponownie. Ten wynik NIE znaczy, że kolizji nie ma.\n'
else
    NA_BAZOWEJ=$(numery_z_ref "$BAZOWA")
    MOJE=$(comm -23 <(numery < "$DZIENNIK") <(printf '%s\n' "$NA_BAZOWEJ"))

    if [ -z "$MOJE" ]; then
        ok "Ta gałąź nie dokłada żadnego numeru decyzji"
    else
        KOLIZJE=0

        for ref in $(git for-each-ref --format='%(refname:short)' refs/remotes 2>/dev/null); do
            case "$ref" in
                "$BAZOWA"|*/HEAD) continue ;;
            esac

            # Numery, które TA gałąź dokłada ponad `main` — cudze rezerwacje.
            CUDZE=$(comm -23 <(numery_z_ref "$ref") <(printf '%s\n' "$NA_BAZOWEJ"))
            WSPOLNE=$(comm -12 <(printf '%s\n' "$MOJE") <(printf '%s\n' "$CUDZE"))

            if [ -n "$WSPOLNE" ]; then
                zle "Numer $(echo "$WSPOLNE" | tr '\n' ' ')jest już rezerwacją gałęzi $ref"
                KOLIZJE=$((KOLIZJE + 1))
            fi
        done

        if [ "$KOLIZJE" -eq 0 ]; then
            ok "Numery tej gałęzi ($(echo "$MOJE" | tr '\n' ' ')) są wolne na wszystkich gałęziach zdalnych"
        else
            printf '  NIE przenumerowuj cudzej gałęzi — ona jest w robocie u kogoś innego,\n'
            printf '  a przydziały bywają UZGODNIONE z właścicielem (D-235). Numer ustępuje\n'
            printf '  ta strona, która wzięła cudzy: weź inny z `%s --nastepny-wolny`\n' "$0"
            printf '  albo zapytaj właściciela, u kogo numer zostaje.\n'
        fi
    fi
fi

if [ "$BLEDY" -gt 0 ]; then
    printf "\n${CZERWONY}Numeracja decyzji: %s usterek.${RESET}\n" "$BLEDY"
    exit 1
fi

printf "\n${ZIELONY}Numeracja decyzji w porządku.${RESET}\n"
