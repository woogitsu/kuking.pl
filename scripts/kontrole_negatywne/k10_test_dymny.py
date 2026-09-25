"""Test dymny po wdrożeniu (#1012, #1332, #974).

Strażnik czyta workflow, bo GitHub Actions nie da się uruchomić z testu.
Mutacja przywraca starą sondę HTTPS, która przepuszczała każdy kod 30x bez
względu na cel przekierowania.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


WDROZENIE_WORKFLOW = ".github/workflows/deploy.yml"
WDROZENIE_TEST = "TestDymnyNieUdajeCudzegoWydaniaTest"


def stara_sonda_https(source):
    """KONTROLA DODATNIA: wróć do `case 301|302|307|308` bez sprawdzania celu.

    `przekierowanie_https_sprawdza_cel_a_nie_sam_kod` ma zapalić (#1332).
    """
    return replace_once(
        source,
        '          sonda_https "${BASE_URL#https://}" || fail=1\n',
        '          redirect=$(curl -sS -o /dev/null -w \'%{http_code}\' --max-time 20 "http://${BASE_URL#https://}/" || echo "000")\n'
        '          case "$redirect" in\n'
        '            301|302|307|308) echo "OK    HTTP przekierowuje ($redirect)" ;;\n'
        '            *) echo "BLAD  HTTP nie przekierowuje na HTTPS ($redirect)"; fail=1 ;;\n'
        '          esac\n',
    )


def test_dymny_bez_sondy_wydania(source):
    """KONTROLA DODATNIA: zdejmij sondę wydania sprzed sprawdzeń.

    `test_dymny_najpierw_potwierdza_pelny_sha_zdarzenia` ma zapalić (#1012):
    bez niej test dymny zdarzenia A znów sprawdza wydanie B.
    """
    return replace_once(
        source,
        '          if ! sonda_wydanie "$BASE_URL" "$OCZEKIWANY_SHA"; then\n'
        '            echo "::error title=Pod adresem działa inne wydanie::Oczekiwano ${OCZEKIWANY_SHA}, otrzymano ${SONDA_OTRZYMANY:-nic}. Test dymny nie sprawdza cudzego wydania."\n'
        '            exit 1\n'
        '          fi\n',
        "",
    )


def koncowa_sonda_jedna_proba(source):
    """KONTROLA DODATNIA: końcowa sonda wydania znów z jedną próbą.

    Ten sam test (#1012, przegląd #1439) ma zapalić: jedna chwilowa porażka
    sieci po testach oblewała całe wdrożenie.
    """
    return replace_once(
        source,
        '          sonda_wydanie_koncowa "$BASE_URL" "$OCZEKIWANY_SHA" || fail=1\n',
        '          SONDA_PROBY=1 sonda_wydanie "$BASE_URL" "$OCZEKIWANY_SHA" || fail=1\n',
    )


def akcja_rollback_wraca(source):
    """KONTROLA DODATNIA: przywróć akcję `rollback`, która niczego nie cofa.

    `zadna_akcja_nie_nazywa_sie_rollback_skoro_nic_nie_cofa` ma zapalić (#974).
    """
    return replace_once(
        source,
        "options: [smoke, migrate, redeploy, instrukcja-cofniecia]",
        "options: [smoke, migrate, redeploy, rollback]",
    )


KONTROLE_DODATNIE = [WDROZENIE_TEST]

KONTROLE = [
    Kontrola("Test dymny przepuszcza każde przekierowanie", WDROZENIE_WORKFLOW, WDROZENIE_TEST,
             stara_sonda_https,
             oczekuj=r'contains "sonda_https "\$\{BASE_URL#https://\}" \|\| fail=1"'),
    Kontrola("Test dymny bez sondy wydania przed sprawdzeniami", WDROZENIE_WORKFLOW, WDROZENIE_TEST,
             test_dymny_bez_sondy_wydania,
             oczekuj=r"Test dymny nie potwierdza, że pod adresem działa wdrażany commit \(#1012\)\."),
    Kontrola("Końcowa sonda wydania z jedną próbą", WDROZENIE_WORKFLOW, WDROZENIE_TEST,
             koncowa_sonda_jedna_proba,
             oczekuj=r"Po sprawdzeniach wydanie trzeba potwierdzić jeszcze raz"),
    Kontrola("Akcja rollback, która nic nie cofa", WDROZENIE_WORKFLOW, WDROZENIE_TEST,
             akcja_rollback_wraca,
             oczekuj=r"Failed asserting that an array contains 'instrukcja-cofniecia'\."),
]
