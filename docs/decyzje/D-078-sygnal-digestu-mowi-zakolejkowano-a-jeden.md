## D-078 · Sygnał digestu mówi „zakolejkowano", a „jeden aktywny eksport na konto" pilnuje baza, nie `exists()`

**Data:** 10 września 2026 · **Audyt drugiej warstwy z 10.09.2026, ustalenia
MAIL-03 oraz QUEUE-04 / RACE-05 (oba P2)** · Status: **obowiązuje**

Dwie niezależne sprawy o tym samym charakterze: **kod twierdził coś
mocniejszego, niż faktycznie zaszło.** Raz w nazwie zdarzenia analitycznego,
raz w obietnicy schematu, której schemat nie składał.

### 1. `weekly_digest_sent` → `weekly_digest_queued` (MAIL-03)

**Stan sprzed zmiany, sprawdzony w pliku:**
`WyslijPodsumowaniaTygodnia::handle()` zapisywał sygnał
`ZapiszSygnal::WEEKLY_DIGEST_SENT` **jedną linijkę po `Mail::queue()`** —
przed jakimkolwiek kontaktem workera z dostawcą poczty.

Nazwa sklejała w jedno trzy różne zdarzenia: **zakolejkowano**, **dostawca
przyjął**, **doręczono**. Kuking ma prawdziwy sygnał tylko o pierwszym.
Skutek był mierzalny i przewrotny: list, który przewróci się w workerze
i wyląduje w `failed_jobs`, **nadal był policzony jako wysłany** — czyli
metryka zawyżała skuteczność wysyłki najbardziej właśnie wtedy, gdy wysyłka
przestawała działać. To jest ta sama klasa usterki co dryf dokumentacji
(patrz `docs/research/audyt-2026-09-10/SPRAWDZENIE.md`): liczba nie jest
fałszywa przez pomyłkę w kodzie, tylko przez nazwę obiecującą więcej, niż kod
może wiedzieć.

**Nazwa jest angielska, `snake_case`** — `AGENTS.md` §11 mówi to wprost
o zdarzeniach analitycznych, a pozostałe nazwy w tym zbiorze
(`photo_upload_failed`, `search_performed`, `weekly_digest_unsubscribed`)
trzymają tę konwencję. Polskie `zakolejkowano` wyłamywałoby jedną nazwę
z ustalonego podziału (nazwy po angielsku, `properties` po polsku).

#### Migracja przepisująca stare wiersze, nie dwie nazwy przy odczycie

To była jedyna realna decyzja w tej połowie i rozstrzygnęło ją **sprawdzenie,
kto tę nazwę czyta. Nikt.** Na `main` `weekly_digest_sent` znały wyłącznie:
`ZapiszSygnal` (zapis), komenda wysyłkowa (zapis) i testy. `kuking:raport`
liczy powroty z `users.ostatnio_widziany_at`, nie z `product_signals`; żaden
ekran panelu nie sięga do `signal_name`; próg z `RETENTION_LOOPS.md` §6
wiersz 5 (wypisy > 1% na wysyłkę) nie jest dziś liczony przez żaden kod.
**Nie ma więc panelu, który po tej zmianie przestaje cokolwiek pokazywać** —
i to jest powód, dla którego dwie nazwy przy odczycie byłyby kosztem bez
korzyści: rozgałęziałyby każde przyszłe zapytanie, a pierwszy człowiek, który
napisze `where('signal_name', 'weekly_digest_queued')` bez tej gałęzi,
dostałby po cichu za małą liczbę.

Migracja `2026_09_10_400000_rename_weekly_digest_sent_signal` robi więc trzy
kroki w tej kolejności: poszerza CHECK o obie nazwy, przepisuje wiersze
(`UPDATE`, nie `DELETE`), zwęża CHECK do nowej. Odwrotna kolejność odbiłaby
`UPDATE` o ograniczenie, którego wiersze jeszcze nie spełniają. `down()` jest
symetryczne i też nie kasuje wierszy — cofnięcie kodu przywraca kod, który
tę nazwę zapisywał, a kasowanie telemetrii przy rollbacku byłoby karą za
cofnięcie wdrożenia. Na produkcji takich wierszy jest prawdopodobnie zero
(digest jest domyślnie wyłączony, D-057 §8), ale migracja tego nie zakłada.

#### Czego świadomie NIE zrobiliśmy: `delivered` i `opened`

Nie emitujemy ani jednego, ani drugiego, i **nie wracamy do pikseli
śledzących, żeby mieć ładniejszą metrykę.** „Doręczono" wymaga webhooka
o odbiciach od dostawcy — osobna, niezrobiona robota (`docs/decyzje/POCZTA.md`
§5 pkt 6). „Otwarto" wymaga niewidzialnego obrazka w treści listu, czyli
zapisywania, kiedy konkretna osoba czyta pocztę i z jakiego adresu IP.
Polityka prywatności obiecuje wprost tego nie robić, transport ma własny
wyłącznik śledzenia u dostawcy (`X-TRACKING-OFF`) domyślnie WŁĄCZONY, a sprawa
jest otwarta jako **#204** i produkt świadomie tego nie chce. Zatrzymujemy się
na uczciwym „zakolejkowano".

