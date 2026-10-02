# „Ugotowałem” zawsze powiadamia autora — szczegóły i trzy granice

Przeniesione z `AGENTS.md` §1 bez zmian treści; zasada w skrócie i odnośnik do tego pliku zostają w `AGENTS.md`.

### Co znaczy tu „zawsze” — i trzy przypadki, w których powiadomienia nie ma

Obietnica działająca w większości ścieżek nie działa, więc słowo „zawsze”
obowiązuje na KAŻDEJ drodze, którą w tym serwisie powstaje wykonanie: przez
formularz, przez akcję domenową wołaną wprost i przez dane demonstracyjne.
Do 12 września 2026 `DemoSeeder` zapisywał dwa wykonania i powiadamiał przy
jednym — obietnica była tam prawdziwa w połowie przypadków, a to są dane, na
których ogląda się serwis lokalnie. Dlatego seeder **też** idzie przez
`RecordCookedEvent`, a nie przez gołe `CookedEvent::create()`.

Granice są trzy, wszystkie odcina `NotifyUser` i wszystkie są zmierzone
w `tests/Feature/UgotowalemZawszePowiadamiaAutoraTest.php`:

1. **Autor ugotował własny przepis.** Wolno mu (`RecipePolicy::cook`), ale
   wiadomość o własnej akcji nie niesie informacji.
2. **Konto autora jest zamknięte** — `banned`, `pending_delete` albo `erased`.
   Przy dwóch pierwszych wykonanie w ogóle nie powstaje, bo przepis takiego
   konta jest niewidoczny. Przy `erased` wykonanie powstaje i **zostaje** (to
   dorobek kucharza), a powiadomienia nie ma, bo nie ma komu go przeczytać.
   **Zawieszenie tu nie wchodzi**: zawieszony autor powiadomienie dostaje —
   zawieszenie odcina od pisania, nie od wiadomości, dla której warto wrócić.
3. **Między autorem a kucharzem jest blokada** (w którąkolwiek stronę). Wtedy
   nie powstaje samo wykonanie.

Czego na tej liście nie ma i mieć nie ma: **ustawienia użytkownika**.
Powiadomienia w serwisie nie wycisza żaden przełącznik. **Wyjątek dotyczy
wyłącznie kanałów zewnętrznych (D-303):** na `/ustawienia/powiadomienia`
człowiek włącza albo wyłącza Web Push (per urządzenie) i ustawia ciszę nocną
oraz dzienny limit — to decyduje, czy i kiedy dowie się o powiadomieniu POZA
serwisem, nigdy o tym, czy powiadomienie w serwisie powstanie. Bez ustawień
per typ. Osobną zgodą jest tygodniowy list (`users.wants_weekly_digest`),
który powiadomień w serwisie też nie dotyka. Ugotowanie
**cofnięte i zrobione ponownie** powiadamia drugi raz, a ta sama osoba
gotująca ten sam przepis dwa razy daje dwa powiadomienia — to są ZDARZENIA,
nie STAN (`NotifyUser::TYPY_WYCISZANE_W_OKNIE`). Jedno ograniczenie jest
wąskie i nazwane: jedno wysłanie formularza to jedno powiadomienie
(`klucz_wyslania`).
