"""Kontrolery Google i Facebooka są adapterami nad `WejdzPrzezDostawce` (#1035).

Mutacja wkleja do kontrolera Google własne `Auth::login` przed odpowiedzią —
kopię wspólnej reguły wejścia — i ma zapalić strażnika architektury.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


KONTROLER_GOOGLE = "app/Http/Controllers/Auth/GoogleLoginController.php"
ADAPTERY_DOSTAWCOW_TEST = "KontroleryDostawcowSaAdapteramiTest"
WPUSC_GOOGLE = "        return match ($this->wejscie()->wpusc($request, $user)) {\n"

KONTROLE_DODATNIE = [ADAPTERY_DOSTAWCOW_TEST]

KONTROLE = [
    Kontrola("Kontroler Google z własną kopią wejścia na konto", KONTROLER_GOOGLE, ADAPTERY_DOSTAWCOW_TEST,
             lambda s: replace_once(s, WPUSC_GOOGLE, "        \\Illuminate\\Support\\Facades\\Auth::login($user, remember: true);\n\n" + WPUSC_GOOGLE)),
]
