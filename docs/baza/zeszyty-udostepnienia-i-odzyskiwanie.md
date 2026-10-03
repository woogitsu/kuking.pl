# Zeszyty, udostępnienia i odzyskiwanie

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### collections + collection_items
Osobisty zeszyt.

- **`collections.name varchar(120) NOT NULL`** — nazwa zeszytu nadana przez
  właściciela, **wolny tekst**. Unikalna w obrębie JEDNEGO konta i bez
  rozróżniania wielkości liter — szczegóły i powód niżej, przy indeksie
  `collections_owner_name_lower_unique`;
- **`collections.description varchar(500) NULL`** — zdanie o tym, co właściciel
  w tym zeszycie zbiera. `NULL` jest stanem normalnym;
- `visibility varchar(20) NOT NULL DEFAULT 'private'` (CHECK: `public` \|
  `private`) — **domyślnie prywatny**, bo zeszyt jest notatnikiem, a nie
  publikacją;
- `is_default boolean NOT NULL DEFAULT false` — zeszyt zakładany kontu
  automatycznie, ten, do którego trafia „Zapisz" bez wyboru;
- **`collection_items.note varchar(500) NULL`** — dopisek właściciela przy
  zapisanej rzeczy („na urodziny taty"). **Kolumna jest ŻYWA.** Zapisują ją
  `SavePostToCollection.php:41,49` i `SaveRecipeToCollection.php:50,58` (przy
  zapisie i przy ponownym zapisie tej samej rzeczy), a **wychodzi w eksporcie
  danych osobowych** jako `moja_notatka` —
  `app/Domain/Users/Exports/CollectUserExportData.php:335,347`, pilnuje tego
  `tests/Feature/DataExportTest.php:188`. `NULL` jest stanem normalnym: dopisek
  jest nieobowiązkowy.

  > **Sprostowanie z 12 września 2026.** Do tego dnia stało tu, że „dziś nic
  > go nie zapisuje ani nie pokazuje" i że kolumna jest pusta. **To była
  > nieprawda** — pochodziła z sekcji „przy okazji zauważone" w D-166, czyli
  > z hipotezy podanej bez pomiaru. Próbne skasowanie tej kolumny oblewa
  > testy. Gdyby ktoś zaufał tamtemu zdaniu i usunął kolumnę, z eksportu RODO
  > zniknęłaby treść napisana przez człowieka.

**`collection_items` NIE MA DZIŚ KLUCZA GŁÓWNEGO** i to jest stan zamierzony.
Migracja zakładająca tabelę (`2026_09_05_000800_create_collections_tables`)
dała `PRIMARY KEY (collection_id, recipe_id)`, ale migracja
`2026_09_06_150000_collection_items_accept_posts` musiała go zdjąć: kolumna
klucza głównego nie może być NULL, a od tamtej pory `recipe_id` bywa NULL —
w zeszycie stoją także wpisy. Zastępują go **dwa indeksy częściowe**,
`collection_items_recipe_unique` i `collection_items_post_unique`, i pilnują
dokładnie tego samego: ta sama pozycja nie stanie w tym samym zeszycie dwa
razy (issue #43). Pełny opis razem z CHECK-iem stoi wyżej, w sekcji
[`collection_items — przepisy ORAZ wpisy`](ugotowalem-komentarze-zeszyty.md#collection_items--przepisy-oraz-wpisy).

> **Nie przywracaj tu klucza głównego.** Wpisanie z powrotem
> `PRIMARY KEY (collection_id, recipe_id)` wymaga `recipe_id NOT NULL`, czyli
> skasowania wszystkich zapisanych wpisów z zeszytów. Stan schematu pilnuje
> `tests/Feature/ZeszytBezKluczaGlownegoTest`, a unikalność —
> `tests/Feature/UnikalnoscZeszytowTest`.

To samo dotyczy `post_media` — `PRIMARY KEY (post_id, media_id)` plus
`UNIQUE (post_id, position)` stoją tam od migracji zakładającej tabelę.

**Nazwa zeszytu jest unikalna w obrębie jednej osoby, bez rozróżniania
wielkości liter.** Unikalny indeks funkcyjny
`collections_owner_name_lower_unique` na `(owner_id, lower(name))`
(migracja `2026_09_06_090000_add_collections_name_unique_index`, issue #43).

```sql
CREATE UNIQUE INDEX collections_owner_name_lower_unique
ON collections (owner_id, lower(name));
```

Bez tego jedna osoba mogła mieć dwa zeszyty „Obiady”. Lista zeszytów pokazuje
nazwę, liczbę przepisów i widoczność — dwa takie wiersze są nie do odróżnienia
i trzeba wejść do obu, żeby sprawdzić, w którym leży szukany przepis. Zwykle
nie brało się to ze złego nazewnictwa, tylko z podwójnego wysłania formularza.

Indeks jest **funkcyjny**, a nie na kolumnie, z tego samego powodu co przy
`profiles_username_lower_unique`: nazwa zostaje zapisana tak, jak ktoś ją
wpisał („Na Święta” zostaje „Na Święta”), a bez rozróżniania wielkości liter
sprawdzamy tylko, czy jest już zajęta. Dla człowieka „Obiady” i „obiady” to
ta sama nazwa.

Ograniczenie jest **per właściciel** — dwie różne osoby mogą mieć zeszyt
„Obiady” i nic w tym dziwnego.

Migracja **nie scala i nie kasuje** zeszytów przy kolizji: sprawdza, czy takie
pary istnieją, i przerywa z ich listą. Dwa zeszyty o tej samej nazwie to dwa
różne pojemniki, z różną zawartością i możliwie różną widocznością — scalenie
albo skasowanie jednego jest nieodwracalne i mogłoby upublicznić prywatne
zapisy. Decyzję podejmuje człowiek. Rollback to `DROP INDEX`, bez utraty
danych.

Konsekwencja dla domyślnego zeszytu: `User::defaultCollection()` szuka
pierwszej wolnej nazwy („Zapisane”, „Zapisane 2”, …), bo ktoś mógł sam założyć
zeszyt „Zapisane”, zanim cokolwiek zapisał. Bez tego pierwsze „Zapisuję”
kończyłoby się błędem 500.

Równoległe pierwsze zapisy (#778) rozstrzyga indeks
`collections_one_default_per_owner_idx`, który nadal dopuszcza tylko jeden
zeszyt domyślny na właściciela. `User::defaultCollection()` próbuje wstawić
wiersz w osobnej transakcji (PostgreSQL savepoint, gdy akcja już jest
w transakcji), a złapane 23505 sprawdza po nazwie tego właśnie indeksu
i dopiero wtedy odczytuje zwycięski wiersz — kolizja nazwy zeszytu ani inna
przyszła reguła unikalności nie zniknie pod pozornie udanym zapisem.
Szukamy po `is_default`, nigdy po nazwie publicznego zeszytu właściciela.
Pomiar dwóch procesów i ograniczenia: `tests/Dwa/PierwszyZapisDoZeszytuTest.php`
oraz `docs/research/2026-09-20-zeszyt-zapisy-778-779.md`. Schemat nie zmienia
się; wycofanie poprawki jest wyłącznie wycofaniem kodu, bez kasowania zapisów.

### collection_members + collection_invitations — wspólny zeszyt (#1743, D-302)

Rodzinny zeszyt: właściciel zaprasza bliską osobę, która może w jego zeszycie
zapisywać i wyjmować pozycje. Migracje
`2026_09_29_100000_create_collection_sharing_tables` (dwie nowe tabele)
i `2026_09_29_100100_add_added_by_to_collection_items` (kolumna na istniejącej
tabeli). Numery `2026_09_29_1000xx` — przenumerowane z `2026_09_26_1200xx`,
bo ten sam znacznik `2026_09_26_120000` miały jeszcze dwie inne migracje
(kolumna `terms_notice_dismissed_version`, tabela `pantry_items`), a ta ma
stać po najnowszej migracji na main. Reguły dostępu:
`CollectionPolicy::addItem()`, `removeItem()`, `share()`, `leave()`; decyzja i granice — **D-302** w `docs/DECISIONS.md`.

**Dokładnie jeden właściciel** — jak dotąd `collections.owner_id NOT NULL`
z kluczem obcym. Współpracownik nie jest drugim właścicielem.

`collection_members`:

- `collection_id uuid NOT NULL` → `collections` `ON DELETE CASCADE` —
  usunięty zeszyt nie zostawia członkostw;
- `user_id uuid NOT NULL` → `users` `ON DELETE CASCADE`;
- `created_at timestamptz NOT NULL DEFAULT now()` — od kiedy osoba ma dostęp;
- **`PRIMARY KEY (collection_id, user_id)`** — unikalne członkostwo w bazie;
  indeks `(user_id)` dla listy „Udostępnione Tobie";
- **wyzwalacz `collection_members_guard`** (`BEFORE INSERT OR UPDATE`) —
  odmawia (`check_violation`) wpisania właściciela jako członka i dopisania
  kogokolwiek do domyślnego zeszytu (`is_default`). CHECK tego nie wyrazi, bo
  warunek dotyczy wiersza `collections`.

`collection_invitations`:

- `id uuid` (`gen_random_uuid()`), `collection_id` → `collections` CASCADE,
  `inviter_id` → `users` CASCADE, `invitee_id uuid NULL` → `users` CASCADE;
- **`token_hash char(64) NULL UNIQUE`** — SHA-256 tokenu z linku-zaproszenia.
  Sam token nie trafia do bazy; pokazujemy go raz, po utworzeniu. To jest
  poświadczenie (AGENTS.md §7): poza `$fillable`, ukryte w `$hidden`, nie
  wychodzi w eksporcie;
- `via_link boolean NOT NULL DEFAULT false` — czy to był link; po przyjęciu
  token znika, a `invitee_id` jest już ustawiony, więc bez tej kolumny nie
  dałoby się tego odróżnić;
- **`status varchar(20) NOT NULL DEFAULT 'pending'`** (CHECK
  `collection_invitations_status_check`: `pending` \| `accepted` \|
  `declined` \| `revoked`) — pole sterujące, poza `$fillable`;
- `expires_at timestamptz NOT NULL` — 14 dni po nazwie konta, 7 dni linkiem
  (`config/kuking.php`, `collections.*_days`). „Wygasłe" nie jest stanem
  w bazie, tylko `expires_at <= now()`;
- `responded_at timestamptz NULL`, `created_at`, `updated_at`.

CHECK-i i indeksy:

- `collection_invitations_target_check` — oczekujące ma adresata:
  `num_nonnulls(invitee_id, token_hash) >= 1`;
- `collection_invitations_token_only_pending` — **link jest jednorazowy**:
  po odpowiedzi (`accepted`, `declined`, `revoked`) token musi zniknąć;
- `collection_invitations_link_check` — token tylko przy `via_link`;
- `collection_invitations_accepted_has_invitee` — przyjęte zawsze wie, kto
  przyjął;
- `collection_invitations_responded_check` — `responded_at` jest wtedy
  i tylko wtedy, gdy zaproszenie nie czeka;
- `collection_invitations_one_pending_idx` — unikalny częściowy
  `(collection_id, invitee_id) WHERE status = 'pending' AND invitee_id IS NOT NULL`:
  jedno oczekujące zaproszenie tej samej osoby, także przy wyścigu;
- `collection_invitations_invitee_idx`, indeksy `collection_id`, `inviter_id`.

**Odpowiedź na link (#2838).** Przyjęcie i odmowa blokują najpierw oba
wiersze `users` przez `ZamekPary` w stałej kolejności, dopiero potem świeży
wiersz `collection_invitations`. Przy linku `invitee_id` jest początkowo pusty;
odmowa ustawia go na adresata, a klucz obcy do `users` bierze wtedy blokadę
`FOR KEY SHARE`. Odwrotna kolejność tworzyła zakleszczenie z równoległym
przyjęciem (`40P01`). Obie akcje ponownie sprawdzają adresata i stan
zaproszenia pod zamkiem. Test dwóch połączeń wymusza oba przeploty, a kontrola
ujemna odwraca kolejność i musi odtworzyć zakleszczenie. Schemat i tokeny
pozostają bez zmian. Wycofanie kodu przywraca ryzyko `40P01`; nie wymaga
rollbacku bazy.

`collection_items.added_by_id uuid NULL` → `users` `ON DELETE SET NULL`
(`collection_items_added_by_fk`, dodany `NOT VALID` + `VALIDATE`), indeks
częściowy `collection_items_added_by_idx` (`CONCURRENTLY`). **Kto dodał
pozycję** — widać to we wspólnym zeszycie. Wiersze sprzed migracji dostały
`owner_id` zeszytu (do dziś tylko on mógł dopisywać). `NULL` znaczy: dodała to
osoba, której konto zostało usunięte (`ZerwijWspoldzielenie::przyWymazaniu()`).

**Blokada** w którąkolwiek stronę kasuje członkostwa między tymi osobami
i odwołuje oczekujące zaproszenia (`BlockUser` → `ZerwijWspoldzielenie::miedzy()`,
pod zamkiem pary kont). **Usunięcie konta** — patrz D-302.

Ponowienie już przyjętego zaproszenia zachowuje idempotencję tylko wtedy, gdy
osoba nadal jest członkiem i ma aktualne prawo odczytu zeszytu. Pod zamkami
obu kont, zaproszenia i zeszytu sprawdzamy świeże członkostwo oraz
`CollectionPolicy::view()`, zanim kontroler użyje bieżącej nazwy w komunikacie.
Po odebraniu dostępu, odejściu, blokadzie lub zamknięciu konta właściciela
odpowiedź jest neutralna; sam publiczny widok zeszytu nie zastępuje
członkostwa ani prawa do dopisywania.

**Rollback.**

- `2026_09_29_100100_add_added_by_to_collection_items` — `down()` **odmawia**,
  gdy choć jedna pozycja ma `added_by_id` różne od właściciela zeszytu (albo
  `NULL`): ponowna migracja przypisałaby ją po cichu właścicielowi (D-088).
  Na bazie, gdzie wszystko dodał właściciel, zdejmuje indeks, klucz i kolumnę
  bez pytania.
- `2026_09_29_100000_create_collection_sharing_tables` — `down()` **odmawia**,
  gdy istnieje choć jedno członkostwo albo oczekujące zaproszenie, i podaje
  liczby oraz polecenia kopii. Wymuszenie po zrobieniu kopii:
  `KUKING_ROLLBACK_KASUJE_WSPOLDZIELENIE=1`. Zeszyty i ich zawartość zostają
  u właścicieli — znika tylko to, kto miał dostęp. Pilnuje
  `tests/Feature/CofniecieMigracjiWspolnegoZeszytuTest.php` (odmowa i kontrola
  dodatnia dla obu migracji).

### collection_items.position — ręczna kolejność przepisów (#2544)

`collection_items.position integer NULL` (migracja
`2026_10_03_140000_add_position_to_collection_items`) — miejsce przepisu na
liście, którą właściciel ułożył sam (V2, F7/D-333).

- `NULL` = zeszyt nieułożony: kolejność jak dotąd, od najnowszego zapisu
  (`created_at DESC`, remis po id przepisu). Migracja **nie nadaje** pozycji
  istniejącym wierszom. Pozycje powstają z pierwszego świadomego kliknięcia
  „Wyżej" / „Niżej" / „Na początek" / „Na koniec" i obejmują wtedy WSZYSTKIE
  przepisy zeszytu (także niewidoczne dla oglądającego), numerowane od 1;
- CHECK `collection_items_position_check`: `position IS NULL OR (position > 0
  AND recipe_id IS NOT NULL)` — dotyczy wyłącznie przepisów, wpisy zostają bez
  pozycji. Dodany `NOT VALID` + `VALIDATE`;
- unikalny indeks częściowy `collection_items_position_unique`
  `(collection_id, position) WHERE position IS NOT NULL` (`CONCURRENTLY`):
  w jednym zeszycie dwa przepisy nie dzielą pozycji, także przy wyścigu.
  Dziury po wyjętych przepisach są dozwolone — przesuwanie liczy się po
  kolejności, nie po różnicy numerów;
- reguły (`App\Domain\Collections\KolejnoscPrzepisow`): przepis dopisany albo
  przywrócony do ułożonego zeszytu staje na końcu (`max + 1`, pod zamkiem
  zeszytu); do nieułożonego — jak zawsze. Przesunięcie nie rusza `created_at`,
  notatki, autora dopisania i nie powiadamia nikogo. „Wróć do kolejności
  zapisu" zeruje pozycje zeszytu;
- czyta to `Collection::recipes()` (`position ASC NULLS LAST, created_at DESC,
  recipe_id DESC`), więc ekran zeszytu, wydruk (`collections.print`) i paczka
  danych (`kolekcje[].kolejnosc_przepisow` = `reczna` | `od_najnowszego`, lista
  `przepisy` w tej kolejności) pokazują ten sam układ. Układać może wyłącznie
  właściciel własnego PRYWATNEGO zeszytu bez zaproszonych osób
  (`CollectionPolicy::reorder`); zeszyty wspólne są poza pilotem. Wymazanie
  konta i usunięcie zeszytu kasują pozycje razem z wierszami (CASCADE).

**Rollback.** `down()` **odmawia**, gdy choć jeden przepis ma pozycję (D-088):
ułożenie jest decyzją człowieka, której `up()` nie odtworzy. Komunikat podaje
liczbę zeszytów i przepisów oraz polecenie kopii (`CREATE TABLE … AS SELECT`);
alternatywą jest „Wróć do kolejności zapisu" w zeszytach. Na bazie bez
ręcznych układów zdejmuje indeks, CHECK i kolumnę bez pytania. Pilnuje
`tests/Feature/KolejnoscPrzepisowWZeszycieMigracjaTest.php` (odmowa i kontrola
dodatnia).

### first_post_events

Trwała pamięć jednorazowego pierwszego wkładu autora (#1009), niezależna od
retencji alertu. `author_id uuid PRIMARY KEY` wskazuje `users.id` (ON DELETE
CASCADE), `post_id uuid NULL` wskazuje nośnik. Złożony FK
`(author_id, post_id)` do `posts(author_id, id)` wymusza zgodność autora;
`ON DELETE SET NULL (post_id)` zachowuje zdarzenie po fizycznym usunięciu
wpisu. Obsługuje go indeks `posts_author_id_id_unique`. Nie przechowujemy
odbiorcy ani kopii treści. Soft delete nośnika nie zmienia wiersza.

Publikacja zapisuje znacznik w tej samej transakcji co wpis, audyt, alert
i zlecenie analizy (produkcyjna kolejka database na tym samym połączeniu).
Prywatny wpis nie konsumuje pierwszeństwa. Przy istniejącym gospodarzu
obowiązuje pełna dostępność wpisu dla niego; bez gospodarza kwalifikuje się
tylko publiczny. Panel pokazuje utrwalony nośnik tylko odbiorcy z dostępem,
nigdy nie promuje drugiego po usunięciu lub odpowiedzi na pierwszy.

Migracja odtwarza zachowane `post.first` przed fallbackiem do najstarszego
dostępnego wpisu (także soft-deleted). Followers wymaga rzeczywistego
obserwowania przez gospodarza wskazanego `KUKING_HOST_USERNAME` w chwili
migracji (migracja wdrożona przed #1089 — nie jest modyfikowana). Nie wysyła
alertów. Od #1089 kod aplikacji rozpoznaje gospodarza po stabilnym
`KUKING_HOST_USER_ID` (fallback po nazwie tylko przy pustym UUID); nowe wiersze
zapisuje `PublishPost`, więc backfill nie jest liczony ponownie.
Fizycznie usunięta historia bez zachowanego dowodu jest nieodtwarzalna;
pełna gwarancja zaczyna się od wdrożenia. Rollback porównuje dokładne
odtworzenie każdego znacznika, również NULL i tożsamość nośnika; odmawia
przed zmianą schematu, jeśli odtworzenie zmieni znaczenie. Świeża lub
dokładnie odtwarzalna tabela może być cofnięta. Retencja powiadomień bez zmian.

Plan przejścia (instrukcja krok po kroku: `docs/DEPLOYMENT.md`, „Konto
gospodarza"): przed wdrożeniem kodu odczytać UUID aktualnego konta
gospodarza, ustawić `KUKING_HOST_USER_ID` i dopiero potem zmieniać jego nazwę.
Rollback tej migracji odtwarza backfill po `KUKING_HOST_USERNAME`; po zmianie
nazwy gospodarza może świadomie odmówić (D-088) — dane zostają.
Nie trzeba przepisywać istniejących relacji ani powiadomień — już przechowują
UUID. Po potwierdzeniu konfiguracji fallback po nazwie można usunąć osobnym
wdrożeniem. Błędny, niepusty UUID celowo oznacza brak gospodarza, nie próbę
odgadnięcia go po nazwie.

### Skrót do zeszytu w „Moje” — `users.ulubiony_zeszyt_id` (issue #2542, D-333)

Migracja `2026_10_02_190100_add_ulubiony_zeszyt_to_users`.

- **`users.ulubiony_zeszyt_id`** (`uuid NULL`) → `collections` `ON DELETE SET NULL`
  (`users_ulubiony_zeszyt_fk`, dodany `NOT VALID` + `VALIDATE`), indeks częściowy
  `users_ulubiony_zeszyt_idx WHERE ulubiony_zeszyt_id IS NOT NULL`
  (`CONCURRENTLY`; bez niego usunięcie zeszytu skanowałoby `users`). Jeden
  własny zeszyt wskazany jako skrót na ekranie „Moje”. `NULL` — brak skrótu.
- Pole NIE jest w `$fillable`; ustawia je `UstawSkrotDoZeszytu` (blokada
  współdzielona wiersza zeszytu, `owner_id` = osoba), odczyt zawęża do
  właściciela. Usunięcie zeszytu zdejmuje skrót samo (`SET NULL`).
- Eksport RODO: `zeszyt_skrot_w_moje` (nazwa zeszytu); anonimizacja konta
  (`EraseAccountData`) zeruje pole.
- **Rollback:** `down()` **odmawia**, gdy choć jedno konto ma ustawiony skrót
  (D-088) — ludzie straciliby własny wybór; wymuszenie świadome:
  `KUKING_ROLLBACK_KASUJE_SKROT_ZESZYTU=1`; komunikat podaje kopię
  `CREATE TABLE ... AS SELECT` i odtworzenie. Na bazie bez skrótów zdejmuje
  indeks, klucz i kolumnę bez pytania. Test: `tests/Feature/UlubionyZeszytMigracjaTest.php`.

### deleted_collections — kopia odzyskania usuniętego zeszytu (issue #2567, D-333)

Migracja `2026_10_03_160000_create_deleted_collections_table`. Krótka,
ograniczona KOPIA zeszytu, który jego właściciel usunął; służy wyłącznie do
odzyskania go przez właściciela w oknie `kuking.usuniete_tresci.retention_days`
(to samo okno co przepisy, wpisy i komentarze — ADR retencji §5.7). To NIE jest
miękkie usunięcie: `collections` i `collection_items` nie zmieniają znaczenia,
więc liczniki, eksport, unikalna nazwa i unikalny zeszyt domyślny nie muszą
niczego filtrować.

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK, `DEFAULT gen_random_uuid()` | |
| `owner_id` | `uuid NOT NULL` → `users` (`ON DELETE CASCADE`) | właściciel; poza `$fillable` (model ma pusty `$fillable`) |
| `collection_id` | `uuid NOT NULL UNIQUE` | dawny identyfikator zeszytu, bez klucza obcego (zeszytu już nie ma); odzyskany zeszyt wraca pod nim |
| `name` | `varchar(120) NOT NULL` | `CHECK` 1–120 znaków (`deleted_collections_name_check`) |
| `description` | `varchar(500) NULL` | |
| `collection_created_at` | `timestamptz NOT NULL` | data założenia zeszytu (wraca bez zmian) |
| `items` | `jsonb NOT NULL DEFAULT '[]'` | pozycje: `{recipe_id, post_id, note, created_at, position}` — TYLKO identyfikatory, własny dopisek, data zapisania i opcjonalna ręczna pozycja przepisu; żadnych tytułów ani tekstów cudzych treści |
| `items_count` | `integer NOT NULL` | `CHECK jsonb_typeof(items) = 'array' AND jsonb_array_length(items) = items_count` (`deleted_collections_items_check`) |
| `deleted_at` | `timestamptz NOT NULL DEFAULT now()` | początek okna odzyskania |

Indeksy: `(owner_id, deleted_at)` (ekran „Usunięte zeszyty”), `deleted_at`
(nocne sprzątanie).

Kiedy powstaje kopia (`UsunZeszyt`, ta sama transakcja co usunięcie, pod
blokadą `users` → `collections`): konto AKTYWNE, zeszyt PRYWATNY, niedomyślny,
bez członków i bez oczekujących zaproszeń, bez sprawy moderacyjnej
(`reports`/`moderation_actions` z `target_type = 'collection'`), nie więcej niż
`kuking.collections.odzyskanie_max_pozycji` (1000) pozycji, nie więcej niż
`kuking.collections.odzyskanie_max_zeszytow` (20) kopii osoby w oknie. W innym
wypadku zeszyt jest usuwany jak dawniej, a komunikat mówi, że nie da się go
odzyskać (bez nazywania sprawy moderacyjnej).

Odzyskanie (`OdzyskajUsunietyZeszyt`, `FOR NO KEY UPDATE` na koncie, potem
`FOR UPDATE` na wierszu kopii): konto aktywne, własność, termin, brak sprawy
moderacyjnej, nazwa niezajęta przez inny zeszyt osoby
(`collections_owner_name_lower_unique`; kopia zostaje, gdy nazwa jest zajęta).
Zeszyt wraca jako prywatny, niewspółdzielony, z oryginalną datą założenia;
pozycje wracają z własnym dopiskiem, datą zapisania i ręczną kolejnością przepisów, o ile ich przepis albo
wpis nadal istnieje i nie jest usunięty (skasowany przepis nie jest
wskrzeszany, a liczba zapisów, które nie wróciły, trafia do komunikatu).
`added_by_id` = właściciel. Powiadomień nie ma. Skrót w „Moje” nie wraca sam.
Kopia jest kasowana w tej samej transakcji.

Od #2816 opcjonalne `position` zachowuje liczbę dodatnią tylko przy przepisie;
wpis i zeszyt bez ręcznego układu mają `null`. Po pominięciu usuniętego
przepisu pozostałe numery mogą mieć dziury. Starsze kopie bez klucza
`position` nadal się odzyskują, ale dawnego układu nie można z nich odtworzyć
ani zgadywać na podstawie dat. Paczka danych usuniętego zeszytu zawiera
`reczna_pozycja` tylko jako dane właściciela. Nie ma zmiany schematu ani
rollbacku migracji; cofnięcie kodu przed odzyskaniem ponownie zgubiłoby tę
informację, więc najpierw trzeba zachować kopię `deleted_collections.items`.

Sprzątanie: `PrzedawnioneUsunieteZeszyty`, wołane przez
`kuking:sprzataj-usuniete-tresci` (to samo okno), czyta wiersz jeszcze raz pod
`FOR UPDATE`. Wymazanie konta kasuje kopie jawnie (`EraseAccountData`). Paczka
danych ma sekcję `usuniete_zeszyty`; wpis w rejestrze czynności: §3.30.

Rollback (D-088): `down()` usuwa tabelę, ale ODMAWIA, gdy jest choć jedna kopia
w oknie odzyskania. Na pustej tabeli, przy samych przedawnionych kopiach i w CI
(`migrate:refresh`) przechodzi bez pytania. Wymuszenie po kopii tabeli:
`KUKING_ROLLBACK_KASUJE_USUNIETE_ZESZYTY=1`. Testy:
`CofniecieMigracjiNieKasujeUsunietychZeszytowTest`,
`OdzyskanieUsunietegoZeszytuTest`, `tests/Dwa/OdzyskanieZeszytuKontraSprzatanieTest`.
