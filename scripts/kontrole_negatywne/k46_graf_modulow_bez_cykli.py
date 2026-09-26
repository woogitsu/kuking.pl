"""Graf modułów app/Domain bez cykli (#971). Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


# Graf modułów app/Domain bez cykli (#971). Strażnik czyta tokeny PHP
# w `app/Domain`; mutacja przywraca import `Social` w `ZalozKonto`, czyli
# dokładnie tę krawędź, która zamykała cykl `Users ↔ Social`.
ZALOZ_KONTO = "app/Domain/Users/Actions/ZalozKonto.php"
GRAF_MODULOW_TEST = "GrafModulowDomenyBezCykliTest"


KONTROLE_DODATNIE = [GRAF_MODULOW_TEST]

KONTROLE = [
    Kontrola("Users znowu importuje Social", ZALOZ_KONTO, GRAF_MODULOW_TEST,
     lambda s: replace_once(s, "use App\\Domain\\Users\\ObserwowanieGospodarza;\n", "use App\\Domain\\Social\\Actions\\FollowUser;\nuse App\\Domain\\Users\\ObserwowanieGospodarza;\n")),
]
