# ADR — Idempotencja formularzy: `PublishPost`, `RecordCookedEvent`, `ReportContent`

**Status: PROPOZYCJA DO ZATWIERDZENIA. Nic z tego dokumentu nie jest wdrożone.**
Data: 7 września 2026 · Dotyczy: audytu wyścigów z 7 września 2026
(`[issue do nadania]`) · Autor: agent badawczy (Claude), na zlecenie właściciela ·
Stan repozytorium zmierzony na `HEAD` gałęzi `claude/kuking-development-muukrs`
z 7 września 2026.

Ten dokument **nie zmienia kodu, migracji, widoków ani konfiguracji**. Nie
dopisuje też pozycji do `docs/DECISIONS.md` — decyzja nie została podjęta,
a gotowa treść wpisu czeka w §10 z nienadanym numerem.

Każde twierdzenie o stanie systemu ma cytat `plik:linia`. Każdy z trzech
przypadków ma **własny pomiar**: co uruchomiłem i co wyszło. Tam, gdzie opis
z zadania okazał się nieścisły, piszę to wprost — bo naprawa fantomu jest
gorsza niż brak naprawy.

**Środowisko pomiaru:** baza `kuking_test_idempotencja` (PostgreSQL,
`kuking_test_idempotencja`, izolacja `read committed` — zmierzone, §1.4.2),
zmigrowana z `database/migrations/`, usunięta po pomiarze. Testy pomiarowe
chodziły z `DB_DATABASE=kuking_test_idempotencja php artisan test
--filter=PomiarIdempotencjiFormularzyTest`; pomiary wielopołączeniowe —
osobnymi skryptami na dwóch niezależnych połączeniach PDO. Plik z testami
pomiarowymi **nie zostaje w repozytorium** — uzasadnienie w §9.

---

## 0. Dlaczego to jest ADR, a nie poprawka w kodzie

