#!/usr/bin/env bash
# WYMAGA: WSL, katalogu /home/mateusz/flota, lustra worktree pod /mnt/c/... i klastra PostgreSQL 127.0.0.1:55439; poza tą maszyną nie zadziała. Tryb domyślny to podgląd.
# sprzataj-flote.sh — sprzątanie osieroconych runtime'ów WSL i baz PostgreSQL
# floty Kuking.pl. Uruchamia WYŁĄCZNIE właściciel, ręcznie.
#
# Tryby:
#   --podglad   (domyślny, też bez argumentów) — pokazuje, co BY skasował,
#               nic nie rusza.
#   --kasuj     — kasuje, ale pyta o potwierdzenie PRZED KAŻDĄ pozycją,
#               z osobna, podając nazwę i rozmiar.
#
# Zakres kandydatów pochodzi z RAPORT_MIEJSCA.md (21.09.2026), ALE ten raport
# jest tylko punktem startowym listy — środowisko żyje (kilkanaście agentów
# zakłada runtime'y w locie), więc każdy kandydat jest sprawdzany NA ŚWIEŻO,
# bezpośrednio przed decyzją, trzema niezależnymi testami:
#   1. `ps -eo args` — cokolwiek pasuje do nazwy → POMIŃ.
#   2. Katalog-odpowiednik w C:\Users\matma\Documents\kuking-flota\ → POMIŃ.
#   3. (tylko bazy) aktywne połączenia w pg_stat_activity → POMIŃ.
# Do tego twarda lista wykluczeń, sprawdzana zawsze, niezależnie od powyższego.
#
# Pułapki, których ten skrypt świadomie unika (patrz ZASADY_FLOTY.md /
# zlecenie właściciela):
#   - nie używa `grep -c` do zliczania trafień (drukuje 0 i zwraca kod 1),
#   - nie robi `grep -v ... > plik.n && mv plik.n plik`,
#   - nie pipe'uje do `grep -q` pod pipefail (SIGPIPE) — używa here-stringów,
#   - nigdy nie woła `git worktree prune`.

set -u
set -o pipefail

# --- Konfiguracja ---------------------------------------------------------

FLOTA_DIR="/home/mateusz/flota"
WORKTREE_MIRROR="/mnt/c/Users/matma/Documents/kuking-flota"
PGHOST="127.0.0.1"
PGPORT="55439"
PGUSER="kuking"
export PGPASSWORD="${PGPASSWORD:-kuking}"

# Twarda lista wykluczeń — NIGDY nie kasować, niezależnie od wyniku
# świeżych sprawdzeń. Dopasowanie dokładne do nazwy (runtime bez sufiksu
# -run, baza bez prefiksu kuking_flota_).
WYKLUCZONE_RUNTIME=(
    "push"
)
WYKLUCZONE_BAZY=(
    "gpt_dr_baza_source"
)

# Lista startowa kandydatów runtime'ów-sierot z RAPORT_MIEJSCA.md §2a.
# To jest PUNKT STARTOWY, nie wyrok — każdy jest weryfikowany na świeżo.
KANDYDACI_RUNTIME=(
    "BAZA-main"
    "gemini-2fa-ustawienia"
    "gemini-eksport"
    "gemini-harmonogram"
    "gemini-haslo-konto"
    "gemini-kontakt-formularz"
    "gemini-kontakt-panel"
    "gemini-moderacja-ai"
    "gemini-n1-powiadomienia"
    "gemini-onboarding"
    "gemini-pytania-eksport"
    "gemini-pytania-widoki"
    "gemini-skladniki"
    "gemini-tablica"
    "gemini-tagi"
    "gemini-turnstile-pomoc"
    "gemini-zalegle"
    "gemini-zdjecia-publikacja"
    "gemini-zeszyt-zapisy"
    "gpt-panel-moderacji-marka-php"
    "gpt-pytania-poradzcie-browser"
    "kontrola-923-main"
    "kontrola-main-cache"
)

# Lista startowa kandydatów baz-sierot z RAPORT_MIEJSCA.md §4a.
KANDYDACI_BAZY=(
    "kuking_flota_format"
    "kuking_flota_notyfikacja"
    "kuking_flota_gpttagi_kontrola"
    "kuking_flota_794"
)

# --- Tryb -------------------------------------------------------------

TRYB="podglad"
case "${1:-}" in
    --kasuj)
        TRYB="kasuj"
        ;;
    --podglad|"")
        TRYB="podglad"
        ;;
    *)
        echo "Nieznany argument: ${1:-}" >&2
        echo "Użycie: $0 [--podglad|--kasuj]" >&2
        exit 2
        ;;
esac

echo "=== sprzątanie floty Kuking.pl — tryb: $TRYB ==="
echo "Katalog runtime'ów: $FLOTA_DIR"
echo "Lustro worktree (Windows): $WORKTREE_MIRROR"
echo "PostgreSQL: ${PGHOST}:${PGPORT}"
echo

