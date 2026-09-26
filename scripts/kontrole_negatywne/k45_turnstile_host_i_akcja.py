"""Turnstile wiąże token z hostem i formularzem (#992). Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


# Turnstile wiąże token z hostem i formularzem (#992). Każda mutacja zdejmuje
# jedno porównanie w `KlientTurnstile` — test tej gałęzi ma wtedy oblać.
KLIENT_TURNSTILE = "app/Turnstile/KlientTurnstile.php"
TURNSTILE_HOST_TEST = "test_host_spoza_listy_jest_odrzucany"
TURNSTILE_AKCJA_TEST = "test_akcja_innego_formularza_jest_odrzucana"


KONTROLE_DODATNIE = [TURNSTILE_HOST_TEST, TURNSTILE_AKCJA_TEST]

KONTROLE = [
    Kontrola("Turnstile bez porównania hosta", KLIENT_TURNSTILE, TURNSTILE_HOST_TEST,
     lambda s: replace_once(s, "! in_array(strtolower($host), $dozwolone, true) => 'host_spoza_listy',\n", "")),
    Kontrola("Turnstile bez porównania akcji", KLIENT_TURNSTILE, TURNSTILE_AKCJA_TEST,
     lambda s: replace_once(s, "! hash_equals($akcja, $akcjaZOdpowiedzi) => 'inna_akcja',\n", "")),
]
