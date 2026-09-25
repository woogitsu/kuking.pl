## D-249 — Wpis w dzienniku audytu: atomowy z decyzją albo pomocniczy za nią — i nic pomiędzy (#1343, #1373, #1363, 23 września 2026)

**Data:** 23 września 2026 · Decyzja zespołu (przegląd kodu gałęzi
`claude/audyt-w-transakcji-g7`) · Prostuje podstawę `AuditLogEntry::recordBezWywracania()` ·
Status: **obowiązuje**

### Co było źle

`recordBezWywracania()` i jego trzy miejsca wywołania powoływały się na
**D-088** cytatem „dziennik audytu zostaje POZA transakcją… jest osobnym
śladem, nie częścią relacji". D-088 dotyczy **odmowy rollbacku migracji**
i o dzienniku audytu nie mówi nic. Cytat pochodzi z **D-090** i opisuje
wyłącznie `BlockUser` (wpis ma powstać wtedy, gdy blokada naprawdę się
zapisała). Na tej złej podstawie zbiorcze zamknięcie sygnałów automatu
(#1343) poszło drogą „za transakcją" — wbrew issue, które wymagało wpisu
w transakcji decyzji.

### Reguła

Każdy wpis `audit_log` należy do jednej z dwóch klas. Trzeciej nie ma.

1. **Atomowy z decyzją — `record()` WEWNĄTRZ `DB::transaction` zmiany.**
   Dla decyzji podjętych przez człowieka z uprawnieniami wobec cudzej
   treści albo konta i dla zmian uprawnień: `moderation.decided`,
   `moderation.automat_dismissed`, `user.role_changed`, a także
   `post.published` (już tak zapisany). Tu wpis jest częścią decyzji —
   „kto, kiedy i ile jednym kliknięciem" nie ma innego zapisu. Awaria
   dziennika **cofa decyzję**, człowiek dostaje komunikat „nic się nie
   zmieniło, spróbuj jeszcze raz", a ponowienie daje jeden komplet.
   Decyzja bez wpisu jest gorsza niż decyzja, którą trzeba kliknąć drugi raz.
2. **Pomocniczy — `recordBezWywracania()` PO zatwierdzeniu zmiany.** Dla
   czynności samego człowieka, których autorytatywny ślad żyje w tabeli
   zmiany: `account.registered` (wiersz `users` z `created_at`
   i `age_confirmed_at`), `content.reported` (wiersz `reports` z terminami
   DSA). Tu cofnięcie zmiany przez awarię dziennika byłoby szkodą dla
   człowieka (utracone zgłoszenie z biegnącym terminem, rejestracja
   odbijająca się od własnego adresu), a 500 po `COMMIT` — kłamstwem.
   Awaria idzie do `report()` z nazwą brakującego wpisu; to nie jest cichy
   sukces.

Rozstrzyga pytanie: **czy bez tego wpisu zostaje w bazie pełny ślad tego,
kto i co zdecydował?** Nie — klasa 1. Tak — klasa 2.

Ta sama zasada dotyczy innych skutków po `COMMIT` rejestracji (#1373):
`event(new Registered)` i obserwowanie gospodarza stoją w punkcie zapisu,
ich awaria idzie do `report()`, a `ZalozKonto` zwraca `ZalozoneKonto`
z flagą „list z potwierdzeniem nie wyszedł", żeby ekran po rejestracji nie
kazał czekać na wiadomość, której nie ma. Ponowienie listu należy do
człowieka („Wyślij potwierdzenie jeszcze raz" w Ustawieniach), naprawa
obserwowania — do operatora (jedno `FollowUser` dla konta z raportu).

### Czego ta decyzja NIE rozstrzyga

Nie przegląda wszystkich pozostałych wywołań `record()` za transakcją
(`BlockUser` z D-090, zmiany adresu e-mail, logowania i inne). Zostają,
jak są; każde następne przeniesienie ma przypisać wpis do jednej z dwóch
klas powyżej, a nie wymyślać trzeciej. D-090 zostaje w mocy dla `BlockUser`.

### Dowód

`tests/Feature/AwariaAudytuNiePrzewracaZatwierdzonejZmianyTest.php`:
awaria `moderation.automat_dismissed` → brak `ModerationAction`, grupa
otwarta, komunikat błędu; ponowienie → jedna decyzja i jeden wpis. Awarie
`account.registered`, `content.reported`, `Registered` i obserwowania
gospodarza → konto albo sprawa istnieje, odpowiedź udana, `report()`
z nazwą braku (rejestracja hasłem, Google i Facebook).

### Wycofanie

Odwrócić commit. Schemat się nie zmienia; danych nie trzeba cofać.
