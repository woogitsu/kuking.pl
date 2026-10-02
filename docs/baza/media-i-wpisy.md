# Media i wpisy

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### media
Tylko metadata, nie binary:
- `owner_id uuid NOT NULL` → `users` (`ON DELETE CASCADE`) — właściciel
  pliku. To on, a nie wpis czy przepis, decyduje o dostępie do zdjęcia
  (`DostepDoZdjecia`);
- `disk` (ORYGINAŁ — patrz niżej);
- `variants_disk` (PUBLICZNE WARIANTY — patrz niżej);
- **`media.object_key varchar(700) UNIQUE`** — ścieżka pliku w buckecie.
  **Generujemy ją sami**; nazwa pliku od człowieka nigdy do niej nie trafia
  (`StoreUploadedImage`) — to zamyka drogę do path traversal i do plików
  udających skrypty. `UNIQUE`, bo dwa wiersze wskazujące ten sam obiekt
  znaczyłyby, że skasowanie jednego zdjęcia zabiera plik drugiemu;
- MIME;
- bytes;
- `width integer NULL` i `height integer NULL` — wymiary **oryginału**
  w pikselach, odczytane z ZAWARTOŚCI pliku przez `getimagesize()`
  w `StoreUploadedImage`, tak samo jak `mime_type`. Wymiary poszczególnych
  wariantów (`thumb`, `feed`, `large`) leżą osobno, w `metadata.variants` —
  `Media::width()` bierze najpierw wariant, a do tych dwóch kolumn schodzi
  dopiero, gdy wariantu nie ma. `NULL` to wiersz z fabryki albo z seedera;
- status;
- checksum;
- metadata.

Dwie z tych kolumn nie mówią o sobie samą nazwą:

- **`mime_type varchar(120) NULL`** — typ pliku **odczytany z jego zawartości**
  przez `getimagesize()` w `StoreUploadedImage`, a NIE nagłówek `Content-Type`
  przysłany przez przeglądarkę: tamten deklaruje nadawca, a plik udający
  obrazek deklaruje cokolwiek. Wpisywany razem z wierszem, więc `NULL` na
  produkcji się nie zdarza — kolumna dopuszcza go dla wierszy z fabryk
  i seederów.
- **`alt_text varchar(500) NULL`** — opis alternatywny, **wolny tekst od
  człowieka**. Dla dostępności bezcenny, ale **nigdy wymagany**: wymóg opisu
  zabiłby publikację „zdjęcie + kilka słów", czyli główną akcję serwisu.
  `NULL` i pusty opis są stanem normalnym, a nie brakiem do uzupełnienia.

**Kolumny `media.perceptual_hash` JUŻ NIE MA** (migracja
`2026_09_12_100000_usun_martwa_kolumne_perceptual_hash`). Było to miejsce na
skrót percepcyjny obrazu — wartość rozpoznającą to samo zdjęcie mimo innej
kompresji, przydatną moderacji przy zdjęciu wstawianym ponownie po decyzji.
Coś innego niż `checksum_sha256`, który jest dokładnym skrótem bajtów i łapie
wyłącznie identyczny plik.

Kolumna stała w schemacie od pierwszej migracji mediów i **przez cały ten czas
nic jej nie wypełniało ani nie czytało**: jedynym wystąpieniem w kodzie był
`$fillable` modelu `Media`, a każdy wiersz miał `NULL`. Pusta kolumna nie jest
darmowa — czytający schemat widzi pole, które wygląda na działający mechanizm
wykrywania duplikatów, i planuje na nim pracę (tak stało się dwa razy
w `docs/legal/MODERATION_PLAYBOOK.md`). Usunięcie jest wykonaniem zauważenia
z D-166.

**Jeśli wykrywanie duplikatów zdjęć kiedyś powstanie**, kolumna wróci razem
z kodem, który ją liczy — a nie przed nim. Skrót percepcyjny jest wartością
WYLICZANĄ z pliku, więc odtworzenie go dla istniejących zdjęć jest przeliczeniem,
nie odzyskiwaniem utraconych danych.

**Dwie kolumny dysku, bo to dwie różne kategorie danych** (migracja
`2026_09_06_170000_add_variants_disk_to_media`, audyt G-01).

```sql
ALTER TABLE media ADD COLUMN variants_disk varchar(40);   -- NULL = tam, gdzie oryginał
```

