"""Obwódka list w panelu „Aa · Wygląd” (audyt B1, zn. 3).

Powrót do `--color-border` (1,3:1 na tle panelu) ma zapalić test kontrastu.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


SZYBKI_WYGLAD_CSS = "resources/css/szybki-wyglad.css"
OBWODKA_WYGLADU_TEST = "KontrolkiPaneluWygladuMajaWidocznaObwodkeTest"

KONTROLE = [
    Kontrola("Obwódka listy wyglądu poniżej 3:1", SZYBKI_WYGLAD_CSS, OBWODKA_WYGLADU_TEST,
             lambda s: replace_once(s, "select { border: 2px solid var(--color-border-strong);",
                                    "select { border: 2px solid var(--color-border);")),
]
