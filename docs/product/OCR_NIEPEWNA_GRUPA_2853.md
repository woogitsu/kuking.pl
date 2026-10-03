# Niepewna nazwa grupy składników w odczycie kartki (#2853)

D-298 wymaga, aby pierwsza publikacja prywatnego szkicu ze zdjęcia kartki
zatrzymała się na każdym fragmencie `[?…?]`, również w nazwie grupy
składników. Odczytany nagłówek, na przykład `[?Krem?]`, zostaje w szkicu
i przy zdjęciu źródłowym. Kreator oraz zwykły formularz pokazują go w liczbie
fragmentów do sprawdzenia i wskazują konkretną nazwę grupy w błędzie przy
polu oraz w podsumowaniu. Numer pola pozostaje właściwy również wtedy, gdy
przed nim stoi pusty wiersz pomijany przy zapisie. Akcja `PublishRecipe` sprawdza to także przy
bezpośrednim wywołaniu, więc żaden formularz nie może ominąć bramki.

Po ręcznym poprawieniu grupy i zaznaczeniu sprawdzenia ze zdjęciem zwykła
publikacja działa. Reguła jest ograniczona do pierwszej publikacji szkicu
z gotowego odczytu zdjęcia. Dosłowny zapis `[?` w zwykłym przepisie nadal
nie podlega tej bramce. Kod nie usuwa znaczników, nie zamawia nowego OCR
i nie zmienia budżetu AI.

Rollback kodu nie wymaga migracji. Przywrócenie starej bramki dopuszczałoby
niepewne nazwy grup na stronę publiczną; do ponownego wdrożenia poprawki
publikację szkiców OCR należy wstrzymać.
