# Ilość w zakresie przy orientacyjnym koszcie — #2477

Parser kosztu traktuje `2–3`, `2 do 3`, `2 lub 3` i `2 albo 3` jako tę
samą parę liczb. Do wyceny bierze środek zakresu i jednostkę stojącą po
drugiej liczbie. Dotyczy to także ułamków i przecinków dziesiętnych.
`2 łyżki do smażenia` nie jest zakresem, bo po „do” nie stoi liczba.
Uszkodzony zapis, w którym spójnik stoi bez drugiej liczby albo po
rozpoznanej liczbie zaczyna się kolejna granica (`300- g`, `300.5.5`,
`300 g do 400 g`), nie dostaje domyślnej miary „sztuka”; szacunek uczciwie
odmawia. To dotyczy również pojedynczej liczby, nie tylko poprawnej pierwszej
pary zakresu. Poprawne `300 g mąki` i `2 łyżki do smażenia` pozostają czytelne.

#2508: ochronę przed następną granicą stosujemy bezpośrednio po ilości.
Opis przeznaczenia za nazwą produktu (`300 g mąki do 2 porcji` albo
`300 g mąki do 2 ciast`) zachowuje 300 g, tak samo jak opis w nawiasie.
Nie zamienia to `300 g do 400 g` w obsługiwany zakres. Regresja mierzy
odczyt masy i identyczny wynik pełnej wyceny; mutacja przywraca zbyt
szerokie przeszukiwanie całego dalszego opisu.

Zmiana obejmuje tylko odczyt tekstu na potrzeby orientacyjnego kosztu
(D-286). Nie poprawia składników zapisanych przez autora, cen, progu
pokrycia ani pierwszeństwa kwoty podanej przez autora. `no_amount` i woda
pozostają poza masą.

Test parsera porównuje granice oraz miary, a test całej wyceny porównuje
równoważne zapisy przy małym cenniku. Kontrola ujemna przywraca obsługę
samego myślnika, a osobne mutacje cofają odmowę przy uszkodzonym ogonie
po parze oraz po pojedynczej liczbie: nazwane testy muszą wtedy oblać własne
asercje.
Bez migracji. Wycofanie samej zmiany parsera przywróci zaniżanie lub
odmowę szacunku dla słownych zakresów; nie zmienia zapisanych danych.

## Kilka bezpośrednich ilości — #2578

`1 kg i 200 g mąki`, `1 kg 200 g mąki` oraz `500 g + 200 g mąki`
nie dostają kosztu liczonego tylko z pierwszej liczby. Parser kosztu
odmawia odczytu ilości, bo sam tekst nie dowodzi, czy obie liczby opisują
ten sam produkt. Pełna wycena, jeśli zna cenę innych składników, pokazuje
brak wiarygodnej masy i nazwę składnika zamiast przedziału kwoty. Poprawny
pojedynczy zapis masy, zakres oraz opis celu po nazwie produktu pozostają
bez zmian. Tekst składnika zapisany przez autora nie jest modyfikowany.

Regresja obejmuje parser i rzeczywistą wycenę na stałym cenniku; kontrola
ujemna usuwa odmowę i wymaga porażki z własnym markerem #2578. Wycofanie
samej reguły odczytu przywraca zaniżanie kosztu do pierwszej ilości, lecz
nie zmienia zapisanych składników ani cennika.
