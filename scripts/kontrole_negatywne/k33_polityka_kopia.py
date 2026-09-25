"""Polityka nie obiecuje „pełnej kopii" danych (R1, wariant A z 20.09.2026).

Strażnik czyta dokument prawny; mutacja przywraca dawne sformułowanie i test
ma wtedy oblać — dowód, że szuka tego słowa w tym pliku, a nie w pustce.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


POLITYKA = "resources/legal/polityka-prywatnosci.md"
POLITYKA_KOPIA_TEST = "PolitykaNieObiecujePelnejKopiiTest"

KONTROLE_DODATNIE = [POLITYKA_KOPIA_TEST]

KONTROLE = [
    Kontrola("Polityka znowu obiecuje pełną kopię", POLITYKA, POLITYKA_KOPIA_TEST,
             lambda s: replace_once(s, "poprosić o **kopię swoich treści**", "poprosić o pełną kopię")),
]
