"""`\\R` bez `u` tnie „ą" (C4 85) na pół (#1276).

Strażnik czyta tokeny PHP w `tests/`, `scripts/` i `app/`; mutacja przywraca
stary podział w skanerze poświadczeń — tym miejscu, gdzie strzępy wierszy
kosztowały najwięcej.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


PODZIAL_WIERSZY = "tests/Feature/PoswiadczeniaPozaRepozytoriumTest.php"
PODZIAL_WIERSZY_TEST = "PodzialWierszyNieRozrywaLiterTest"

KONTROLE_DODATNIE = [PODZIAL_WIERSZY_TEST]

KONTROLE = [
    Kontrola("Podział wierszy przez \\R bez u", PODZIAL_WIERSZY, PODZIAL_WIERSZY_TEST,
             lambda s: replace_once(s, r"preg_split('/\r\n|\n|\r/', $tresc)", r"preg_split('/\R/', $tresc)")),
]
