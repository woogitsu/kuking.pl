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

**Uzupełnienie (#1429, #1530, #1573, 24 września 2026).** Trzy kolejne
wywołania przypisane do klas:

- `data.export_requested` — **klasa 2**. Autorytatywny ślad to wiersz
  `data_exports` i zadanie w `jobs`, zatwierdzane razem (A02).
- `user.blocked` — **klasa 2**, zgodnie z D-090 i D-080 („blokada musi się
  udać zawsze"). Autorytatywny ślad to wiersz `blocks` z `created_at`.
- `account.login_link_used` — **klasa 1**. Tu trwałym skutkiem jest
  zużycie jednorazowego poświadczenia, więc wpis stoi w transakcji
  `ZamekKonta` razem z `delete()` tokenu. Awaria cofa oba zapisy, sesja
  ani etap 2FA nie powstają, a człowiek dostaje „link nadal działa, kliknij
  jeszcze raz". Samej sesji HTTP transakcja nie obejmuje.

Dowód: `AwariaAudytuNiePrzewracaZatwierdzonejZmianyTest` (eksport, blokada)
i `LogowanieLinkiemTest` (sekcja #1530).

**Uzupełnienie (#829, #830, #1051, 26 września 2026 — decyzja właściciela).**
`content.flagged_by_automat` — **klasa 2**, w obu miejscach, które go piszą:

- `OznaczDoPrzegladu` (nowa sprawa automatu) — już tak od #1051.
- `DolozDoOznaczenia` (sygnały dołożone do istniejącej sprawy, `dolozone:
  true` w metadanych) — od dopisku `7fb4e467` do PR #1548. Wcześniej gołe
  `record()` stało WEWNĄTRZ transakcji dokładania, więc awaria dziennika
  cofała dołożone sygnały, a wyjątek połykany w `PrzeanalizujTresc` zjadał
  pilny alarm.

Autorytatywny ślad to wiersz `reports` (`source = automat`, powód, opis
sygnałów, `alarm_pilny_stan`), zatwierdzany razem z obowiązkiem alarmu:
pilny sygnał dołożony do sprawy bez stanu alarmu zapisuje `ZALEGLY` w tej
samej transakcji. Na pytanie rozstrzygające odpowiedź brzmi „tak" — ślad
decyzji automatu zostaje w bazie bez wpisu dziennika, a o tym, co z nim
zrobić, i tak decyduje człowiek (`moderation.decided`, klasa 1). Awaria
dziennika idzie do `report()` z nazwą braku; sygnały i alarm zostają.

Dowód: `PilnyAlarmModeracyjnyNieGinieTest` (nowa sprawa) i
`ModeracjaBudzetIZdjeciaPoGotowosciTest::test_awaria_dziennika_nie_cofa_dolozonych_sygnalow`
oraz `test_pilny_sygnal_dolozony_zapisuje_zalegly_alarm_przed_listem`
(dokładanie).

**Uzupełnienie (#1305, 25 września 2026).** `appeal.filed` — **klasa 2**.
To czynność samego człowieka (autora treści albo zgłaszającego), a jej
autorytatywny ślad to wiersz `appeals` z terminem DSA art. 20, zatwierdzany
w jednej transakcji z zawiadomieniami administratorów i zleceniem listu
z potwierdzeniem w `jobs` (`FileAppeal`, `FileReporterAppeal`). Awaria
dziennika nie cofa pisma z biegnącym terminem. Dowód:
`ZlozenieOdwolaniaJestAtomoweTest::test_awaria_audytu_nie_cofa_zlozonego_pisma`.

**Uzupełnienie (#1347, #1892–#1897, 26 września 2026).** Audyt zgłoszony
jako „awaria dziennika daje 500 po zatwierdzonej zmianie konta" na pięciu
niepowiązanych ścieżkach naraz — jedna rodzina, jedna reguła, klasyfikacja
niżej:

- `account.delete_requested` (#1347, `RequestAccountDeletion`) i
  `account.delete_cancelled` (#1893, `CancelAccountDeletion`) — **klasa 1**.
  Oba wpisy są, RAZEM, jedynym miejscem w całej bazie mówiącym, że ktoś
  zgłosił usunięcie konta i (ewentualnie) się rozmyślił —
  `AuditLogEntry::NIGDY_NIE_KASUJ` nazywa je z tego właśnie powodu. Sprawa
  w `potwierdzenia_zadan_rodo` ma `zakres = NULL` w toku, a `cancelDeletion()`
  zeruje `users.delete_scope`/`delete_requested_at` — więc bez tego wpisu nie
  zostaje pełny ślad wyboru człowieka. Oba wpisy stoją więc W TEJ SAMEJ
  transakcji co zmiana; awaria cofa całość, a formularz da się wysłać
  jeszcze raz (stan konta wraca do tego sprzed kliknięcia).
- `user.unblocked` (#1896, `UnblockUser`) — **klasa 2**, symetrycznie do
  `user.blocked` (D-090/D-249 wyżej). Autorytatywny ślad to usunięty wiersz
  `blocks`; odblokowania nie da się cofnąć drugim kliknięciem („Zablokuj"
  ponownie to inna decyzja, z innym `created_at`), więc dziennik idzie przez
  `recordBezWywracania()` PO wykonanym usunięciu.
- `account.email_change_requested`, `account.email_changed`,
  `account.email_change_cancelled` (#1897, `RequestEmailChange` /
  `ConfirmEmailChange` / `CancelEmailChange`) — **klasa 2**. Autorytatywny
  ślad każdej z trzech operacji to stan wiersza `pending_email_changes` albo
  `users.email`, zapisany w transakcji tej samej akcji. W `RequestEmailChange`
  dodatkowo: `record()` stał PRZED wysyłką obu listów, więc jego awaria
  blokowała też pocztę — `recordBezWywracania()` nie rzuca, więc listy
  wychodzą niezależnie od losu wpisu.
- `account.suspension_expired` (#1894, `RestoreExpiredSuspensions`) i
  `account.data_erased` (#1894, `EraseAccountData`, wołane z
  `PurgeExpiredAccountDeletions`) — **klasa 1**, mimo że decyzję podejmuje
  zegar, nie moderator. Dla obu wpis jest JEDYNYM zapisem TEGO zdarzenia
  (`reinstate()` nie zostawia innego śladu „wygasła kara, nie inna droga
  powrotu"; `account.data_erased` jest jedynym dowodem wykonania art. 17
  RODO na koncie zanonimizowanym, nie skasowanym). Kluczowe dla komend:
  obie zmiany stoją TERAZ w tej samej transakcji co wpis, więc awaria
  zostawia konto w stanie SPRZED zmiany — a warunek kolejki tej samej komendy
  (`status = suspended` / `data_erased_at IS NULL`) je odzyska same przy
  następnym przebiegu, bez żadnego ręcznego backfillu. Wyjątek jednego konta
  w pętli nie przerywa obsługi pozostałych (ta sama zasada co #1028 dla
  drugiej z tych komend).

Dowód: `tests/Feature/AccountDeletionCancellationTest.php` (#1893),
`tests/Feature/AwariaAudytuNiePrzewracaZatwierdzonejZmianyTest.php`
(#1896, #1897) i `tests/Feature/AwariaAudytuKomendAutomatycznychTest.php`
(#1894).

### Dowód

`tests/Feature/AwariaAudytuNiePrzewracaZatwierdzonejZmianyTest.php`:
awaria `moderation.automat_dismissed` → brak `ModerationAction`, grupa
otwarta, komunikat błędu; ponowienie → jedna decyzja i jeden wpis. Awarie
`account.registered`, `content.reported`, `Registered` i obserwowania
gospodarza → konto albo sprawa istnieje, odpowiedź udana, `report()`
z nazwą braku (rejestracja hasłem, Google i Facebook).

### Wycofanie

Odwrócić commit. Schemat się nie zmienia; danych nie trzeba cofać.