ROZMIAR_PRZED="$(du -sh "$FLOTA_DIR" 2>/dev/null | cut -f1)"
echo "Rozmiar ${FLOTA_DIR} PRZED: ${ROZMIAR_PRZED}"
echo

# --- Pomocnicze ---------------------------------------------------------

jest_wykluczony() {
    # $1 = nazwa, reszta = lista wykluczeń
    local nazwa="$1"; shift
    local w
    for w in "$@"; do
        if [ "$nazwa" = "$w" ]; then
            return 0
        fi
    done
    return 1
}

zawiera_proces() {
    # Zwraca 0 (prawda), jeśli JAKIKOLWIEK proces poza tym skryptem wspomina
    # nazwę. UWAGA: `ps -eo args | grep -F -- "$nazwa"` samo-dopasowuje się —
    # `grep`'a własny argv zawiera wzorzec, więc pojawia się w kolejnym
    # zrzucie `ps` jako "trafienie" na samego siebie. `pgrep -f` wyklucza
    # WŁASNY proces z definicji (sprawdzone: `pgrep -af -- "$nazwa"` nie
    # łapie samego siebie, mimo że wzorzec jest w jego własnym argv) — to
    # jedyny powód, dla którego używamy tu `pgrep`, a nie `ps | grep`.
    local nazwa="$1"
    local trafienia
    trafienia="$(pgrep -af -- "$nazwa" 2>/dev/null)"
    if [ -n "$trafienia" ]; then
        return 0
    fi
    return 1
}

ma_odpowiednik_windows() {
    local nazwa="$1"
    [ -d "${WORKTREE_MIRROR}/${nazwa}" ]
}

ma_aktywne_polaczenia_bazy() {
    local baza="$1"
    local liczba
    liczba="$(PGPASSWORD="$PGPASSWORD" psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d postgres -tAc \
        "select count(*) from pg_stat_activity where datname = '${baza}'" 2>/dev/null)"
    # Jeśli zapytanie się nie powiodło (np. baza nie istnieje już), traktuj
    # to jako "nie wiem, więc bezpieczniej pominąć" tylko gdy liczba jest pusta
    # i osobno sprawdzamy istnienie bazy niżej — tu tylko liczymy połączenia.
    if [ -z "$liczba" ]; then
        # Brak odpowiedzi z psql — ostrożnościowo traktuj jak "są połączenia"
        return 0
    fi
    if [ "$liczba" -gt 0 ] 2>/dev/null; then
        return 0
    fi
    return 1
}

baza_istnieje() {
    local baza="$1"
    local wynik
    wynik="$(PGPASSWORD="$PGPASSWORD" psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d postgres -tAc \
        "select 1 from pg_database where datname = '${baza}'" 2>/dev/null)"
    [ "$wynik" = "1" ]
}

rozmiar_katalogu() {
    local sciezka="$1"
    if [ -d "$sciezka" ]; then
        du -sh --apparent-size "$sciezka" 2>/dev/null | cut -f1
    else
        echo "0"
    fi
}

rozmiar_bazy() {
    local baza="$1"
    PGPASSWORD="$PGPASSWORD" psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d postgres -tAc \
        "select pg_size_pretty(pg_database_size('${baza}'))" 2>/dev/null
}

potwierdz() {
    # $1 = opis pozycji. Zwraca 0 gdy użytkownik potwierdził "tak".
    local opis="$1"
    local odpowiedz
    read -r -p "Skasować [$opis]? Wpisz 'tak' żeby potwierdzić: " odpowiedz
    [ "$odpowiedz" = "tak" ]
}

# --- Sekcja 1: runtime'y --------------------------------------------------

echo "--- 1. Runtime'y-kandydaci (${#KANDYDACI_RUNTIME[@]}) ---"
echo

SUMA_RUNTIME_BAJTY=0
LICZBA_SKASOWANYCH_RUNTIME=0

