# Zasady schematu i reguły wspólne

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

## Czym się sprawdza, że wycofania naprawdę działają

`AGENTS.md` §6 wymaga przy każdej zmianie schematu opisu rollbacku. Opis to
za mało — rollback trzeba URUCHOMIĆ, i robią to dwie różne rzeczy:

| Narzędzie | Co mierzy | Ile trwa |
|---|---|---|
| `./scripts/proba-wycofania.sh` | podnosi KOMPLET migracji na WŁASNEJ bazie `proba_wycofania*`, schodzi krok po kroku do zera, wraca na szczyt i porównuje `pg_dump --schema-only` ze wzorcem — na każdej głębokości z osobna | kilka minut |
| `tests/Feature/KazdaMigracjaMaWycofanieTest.php` | że każda migracja MA własny, niepusty `down()`; świadoma pustka musi być zadeklarowana stałą `WYCOFANIE_NIC_NIE_ROBI` z uzasadnieniem | ułamek sekundy, w każdym `php artisan test` |

Skrypt schodzi do zera na PUSTEJ bazie, więc nie mierzy zachowania `down()`
przy danych — tego pilnują osobne testy odmowy (`CofniecieMigracji*Test`),
po jednym na strażnika z D-088. Stan zmierzony 12 września 2026: 76 z 76
migracji wycofuje się i wraca. **Zmierzone ponownie 19 września 2026 na
`b34c2973`: 82 z 82**, schemat po cyklu identyczny ze wzorcem na każdej
z 82 głębokości. Liczba migracji rośnie przy każdej zmianie schematu, więc
nie ma jej ani w progu skryptu, ani w progu
`KazdaMigracjaMaWycofanieTest` — obydwa są celowo niższe od stanu dnia.

## V1 / V2

Później:
- groups;
- group_members;
- recipe_forks;
- family_books;
- questions;
- answers;
- meal_plans (rozbudowa planera ponad `meal_plan_entries`);
- shopping_lists (nagłówek listy; pozycje już są w `shopping_list_items`, a wspólna lista — poza zakresem);
- subscriptions;
- payments.

## Dwie reguły, które obowiązują CAŁY schemat

Wszystko wyżej opisuje tabele po kolei. Te dwie rzeczy nie należą do
żadnej z nich z osobna — obowiązują wszystkie i dlatego stoją tu razem,
z asercją w `tests/Feature/SchematBazyTrzymaSieDokumentuTest.php`.

### 1. Każdy klucz obcy ma ZAPISANE zachowanie przy kasowaniu

Klucz obcy bez klauzuli `ON DELETE` nie jest kluczem bez zachowania — dostaje
`NO ACTION` z definicji SQL-a. Różnica jest cała w tym, czy ktoś tę odmowę
WYBRAŁ, czy tylko jej nie napisał; jedno i drugie wygląda w `\d` tabeli
identycznie, a pierwszy raz widać je dopiero przy kasowaniu konta na produkcji.

Zmierzone 12 września 2026 na pełnym schemacie (`pg_constraint`, `contype='f'`):
**71 kluczy obcych**, z tego 44 × `ON DELETE CASCADE`, 23 × `ON DELETE SET NULL`,
3 × `ON DELETE RESTRICT` i **jeden bez klauzuli**.

Trzy `RESTRICT` to nie przeoczenie, tylko ślad, którego nie wolno zgubić:
`dziennik_zgod.user_id`, `moderation_actions.moderator_id`
i `recipe_versions.editor_id`. Baza odmawia skasowania wiersza `users`,
dopóki wisi na nim zgoda, decyzja moderacyjna albo autorstwo wersji przepisu —
kasowanie konta idzie więc przez anonimizację (`data_erased_at`), a nie przez
`DELETE FROM users`.

Jeden klucz bez klauzuli też jest wyborem, jedynym takim w schemacie:
`tags.merged_into_tag_id` → `tags`. Domyślne `NO ACTION` blokuje skasowanie
tagu kanonicznego, dopóki są do niego przypięte tagi scalone (opis przy tabeli
`tags` wyżej, uzasadnienie w migracji `2026_09_07_100000_create_tags_tables`).
Test zna ten jeden wyjątek z nazwy i **sam pilnuje, żeby wyjątek nie zgnił**:
gdy kiedyś dostanie jawne `ON DELETE`, test każe wykreślić go z listy.

