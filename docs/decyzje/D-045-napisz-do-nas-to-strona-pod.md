## D-045 · „Napisz do nas" to strona pod własnym adresem, nie dymek w rogu

**Data:** 9 września 2026 · Status: **obowiązuje**

Kontakt z operatorem serwisu ma jedną drogę podstawową: **zwykłą stronę
`/napisz-do-nas`**, renderowaną serwerowo, wysyłaną POST-em, z odnośnikiem
w stopce każdej strony i w nawigacji bocznej zalogowanego. Wiadomość zapisuje
się w tabeli `contact_messages`, a operator obsługuje ją na osobnym ekranie
`/admin/wiadomosci`.

**DLACZEGO NIE DYMEK PRZYKLEJONY DO ROGU EKRANU.** Dymek jest łatwiejszy do
znalezienia dokładnie o tyle, o ile zasłania treść. Dwa powody, oba
zmierzone gdzie indziej w tym repozytorium:

1. **Bez skryptu się nie otwiera.** AGENTS.md §5: ważne funkcje działają bez
   JavaScriptu. Człowiek, który pisze „coś nie działa", jest bardzo często
   tym samym człowiekiem, do którego nie dociągnął się skrypt — dymek byłby
   wtedy przyciskiem, który nic nie robi po kliknięciu. To ta sama decyzja,
   co przy menu pod awatarem w `layout.blade.php`.
2. **Element o stałej pozycji zasłania i rozpycha.** WCAG 1.4.10 (Reflow)
   i 2.4.11 (Focus Not Obscured) — przy 320 px i przy czcionce przeglądarki
   podkręconej do 200% element w rogu zabiera największą część ekranu
   i potrafi zakryć właśnie sfokusowany przycisk. Issues #80 i #162 w tym
   repozytorium dotyczyły dokładnie tej klasy usterki i oba zaczęły się od
   elementu, który „tylko trochę" wystawał poza ekran.

Dymek albo panel wolno kiedyś dołożyć, ale **wyłącznie jako skrót do tego
adresu**, nigdy zamiast niego — i dopiero po przebiegu
`scripts/dostepnosc.mjs`, który mierzy `/napisz-do-nas` przy 320 px i przy
czcionce 200%.

**PISAĆ MOŻE KAŻDY, TAKŻE BEZ KONTA.** Najczęstsze zdanie, jakie ludzie mają
nam do powiedzenia na starcie, brzmi „nie mogę się zalogować" albo „nie udało
mi się założyć konta". Formularz za logowaniem wykluczałby dokładnie te
osoby, dla których w pierwszej kolejności istnieje. Ochroną jest limit
zapytań (`kuking.limits.kontakt`, pięć na godzinę), nie konto — ta sama
konstrukcja, co przy publicznej drodze z DSA art. 16, tylko z luźniejszym
progiem, bo tu nadużycie kosztuje wiersz w tabeli, a nie sprawę z terminem
odpowiedzi.

**TO NIE JEST ZGŁASZANIE TREŚCI I NIE WOLNO TEGO ZLEWAĆ.** Trzy drogi, trzy
kolejki, trzy różne obowiązki:

| Droga | Czego dotyczy | Czym się kończy |
|---|---|---|
| „Zgłoś" pod treścią (`reports`, `community`) | cudzy wpis łamiący nasze zasady | decyzja moderatora, prawo do odwołania |
| `/zglos-nielegalna-tresc` (`reports`, `legal_notice`) | treść niezgodna z prawem (DSA art. 16) | decyzja z pouczeniem o środkach odwoławczych |
| `/napisz-do-nas` (`contact_messages`) | działanie serwisu | odpowiedź człowieka albo poprawka w kodzie |

Rozdział jest zrobiony w schemacie (osobna tabela), w panelu (osobny ekran)
i na obu formularzach (blok „Chodzi o czyjś wpis?" z linkami w obie strony).
Rodzaje wiadomości (`blad`, `pomysl`, `inne`) są świadomie rozłączne
z `Report::REASONS` — gdyby na formularzu technicznym stało „Mowa
nienawiści", ludzie zgłaszaliby tędy sąsiada.

**RETENCJA: 12 MIESIĘCY OD ZAŁATWIENIA**
(`kuking.kontakt.retention_months`), nie od napisania, i **nigdy** dla
wiadomości jeszcze niezałatwionej. Krócej niż 36 miesięcy spraw
moderacyjnych, bo tamten okres broni się tym, że sprawa może wrócić jako
spór prawny — tutaj nie ma decyzji, od której da się odwołać. Dłużej niż
3 miesiące powiadomień, bo pomysł zgłoszony w marcu bywa wdrażany jesienią
i trzeba wtedy wiedzieć, komu odpisać.

**WEBHOOK OPERATORA NIESIE DZWONEK, NIE TREŚĆ.** Po zapisie idzie na kanał
`blad_webhook` (D-041) jedno zdanie: rodzaj z zamkniętej listy,
identyfikator wiersza i adres ekranu w panelu. Treść wiadomości, adres
e-mail i adres strony **nie wychodzą stąd nigdy** — to jest ta sama lista
dozwolonych pól, którą wprowadził audyt A6-01, i pilnuje jej
`tests/Feature/WiadomoscNaWebhookuBezDanychOsobowychTest.php`.

**CO ZOSTAJE DO ROZSTRZYGNIĘCIA WŁAŚCICIELOWI.** Czy na potwierdzenie
odbioru ma iść e-mail (dziś jest wyłącznie potwierdzenie NA EKRANIE, bo
serwis nie ma jeszcze dostawcy poczty — D-040) i czy 12 miesięcy retencji to
właściwa liczba.

**Zmiana wymaga:** decyzji właściciela — dymek/panel wolno dołożyć tylko jako
skrót do tego adresu i tylko z pomiarem dostępności w ręku.

📄 `routes/web.php` · `app/Http/Controllers/NapiszDoNasController.php` ·
`app/Domain/Contact/` · `app/Models/ContactMessage.php` ·
`app/Policies/ContactMessagePolicy.php` ·
`database/migrations/2026_09_09_100000_create_contact_messages_table.php` ·
`config/kuking.php` (`limits.kontakt`, `kontakt.retention_months`) ·
`docs/DATABASE.md` (`contact_messages`) ·
`resources/legal/polityka-prywatnosci.md` §2 · `scripts/dostepnosc.mjs`
