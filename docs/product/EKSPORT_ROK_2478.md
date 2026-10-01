# Rok rodzinnego przepisu w czytelnej paczce (#2478)

`przepisy/*.html` pokazuje „W rodzinie od … roku” także wtedy, gdy autor podał
tylko rok i pozostawił osobę, notatkę oraz adres źródła puste. Sekcja „Skąd ten
przepis” pozostaje nieobecna, gdy nie ma żadnego z tych czterech pól.

Zakres danych, format `dane.json`, walidacja i sposób zapisu roku nie zmieniają
się. Test `EksportArchiwumPrzepisuTest` buduje rzeczywistą paczkę ZIP i sprawdza
HTML oraz odpowiadający rekord JSON. Warianty z samą osobą, notatką lub adresem
mają własną kontrolę dodatnią. Cofnięcie polega na przywróceniu poprzedniego
warunku szablonu; nie ma migracji danych.
