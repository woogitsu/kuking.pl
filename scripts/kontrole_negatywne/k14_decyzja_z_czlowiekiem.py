"""Decyzja moderacyjna tylko z człowiekiem (UzasadnienieDecyzji, G31/D-251).

Strażnik skanuje `app/` w poszukiwaniu `ModerationAction::create(` i porównuje
z listą dozwolonych miejsc. Mutacja dokłada to wywołanie w pliku SPOZA listy
(`NotifyModerationDecision`, sąsiad nowego `ZdejmijZUrzedu`) — strażnik ma
zapalić, czyli naprawdę widzi nowe pliki, a nie tylko potwierdza listę, którą
już zna.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


POWIADOM_O_DECYZJI = "app/Domain/Moderation/Actions/NotifyModerationDecision.php"
DECYZJA_Z_CZLOWIEKIEM_TEST = "test_nie_ma_w_kodzie_drogi_do_decyzji_bez_czlowieka"

KONTROLE_DODATNIE = [DECYZJA_Z_CZLOWIEKIEM_TEST]

KONTROLE = [
    Kontrola("Decyzja moderacyjna tworzona poza listą", POWIADOM_O_DECYZJI, DECYZJA_Z_CZLOWIEKIEM_TEST,
             lambda s: replace_once(
                 s,
                 "final class NotifyModerationDecision\n{\n",
                 "final class NotifyModerationDecision\n{\n    // ModerationAction::create( — mutacja kontroli dodatniej\n",
             ),
             oczekuj=r"Powstało nowe miejsce tworzące decyzję moderacyjną.*NotifyModerationDecision\.php"),
]
