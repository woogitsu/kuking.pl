## D-293 — `reports.decision_sent_at` stawia list z decyzją PO wysłaniu, a nie akcja przy zakolejkowaniu (#1838, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja techniczna
sesji roboczej, do potwierdzenia przez właściciela · Dotyczy **#1838**

**Problem.** `RozstrzygnijZgloszenie` stawiała `decision_sent_at` zaraz po
`Notification::route('mail', …)->notify(new DecyzjaWSprawieZgloszenia(…))`.
List jest `ShouldQueue`, więc znacznik powstawał w chwili utworzenia
zadania. Worker mógł potem wyczerpać próby (list w `failed_jobs`), a kolumna
opisana w `docs/DATABASE.md` jako „informacja o decyzji przekazana
zgłaszającemu (ust. 5)” dalej twierdziła, że przekazaliśmy.

**Decyzja.**

1. Znacznik stawia **sam list**, w `afterSending()` — po tym, jak transport
   pocztowy przyjął wiadomość. „Przyjęta przez transport” to nie „doszła do
   skrzynki”, ale też nie „powstało zadanie”. Zapis warunkowy
   (`WHERE decision_sent_at IS NULL`), więc znacznik stoi raz.
2. `shouldSend()` pyta bazę o znacznik i pomija wysyłkę, gdy już stoi —
   `queue:retry` albo drugie zakolejkowanie tej samej sprawy nie wyśle
   drugiego listu.
3. **„List przyjęty, zapis znacznika padł”**: wyjątek zapisu jest łapany
   i trafia do dziennika z numerem sprawy (bez adresu). Zadanie NIE pada,
   bo padnięcie znaczyłoby kolejną próbę, czyli kolejny identyczny list
   prawny z linkiem do odwołania. Wybieramy stan fałszywie ostrożny (znacznik
   pusty, choć list wyszedł) zamiast serii duplikatów.
4. Ostateczna porażka (`failed()`) zostawia `Log::error` z numerem sprawy;
   `mail_failures` i `/health` (D-062) działają jak dotąd. Sprawy bez
   przekazanej decyzji liczy zakres `Report::decyzjaNieprzekazanaMailem()`.
5. Kanał w serwisie dla zgłoszeń społecznościowych (`NotifyReporterDecision`)
   zostaje bez zmian — tam powiadomienie powstaje w bazie od razu, więc
   znacznik mówi prawdę w chwili zapisu.

Znacznik jest związany ze sprawą (`reports`), nie z konkretnym wierszem
`moderation_actions`: zgłoszenie ma jedną decyzję (`JednaDecyzjaNaZgloszenieTest`).

**W kodzie.** `app/Notifications/DecyzjaWSprawieZgloszenia.php`,
`app/Domain/Moderation/Actions/RozstrzygnijZgloszenie.php`,
`App\Models\Report::scopeDecyzjaNieprzekazanaMailem()`. Pilnuje
`tests/Feature/DecyzjaZgloszeniaOznaczanaPoWysylceTest.php` — prawdziwa
kolejka `database` i `queue:work`, nie `Notification::fake()`; kontrole ujemne
(przywrócenie znacznika w akcji, usunięcie `shouldSend()`, rzucanie wyjątku
z `afterSending()`, brak zapisu w `afterSending()`, brak wpisu w `failed()`)
wywracają co najmniej jeden test.

**Czego tu nie ma.** Automatycznej dosyłki decyzji (odpowiednika
`kuking:dosylaj-potwierdzenia-zgloszen`) ani sondy w `/health` dla spraw
z `decyzjaNieprzekazanaMailem()`. Ponowienie to dziś `php artisan queue:retry`.
Dołożenie którejś z nich to osobne issue.

### Wycofanie
Bez zmian schematu i danych. Cofnięcie kodu przywraca stawianie znacznika
przy zakolejkowaniu; sprawy rozstrzygnięte w międzyczasie, których list
jeszcze nie wyszedł, zostaną wtedy z pustym znacznikiem do czasu wysyłki
(list stawia go sam tylko w nowym kodzie) — przed cofnięciem sprawdzić
`Report::decyzjaNieprzekazanaMailem()->count()`.
