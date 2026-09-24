#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — czekanie na GOTOWE środowisko preview (.github/workflows/preview.yml)
# =============================================================================
#
#  BIBLIOTEKA DO `source`, nie program. Wczytanie niczego nie wykonuje.
#  Logika stoi tu, a nie w YAML-u, żeby dało się ją sprawdzić na atrapie `gh`
#  bez GitHub Actions i bez sieci: bash tests/skrypty/kontrola-czekania-preview.sh
#
#  czekaj_na_preview <owner/repo> <sha>                             (issue #1389)
#      Szuka deploymentu GitHuba dla DOKŁADNIE tego SHA w środowisku preview
#      (`pr-*` albo `*preview*`, także w postaci „<projekt> / pr-12”, bo tak
#      Railway nazywa środowiska — zmierzone w deploy.yml) i czeka na jego
#      NAJNOWSZY status. Sam obiekt deploymentu i jego adres NIE oznaczają
#      gotowości: Railway podaje adres, zanim obraz się zbuduje i kontener
#      wstanie. Gotowość to wyłącznie status `success`.
#
#      Zwraca 0 tylko po `success` z adresem przypiętym do statusu albo do tego
#      samego deploymentu. `failure`/`error`/`inactive`, `success` bez adresu,
#      brak deploymentu i upływ limitu zwracają 1 z komunikatem, co zrobić —
#      nie „pominięty test na zielono”.
#
#      Najwyżej PREVIEW_PROBY prób (domyślnie 40) co PREVIEW_ODSTEP sekund
#      (domyślnie 15). Ustawia PREVIEW_URL (bez końcowego ukośnika, pusty przy
#      porażce), PREVIEW_STAN (ostatni odczytany stan) i PREVIEW_DEPLOYMENT (id).
# =============================================================================

: "${PREVIEW_PROBY:=40}"
: "${PREVIEW_ODSTEP:=15}"

# Wybór deploymentu: ten SHA i środowisko preview; API zwraca od najnowszego.
_PREVIEW_JQ_DEPLOYMENT='
  [ .[]
    | select(.sha == $sha)
    | select(.environment | split("/") | last | gsub("\\s"; "") | test("^pr-|preview"))
  ][0] // empty
  | [ (.id | tostring), (.payload.web_url // ""), (.environment_url // "") ]
  | @tsv'

czekaj_na_preview() {
    local repo="$1" sha="${2,,}"
    local proba odpowiedz wiersz id url_dep url_env statusy stan url_statusu url

    PREVIEW_URL=""
    PREVIEW_STAN=""
    PREVIEW_DEPLOYMENT=""

    if [[ ! "$sha" =~ ^[0-9a-f]{40}$ ]]; then
        echo "BLAD  SHA '${2}' nie jest pełnym SHA commita (40 znaków szesnastkowych)"
        echo "      bez niego nie da się sprawdzić, KTÓRE wdrożenie testujemy"
        return 1
    fi

    for ((proba = 1; proba <= PREVIEW_PROBY; proba++)); do
        if [ "$proba" -gt 1 ]; then
            sleep "$PREVIEW_ODSTEP"
        fi

        if ! odpowiedz=$(gh api "repos/${repo}/deployments?sha=${sha}&per_page=20" 2>/dev/null); then
            PREVIEW_STAN="brak odpowiedzi API deploymentów"
            echo "..    próba ${proba}/${PREVIEW_PROBY}: ${PREVIEW_STAN}"
            continue
        fi

        wiersz=$(jq -r --arg sha "$sha" "$_PREVIEW_JQ_DEPLOYMENT" <<< "$odpowiedz" 2>/dev/null || true)
        if [ -z "$wiersz" ]; then
            PREVIEW_STAN="brak deploymentu preview dla ${sha}"
            echo "..    próba ${proba}/${PREVIEW_PROBY}: ${PREVIEW_STAN}"
            continue
        fi

        IFS=$'\t' read -r id url_dep url_env <<< "$wiersz"
        PREVIEW_DEPLOYMENT="$id"

        if ! statusy=$(gh api "repos/${repo}/deployments/${id}/statuses?per_page=1" 2>/dev/null); then
            PREVIEW_STAN="brak odpowiedzi API statusów deploymentu ${id}"
            echo "..    próba ${proba}/${PREVIEW_PROBY}: ${PREVIEW_STAN}"
            continue
        fi

        stan=$(jq -r '.[0].state // "brak statusu"' <<< "$statusy" 2>/dev/null || echo "nieczytelny status")
        url_statusu=$(jq -r '.[0].environment_url // ""' <<< "$statusy" 2>/dev/null || true)
        PREVIEW_STAN="$stan"

        case "$stan" in
            success)
                url="${url_statusu:-${url_dep:-$url_env}}"
                if [ -z "$url" ]; then
                    echo "BLAD  deployment ${id} ma status success, ale żadnego adresu"
                    echo "      ani w statusie, ani w deploymencie — nie wiem, co testować."
                    return 1
                fi
                PREVIEW_URL="${url%/}"
                echo "OK    deployment ${id} gotowy (success) pod ${PREVIEW_URL} (próba ${proba}/${PREVIEW_PROBY})"
                return 0
                ;;
            failure|error)
                echo "BLAD  wdrożenie preview się nie udało: deployment ${id}, status ${stan}"
                echo "      Sprawdź logi budowania środowiska PR w panelu Railway."
                return 1
                ;;
            inactive)
                echo "BLAD  deployment ${id} jest nieaktywny — zastąpiło go inne wdrożenie."
                echo "      Ten commit nie jest już tym, co działa w środowisku preview."
                return 1
                ;;
            *)
                # queued / pending / in_progress / brak statusu: adres mógł już
                # się pojawić, ale to nie jest gotowość (#1389).
                echo "..    próba ${proba}/${PREVIEW_PROBY}: deployment ${id} w stanie ${stan}, czekam na success"
                ;;
        esac
    done

    echo "BLAD  środowisko preview nie stało się gotowe (nieudane wszystkie próby: ${PREVIEW_PROBY})"
    echo "      ostatni stan: ${PREVIEW_STAN}"
    echo "      Sprawdź, czy PR Environments są włączone w Railway i czy wdrożenie się buduje."
    return 1
}
