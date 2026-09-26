"""Limit ciała żądania w Caddy (audyt A5-16). Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


CADDYFILE = "docker/Caddyfile"
# Limit ciała żądania w Caddy (audyt A5-16). Strażnik czyta `docker/Caddyfile`:
# każda trasa ze zdjęciem stoi poza progiem 2 MB. Mutacja zdejmuje
# `/ustawienia/zdjecie` z listy odmowy 413 — zdjęcie profilowe powyżej 2 MB
# dostałoby wtedy „Za duże żądanie", a test ma zapalić.
CADDY_LIMIT_TEST = "CaddyLimitCialaZadaniaTest"
CADDY_LIMIT_WYJATKI = "@zaDuzeBezPlikow {\n\t\tnot path /dodaj/* /pytania /przepisy/* /wpisy/* /ustawienia/zdjecie "


KONTROLE_DODATNIE = [CADDY_LIMIT_TEST]

KONTROLE = [
    Kontrola("Trasa ze zdjęciem pod progiem 2 MB w Caddy", CADDYFILE, CADDY_LIMIT_TEST,
     lambda s: replace_once(s, CADDY_LIMIT_WYJATKI, CADDY_LIMIT_WYJATKI.replace("/ustawienia/zdjecie ", ""))),
]
