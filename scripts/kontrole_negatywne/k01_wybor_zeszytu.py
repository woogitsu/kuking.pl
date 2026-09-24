"""Wybór zeszytu ma walidację (issue #473): format UUID, własność, komunikat."""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


CONTROLLER = "app/Http/Controllers/CollectionController.php"
LAYOUT = "resources/views/components/layout.blade.php"
COLLECTION_TEST = "WyborZeszytuMaWalidacjeTest"


def remove_notice(source):
    start = source.index("@if($collectionError)")
    end = source.index("@endif", start) + len("@endif")
    return source[:start] + source[end:]


KONTROLE_DODATNIE = [COLLECTION_TEST]

KONTROLE = [
    Kontrola("Format UUID", CONTROLLER, COLLECTION_TEST,
             lambda s: replace_once(s, "'bail', 'nullable', 'uuid',", "'bail', 'nullable',"),
             oczekuj=r"received 500.*invalid input syntax for type uuid"),
    Kontrola("Własność zeszytu", CONTROLLER, COLLECTION_TEST,
             lambda s: replace_once(s, "Rule::exists('collections', 'id')->where('owner_id', $request->user()->getKey())", "Rule::exists('collections', 'id')"),
             oczekuj=r"but received 404\."),
    Kontrola("Komunikat po powrocie", LAYOUT, COLLECTION_TEST, remove_notice,
             oczekuj=r"Po powrocie na stronę główną błąd musi być widoczny"),
]
