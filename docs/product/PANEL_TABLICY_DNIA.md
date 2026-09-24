# Panel tablicy dnia — #863–#868

## Zachowanie

Dzisiejsze wyróżnienia stoją na początku formularza, przed kandydatami.
Publiczny wpis pozostaje edytowalny także po wypadnięciu poza pierwsze
40 propozycji i po przekroczeniu siedmiu dni. To samo dotyczy aktywnej
wybranej osoby poza listą kandydatów. Odznaczenie usuwa wyróżnienie.

Panel nie pokazuje prywatnej ani ukrytej treści lub notatki przy niedostępnej
pozycji. Zamiast tego uprzedza: „Niedostępne wyróżnienia zostaną pominięte
przy zapisie”. Sam odczyt panelu nie zmienia wyboru. Zapis ponownie sprawdza
dostępność przesłanych identyfikatorów; odrzucenie nie kasuje poprzednich
wyróżnień. Nie zmienia to widoczności żadnego wpisu.

Wyszukiwarka osób obejmuje nazwę i pseudonim wszystkich kwalifikujących się
autorów, także poza pierwszą czterdziestką. Kandydaci są uporządkowani według
ostatniej publicznej publikacji, a remis rozstrzyga identyfikator konta.
W odpowiedzi jest najwyżej 40 kandydatów oraz aktualnie wybrane osoby.
„Szukaj osób” przesyła formularz i odtwarza zaznaczenia oraz notatki przy
wybranych osobach, bez zapisu tablicy. „Zapisz tablicę na dziś” zatwierdza
wybór. Obie czynności działają bez JavaScriptu.

Po błędzie walidacji pola wracają z przesłanego formularza, również gdy
cała grupa została odznaczona albo notatka wyczyszczona. Dane z sesji
przechodzą kontrolę typów, a Blade koduje tekst w HTML. Limit zatwierdzonego
wyboru pozostaje równy sześciu osobom i sześciu wpisom.

Kolejność odczytu wyróżnień wyznacza `position`, następnie `daily_picks.id`
przy remisie. Pobranie modeli przez `whereIn` nie zmienia tego porządku.
Odfiltrowanie niedostępnej pozycji nie przestawia pozostałych; automatyczne
uzupełnienie pozostaje na końcu (D-123). Algorytm doboru automatycznego
w `DailyBoard.php` nie został zmieniony.

Komunikat po zapisie mówi o wyróżnieniach, bez obietnicy liczby kart
widocznych dla każdego odwiedzającego. Audyt `daily_board.updated` zapisuje
`osoby` i `wpisy` jako liczbę zapisanych wierszy, a `przeslane_osoby`
i `przeslane_wpisy` jako wielkość wejścia. Powtórzone UUID-y dają jedno
wyróżnienie. Pusty wybór przywraca dobór automatyczny.

## Decyzja właściciela dotycząca #868 — 20 września 2026

Właściciel wybrał: „Tak — pokaż tytuł pytania i dostosuj podpis sekcji”.
Przy włączonym dziale „Poradźcie” tablica może więc zawierać pytania.
Ich pełny tytuł jest widoczny na karcie i w nazwie dostępnej odnośnika,
także gdy pytanie ma dodatkowy opis. Sekcja zawierająca pytania nazywa się
„Dania i pytania”, a panel używa określenia „Wpisy”. Zwykłe wpisy i wpisy
wskazujące przepis zachowują swoje opisy. Wyłączona flaga, prywatność
i ukrycie nadal wykluczają pytanie z publicznej tablicy.

D-081 pozostaje bez zmian: tablica nie dolicza stanu ani liczby zapisów
w zeszytach. Ta praca nie dotyczy udostępniania pytań.

## Wycofanie

Nie ma migracji ani zmiany schematu. Wycofanie commitów przywraca poprzedni
kod i widoki; już zapisane wyróżnienia zostają w `daily_picks`. Przywraca
też opisane usterki, w tym utratę wyboru przy ponownym zapisie panelu.
Nie należy cofać ani czyścić bazy.

## Dowody

Testy HTTP i PostgreSQL: `PanelTablicyZachowujeWyborTest`,
`KolejnoscWyroznienTablicyDniaTest`, `PytanieNaTablicyDniaTest`.
Raport wykonania, pochodzenie zmian i ograniczenia:
[raport stanowiska](../research/TABLICA_DNIA_863_868.md).
