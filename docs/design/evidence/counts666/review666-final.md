# Końcowy read-only review #666

Odczyt aktualnego diffu ośmiu tracked plików i nowego tests/Feature/CookedCountsUnitsTest.php w kuking-666. Bez zmian implementacji, uruchamiania testów, baz, przeglądarki i subagentów.

## Werdykt

Brak znalezionych blokerów w odczytanym zakresie. Wcześniejszy P2 pozostaje zamknięty: WykonczenieProduktuTest sprawdza oba aktualne napisy, a nie usunięty tekst o osobach.

Nowy test test_unanswered_event_does_not_reach_the_three_answer_threshold tworzy trzy rzeczywiste wykonania z true,true,null. Potwierdza cookedCount=3 i oceniloWykonanie=2, brak badge w recipe-facts oraz dokładny tekst „3 wykonania” w pasku Komu wyszło. Wykryje zarówno błędny mianownik liczący null, jak i uzależnienie widoczności procentu/badge od liczby wykonań. Jego ujemne sprawdzenie badge ma dodatnie pokrycie tego samego selektora w scenariuszu czterech odpowiedzi.

Pozostałe nowe przypadki zachowują kontrolę liczby zdarzeń, odmiany jednostki, sprzecznych odpowiedzi dwóch osób oraz total ponad pierwszą stronę galerii. Napisy uczciwie mówią o wykonaniach i odpowiedziach; brak deklaracji liczby unikalnych osób. Zapytania, widoczneDla, paginacja i >=3 odpowiedzi pozostają bez zmian. Dotychczasowe regresje blokad i JSON-LD nie zostały wyłączone. Alfa0.64 w config i changelog są zgodne, a opis nie przypisuje tej zmianie nowego sposobu agregowania danych.

## Ograniczenia

To review kodu i testów, nie nowe wykonanie testów ani fizycznych negatywów. Nie oceniałem zrzutów ani rzeczywistego zoomu200; root wykonuje ten odbiór osobno. Pełny hook, CI i dostarczenie muszą mieć własne wyniki; brak blockerów w review nie oznacza zakończonego odbioru ani gotowości do merge niezależnie od tych wyników.
