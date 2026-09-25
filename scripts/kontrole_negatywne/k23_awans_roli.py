"""Awans roli z powłoki gasi sesje sprzed awansu (#1315).

Test chodzi po HTTP w osobnych procesach; bez tej linijki stara sesja
wchodzi do panelu.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


ZMIANA_ROLI = "app/Domain/Users/Actions/ChangeUserRole.php"
AWANS_ROLI_TEST = "AwansRoliWymagaNowejSesjiTest"

KONTROLE_DODATNIE = [AWANS_ROLI_TEST]

KONTROLE = [
    Kontrola("Awans roli bez odwołania sesji", ZMIANA_ROLI, AWANS_ROLI_TEST,
             lambda s: replace_once(s, "            $fresh->invalidateSessions();\n", "")),
]