Nowy klucz obcy bez `ON DELETE` oblewa test i jest to pytanie, nie zakaz:
„co ma się stać z tym wierszem, gdy zniknie rodzic". Odpowiedzią bywa
`NO ACTION` — ale wpisaną tutaj, nie milczeniem.

### 2. E-mail i nazwa użytkownika są unikalne BEZ WZGLĘDU NA WIELKOŚĆ LITER

Zwykły `UNIQUE (email)` tego nie daje: PostgreSQL porównuje teksty co do
znaku, więc `Jan@example.com` i `jan@example.com` to dla niego dwa różne
adresy. Dla człowieka to jeden adres — a dla klawiatury telefonu, która
kapitalizuje pierwszą literę, to jest zachowanie domyślne, nie wyjątek.

Regułę trzymają dwa **funkcyjne** indeksy unikalne, nie mutatory w PHP:

```sql
CREATE UNIQUE INDEX users_email_lower_unique       ON users    (lower(email));
CREATE UNIQUE INDEX profiles_username_lower_unique ON profiles (lower(username));
```

Mutator `User::email` i `NazwaUzytkownika` dalej normalizują wejście i dalej
są potrzebne — ale jako sposób na ŁADNY komunikat, nie jako gwarancja
(AGENTS.md §6: „walidacja w PHP jest dodatkiem, nie zamiennikiem"; D-079:
„gwarancję daje constraint albo blokada, nie `exists()` w PHP"). Zwykłe
`users_email_unique` i `profiles_username_unique` zostają obok, bo są tańsze
przy wyszukiwaniu po dokładnej wartości. Reguły nie osłabiają: każdy duplikat,
który przeszedłby przez nie, zatrzymuje indeks funkcyjny. **Same z siebie nie
wystarczają** i to jest cały powód, dla którego te dwa funkcyjne istnieją.

Dlaczego akurat te dwie kolumny, a nie „każda kolumna tekstowa z UNIQUE":
to są jedyne dwie, po których człowiek **wraca do własnego konta**. Duplikat
tutaj nie jest brzydkim wierszem w tabeli, tylko drugim kontem tej samej
osoby albo cudzym profilem pod adresem, który ktoś rozdał znajomym.

## Indeksy kluczy obcych na gorących ścieżkach (audyt B3 W1/W2/W5, B4 W4/W5/N13)

PostgreSQL nie zakłada indeksu na kolumnie klucza obcego sam. Migracja
`2026_09_25_100000_indeksy_kluczy_obcych_na_goracych_sciezkach` dokłada go
tam, gdzie pytamy przy każdym żądaniu albo pod blokadą:

| Indeks | Na czym | Kto po nim pyta |
|---|---|---|
| `post_media_media_idx` | `post_media (media_id)` | `DostepDoZdjecia` przy każdym wydaniu zdjęcia, `KasujZdjecie`, `OsieroconeZdjecia`, kontrola FK przy `DELETE FROM media` |
| `cooked_event_media_media_idx` | `cooked_event_media (media_id)` | j.w. |
| `profiles_avatar_media_idx` | `profiles (avatar_media_id) WHERE avatar_media_id IS NOT NULL` | j.w. |
| `recipes_hero_media_idx` | `recipes (hero_media_id) WHERE hero_media_id IS NOT NULL` | j.w.; `OR` ze skanem kartki rozwiązuje `BitmapOr` |
| `recipes_source_scan_media_idx` | `recipes (source_scan_media_id) WHERE source_scan_media_id IS NOT NULL` | j.w. |
| `recipe_steps_media_idx` | `recipe_steps (media_id) WHERE media_id IS NOT NULL` | j.w. |
| `posts_recipe_idx` | `posts (recipe_id) WHERE recipe_id IS NOT NULL` | `WpisWskazujacyPrzepis` pod `FOR UPDATE` przepisu, `ON DELETE SET NULL` |
| `notifications_actor_idx` | `notifications (actor_id) WHERE actor_id IS NOT NULL` | `ON DELETE SET NULL` przy usunięciu konta |
| `product_signals_user_signal_idx` | `product_signals (user_id, signal_name) WHERE user_id IS NOT NULL` | `RecordPromptShown` pod blokadą konta |
| `collection_items_collection_idx` | `collection_items (collection_id)` — zwykły, bez `WHERE` | flagi „zapisane przeze mnie" (`ZapisyWpisu`), kaskada `ON DELETE CASCADE` z `collections` |

Ostatni wiersz pochodzi z osobnej migracji
`2026_10_01_090000_indeks_collection_items_collection_id` (audyt wydajności P3
W6). `collection_items` miała już dwa unikalne indeksy z `collection_id` na
początku, ale oba są **częściowe** (`WHERE recipe_id IS NOT NULL` /
`WHERE post_id IS NOT NULL`), więc zapytanie po samym `collection_id` nie może
z nich skorzystać. Rollback: `DROP INDEX CONCURRENTLY IF EXISTS` — bezstratny.

`post_media.media_id` i `cooked_event_media.media_id` były wcześniej w indeksie
tylko jako **druga** kolumna klucza głównego — to nie zawęża wyszukiwania po
`media_id`. Liczy się kolumna **wiodąca**.

Pilnuje tego `tests/Feature/IndeksyKluczyObcychNaGoracychSciezkachTest.php`:
przechodzi po `DostepDoZdjecia::ODWOLANIA` (a nie po liście przepisanej
z palca), więc nowa kolumna wskazująca na `media` bez indeksu wiodącego oblewa
test.

Indeksy powstają przez `CREATE INDEX CONCURRENTLY` w migracji z
`$withinTransaction = false`, bo tabele są gorące, a zwykłe `CREATE INDEX`
wstrzymuje zapisy na czas budowy. Niedokończony (INVALID) indeks po przerwanej
budowie migracja zdejmuje i buduje od nowa.

**Rollback:** `down()` zdejmuje wszystkie dziewięć indeksów
(`DROP INDEX CONCURRENTLY IF EXISTS`). Bezstratnie — indeks nie niesie danych
ani decyzji człowieka, więc D-088 nie ma tu czego chronić; wracają tylko skany.

Pozostałe klucze obce bez indeksu wiodącego (audyt B3 N1: m.in.
`recipe_versions.editor_id`, `moderation_actions.moderator_id`,
`tozsamosci_zewnetrzne.user_id`) dotyczą rodziców kasowanych rzadko albo nigdy
i świadomie zostały poza tą migracją.

## Indeks częściowy opublikowanych pytań (#372)

Migracja `2026_09_28_233800_add_questions_published_index_to_posts`:

```sql
CREATE INDEX CONCURRENTLY IF NOT EXISTS posts_questions_published_idx
    ON posts (published_at DESC, id DESC)
    WHERE kind = 'question' AND deleted_at IS NULL AND status = 'published';
```

Po co: `/pytania` przy każdym wejściu liczy „Czeka na odpowiedź (N)” osobnym
`COUNT(*)` po całym zbiorze pytań (`QuestionController::index`). Pomiar na
danych syntetycznych (200 000 wpisów, 5% pytań — `scripts/pomiar-pytan-372.py`)
pokazał pełny skan `posts` u gościa i przegląd ~193 000 pozycji
`posts_author_published_idx` u zalogowanego; u zalogowanego zawyżony koszt planu
włączał jeszcze JIT (~280 ms z ~480 ms). Z indeksem licznik zalogowanego spada
do ~110 ms, a lista czyta pytania w kolejności kursora. Pełne liczby, plany
i to, czego indeks nie naprawia (pełny skan `comments` w anty-złączeniu
licznika gościa): `docs/product/WLACZENIE_PYTAN_372.md`.

Od 25.09.2026 licznik nie jest już liczony na żądanie: liczbę gościa przelicza
w tle `PytaniaBezOdpowiedzi::przelicz()` (zadanie po zapisie pytania albo
komentarza pod pytaniem i harmonogram `kuking:policz-pytania`), a zalogowanemu
dolicza się dokładną poprawkę na blokady i obserwowanych. Indeks nadal służy
temu przeliczeniu, poprawce widza i liście. Bez zmian schematu — wynik leży
w istniejącej tabeli `cache` (klucz `pytania:czeka-na-odpowiedz`).

Predykat zawiera tylko warunki obecne w KAŻDYM zapytaniu listy i licznika
(`kind`, `SoftDeletes`, `published()`); widoczność zostaje poza nim, bo gość
i zalogowany pytają o nią inaczej. Rozmiar przy 10 000 pytań: 392 kB.

Indeks powstaje `CONCURRENTLY` w migracji z `$withinTransaction = false`
(ten sam wzorzec co indeksy kluczy obcych wyżej); niedokończony (INVALID)
migracja zdejmuje i buduje od nowa.

Pilnuje go `tests/Feature/IndeksPytanOpublikowanychTest.php`: zapytanie licznika
zbudowane przez `QuestionList` (gość i zalogowany) potrafi użyć indeksu,
zapytanie o dania nie (predykat jest prawdziwy), a `down()`/`up()` zdejmuje
i przywraca indeks.

**Rollback:** `down()` → `DROP INDEX CONCURRENTLY IF EXISTS
posts_questions_published_idx`. Bezstratnie — indeks nie niesie danych ani
decyzji człowieka, więc D-088 nie ma tu czego chronić i `down()` nie odmawia.
Wracają plany sprzed migracji.

## Normalizacja adresu e-mail

`User::email` ma mutator wymuszający małe litery i przycięcie spacji.
PostgreSQL porównuje teksty z uwzględnieniem wielkości liter, a klawiatury
telefonów kapitalizują pierwszą literę — bez tego konto założone jako
`Jan@example.com` było nie do zalogowania przez `jan@example.com`.

Sama reguła stoi jednak w bazie, nie w mutatorze: unikalny indeks funkcyjny
`users_email_lower_unique` — szczegóły i uzasadnienie przy tabeli `users` wyżej.

## `database/reference/schema_mvp.sql` NIE jest stanem bazy

Do 12 września 2026 stało tu zdanie „Pełny referencyjny DDL jest
w `database/reference/schema_mvp.sql`". Słowo **pełny** było nieprawdą i jest
to dokładnie ta nieprawda, przed którą ostrzega `AGENTS.md` §3 przy tabeli
stacku: wpis opisujący ZAMIAR, czytany jako opis STANU.

Zmierzone tego dnia: ten plik ma **22 wyrażenia `CREATE TABLE`**, a schemat
po migracjach ma **50 tabel** (42 nasze i 8 frameworka). Brakuje w nim 28,
z czego **20 naszych** — wszystkie pięć tabel tagów, `dziennik_zgod`,
`appeals`, `pending_email_changes`, `login_link_tokens`,
`registration_invites`, `data_exports`, `product_signals`,
`contact_messages`, `contact_message_replies`, `mail_failures`,
`weekly_digest_sends`, `tozsamosci_zewnetrzne`, `daily_picks`, `hero_picks`
i `recipe_slug_redirects`. Te, które są, też bywają nieaktualne —
`users.text_scale` ma tam `CHECK (BETWEEN 90 AND 140)`, a w bazie jest
`>= 70 AND <= 140` od migracji `2026_09_11_600000_rozszerz_skale_tekstu_w_dol`.

Czym ten plik jest naprawdę: **szkicem MVP z pierwszych dni projektu**,
przydatnym do czytania kształtu, bezużytecznym do sprawdzania faktu. Nic go
nie generuje i nic go nie pilnuje.

**Prawdą o schemacie jest żywa baza po `php artisan migrate`.** Ten dokument
opisuje ją zdaniami, a `SchematBazyTrzymaSieDokumentuTest` pilnuje, żeby żadna
tabela nie została w nim pominięta ani nie została opisana po skasowaniu.

---

### Kontrakt czasu: `timestamptz`, UTC, casty modeli (#2407)

Wszystkie nowe kolumny czasu to `timestamptz`, `app.timezone` = `UTC`, a
strefa **wyświetlania** to `kuking.strefa` (`Europe/Warsaw`) i stosuje ją
widok, nigdy model ani zapytanie. Model oddaje kolumnę czasu jako Carbon w UTC
dzięki jawnemu `datetime` w `casts()`; bez niego pole jest napisem zależnym od
strefy sesji PostgreSQL, a porównanie w PHP robi się na tekście.

Domknięte w #2407 (bez migracji — typ kolumn jest już poprawny, brakowało
castów): `comments.body_removed_at`, `contact_message_replies.sending_started_at`
i `contact_message_replies.audit_recorded_at`. Pilnuje tego
`ZnacznikiCzasuKomentarzaIOdpowiedziMajaCastyUtcTest` (serializacja do
ISO 8601 z `Z`, porównania, chwile przy przejściach DST 29.03 i 25.10.2026).
Rollback: usunięcie castów przywraca stare zachowanie (napis z bazy); danych
nie dotyka.

