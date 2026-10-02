# Grupowanie cyfr w masie składnika — #2560

Parser wartości odżywczych odczytuje `1 500 g`, `1 500 g` (NBSP) i
`1 500 g` (wąska NBSP) jako 1500 g. Kilka poprawnych grup, np.
`1 234 567 g`, działa tak samo. Kropka i przecinek nadal znaczą część
dziesiętną; nie zgadujemy, że kropka rozdziela tysiące. Ułamki mieszane
`1 1/2`, zakresy, pojemniki oraz znaczenie „po” i „razem” z #2487 zostają.

Jeśli grupy cyfr są uszkodzone (`1 50 g`, `1 500 00 g`, `1,5 500 g`),
wiersz ma nieznaną masę. Nie wolno brać końcowego `50 g` ani zastępować
jej typową masą puszki ze słownika. Przy braku wiarygodnej masy cały
szacunek przepisu pozostaje ukryty zgodnie z D-299. Tekst autora nie jest
zmieniany. Strukturalne `quantity` i `unit_id`, jeśli są jawnie zapisane,
zachowują pierwszeństwo nad odczytem wolnego tekstu.

Regresje mierzą odczyt na początku, po separatorze i w środku wiersza,
nawiasy, trzy rodzaje spacji oraz kalkulator na lokalnej kontrolowanej
pozycji słownika. Trzy kontrole ujemne przywracają ucięcie grupowanej
masy, częściowe przyjęcie błędnej grupy i awaryjne użycie miary puszki.
Wycofanie kodu przywraca stary
błąd zaniżania; nie ma migracji ani zmiany zapisanych danych.
