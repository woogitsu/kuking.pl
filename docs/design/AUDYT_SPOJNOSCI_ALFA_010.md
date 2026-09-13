# Spójność podstron i wiadomości — Alfa 0.10

Status: poprawki przygotowane; odbiór przeglądarkowy i pełne CI w toku.

Audyt #492 potwierdził różnice poza wspólną ramą: biały nagłówek cudzych profili, bazową skalę tytułu przepisu, 16-pikselowe opisy ustawień i wyborów oraz stare fonty i kolory w 11 własnych szablonach HTML e-maili. Poprawki nie zmieniają tras ani uprawnień.

Wiadomości używają palety jasnego motywu z tokens.css, systemowego kroju Arial/Helvetica oraz stylów inline i tabel zgodnych z istniejącym sposobem budowania poczty. Nie dokładamy zewnętrznego fontu ani zdjęć wymagających sesji. Nie deklarujemy testu w każdym kliencie pocztowym.

Pomiar portu obejmuje teraz także cudzy profil, rzeczywisty opublikowany przepis i ustawienia czytelności. Sprawdza renderowaną powierzchnię profilu, skalę H1 i rozmiar pomocniczych opisów. Kontrole ujemne mutują rzeczywisty CSS, przebudowują Vite i wymagają wykrycia błędu; cp i MD5 potwierdzają przywrócenie.

Test wiadomości renderuje 10 szablonów bez digestu, którego obiekt danych i zachowanie pokrywają istniejące testy podsumowania. Kontrola ujemna przywraca starą paletę w źródle wiadomości i sprawdza porażkę testu oraz sukces po przywróceniu. Dwa maile logowania/resetu dodatkowo zachowują działający link i instrukcję wygaśnięcia, bez fałszywej obietnicy, że skrzynka jest jedyną drogą dostępu do konta.

Pozostaje oddzielny problem zasłaniania fokusu #485. Ten dokument nie jest deklaracją, że każdy historyczny tekst oraz każdy stan wszystkich ekranów przeszedł ręczną redakcję.