**Inwentaryzacja castów czasu (schemat bazy testowej po wszystkich migracjach).**
181 kolumn typu `timestamp`/`timestamptz`/`date` w tabelach bazowych; stan po #2407:

| Grupa | Kolumn | Stan |
|---|---|---|
| `created_at`/`updated_at` w modelach z `$timestamps` | 74 | Eloquent sam rzutuje na Carbon — cast zbędny |
| Kolumny modeli z jawnym castem (`datetime`, `immutable_datetime`, `date`, `immutable_date`) | 75 | OK; w tym trzy dopisane w #2407 |
| Kolumny modeli **świadomie bez castu** | 2 | wyjątki poniżej |
| Tabele bez modelu Eloquent (17 tabel, 30 kolumn) | 30 | poza zakresem strażnika, patrz niżej |

Świadomie bez castu (wyjątki w `KolumnyCzasuMajaCastTest::WYJATKI`):

| Kolumna | Powód |
|---|---|
| `users.terms_notice_dismissed_version` | to **etykieta wersji** regulaminu (data zapisana i porównywana jako napis ISO `Y-m-d` z configiem, D-306), nie chwila w czasie; `date` zrobiłby z niej Carbon i zepsuł porównanie napisów oraz eksport RODO |
| `users.policy_notice_dismissed_version` | jak wyżej, dla wersji polityki prywatności (`ZmianaPolityki`) |