Pilnuje tego test, nie tylko zdanie w tym wpisie:
`SygnalDigestuMowiZakolejkowanoTest::test_zamkniety_zbior_nazw_nie_obiecuje_doreczenia_ani_otwarcia`
czyta CHECK wprost z `pg_constraint` i przechodzi po stałych `ZapiszSygnal`
przez refleksję. Nazwa mówiąca „doręczono", „otwarto" albo „kliknięto" oblewa
go. Gdy prawdziwy webhook o odbiciach kiedyś powstanie, `delivered` zdejmuje
się z tamtej listy **jawnie**, jedną decyzją — śledzenia otwarć i kliknięć
nie zdejmuje się wcale.

### 2. Jeden aktywny eksport danych na konto — indeks częściowy (QUEUE-04 / RACE-05)

**Stan sprzed zmiany, sprawdzony w pliku:**
`DataSettingsController::requestExport()` robił `exists()` na stanach
`queued`/`processing`, a potem **osobny `INSERT`**. Schemat nie wymuszał
niczego: `data_exports` miało CHECK na `status` i indeks `(user_id,
created_at)`, ale żadnego ograniczenia unikalności.

Między `SELECT`-em a `INSERT`-em jest okno. Przy izolacji `read committed`
dwa równoległe żądania widzą „nie ma aktywnego eksportu" **jednocześnie**
i oba wstawiają swój wiersz, żadne nie czeka. Skutkiem są **dwa ciężkie
eksporty tego samego konta**: `GenerateUserExport` pakuje wszystkie zdjęcia,
ma 15 minut limitu czasu, chodzi na kolejce `low` przy jednym workerze — plus
dwa listy do jednej osoby z tego samego dobowego wiadra 300 wiadomości.
Wejściem jest podwójne kliknięcie „Zamów swoje dane", a **przy grupie 60+
dwuklik jest scenariuszem typowym, nie skrajnym** (`docs/UX_50_PLUS.md`,
`docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`).

```sql
CREATE UNIQUE INDEX data_exports_one_active_per_user
    ON data_exports (user_id)
 WHERE status IN ('queued', 'processing');
```

**`exists()` W PHP ZOSTAJE — ale robi coś innego niż indeks.** `exists()` daje
ŁADNY KOMUNIKAT, indeks daje GWARANCJĘ. `AGENTS.md` §6: „prawdziwe klucze obce
i prawdziwe CHECK-i w bazie — walidacja w PHP jest dodatkiem, nie
zamiennikiem". Tutaj było odwrotnie. To jest dokładnie ten przypadek, w którym
PostgreSQL potrafi wyrazić inwariant, a `exists()` w PHP nie potrafi.

**`lockForUpdate()` NIE jest tu rozwiązaniem i nie został dodany** — `SELECT
... FOR UPDATE`, który nie zwrócił żadnego wiersza, nie blokuje niczego. To
wstawienie fantomu, nie konflikt na wierszu (zmierzone przy
`reports_one_open_per_pair`, ADR §1.4.2).

**Indeks jest CZĘŚCIOWY, bo inwariant brzmi „jeden AKTYWNY", nie „jeden
w historii".** RODO art. 15 nie jest jednorazowe, a ekran ustawień pokazuje
pięć ostatnich paczek. Zwykły `UNIQUE (user_id)` zamieniłby usterkę
współbieżności na usterkę produktową: człowiek nie mógłby już nigdy zamówić
swoich danych po raz drugi.

**Konflikt kończy się TYM SAMYM zdaniem co zwykły dwuklik, nigdy 500.**
Kontroler łapie `UniqueConstraintViolationException`, upewnia się, że aktywny
eksport naprawdę istnieje (inaczej wyjątek leci dalej — to samo, co robi
`ReportContent`), i oddaje `back()->with('status', …)` z jedną, wspólną
treścią. Człowiek, który kliknął dwa razy, ma zobaczyć to samo co ten, który
kliknął raz; ekran błędu byłby karą za dwuklik. `INSERT` jest owinięty
w `DB::transaction()` — nie z ostrożności, a dlatego, że na PostgreSQL nieudany
`INSERT` wewnątrz szerszej transakcji zatruwa całą transakcję i sprawdzenie po
konflikcie odbiłoby się o „current transaction is aborted" (ta sama pułapka co
w `ZapiszSygnal`).

