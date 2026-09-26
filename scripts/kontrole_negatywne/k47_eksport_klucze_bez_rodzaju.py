"""Klucze paczki RODO bez rodzaju gramatycznego (#1750). Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


# Klucze eksportu danych bez formy żeńskiej/męskiej (#1750).
EKSPORT_DANE = "app/Domain/Users/Exports/CollectUserExportData.php"
EKSPORT_KLUCZE_TEST = "EksportKluczeBezRodzajuTest"


KONTROLE_DODATNIE = [EKSPORT_KLUCZE_TEST]

KONTROLE = [
    # #1750: klucz paczki RODO wraca do formy żeńskiej sprzed poprawki.
    Kontrola("Klucz eksportu z rodzajem", EKSPORT_DANE, EKSPORT_KLUCZE_TEST,
     lambda s: replace_once(s, "'na_czym_sie_znam' =>", "'w_czym_jestem_dobra' =>")),
]
