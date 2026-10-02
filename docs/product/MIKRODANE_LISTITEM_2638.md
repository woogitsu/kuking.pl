# Mikrodane: instrukcja wewnątrz pozycji listy (#2638)

`ListItem` jest opakowaniem pozycji, a jego `name` bywa etykietą „Krok 1”.
Parser najpierw czyta osadzony `item` typu `HowToStep`, `HowToDirection`,
`HowToSection`, `ItemList` albo `ListItem`. Nie pobiera adresów ani `itemref`.
Brak takiego lokalnego obiektu daje pustą listę kroków tej pozycji; etykieta
opakowania ani obiekt innego typu nie zastępują instrukcji.

Kolejność DOM i tożsamość węzłów z #2626 zostają: jeden węzeł z aliasami jest
jedną pozycją, dwa różne węzły z jednakowym tekstem pozostają dwiema.
Rekursja zachowuje istniejącą granicę głębokości 8, węzłów 20 000 i pozycji
300; DTO nadal stosuje granice formularza. Bezpośrednie kroki i sekcje
korzystają z dotychczasowego odczytu.

Regresje: publiczne `odczytaj()` w `ImportMikrodaneTest` oraz prawdziwy import
HTTP do prywatnego szkicu w `ImportPrzepisuZAdresuIPdfTest`. Fizyczne mutacje
wyłączają obsługę `ListItem.item` i wymagają markerów właściwego tekstu,
odmowy zgadywania oraz zapisu szkicu. Lokalne sondy DOM nie zastępują tych
testów PostgreSQL 18.

Nie ma migracji, pobierania zdjęć ani nowego wywołania modelu. Zależność:
aliasy mikrodanych #2635. Rollback zwykłym revert poprawki #2638, zachowując
#2635; przywraca on ryzyko zapisania etykiety zamiast instrukcji.
