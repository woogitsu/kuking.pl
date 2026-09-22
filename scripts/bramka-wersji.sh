#!/usr/bin/env bash
#
# Kuking — bramka podbicia wersji.
#
# CO PILNUJE
# Reguły, która od 11 września 2026 stoi w komentarzu nad
# `config/kuking.php` → `wersja.etykieta`:
#
#   „CYFRA ROŚNIE PRZY KAŻDEJ ZMIANIE, KTÓRĄ CZŁOWIEK ZOBACZY (…).
#    KAŻDE PODBICIE CYFRY MA WPIS W `CHANGELOG.md` — jedno pilnuje drugiego."
#
# DLACZEGO TO W OGÓLE POWSTAŁO. Reguła istniała WYŁĄCZNIE jako komentarz.
# `tests/Feature/WersjaWStopceTest.php` sprawdza jedenaście rzeczy o tym, jak
# wersja się WYŚWIETLA, i ani jednej o tym, czy została PODBITA. Skutek dał się
# zmierzyć: etykieta `Alfa 0.67` weszła 18 września 2026 commitem 06c8c7e5
# i przestała się ruszać, mimo że przez kolejne cztery dni na `main` weszły
# 292 commity, w tym kilkanaście zmieniających rzeczy widoczne dla człowieka.
# Reguła bez bramki przestaje działać dokładnie w tym momencie, w którym
# zaczyna być potrzebna.
#
# CZEGO TA BRAMKA NIE PILNUJE — I KTO PILNUJE TEGO ZAMIAST NIEJ.
# Reguła ma dwa kierunki. „Podbito wersję ⇒ jest wpis w CHANGELOG-u" to
# niezmiennik stanu drzewa i sprawdza go `PodbicieWersjiWymagaWpisuWChangelog-
# Test` — bez gita, więc chodzi też tam, gdzie historii nie ma. Tutaj idzie
# kierunek drugi: „zmiana widoczna dla człowieka ⇒ podbicie", czyli pytanie
# o RÓŻNICĘ, którego bez zakresu gita nie da się zadać. Dwa strażniki, dwa
# różne pytania; żaden z nich nie łapie tego, co drugi.
#
# ZAKRES — „TO, CO WCHODZI DO MAIN", NIE POJEDYNCZY COMMIT.
# Gałąź ma zwykle kilka commitów, a wersję podbija się RAZ, na końcu.
# Bramka liczona per commit oblewałaby każdy commit poza ostatnim i nauczyłaby
# wszystkich ją obchodzić. Dlatego porównujemy punkt odcięcia gałęzi
# (`git merge-base`) ze szczytem — czyli cały wkład, który zobaczy `main`.
#
# FURTKA — `Bez-podbicia-wersji: <powód>` w treści dowolnego commita z zakresu.
# Zmiana w `resources/` bywa czysto techniczna i takie przypadki są w tej
# historii realne: `a25c52b3` skreśla reguły CSS bez nosiciela, `46ea92b5`
# usuwa martwą klasę `lead` z widoków, `feabfa4f` dokłada ekranom błędu
# nagłówki bezpieczeństwa. Żadna z nich nie zmienia niczego, co człowiek
# zobaczy, a bramka bez furtki kazałaby przy nich podbić wersję i dopisać
# wpis o niczym — czyli zaśmiecić CHANGELOG, którego ta sama reguła broni.
#
# Dlaczego linia w commicie, a nie plik-wyłącznik ani etykieta na PR:
#   * zostaje w historii na zawsze i widać ją w recenzji razem ze zmianą,
#     więc świadoma decyzja jest udokumentowana tam, gdzie jej szukamy;
#   * nie da się jej zapomnieć w repozytorium — plik `.bez-wersji` zostałby
#     po pierwszym użyciu i wyłączył bramkę na stałe, po cichu;
#   * działa lokalnie, bez sieci i bez GitHuba, więc ten sam przebieg da się
#     odtworzyć ręcznie przy diagnozie;
#   * wymaga POWODU (sama nazwa bez treści nie wystarcza), więc jest decyzją,
#     a nie odruchem.
#
# UŻYCIE
#   scripts/bramka-wersji.sh                # merge-base origin/main..HEAD
#   scripts/bramka-wersji.sh BAZA           # BAZA..HEAD
#   scripts/bramka-wersji.sh BAZA SZCZYT    # dokładny zakres (CI podaje base.sha)
#
# Wyjście: 0 — w porządku; 1 — brak podbicia; 2 — błąd użycia.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 2

