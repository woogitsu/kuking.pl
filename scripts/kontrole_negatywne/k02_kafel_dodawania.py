"""Kafel dodawania przy dużym tekście: podpis co najmniej 18 px."""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


CSS = "resources/css/app.css"
COMPOSER_TEST = "KafelDodawaniaPrzyDuzymTekscieTest"


def smaller_help(source):
    start = source.index(".composer-help {")
    end = source.index("}", start)
    block = replace_once(source[start:end], "var(--text-body)", "var(--text-help)")
    return source[:start] + block + source[end:]


KONTROLE_DODATNIE = [COMPOSER_TEST]

KONTROLE = [
    Kontrola("Podpis co najmniej 18 px", CSS, COMPOSER_TEST, smaller_help),
]
