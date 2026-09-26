"""Daty dla człowieka tylko przez App\Support\Czas (#746). Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


# Gołe `->format(` z datą dla człowieka omija `App\Support\Czas` (issue #746).
# Strażnik czyta widoki linia po linii; mutacja przywraca w ekranie
# potwierdzenia adresu surową godzinę UTC i strażnik ma ją zobaczyć.
WIDOK_POTWIERDZENIA = "resources/views/auth/verify-email.blade.php"
STREFA_STRAZNIK_TEST = "test_zaden_widok_nie_formatuje_daty_z_pominieciem_pomocnika"


KONTROLE_DODATNIE = [STREFA_STRAZNIK_TEST]

KONTROLE = [
    Kontrola("Godzina w widoku z pominięciem Czas", WIDOK_POTWIERDZENIA, STREFA_STRAZNIK_TEST,
     lambda s: replace_once(s, "{{ \\App\\Support\\Czas::lokalnie($nieudanaWysylka->failed_at)->format('H:i') }}", "{{ $nieudanaWysylka->failed_at->format('H:i') }}")),
]
