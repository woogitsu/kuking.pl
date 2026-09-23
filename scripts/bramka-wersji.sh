#!/usr/bin/env bash
#
# Kuking — bramka wpisu w CHANGELOG.
#
# CO PILNUJE
# Zmiana, którą człowiek zobaczy, zostawia ślad w `CHANGELOG.md`: przynajmniej
# jedną linię `- …` dopisaną w sekcji `## Nieopublikowane` na górze pliku.
# Reguła stoi w AGENTS.md („Wersja i CHANGELOG") i w komentarzu nad
# `config/kuking.php` → `wersja.etykieta`.
#
# DLACZEGO WPIS, A NIE PODBICIE NUMERU (decyzja właściciela, 23.09.2026).
# Pierwsza wersja tej bramki kazała każdemu PR-owi podbić `wersja.etykieta`.
# Przy kilkunastu równoległych PR-ach każdy podbija tę samą linię na ten sam
# numer — i każdy kolejny scala się z konfliktem w `config/kuking.php`
# i w nagłówku CHANGELOG-u. Dlatego PR dopisuje tylko linię w sekcji
# „Nieopublikowane" (linie dopisane w różnych miejscach listy git scala sam),
# a numer rośnie RAZ, przy wydaniu: wtedy lista przechodzi pod nowy nagłówek
# `## Alfa 0.N — …`.
#
# DLACZEGO TA BRAMKA W OGÓLE POWSTAŁA. Reguła istniała wyłącznie jako
# komentarz: etykieta `Alfa 0.67` weszła 18.09.2026 (06c8c7e5) i stała przez
# 292 commity na `main`, w tym kilkanaście widocznych dla człowieka. Reguła
# bez bramki przestaje działać dokładnie wtedy, gdy zaczyna być potrzebna.
#
# ZAKRES — „TO, CO WCHODZI DO MAIN", NIE POJEDYNCZY COMMIT.
# Gałąź ma kilka commitów, a wpis dopisuje się raz. Porównujemy bazę (w CI:
# `base.sha` PR-a, lokalnie: `git merge-base origin/main HEAD`) ze szczytem.
#
# TRZY DROGI NA ZIELONO, gdy zakres rusza warstwę widoczną:
#   1. w diffie `CHANGELOG.md` przybyła linia `- …` wewnątrz sekcji
#      `## Nieopublikowane` (liczymy numer linii w pliku po zmianie,
#      nie samą treść — linia dopisana pod nagłówkiem starej wersji
#      NIE jest wpisem do nieopublikowanych);
#   2. WYDANIE: `wersja.etykieta` urosła, a CHANGELOG ma nagłówek
#      `## <nowa etykieta>` — PR wydania przenosi listę, nie dopisuje do niej;
#   3. FURTKA: linia `Bez-podbicia-wersji: <powód>` w opisie PR-a
#      (zmienna `KUKING_OPIS_PR`, podaje ją CI) albo w treści dowolnego
#      commita z zakresu. Wymaga POWODU — sama nazwa nie wystarcza.
#      Zmiana w `resources/` bywa czysto techniczna (a25c52b3 skreśla martwy
#      CSS, 46ea92b5 usuwa martwą klasę z widoków); bez furtki bramka
#      kazałaby dopisać wpis o niczym i zaśmiecić CHANGELOG, którego broni.
#      Nazwa furtki zostaje z pierwszej wersji bramki, bo tak ją zna
#      opis decyzji i historia commitów.
#
# UŻYCIE
#   scripts/bramka-wersji.sh                # merge-base origin/main..HEAD
#   scripts/bramka-wersji.sh BAZA           # BAZA..HEAD
#   scripts/bramka-wersji.sh BAZA SZCZYT    # dokładny zakres (CI podaje base.sha)
#   KUKING_OPIS_PR="…" scripts/bramka-wersji.sh BAZA SZCZYT
#
# Wyjście: 0 — w porządku; 1 — brak wpisu; 2 — błąd użycia.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 2

CZERWONY='\033[0;31m'; ZIELONY='\033[0;32m'; RESET='\033[0m'

