# Wspomnienie w pomiarze dostępności przez północ

Job dostępności zakłada lokalne dane przez `scripts/fixtures/nowe-ekrany-s.php`, a później osobny proces PHP pokazuje `/home`. Dwa przebiegi z 1 października 2026 (#2488 i #2496) zaczęły zgłaszać brak `.wspomnienie` dokładnie o 22:00 UTC, czyli o północy w Polsce. Poprzednia fixture tworzyła tylko rocznicę dnia, w którym ją zapisano. Po północy `Wspomnienia::wykonanieDlaOsoby()` prawidłowo szuka już kolejnego dnia i nie znajduje tamtego wykonania.

Fixture przygotowuje teraz rocznice dwóch kolejnych dni, licząc je z jednego odczytu zegara. Każde wykonanie jest osobnym zdarzeniem pomiarowym; produkt nadal pokazuje najwyżej jedno wspomnienie i nie zmienia swojej reguły dnia ani prywatności. Test `WspomnieniaZWykonanTest::test_fixture_pomiaru_pokazuje_wspomnienie_po_polnocy_w_polsce` przechodzi przez 21:59:55–22:00:05 UTC, a kontrola ujemna usuwa drugi dzień z fixture.

To nie jest ogląd produkcji. Sam test HTTP sprawdza oba stany lokalne; kompletne uruchomienie Chromium/axe oraz wynik pozostałych ekranów potwierdzi dopiero CI PR-a. Nie zmieniamy limitu czasu pomiaru ani zegara aplikacji.