CZERWONY='\033[0;31m'; ZIELONY='\033[0;32m'; RESET='\033[0m'

# WARSTWA WIDOCZNA DLA CZŁOWIEKA. Celowo wąska: szablony, style i skrypty
# przeglądarki. Nie ma tu `app/` ani `routes/` — tam zmiana widoczna dla
# człowieka prawie zawsze ma odpowiednik w widoku, a zmiana niewidoczna
# (walidacja, zapytanie, polityka) jest regułą, nie wyjątkiem. Szeroka lista
# oblewałaby częściej niż trafiała i to ją zabiłoby jako pierwsze.
WZORZEC_WIDOCZNE='^resources/(views|css|js)/'

# Testy JS leżą obok kodu, który testują, ale reguła wymienia je wprost jako
# zmianę BEZ śladu w interfejsie. Ten sam wyjątek dotyczy `*.test.mjs`.
WZORZEC_NIEWIDOCZNE='(\.test\.mjs|\.test\.js)$'

SZCZYT="${2:-HEAD}"

if [ "$#" -ge 1 ]; then
    BAZA="$1"
else
    # Punkt odcięcia od `main`. `origin/main` bywa nieosiągalne (świeży klon
    # bez remote'a, przebieg w kontenerze) — wtedy NIE ZGADUJEMY zakresu,
    # tylko mówimy to wprost i przepuszczamy. Bramka, która przy braku punktu
    # odniesienia wymyśla sobie zakres, oblewa losowo.
    _ref=""
    for _kandydat in origin/main main; do
        if git rev-parse --verify --quiet "$_kandydat^{commit}" >/dev/null; then
            _ref="$_kandydat"
            break
        fi
    done

    if [ -z "$_ref" ]; then
        printf "Bramka wersji: brak %s — nie ma z czym porównać zakresu. Pomijam.\n" "origin/main"
        exit 0
    fi

    BAZA="$(git merge-base "$_ref" "$SZCZYT" 2>/dev/null || true)"
    if [ -z "$BAZA" ]; then
        printf "Bramka wersji: brak wspólnego przodka z %s. Pomijam.\n" "$_ref"
        exit 0
    fi
fi

if ! git rev-parse --verify --quiet "$BAZA^{commit}" >/dev/null; then
    printf "Bramka wersji: nieosiągalna baza %s\n" "$BAZA" >&2
    exit 2
fi

ZMIENIONE="$(git diff --name-only "$BAZA" "$SZCZYT")"
WIDOCZNE="$(printf '%s\n' "$ZMIENIONE" \
    | grep -E "$WZORZEC_WIDOCZNE" \
    | grep -vE "$WZORZEC_NIEWIDOCZNE" || true)"

if [ -z "$WIDOCZNE" ]; then
    printf "${ZIELONY}✓ Bramka wersji: zakres %s..%s nie rusza warstwy widocznej dla człowieka.${RESET}\n" \
        "$(git rev-parse --short "$BAZA")" "$(git rev-parse --short "$SZCZYT")"
    exit 0
fi

# --- Furtka ---------------------------------------------------------------
# Czytamy PEŁNE treści commitów z zakresu (`%B`), więc linia może stać
# w stopce commita, w opisie scalenia albo w połączonym komunikacie squasha.
# Commit po commicie, a nie jednym strumieniem, żeby dało się POWIEDZIEĆ,
# kto tę decyzję podjął — furtka bez autora byłaby anonimowym wyłącznikiem.
FURTKA=""
for _sha in $(git rev-list "$BAZA".."$SZCZYT"); do
    _powod="$(git log -1 --format=%B "$_sha" \
        | grep -iE '^[[:space:]]*Bez-podbicia-wersji:[[:space:]]*[^[:space:]]' \
        | head -n 1 || true)"
    if [ -n "$_powod" ]; then
        FURTKA="$(git log -1 --format='%h %an: %s' "$_sha")
    $(printf '%s' "$_powod" | sed 's/^[[:space:]]*//')"
        break
    fi
done

if [ -n "$FURTKA" ]; then
    printf "${ZIELONY}✓ Bramka wersji: świadoma decyzja o pominięciu podbicia.${RESET}\n"
    printf '  %s\n' "$FURTKA"
    exit 0
fi