# WARSTWA WIDOCZNA DLA CZŁOWIEKA — ta sama lista co w AGENTS.md: szablony,
# style, skrypty przeglądarki, teksty interfejsu i pliki podawane wprost
# (`public/`: manifest, strona offline, ikony). Nie ma tu `app/` ani `routes/`:
# zmiana niewidoczna (walidacja, zapytanie, polityka) jest tam regułą,
# a szeroka lista oblewałaby częściej, niż trafiała.
WZORZEC_WIDOCZNE='^(resources/(views|css|js)|lang|public)/'

# Testy JS leżą obok kodu, który testują, ale śladu w interfejsie nie mają.
WZORZEC_NIEWIDOCZNE='\.test\.m?js$'

WZORZEC_FURTKI='^[[:space:]]*Bez-podbicia-wersji:[[:space:]]*[^[:space:]]'
NAGLOWEK_NIEOPUBLIKOWANE='## Nieopublikowane'

SZCZYT="${2:-HEAD}"

if [ "$#" -ge 1 ]; then
    BAZA="$1"
else
    # `origin/main` bywa nieosiągalne (świeży klon bez remote'a) — wtedy NIE
    # ZGADUJEMY zakresu, tylko mówimy to wprost i przepuszczamy.
    _ref=""
    for _kandydat in origin/main main; do
        if git rev-parse --verify --quiet "$_kandydat^{commit}" >/dev/null; then
            _ref="$_kandydat"
            break
        fi
    done

    if [ -z "$_ref" ]; then
        printf "Bramka wersji: brak origin/main — nie ma z czym porównać zakresu. Pomijam.\n"
        exit 0
    fi

    BAZA="$(git merge-base "$_ref" "$SZCZYT" 2>/dev/null || true)"
    if [ -z "$BAZA" ]; then
        printf "Bramka wersji: brak wspólnego przodka z %s. Pomijam.\n" "$_ref"
        exit 0
    fi
fi

for _rev in "$BAZA" "$SZCZYT"; do
    if ! git rev-parse --verify --quiet "$_rev^{commit}" >/dev/null; then
        printf "Bramka wersji: nieosiągalny commit %s\n" "$_rev" >&2
        exit 2
    fi
done

ZAKRES="$(git rev-parse --short "$BAZA")..$(git rev-parse --short "$SZCZYT")"
ZMIENIONE="$(git diff --name-only "$BAZA" "$SZCZYT")"
WIDOCZNE="$(printf '%s\n' "$ZMIENIONE" \
    | grep -E "$WZORZEC_WIDOCZNE" \
    | grep -vE "$WZORZEC_NIEWIDOCZNE" || true)"

if [ -z "$WIDOCZNE" ]; then
    printf "${ZIELONY}✓ Bramka wersji: zakres %s nie rusza warstwy widocznej dla człowieka.${RESET}\n" "$ZAKRES"
    exit 0
fi

# --- 1. Linia dopisana w sekcji „Nieopublikowane" -------------------------
# Granice sekcji w pliku PO zmianie: od nagłówka do następnego `## ` (albo
# końca pliku). Potem numery linii dodanych w diffie (`-U0`, nagłówki hunków
# `@@ -a,b +c,d @@`) i pytanie, czy któraś dodana linia `- …` leży w środku.
ZAKRES_SEKCJI="$(git show "$SZCZYT:CHANGELOG.md" 2>/dev/null | awk -v naglowek="$NAGLOWEK_NIEOPUBLIKOWANE" '
    $0 == naglowek && !start { start = NR; next }
    start && !koniec && /^## / { koniec = NR - 1 }
    END { if (start) { if (!koniec) koniec = NR; print start, koniec } }
')"

WPIS=""
if [ -n "$ZAKRES_SEKCJI" ]; then
    read -r OD DO <<<"$ZAKRES_SEKCJI"
    WPIS="$(git diff -U0 "$BAZA" "$SZCZYT" -- CHANGELOG.md | awk -v od="$OD" -v do_="$DO" '
        /^@@ / {
            split($3, nowe, ",")
            linia = substr(nowe[1], 2) + 0
            next
        }
        /^\+\+\+ / { next }
        /^\+/ {
            if (linia > od && linia <= do_ && $0 ~ /^\+[-*][[:space:]]+[^[:space:]]/) {
                print substr($0, 2)
                exit
            }
            linia++
        }
    ')"
