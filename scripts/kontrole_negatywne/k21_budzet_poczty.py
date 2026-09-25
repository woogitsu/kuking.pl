"""Timeout własnej blokady po udanej rezerwacji u rodzica oddaje miejsce (#1393).

Test jest behawioralny; mutacja przywraca `return false` z `catch`, który
pomijał zwrot miejsca do wspólnej puli poczty.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


BUDZET_POCZTY = "app/Domain/Security/DziennyBudzetListow.php"
BUDZET_POCZTY_TEST = "test_timeout_wlasnej_blokady_oddaje_miejsce_we_wspolnej_puli"

KONTROLE_DODATNIE = [BUDZET_POCZTY_TEST]

KONTROLE = [
    Kontrola("Timeout blokady funkcji nie oddaje miejsca wspólnej puli", BUDZET_POCZTY, BUDZET_POCZTY_TEST,
             lambda s: replace_once(s, "            $zajete = false;\n", "            return false;\n"),
             oczekuj=r"Timeout blokady funkcji zostawił zajęte miejsce we wspólnej puli"),
]