# --- Etykieta -------------------------------------------------------------
etykieta_z() {
    git show "$1:config/kuking.php" 2>/dev/null \
        | sed -n "s/^[[:space:]]*'etykieta'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" \
        | head -n 1
}

ETYKIETA_PRZED="$(etykieta_z "$BAZA")"
ETYKIETA_PO="$(etykieta_z "$SZCZYT")"

if [ -z "$ETYKIETA_PO" ]; then
    printf "${CZERWONY}✗ Bramka wersji: nie umiem odczytać 'wersja.etykieta' z config/kuking.php.${RESET}\n" >&2
    printf "  To jest błąd bramki albo zmiana kształtu klucza — napraw jedno z dwóch, nie omijaj.\n" >&2
    exit 1
fi

# --- CHANGELOG ------------------------------------------------------------
# Dwa warunki, bo każdy sam z osobna da się spełnić przypadkiem: plik musi
# być RUSZONY w tym zakresie i musi mieć nagłówek DOKŁADNIE tej etykiety.
CHANGELOG_RUSZONY="$(printf '%s\n' "$ZMIENIONE" | grep -xF 'CHANGELOG.md' || true)"
CHANGELOG_MA_WPIS="$(git show "$SZCZYT:CHANGELOG.md" 2>/dev/null \
    | grep -xF "## ${ETYKIETA_PO}" || true)"

# Nagłówek bywa w formie „## Alfa 0.68 — nazwa", więc dopuszczamy oba zapisy.
if [ -z "$CHANGELOG_MA_WPIS" ]; then
    CHANGELOG_MA_WPIS="$(git show "$SZCZYT:CHANGELOG.md" 2>/dev/null \
        | grep -E "^## ${ETYKIETA_PO}([[:space:]]|$)" || true)"
fi

BLAD=0
[ "$ETYKIETA_PRZED" = "$ETYKIETA_PO" ] && BLAD=1
[ -z "$CHANGELOG_RUSZONY" ] && BLAD=1
[ -z "$CHANGELOG_MA_WPIS" ] && BLAD=1

if [ "$BLAD" -eq 0 ]; then
    printf "${ZIELONY}✓ Bramka wersji: %s → %s, wpis w CHANGELOG.md jest.${RESET}\n" \
        "$ETYKIETA_PRZED" "$ETYKIETA_PO"
    exit 0
fi

printf "${CZERWONY}✗ Bramka wersji: zmiana dotyka warstwy widocznej dla człowieka, a wersja nie urosła.${RESET}\n" >&2
printf "\nZakres: %s..%s\n" "$(git rev-parse --short "$BAZA")" "$(git rev-parse --short "$SZCZYT")" >&2
printf "\nPliki z warstwy widocznej:\n" >&2
printf '%s\n' "$WIDOCZNE" | sed 's/^/  /' >&2

printf "\nCo nie gra:\n" >&2
if [ "$ETYKIETA_PRZED" = "$ETYKIETA_PO" ]; then
    printf "  • wersja stoi na '%s' — podbij cyfrę w config/kuking.php (wersja.etykieta).\n" "$ETYKIETA_PO" >&2
fi
if [ -z "$CHANGELOG_RUSZONY" ]; then
    printf "  • CHANGELOG.md nietknięty w tym zakresie.\n" >&2
fi
if [ -n "$CHANGELOG_RUSZONY" ] && [ -z "$CHANGELOG_MA_WPIS" ]; then
    printf "  • CHANGELOG.md nie ma nagłówka '## %s'.\n" "$ETYKIETA_PO" >&2
fi

cat >&2 <<'POMOC'

Dwie drogi wyjścia — obie są poprawne, wybierz świadomie:

  1. Człowiek to zobaczy. Podbij cyfrę w `config/kuking.php`
     (`wersja.etykieta`) i dopisz na górze `CHANGELOG.md` nagłówek
     `## <nowa etykieta> — <krótka nazwa>` z punktami językiem użytkownika.

  2. Człowiek tego nie zobaczy (martwy CSS, komentarz w Blade, nagłówek
     HTTP, przeniesienie pliku). Dopisz do treści dowolnego commita z tej
     gałęzi linię z POWODEM:

         Bez-podbicia-wersji: usunięcie reguł CSS bez nosiciela, render bez zmian

SŁOWO („Alfa"/„Beta") zmienia się osobno, przy kamieniach milowych
z `docs/ROADMAP.md` — nie tutaj.
POMOC

exit 1
