# Podziały wierszy w kopii HTML przepisu — #2839

`przepis.html` w kopii jednego przepisu i w pełnej paczce korzysta z tego samego widoku `exports.recipe` oraz osadzonego arkusza `exports.styles`. Instrukcje kroków i notatka o pochodzeniu są nadal wypisywane przez Blade `{{ }}`, więc wpisane znaczniki HTML pozostają tekstem. Tylko te dwa pola dostają regułę `white-space: pre-line` i `overflow-wrap: anywhere`: pierwsza zachowuje wiersze i puste linie, druga pozwala łamać długi wyraz w wąskim oknie. Reguła działa także w wydruku, bo nie jest ograniczona do `screen`.

Test `KopiaJednegoPrzepisuTest::test_kopia_html_zachowuje_wiersze_krokow_i_historii_bez_surowego_html` pobiera rzeczywistą kopię ZIP przez HTTP i sprawdza jej HTML oraz niezmienione dane JSON. Kontrola ujemna zmienia `pre-line` na `normal` i wymaga porażki z markerem `KOPIA_2839_WIERSZE`.

Test automatyczny sprawdza osadzoną regułę i strukturę rzeczywistego pliku, lecz nie zastępuje oglądu wydruku na fizycznej drukarce ani pomiaru w każdej przeglądarce.

Zmiana nie dotyka schematu ani danych. Wycofanie polega na cofnięciu klasy w dwóch polach i jednej reguły CSS; przywróci to jednak sklejanie wierszy w czytelnej kopii.