for nazwa in "${KANDYDACI_RUNTIME[@]}"; do
    sciezka="${FLOTA_DIR}/${nazwa}-run"
    echo "## ${nazwa}-run"

    if [ ! -d "$sciezka" ]; then
        echo "   -> nie istnieje (już posprzątane albo nigdy nie powstał) — pomijam."
        echo
        continue
    fi

    if jest_wykluczony "$nazwa" "${WYKLUCZONE_RUNTIME[@]}"; then
        echo "   -> POMIŃ: na twardej liście wykluczeń (nigdy nie kasować)."
        echo
        continue
    fi

    rozmiar="$(rozmiar_katalogu "$sciezka")"
    echo "   rozmiar: ${rozmiar}"

    if zawiera_proces "$nazwa"; then
        echo "   -> POMIŃ: żywy proces wspomina '${nazwa}' w \`ps\` TERAZ."
        echo
        continue
    fi

    if ma_odpowiednik_windows "$nazwa"; then
        echo "   -> POMIŃ: istnieje katalog-odpowiednik ${WORKTREE_MIRROR}/${nazwa}"
        echo
        continue
    fi

    echo "   -> świeże sprawdzenie: brak procesu, brak katalogu-odpowiednika. Kandydat do kasowania."

    if [ "$TRYB" = "podglad" ]; then
        echo "   [PODGLĄD] skasowałbym: ${sciezka} (${rozmiar})"
        echo
        continue
    fi

    # TRYB=kasuj
    if potwierdz "runtime ${nazwa}-run, ${rozmiar}, ${sciezka}"; then
        # Ostatnia, trzecia weryfikacja tuż przed `rm -rf` — środowisko mogło
        # się zmienić w czasie, gdy właściciel czytał pytanie.
        if zawiera_proces "$nazwa" || ma_odpowiednik_windows "$nazwa"; then
            echo "   -> ANULOWANO: stan zmienił się między pytaniem a potwierdzeniem. Nie kasuję."
            echo
            continue
        fi
        rm -rf -- "$sciezka"
        if [ -d "$sciezka" ]; then
            echo "   -> BŁĄD: katalog nadal istnieje po rm -rf."
        else
            echo "   -> skasowano ${sciezka} (${rozmiar})"
            LICZBA_SKASOWANYCH_RUNTIME=$((LICZBA_SKASOWANYCH_RUNTIME + 1))
        fi
    else
        echo "   -> pominięto na życzenie."
    fi
    echo
done

# --- Sekcja 2: bazy danych ------------------------------------------------

echo "--- 2. Bazy PostgreSQL-kandydaci (${#KANDYDACI_BAZY[@]}) ---"
echo

LICZBA_SKASOWANYCH_BAZ=0

for baza in "${KANDYDACI_BAZY[@]}"; do
    echo "## ${baza}"

    # Nazwa bez prefiksu kuking_flota_, do porównania z listą wykluczeń
    # i z katalogiem-odpowiednikiem po stronie Windows.
    nazwa_krotka="${baza#kuking_flota_}"

    if jest_wykluczony "$nazwa_krotka" "${WYKLUCZONE_BAZY[@]}"; then
        echo "   -> POMIŃ: na twardej liście wykluczeń (nigdy nie kasować)."
        echo
        continue
    fi

    if ! baza_istnieje "$baza"; then
        echo "   -> nie istnieje (już posprzątana albo nigdy nie powstała) — pomijam."
        echo
        continue
    fi

    rozmiar="$(rozmiar_bazy "$baza")"
    echo "   rozmiar: ${rozmiar}"

    if zawiera_proces "$baza"; then
        echo "   -> POMIŃ: żywy proces wspomina '${baza}' w \`ps\` TERAZ."
        echo
        continue
    fi

    if ma_odpowiednik_windows "$nazwa_krotka"; then
        echo "   -> POMIŃ: istnieje katalog-odpowiednik ${WORKTREE_MIRROR}/${nazwa_krotka}"
        echo
        continue
    fi

    if ma_aktywne_polaczenia_bazy "$baza"; then
        echo "   -> POMIŃ: aktywne połączenia w pg_stat_activity TERAZ."
        echo
        continue
    fi

    echo "   -> świeże sprawdzenie: brak procesu, brak katalogu-odpowiednika, brak aktywnych połączeń. Kandydat do kasowania."

    if [ "$TRYB" = "podglad" ]; then
        echo "   [PODGLĄD] skasowałbym bazę: ${baza} (${rozmiar})"
        echo
        continue
    fi

    # TRYB=kasuj
    if potwierdz "baza ${baza}, ${rozmiar}"; then
        # Ostatnia weryfikacja tuż przed DROP DATABASE.
        if zawiera_proces "$baza" || ma_aktywne_polaczenia_bazy "$baza"; then
            echo "   -> ANULOWANO: stan zmienił się między pytaniem a potwierdzeniem. Nie kasuję."
            echo
            continue
        fi
        if PGPASSWORD="$PGPASSWORD" psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d postgres \
            -c "DROP DATABASE \"${baza}\"" >/dev/null 2>&1; then
            echo "   -> skasowano bazę ${baza} (${rozmiar})"
            LICZBA_SKASOWANYCH_BAZ=$((LICZBA_SKASOWANYCH_BAZ + 1))
        else
            echo "   -> BŁĄD: DROP DATABASE nie powiodło się."
        fi
    else
        echo "   -> pominięto na życzenie."
    fi
    echo
done

# --- Podsumowanie ----------------------------------------------------------

echo "=== Podsumowanie ==="
echo "Tryb: $TRYB"
echo "Runtime'y skasowane: $LICZBA_SKASOWANYCH_RUNTIME / ${#KANDYDACI_RUNTIME[@]} kandydatów"
echo "Bazy skasowane: $LICZBA_SKASOWANYCH_BAZ / ${#KANDYDACI_BAZY[@]} kandydatów"
echo
ROZMIAR_PO="$(du -sh "$FLOTA_DIR" 2>/dev/null | cut -f1)"
echo "Rozmiar ${FLOTA_DIR} PRZED: ${ROZMIAR_PRZED}"
echo "Rozmiar ${FLOTA_DIR} PO:    ${ROZMIAR_PO}"
