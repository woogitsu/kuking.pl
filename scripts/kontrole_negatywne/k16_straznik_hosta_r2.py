"""Strażnik hosta magazynu R2 (D-255).

Mutacja 1 przepuszcza endpoint bez jurysdykcji `eu` (i każdą inną jurysdykcję),
mutacja 2 zdejmuje kotwicę końca, więc przechodzi host podszywający się sufiksem.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


STRAZNIK_R2 = "app/Support/Storage/DozwolonyHostR2.php"
STRAZNIK_R2_TEST = "test_straznik_r2_odrzuca_host_spoza_wzoru"
WZOR_R2 = r"""'/^[0-9a-f]{32}\.eu\.r2\.cloudflarestorage\.com$/'"""

KONTROLE_DODATNIE = [STRAZNIK_R2_TEST]

KONTROLE = [
    Kontrola("Strażnik R2 bez segmentu eu", STRAZNIK_R2, STRAZNIK_R2_TEST,
             lambda s: replace_once(s, WZOR_R2, WZOR_R2.replace(r"\.eu\.", r"(\.[a-z]+)?\.")),
             oczekuj=r"Przepuszczony: https://[0-9a-f]{32}\.(us\.)?r2\.cloudflarestorage\.com\s"),
    Kontrola("Strażnik R2 bez kotwicy końca", STRAZNIK_R2, STRAZNIK_R2_TEST,
             lambda s: replace_once(s, WZOR_R2, WZOR_R2.replace("$/", "/")),
             oczekuj=r"Przepuszczony: https://[0-9a-f]{32}\.eu\.r2\.cloudflarestorage\.com\."),
]