Tabele bez modelu (czytane i pisane przez `DB::table()`/SQL, a nie przez
Eloquent, więc cast nie ma gdzie działać): `ai_budzet_dzienny`,
`ai_rezerwacje`, `collection_items`, `collection_members`, `failed_jobs`,
`follows`, `human_urgent_alarm_attempts`, `password_reset_tokens`,
`proby_importu`, `profile_username_redirects`, `przypomnienia_dobowe`,
`recipe_slug_redirects`, `tag_follows`, `wdrozenia`, `wdrozenia_funkcje`,
`weekly_digest_sends`, `zalegle_czyszczenia_cdn`. Gdy któraś dostanie model,
strażnik wymusi cast od razu. Strażnik: `KolumnyCzasuMajaCastTest` — każda
kolumna czasu tabeli modelu z `app/Models` ma cast albo wpis w `WYJATKI` z
powodem (drugi test pilnuje, żeby wyjątek nie przeżył własnej przyczyny).
Rollback: strażnik to sam test, danych nie dotyka.

**Otwarte, świadomie niezrobione: `failed_jobs.failed_at`.** Tabela pochodzi
ze schematu Laravela i ma `timestamp` bez strefy. Zmiana na `timestamptz`
wymaga osobnej, kompatybilnej migracji, **dopiero po sprawdzeniu danych**:
`SELECT min(failed_at), max(failed_at), count(*) FROM failed_jobs` oraz
`SHOW timezone` na bazie produkcyjnej, bo wartość bez strefy trzeba
zinterpretować (zapisywał ją Laravel w `app.timezone`, czyli UTC, więc
`ALTER COLUMN failed_at TYPE timestamptz USING failed_at AT TIME ZONE 'UTC'`).
Przepisanie tabeli jest tu tanie (mało wierszy), ale `down()` ma ODMAWIAĆ, gdy
w tabeli są wiersze (D-088) — cofnięcie `timestamptz` → `timestamp` zależy od
strefy sesji. Do czasu tej migracji nic w kodzie nie powinno porównywać
`failed_at` z kolumnami `timestamptz` bez jawnego `AT TIME ZONE 'UTC'`.
