"""Procesy queue:work bez głodzenia kolejek i bez OOM w roli all (#1030).
Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


# Kolejki w entrypoincie (#1030): osobne procesy w roli worker, jeden w roli all.
ENTRYPOINT = "docker/entrypoint.sh"
KOLEJKI_BEZ_GLODZENIA_TEST = "KolejkiBezGlodzeniaTest"
UMOWA_KOLEJKI_TEST = "UmowaKolejkiTest"


KONTROLE_DODATNIE = [KOLEJKI_BEZ_GLODZENIA_TEST, UMOWA_KOLEJKI_TEST]

KONTROLE = [
    Kontrola("Jeden worker ze ścisłym priorytetem kolejek", ENTRYPOINT, KOLEJKI_BEZ_GLODZENIA_TEST,
     lambda s: replace_once(s, 'local osobne="high default media low"', 'local osobne="high,default,media,low"')),
    Kontrola("Rola all z procesem na kolejkę (OOM w 1024 MB)", ENTRYPOINT, UMOWA_KOLEJKI_TEST,
     lambda s: replace_once(s, '${QUEUE_NAMES:-high,default,media,low}', '${QUEUE_NAMES:-high default media low}')),
]
