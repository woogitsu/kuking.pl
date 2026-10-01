## D-212 · Kafle zainteresowań i zwykłe powiadomienia

13 września 2026, issue #513. Kompozycja dostarczonego prototypu obejmuje
duże kafle zainteresowań oraz zwykłe powiadomienie z awatarem, treścią
i akcją obok siebie, jeśli pozwala na to dostępne miejsce.
Przenosimy ją do istniejących formularzy, bez fikcyjnych tematów i danych.
Zaznaczenie pozostaje zielone zgodnie z semantyką wyborów aplikacji.

Zachowujemy trzy opcjonalne kroki, natywne checkboxy, prawdziwe promowane
tagi, pomijanie i POST z CSRF. Układ powiadomień obejmuje wyłącznie pięć
zwykłych typów: ugotowanie, komentarz, odpowiedź, obserwowanie i zapis.
Decyzje, zgłoszenia i pozostałe typy zachowują pełną treść i wszystkie akcje.
Kliknięcie nadal zapisuje odczyt przez formularz POST, nie odnośnik GET.

Stan implementacji i granice odbioru: docs/design/ZAINTERESOWANIA_POWIADOMIENIA_513.md.
