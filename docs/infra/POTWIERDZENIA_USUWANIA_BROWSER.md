# Potwierdzenia usuwania: przeglądarka (#2466–#2468)

`scripts/potwierdzenia-usuwania-browser.mjs` sprawdza prawdziwy HTML trzech
prywatnych ekranów: listy zakupów, Spiżarni i Planera. Fixture zapisuje po
jednym rekordzie w **osobnej, jednorazowej** bazie PostgreSQL 18. Test loguje
się zwykłym formularzem, wyłącza JavaScript w kontekście Chromium i przy
320 px sprawdza zwykłą czcionkę oraz bazową czcionkę przeglądarki 200%.

W każdym ekranie sprawdza: fokus, rozmiar akcji i tekstu, brak poziomego
przewijania, otwarcie i anulowanie `<details>` przez Enter bez zapisu HTTP i
bez zmiany rekordu, a na końcu jego rzeczywiste usunięcie po potwierdzeniu.
Wykrywa też różnicę między widocznym stanem przycisku a nazwą w drzewie
dostępności (`ariaSnapshot`), również gdy nie ma jawnego `aria-label`.

Warunki uruchomienia w przyszłym jobie przeglądarkowym: migracje na świeżej
bazie o nazwie `kuking_port_confirm_2466_68` w istniejącej usłudze PG18;
jawne `DB_HOST=127.0.0.1`,
`DB_PORT` różne od 5432, `CONFIRM_BROWSER_DB_PORT=$DB_PORT`, `APP_ENV=local`,
klucz testowej aplikacji, Composer i Playwright. Test wymaga odpowiedzi 419 na
żądanie kasujące bez tokenu CSRF, zanim sprawdzi zwykły formularz. Skrypt nie wykonuje migracji,
nie dotyka wspólnej bazy ani produkcji. Sam stawia lokalny serwer, a po pomiarze
usuwa własne rekordy fixture. Krok CI został dodany po połączeniu trzech
poprawek widoków na gałęzi integracyjnej.

Po połączeniu trzech widoków skrypt został dopięty jako jeden krok do
istniejącego joba dostępności. Na etapie przygotowania wykonano tylko kontrolę
składni PHP/Node. Nie wykonano Chromium ani testów PostgreSQL lokalnie;
wynik użytkowy rozstrzygnie CI tej połączonej gałęzi.
