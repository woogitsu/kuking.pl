# Spójność podstron i wiadomości — Alfa 0.10

To zapis odbioru historycznego pakietu. Niezależny [audyt kompletności Alfa 0.14](AUDYT_KOMPLETNOSCI_MARKI_ALFA_014.md)
wykrył dalsze braki rozmiaru tekstu własnych maili i instrukcji logowania.
Poniższy sukces testu palety nie oznaczał sprawdzenia tych cech.

Status sprawdzony 13 września 2026: pakiet scalony w PR #494 (PR #493 zamknięty bez osobnego scalenia), obecny na produkcji jako Alfa 0.11, commit `dbd1cb920f872233f8cc8f240f94273f26f634e8`.

Railway: deployment `ba872757-91a9-4850-97bb-1e9dceaba112`, środowisko production, status SUCCESS. Domena kuking.pl odpowiada HTTP 200 i pokazuje Alfa 0.11 / dbd1cb9. To dowód wdrożenia niezależny od wyniku CI.

CI #928, przebieg `34749126034`, w próbie 2 pokazuje dziewięć sukcesów. Ponowiono tylko wyścigi: job `103704702033`, 5 testów / 44 asercje. Pierwotny job `103702460527` miał brak kroków i nazwy runnera, a log odpowiadał 404 BlobNotFound. Późniejszy odczyt adnotacji w interfejsie GitHuba potwierdził, że zadanie nie wystartowało po pięciu nieudanych próbach pobrania do wykonania. Głębsza przyczyna przydzielania pozostaje nieustalona; szczegółowy dowód w `AUDYT_SPOJNOSCI_ALFA_012.md`. Nie zmieniono zasad D-105.

Audyt #492 potwierdził różnice poza wspólną ramą: biały nagłówek cudzych profili, bazową skalę tytułu przepisu, 16-pikselowe opisy ustawień i wyborów oraz stare fonty i kolory w 11 własnych szablonach HTML e-maili. Poprawki nie zmieniają tras ani uprawnień.

Wiadomości używają palety jasnego motywu z tokens.css, systemowego kroju Arial/Helvetica oraz stylów inline i tabel zgodnych z istniejącym sposobem budowania poczty. Nie dokładamy zewnętrznego fontu ani zdjęć wymagających sesji. Nie deklarujemy testu w każdym kliencie pocztowym.

Pomiar portu obejmuje teraz także cudzy profil, rzeczywisty opublikowany przepis i ustawienia czytelności. Sprawdza renderowaną powierzchnię profilu, skalę H1 i rozmiar pomocniczych opisów. Kontrole ujemne mutują rzeczywisty CSS, przebudowują Vite i wymagają wykrycia błędu; cp i MD5 potwierdzają przywrócenie.

Test wiadomości renderuje 10 szablonów bez digestu, którego obiekt danych i zachowanie pokrywają istniejące testy podsumowania. Kontrola ujemna przywraca starą paletę w źródle wiadomości i sprawdza porażkę testu oraz sukces po przywróceniu. Dwa maile logowania/resetu dodatkowo zachowują działający link i instrukcję wygaśnięcia, bez fałszywej obietnicy, że skrzynka jest jedyną drogą dostępu do konta.

Problem zasłaniania fokusu #485 został naprawiony i wdrożony w tym samym PR #494; szczegóły w `FOKUS_KARTY_DANIA_485.md`. Ten dokument nie jest deklaracją, że każdy historyczny tekst oraz każdy stan wszystkich ekranów przeszedł ręczną redakcję. Standardowe MailMessage, ekrany 500/503, offline oraz eksporty danych nie należały do zakresu powyższych 11 własnych szablonów; ich dalszy odbiór opisuje `AUDYT_SPOJNOSCI_ALFA_012.md`.
