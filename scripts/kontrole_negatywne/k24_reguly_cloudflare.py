"""Warunki reguł Cloudflare (#597).

Strażnik czyta sparsowany JSON; mutacja zdejmuje warunek pustego ciasteczka
z reguły zdjęć — to jest dokładnie wyciek treści prywatnej do wspólnego cache,
którego #597 zakazuje.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


REGULY_CF = "docs/infra/cloudflare-cache-rules-597-610.json"
REGULY_CF_TEST = "test_warunki_regul_nie_wpuszczaja_stanu_klienta_do_wspolnego_cache"
REGULA_ZDJEC_CIASTKO = '\\"/zdjecia/\\") and http.request.uri.query eq \\"\\" and http.cookie eq \\"\\"'

KONTROLE_DODATNIE = [REGULY_CF_TEST]

KONTROLE = [
    Kontrola("Reguła zdjęć Cloudflare bez warunku ciasteczka", REGULY_CF, REGULY_CF_TEST,
             lambda s: replace_once(s, REGULA_ZDJEC_CIASTKO, REGULA_ZDJEC_CIASTKO.replace(' and http.cookie eq \\"\\"', "")),
             oczekuj=r'Reguła \d+ bez warunku and http\.cookie eq ""\.'),
]
