#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — program testów mutacyjnych (tryb wsadowy kontroli ujemnych)
# =============================================================================
#
#  PO CO TO ISTNIEJE
#  Mamy ponad cztery tysiące testów i NIE WIEMY, ile z nich naprawdę czegoś
#  pilnuje. Test, który przeżywa mutację chronionego kodu, jest atrapą —
#  niezależnie od tego, jak ładnie się nazywa i ile ma asercji. Ten program
#  mierzy to systematycznie: bierze katalog mutacji, puszcza każdą przez
#  `scripts/kontrola-ujemna.sh` i liczy, ile PRZEŻYŁO.
#
#  Zaczynamy od miejsc, w których cena przeoczenia jest najwyższa:
#  autoryzacja i Policy, granice widoczności treści, `$fillable`, prywatność
#  w paczce z danymi, kasowanie konta i mediów, powiadomienia „Ugotowałem".
#
#  CZEGO TEN PROGRAM NIE ROBI I NIE BĘDZIE ROBIŁ
#  Nie rozstrzyga sam, czy przeżywająca mutacja to DZIURA W TEŚCIE, czy
#  MUTACJA BEZ ZNACZENIA (równoważna — zmienia kod, nie zmienia zachowania).
#  Tego nie da się zmierzyć maszyną i lista bez tego rozróżnienia jest
#  bezużyteczna: zniechęci wszystkich do narzędzia przy pierwszym fałszywym
#  alarmie. Program produkuje więc LISTĘ DO ROZSTRZYGNIĘCIA, a rozstrzyga
#  człowiek i zapisuje werdykt w kolumnie `ocena:` katalogu.
#
#  FORMAT KATALOGU (tests/mutacje/*.txt)
#  Bloki rozdzielone linią `---`. Wiersze `klucz: wartość`, pierwszy dwukropek
#  ze spacją rozdziela. Linie puste i zaczynające się od `#` są pomijane.
#
#      nazwa: Policy nie wpuszcza obcego na cudzy szkic
#      plik: app/Policies/RecipePolicy.php
#      zamien: $user->id === $recipe->author_id
#      na: $user->id !== $recipe->author_id
#      oczekuj: RecipePolicyTest
#      polecenie: vendor/bin/phpunit --no-coverage tests/Feature/RecipePolicyTest.php
#      ocena: (puste — do rozstrzygnięcia po przebiegu)
#      ---
#
#  UŻYCIE
#  Ścieżki w katalogu są względne wobec katalogu, z którego uruchamiasz program
#  — normalnie korzeń repozytorium.
#
#    scripts/mutacje.sh tests/mutacje/autoryzacja.txt
#    scripts/mutacje.sh tests/mutacje/*.txt --json storage/mutacje.json
#    scripts/mutacje.sh tests/mutacje/autoryzacja.txt --tylko 3   # sama trzecia
#
#  KOD WYJŚCIA
#    0  żadna mutacja nie przeżyła i żadna próba nie była wadliwa
#    1  co najmniej jedna mutacja PRZEŻYŁA (STRAZNIK_NIE_STRZEZE)
#    2  co najmniej jedna próba była WADLIWA (no-op, zła przyczyna, brak
#       kontroli dodatniej, niewykonane polecenie) — wynik nie jest pomiarem
# =============================================================================

set -uo pipefail

KORZEN="$(cd "$(dirname "$0")/.." && pwd)"
PRZYRZAD="$KORZEN/scripts/kontrola-ujemna.sh"
KATALOGI=(); JSON=""; TYLKO=""

while [ $# -gt 0 ]; do
    case "$1" in
        --json)  JSON="${2:-}"; shift 2 ;;
        --tylko) TYLKO="${2:-}"; shift 2 ;;
        -*) printf 'Nieznany argument: %s\n' "$1" >&2; exit 9 ;;
        *) KATALOGI+=("$1"); shift ;;
    esac
done

