# Masa łączna w nawiasie składnika — #2487

Parser wartości odżywczych traktuje nieoznaczone `2 puszki (400 g)` jak
400 g na puszkę, zgodnie z dotychczasowym D-299. `po 400 g` znaczy to samo.
Gdy autor napisze `800 g razem` albo `łącznie 800 g`, jest to masa całego
wiersza i nie mnożymy jej przez liczbę opakowań. Działa też odwrotna
kolejność słów oraz zapis `lacznie` bez polskich znaków.

Sprzeczne `po 400 g razem` nie dostaje liczby. Kalkulator nie zastępuje tej
sprzeczności typową wagą puszki ze słownika: wiersz pozostaje bez znanej
masy i cały szacunek nie jest pokazywany. Jeżeli istnieją osobne, jawne
kolumny ilości i jednostki, zachowują pierwszeństwo nad parserem tekstu,
jak w D-299. Tekst autora pozostaje bez zmian.

Regresje obejmują sam parser, pełne wyliczenie na jednej kontrolowanej
pozycji słownika (800 g, 160 kcal) oraz odmowę przy sprzecznym nawiasie.
Kontrola ujemna przywraca mnożenie oznaczonej masy łącznej, a druga usuwa
odmowę dla sprzecznego nawiasu.