**Migracja odmawia, gdy w bazie już leżą dwa aktywne eksporty jednego konta**
— bo `CREATE UNIQUE INDEX` i tak by się o nie odbił, tylko komunikatem
PostgreSQL, z którego nie wynika, co zrobić. Komunikat migracji mówi: zostaw
NAJSTARSZY aktywny wiersz na konto, nadmiarowe skasuj — gotowy `DELETE` stoi
w komentarzu migracji. Kasowanie jest tu bezpieczne, **w odróżnieniu od
`reports`**, i to jest osobna decyzja: wiersz w stanie aktywnym nie ma jeszcze
`object_key` ani `disk` (nie ma osieroconego pliku),
`GenerateUserExport::handle()` przy braku wiersza po prostu wraca, a paczka
z pozostawionego wiersza jest bajt w bajt tą samą paczką. To nie jest sprawa
z terminem odpowiedzi z DSA art. 16.

### Ryzyka i rollback

| Zmiana | Rollback | Co wraca |
|---|---|---|
| Nazwa sygnału | `migrate:rollback` na `2026_09_10_400000_*` — przepisuje wiersze z powrotem, nic nie kasuje | stara, nieprawdziwa nazwa; cofać razem z kodem, inaczej w tabeli mieszają się obie |
| Indeks eksportu | `DROP INDEX IF EXISTS` — bezstratnie, żaden wiersz nie ginie | `exists()` łapie zwykły dwuklik, baza nie broni niczego, `catch` staje się gałęzią, w którą nic nie wchodzi |

Największe ryzyko po tej stronie to **migracja odmawiająca na produkcji**
przy istniejących duplikatach. Jest świadome: lepiej zatrzymać wdrożenie
komunikatem mówiącym co zrobić, niż wdrożyć się w połowie.

### Sprostowanie po drodze

`docs/DATABASE.md` twierdził przy `product_signals.occurred_at`, że dla
`weekly_digest_sent` kolumna jest **czytana jako licznik dobowego limitu
poczty**. Nieprawda — sprawdzone w kodzie: sufit liczy
`App\Domain\Security\DziennyBudzetListow`, a ten trzyma licznik w **cache**
i do `product_signals` nie sięga ani razu. Poprawione tam na miejscu.

### Zmiana wymaga

Dla (1) — prawdziwego sygnału od dostawcy poczty, jawnie zdjętego z listy
zakazanych cząstek w teście, plus wpisu tutaj. Śledzenia otwarć i kliknięć
nie dotyczy: to obietnica z polityki prywatności, nie brak funkcji.
Dla (2) — zmiany słownika stanów `data_exports`; wtedy warunek `WHERE` indeksu
i lista w `maAktywnyEksport()` muszą pójść razem, inaczej rozjadą się cicho
(obie gałęzie kończą się tym samym ekranem).

📄 `app/Console/Commands/WyslijPodsumowaniaTygodnia.php` ·
`app/Domain/Analytics/ZapiszSygnal.php` ·
`app/Http/Controllers/Settings/DataSettingsController.php` ·
`database/migrations/2026_09_10_400000_rename_weekly_digest_sent_signal.php` ·
`database/migrations/2026_09_10_400100_one_active_data_export_per_user.php` ·
`tests/Feature/SygnalDigestuMowiZakolejkowanoTest.php` ·
`tests/Feature/JedenAktywnyEksportNaKontoTest.php` ·
`tests/Feature/Wyscigi/EksportDanychRaceTest.php` ·
`tests/Feature/TygodniowePodsumowanieTest.php` ·
`docs/DATABASE.md` (`data_exports`, `product_signals`) ·
audyt `docs/research/audyt-2026-09-10/` (MAIL-03, QUEUE-04 / RACE-05) ·
issue #204 (otwarta: śledzenie otwarć — nie robimy)

[^1]: [spatie/laravel-permission — migracja `create_permission_tables.php.stub`](https://raw.githubusercontent.com/spatie/laravel-permission/main/database/migrations/create_permission_tables.php.stub) — pięć `Schema::create()`: `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`.
[^2]: [spatie/laravel-permission — `config/permission.php`](https://raw.githubusercontent.com/spatie/laravel-permission/main/config/permission.php) — `'store' => 'default'`, `'expiration_time' => DateInterval::createFromDateString('24 hours')`.
[^3]: [Laravel 13.x Docs — Pennant](https://laravel.com/docs/13.x/pennant) — sterownik `database` jest domyślnym mechanizmem trwałego zapisu wartości flag; migracja pakietu tworzy tabelę `features`.
[^4]: [spatie/laravel-activitylog — README](https://raw.githubusercontent.com/spatie/laravel-activitylog/main/README.md) — jedna tabela `activity_log`, kolumny `subject_id`/`subject_type`, `causer_id`/`causer_type`, `description`, `properties`, `event`.
[^5]: `spatie/laravel-activitylog` dokumentacja, sekcja „Log Options" (`docs/advanced-usage/log-options.md` w repozytorium pakietu) — `logOnly()`/`logExcept()`/`dontLogEmptyChanges()`.
[^6]: [spatie/laravel-activitylog — README, sekcja „Clean log"](https://raw.githubusercontent.com/spatie/laravel-activitylog/main/README.md) — komenda `activitylog:clean`, kasuje wpisy starsze niż skonfigurowana liczba dni, bez pojęcia kategorii wyłączonych z kasowania.
