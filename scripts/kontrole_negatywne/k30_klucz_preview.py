"""Entrypoint nadaje klucz preview środowiska PR, zanim odmówi startu (#975).

Zachowanie `docker/klucz-preview.sh` mierzą testy behawioralne; ta kontrola
pilnuje jedynego testu czytającego entrypoint — mutacja odcina wywołanie
przed odmową startu i ma go zapalić.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


ENTRYPOINT = "docker/entrypoint.sh"
KLUCZ_PREVIEW_TEST = "test_entrypoint_nadaje_klucz_preview_przed_odmowa_startu"

KONTROLE_DODATNIE = [KLUCZ_PREVIEW_TEST]

KONTROLE = [
    Kontrola("Entrypoint bez klucza preview", ENTRYPOINT, KLUCZ_PREVIEW_TEST,
             lambda s: replace_once(s, '[[ -z "${APP_KEY:-}" ]] && kuking_klucz_preview; then', '[[ -z "${APP_KEY:-}" ]] && false; then')),
]
