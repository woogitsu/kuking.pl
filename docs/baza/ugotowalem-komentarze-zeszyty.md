# Ugotowałem, komentarze, zeszyty

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### collection_items — przepisy ORAZ wpisy

Od migracji `2026_09_06_150000_collection_items_accept_posts` zeszyt przyjmuje
także wpisy (UI kit v2, ekran 01 — decyzja właściciela). To dwie różne
potrzeby: zapisany przepis znaczy „chcę to ugotować i mam listę składników",
zapisane zdjęcie — „chcę kiedyś zrobić coś **takiego**".

Wzorzec jest ten sam co przy komentarzach: dwie kolumny dopuszczające NULL
i CHECK `collection_items_single_target_check`
(`num_nonnulls(recipe_id, post_id) = 1`). **Nie polimorfizm** z
`item_type`/`item_id`: tamten zapis nie ma kluczy obcych, więc skasowany wpis
zostawia wiersz wskazujący w próżnię, a baza nie ma jak tego zauważyć.

Klucz główny `(collection_id, recipe_id)` **musiał zniknąć** — kolumna klucza
głównego nie może być NULL. Zastępują go dwa indeksy częściowe:
`collection_items_recipe_unique` i `collection_items_post_unique`. Pilnują
dokładnie tego samego co stary klucz: ta sama pozycja nie stanie w tym samym
zeszycie dwa razy (issue #43).

**Rollback jest STRATNY.** `down()` przywraca stary klucz główny, więc musi
najpierw skasować wiersze z `post_id` — zapisane wpisy znikają z zeszytów
bezpowrotnie. Przy cofaniu na produkcji: najpierw kopia tabeli.

**Widoczność:** zeszyt jest pojemnikiem na CUDZE treści, więc `CollectionController`
przepuszcza wpisy przez `widoczneDla()` i `tylkoOdDostepnychAutorow()`. Wpis,
który przestał być widoczny, **zostaje w bazie**, a ekran mówi ile takich
pozycji jest, nie mówiąc jakich — ciche zniknięcie wygląda jak utrata danych,
a pokazanie treści łamie ustawienie autora.
Właściciel może wyjąć same niedostępne pozycje z jednego zeszytu (#773,
`RemoveUnavailableFromCollection`): kasowane są wyłącznie wiersze
`collection_items` tego zeszytu, wyznaczone tymi samymi filtrami co lista
(`WidocznaZawartoscZeszytu`), i tylko gdy zbiór zgadza się z potwierdzonym
odciskiem. Treść, inne zeszyty i schemat bez zmian — brak migracji.

**Notatka (`note`)** ma od #978 drogę w interfejsie: `UpdateCollectionItemNote`
zmienia wyłącznie `note` jednej pary zeszyt–treść (bez `created_at`, bez
powiadomień), puste pole zapisuje NULL, limit 500 znaków pilnowany w akcji,
nie tylko w kolumnie. Notatkę rysuje `x-notatka-zapisu` tylko właścicielowi
zeszytu. Bez migracji.

### cooked_events
Jedno realne gotowanie. Brak unique `(user_id, recipe_id)`.

- `user_id`, `recipe_id` — kto i co gotował. **Klucza do `posts` tu nie ma**:
  wpis ze zdjęciem jest osobną encją, a gotowanie da się zgłosić bez wpisu;
- `note varchar(2000) NULL` — „Jak wyszło?", czyli **wolny tekst od
  człowieka** o tym jednym gotowaniu;
- `would_make_again boolean NULL` — „zrobię jeszcze raz". `NULL` znaczy
  „nie odpowiedział" i jest czymś innym niż `false`;
- `perceived_difficulty varchar(12) NULL` (CHECK: `easy` \| `medium` \| `hard`)
  — trudność **odczuta przez gotującego**, osobna od `recipes.difficulty`
  deklarowanej przez autora przepisu;
- `actual_minutes integer NULL` (CHECK `>= 0`) — ile to naprawdę zajęło;
- **`changes_note varchar(1000) NULL`** — „co zmieniłem po swojemu". **Wolny
  tekst od człowieka** i najczęściej czytana część komentarza pod przepisem;
  pierwszy krok do „Mojej wersji" (V1);
- `cooked_at timestamptz NOT NULL DEFAULT now()` — kiedy gotowano. Osobne od
  `created_at`, bo wpis o niedzielnym obiedzie bywa pisany we wtorek;
- `klucz_wyslania` — patrz niżej.
- **`recipe_version_id uuid NULL` → `recipe_versions (id)` `ON DELETE SET NULL`**
  (#2378, migracja `2026_10_01_100100_add_recipe_version_id_to_cooked_events`) —
  wersja przepisu otwarta przy formularzu „Ugotowałem”. **Wskaźnik, nie kopia:**
  do wykonania nie trafia żadna treść przepisu. Ustawia go wyłącznie
  `RecordCookedEvent` (poza `$fillable`), po sprawdzeniu, że wersja należy do
  TEGO przepisu; brak/cudzy identyfikator → najnowsza wersja z chwili zapisu;
  przepis bez wersji → `NULL`. `NULL` znaczy „nie wiadomo" (wykonania sprzed
  migracji — bez backfillu — albo wersja skasowana). Czyta go tylko kucharz
  (`CookedEventPolicy::viewVersion` + `WersjaWykonania`); publiczne widoki i
  historia #2024 go nie pokazują.
  Dlaczego `SET NULL`: `CASCADE` skasowałby notatkę i zdjęcie przy retencji
  wersji (#2024), `RESTRICT` zablokowałby `kuking:sprzataj-wersje-przepisow`.
  Wersje usuniętego przepisu i wykonania tego przepisu idą razem z nim
  (`recipe_id` jest `CASCADE`); wymazanie konta kucharza w zakresie `everything` kasuje jego wykonania, a przy
  domyślnym `minimum` (D-022) wykonania zostają przy zanonimizowanym koncie,
  razem ze wskaźnikiem.
  Indeks częściowy `cooked_events_recipe_version_idx (recipe_version_id) WHERE
  recipe_version_id IS NOT NULL` obsługuje kaskadę `SET NULL`.
  **Rollback:** `down()` odmawia, gdy choć jedno wykonanie ma wskaźnik (D-088 —
  kolejny `migrate` odtworzyłby kolumnę pustą); na świeżej bazie i samych
  `NULL`-ach zdejmuje indeks, klucz i kolumnę. Test:
  `tests/Feature/WykonaniePamietaWersjePrzepisuTest.php`. Przyjęte domyślne i
  pytania otwarte: `docs/product/PROPOZYCJA_WYKONANIE_WERSJA_2378.md`.

**`klucz_wyslania` — jedno wysłanie formularza to jeden wiersz** (D-027,
migracja `2026_09_07_900100_add_klucz_wyslania_to_cooked_events`).

```sql
ALTER TABLE cooked_events ADD COLUMN klucz_wyslania uuid NULL;
CREATE UNIQUE INDEX cooked_events_one_per_klucz_wyslania
    ON cooked_events (user_id, klucz_wyslania)
    WHERE klucz_wyslania IS NOT NULL;
```

**To NIE jest `UNIQUE (user_id, recipe_id)` i zakaz z AGENTS.md §6 zostaje
nienaruszony.** Ta sama osoba może gotować ten sam przepis dziesiątki razy
przez lata i każde wykonanie jest osobnym wydarzeniem — indeks pilnuje
wyłącznie tego, żeby JEDNO wysłanie formularza dało JEDEN wiersz. Nowe
gotowanie otwiera nowy formularz, więc dostaje nowy klucz i przechodzi
(zmierzone, ADR §3.4 wiersz 3).

Stawka jest tu wyższa niż przy wpisie: podwójne „Ugotowałem" dawało dwa
wykonania **i dwa powiadomienia** u autora przepisu — a to jest
najcenniejsze powiadomienie w całym serwisie i nie może przychodzić podwójnie
za jedno gotowanie.

**Kolumna jest `NULL`-owalna i nie ma backfillu.** Wiersze sprzed tej
migracji, wiersze z seederów i wiersze z fabryk mają `NULL` i indeks ich nie
obejmuje — w PostgreSQL indeks częściowy z `WHERE klucz_wyslania IS NOT NULL`
mówi to wprost, zamiast liczyć na to, że czytelnik pamięta, iż zwykły UNIQUE
przepuszcza dowolnie wiele `NULL`-i. `NOT NULL` rozwaliłoby `database/seeders/`
i każdy test tworzący wiersz fabryką.

**Wyłącznik:** `kuking.formularze.klucz_wyslania_wlaczony` (`false` →
formularz nie renderuje ukrytego pola, kolumna dostaje `NULL`, indeks
przestaje cokolwiek odbijać). To jedyna droga wycofania bez wdrażania
migracji — dlatego jest w konfiguracji.

**Rollback:** `DROP INDEX IF EXISTS cooked_events_one_per_klucz_wyslania`, potem `DROP COLUMN
klucz_wyslania`. Bezstratnie i dlatego `down()` niczego nie odmawia: kolumna
niesie wyłącznie identyfikator wysłania wygenerowany przez serwer, ani jednego
słowa napisanego przez człowieka.

### cooked_event_media
Zdjęcia z JEDNEGO gotowania. Tabela łącząca `cooked_events` z `media`,
bliźniacza do `post_media` i z tego samego powodu: jedno wykonanie bywa
udokumentowane kilkoma zdjęciami, a to samo zdjęcie nie należy do wykonania
„na własność" — należy do właściciela, a wykonanie je tylko przypina.

```sql
CREATE TABLE cooked_event_media (
    cooked_event_id uuid NOT NULL REFERENCES cooked_events(id) ON DELETE CASCADE,
    media_id        uuid NOT NULL REFERENCES media(id)          ON DELETE CASCADE,
    position        smallint NOT NULL DEFAULT 0,
    PRIMARY KEY (cooked_event_id, media_id)
);
ALTER TABLE cooked_event_media
    ADD CONSTRAINT cooked_event_media_cooked_event_id_position_unique
    UNIQUE (cooked_event_id, position);
ALTER TABLE cooked_event_media
    ADD CONSTRAINT cooked_event_media_position_check CHECK (position >= 0);
```

- **Nie ma tu kolumny `id`** i jest to ta sama decyzja co przy `follows`:
  przypięcie jest tożsamością pary, nie osobnym bytem. Klucz główny
  `(cooked_event_id, media_id)` załatwia przy okazji „to samo zdjęcie dwa razy
  przy jednym gotowaniu";
- `position smallint NOT NULL DEFAULT 0` (CHECK `>= 0`) — kolejność zdjęć
  ustawiona przez człowieka. `UNIQUE (cooked_event_id, position)` mówi, że
  w obrębie jednego wykonania dwa zdjęcia nie stoją na tym samym miejscu;
  przestawianie kolejności wymaga więc zapisu przenoszącego całą serię, a nie
  podmiany jednej liczby. Kolejność czyta relacja `CookedEvent::media()`
  (`orderBy('cooked_event_media.position')`), nie kolejność wierszy;
- **oba klucze obce są `ON DELETE CASCADE`, i każdy kasuje co innego.**
  Kasowanie wykonania zabiera przypięcia i zostawia zdjęcia — plik dalej
  należy do właściciela i może wisieć gdzie indziej. Kasowanie wiersza `media`
  zabiera przypięcie, ale nie wykonanie: opis „jak wyszło" zostaje bez
  zdjęcia, zamiast zniknąć razem z nim.

**Ta tabela jest na obu listach odwołań do `media`** —
`App\Domain\Media\KasujZdjecie::ODWOLANIA`
i `App\Domain\Media\DostepDoZdjecia::ODWOLANIA`. Pierwsza pilnuje, żeby
sprzątacz osieroconych zdjęć nie skasował pliku przypiętego do gotowania;
druga, żeby takie zdjęcie miało rodzica przy pytaniu o dostęp. Wypadnięcie
stąd z którejkolwiek z nich jest cichą awarią i pilnują tego osobne testy
(`ZdjeciaChronioneNieWyciekajaTest`, `AutoryzacjaZdjeciaJednymPrzejsciemTest`).

**Zdjęcia przypina się pod blokadą, w tej samej transakcji co wiersz
`cooked_events`** (`RecordCookedEvent`, issue #285, D-083). Powód jest
zapisany przy tamtej akcji: przy wyborze zdjęć poza transakcją sprzątacz
osieroconych mieścił się w środku, a `cooked_event_media.media_id` kasuje się
kaskadowo — więc wykonanie zostawało bez zdjęcia i bez pliku.

**Rollback:** tabela powstaje i znika razem z `cooked_events`
(`2026_09_05_000600_create_cooked_events_tables`). Osobnego `down()` nie ma
i nie potrzebuje strażnika z D-088: nie leży tu ani jedna wartość semantyczna —
tylko dwa identyfikatory i liczba porządkowa.

### comment_thanks
„Dziękuję” pod komentarzem (issue #2355, F11). Migracja
`2026_10_01_113000_create_comment_thanks_table.php`.

- `id uuid` (PK, `gen_random_uuid()`),
- `comment_id uuid NOT NULL` → `comments` (`ON DELETE CASCADE`) — za który komentarz,
- `thanker_id uuid NOT NULL` → `users` (`ON DELETE CASCADE`) — kto dziękuje;
  zawsze autor treści (wpisu, przepisu, wykonania), pod którą stoi komentarz,
- `created_at timestamptz`.

`UNIQUE (comment_id, thanker_id)` — podziękowanie to STAN („podziękowano”),
nie zdarzenie: drugie kliknięcie nie tworzy drugiego wiersza i nie wysyła
drugiego powiadomienia (`ThankForComment`: `INSERT … ON CONFLICT DO NOTHING`,
powiadomienie tylko gdy wiersz właśnie powstał). Indeks `thanker_id` pod
kaskadę konta i eksport. Kto może dziękować, rozstrzyga `CommentPolicy::thank()`
(nie baza).

**Wycofania nie ma** — decyzja w `ThankForComment` (uprzejmość, nie stan do
odkręcania; powiadomienie i tak już poszło, a „wycofaj i ponów” nie może
wyprodukować drugiego). Wiersz znika z komentarzem (twarde usunięcie) albo z kontem.

Bez licznika i bez wpływu na kolejność: żadna lista nie sortuje ani nie
przycina po tej tabeli (`FeedNieSortujePoMierzeReakcjiTest`, wzorzec „comment”).
Podziękowanie NIE jest odpowiedzią — nie ma wiersza w `comments`, więc nie
zamyka edycji komentarza (#1337) i nie wchodzi do wskaźnika odpowiedzi
(SOUL.md). Stan widzą dwie osoby: dziękujący i autor komentarza.

**Kaskada działa tylko przy twardym usunięciu.** Konta się anonimizuje (D-022),
więc `EraseAccountData` kasuje jawnie podziękowania wymazywanego konta
w OBU kierunkach (`thanker_id` oraz `comment_id` jego komentarzy) — przy każdym
`delete_scope`. Eksport: `moje_podziekowania` (adres rozmowy i chwila, bez
treści i bez nazwy komentującej); podziękowania otrzymane są w `powiadomienia`
(typ `comment.thanked`, z żywym wycinkiem komentarza).

**Rollback odmawia (D-088)**, gdy w tabeli są podziękowania — to słowa ludzi
do ludzi, a `up()` ich nie odtworzy. Na pustej tabeli przechodzi. Test:
`DziekujePodKomentarzemTest::test_rollback_odmawia_gdy_sa_podziekowania…`.

### comments
Komentarz dotyczy dokładnie jednego:
- post;
- recipe;
- cooked event.

Pilnuje tego CHECK `comments_single_target_check`:
`num_nonnulls(post_id, recipe_id, cooked_event_id) = 1`.

**`comments.body varchar(4000) NOT NULL`** — treść komentarza, **wolny tekst
od człowieka**, zapisywana dosłownie. 4000 znaków to nie jest limit
„dla porządku": pod przepisem pisze się przepis po swojemu, a ucięcie
takiego komentarza w połowie zdania byłoby zabraniem komuś głosu bez
uprzedzenia. `parent_id uuid NULL` → `comments` — odpowiedź na komentarz;
`NULL` znaczy „komentarz pierwszego poziomu". Kasowanie jest miękkie
(`deleted_at`), a `status` (`published` \| `hidden` \| `removed`) trzyma
decyzję moderacji osobno od skasowania przez autora.

**Odpowiedź dotyczy tej samej treści co rodzic i wisi pod komentarzem
głównym (#954).** Pilnuje tego wyzwalacz
`comments_odpowiedz_zgodna_z_rodzicem_trg` (funkcja
`comments_odpowiedz_zgodna_z_rodzicem()`), `BEFORE INSERT OR UPDATE OF
parent_id, post_id, recipe_id, cooked_event_id`. Odrzuca (SQLSTATE `23000`):

- odpowiedź, której `post_id`/`recipe_id`/`cooked_event_id` różni się od
  rodzica (porównanie `IS NOT DISTINCT FROM` na wszystkich trzech);
- odpowiedź na odpowiedź (`parent.parent_id IS NOT NULL`) — drzewo ma jeden
  poziom, `PublishComment` spłaszcza do korzenia;
- `parent_id = id`;
- zamianę w odpowiedź komentarza, który ma odpowiedzi;
- zmianę celu komentarza głównego, pod którym są odpowiedzi.

CHECK nie może czytać innego wiersza, a FK złożony nie zadziała na
NULL-owalnych kolumnach celu — stąd wyzwalacz. Rodzica czyta `FOR SHARE`,
więc równoległe „wstaw odpowiedź” i „zmień cel rodzica” nie miną się.
Brakującego rodzica zgłasza FK, nie wyzwalacz. Kaskada `ON DELETE` bez zmian.

Migracja `2026_09_24_100000_odpowiedz_dotyczy_tej_samej_tresci_co_rodzic`
najpierw (pod `SHARE ROW EXCLUSIVE` na `comments`) liczy zastane niespójne
wiersze, także miękko skasowane, i przy choćby jednym **odmawia** z liczbami.
Nie przepina rozmów. Wiersze pokazuje skrypt tylko-do-odczytu
`docs/diagnostyka/954_odpowiedzi_niezgodne_z_rodzicem.sql`.

Rollback: `down()` zdejmuje wyzwalacz i funkcję. Bezstratny — nie dotyka
wierszy, więc nie ma strażnika z D-088. Po nim regułę trzyma już tylko
`PublishComment`.

`body_removed_at timestamptz NULL` oznacza usunięcie treści z zachowaniem
wątku odpowiedzi (#372). Kontroler zapisuje ten znacznik razem z tekstem
„Komentarz usunięty.”, jeżeli komentarz ma dzieci. Ślad nadal pozwala czytać
rozmowę, ale nie jest odpowiedzią na pytanie: nie trafia do licznika odpowiedzi,
QAPage ani nie usuwa pytania z kolejki gospodarza. Nie można go ponownie edytować.
Migracja nie odgaduje historycznych usunięć z samego tekstu. Cofnięcie kolumny
jest dozwolone tylko, gdy wszystkie wartości są NULL; sprawdzenie i DDL są
objęte jedną blokadą tabeli. Przy istniejących znacznikach wycofuje się kod
bez cofania tej migracji.

**Podwójne kliknięcie „Wyślij" NIE jest tu pilnowane przez schemat —
i to jest świadome.** Zmierzone przed poprawką (audyt podwójnego wysłania,
12 września 2026): dwa identyczne `POST /wpisy/{post}/komentarz` dawały
**dwa** wiersze i **dwa** powiadomienia u autora wpisu; po poprawce jeden
i jedno. Ochrona stoi w akcji domenowej `PublishComment` i jest BLOKADĄ
W BAZIE z rewalidacją pod nią (`pg_advisory_xact_lock` na tożsamości
wysłania: autor + miejsce + wątek + treść), a nie ograniczeniem w tabeli.

Powód, dla którego nie ma tu `klucz_wyslania` jak w `posts`, `recipes`,
`cooked_events` i `reports`: klucz musi przyjechać z formularza, a formularz
komentarza jest **jeden dla trzech ekranów**
(`resources/views/components/comment-thread.blade.php`) i nie ma w nim
miejsca na własne pole bez zmiany tego komponentu.

Powód, dla którego nie ma tu `UNIQUE` na treści: to samo zdanie pod tym samym
wpisem po tygodniu jest **nową reakcją, nie duplikatem**, a zakaz bez okna
czasowego wyciszałby rozmowę. Okno stoi
w `kuking.formularze.okno_powtorzenia_komentarza_sekund` (domyślnie 60 s,
`0` wyłącza mechanizm).

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
[`collection_items — przepisy ORAZ wpisy`](#collection_items--przepisy-oraz-wpisy).

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

`collection_items.added_by_id uuid NULL` → `users` `ON DELETE SET NULL`
(`collection_items_added_by_fk`, dodany `NOT VALID` + `VALIDATE`), indeks
częściowy `collection_items_added_by_idx` (`CONCURRENTLY`). **Kto dodał
pozycję** — widać to we wspólnym zeszycie. Wiersze sprzed migracji dostały
`owner_id` zeszytu (do dziś tylko on mógł dopisywać). `NULL` znaczy: dodała to
osoba, której konto zostało usunięte (`ZerwijWspoldzielenie::przyWymazaniu()`).

**Blokada** w którąkolwiek stronę kasuje członkostwa między tymi osobami
i odwołuje oczekujące zaproszenia (`BlockUser` → `ZerwijWspoldzielenie::miedzy()`,
pod zamkiem pary kont). **Usunięcie konta** — patrz D-302.

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

Migracja `2026_10_02_190000_add_ulubiony_zeszyt_to_users`.

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
