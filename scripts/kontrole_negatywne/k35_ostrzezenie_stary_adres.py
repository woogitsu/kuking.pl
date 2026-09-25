"""Ostrzeżenie o zmianie adresu utrwala STARY adres przy prośbie (#888).

Mutacja wraca do `$user->notify()` — adres czytany przy wysyłce, po
potwierdzeniu już nowy — i test przechodzący przez kolejkę ma zapalić.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


OSTRZEZENIE_888 = "app/Domain/Users/Actions/RequestEmailChange.php"
OSTRZEZENIE_888_TEST = "OstrzezenieZmianyAdresuWKolejceTest"

KONTROLE_DODATNIE = [OSTRZEZENIE_888_TEST]

KONTROLE = [
    Kontrola("Ostrzeżenie o zmianie adresu czyta adres przy wysyłce", OSTRZEZENIE_888, OSTRZEZENIE_888_TEST,
             lambda s: replace_once(s, "Notification::route('mail', $oldAddress)->notify(new ZgloszonaZmianaAdresu(",
                                    "$user->notify(new ZgloszonaZmianaAdresu(")),
]