Trzy błędy zmierzone poniżej mają jeden wspólny mianownik: **nie da się ich
naprawić, nie rozstrzygając wcześniej, czym w tym produkcie jest „jedno
wysłanie formularza"**. `docs/DECISIONS.md` nie ma dziś ani jednej pozycji na
ten temat (`grep -rn "idempot" docs/DECISIONS.md` — pusty wynik), a
`AGENTS.md` (część „Claude-specific" w `CLAUDE.md` i §4 kontraktu) zakazuje
wymyślania architektury po cichu w kodzie implementacji.

Repozytorium ma już **trzy różne, niezależnie wymyślone** odpowiedzi na to
pytanie i żadna nie jest regułą ogólną:

| Miejsce | Mechanizm | Cytat |
|---|---|---|
| „Zapisuję" do zeszytu | klucz główny `collection_items (collection_id, recipe_id)` + `syncWithoutDetaching` bez nadpisywania `created_at` | `tests/Feature/IdempotentnyZapisDoZeszytuTest.php:13-31` |
| „X Cię obserwuje" | okno czasowe na PARZE osób, jawna lista typów | `app/Domain/Notifications/Actions/NotifyUser.php:45-47,92-106` |
| jedna decyzja na zgłoszenie | częściowy indeks UNIQUE w bazie + `lockForUpdate()` na wierszu RODZICA | `database/migrations/2026_09_06_190000_one_decision_per_report.php:61-63` |

Czwarty przypadek — trzy z tego dokumentu — nie ma odpowiedzi żadnej. To jest
dokładnie ta sytuacja, w której `AGENTS.md` każe napisać ADR, a nie wybrać
wzorzec w implementacji.

---

## 1. Stan faktyczny zmierzony w kodzie

### 1.1 Przypadek 1 — `PublishPost`: dwa wywołania, dwa wpisy

**Ścieżka:** `app/Domain/Posts/Actions/PublishPost.php`
(`App\Domain\Posts\Actions\PublishPost`), wołana z
`app/Http/Controllers/PostController.php:36,85` na trasie `posts.store`
(`routes/web.php:256-258`).

**Kod:** `handle()` sprawdza dokładnie dwie rzeczy przed zapisem — czy wpis
nie jest pusty (linie 51-53) i czy zdjęcia należą do tej osoby (57-61).
Potem `Post::create([...])` w transakcji (linie 88-97). **Nie ma ani
`firstOrCreate`, ani `SELECT` szukającego wcześniejszego wpisu, ani żadnego
klucza wysłania.** Transakcja z linii 88 chroni spójność wpisu z jego
zdjęciami i tagami — nie chroni przed drugim wpisem, bo drugie wywołanie
otwiera własną transakcję.

**Pomiar (przez akcję domenową):**

```text
POMIAR 1 · PublishPost dwa razy z identyczną treścią
  wiersze w `posts`: 2
  identyczne id?     NIE
  id 1: 01a07cc1-cc71-72a8-ade5-558a94723b6f
  id 2: 01a07cc1-cc76-705b-855d-4dffeab11531
  wpisy w audit_log `post.published`: 2
```

**Pomiar (przez HTTP, czyli prawdziwe podwójne kliknięcie):**

```text
POMIAR 1c · dwa razy POST /dodaj/zdjecie z identycznym ciałem
  status 1: 302 -> http://localhost:8000/wpisy/01a07cc1-cdfe-7196-a765-2ded4e25e3dc
  status 2: 302 -> http://localhost:8000/wpisy/01a07cc1-ce0a-707f-816c-f3f93c58bdd2
  wiersze w `posts`: 2
  limit `post` z configu: 20,10
```

Dwa różne adresy w `Location` to najkrótszy dowód: serwis odesłał człowieka
do DRUGIEGO wpisu, o którego istnieniu ten człowiek nie wie. Limit zapytań
nie ma z tym nic wspólnego — `'post' => '20,10'`
(`config/kuking.php:445`) to dwadzieścia wysyłek na dziesięć minut, a
podwójne kliknięcie mieści się w tym z zapasem na osiemnaście kolejnych.

**Pomiar oporu bazy:**

```text
POMIAR 1b · indeksy na `posts`
  CREATE UNIQUE INDEX posts_pkey ON public.posts USING btree (id)
  CREATE INDEX posts_author_published_idx ON public.posts USING btree (author_id, published_at DESC, id DESC) WHERE (deleted_at IS NULL)
  CREATE INDEX posts_published_idx ON public.posts USING btree (published_at DESC, id DESC) WHERE ((deleted_at IS NULL) AND (status = 'published') AND (visibility = 'public'))
  indeksów UNIQUE poza kluczem głównym: 0
```

Zgodne z migracją (`database/migrations/2026_09_05_000500_create_posts_tables.php:23-48`):
trzy CHECK-i na słowniki wartości, dwa indeksy częściowe pod feed, zero
unikalności poza `id`.

**Dotkliwość: najwyższa z trzech.**

1. Dotyczy **głównej akcji produktu** — `AGENTS.md` §1 nazywa ją wprost
   („Co dziś ugotowałeś? → zdjęcie + kilka słów → Opublikuj").
2. Dotyczy zachowania **typowego, nie brzegowego**, w głównej grupie
   odbiorców. Repozytorium samo to już raz zapisało, przy innym przycisku:
   „Podwójne kliknięcie nie jest w grupie 50+ pomyłką, tylko sposobem obsługi
   komputera: strona myśli chwilę, więc klika się drugi raz. Przycisk, który
   przy drugim kliknięciu robi coś innego niż przy pierwszym, jest usterką"
   (`tests/Feature/IdempotentnyZapisDoZeszytuTest.php:16-19`, issue #43).
3. Skutek jest **widoczny publicznie i trwały**: dwa wpisy w feedzie
   obserwujących i w archiwum profilu.
4. Naprawa jest wykonalna przez człowieka — trasa `posts.destroy` istnieje
   (`routes/web.php:269`) — ale **przenosi pracę na osobę, która najmniej ma
   jak ją wykonać**, i wymaga od niej zauważenia, że wpisy są dwa.

**Odnotowany szczegół, który zmienia postać duplikatu (nie jest błędem sam
w sobie).** Duplikat nie musi być identyczny. `PostController::zebranZdjecia()`
odzyskuje `media_ids` z ukrytych pól tylko dla zdjęć jeszcze niepodpiętych do
żadnego wpisu (`whereDoesntHave('posts')`, `app/Http/Controllers/PostController.php:357-362`).
Przy powrocie „wstecz" i ponownym kliknięciu przeglądarka nie odtwarza pola
`<input type="file">`, a ukryte `media_ids` wskazują już zajęte zdjęcia —
więc **drugi wpis powstaje bez zdjęcia**. Przy natywnym „wysłać ponownie?"
przeglądarki plik idzie w żądaniu jeszcze raz i drugi wpis dostaje **własną,
nową kopię zdjęcia**. Obie postaci trzeba złapać jednym mechanizmem; §1.4.1
pokazuje, dlaczego to wyklucza jeden z wariantów.

**Nie zmierzyłem, a warto odnotować:** licznik „czy to pierwszy wpis"
(`PublishPost.php:145-153`) liczy `limit(2)->count()` PO zatwierdzeniu
transakcji. Przy sekwencyjnym podwójnym kliknięciu drugie wywołanie widzi
`2` i powiadomienia gospodarza nie dubluje. Przy **prawdziwej
współbieżności** oba wywołania mogą policzyć `2` i wtedy gospodarz nie
dostanie powiadomienia **wcale** — czyli błąd odwrotny do zdublowanego.
To jest wniosek z czytania kodu, **nie pomiar**, i tak go tu zapisuję.

### 1.2 Przypadek 2 — `RecordCookedEvent`: dwa zdarzenia i dwa powiadomienia

**Ścieżka:** `app/Domain/Recipes/Actions/RecordCookedEvent.php`
(`App\Domain\Recipes\Actions\RecordCookedEvent`), wołana z
`app/Http/Controllers/CookedEventController.php:42` na trasie `cooked.store`
(`routes/web.php:314-316`).

**Kod:** trzy bramki przed zapisem — przepis opublikowany (linie 45-47),
brak blokady między osobami (49-51), zdjęcia własne (53-57). Potem
`CookedEvent::create([...])` w transakcji (59-71). Powiadomienie autora idzie
**poza transakcją, zawsze** (87-98) — i to jest zamierzone: komentarz klasy
mówi „powiadomienie autora jest OBOWIĄZKOWĄ częścią tej operacji, nie
dodatkiem" (linia 23).

**Pomiar (przez akcję domenową):**

```text
POMIAR 2 · RecordCookedEvent dwa razy, ta sama treść, ta sama sekunda
  wiersze w `cooked_events`: 2
  identyczne id?             NIE
  powiadomienia TYPE_COOKED do autora: 2
  okno_powtorzenia_godzin w configu: 24
```

**Pomiar (przez HTTP):**

```text
POMIAR 2c · dwa razy POST /przepisy/{slug}/ugotowalem
  wiersze w `cooked_events`: 2
  powiadomienia do autora:   2
```

**Zadanie kazało sprawdzić, czy `okno_powtorzenia_godzin` to nie jest ten sam
problem rozwiązany raz. NIE JEST — i to jest rozstrzygnięcie, nie domysł.**

`NotifyUser` ma okno wyciszania, ale obejmuje ono **jawną, wąską listę
typów**: `TYPY_WYCISZANE_W_OKNIE = [Notification::TYPE_FOLLOW]`
(`app/Domain/Notifications/Actions/NotifyUser.php:45-47`). `juzBylo()`
zwraca `false` natychmiast dla każdego typu spoza listy (linia 94), więc
`TYPE_COOKED` nie przechodzi przez okno w ogóle. Zmierzone:

```text
POMIAR 2b · zasięg `okno_powtorzenia_godzin`
  dwa razy TYPE_FOLLOW  -> powiadomień: 1
  dwa razy TYPE_COOKED  -> powiadomień: 2
```

To wyłączenie jest **świadome i uzasadnione na piśmie w dwóch miejscach**.
`config/kuking.php:386-387`: „Komentarze i «Ugotowałem» to ZDARZENIA
i dochodzą zawsze, bo drugi komentarz jest nową rzeczą". `NotifyUser.php:34-39`
rozwija to samo: „«X skomentował» to ZDARZENIE. Drugi komentarz tej samej
osoby pod tym samym wpisem jest NOWĄ rzeczą i musi dojść — inaczej wyciszamy
rozmowę, czyli dokładnie to, po co ten serwis istnieje".

**Wniosek: okno powtórzenia nie jest mechanizmem, który tu zapomniano
podłączyć. To mechanizm o innej semantyce (STAN kontra ZDARZENIE), a nasz
problem jest o trzeciej rzeczy: o TYM SAMYM ZDARZENIU wysłanym dwa razy.**
Podłączenie `TYPE_COOKED` pod tę listę byłoby błędem — wyciszałoby drugie
prawdziwe wykonanie tego samego przepisu w ciągu doby, a to jest zdarzenie,
które produkt istnieje żeby zapisywać.

**Dotkliwość: najwyższy koszt nieodwracalny.**

- Zdarzenie da się usunąć (`cooked.destroy`, `routes/web.php:320`).
  **Powiadomienia nie da się cofnąć** — autor już je zobaczył, a to jest,
  wprost z `AGENTS.md` §1, „najcenniejsze powiadomienie w całym serwisie".
- Zdublowanie dewaluuje najmocniejszy sygnał jakości w produkcie: licznik
  „ugotowali to 4 osoby" przestaje znaczyć „cztery osoby".
- **`AGENTS.md` §6 zakazuje tu jednego konkretnego rozwiązania:** „Nigdy nie
  dodawaj `UNIQUE (user_id, recipe_id)` do `cooked_events`" (§6; to samo
  w `database/migrations/2026_09_05_000600_create_cooked_events_tables.php:16-18`
  i w `docs/DECISIONS.md:69-81`, D-005, ze statusem „obowiązuje,
  nienaruszalne"). Zakaz jest słuszny i ten ADR go nie rusza. §3.4 pokazuje,
  która postać unikalności jest z nim zgodna, i **mierzy to**, zamiast
  zakładać.

### 1.3 Przypadek 3 — `ReportContent`: dedup w PHP działa, baza nie broni niczego

**Ścieżka:** `app/Domain/Moderation/Actions/ReportContent.php`
(`App\Domain\Moderation\Actions\ReportContent`), wołana z
`app/Http/Controllers/ReportController.php:37,66` na trasie `reports.store`
(`routes/web.php:427-429`).

**Kod — dokładnie tak, jak opisało zadanie:** `SELECT` szukający otwartego
zgłoszenia tej pary (linie 113-118), `return $existing` przy trafieniu
(120-122), `Report::create([...])` przy braku (124-131). Bez transakcji, bez
`lockForUpdate()`, bez unikalności w bazie. Komentarz klasy obiecuje efekt
wprost: „To samo zgłoszenie od tej samej osoby nie tworzy duplikatów —
zgłaszający dostaje potwierdzenie, a kolejka moderacji nie puchnie od
podwójnych kliknięć" (linie 26-27).

**Pomiar 1 — sekwencyjnie, przez akcję. Tu opis z zadania trzeba
skorygować:**

```text
POMIAR 3 · ReportContent dwa razy, sekwencyjnie
  wiersze w `reports`: 1
  identyczne id?       TAK
```

**Zwykłe podwójne kliknięcie na `/zglos` NIE tworzy duplikatu.** Akcja zwraca
ten sam obiekt, a `ReportController::store()` pokazuje to samo podziękowanie
(`app/Http/Controllers/ReportController.php:83-85`). Obietnica z komentarza
klasy jest w tym zakresie prawdziwa. Zadanie sugerowało, że duplikat powstaje
— w tej postaci **nie reprodukuje się**.

**Pomiar 2 — opór bazy. Tu opis z zadania jest w pełni trafny:**

```text
POMIAR 3b · ręczny INSERT identycznego, otwartego zgłoszenia
  baza odrzuciła wiersz? NIE (INSERT przeszedł)
  otwarte zgłoszenia tej samej pary: 2
  id z akcji:  01a07cc1-cd84-71aa-a3f5-d11c95180637
  id z INSERT: b035afeb-f0fa-4e61-bbd4-108eaad46334
  indeksy UNIQUE na `reports`: [reports_pkey]
```

**Pomiar 3 — prawdziwy wyścig na DWÓCH niezależnych połączeniach PDO** (nie
symulacja przeplotu w jednym procesie):

```text
izolacja: read committed
A widzi istniejacych: 0
B widzi istniejacych: 0
A: INSERT wykonany
B: INSERT wykonany (bez blokady, bez czekania)
A: COMMIT
B: COMMIT
WYNIK otwartych zgloszen tej samej pary: 2
```

**Dotkliwość: najwyższa poza produktem, bo dotyczy terminu prawnego.**
Podwójne zgłoszenie to podwójna sprawa w kolejce moderacji, podwójny termin
odpowiedzi z DSA art. 16 i dwie decyzje do wydania tam, gdzie sprawa jest
jedna. Zespół moderacji to 1-2 osoby (`docs/DECISIONS.md:251`, D-012).

**Prawdopodobieństwo wyścigu jest niższe niż w przypadkach 1-2** (dwa żądania
muszą trafić na dwa procesy PHP w tej samej milisekundzie), ale **ta sama
dziura ma drugie, prawdopodobniejsze wejście, które nie jest wyścigiem
wcale**: seeder, komenda konsolowa albo przyszły endpoint nie przechodzą
przez `ReportContent::handle()` i o `SELECT`-cie z linii 113 nie wiedzą. To
jest dosłownie argument, który to repozytorium już raz zapisało dla tabeli
obok:

> „komenda konsolowa, seeder albo przyszły endpoint API nie przejdą tą drogą
> i nikt o tej blokadzie nie będzie pamiętał; […] AGENTS.md §6: «prawdziwe
> klucze obce i prawdziwe CHECK-i w bazie — walidacja w PHP jest dodatkiem,
> nie zamiennikiem». Tutaj było odwrotnie."
>
> — `database/migrations/2026_09_06_190000_one_decision_per_report.php:16-22`

### 1.4 Cztery pomiary poboczne, które zmieniają kształt rozwiązania

Te cztery nie były w zadaniu. Każdy z nich **wyklucza albo przeformułowuje**
któryś z wariantów z §3, więc stoją tu, a nie w przypisie.

#### 1.4.1 Odcisk treści liczony po `media_id` nie zadziała; po sumie kontrolnej — zadziała

```text
POMIAR 1d · to samo zdanie, DWA rozne wgrania tego samego zdjecia
  media_id 1: 01a07cc1-ce7f-7357-a381-19e31465fc47
  media_id 2: 01a07cc1-ce81-7038-9907-8f1a0c5ba961
  wiersze w `posts`: 2
  wniosek: odcisk liczony PO media_id widzi dwie rozne tresci

POMIAR 1e · ten sam plik wgrany dwa razy
  wgranie 1: media_id 01a07cc2-9094-729f-8b4a-b6bd35247ce1  sha256 af1edeb2e9b8426e...
  wgranie 2: media_id 01a07cc2-90a7-7124-a0bb-0379b019555f  sha256 af1edeb2e9b8426e...
  media_id identyczne?  NIE
  sha256 identyczne?    TAK
```

Każde wgranie tworzy nowy wiersz `media` z nowym UUID-em, więc odcisk
liczony po identyfikatorach zdjęć uznaje dwa wysłania tego samego zdjęcia za
dwie różne treści. Suma `checksum_sha256`
(`app/Domain/Media/Actions/StoreUploadedImage.php:204`, indeks
`media_checksum_idx`, `database/migrations/2026_09_05_000100_create_media_table.php:52`)
jest natomiast **powtarzalna między wgraniami** — mimo że liczona jest z
bajtów PO zdjęciu GPS-u, a nie z pliku od użytkownika (komentarz, linie
200-203). Czyli: wariant „okno czasowe na tej samej treści" (§3.3) jest
wykonalny, ale **musi liczyć odcisk po sumach kontrolnych**, nie po
`media_id`. Warto odnotować, że dziś tej kolumny nic nie czyta —
`grep -rn "checksum_sha256" app/` daje dwa trafienia, oba zapis.

#### 1.4.2 `lockForUpdate()` dla przypadku 3 **nie naprawiłby go**

Zadanie wymienia `lockForUpdate()` jako wariant do rozważenia. Rozważyłem
i zmierzyłem:

```text
POMIAR 3d · czy FOR UPDATE (lockForUpdate) w ogole cokolwiek blokuje
A: SELECT ... FOR UPDATE zwrocil wierszy: 0
B: SELECT ... FOR UPDATE zwrocil wierszy: 0 (bez czekania na A)
WYNIK: wierszy 'wyscig' = 2 (FOR UPDATE nie pomogl)
```

**`FOR UPDATE` blokuje wiersze, które zapytanie ZWRÓCIŁO. Gdy nie zwróciło
żadnego, nie blokuje niczego** — obydwa połączenia przechodzą do `INSERT`
bez czekania. To jest wstawienie fantomu, a nie konflikt na wierszu, i
`READ COMMITTED` (zmierzona izolacja) go nie widzi.

Ma to znaczenie poza techniką: **dopisanie `lockForUpdate()` do
`ReportContent` i napisanie w PR-ze „naprawione" byłoby obietnicą bez
pokrycia w kodzie** — czyli tym rodzajem błędu, którego to repozytorium
znalazło u siebie już kilka (martwy limit `'upload'`, `config/kuking.php:433-443`;
`kuking.media_disk`; placeholder retencji z D-024). Wariant zostaje w §3.5
razem z tym pomiarem, właśnie żeby nikt nie sięgnął po niego drugi raz.

Dodam, że `one_decision_per_report` używa `lockForUpdate()` **skutecznie** —
ale tam blokowany jest ISTNIEJĄCY wiersz `reports`, czyli rodzic
(`database/migrations/2026_09_06_190000_one_decision_per_report.php:13-14`).
W naszym przypadku 3 rodzicem byłby wiersz `posts`/`recipes`/`comments` —
zablokowanie go działa, ale oznacza serializowanie wszystkich zgłoszeń
dowolnej treści na wierszu tej treści, więc jest gorsze od indeksu i od
niego droższe.

#### 1.4.3 Częściowy indeks UNIQUE dla `reports` działa i przepuszcza to, co ma przepuszczać

Utworzyłem indeks próbny w bazie pomiarowej i powtórzyłem przeplot:

```text
POMIAR 3e · przeplot z CZESCIOWYM INDEKSEM UNIQUE (B ma limit czasu 2 s)
indeks utworzony: TAK
A: INSERT ok (transakcja jeszcze otwarta)
B: zablokowany na indeksie i przerwany po 2.0 s -> SQLSTATE[57014]: Query canceled
A: COMMIT
--- teraz B probuje jeszcze raz, po zatwierdzeniu A ---
B: ODRZUCONY przez baze -> SQLSTATE[23505]: Unique violation: duplicate key value
                            violates unique constraint "probny_reports_one_open"
WYNIK: wierszy 'wyscig' = 1

POMIAR 3f · czy indeks przepuszcza to, co MA przepuszczac
pierwsze zgloszenie zamkniete (resolved)
nowe OTWARTE zgloszenie tej samej pary po zamknieciu poprzedniego: PRZESZLO
dwa ANONIMOWE (reporter_id NULL) zgloszenia tego samego celu: PRZESZLY — indeks ich NIE obejmuje
```

Testowany kształt (użyty w pomiarze, **nie wdrożony**):

```sql
CREATE UNIQUE INDEX ... ON reports (reporter_id, target_type, target_id)
  WHERE reporter_id IS NOT NULL AND status IN ('open','triage','reviewing');
```

Trzy rzeczy wyszły z tego pomiaru, których nie dałoby się założyć:

1. Drugi `INSERT` **czeka** na zatwierdzenie pierwszego, a potem dostaje
   `23505`. Czyli baza serializuje wyścig sama, bez blokad w PHP.
2. Zgłoszenie tej samej pary **po zamknięciu** poprzedniego przechodzi — a
   to jest wymóg produktowy: ktoś, kto zgłosił, dostał decyzję, a treść
   znów jest nie w porządku, musi móc zgłosić ponownie.
3. **Zgłoszenia anonimowe (`reporter_id IS NULL`) nie są objęte.** To jest
   wprost konsekwencja `2026_09_07_600000_allow_anonymous_legal_notices.php`
   i wymogu DSA art. 16, żeby kanał był otwarty dla każdego bez konta
   (`config/kuking.php:448-460`). Skutek: przypadek 3 na trasie
   `zglos.nielegalna.store` (`ZglosNielegalnaTresc`) tym indeksem się NIE
   załatwia — patrz pytanie P4 w §5.

#### 1.4.4 Pole z fragmentem „token" w nazwie **ginie** na ekranie 419

To jest pomiar, który przesądza o nazwie pola, a nie o architekturze — i
gdyby go zabrakło, mechanizm przestawałby działać dokładnie w sytuacji,
dla której ekran 419 powstał.

```text
POMIAR 4 · czy pole wroci na ekranie 419
  token_wyslania      WYCINANE
  idempotency_token   WYCINANE
  klucz_wyslania      wraca
  numer_wyslania      wraca
  body                wraca
```

`OdzyskiwalneDane::jestWrazliwe()` dopasowuje po FRAGMENCIE nazwy, a lista
fragmentów zawiera `'token'` (`app/Support/OdzyskiwalneDane.php:113-121,187-192`).
Ekran 419 przenosi pola jako `hidden`
(`resources/views/errors/419.blade.php:89`), więc pole nazwane
`token_wyslania` albo `idempotency_token` **nie wróci** — i ponowne
wysłanie po wygaśnięciu sesji byłoby dla serwera nowym wysłaniem. Filtr
działa prawidłowo (`_token` CSRF nie ma prawa wracać, wraca świeży, linia 66)
— to my musimy nazwać pole inaczej.

Wszystkie trzy trasy są przy tym na liście tras treści, więc odzyskiwanie
ich obejmuje: `posts.store`, `cooked.store`, `reports.store`
(`app/Support/OdzyskiwalneDane.php:79-102`).

---

## 2. Jeden problem czy trzy — rozstrzygnięcie

Zadanie kazało nie zakładać z góry, że odpowiedź brzmi „jeden". Nie brzmi.

**Odpowiedź: to są DWA problemy, a nie jeden i nie trzy. Kryterium podziału
jest jedno: czy stan, który ma być niepowtarzalny, da się WYRAZIĆ W BAZIE.**

| | Przypadek 1 `posts` | Przypadek 2 `cooked_events` | Przypadek 3 `reports` |
|---|---|---|---|
| Co ma być niepowtarzalne | **jedno wysłanie formularza** | **jedno wysłanie formularza** | **jedno otwarte zgłoszenie pary (osoba, treść)** |
| Czy powtórzenie tej samej TREŚCI jest błędem | nie — wolno napisać dwa razy „Rosół" | **nie, i to jest cechą produktu** (D-005) | tak, dopóki pierwsze jest otwarte |
| Czy da się to zapisać jako warunek na kolumnach | **nie** | **nie** | **tak** (zmierzone, §1.4.3) |
| Dedup w PHP dziś | brak | brak | jest i działa sekwencyjnie |
| Czego brakuje | tożsamości wysłania | tożsamości wysłania | egzekucji w bazie |

Przypadki 1 i 2 to **ten sam problem**: nie ma czegoś, co odpowiada na
pytanie „czy to jest to samo wysłanie, co poprzednie". Nie ma, bo w tych
tabelach nie ma nic, co by je odróżniało — dwa prawdziwe wpisy o tej samej
treści od tej samej osoby są w tym produkcie **poprawne** (przy
`cooked_events` mówi to wprost decyzja nienaruszalna D-005, a przy `posts`
mówi to sam charakter serwisu: ktoś gotuje rosół co niedzielę i pisze o tym
za każdym razem). Jedyny sposób, żeby drugie kliknięcie odróżnić od drugiego
gotowania, to **dołożyć do żądania tożsamość wysłania** — nic w treści tego
nie zdradza.

Przypadek 3 jest **inny**. Tutaj stan, który ma być niepowtarzalny, jest
własnością danych, nie żądania: „ta osoba ma otwarte zgłoszenie na tę
treść". To da się zapisać jako częściowy indeks UNIQUE i zmierzyłem, że
działa. Mechanizm z przypadków 1-2 dałby się tu przystawić, ale byłby
**słabszy**: chroniłby wyłącznie ruch przez formularz, a nie przed seederem,
komendą i drugim endpointem — czyli przed prawdopodobniejszym z dwóch wejść
do tej dziury (§1.3).

**Dlatego jeden mechanizm ich nie obsłuży, i to nie jest wybór estetyczny.**
Mechanizm „tożsamość wysłania" nie umie wyrazić „jedno otwarte zgłoszenie na
parę", a częściowy indeks UNIQUE na treści nie umie wyrazić „to samo
kliknięcie", bo w bazie nie ma śladu kliknięcia.

---

## 3. Warianty rozwiązania, z uczciwym kosztem każdego

### 3.1 Wariant A — klucz wysłania w formularzu (token idempotencji)

Serwer generuje wartość przy RENDEROWANIU formularza (w `GET`), wkłada ją
w ukryte pole, a przy `POST` sprawdza, czy tego klucza już nie użyto. Trzy
poddawarianty różnią się TYLKO tym, gdzie klucz mieszka.

**Nazwa pola nie może zawierać fragmentu „token"** — inaczej ginie na
ekranie 419 (zmierzone, §1.4.4). Dalej piszę `klucz_wyslania`.

#### A1 — klucz w sesji

Sesja chodzi na bazie (`SESSION_DRIVER=database`, `.env.example:42`,
`docs/infra/DEPLOYMENT_RUNBOOK.md:516`), więc technicznie jest to trwałe
i wspólne dla całej przeglądarki.

- **Dwie karty:** działa. Każda karta wyrenderowała własny formularz, więc
  ma własny klucz; jedna sesja obsługuje oba, bo klucze są różne.
- **„Wstecz" i ponowne wysłanie:** działa. Przeglądarka odtwarza formularz
  z tym samym ukrytym polem, więc klucz jest ten sam i zostaje rozpoznany.
- **Nieudana walidacja:** wymaga uwagi. Trasa `posts.store` przy błędzie
  odsyła `back()->withInput()` (`app/Http/Controllers/PostController.php:87`),
  a formularz renderuje się od nowa — klucz **musi** wtedy wrócić przez
  `old()`, a nie zostać wygenerowany na nowo. Inaczej po jednym błędzie
  walidacji ochrona znika na resztę wysyłki.
- **Czego NIE łapie:** wygaśnięcia sesji. A to jest jedyny moment, w którym
  człowiek na pewno kliknie „wyślij" drugi raz — po to istnieje ekran 419
  (`resources/views/errors/419.blade.php:1-16`, issue #81). Klucz z martwej
  sesji jest nieznany, więc wysłanie jest nowe. **Wariant zawodzi dokładnie
  w scenariuszu, dla którego repozytorium już raz zrobiło osobną robotę.**
  Nie łapie też niczego, co nie idzie przez sesję: komendy, seedera,
  przyszłego endpointu.
- **Koszt:** zero migracji. Ale sesja jest jednocześnie najkrótszym z
  możliwych okien ochrony — a `SESSION_LIFETIME` na produkcji to 43 200
  minut (`docs/infra/DEPLOYMENT_RUNBOOK.md:517`), więc w praktyce 30 dni.

#### A2 — klucz w cache

`CACHE_STORE=database` (`.env.example:50`, `config/cache.php:20`), tabela
`cache` istnieje (`database/migrations/0001_01_01_000002_create_cache_table.php:16-22`).
`Cache::add()` na sterowniku bazodanowym jest atomowe — wstawia wiersz i
przy kolizji klucza zwraca `false`, więc nie jest to check-then-act.

- **Dwie karty, „wstecz", walidacja:** jak w A1.
- **Czego NIE łapie:** czyszczenia cache. `php artisan cache:clear` przy
  wdrożeniu wymazuje ochronę dla wszystkich formularzy otwartych w tym
  momencie. Nie łapie też — i to jest ważniejsze — **pytania „do którego
  wpisu odesłać człowieka"**. Cache pamięta, że klucz był użyty; nie
  pamięta, jaki wiersz wtedy powstał. Drugie kliknięcie da się więc
  wyciszyć, ale nie da się na nim pokazać pierwszego wpisu — a to jest
  dokładnie to, czego człowiek oczekuje po kliknięciu „Opublikuj".
- **Koszt:** zero migracji; TTL trzeba wybrać i uzasadnić.

#### A3 — klucz w bazie, jako kolumna tabeli docelowej

Nowa kolumna `klucz_wyslania uuid NULL` w `posts` i `cooked_events`, plus
częściowy indeks UNIQUE `(author_id, klucz_wyslania) WHERE klucz_wyslania
IS NOT NULL`. Zapis idzie **wstaw-i-złap-wyjątek**, nie sprawdź-potem-wstaw.

- **Dwie karty, „wstecz", walidacja:** jak w A1.
- **419:** działa, bo klucz jedzie w ukrytym polu formularza, a to pole
  wraca (zmierzone, §1.4.4) — o ile nazwa nie zawiera „token".
- **Odpowiada na „gdzie odesłać":** tak. Przy kolizji wystarczy odczytać
  wiersz po `(user_id, klucz_wyslania)` i przekierować tam, gdzie
  przekierowałoby pierwsze wysłanie. Drugie kliknięcie staje się
  **nieodróżnialne od pierwszego**, a nie „wyciszone".
- **Czego NIE łapie:** wysłania bez klucza. Indeks jest częściowy, więc
  wiersze z `NULL` są poza nim — seeder, komenda konsolowa i każda nowa
  trasa, która klucza nie wyśle, nie są chronione. To jest **zamierzony
  koszt**: alternatywa (kolumna `NOT NULL`) rozwaliłaby `database/seeders/`
  i każdy istniejący test tworzący wpis fabryką.
- **Czego NIE łapie nigdy, w żadnym poddawariancie A:** dwóch osobno
  otwartych formularzy. Kto otworzy „Dodaj zdjęcie" w dwóch kartach i
  wyśle to samo z obu, dostanie dwa wpisy — bo dostał dwa klucze. Można
  argumentować, że to poprawne (dwa świadome wysłania), ale to nie jest
  ochrona: to granica mechanizmu i trzeba ją nazwać.
- **Koszt:** dwie migracje na dwóch najgorętszych tabelach w serwisie,
  każda z testem, wpisem w `docs/DATABASE.md` i rollbackiem (`AGENTS.md` §6).
  Plus zmiana w widokach formularzy — a `resources/**` jest dziś w rękach
  pięciu innych zleceń.

### 3.2 Wariant B — blokada po stronie przeglądarki

Wyłączenie przycisku po kliknięciu (Alpine.js: `x-on:submit` +
`disabled`), ewentualnie z podmianą napisu na „Zapisuję".

**To NIE jest rozwiązanie i nie może nim być**, i powód jest zapisany
w kontrakcie, nie w gustach:

> „Rejestracja, logowanie, publikacja wpisu, przepis, komentarz
> i «Ugotowałem» **muszą działać bez JavaScriptu**. Powód nie jest
> ideologiczny: przy słabym zasięgu skrypt się nie dociąga, a użytkownik
> zostaje z formularzem, który nic nie robi po kliknięciu."
>
> — `AGENTS.md` §5, powtórzone jako D-007 (`docs/DECISIONS.md:100-117`)

Wysłanie, przy którym skrypt się nie dociągnął, to **ten sam ruch**, w
którym strona ładuje się wolno — czyli **dokładnie ten, w którym człowiek
klika drugi raz**. Blokada w przeglądarce jest więc wyłączona właśnie
wtedy, kiedy jest potrzebna. Do tego nie robi nic dla przypadku 3 w jego
prawdziwej postaci (seeder, komenda, wyścig na dwóch procesach).

**Czego nie łapie:** braku JS, wolnego łącza, drugiej karty, natywnego
„wysłać ponownie?" przeglądarki, wyścigu na serwerze — czyli wszystkiego,
o czym jest ten ADR.

**Co jednak warto:** jako **uzupełnienie** (progressive enhancement) blokada
jest tania i realnie zmniejsza liczbę drugich żądań u osób z działającym
skryptem. Rekomendacja w §4 wymienia ją jako dodatek, nigdy jako mechanizm,
i **nie liczy jej do gwarancji**.

### 3.3 Wariant C — okno czasowe „ta sama treść od tej samej osoby w ciągu N sekund"

Bez zmian w formularzu: przed zapisem szukamy wiersza tej samej osoby o tym
samym odcisku treści z ostatnich N sekund.

- **Nie wymaga niczego od widoku** — a `resources/**` jest dziś zajęte.
- **Działa bez JS, przy „wstecz", przy natywnym ponownym wysłaniu i po
  419** — bo nie zależy od żadnego pola, które mogłoby zginąć.
- **Odcisk musi iść po sumach kontrolnych zdjęć, nie po `media_id`** —
  zmierzone (§1.4.1). Odcisk po `media_id` przepuszcza duplikat ze zdjęciem
  wgranym powtórnie, czyli najczęstszą postać duplikatu z natywnego
  „wysłać ponownie?".
- **Czego NIE łapie #1 — i to jest cena, nie usterka:** prawdziwego
  drugiego wysłania o identycznej treści w oknie. Przy `posts` to rzadkie,
  ale możliwe („Rosół" + to samo zdjęcie w dwóch wpisach). Przy
  `cooked_events` **pole `note` bywa puste** (`RecordCookedEvent.php:24`:
  „nie wymagamy zdjęcia ani żadnego pola — wystarczy sam fakt ugotowania"),
  więc odcisk pustego wykonania to praktycznie `(user_id, recipe_id)` —
  i okno zamienia się w to, czego D-005 zakazuje, tylko na N sekund. To
  jest do obrony przy N = 10 s i nie do obrony przy N = 1 h; **liczba jest
  tu decyzją, nie szczegółem** (pytanie P3 w §5).
- **Czego NIE łapie #2:** samo z siebie jest check-then-act. Bez
  unikalności w bazie albo blokady dwa równoległe żądania nadal przechodzą
  — dokładnie jak zmierzony POMIAR 3c/3d. Wariant C **nie jest
  alternatywą** dla wsparcia w bazie, tylko innym sposobem wyliczenia
  klucza; żeby był szczelny, ten odcisk musiałby wylądować w kolumnie
  z indeksem UNIQUE, a wtedy jest to wariant A3 z inną metodą liczenia
  klucza (i z gorszą semantyką, patrz wyżej).
- **Koszt:** bez migracji w wersji nieszczelnej; z migracją w wersji
  szczelnej. Plus koszt zapytania po `md5(body)` przy każdej publikacji,
  bez indeksu pod ten wzorzec.

### 3.4 Wariant D — UNIQUE w bazie, tam gdzie w ogóle da się go zdefiniować

Trzy tabele, trzy różne odpowiedzi. Wszystkie zmierzone.

**`reports` — da się, i to czysto.** Częściowy indeks UNIQUE na
`(reporter_id, target_type, target_id) WHERE reporter_id IS NOT NULL AND
status IN ('open','triage','reviewing')`. Zmierzone (§1.4.3): serializuje
wyścig, odrzuca duplikat, przepuszcza nowe zgłoszenie po zamknięciu
starego, nie dotyka zgłoszeń anonimowych.
**Czego nie łapie:** zgłoszeń bez konta (`reporter_id IS NULL`) — czyli
trasy `zglos.nielegalna.store`.

**`posts` — na treści NIE da się.** „Ta sama treść, ale nie w ciągu 30
sekund" nie jest warunkiem wyrażalnym w indeksie UNIQUE, bo indeks nie ma
pojęcia „teraz". Można by indeksować po `date_trunc('minute', published_at)`,
ale to daje ochronę zależną od tego, po której stronie granicy minuty
kliknięto — czyli losową, a losowa ochrona jest gorsza niż jej brak.
**Na kluczu wysłania — da się** i to jest wariant A3.

**`cooked_events` — na `(user_id, recipe_id)` NIE WOLNO** (D-005, `AGENTS.md`
§6). **Na kluczu wysłania — wolno, i to zmierzyłem, zamiast założyć:**

```text
POMIAR 2d · kolumna `klucz_wyslania` + czesciowy UNIQUE w `cooked_events`
1. pierwsze wyslanie (klucz A):                       PRZESZLO
2. TO SAMO wyslanie jeszcze raz (klucz A):            ODRZUCONE przez baze
3. NOWE gotowanie tego samego przepisu (klucz B):     PRZESZLO  <- D-005 nienaruszone
4. wpis bez klucza, np. z seedera (klucz NULL):       PRZESZLO
5. drugi wpis bez klucza (klucz NULL):                PRZESZLO  <- indeks czesciowy nie dotyczy NULL-i

wierszy `cooked_events` tej pary: 4
z czego roznych gotowan (klucz nie-NULL): 2
```

**To rozstrzyga najdelikatniejszą kwestię tego ADR-u.** Indeks
`(user_id, klucz_wyslania)` nie jest indeksem `(user_id, recipe_id)`
i D-005 nie narusza: ta sama osoba nadal może zapisać dowolnie wiele wykonań
tego samego przepisu, byle każde przyszło z własnego formularza (wiersz 3
pomiaru). Zakazane jest wyłącznie dwukrotne policzenie **jednego** wysłania.
**Migracja, która to wprowadzi, musi mieć ten pomiar w komentarzu** — inaczej
następna osoba przeczyta „UNIQUE w `cooked_events`", zobaczy zakaz w
`AGENTS.md` i albo to usunie, albo utknie.

### 3.5 Wariant E — `lockForUpdate()` dla samego przypadku 3

**Zmierzone: nie działa.** `SELECT ... FOR UPDATE`, który nie zwrócił
wiersza, nie blokuje niczego; oba połączenia wstawiają bez czekania (POMIAR
3d, §1.4.2). Wariant zostaje w tym dokumencie **wyłącznie z pomiarem**, żeby
nie wrócił jako „przecież wystarczy dodać blokadę".

Wersja, która by działała — blokada wiersza CELU (`SELECT * FROM posts WHERE
id = ? FOR UPDATE`) — serializuje wszystkie zgłoszenia dowolnej treści na
wierszu tej treści i wymaga transakcji wokół całej akcji. Jest droższa i
mniej czytelna od indeksu z §3.4, a chroni mniej (nie chroni seedera ani
komendy). **Nie rekomenduję.**

### 3.6 Wariant F — nie robić nic i polegać na „Usuń"

Uczciwa linia bazowa, bo trasy istnieją: `posts.destroy`
(`routes/web.php:269`), `cooked.destroy` (`routes/web.php:320`).

- **Przypadek 1:** do przeżycia, ale przenosi pracę na osobę, która ma
  najmniej narzędzi, żeby ją wykonać — i wymaga, żeby najpierw zauważyła,
  że wpisy są dwa. `docs/UX_50_PLUS.md` mierzy sukces ekranu pytaniem „co
  mam teraz kliknąć?"; dwa identyczne wpisy w archiwum to jest ten moment.
- **Przypadek 2: nie do przeżycia.** Zdarzenie da się usunąć,
  **powiadomienia nie da się cofnąć**. Autor już zobaczył dwa „Halina
  ugotowała Twój rosół" za jedno gotowanie.
- **Przypadek 3: nie do przeżycia.** Podwójna sprawa moderacyjna to
  podwójny termin odpowiedzi z DSA i dwie decyzje tam, gdzie sprawa jest
  jedna.

**Czego nie łapie:** wszystkiego. Wymieniam ten wariant, żeby koszt
pozostałych był z czymś porównany, nie jako propozycję.

---

## 4. Rekomendacja — jedna, nazwana wprost

**Rekomenduję DWA mechanizmy w trzech zastosowaniach: wariant A3 (klucz
wysłania w kolumnie tabeli docelowej, z częściowym indeksem UNIQUE i zapisem
„wstaw i złap wyjątek") dla przypadków 1 i 2, oraz wariant D (częściowy
indeks UNIQUE na otwartych zgłoszeniach) dla przypadku 3. Bez wariantu C,
bez wariantu E, z wariantem B jako niewiążącym dodatkiem.**

### 4.1 Dlaczego A3, a nie A1/A2 ani C

- **A1 (sesja) zawodzi po 419** — czyli w jedynym scenariuszu, o którym
  wiemy na pewno, że człowiek wyśle drugi raz. Repozytorium zrobiło osobną
  robotę, żeby ten scenariusz obsłużyć (issue #81); mechanizm, który
  właśnie tam się rozpada, jest wewnętrznie niespójny z tą pracą.
- **A2 (cache) nie umie odpowiedzieć, gdzie odesłać człowieka.** Wycisza
  drugie kliknięcie, ale nie potrafi pokazać pierwszego wpisu — a bez tego
  drugie kliknięcie wygląda jak „nie zadziałało". Do tego ginie przy
  `cache:clear` na wdrożeniu.
- **C (okno na treści) przy `cooked_events` degeneruje się do zakazanego
  `(user_id, recipe_id)`** dla pustego wykonania, bo pole `note` może być
  puste z założenia. Przy N = 10 s da się to obronić, ale mechanizm, który
  jest bezpieczny tylko przy odpowiednio małej liczbie w konfiguracji, jest
  gorszy od mechanizmu, który nie zależy od żadnej liczby.
- **A3 nie zależy od żadnej liczby.** Nie ma okna, nie ma TTL, nie ma
  progu. Klucz jest ważny dokładnie tak długo, jak długo istnieje
  wyrenderowany formularz — a to jest to samo, co intuicja człowieka:
  „ten jeden formularz wysłałem raz".
- **A3 daje jedną własność, której żaden inny wariant nie daje: drugie
  kliknięcie jest NIEODRÓŻNIALNE od pierwszego.** Człowiek ląduje na swoim
  wpisie i widzi to, co miał zobaczyć. To jest jedyna postać rozwiązania,
  która nie wymaga od odbiorcy 50+ zrozumienia niczego nowego.

### 4.2 Dlaczego przypadek 3 dostaje inny mechanizm

Bo stan, który ma być niepowtarzalny, **jest w bazie wyrażalny** (zmierzone,
§1.4.3), a prawdopodobniejsze z dwóch wejść do tej dziury nie jest
formularzem (§1.3). Klucz wysłania chroniłby tylko formularz. Indeks chroni
wszystko, jest mniejszy (bez kolumny, bez zmiany widoku) i ma w tym
repozytorium dokładny precedens wraz z uzasadnieniem
(`2026_09_06_190000_one_decision_per_report.php`).

`SELECT` z linii 113-118 **zostaje** — to on daje ciepłą ścieżkę
„już to mamy", zamiast wyjątku z bazy. Indeks jest pod nim, nie zamiast
niego; przy `23505` akcja odczytuje istniejące zgłoszenie i zwraca je tak
samo, jak dziś zwraca `$existing`. **`lockForUpdate()` nie zostaje dodany** —
zmierzyłem, że nie zrobiłby nic (§1.4.2), a dopisanie go byłoby obietnicą
bez pokrycia.

### 4.3 Reguła nadrzędna: mechanizm zawodzi OTWARCIE, nigdy zamknięcie

**Nieznany albo brakujący klucz wysłania oznacza „wyślij normalnie", nigdy
„odmawiam".** Ta reguła jest częścią rekomendacji, nie szczegółem
implementacji, i wynika z §6: **zduplikowany wpis jest dla odbiorcy 50+
mniej szkodliwy niż utracony wpis**. Cena jest jawna — ochrona jest z
definicji najlepszym staraniem, nie gwarancją; wysłanie z formularza, który
klucza nie ma (stara zakładka, nowa trasa, seeder), przejdzie.
**Alternatywę — zawodzenie zamknięte, z komunikatem — opisuję w §7.5 wraz z
tekstem, bo właściciel ma prawo ją wybrać. Nie rekomenduję jej.**

---

## 5. Co pytanie zostawia właścicielowi

Krótkie wybory. Rekomendacja agenta jest zaznaczona **pogrubieniem**.

**P1. Mechanizm dla przypadków 1 i 2 (wpis, „Ugotowałem"):**
czy **klucz wysłania w kolumnie bazy (A3)**, czy klucz w cache (A2), czy
okno czasowe na treści (C)?

**P2. Mechanizm dla przypadku 3 (zgłoszenie):**
czy **częściowy indeks UNIQUE w bazie (D)**, czy zostawiamy sam dedup w PHP,
jak jest dziś?

**P3. Gdyby jednak wariant C:** okno **10 s**, 30 s czy 60 s?
(Pytanie odpada przy P1 = A3 — A3 nie ma okna.)

**P4. Zgłoszenia bez konta (`/zglos-nielegalna-tresc`, DSA art. 16):**
czy obejmujemy je **kluczem wysłania (A3)**, czy zostawiamy bez ochrony,
bo `reporter_id` jest `NULL` i indeks ich nie widzi?

**P5. Gdy klucz jest nieznany albo go nie ma:**
czy **wysyłamy normalnie** (ryzyko duplikatu), czy odmawiamy i pokazujemy
komunikat (ryzyko utraty wpisu)?

**P6. Kolejność wdrożenia:**
czy **`reports` → `cooked_events` → `posts`** (od najmniejszej zmiany
i najwyższego kosztu prawnego), czy `posts` najpierw, bo to najczęstsza
akcja?

**P7. Blokada przycisku w przeglądarce (JS) jako dodatek:**
czy **wchodzi razem z mechanizmem**, czy osobnym issue później?

**P8. Nazwa pola i kolumny:**
czy **`klucz_wyslania`**, czy `numer_wyslania`? (Nazwa z fragmentem „token"
jest wykluczona pomiarem — §1.4.4.)

---

## 6. Wpływ na odbiorcę 50+

`docs/UX_50_PLUS.md` mówi to wprost i ten ADR się temu podporządkowuje:
**„Poprawne dane nie znikają"**, a strony błędów „po polsku, w layoucie
serwisu, każda mówi **co zrobić** i daje drogę powrotu".

### 6.1 Ekran 419 jest tym samym problemem w innej odsłonie — i jest już rozwiązany dobrze

Zadanie kazało sprawdzić, co dziś mówi człowiekowi `errors/419.blade.php`.
Mówi to:

> **Ta strona była otwarta zbyt długo**
>
> Ze względów bezpieczeństwa formularz jest ważny tylko przez pewien czas,
> a ten był otwarty dłużej. **Twój tekst jest na miejscu** — nic nie
> przepadło. Kliknij „Wyślij jeszcze raz", a wpis pójdzie tam, gdzie miał iść.

(`resources/views/errors/419.blade.php:35,38-43`, przycisk „Wyślij jeszcze
raz" w linii 115.)

To jest wzorzec, a nie problem. Komentarz na górze pliku formułuje zasadę,
którą ten ADR przyjmuje jako wiążącą dla siebie: „To NIE jest strona błędu.
To jest ten sam formularz, wystawiony jeszcze raz, z treścią i ze świeżym
tokenem. Kod 419 jest tu tylko statusem HTTP — na ekranie nie ma go wcale,
bo nikomu nic nie mówi" (linie 3-6).

**Rekomendacja z §4 nie dodaje żadnego nowego ekranu „ta strona wygasła"
i nie może dodać** — bo:

1. Klucz jedzie w ukrytym polu, a ukryte pola z tras treści ekran 419
   przenosi (`resources/views/errors/419.blade.php:89`) — o ile nazwa nie
   zawiera „token" (zmierzone, §1.4.4). Dlatego nazwa pola jest w tym
   dokumencie osobnym pytaniem do właściciela (P8), a nie szczegółem.
2. Klucz nie ma terminu ważności. Nie ma czego „wygasić".
3. Nieznany klucz przechodzi jak zwykłe wysłanie (§4.3), więc nie ma
   ścieżki, na której człowiek dostaje odmowę.

Gdyby właściciel wybrał inaczej w P5 (zawodzenie zamknięte), **wtedy taki
ekran by powstał** — i mówię to tutaj, nie w przypisie: byłoby to
pogorszenie dla tej grupy, oddające część roboty, którą issue #81 właśnie
wykonało. Tekst na taką okoliczność jest w §7.5, żeby wybór był świadomy,
a nie żeby go zachęcać.

### 6.2 Co się zmienia dla człowieka po wdrożeniu rekomendacji

| Sytuacja | Dziś | Po |
|---|---|---|
| Kliknięcie „Opublikuj" dwa razy, bo strona myśli | dwa wpisy, drugi adres w przeglądarce | jeden wpis, ten sam ekran co przy jednym kliknięciu |
| „Wstecz" i „Opublikuj" jeszcze raz | drugi wpis, często bez zdjęcia (§1.1) | ten sam wpis, bez komunikatu o błędzie |
| Natywne „wysłać ponownie?" przeglądarki | drugi wpis z nową kopią zdjęcia | ten sam wpis |
| Dwa razy „Ugotowałem" | dwa wykonania, **dwa powiadomienia u autora** | jedno wykonanie, jedno powiadomienie |
| Prawdziwe drugie gotowanie tego samego przepisu | zapisuje się (poprawnie) | **zapisuje się nadal** (zmierzone, §3.4 wiersz 3) |
| Dwa razy „Zgłoś" | dziś jedno zgłoszenie (dedup w PHP działa), ale baza nie broni | jedno zgłoszenie, egzekwowane w bazie |
| Formularz otwarty w dwóch kartach, to samo wysłane z obu | dwa wpisy | **dwa wpisy** — granica mechanizmu, §3.1 |
| Sesja wygasła, ekran 419, „Wyślij jeszcze raz" | wpis idzie (poprawnie) | **wpis idzie nadal**, bez nowego ekranu |

### 6.3 Czego mechanizm NIE wolno zrobić, i to jest wymóg, nie preferencja

- **Nie wolno pokazać błędu przy drugim kliknięciu.** Drugie kliknięcie nie
  jest pomyłką człowieka, tylko sposobem obsługi komputera
  (`tests/Feature/IdempotentnyZapisDoZeszytuTest.php:16-19`). Komunikat
  o błędzie za coś, co nie jest błędem, uczy tę grupę, że przycisk jest
  niebezpieczny — a stąd jest jeden krok do niepublikowania wcale
  (`AGENTS.md` §12, `docs/brand/COPY_STYLE.md` §2: ponad połowa osób 50+
  nigdy nic nie publikuje).
- **Nie wolno zgubić tekstu.** `docs/UX_50_PLUS.md`, „Poprawne dane nie
  znikają".
- **Nie wolno wymagać JavaScriptu.** `AGENTS.md` §5, D-007.
- **Nie wolno zablokować prawdziwego drugiego gotowania.** D-005, status
  „obowiązuje, nienaruszalne".

---

## 7. Wszystkie teksty widoczne dla człowieka — dosłownie

Ton: `docs/brand/COPY_STYLE.md` — rejestr „ciepły bez żartu" dla
potwierdzeń, „poważny" dla wszystkiego, co jest odmową. Zero emoji, zero
wykrzykników, zero `kuKING` (§2 tego pliku: „kuKING nigdy nie pojawia się
w komunikacie błędu … ani w formularzu, który ktoś właśnie wypełnia").

**Uwaga o tym, ile z tych tekstów jest naprawdę potrzebne.** Przy
rekomendacji z §4 drugie kliknięcie jest nieodróżnialne od pierwszego, więc
w ścieżce podstawowej **nie potrzeba żadnego nowego tekstu** — człowiek
widzi istniejące „Opublikowane. Dziękujemy." (`docs/brand/COPY_STYLE.md` §6).
Teksty poniżej są potrzebne tylko tam, gdzie zachowanie serwisu odbiega od
tego, czego człowiek mógłby się spodziewać.

### 7.1 Drugie wysłanie tego samego formularza wpisu — komunikat na stronie wpisu

> Ten wpis jest już opublikowany. Kliknięcie drugi raz nic nie zepsuło —
> wpis jest jeden.

Do wystawienia jako `session('status')` w tym samym miejscu, w którym dziś
pojawia się „Opublikowane. Dziękujemy.". Drugie zdanie jest tam celowo:
człowiek, który kliknął dwa razy, zwykle zdążył się już zaniepokoić.

### 7.2 Drugie wysłanie tego samego formularza „Ugotowałem"

> To wykonanie już zapisaliśmy. Autor przepisu dostał jedno powiadomienie,
> nie dwa.

### 7.3 Podpowiedź dla kogoś, kto chce dodać to samo jeszcze raz NA SERIO

Bez tego zdania mechanizm wygląda jak awaria dla osoby, która naprawdę
gotowała ten przepis dwa razy w jednym dniu. Do postawienia pod
komunikatami z §7.1 i §7.2, jako zwykły tekst pomocniczy.

Dla wpisu:

> Chcesz dodać osobny wpis? Otwórz „Dodaj zdjęcie" jeszcze raz — wtedy
> powstanie nowy.

Dla „Ugotowałem":

> Gotowałeś ten przepis drugi raz? Otwórz „Ugotowałem" jeszcze raz — każde
> wykonanie zapisujemy osobno.

### 7.4 Drugie zgłoszenie tej samej treści

Dziś na obu ścieżkach — pierwszej i powtórnej — wyświetla się to samo
zdanie (`app/Http/Controllers/ReportController.php:83-85`), i to jest
w porządku. Gdyby właściciel chciał powtórzenie wyróżnić:

> To zgłoszenie już do nas trafiło. Sprawdzamy je — nie musisz wysyłać go
> drugi raz.

Bez `kuKING`, bez wykrzyknika, bez „Ups" — `docs/brand/COPY_STYLE.md` §4
wymienia „Ups! Coś poszło nie tak" wprost jako to, czego nie bierzemy.

### 7.5 Tekst dla wariantu, którego NIE rekomenduję (P5 = zawodzenie zamknięte)

Gdyby właściciel wybrał odmowę przy nieznanym kluczu, komunikat MUSI stać
przy wystawionym od nowa formularzu z zachowaną treścią — nigdy jako sucha
strona błędu. W tonie ekranu 419, bo to jest ten sam problem:

> **Nie jesteśmy pewni, czy ten wpis już nie został opublikowany**
>
> Ten formularz był otwarty od dawna. Twój tekst jest na miejscu — nic nie
> przepadło. Zajrzyj najpierw do swojego profilu: jeśli wpisu tam nie ma,
> kliknij „Opublikuj" jeszcze raz.

**To jest tekst gorszy od braku tekstu** — przenosi na człowieka sprawdzenie,
które serwis powinien zrobić sam. Zapisuję go, żeby wybór był świadomy, i
powtarzam: nie rekomenduję (§4.3).

### 7.6 Tekst dla wariantu B (blokada w przeglądarce), jeśli wejdzie jako dodatek

Napis na przycisku po kliknięciu — spójny z „Autosave" z
`docs/UX_50_PLUS.md` i z „Zapisuję" z `AGENTS.md` §11:

> Zapisuję

Przycisk nie może zmieniać szerokości ani wysokości po zmianie napisu (48 px
minimum, `docs/UX_50_PLUS.md`) — inaczej układ skacze pod palcem.

---

## 8. Plan wdrożenia i wycofania

### 8.1 Kolejność (rekomendacja; pytanie P6)

**Krok 1 — `reports`, sam indeks. Najpierw, bo najmniejsza zmiana i
najwyższy koszt zwłoki.**
Bez kolumny, bez zmiany widoku, bez dotykania `resources/**` (dziś zajętego
przez pięć innych zleceń). Jeden plik migracji + `try/catch` na `23505`
w `ReportContent::handle()` + test regresyjny. Koszt prawny zwłoki
(podwójna sprawa DSA) jest najwyższy z trzech.

**Krok 2 — `cooked_events`: kolumna + indeks + klucz w formularzu.**
Drugi, bo koszt nieodwracalny (powiadomienie u autora, którego nie da się
cofnąć) jest wyższy niż przy `posts`, a powierzchnia zmiany mniejsza —
formularz „Ugotowałem" to jeden widok.

**Krok 3 — `posts`: kolumna + indeks + klucz w formularzu.**
Ostatni, bo powierzchnia największa: formularz publikacji ma trzy osobne
przyciski wysyłające w tym samym `<form>` („Szukaj tagów", „Dodaj",
„Usuń" — `app/Http/Controllers/PostController.php:92-109`), a klucz musi
przeżyć każdą z tych dróg i nie zostać „zużyty" przez wysłanie, które wpisu
nie tworzy. **To jest najbardziej prawdopodobne miejsce błędu w całym
wdrożeniu** i dlatego idzie na końcu, na już sprawdzonym mechanizmie.

**Krok 4 (opcjonalny, pytanie P4)** — ta sama kolumna i indeks dla
`zglos.nielegalna.store`, jeśli właściciel chce objąć zgłoszenia bez konta.

**Krok 5 (opcjonalny, pytanie P7)** — blokada przycisku w przeglądarce jako
dodatek, osobnym issue, po wdrożeniu mechanizmu. Nigdy przed — inaczej
maskuje błąd, którego się jeszcze nie naprawiło, i pomiar z §8.3 przestaje
mierzyć cokolwiek.

### 8.2 Migracje — CZY tak i JAKIE (kodu nie piszę, zgodnie ze zleceniem)

**Tak, potrzebne są trzy.** Każda z nich, wprost z `AGENTS.md` §6, wymaga
**wszystkich czterech** rzeczy: migracji, testu, akapitu w `docs/DATABASE.md`
i opisu rollbacku.

| # | Tabela | Co robi | Rollback |
|---|---|---|---|
| M1 | `reports` | tylko częściowy indeks UNIQUE na `(reporter_id, target_type, target_id) WHERE reporter_id IS NOT NULL AND status IN ('open','triage','reviewing')`; bez kolumny | `DROP INDEX IF EXISTS`, bezstratnie |
| M2 | `cooked_events` | kolumna `klucz_wyslania uuid NULL` + częściowy indeks UNIQUE `(user_id, klucz_wyslania) WHERE klucz_wyslania IS NOT NULL` | `DROP INDEX`, potem `DROP COLUMN`; bezstratnie, bo kolumna nie niesie treści od człowieka |
| M3 | `posts` | jak M2, dla `(author_id, klucz_wyslania)` | jak M2 |

Cztery rzeczy, które te migracje MUSZĄ zrobić — każda z nich wynika z
pomiaru w tym dokumencie, nie z ostrożności:

1. **M1 musi ODMÓWIĆ, gdy duplikaty już są w bazie** — dokładnie tak, jak
   `2026_09_06_190000_one_decision_per_report.php:43-59`, i z tego samego
   powodu: ciche skasowanie „nadmiarowego" zgłoszenia byłoby skasowaniem
   sprawy DSA, na którą ktoś mógł się powołać. Który wiersz obowiązuje,
   rozstrzyga człowiek.
2. **Indeksy muszą być CZĘŚCIOWE, nie zwykłe.** W PostgreSQL zwykły UNIQUE
   przepuszcza dowolnie wiele `NULL`-i, więc technicznie zadziałałby — ale
   indeks częściowy mówi to wprost i nie każe czytelnikowi pamiętać o tej
   właściwości (ten sam argument, ta sama migracja, linie 24-28).
3. **Kolumna musi być `NULL`-owalna, bez backfillu.** Wiersze istniejące
   i wiersze z seederów/fabryk zostają poza indeksem — zmierzone (§3.4,
   wiersze 4-5). `NOT NULL` rozwaliłoby `database/seeders/` i każdy test
   tworzący wpis fabryką.
4. **Komentarz M2 musi cytować pomiar z §3.4** — że to NIE jest
   `UNIQUE (user_id, recipe_id)` i że D-005 zostaje nienaruszone (wiersz 3
   pomiaru: nowe gotowanie z nowym kluczem przechodzi). Bez tego następna
   osoba zderzy ten indeks z zakazem z `AGENTS.md` §6 i albo go usunie,
   albo utknie na godzinę.

Do tego: `docs/DATABASE.md` potrzebuje akapitu przy `posts`,
`cooked_events` i `reports` w tym samym kształcie, w jakim ma go
`product_signals` przy retencji. **Ten ADR `docs/DATABASE.md` nie zmienia** —
zgodnie ze zleceniem.

### 8.3 Jak sprawdzić, że działa

**Testy (obowiązkowe, `AGENTS.md`: „Bugfix zawsze zawiera test
regresyjny").** Po jednym na przypadek, w postaci, którą decyzja właściciela
ustali jako poprawną — czyli **odwróconej** wobec pomiarów z tego ADR-u
(1 wiersz, nie 2). Do tego trzy testy, których łatwo nie napisać, a które
są tu najważniejsze:

- **Prawdziwe drugie gotowanie z nowym kluczem przechodzi** (obrona D-005;
  bez tego testu pierwsza osoba, która zobaczy UNIQUE w `cooked_events`,
  słusznie się przestraszy).
- **Nowe zgłoszenie po zamknięciu poprzedniego przechodzi** (§1.4.3,
  wiersz 2 pomiaru 3f).
- **Formularz odzyskany po 419 przenosi klucz** — czyli że nazwa pola nie
  wpadła pod filtr z §1.4.4. Ten test broni pomiaru, który jest najłatwiejszy
  do przypadkowego zepsucia zmianą nazwy pola.

**Obserwacja na produkcji.** Zapytanie liczące pary wpisów tego samego
autora o identycznej treści powstałe w odstępie do 60 sekund — uruchomione
PRZED i PO wdrożeniu. Bez pomiaru „przed" nie da się powiedzieć, czy
mechanizm coś zmienił, a przy 20 osobach w zamkniętej becie
(`docs/DECISIONS.md:251`, D-012) liczby będą małe i trzeba je znać dokładnie.
Analogicznie dla `cooked_events` (te same `user_id`, `recipe_id`
w odstępie do 60 s) i dla `reports` (dwa otwarte zgłoszenia tej samej pary
— po M1 ta liczba musi być zerowa z definicji).

### 8.4 Co zrobić, jeśli mechanizm zacznie blokować prawdziwe wysyłki

Przy rekomendacji z §4.3 (zawodzenie otwarte) ten scenariusz jest wąski, ale
nie zerowy: błąd w przenoszeniu klucza przez `old()` mógłby sprawić, że po
nieudanej walidacji formularz wysyła klucz JUŻ ZUŻYTY — i drugie, poprawione
wysłanie zostałoby uznane za duplikat pierwszego. **Skutek dla człowieka:
poprawiony wpis nie powstaje, a serwis odsyła go do wpisu, którego nie ma.**
To jest najgorsza z możliwych awarii tego mechanizmu i dlatego ma dwa
niezależne wyjścia awaryjne:

**Wyjście 1 — natychmiastowe, bez migracji i bez wdrożenia schematu:
przestać wysyłać klucz.** Flaga w `config/kuking.php` (proponowany kształt,
do przeglądu przy implementacji: `kuking.formularze.klucz_wyslania_wlaczony`,
z `env()`), która sprawia, że formularz renderuje się bez ukrytego pola.
Wtedy kolumna dostaje `NULL`, indeks częściowy takiego wiersza nie obejmuje
i **serwis wraca dokładnie do dzisiejszego zachowania** — z duplikatami, ale
bez zablokowanych wysyłek. **To jest jedyna droga wycofania, która nie
wymaga wdrożenia migracji, i dlatego flaga musi wejść razem z mechanizmem,
nie później.** Indeks może zostać w bazie; nie przeszkadza.

**Wyjście 2 — pełne: rollback migracji** (`DROP INDEX`, potem `DROP COLUMN`,
bezstratnie). Potrzebne tylko wtedy, gdy problem jest w samym indeksie,
a nie w kliencie.

**Dla M1 (`reports`) wyjście 1 nie istnieje** — indeks nie zależy od
niczego, co wysyła formularz. Wycofanie to `DROP INDEX IF EXISTS`, czyli
osobna migracja i osobne wdrożenie. Dlatego M1 idzie pierwsze, samo, na
możliwie małym ruchu: żeby ewentualny problem ujawnił się, kiedy jest tanio
odkręcić. Ryzyko jest przy tym najmniejsze z trzech — dedup w PHP już dziś
nie dopuszcza duplikatu w normalnym ruchu (zmierzone, POMIAR 3), więc indeks
odrzuci wyłącznie to, czego i tak nie powinno być.

---

## 9. Czego ten ADR nie rozstrzyga

- **Pozostałe formularze serwisu.** Zlecenie objęło trzy akcje. Ten sam
  problem ma prawdopodobnie `PublishRecipe`, `EditPost`, `CommentController`
  i `FileAppeal` — **nie mierzyłem ich** i nie zgaduję. Jeśli rekomendacja
  zostanie przyjęta, ich przegląd jest osobnym issue, z własnymi pomiarami.
- **Zgłoszenia anonimowe** (`reporter_id IS NULL`, `source = 'legal_notice'`)
  — zmierzone, że indeks z §3.4 ich nie obejmuje; decyzja, czy je objąć
  kluczem wysłania, to pytanie P4.
- **Zdjęcia osierocone przez wyciszone wysłanie.** Gdy drugie wysłanie
  zostanie rozpoznane jako duplikat, zdjęcia wgrane przy nim zostają
  niepodpięte do niczego. Sprząta je `kuking:sprzataj-osierocone-zdjecia`
  po 24 h karencji (`app/Console/Commands/SprzatajOsieroconeZdjecia.php:13`,
  `app/Domain/Media/OsieroconeZdjecia.php:36`), więc mechanizm nie zostawia
  śmieci na stałe — ale **nie mierzyłem** tej ścieżki i nie twierdzę,
  że przechodzi bez poprawki.
- **Brak powiadomienia gospodarza przy współbieżnym pierwszym wpisie**
  (§1.1, wniosek z czytania kodu, nie pomiar). Osobny błąd, o odwrotnym
  znaku, poza zakresem tego ADR-u.
- **Idempotencja po stronie kolejki.** `QUEUE_CONNECTION` to kolejka
  bazodanowa (`AGENTS.md` §3); zdublowane zadanie w tle to inny problem
  i inny mechanizm.
- **Nazwy kluczy konfiguracji i nazwa kolumny** podane w §7-§8 są
  propozycją do przeglądu przy implementacji, nie ostatecznym API. Sama
  nazwa POLA W FORMULARZU jest natomiast ograniczona pomiarem (§1.4.4)
  i to ograniczenie nie jest kwestią gustu.
- **Testy pomiarowe nie zostają w repozytorium.** Plik
  `tests/Feature/PomiarIdempotencjiFormularzyTest.php` (12 przypadków,
  23 asercje, wszystkie zielone) asercjonuje **stan dzisiejszy**: dwa
  wiersze, dwa powiadomienia. Zostawienie go oznaczałoby, że w dniu
  wdrożenia decyzji właściciela CI świeci na czerwono, a osoba
  wdrażająca musi najpierw skasować cudzy test — czyli że test broni
  błędu. To jest gorsze niż jego brak. Właściwe testy (odwrócone,
  regresyjne) należą do wdrożenia, nie do tego ADR-u; §8.3 wymienia,
  które muszą w nim być. Same pomiary są przepisane w §1 dosłownie,
  razem z komendami, więc da się je powtórzyć bez tego pliku.

---

## 10. Gotowa treść wpisu do `docs/DECISIONS.md` — DO WKLEJENIA PO DECYZJI

**Tego wpisu nie ma w `docs/DECISIONS.md` i nie wolno go tam wkleić, dopóki
właściciel nie odpowie na pytania z §5.** Numer jest nienadany; najbliższy
wolny na dziś to **D-027** (ostatni zajęty: D-026,
`docs/DECISIONS.md:1059`). Poniższa treść zakłada przyjęcie rekomendacji
z §4; przy innym wyborze w P1/P2 wymaga przepisania, nie tylko podmiany
liczby.

```markdown
## D-0XX · Jedno wysłanie formularza to jeden zapis — klucz wysłania, nie okno czasowe

**Data:** [do uzupełnienia] · **Decyzja właściciela** · Status: **obowiązuje**

Podwójne kliknięcie nie jest w grupie 50+ pomyłką, tylko sposobem obsługi
komputera: strona myśli chwilę, więc klika się drugi raz. Zmierzone (audyt
wyścigów, 7 września 2026): dwa kliknięcia „Opublikuj" dawały dwa wpisy,
dwa kliknięcia „Ugotowałem" — dwa wykonania i **dwa powiadomienia** u autora
przepisu, a `reports` przyjmowało drugie identyczne otwarte zgłoszenie bez
oporu bazy.

Rozstrzygnięte DWA mechanizmy, nie jeden, bo to są dwa różne problemy:

1. **Wpis i „Ugotowałem": klucz wysłania.** Formularz dostaje przy
   renderowaniu jednorazowy klucz w ukrytym polu; tabela dostaje kolumnę
   `klucz_wyslania` i częściowy indeks UNIQUE. Zapis idzie
   „wstaw i złap wyjątek", a przy kolizji człowiek trafia na swój
   pierwszy wpis — drugie kliknięcie jest nieodróżnialne od pierwszego.
   **To NIE jest `UNIQUE (user_id, recipe_id)` i D-005 zostaje
   nienaruszone**: nowe gotowanie z nowego formularza przechodzi
   (zmierzone).
2. **Zgłoszenie: częściowy indeks UNIQUE w bazie** na otwartych
   zgłoszeniach pary (osoba, treść). Dedup w PHP już działał w zwykłym
   ruchu, ale nie chronił przed seederem, komendą ani wyścigiem — ten sam
   argument, który stoi za `moderation_actions_one_per_report`.

Odrzucone i dlaczego:

- **Blokada przycisku w JavaScripcie** jako mechanizm — publikacja musi
  działać bez JS (D-007), a brak skryptu to ten sam ruch, w którym strona
  ładuje się wolno, czyli ten, w którym klika się drugi raz. Zostaje
  wyłącznie jako niewiążący dodatek.
- **`lockForUpdate()` dla zgłoszeń** — zmierzone, że nie działa:
  `SELECT ... FOR UPDATE`, który nie zwrócił wiersza, nie blokuje niczego
  i oba połączenia wstawiają bez czekania.
- **Okno czasowe „ta sama treść w ciągu N sekund"** — przy pustym
  wykonaniu „Ugotowałem" odcisk treści degeneruje się do
  `(user_id, recipe_id)`, czyli do tego, czego D-005 zakazuje, na N sekund.

Mechanizm **zawodzi otwarcie**: nieznany albo brakujący klucz oznacza
„wyślij normalnie", nigdy „odmawiam". Zduplikowany wpis jest dla odbiorcy
50+ mniej szkodliwy niż utracony wpis, a ekran mówiący „ta strona wygasła"
jest gorszy od jednego i drugiego (issue #81, `errors/419.blade.php`).

**Zmiana wymaga:** zmierzonego przypadku, w którym klucz wysłania blokuje
prawdziwe wysyłki, i to takiego, którego nie da się naprawić bez zmiany
samego mechanizmu.

📄 `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` · `docs/DATABASE.md` ·
`app/Domain/Posts/Actions/PublishPost.php` ·
`app/Domain/Recipes/Actions/RecordCookedEvent.php` ·
`app/Domain/Moderation/Actions/ReportContent.php`
```