[ ${#KATALOGI[@]} -gt 0 ] || { printf 'Użycie: %s tests/mutacje/*.txt [--json plik]\n' "$0" >&2; exit 9; }
[ -x "$PRZYRZAD" ] || { printf 'Brak wykonywalnego %s (git update-index --chmod=+x)\n' "$PRZYRZAD" >&2; exit 9; }

CZERWONY='\033[0;31m'; ZIELONY='\033[0;32m'; ZOLTY='\033[0;33m'; SZARY='\033[0;90m'; RESET='\033[0m'

zabite=0; przezyly=0; wadliwe=0; numer=0
WIERSZE=()

# Jedna próba. Werdykt bierzemy z KODU WYJŚCIA przyrządu, nie z jego tekstu —
# tekst wolno zmienić, kod wyjścia jest umową.
proba() {
    local nazwa="$1" plik="$2" zamien="$3" na="$4" oczekuj="$5" polecenie="$6" ocena="$7"
    numer=$((numer + 1))
    if [ -n "$TYLKO" ] && [ "$TYLKO" != "$numer" ]; then return 0; fi

    printf "${SZARY}[%02d]${RESET} %s
" "$numer" "$nazwa"

    # Katalog jest liniowy, a mutacje bywaja wieloliniowe (np. dolozenie
    # kolumny do $fillable). Umowa formatu: dwuznak backslash-n w polach
    # `zamien` i `na` znaczy znak nowej linii. Zamiana siedzi tutaj, a nie
    # w przyrzadzie, bo to jest wlasciwosc FORMATU KATALOGU, nie kontroli.
    zamien="${zamien//\\n/$'\n'}"
    na="${na//\\n/$'\n'}"

    local log; log="$(mktemp)"
    # shellcheck disable=SC2086
    # Bez `cd` - sciezki z katalogu sa wzgledne wobec KATALOGU ROBOCZEGO,
    # z ktorego uruchomiono program (normalnie korzen repozytorium).
    "$PRZYRZAD" --nazwa "$nazwa" --plik "$plik" \
        --zamien "$zamien" --na "$na" --oczekuj "$oczekuj" -- $polecenie >"$log" 2>&1
    local kod=$?

    local werdykt symbol kolor
    case "$kod" in
        0) werdykt="ZABITA";              symbol="v"; kolor="$ZIELONY";  zabite=$((zabite + 1)) ;;
        3) werdykt="PRZEZYLA";            symbol="!"; kolor="$CZERWONY"; przezyly=$((przezyly + 1)) ;;
        2) werdykt="WADLIWA_NO_OP";       symbol="x"; kolor="$ZOLTY";    wadliwe=$((wadliwe + 1)) ;;
        4) werdykt="WADLIWA_ZLA_PRZYCZYNA"; symbol="x"; kolor="$ZOLTY";  wadliwe=$((wadliwe + 1)) ;;
        5) werdykt="WADLIWA_BRAK_KD";     symbol="x"; kolor="$ZOLTY";    wadliwe=$((wadliwe + 1)) ;;
        7) werdykt="WADLIWA_POLECENIE";   symbol="x"; kolor="$ZOLTY";    wadliwe=$((wadliwe + 1)) ;;
        *) werdykt="WADLIWA_KOD_$kod";    symbol="x"; kolor="$ZOLTY";    wadliwe=$((wadliwe + 1)) ;;
    esac
    printf "     ${kolor}%s %s${RESET}\n" "$symbol" "$werdykt"
    [ "$kod" -ne 0 ] && sed -n '/^.\[0;31m/p' "$log" | head -3 | sed 's/^/       /'

    WIERSZE+=("$(printf '%s\t%s\t%s\t%s\t%s' "$numer" "$werdykt" "$nazwa" "$plik" "${ocena:-—}")")
    rm -f "$log"
}

