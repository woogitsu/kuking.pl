# Błąd pola zdjęcia przy odczycie kartki — #2586

Na formularzu „Przepisz z kartki lub zeszytu” etykieta pola pliku i stała
wskazówka pozostają na swoim miejscu. Po błędzie walidacji `zdjecie` pole
otrzymuje `aria-invalid="true"`, a jego `aria-describedby` wskazuje kolejno
wskazówkę i widoczny komunikat z identyfikatorem `f-zdjecie-error`.
Bez błędu wskazuje tylko istniejącą wskazówkę. Odnośnik z podsumowania
błędów nadal prowadzi do `#f-zdjecie`.

Test przechodzi przez prawdziwe POST i następny GET przy braku pliku oraz
niedozwolonym formacie. Sprawdza istniejące elementy IDREF, zgodność
komunikatu przy polu z podsumowaniem i brak zlecenia płatnego odczytu.
Kontrola ujemna usuwa samo powiązanie z `aria-describedby` i wymaga porażki
z markerem #2586, nawet gdy komunikat nadal jest widoczny.

Nie zmieniono walidacji, zgody, kosztu ani zapisu szkicu. Wycofanie dwóch
atrybutów w szablonie przywróci dawny brak powiązania błędu z polem;
nie dotyka danych użytkownika ani kolejki importu. Odbiór czytnikiem ekranu
na urządzeniu użytkownika pozostaje osobnym pomiarem dostępności.