fi

if [ -n "$WPIS" ]; then
    printf "${ZIELONY}✓ Bramka wersji: zakres %s dopisuje do „Nieopublikowane”:${RESET}\n  %s\n" "$ZAKRES" "$WPIS"
    exit 0
fi

# --- 2. Wydanie ------------------------------------------------------------
etykieta_z() {
    git show "$1:config/kuking.php" 2>/dev/null \
        | sed -n "s/^[[:space:]]*'etykieta'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" \
        | head -n 1
}

ETYKIETA_PRZED="$(etykieta_z "$BAZA")"
ETYKIETA_PO="$(etykieta_z "$SZCZYT")"

if [ -n "$ETYKIETA_PO" ] && [ "$ETYKIETA_PRZED" != "$ETYKIETA_PO" ] \
   && git show "$SZCZYT:CHANGELOG.md" 2>/dev/null \
        | grep -qxE "## ${ETYKIETA_PO}( — .*)?" ; then
    printf "${ZIELONY}✓ Bramka wersji: wydanie %s → %s z nagłówkiem w CHANGELOG.md.${RESET}\n" \
        "$ETYKIETA_PRZED" "$ETYKIETA_PO"
    exit 0
fi

# --- 3. Furtka -------------------------------------------------------------
FURTKA=""
_z_opisu="$(printf '%s\n' "${KUKING_OPIS_PR:-}" | tr -d '\r' | grep -iE "$WZORZEC_FURTKI" | head -n 1 || true)"
if [ -n "$_z_opisu" ]; then
    FURTKA="opis PR-a: $(printf '%s' "$_z_opisu" | sed 's/^[[:space:]]*//')"
else
    # Commit po commicie, żeby dało się POWIEDZIEĆ, kto podjął decyzję.
    for _sha in $(git rev-list "$BAZA".."$SZCZYT"); do
        _powod="$(git log -1 --format=%B "$_sha" | grep -iE "$WZORZEC_FURTKI" | head -n 1 || true)"
        if [ -n "$_powod" ]; then
            FURTKA="$(git log -1 --format='%h %an: %s' "$_sha")
    $(printf '%s' "$_powod" | sed 's/^[[:space:]]*//')"
            break
        fi
    done
fi

if [ -n "$FURTKA" ]; then
    printf "${ZIELONY}✓ Bramka wersji: świadoma decyzja — zmiana bez wpisu w CHANGELOG.md.${RESET}\n"
    printf '  %s\n' "$FURTKA"
    exit 0
fi

# --- Czerwień --------------------------------------------------------------
printf "${CZERWONY}✗ Bramka wersji: zmiana dotyka warstwy widocznej dla człowieka, a CHANGELOG.md nie ma nowego wpisu w „Nieopublikowane”.${RESET}\n" >&2
printf "\nZakres: %s\n\nPliki z warstwy widocznej:\n" "$ZAKRES" >&2
printf '%s\n' "$WIDOCZNE" | sed 's/^/  /' >&2
if [ -z "$ZAKRES_SEKCJI" ]; then
    printf "\n  • CHANGELOG.md nie ma sekcji '%s' — dodaj ją na samej górze, nad najnowszą wersją.\n" "$NAGLOWEK_NIEOPUBLIKOWANE" >&2
fi

cat >&2 <<'POMOC'

Dwie drogi wyjścia — obie są poprawne, wybierz świadomie:

  1. Człowiek to zobaczy. Dopisz w `CHANGELOG.md`, w sekcji
     `## Nieopublikowane` na górze pliku, linię językiem użytkownika:

         - Pole filtra mieści się w wąskim oknie także przy dużym piśmie.

     NIE podbijaj numeru w `config/kuking.php` — ten rośnie raz, przy wydaniu.

  2. Człowiek tego nie zobaczy (martwy CSS, komentarz w Blade, nagłówek
     HTTP, przeniesienie pliku). Dopisz do opisu PR-a albo do treści
     dowolnego commita z tej gałęzi linię z POWODEM:

         Bez-podbicia-wersji: usunięcie reguł CSS bez nosiciela, render bez zmian
POMOC

exit 1
