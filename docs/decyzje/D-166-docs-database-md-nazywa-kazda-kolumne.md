## D-166 · `docs/DATABASE.md` nazywa każdą kolumnę TEKSTOWĄ, a pilnuje tego test

**Data:** 12 września 2026 · PR #417 · Status: **obowiązuje** ·
rozwinięcie D-104, wykonanie zauważenia z D-156

### Co było nieprawdą o dokumencie

`AGENTS.md` stawia regułę „zmiana schematu = migracja + test + `docs/DATABASE.md`
+ rollback" od pierwszego dnia. Przegląd rzeczywistego schematu (migracje
wykonane, odczyt z `information_schema` — **49 tabel, 401 kolumn**) wobec
dokumentu znalazł **22 kolumny tekstowe w 13 tabelach**, o których dokument nie
pisał ani razu; sekcja `### recipes` składała się z dwóch słów („Aktualny stan."),
więc nie było tam ani `source_person`, ani `source_note`, ani `source_url`. Drugi
przebieg znalazł jeszcze **12 kolumn w 9 tabelach** opisanych wyłącznie na
zbieżności nazw.

**Koszt jest zmierzony, nie hipotetyczny:** `source_person` nazywa się „person",
jest `varchar(120)` i nie ma w sobie człowieka. Zanim ktoś zapytał właściciela, ta
sama nieprawda została zbudowana **dwa razy** — raz na ekranie („Po Nasze smaki.",
D-153), raz w danych strukturalnych dla Google (`@type: Person`, D-156). Obie
naprawy kosztowały cudzą pracę.

### Zasada

> **Dokument modelu danych ma nazywać każdą kolumnę TEKSTOWĄ, a przy kolumnie
> niosącej treść od człowieka — powiedzieć, co w niej NAPRAWDĘ leży, i czym jest
> `NULL`. Nazwa kolumny nie jest opisem.**

### Zakres obowiązku i dlaczego akurat taki

Strażnik żądający opisu KAŻDEJ kolumny oblewałby przy każdej migracji dokładającej
`position` albo `cos_id` — i zostałby wyłączony w tydzień. **Strażnik, którego się
wyłącza, nie jest strażnikiem.** Obowiązek obejmuje więc kolumny
`text`/`varchar`/`char` poza ośmioma tabelami frameworka (dziś 129 kolumn w 41
tabelach), bo kolumna tekstowa to jedyny rodzaj kolumny, której zawartości **nie
da się odczytać z nazwy i typu**: `family_since_year smallint` mówi o sobie
wszystko, `source_person varchar(120)` mówi nieprawdę.

Lista tabel jest listą **wykluczeń**, nie objętych — nowa tabela wchodzi pod
obowiązek sama.

### Strażnik

`tests/Feature/DokumentacjaBazyOpisujeSchematTest.php`. Schemat czytany
z `information_schema` **żywej** bazy po migracjach, nie z plików migracji:
migracje bywają wielokrotne (`posts.topic_id`), a liczy się stan końcowy. Progi
`MIN_*` (30 tabel / 100 kolumn / 50 000 znaków dokumentu) są zamkiem na skanie
pustego zbioru i na wytrychu „wpisz nasze tabele do wykluczeń". Osobny test
kontroluje sam wykrywacz: musi umieć odpowiedzieć **przecząco**.

### Czego ten strażnik świadomie nie pilnuje

Czy opis jest **prawdziwy** — ze schematu tego wyprowadzić się nie da;
`source_person` był `varchar(120) NULL` także wtedy, gdy wszyscy myśleli, że to
człowiek. Ani **gdzie** w dokumencie kolumna jest nazwana — wymaganie sekcji
oblewałoby przy każdym przestawieniu dokumentu, czyli byłoby tą kruchością, przez
którą strażników się wyłącza. Cena tej granicy jest jawna i została raz zapłacona
ręcznie.

### Przy okazji zauważone, NIETKNIĘTE

> ## ⛔ SPROSTOWANIE z 12 września 2026 — ta sekcja była w DWÓCH punktach nieprawdziwa
>
> Powstała z przeglądu, który tych twierdzeń **nie zmierzył**, tylko je
> zauważył. Przy próbie ich wykonania okazało się, że:
>
> | twierdzenie poniżej | jak jest naprawdę |
> |---|---|
> | `collection_items.note` — nic nie zapisuje ani nie czyta | **żywa**: zapisują `SavePostToCollection.php:41,49` i `SaveRecipeToCollection.php:50,58`, a **wychodzi w eksporcie danych osobowych** jako `moja_notatka` (`Users/Exports/CollectUserExportData.php:335,347`, `DataExportTest.php:188`) |
> | `daily_picks.note` — nic nie czyta | **żywa i widoczna dla człowieka**: `DailyBoardController.php:188`, `DailyBoard.php:161-165`, wyświetlana w `kuking-board.blade.php:138,278` (`DailyBoardTest.php:65,317`) |
> | `users.role` nie ma CHECK-a | **ma**: `users_role_check` istnieje od pierwszej migracji (`0001_01_01_000001_create_users_table.php:58`) i ma własny test (`NadanieRoliTest.php:167`) |
>
> Prawdziwy okazał się jeden punkt: `media.perceptual_hash` była martwa
> i została usunięta. Próbne skasowanie dwóch pozostałych kolumn **oblewa pięć
> testów**.
>
> **Dlaczego zdania niżej zostają zamiast poprawki.** Sekcja „przy okazji
> zauważone" w cudzym przeglądzie jest **hipotezą, nie ustaleniem** — a ta
> podała hipotezę tonem ustalenia. Gdyby ktoś jej zaufał, z eksportu danych
> osobowych zniknęłaby treść napisana przez człowieka. Ostrzeżenie jest warte
> więcej niż czysty wpis; zdania niżej czytaj **jako przykład błędu**, nie jako
> opis stanu.

`media.perceptual_hash`, `collection_items.note` i `daily_picks.note` to kolumny,
których dziś **nic nie zapisuje ani nie czyta**. Zostają, ale dokument mówi to
wprost — opis obiecujący działające pole byłby tą samą klasą nieprawdy.
`recipes.source_url` jest `text` bez limitu w bazie przy walidacji tnącej na 2000
znaków. `users.role` i `units.unit_type` nie mają CHECK-a.

📄 `docs/DATABASE.md` · `tests/Feature/DokumentacjaBazyOpisujeSchematTest.php` ·
D-104 · D-132 · D-153 · D-156