# --- Czytanie katalogu ------------------------------------------------------
for katalog in "${KATALOGI[@]}"; do
    [ -f "$katalog" ] || { printf 'Brak katalogu mutacji: %s\n' "$katalog" >&2; exit 9; }
    printf "\n${ZOLTY}══ %s ══${RESET}\n" "$katalog"
    nazwa=""; plik=""; zamien=""; na=""; oczekuj=""; polecenie=""; ocena=""
    while IFS= read -r linia || [ -n "$linia" ]; do
        case "$linia" in
            '#'*|'') continue ;;
            '---')
                if [ -n "$nazwa" ]; then proba "$nazwa" "$plik" "$zamien" "$na" "$oczekuj" "$polecenie" "$ocena"; fi
                nazwa=""; plik=""; zamien=""; na=""; oczekuj=""; polecenie=""; ocena=""
                continue ;;
        esac
        klucz="${linia%%: *}"; wartosc="${linia#*: }"
        # Wiersz „klucz:” bez wartości znaczy pusty łańcuch, nie całą linię.
        [ "$klucz" = "$linia" ] && { klucz="${linia%:}"; wartosc=""; }
        case "$klucz" in
            nazwa) nazwa="$wartosc" ;;
            plik) plik="$wartosc" ;;
            zamien) zamien="$wartosc" ;;
            na) na="$wartosc" ;;
            oczekuj) oczekuj="$wartosc" ;;
            polecenie) polecenie="$wartosc" ;;
            ocena) ocena="$wartosc" ;;
            *) printf 'Nieznany klucz w %s: %s\n' "$katalog" "$klucz" >&2; exit 9 ;;
        esac
    done < "$katalog"
    [ -n "$nazwa" ] && proba "$nazwa" "$plik" "$zamien" "$na" "$oczekuj" "$polecenie" "$ocena"
done

# --- Jedna tabela wyników ---------------------------------------------------
printf "\n${ZOLTY}══ Wynik ══${RESET}\n\n"
printf '%-4s %-22s %-52s %s\n' '#' 'werdykt' 'gwarancja' 'ocena człowieka'
printf '%-4s %-22s %-52s %s\n' '---' '---------------------' '---------------------------------------------------' '---------------'
for w in "${WIERSZE[@]}"; do
    IFS=$'\t' read -r n werd nz _pl oc <<< "$w"
    printf '%-4s %-22s %-52s %s\n' "$n" "$werd" "${nz:0:52}" "$oc"
done

razem=$((zabite + przezyly + wadliwe))
printf '\nMutacji: %s. ZABITE: %s. PRZEŻYŁY: %s. WADLIWE PRÓBY: %s.\n' "$razem" "$zabite" "$przezyly" "$wadliwe"
# Linia dla maszyn - bez polskich znakow i bez formatowania. Podsumowanie wyzej
# czyta czlowiek i wolno je przeredagowac; te linie czytaja skrypty i testy,
# wiec jej ksztalt jest umowa tak samo jak kody wyjscia.
printf 'PODSUMOWANIE mutacji=%s zabite=%s przezyly=%s wadliwe=%s
' "$razem" "$zabite" "$przezyly" "$wadliwe"

if [ "$wadliwe" -gt 0 ]; then
    printf "${ZOLTY}Wadliwe próby NIE SĄ pomiarem — napraw je, zanim policzysz wynik.${RESET}\n"
fi
if [ "$przezyly" -gt 0 ]; then
    printf "${CZERWONY}Każda mutacja, która przeżyła, wymaga ROZSTRZYGNIĘCIA:${RESET}\n"
    printf "  dziura w teście  → dopisz asercję i uruchom ponownie\n"
    printf "  mutacja bez znaczenia → wpisz uzasadnienie w pole 'ocena:' w katalogu\n"
    printf "Lista bez tego rozróżnienia jest bezużyteczna.\n"
fi

if [ -n "$JSON" ]; then
    mkdir -p "$(dirname "$JSON")"
    {
        printf '{\n  "data": "%s",\n  "mutacji": %s,\n  "zabite": %s,\n  "przezyly": %s,\n  "wadliwe": %s,\n  "proby": [\n' \
            "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$razem" "$zabite" "$przezyly" "$wadliwe"
        pierwszy=1
        for w in "${WIERSZE[@]}"; do
            IFS=$'\t' read -r n werd nz pl oc <<< "$w"
            [ $pierwszy -eq 1 ] || printf ',\n'; pierwszy=0
            printf '    {"nr": %s, "werdykt": "%s", "gwarancja": "%s", "plik": "%s", "ocena": "%s"}' \
                "$n" "$werd" "${nz//\"/\\\"}" "$pl" "${oc//\"/\\\"}"
        done
        printf '\n  ]\n}\n'
    } > "$JSON"
    printf 'Zapis: %s\n' "$JSON"
fi

[ "$wadliwe" -gt 0 ] && exit 2
[ "$przezyly" -gt 0 ] && exit 1
exit 0
