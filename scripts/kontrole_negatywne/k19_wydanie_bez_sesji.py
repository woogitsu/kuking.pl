"""`/wydanie` bez sesji i CSRF (przegląd #1439).

Mutacja wraca z trasą do pełnej grupy `web` i ma zapalić test braku
`Set-Cookie`.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


WYDANIE_TRASY = "bootstrap/app.php"
WYDANIE_TEST = "WydanieWystawiaPelnyShaTest"

KONTROLE_DODATNIE = [WYDANIE_TEST]

KONTROLE = [
    Kontrola("/wydanie z sesją i ciasteczkami", WYDANIE_TRASY, WYDANIE_TEST,
             lambda s: replace_once(s, "Route::get('/wydanie', WydanieController::class)->name('wydanie');", "Route::middleware('web')->get('/wydanie', WydanieController::class)->name('wydanie');"),
             oczekuj=r"`/wydanie` stawia ciasteczka\."),
]
