# Potwierdzenie usunięcia pozycji Planera — #2468

Na `/planer` pierwszy wybór „Usuń z planu” rozwija pytanie w tej samej
pozycji. Pytanie podaje dzień i nazwę własnego wpisu lub dostępnego przepisu.
Gdy przepis nie jest już dostępny, używa neutralnej nazwy; nie pobiera jego
tytułu inną drogą. Po otwarciu pytania pierwszy przycisk zmienia napis na
„Anuluj”; ponowny wybór zamyka pytanie i niczego nie zapisuje. Przycisk „Tak,
usuń z planu” wysyła dotychczasowy formularz DELETE z CSRF. Uprawnienie
właściciela i powrót do tygodnia pozostają w kontrolerze bez zmian.

Wzorzec `<details>` i `<summary>` działa bez JavaScriptu i z klawiatury.
Akcja jest oddzielona od zwykłych przycisków osobnym wierszem. Tekst bierze
rozmiar podstawowy z projektu, a przyciski istniejącą wysokość 48 px.
Układ ma `min-width: 0` i zawija się przy wąskim ekranie oraz powiększeniu.

Regresja HTTP sprawdza rzeczywisty formularz wewnątrz `<details>`, tekst
trzech stanów, brak tytułu niedostępnego przepisu, niezmienność planu po
otwarciu strony i usunięcie wyłącznie wybranej pozycji po DELETE. Kontrola
ujemna przywraca bezpośredni formularz bez `<details>` i musi oblać test.

## Wycofanie

Pomiar prawdziwego ekranu w CI (job `110623651455`, 2.10.2026) wykrył
416 px szerokości po otwarciu pytania przy oknie 320 px. Tor siatki dni
ma teraz minimum zero, a pytanie granicę szerokości swojego wiersza.
Nie podnosimy progu pomiaru; wynik poprawki musi potwierdzić świeże CI.

Kolejny pomiar (`110629201573`) przeszedł dla czcionki 100%, ale przy 200%
wykazał jeszcze 375 px. Poprawka obejmuje też wewnętrzny tor listy pozycji
i nadpisanie globalnych, niewarstwowych stylów `confirm`. Nic nie ukrywa
przepełnienia: pomiar nadal wymaga najwyżej 320 px, a diagnostyka pokazuje
również wewnętrzne `scrollWidth`. Brak lokalnego środowiska przeglądarkowego;
odbiór poprawki wymaga następnego pełnego CI.

Zmiana nie ma migracji ani nowych danych. Cofnięcie widoku, CSS i testu
przywraca dawny sposób obsługi; zapisane pozycje planu pozostają bez zmian.
Taki rollback przywróci też ryzyko przypadkowego usunięcia, więc nie jest
zalecany jako rozwiązanie awarii niezwiązanej z tym ekranem.
