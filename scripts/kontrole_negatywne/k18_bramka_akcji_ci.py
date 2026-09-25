"""Bramka zakresu w `ci.yml` obejmuje lokalne akcje `.github/actions/` (#1273).

Joby przeglądarkowe wołają akcje przez `uses: ./…`. Strażnik pyta PRAWDZIWY
skrypt bramki, ale czyta go z `ci.yml`, więc tylko mutacja dowodzi, że zapala
się, gdy akcje wypadną z filtra warstwy widoku.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


BRAMKA_CI = ".github/workflows/ci.yml"
BRAMKA_AKCJE_TEST = "test_zmiana_lokalnej_akcji_uruchamia_joby_ktore_jej_uzywaja"


def akcje_poza_filtrem_widoku(source):
    """KONTROLA DODATNIA: wyjmij `.github/actions/` z filtra warstwy widoku.

    Zmiana lokalnej akcji znowu daje `widok=false`, więc joby przeglądarkowe,
    które jej używają, byłyby pominięte. Strażnik bramki ma zapalić.
    """
    return replace_once(
        source,
        r"|\.github/(workflows/ci\.yml|actions/))'",
        r"|\.github/workflows/ci\.yml)'",
    )


KONTROLE_DODATNIE = [BRAMKA_AKCJE_TEST]

KONTROLE = [
    Kontrola("Lokalne akcje poza filtrem widoku", BRAMKA_CI, BRAMKA_AKCJE_TEST,
             akcje_poza_filtrem_widoku,
             oczekuj=r"Bramka pomija job `\w+` przy zmianie `\.github/actions/"),
]
