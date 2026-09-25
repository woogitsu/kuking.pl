"""Akcja zapisu do zeszytu sama sprawdza prawo do zeszytu (#942).

Test woła akcję BEZPOŚREDNIO, z pominięciem kontrolera, więc walidacja
`collection_id` w kontrolerze go nie ratuje. Mutacja zdejmuje `authorize`
osobno z akcji przepisu i z akcji wpisu — każda ma zapalić ten sam test.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


ZAPIS_PRZEPISU = "app/Domain/Collections/Actions/SaveRecipeToCollection.php"
ZAPIS_WPISU = "app/Domain/Collections/Actions/SavePostToCollection.php"
ZAPIS_CUDZY_ZESZYT_TEST = "ZapisDoCudzegoZeszytuWAkcjiTest"
AUTORYZACJA_ZESZYTU = "        Gate::forUser($user)->authorize('update', $collection);\n"

KONTROLE_DODATNIE = [ZAPIS_CUDZY_ZESZYT_TEST]

KONTROLE = [
    Kontrola("Zapis przepisu do cudzego zeszytu", ZAPIS_PRZEPISU, ZAPIS_CUDZY_ZESZYT_TEST,
             lambda s: replace_once(s, AUTORYZACJA_ZESZYTU, "")),
    Kontrola("Zapis wpisu do cudzego zeszytu", ZAPIS_WPISU, ZAPIS_CUDZY_ZESZYT_TEST,
             lambda s: replace_once(s, AUTORYZACJA_ZESZYTU, "")),
]
