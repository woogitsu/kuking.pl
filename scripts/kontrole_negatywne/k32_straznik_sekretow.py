"""Strażnik adresów z sekretami (#991, D-250).

Kryterium issue wprost: kontrola ujemna osłabiająca sprawdzenie do samego
`https://` ma oblać test hosta podszywającego się sufiksem. Druga mutacja
zabiera sprawdzenie ścieżki.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


STRAZNIK_HOSTA = "app/Support/DozwolonyHostApi.php"
STRAZNIK_HOSTA_TEST = "test_straznik_odrzuca_adres_spoza_listy"


def bez_sprawdzenia_hosta(source):
    """KONTROLA DODATNIA: strażnik adresu zostaje przy samym `https://`."""
    return replace_once(
        source,
        "        if (! in_array(strtolower($uri->getHost()), $hosty, true)) {\n"
        "            return 'host spoza listy dostawcy';\n"
        "        }\n",
        "",
    )


def bez_sprawdzenia_sciezki(source):
    """KONTROLA DODATNIA: strażnik adresu przestaje patrzeć na ścieżkę."""
    return replace_once(
        source,
        "        if (preg_match($sciezka, $uri->getPath()) !== 1) {\n"
        "            return 'ścieżka spoza API dostawcy';\n"
        "        }\n",
        "",
    )


KONTROLE_DODATNIE = [STRAZNIK_HOSTA_TEST]

KONTROLE = [
    Kontrola("Strażnik sekretów osłabiony do samego https", STRAZNIK_HOSTA, STRAZNIK_HOSTA_TEST,
             bez_sprawdzenia_hosta),
    Kontrola("Strażnik sekretów bez sprawdzenia ścieżki", STRAZNIK_HOSTA, STRAZNIK_HOSTA_TEST,
             bez_sprawdzenia_sciezki),
]
