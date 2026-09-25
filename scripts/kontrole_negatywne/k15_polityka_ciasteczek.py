"""Polityka nazywa każde ciasteczko ustawień (R6).

Strażnik czyta dokument prawny; mutacja zdejmuje NAZWĘ ciasteczka motywu
w backtickach, a zwykłe słowo „motyw" zostaje w tekście — test ma wtedy oblać,
bo szuka nazwy, a nie wyrazu.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


POLITYKA = "resources/legal/polityka-prywatnosci.md"
POLITYKA_CIASTECZKA_TEST = "PolitykaNazywaCiasteczkaUstawienTest"

KONTROLE_DODATNIE = [POLITYKA_CIASTECZKA_TEST]

KONTROLE = [
    Kontrola("Polityka bez nazwy ciasteczka motywu", POLITYKA, POLITYKA_CIASTECZKA_TEST,
             lambda s: replace_once(s, "ciemnego motywu (`motyw`)", "ciemnego motywu"),
             oczekuj=r"polityka nie podaje jego NAZWY"),
]
