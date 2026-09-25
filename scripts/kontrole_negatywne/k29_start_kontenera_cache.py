"""Start kontenera nie czyści tabeli `cache` (audyt B10-03).

W tabeli żyją RateLimiter i sufit listów D-076. Mutacja przywraca stare
`cache:clear` w entrypoincie.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


ENTRYPOINT = "docker/entrypoint.sh"
START_KONTENERA_TEST = "StartKonteneraNieCzysciCacheTest"

KONTROLE = [
    Kontrola("Entrypoint czyści cache aplikacji", ENTRYPOINT, START_KONTENERA_TEST,
             lambda s: replace_once(s, "php /app/artisan event:clear  --no-interaction >/dev/null\n", "php /app/artisan event:clear  --no-interaction >/dev/null\nphp /app/artisan cache:clear --no-interaction >/dev/null 2>&1 || true\n")),
]
