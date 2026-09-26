## D-207 · Kompozycja wizualizacji jest kryterium portu, nie sama paleta

13 września 2026, zgłoszenie właściciela i issue #501. Właściciel porównał
wzorzec „Dzień dobry, Basiu” z produkcją „Witaj, Mateusz” i wymagał
odtworzenia wzorca. Poprzedni audyt tokenów i reflow nie dowiódł zgodności
kompozycji. Dokładnego źródła HTML tego obrazu nie znaleziono w dostępnej
historii, również w gałęzi PR #456; źródłem odniesienia jest przesłany obraz.

Odtwarzamy hierarchię powitania, kafel z pierścieniem, trzy pozycje menu
komputerowego, nagłówek strumienia oraz ciemną i jasne karty boczne.
„Odkrywaj” wraca wyłącznie jako nazwa wejścia do publicznego strumienia
w menu komputerowym; „Szukaj” pozostaje wyszukiwarką. Stałe „Dzień dobry”
zastępuje „Witaj” zgodnie z nowym wzorcem; nie wprowadzamy rozpoznawania
pory dnia, płci ani automatycznej odmiany nazwy. Uaktualniono COPY_STYLE
i konstytucję 1.4, zamiast pozostawić sprzeczne aktywne instrukcje.

Granice: potwierdzony znak garnka, prawdziwe dane i uprawnienia, pięć
mobilnych pozycji, oba źródła strumienia oraz fallback tagów, wspomnienia,
notatki i podglądy tablicy pozostają. Brak demonstracyjnego ostrzeżenia
i fikcyjnych liczb z makiety jest zamierzony. Wymagane są pomiary
rzeczywistego CSS, oglądane zrzuty oraz kontrole ujemne z kopią poza repo
i MD5. Wynik scalenia i wynik produkcji raportujemy oddzielnie.