`disk` mówi, gdzie leży ORYGINAŁ — plik dokładnie taki, jaki przyszedł od
człowieka, z pełnym EXIF-em, czyli ze współrzędnymi GPS kuchni. `variants_disk`
mówi, gdzie leżą PRZETWORZONE warianty WebP, z których re-enkodowanie zdjęło
metadane.

Do tej pory obie rzeczy leżały w jednym buckecie R2, a prywatność oryginału
opierała się na zapisaniu go jako „private" pod prefiksem `incoming/`.
**Na R2 to nie działa:** Cloudflare nie implementuje S3-owych ACL na obiektach
(`x-amz-acl` jest oznaczony jako nieobsługiwany dla `PutObject`), a publiczność
jest cechą BUCKETU — własnej domeny albo `r2.dev`. Bucket wystawiony pod
`cdn.kuking.pl` wystawiał więc też `incoming/`. Adres oryginału dawał się przy
tym wyprowadzić z publicznego adresu wariantu:

```text
media/{uuid_wlasciciela}/{rok}/{mc}/{uuid}_feed.webp    ← publiczny, znany
incoming/{uuid_wlasciciela}/{rok}/{mc}/{uuid}.jpg       ← oryginał
```

**`NULL` znaczy „tam, gdzie oryginał"** i tak ma każdy wiersz sprzed tej
migracji — bo tam te warianty naprawdę leżą. Kolumny NIE backfillujemy:
wpisanie nazwy nowego dysku byłoby stwierdzeniem nieprawdy o położeniu plików,
a `KasujZdjecie` szukałoby ich w niewłaściwym buckecie i zostawiało publiczne
kopie na zawsze — także po wymazaniu konta. Przeniesienie starych wariantów to
osobna praca: kopiowanie obiektów plus aktualizacja tej kolumny po każdym
udanym kopiowaniu.

Rollback: `DROP COLUMN`, bezstratnie — wiedza wraca do „ten sam dysk co
oryginał", czyli do stanu sprzed rozdzielenia. **Cofać przed migracją danych,
nie po:** po przeniesieniu wariantów ta kolumna niesie już prawdziwą wiedzę
i jej utrata znaczy, że aplikacja szuka ich w starym buckecie.

