# Niezależny read-only review odbioru PUT /419 przy zoom200

Odczytano ODBIOR.md, run.mjs, results.json i pomocniczy read-state.php. Obejrzano oba rzeczywiste PNG419-light/dark. Bez ponawiania przebiegu, dostępu do DB ani zmian aplikacji.

## Werdykt

Dowód potwierdza wąski sukces ponowienia PUT z ekranu419 w obu motywach przy realnym zoomie2, viewport320×900 i ustawieniu tekstu140. Skrypt sprawdza chrome.tabs.getZoom=2 po renderze419, odczytuje viewport i ustawienie skali, mierzy przycisk, używa Enter, czeka na prawdziwą odpowiedźPOST302 oraz porównuje wynik zapisu. Kontrolowane wywołanie419 jest jawnie opisane i nie udaje naturalnego wygaśnięcia sesji.

Na obu PNG przycisk „Wyślij jeszcze raz” jest w całości widoczny, z widocznym obrysem fokusu, bez zasłonięcia. Górny pasek istotnie zasłania fragment tekstu, dolny zajmuje dużo miejsca; raport to uczciwie zaznacza. Brak widocznych tokenów, adresów email lub prywatnych treści; widoczny inicjał konta K. To nie dowód pełnej dostępności całego ekranu.

## Doprecyzowania przed publikacją

1. Widoczność zmierzono po `retry.scrollIntoViewIfNeeded()`, już po pięciu Tab. Dlatego dowód potwierdza osiągnięcie fokusu klawiaturą i widoczność po dodatkowym przewinięciu wykonanym przez harness; nie dowodzi widoczności bezpośrednio po samym Tab ani zwykłego przewijania użytkownika. Dodać to ograniczenie do raportu. Nie potrzeba powtarzać całej ścieżki, aby uczciwie opisać obecny wynik.
2. „Baza nie zmienia się przy419” jest szersze niż snapshot: read-state.php porównuje wybrane pola przepisu, wszystkie kroki oraz liczby przepisów/mediów. Nie czyta całej bazy, pełnego rekordu przepisu ani wszystkich powiązań. Napisać „snapshot wskazanych danych przepisu pozostaje bez zmian”.
3. „Zachowane czasy” po zapisie obejmuje w asercjach timer_seconds kroków. Snapshot nie zawiera czasów na poziomie przepisu (np. przygotowania/gotowania), choć niepuste pola payloadu są porównywane przed ponowieniem. Zawęzić do „minutniki kroków”; nie deklarować odczytu pozostałych czasów po zapisie.

## Granice dodatkowe

Tekst140 potwierdza ustawienie aplikacji i wygląd na PNG, nie pomiar końcowego computed font-size. Obrys w JSON był próbkowany podczas animacji (outline:none i niezerowy box-shadow), ale końcowe PNG pokazują rzeczywisty widoczny pierścień. `scroll` w results oznacza scrollWidth, nie pozycję przewijania. Nie wykonano odbioru odzyskanego edytowalnego formularza ani całego Tab — ponowienie realizuje formularz ekranu419 z zachowanym payloadem. Ten zakres jest zgodny z opisanym wąskim celem i nie zamyka całości492.

Nie znaleziono w tym materiale nowego potwierdzonego błędu aplikacji. Wymagane są powyższe doprecyzowania zakresu dowodu, nie automatyczna zmiana implementacji.
