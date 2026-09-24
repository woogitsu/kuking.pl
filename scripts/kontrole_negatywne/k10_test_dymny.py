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


KONTROLE_DODATNIE = [WDROZENIE_TEST]

KONTROLE = [
    Kontrola("Test dymny przepuszcza każde przekierowanie", WDROZENIE_WORKFLOW, WDROZENIE_TEST,
             stara_sonda_https),
]
