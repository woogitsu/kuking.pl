# Minutnik w porównaniu wersji przepisu — #2445

Porównanie bierze tekst i czas kroku wyłącznie z dwóch zapisanych migawek.
Przy zmianie tekstu pokazuje po obu stronach również minutnik. Gdy minutnik
istniał tylko w jednej wersji, druga strona mówi „minutnik: brak” — nie
przypisujemy jej czasu z dzisiejszego przepisu. Dodany albo usunięty krok
pokazuje własny czas, jeśli go miał; bez czasu zostaje sam tekst kroku.

Parowanie kroków, ich numery, uprawnienia do historii i widoczność wersji
pozostają takie jak wcześniej. Zmiana dotyczy wyłącznie opisu różnicy na
ekranie „Co się zmieniło”. Nie wymaga migracji ani rollbacku danych; wycofanie
tego kodu przywróciłoby stary, niepełny opis, bez zmiany zapisanych migawek.

Regresję mierzą rzeczywiste `PorownanieWersji::porownaj()` dla zmiany tekstu
i czasu, dołożenia i usunięcia minutnika, sekund niepełnych oraz dodanych
i usuniętych kroków. Test HTTP sprawdza treść sekcji „Przygotowanie” wyrenderowanej
z dwóch wersji, odcinając nagłówek strony i inne miejsca z podobnymi słowami.
