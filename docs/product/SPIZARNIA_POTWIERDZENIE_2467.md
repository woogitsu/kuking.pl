# Potwierdzenie usunięcia produktu — #2467

AGENTS.md §5 wymaga potwierdzenia akcji destrukcyjnej. Dawny przycisk usuwał całą pozycję, wraz z ilością, terminem i oznaczeniem zamrażarki, po jednym dotknięciu.

„Usuń” teraz otwiera natywne `details` z pytaniem i nazwą produktu. Jest oddzielone od zmiany terminu. Dopiero „Tak, usuń z listy” wysyła dotychczasowy formularz DELETE z CSRF. Otwarte podsumowanie ma napis „Anuluj”; ponowne naciśnięcie lub Enter zamyka pytanie bez żądania do serwera. Nie wymaga JavaScriptu ani ponownego pobierania strony.

Test HTTP/DOM sprawdza zamknięte pytanie, brak formularza w pierwszej akcji, nazwę i właściwy formularz oraz brak zmian ilości i terminu po GET. Dodatnie testy sprawdzają usunięcie wyłącznie własnej wybranej pozycji, odmowę dla cudzej oraz escapowanie nazwy. Fizyczna kontrola ujemna przywraca bezpośredni formularz i wymaga porażki `SPIZARNIA_2467_USUNIECIE_WYMAGA_PYTANIA`.

Test HTTP/DOM nie dowodzi obsługi w przeglądarce. Ogląd otwarcia/anulowania bez JavaScriptu, przy 320 px i powiększeniu 200%, wymaga pomiaru przeglądarkowego; nie został wykonany lokalnie w tej zmianie. Nie zmieniamy danych, schematu ani autoryzacji. Wycofanie commita przywraca pojedynczy klik usunięcia, dlatego wymaga świadomego przyjęcia ryzyka przypadkowego skasowania pozycji.
