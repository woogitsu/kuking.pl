# Powrót po odmowie usunięcia listy zakupów — #2872

Usunięcie nazwanej listy nadal wymaga świadomego potwierdzenia z aktualną
liczbą pozycji. Jeżeli w drugiej karcie ktoś dopisze pozycję albo formularz
nie potwierdza usunięcia, serwer niczego nie kasuje i kieruje z błędem na tę
samą listę. Przy pytaniu widać nową liczbę i wyjaśnienie, co zrobić.

Po odmowie natywne pytanie `<details>` jest otwarte. Link w podsumowaniu
błędów prowadzi do jego widocznego `<summary>`, dostępnego z klawiatury.
Bez błędu pytanie pozostaje zwinięte. Nazwa, pozycje i uprawnienia innych
list nie zmieniają się.

Regresja HTTP przechodzi przez otwarcie listy, dopisanie pozycji w drugiej
karcie, odmowę usunięcia, końcowy GET oraz świeże skuteczne potwierdzenie.
Dwie kontrole ujemne usuwają kolejno cel odnośnika i otwarcie pytania;
obie muszą oblać asercję `SZAKUPY_2872_PYTANIE`.

Rollback kodu: przywrócenie poprzedniego widoku i obsługi przekierowania.
Nie ma migracji ani zmiany zapisanych danych. Test HTTP nie zastępuje
osobnego odbioru klawiaturą w przeglądarce.
