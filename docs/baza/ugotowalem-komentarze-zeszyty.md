# Ugotowałem, komentarze i zapisy

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### collection_items — przepisy ORAZ wpisy

**Ponowienie przeniesienia (#2809).** Gdy zapis jest już w zeszycie docelowym,
odpowiedź nie zmienia relacji, dopisku, daty ani dodającej osoby. Tytuł treści
trafia do komunikatu tylko po ponownej kontroli Policy na świeżym stanie pod
blokadami. Przy utracie dostępu komunikat neutralnie potwierdza stan własnego
zapisu, bez bieżącego tytułu. Zapis pozostaje; przywrócenie dostępu pozwala
znów otworzyć treść. Schemat i kolejność blokad pozostają bez zmian.

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
- **`dzien_gotowania date NULL`** (CHECK `dzien_gotowania IS NULL OR
  dzien_gotowania >= DATE '2000-01-01'`, migracja
  `2026_10_02_230000_add_dzien_gotowania_to_cooked_events`, #2583, decyzja
  właściciela z 2.10.2026) — **prywatny** dzień kalendarzowy, który kucharz
  świadomie podał przy „Ugotowałem”, gdy zgłasza później niż gotował. Osobny od
  `cooked_at` (chwila zgłoszenia, serwerowa), który zostaje nietknięty: na nim
  stoją feed, digest, WAC, kohorty i „Ugotujmy razem”. Typ `date`, nie
  `timestamptz` — bez godziny i bez strefy, więc konwersja nie przesuwa dnia.
  `NULL` = nie podano (domyślnie; wykonania sprzed migracji bez backfillu z
  `cooked_at`, bo to byłoby zmyślone). Górną granicę (nie z przyszłości wg
  `Europe/Warsaw`) pilnuje `App\Domain\Recipes\Gotowanie\DzienGotowania`
  w formularzu i w `RecordCookedEvent`; CHECK nie może zależeć od `now()`.
  Poza `$fillable` (ustawia go wyłącznie `RecordCookedEvent`). Widzi go tylko
  kucharz (karta wykonania, gdy `auth()->id() === user_id`); publiczna karta,
  profil, autor przepisu, powiadomienie, API i SEO go nie niosą. Eksport
  danych konta: `ugotowalem[].dzien_gotowania_podany_przeze_mnie`. Wymazanie
  konta zeruje kolumnę także przy zakresie `minimum` (wykonanie zostaje).
  **Rollback:** `down()` odmawia, gdy choć jedno wykonanie ma zapisany dzień
  (D-088 — to deklaracja człowieka, której kolejny `migrate` nie odtworzy, a
  odtworzenie z `cooked_at` byłoby nieprawdą); inaczej zdejmuje CHECK i
  kolumnę. Test: `tests/Feature/PrywatnyDzienGotowaniaTest.php`.
- **`photos_added_at timestamptz NULL`** (migracja
  `2026_10_07_120000_add_photos_added_at_to_cooked_events`, #2500, decyzja
  właściciela z 2.10.2026, D-333 — paczka E) — chwila, w której kucharz
  **dołączył zdjęcie do już zapisanego wykonania**. Publiczna karta pokazuje ją
  jako „Zdjęcie uzupełnione …”; `cooked_at`, przypięta wersja, notatka i
  komentarze zostają bez zmian. `NULL` = zdjęcia nie dołączano później (także
  wykonania sprzed migracji, bez backfillu). Poza `$fillable`; ustawia ją
  wyłącznie `DolaczZdjeciaDoWykonania` (zapytaniem po kluczu, bez zdarzeń
  modelu). Akcja nie tworzy wykonania, `TYPE_COOKED`, celebracji ani analityki
  publikacji; dokłada wiersze `cooked_event_media` (kolejne pozycje, istniejące
  nietknięte, klucz główny (wykonanie, zdjęcie) = ponowienie nie dubluje), pod
  blokadą `media` → `users` → `cooked_events` i z ponowną oceną
  `CookedEventPolicy::addPhotos` na świeżym stanie (kucharz, konto aktywne,
  okno `kuking.wykonania.dolaczenie_zdjec_dni` = 7, przepis dostępny wg
  `RecipePolicy::cook`). Limit `kuking.media.max_per_post` liczy zdjęcia już
  przypięte i nowe razem. Eksport: `ugotowalem[].zdjecie_uzupelnione`.
  **Rollback:** `down()` odmawia, gdy choć jedno wykonanie ma znacznik (D-088 —
  uzupełnione zdjęcie wyglądałoby jak oryginalne). Test:
  `tests/Feature/DolaczenieZdjeciaDoWykonaniaTest.php`.

Ponowienie multipart dołączenia zdjęcia (#2811) niesie osobny UUID
`klucz_wyslania`. Udane klucze są w `cooked_events.photo_submission_keys`
(JSONB z bazowym CHECK typu tablicy, domyślnie `[]`, poza masowym przypisaniem).
Klucz jest przypięty do konkretnego wykonania i zostaje po usunięciu zdjęcia;
historia udanych UUID nie jest obcinana do limitu bieżących zdjęć, bo siódme
wysłanie po usunięciu jednego zdjęcia musi działać, a starsze ponowienie nadal
nie może utworzyć duplikatu. UUID sprawdza formularz przed akcją; klucze
pozostają prywatnym śladem wykonania i nie są renderowane ani eksportowane.
Przy wymazaniu konta z zachowaniem wykonania historia tych kluczy jest
czyszczona: wymazane konto nie może ponowić żądania, a prywatny ślad nie jest
potrzebny do zachowania publicznego wykonania.
Ponowienie nie oznacza nowego gotowania ani nowego powiadomienia. Przed zapisem pliku
sesyjna blokada PostgreSQL serializuje ten sam klucz, potem dotychczasowa
transakcja zachowuje kolejność media → users → cooked_events i ponownie
sprawdza prawo. Starszy klient bez klucza zachowuje dawną drogę, a formularz
WWW zawsze go wysyła. Rollback kolumny odmawia, jeśli zapisano choć jeden
klucz: utrata historii pozwoliłaby ponowieniu utworzyć nowe zdjęcie.
Migracja dodaje CHECK jako `NOT VALID`, następnie osobno go waliduje,
poza jedną transakcją (AGENTS.md §6). Przerwane DDL można ponowić;
istniejąca kolumna lub ograniczenie nie przerywa kolejnej próby.
- **`faktyczne_porcje numeric(5,2) NULL`** (CHECK `faktyczne_porcje IS NULL OR
  (faktyczne_porcje >= 0.5 AND faktyczne_porcje <= 100)`, migracja
  `2026_10_03_180000_add_faktyczne_porcje_to_cooked_events`, #2540, decyzja
  właściciela z 2.10.2026) — **prywatna** liczba porcji, którą kucharz
  świadomie podał o TEJ próbie („przepis na 4, ugotowano 8”). `numeric`, nie
  `float`: „2,5” i „0,75” wracają dokładnie; dwa miejsca po przecinku to jawna
  precyzja, zakres 0,5–100 pilnuje CHECK i `App\Domain\Recipes\Gotowanie\PorcjeWykonania`.
  `NULL` = nie podano (domyślnie; wykonania sprzed migracji bez backfillu,
  bo liczba porcji przepisu nie jest dowodem, ile ugotowano). Nic jej nie
  wylicza z przepisu, adresu `?porcje=`, zapamiętanego wyboru ani Planera.
  Poza `$fillable`; ustawiają ją wyłącznie `RecordCookedEvent` (przy zapisie)
  i `PoprawPorcjeWykonania` (poprawa/usunięcie przy istniejącym wykonaniu —
  zapytanie po kluczu, bez nowego wykonania, powiadomienia ani ruszania
  `cooked_at`). Widzi ją tylko kucharz (karta wykonania gdy `auth()->id() ===
  user_id`; poprawa: `CookedEventPolicy::poprawPorcje`); publiczna karta, profil,
  autor przepisu, powiadomienie, API i SEO jej nie niosą. Eksport danych konta:
  `ugotowalem[].faktyczne_porcje_podane_przeze_mnie`. Wymazanie konta zeruje
  kolumnę także przy zakresie `minimum` (wykonanie zostaje). **Rollback:**
  `down()` odmawia, gdy choć jedno wykonanie ma zapisaną liczbę (D-088 —
  deklaracja człowieka, której kolejny `migrate` nie odtworzy); inaczej zdejmuje
  CHECK i kolumnę. Test: `tests/Feature/PrywatneFaktycznePorcjeTest.php`.
- **`poprawiono_at timestamptz NULL`** (migracja
  `2026_10_05_200000_add_poprawiono_at_to_cooked_events`, #2459, decyzja
  właściciela z 2.10.2026) — ślad korekty własnej uwagi (`note`), opisu zmian
  (`changes_note`) albo czasu (`actual_minutes`). `NULL` = wykonanie nie było
  poprawiane (także wszystkie wiersze sprzed migracji, bez backfillu). Tabela
  nie ma `updated_at`, więc to jedyny nowy ślad; **poprzednia treść nie jest
  nigdzie przechowywana**. Ustawia go wyłącznie `PoprawWykonanie` (poza
  `$fillable`), tylko gdy któreś z trzech pól faktycznie się zmieniło. Widać go
  na karcie jako „Poprawiono <data>”; eksport danych konta: `ugotowalem[].poprawiono`.
  Konflikt starej karty nie używa tej kolumny, tylko odcisk trzech pól
  (`CookedEvent::wersjaPolKorekty()`) porównywany pod `FOR NO KEY UPDATE`.
  Bez CHECK i indeksu (`ADD COLUMN … NULL` zmienia tylko katalog).
  **Rollback:** `down()` odmawia, gdy choć jedno wykonanie ma ślad korekty
  (D-088 — po cofnięciu poprawiony tekst wyglądałby jak pierwotny); inaczej
  zdejmuje kolumnę. Test: `tests/Feature/KorektaWykonaniaTest.php`.
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
