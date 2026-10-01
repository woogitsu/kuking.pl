# Ilość w zakresie przy orientacyjnym koszcie — #2477

Parser kosztu traktuje `2–3`, `2 do 3`, `2 lub 3` i `2 albo 3` jako tę
samą parę liczb. Do wyceny bierze środek zakresu i jednostkę stojącą po
drugiej liczbie. Dotyczy to także ułamków i przecinków dziesiętnych.
`2 łyżki do smażenia` nie jest zakresem, bo po „do” nie stoi liczba.
Uszkodzony zapis, w którym spójnik stoi bez drugiej liczby albo po
zakresie zaczyna się następna granica (`300-`, `300.5.5`, `300 g do 400 g`),
nie dostaje domyślnej miary „sztuka”; szacunek uczciwie odmawia.

Zmiana obejmuje tylko odczyt tekstu na potrzeby orientacyjnego kosztu
(D-286). Nie poprawia składników zapisanych przez autora, cen, progu
pokrycia ani pierwszeństwa kwoty podanej przez autora. `no_amount` i woda
pozostają poza masą.

Test parsera porównuje granice oraz miary, a test całej wyceny porównuje
równoważne zapisy przy małym cenniku. Kontrola ujemna przywraca obsługę
samego myślnika, a osobna mutacja usuwa odmowę przy uszkodzonym ogonie:
nazwane testy muszą wtedy oblać własne asercje.
Bez migracji. Wycofanie samej zmiany parsera przywróci zaniżanie lub
odmowę szacunku dla słownych zakresów; nie zmienia zapisanych danych.
