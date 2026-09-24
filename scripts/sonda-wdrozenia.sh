#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — sondy testu dymnego po wdrożeniu (.github/workflows/deploy.yml)
# =============================================================================
#
#  BIBLIOTEKA DO `source`, nie program. Wczytanie niczego nie wykonuje.
#  Logika stoi tu, a nie w YAML-u, żeby dało się ją sprawdzić na atrapach curl
#  bez GitHub Actions i bez sieci: bash tests/skrypty/kontrola-sondy-wdrozenia.sh
#
#  sonda_wydanie <base_url> <oczekiwany_sha>                        (issue #1012)
#      Czy pod adresem działa DOKŁADNIE wdrażany commit. Pyta `/wydanie`
#      (pełny SHA, `no-store`) do skutku, najwyżej SONDA_PROBY razy co
#      SONDA_ODSTEP sekund. Bez tego zielony wynik testu zdarzenia A mógł
#      opisywać wydanie B, które zdążyło w międzyczasie przejąć adres.
#      Ustawia SONDA_POTWIERDZONY (SHA zgodny z oczekiwanym albo pusty)
#      i SONDA_OTRZYMANY (ostatni odczytany opis sygnału).
#
#  sonda_https <host>                                                (issue #1332)
#      Czy `http://<host>/` przekierowuje JEDNYM skokiem 301/308 dokładnie na
#      `https://<host>/` — kontrakt ręczny z docs/infra/DEPLOYMENT_RUNBOOK.md
#      („301/308 -> https://kuking.pl/"). Sam kod 30x nie wystarcza:
#      przekierowanie na `http://`, na obcy host albo bez `Location` też ma
#      kod 30x. Pętli nie ma jak tu przejść: cel `https://<host>/` sprawdza
#      osobno `check / 200` w teście dymnym, bez podążania za przekierowaniem.
#
#  Obie funkcje zwracają 0 wyłącznie po POTWIERDZENIU. Brak odpowiedzi to
#  porażka, nie „pewnie działa".
# =============================================================================

: "${SONDA_PROBY:=18}"
: "${SONDA_ODSTEP:=10}"

sonda_wydanie() {
    local base="${1%/}" oczekiwany="${2,,}"
    local proba tresc rc otrzymany

    SONDA_POTWIERDZONY=""
    SONDA_OTRZYMANY=""

    if [[ ! "$oczekiwany" =~ ^[0-9a-f]{40}$ ]]; then
        echo "BLAD  oczekiwany SHA '${2}' nie jest pełnym SHA commita (40 znaków szesnastkowych)"
        echo "      bez niego nie da się sprawdzić, CO działa pod adresem"
        return 1
    fi

    for ((proba = 1; proba <= SONDA_PROBY; proba++)); do
        # Zmienny parametr zapytania: każda próba to inny klucz cache brzegu.
        tresc=$(curl -sS --max-time 15 -H 'Cache-Control: no-cache' \
                  "${base}/wydanie?sonda=${oczekiwany:0:12}-${proba}-${RANDOM}") && rc=0 || rc=$?

        if [ "$rc" -ne 0 ]; then
            otrzymany="brak odpowiedzi (curl ${rc})"
        elif [[ "$tresc" =~ \"commit\"[[:space:]]*:[[:space:]]*\"([0-9A-Fa-f]{40})\" ]]; then
            otrzymany="${BASH_REMATCH[1],,}"
        else
            otrzymany="brak sygnału wydania w odpowiedzi"
        fi
        SONDA_OTRZYMANY="$otrzymany"

        if [ "$otrzymany" = "$oczekiwany" ]; then
            SONDA_POTWIERDZONY="$otrzymany"
            echo "OK    pod ${base} działa wdrażany commit ${otrzymany} (próba ${proba}/${SONDA_PROBY})"
            return 0
        fi

        echo "..    próba ${proba}/${SONDA_PROBY}: oczekiwano ${oczekiwany}, otrzymano ${otrzymany}"
        if [ "$proba" -lt "$SONDA_PROBY" ]; then
            sleep "$SONDA_ODSTEP"
        fi
    done

    echo "BLAD  pod ${base} NIE działa wdrażany commit"
    echo "      oczekiwano: ${oczekiwany}"
    echo "      otrzymano:  ${SONDA_OTRZYMANY}"
    echo "      Wynik testu dymnego opisywałby inne wydanie niż to ze zdarzenia wdrożenia."
    return 1
}

sonda_https() {
    local host="${1,,}" wynik rc kod cel

    wynik=$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 20 \
              "http://${host}/") && rc=0 || rc=$?

    if [ "$rc" -ne 0 ]; then
        echo "BLAD  HTTP -> HTTPS: brak odpowiedzi z http://${host}/ (curl ${rc})"
        return 1
    fi

    kod="${wynik%% *}"
    cel=""
    [[ "$wynik" == *" "* ]] && cel="${wynik#* }"

    # Porównanie z całym adresem, nie z prefiksem: `https://kuking.pl.obcy/`,
    # `https://kuking.pl@obcy/` i `https://kuking.pl:8443/` mają ten sam
    # początek, a prowadzą gdzie indziej.
    if [[ "$kod" =~ ^(301|308)$ ]] && [ "${cel,,}" = "https://${host}/" ]; then
        echo "OK    HTTP przekierowuje na https://${host}/ (${kod})"
        return 0
    fi

    echo "BLAD  HTTP nie przekierowuje na https://${host}/ (kod ${kod:-brak}, cel '${cel}')"
    echo "      oczekiwano 301 albo 308 -> https://${host}/ (Cloudflare: Always Use HTTPS)"
    return 1
}
