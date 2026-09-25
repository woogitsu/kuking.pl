"""Rejestr wyjątków kolumn wrażliwych nazywa tylko istniejące symbole (audyt A5-18).

Strażnik czyta własną stałą REJESTR; mutacje wracają do nazw sprzed poprawki —
klasy, której nie ma, i stałej, której model nie definiuje.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


REJESTR_WYJATKOW = "tests/Feature/WrazliweKolumnyPozaMasowymPrzypisaniemTest.php"
REJESTR_WYJATKOW_TEST = "test_rejestr_nazywa_tylko_istniejace_klasy_i_stale"

KONTROLE_DODATNIE = [REJESTR_WYJATKOW_TEST]

KONTROLE = [
    Kontrola("Rejestr wyjątków z nieistniejącą klasą", REJESTR_WYJATKOW, REJESTR_WYJATKOW_TEST,
             lambda s: replace_once(s, "'PublishComment składa", "'AddComment składa")),
    Kontrola("Rejestr wyjątków z nieistniejącą stałą", REJESTR_WYJATKOW, REJESTR_WYJATKOW_TEST,
             lambda s: replace_once(s, "dostaje STATUS_OPEN na sztywno", "dostaje STATUS_NEW na sztywno")),
]