**Migracja danych `2026_09_06_180000_point_existing_media_at_legacy_disk`**
(bez zmiany schematu) przestawia stare wiersze z `disk = 'r2'` na
`disk = variants_disk = 'r2_legacy'`. Po niej nazwa `r2` znaczy wyłącznie nowy,
prywatny bucket. **Rollback odmawia (D-088, issue #2329)**, gdy w `media` jest
choć jeden wiersz z `disk = 'r2'` — przeniesiony przez `kuking:przenies-zdjecia`
albo dodany po rozdzieleniu. Cofnięcie zlałoby wtedy stare wiersze z nowymi,
a kolejne `up()` przestawiłoby na `r2_legacy` także te, których plików
w starym buckecie nigdy nie było. Odmowa niczego nie zmienia i mówi, co zrobić
(cofnąć wdrożenie bez tej migracji albo ręcznie, po kopii tabeli
i `kuking:zaleznosc-od-starego-bucketu --pliki`). Bez takich wierszy (pusta
baza, dev/CI na dysku `public`, produkcja przed pierwszym nowym zdjęciem)
rollback przechodzi jak dawniej: `r2_legacy` → `r2`, `variants_disk` → `NULL`,
a ponowne `up()` odtwarza stan w całości. Test:
`tests/Feature/CofniecieMigracjiStaregoBucketuOdmawiaTest.php`.

#### `status = 'deleted'` — kasowanie TRWA, a wiersz jest uchwytem do ponowienia

Wprowadzone przez **D-083** (issue #285, MEDIA-01). **Bez migracji i bez
zmiany schematu:** wartość `deleted` dopuszcza `media_status_check` od
pierwszej migracji tabeli (`2026_09_05_000100_create_media_table`) — do tej
pory po prostu nikt jej nie zapisywał.

```sql
-- stan NIEZMIENIONY, cytowany tu tylko po to, żeby nie trzeba było
-- otwierać migracji, żeby sprawdzić, czy ta wartość jest legalna:
ALTER TABLE media ADD CONSTRAINT media_status_check
    CHECK (status IN ('pending','processing','ready','rejected','deleted'));
```

Znaczenie: **zdjęcie zostało przejęte do skasowania, ale jeszcze nie zniknęło
z dysku.** To nie jest „skasowane" — to jest „kasowanie trwa".

- Znacznik ustawia `KasujZdjecie::przejmij()` w krótkiej transakcji, pod
  `SELECT … FOR UPDATE` na tym wierszu i po ponownym sprawdzeniu, że nic go
  nie używa. Pliki kasują się dopiero **po** commicie tej transakcji.
- Dopóki znacznik stoi, `App\Domain\Media\ZdjeciaDoPrzypiecia` nie pozwoli
  przypiąć tego zdjęcia do wpisu ani do wykonania. Bez tego okno na utratę
  pliku wracałoby zaraz po zwolnieniu blokady, a przed skasowaniem plików.
- Nieudane kasowanie plików **zostawia wiersz ze znacznikiem** — i to jest
  cały mechanizm ponowienia, ten sam co przy issue #17: kolejny przebieg
  `kuking:sprzataj-osierocone-zdjecia` wybiera go po wieku tak samo jak każdy
  inny wiersz. Wiersz bez plików da się zauważyć; pliki bez wiersza są dla
  aplikacji niewidoczne na zawsze.

To jest odpowiednik `users.data_erased_at` z `EraseAccountData`: zatwierdzona
deklaracja „to odchodzi", widoczna dla innych transakcji.

**Rollback:** nie ma czego cofać w schemacie — CHECK się nie zmienił, kolumny
nie przybyło. Cofnięcie SAMEJ ZMIANY KODU (revert PR-a #285) jest bezpieczne
dla danych, ale wymaga jednego ruchu operacyjnego: wiersze, które zostały
z `status = 'deleted'` po nieudanym kasowaniu plików, przestaną cokolwiek
znaczyć dla starego kodu i będą wyglądać jak zwykłe osierocone zdjęcia —
stary sprzątacz podejmie je normalnie, po wieku, więc nie zablokują się
w bazie. Nic nie trzeba backfillować.

### posts + post_media
Najprostszy content społecznościowy.

**Miękkie usunięcie nie jest stanem końcowym (audyt B5 pkt 1, 25.09.2026).**
`posts`, `recipes` i `comments` mają `deleted_at`. Treść usunięta przez autora
leży z `deleted_at` najwyżej `kuking.usuniete_tresci.retention_days` (30) dni;
potem `kuking:sprzataj-usuniete-tresci`
(`App\Domain\Compliance\PrzedawnioneUsunieteTresci`) robi `forceDelete()`
— kaskady zabierają `post_media`, `post_tags`, `collection_items`,
`hero_picks`, komentarze — i kasuje pliki zdjęć przez
`KasujZdjecie::jesliNieuzywane()`. Dwa wyjątki:

- treść, na którą (albo na której komentarz, zdjęcie, wykonanie) wskazuje
  jakikolwiek wiersz `reports` lub `moderation_actions`, czeka — moderacja
  zdejmuje treść tym samym `delete()`, a sprawa potrzebuje celu. Po retencji
  sprawy (36 mies.) treść wraca do kolejki;
- przepis z cudzymi `cooked_events` (FK `ON DELETE CASCADE`) nie jest
  kasowany, tylko opróżniany do **nagrobka**: `title = 'Przepis usunięty'`,
  `slug = 'usuniety-przepis-' || id bez kresek`, kolumny opisu, źródła
  i zdjęć `NULL`, a `recipe_ingredients`, `recipe_steps`, `recipe_versions`,
  `recipe_slug_redirects`, `collection_items` i komentarze przepisu znikają.
  Gdy ostatnie cudze wykonanie zniknie, nagrobek idzie `forceDelete()`.

Bez zmiany schematu — rollback to wyłączenie zadania w `routes/console.php`
(skasowanych wierszy żaden rollback nie przywróci; to jest cel zmiany).


**Rodzaj wpisu i tytuł pytania (#371).** Migracja
`2026_09_18_100000_add_kind_and_title_to_posts` dodaje `kind varchar(20)
NOT NULL DEFAULT 'dish'` i `title varchar(180) NULL` razem z ograniczeniami
`posts_kind_check` i `posts_kind_title_check` w jednym poleceniu ALTER.
Dozwolone są `dish` (tytuł zawsze NULL) oraz `question` (tytuł nie-NULL,
10–180 znaków po usunięciu brzegowych spacji, tabulatorów, LF, CR i VT).
To zestaw PHP trim poza NUL, którego PostgreSQL nie dopuszcza w tekście.
Długość liczymy w znakach, także polskich, nie w bajtach; varchar(180)
ogranicza również surowy tytuł przed trim. Nie normalizujemy środka tytułu.

**`kind` i `title` SĄ w `Post::$fillable`** — i to `posts_kind_title_check`
jest powodem, dla którego wolno je tam trzymać. Zakaz z AGENTS.md §7 dotyczy
kolumn niosących STAN KONTA albo uprawnienie (`users.status`, `users.role`,
`users.email`); `kind` i `title` niosą treść wpisu, a jedyny sposób, w jaki
hurtowe przypisanie mogłoby tu zaszkodzić — pytanie bez tytułu albo danie
z tytułem — baza odrzuca sama, na każdej drodze zapisu. To jest ogólna
zasada, nie wyjątek dla tej jednej tabeli: **kolumna wolno-przypisywalna
hurtem to taka, której wszystkie dopuszczalne kombinacje z innymi kolumnami
pilnuje ograniczenie w bazie.** Powiązania między kolumnami, które o tym
decydują, są w tym dokumencie wypisane przy każdej tabeli z osobna —
`comments_single_target_check`, `collection_items_single_target_check`,
`appeals_appellant_identity_check`, `users_data_erased_at_check`,
`users_status_expires_at_check`, `recipe_ingredients_no_amount_check`,
`tags_merged_consistency_check`, trzy CHECK-i celu w `reports` i komplety
„rozpatrzone/obsłużone" w `appeals`, `contact_messages`
i `contact_message_replies`.

Dotychczasowe wpisy otrzymują `dish` i NULL bez zmiany treści, widoczności,
relacji ani `recipe_id`. Nie ma wariantu `kind=recipe` ani drugiej tabeli.
Konfiguracja `kuking.questions.enabled` (`KUKING_QUESTIONS_ENABLED`, domyślnie
false) przygotowuje kolejny etap #372; sama nie filtruje istniejących feedów
ani ręcznie zapisanych pytań. Ten etap nie dodaje ścieżki HTTP tworzenia pytań.

**`kind` NIE JEST W `$fillable` MODELU `Post`.** To pole STERUJĄCE — decyduje
o strumieniach (`scopeEnabledKinds`), o adresie wpisu (`url()`) i o tym, co
przepuści `PostPolicy` — czyli ta sama rodzina co `users.status` i `users.role`
z AGENTS.md §7. Jedyna droga to nazwana metoda `Post::oznaczJakoPytanie()`,
tak jak `ContactMessage::oznaczJako()` dla stanu obsługi wiadomości. `title`
w `$fillable` ZOSTAJE: to treść pisana przez autora, a sam z siebie nie otwiera
furtki, bo `posts_kind_title_check` nie przyjmie tytułu przy daniu. Pilnuje
tego `tests/Feature/RodzajWpisuPozaMasowymPrzypisaniemTest.php` — sprawdzając
zawartość wiersza, a nie zawartość tablicy `$fillable`.

**Rollback:** przy braku pytań `down()` usuwa oba CHECK-i i nowe kolumny,
zachowując stare wpisy. Jeśli istnieje choć jedno pytanie, również ukryte
lub miękko usunięte, odmawia przed DDL. Wtedy wycofujemy kod, pozostawiając
rozszerzony schemat; nie usuwamy pytań w celu przepchnięcia rollbacku.
Sprawdzenie i DDL są objęte transakcją oraz blokadą tabeli, aby równoległy
zapis nie wszedł pomiędzy sprawdzenie a usunięcie kolumn.

**`posts.recipe_id` — wpis WSKAZUJĄCY przepis** (issue #368). Kolumna istnieje
od pierwszej migracji (`2026_09_05_000500_create_posts_tables`, `nullable`,
`nullOnDelete`) i **nie zmienia się tą pracą ani o jeden bajt** — zmienia się
to, kto ją wypełnia i co z niej wynika. Nie ma tu migracji, bo nie ma zmiany
schematu.
Indeks `posts_recipe_idx (recipe_id) WHERE recipe_id IS NOT NULL` doszedł
później, osobną migracją (`2026_09_25_100000_…`, rozdział „Indeksy kluczy
obcych na gorących ścieżkach” niżej). Świadomie **nie** `UNIQUE`: `PublishPost`
też ustawia `recipe_id`, więc jeden przepis może mieć wiele wpisów.

Od issue #368 publikacja przepisu tworzy dokładnie JEDEN wiersz `posts`
z `recipe_id` wskazującym przepis, `body = null` i bez ani jednego wiersza
w `post_media` (`App\Domain\Recipes\WpisWskazujacyPrzepis`). Bez tego
opublikowany przepis nie trafiał do żadnego strumienia — wszystkie trzy pytają
wyłącznie o `posts`.

**Ten wiersz niczego z przepisu nie kopiuje.** Tytuł, zdjęcie i widoczność
karta i zapytania biorą z relacji, a nie z kolumn wpisu:

- tytuł i zdjęcie — `resources/views/components/post-card.blade.php`
  z `$post->recipe` i `$post->recipe->heroMedia`;
- widoczność — `Post::scopeZWidocznymPrzepisem()`, czyli
  `Recipe::scopeWidoczneDla()` na wskazywanym przepisie.

Dlatego usunięcie przepisu (także miękkie), ukrycie go przez moderację,
zawężenie widoczności i zmiana tytułu **nie wymagają ani jednego zapisu
na `posts`**. Wiersz zostaje w bazie nietknięty i po prostu przestaje
wychodzić ze strumieni. Kopiowanie tych czterech rzeczy na wpis dałoby cztery
niezależne miejsca do rozjechania się.

`posts.visibility` takiego wiersza to zawsze `'public'` i **nie jest to kopia
widoczności przepisu**, tylko brak własnego zawężenia: wpis nie niesie treści,
której miałby strzec.

**Brak `UNIQUE (recipe_id)` jest świadomy.** Jeden wpis na przepis pilnuje
bramka w transakcji publikacji, idąca po blokadzie wiersza `recipes`. Twardy
indeks unikalny zabroniłby czegoś, co jest dozwolone i pożądane osobno: wpisu
„ugotowałem z tego przepisu", który TEŻ niesie `recipe_id` i ma własne zdjęcie
(patrz `cooked_events` i `PublishPost`). Ograniczenie w bazie musiałoby
odróżniać te dwa rodzaje wierszy, a do tego potrzebna byłaby kolumna, której
świadomie nie dodajemy.

**Przepisy opublikowane przed tą zmianą** uzupełnia komenda
`kuking:dopisz-wpisy-przepisow` (idempotentna, z `--na-sucho`) — nie migracja,
bo to zmiana danych, nie schematu.

**Rollback:** brak migracji do cofnięcia. Wycofanie zachowania to usunięcie
wywołania `WpisWskazujacyPrzepis::dopisz()` z `PublishRecipe`; wiersze, które
już powstały, kasuje się wtedy ręcznie
(`delete from posts where recipe_id is not null and body is null` — z uwagą, że
wpisy „ugotowałem" mają `body` albo zdjęcia, a te są cudzą treścią i zostają).

**`posts.display_mode` — jak autor chce pokazać kilka zdjęć** (issue #92,
migracja `2026_09_06_120000_add_display_mode_to_posts`).

```sql
ALTER TABLE posts ADD COLUMN display_mode varchar(20) NOT NULL DEFAULT 'normal';
ALTER TABLE posts ADD CONSTRAINT posts_display_mode_check
    CHECK (display_mode IN ('normal','carousel','collage'));
```

- `normal` — zdjęcia jedno pod drugim (dotychczasowy i domyślny układ);
- `carousel` — jedno zdjęcie naraz, przewijane w bok;
- `collage` — siatka na jednym ekranie.

CHECK jest w BAZIE, nie tylko w PHP: widok umie narysować dokładnie te trzy
warianty, więc czwarty nie ma prawa się tam znaleźć żadną drogą — ani przez
formularz, ani przez `php artisan tinker`, ani przez przyszłe API.

Wartość domyślna wypełnia wszystkie istniejące wiersze bez migracji danych
i bez przepisywania tabeli (PostgreSQL trzyma `DEFAULT` w katalogu). Wpis
zapisany przed tą zmianą wyświetla się dokładnie jak dotąd.

**Kolumna nie zastępuje liczby zdjęć.** Przy jednym zdjęciu wszystkie trzy
tryby dają ten sam widok, więc `PublishPost` i `ArrangePostMedia` zapisują
wtedy `normal`, a `Post::trybWyswietlaniaZdjec()` i tak liczy tryb na nowo
przy renderowaniu — wpis może stracić zdjęcia (moderacja) długo po wyborze
autora.

**Rollback:** `php artisan migrate:rollback --step=1`. `down()` zdejmuje CHECK
i kasuje kolumnę; traci się wyłącznie wybór autora (wszystko wraca do układu
„zwykle"). Żadne zdjęcie, żaden wpis ani żadna pozycja w `post_media` nie
ginie, więc cofnięcie jest bezpieczne także na produkcji w trakcie awarii.

**Kolejność zdjęć zmienia `post_media.position`**, a nie kolejność wierszy.
Zamiana dwóch zdjęć miejscami przechodziłaby przez stan łamiący
`UNIQUE (post_id, position)`, więc `ArrangePostMedia` robi to w dwóch
przebiegach w jednej transakcji: najpierw odsuwa wszystkie pozycje w zakres
100+, potem ustawia docelowe `0, 1, 2…`. Wartości pośrednie są dodatnie,
więc `CHECK (position >= 0)` obowiązuje przez cały czas.


**`klucz_wyslania` — jedno wysłanie formularza to jeden wiersz** (D-027,
migracja `2026_09_07_900200_add_klucz_wyslania_to_posts`).

```sql
ALTER TABLE posts ADD COLUMN klucz_wyslania uuid NULL;
CREATE UNIQUE INDEX posts_one_per_klucz_wyslania
    ON posts (author_id, klucz_wyslania)
    WHERE klucz_wyslania IS NOT NULL;
```

Klucz jest w indeksie razem z `author_id`, nie sam: klucz wygenerowany
w cudzej przeglądarce nie ma prawa wskazywać na wpis innej osoby, a przy
kolizji kontroler odsyła człowieka do **jego** pierwszego wpisu. Bez
`author_id` byłoby to odesłanie pod cudzy adres.

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

**Rollback:** `DROP INDEX IF EXISTS posts_one_per_klucz_wyslania`, potem `DROP COLUMN
klucz_wyslania`. Bezstratnie i dlatego `down()` niczego nie odmawia: kolumna
niesie wyłącznie identyfikator wysłania wygenerowany przez serwer, ani jednego
słowa napisanego przez człowieka.

### zalegle_czyszczenia_cdn

Adresy skasowanych zdjęć, których cache CDN **jeszcze nie wyczyszczono**,
migracja `2026_09_24_100000_utworz_zalegle_czyszczenia_cdn` (issue #959).
Do niej `PurgePublicMediaCache` bez `CLOUDFLARE_ZONE_ID` albo
`CLOUDFLARE_PURGE_TOKEN` kończył się sukcesem z samym ostrzeżeniem w logu,
a po uzupełnieniu zmiennych nikt nie wiedział, co dokończyć.

| Kolumna | Opis |
|---|---|
| `id bigserial` | Kolejność odkładania — `kuking:wyczysc-zalegle-cdn` bierze najstarsze. |
| `adres varchar(2048) NOT NULL` | Pełny publiczny adres wariantu. **UNIKALNY** (`zalegle_czyszczenia_cdn_adres_unique`): czyszczenie jest idempotentne, drugie odłożenie nic nie dodaje (`insertOrIgnore`). CHECK `zalegle_czyszczenia_cdn_adres_http_check`: `adres ~ '^https?://'` — adresu względnego Cloudflare nie wyczyści nigdy. |
| `created_at timestamptz NOT NULL DEFAULT now()` | Kiedy odłożono. |

Kto pisze: zadanie na **produkcji** bez konfiguracji oraz `failed()` po
wyczerpaniu prób (wszędzie). Kto kasuje: wyłącznie
`ZalegleCzyszczeniaCdn::wyczysc()` — **po** potwierdzeniu Cloudflare
(`success: true` dla każdej partii). Porażka zostawia wiersze na następny
przebieg (co kwadrans). `/health` → `cdn_zalegle` = `czyszczenie_cdn_zalegle`,
dopóki tabela nie jest pusta.

**Rollback odmawia przy niepustej tabeli (D-088):** każdy wiersz to zdjęcie,
które może się jeszcze otwierać z cache — często po wymazaniu konta albo
decyzji moderacyjnej. Najpierw uzupełnij konfigurację i uruchom
`php artisan kuking:wyczysc-zalegle-cdn`, potem wycofuj. Pusta tabela znika
bez pytań. **Kolejność wycofywania: NAJPIERW KOD, POTEM MIGRACJA** — kod
z tej zmiany odkłada adresy do tej tabeli, a `/health` ją liczy. Pilnuje tego
`tests/Feature/ZalegleCzyszczenieCdnTest.php`.
