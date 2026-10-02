# Sygnały produktowe i tagi

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### product_signals

Sygnały produktowe (issue #115), migracja
`2026_09_06_220000_create_product_signals_table`. Dwanaście zdarzeń:
`photo_upload_failed` (próba wgrania zdjęcia, która się nie udaje —
`App\Domain\Media\Actions\StoreUploadedImage`), `search_performed`
(wykonane wyszukiwanie — `App\Http\Controllers\SearchController`) oraz para
od tygodniowego podsumowania: `weekly_digest_queued` i
`weekly_digest_unsubscribed` (issue #11, D-057; migracja
`2026_09_10_100100_add_digest_signals_to_product_signals`) oraz cztery sygnały
instalacji PWA opisane przy `users.pwa_prompt_state` powyżej. Jedyne miejsce,
które tu pisze: `App\Domain\Analytics\ZapiszSygnal`.

**Cztery sygnały „Jak wyszło?” (F1, D-333, migracja
`2026_09_30_140000_add_cooking_followup_product_signals`):**
`cooking_last_step_reached` (nowe gotowanie otworzyło ostatni krok trybu
gotowania), `cooking_last_step_cooked` (to gotowanie skończyło się
„Ugotowałem”; jedyne pole `properties.po_pytaniu`, bool), `cooking_followup_shown`
i `cooking_followup_dismissed` (pytanie na Starcie pokazane / „Nie teraz”).
Pisze je wyłącznie `App\Domain\Recipes\Gotowanie\JakWyszlo` i zawsze z
`user_id = NULL`, bez przepisu — raport (`App\Domain\Analytics\DojsciaDoKoncaGotowania`,
`kuking:raport`) potrzebuje samych liczników. To, KTÓRY przepis ta osoba
gotowała, zna tylko jej sesja (klucz `gotowanie.jak_wyszlo`: przepis, konto,
chwila dojścia, stan; najwyżej 10 wpisów, starsze niż 3 dni się nie liczą) —
bez nowej tabeli, tak jak odhaczone kroki w `CookingModeController`. CHECK
jest przestawiany bez przerwy w ochronie: nowy pod tymczasową nazwą
`NOT VALID`, `VALIDATE`, zdjęcie starego, zmiana nazwy (AGENTS.md §6).
**Rollback:** `down()` kasuje tylko wiersze tych czterech sygnałów (telemetria
z retencją 90 dni, nie decyzja człowieka — D-088 nie dotyczy) i przywraca
poprzedni słownik tą samą drogą. Test: `SygnalyJakWyszloMigracjaTest`.

**Trzy sygnały „Zużyj w pierwszej kolejności” (#1903, D-333, migracja
`2026_10_01_103000_add_pantry_product_signals`):** `pantry_expiry_set` (zapisano
termin przy produkcie), `pantry_priority_viewed` (otwarto listę, na której jest
sekcja „Zużyj w pierwszej kolejności”), `pantry_cook_priority_viewed` (obejrzano
przepisy w trybie „Najpierw to, co się psuje”, tylko pierwsza strona). Pisze je
`PantryController` przez `ZapiszSygnal` i zawsze z `user_id = NULL`, bez nazw
produktów i dat (`properties = {}`). Odsetek kont z co najmniej jednym terminem
liczy się wprost z `pantry_items`. „Zużyte” i „wyrzucone” nie są mierzone (D6 A).
CHECK przestawiany bez przerwy w ochronie (tymczasowa nazwa, `NOT VALID`,
`VALIDATE`, zmiana nazwy — AGENTS.md §6). **Rollback:** `down()` kasuje tylko
wiersze tych trzech sygnałów (telemetria z retencją 90 dni — D-088 nie
dotyczy). Test: `MigracjaTerminowSpizarniTest`,
`CofniecieMigracjiNieKasujeTerminowSpizarniTest`.

**`weekly_digest_queued` nazywał się do 10 września `weekly_digest_sent`**
(audyt MAIL-03, **D-078**, migracja
`2026_09_10_400000_rename_weekly_digest_sent_signal`). Wiersz powstaje zaraz
po `Mail::queue()`, więc stara nazwa sklejała w jedno trzy różne zdarzenia —
ZAKOLEJKOWANO, DOSTAWCA PRZYJĄŁ, DORĘCZONO — a Kuking widzi tylko pierwsze.
Skutek był mierzalny: list, który przewracał się w workerze i lądował
w `failed_jobs`, i tak liczył się jako wysłany, czyli metryka zawyżała
skuteczność wysyłki najbardziej właśnie wtedy, gdy wysyłka nie działała.
Migracja **przepisuje** stare wiersze (`UPDATE`, nie `DELETE`) i nie zostawia
w bazie dwóch nazw na jedno zdarzenie; nic w kodzie nie czytało starej nazwy,
więc nie było panelu do zepsucia. `down()` przepisuje symetrycznie
z powrotem.

**Zbiór nazw rośnie o nazwy WYMIENIONE Z IMIENIA, jedna decyzja na jedną
nazwę.** CHECK nie jest formalnością: zamknięta lista jest drugą linią
obrony przed zamienieniem tej tabeli w ogólny dziennik odwiedzin, którego
AGENTS.md §3 zabrania budować bez zmierzonej potrzeby. Rozszerzenie
przechodzi więc przez migrację `DROP CONSTRAINT` + `ADD CONSTRAINT`
z pełną listą, a nie przez zdjęcie ograniczenia.

**Czego świadomie NIE ma: `weekly_digest_opened` i `weekly_digest_clicked`.**
Issue #11 prosiło o cztery zdarzenia; wdrożone są pierwsze i ostatnie.
„Otwarty" wymaga niewidzialnego obrazka śledzącego w treści listu,
„kliknięty" — podmiany każdego odnośnika na przekierowanie przez nasz serwer.
Obie techniki zapisują, kiedy konkretna osoba czytała pocztę i z jakiego
adresu IP; polityka prywatności obiecuje czegoś takiego nie robić, a własny
transport ma nawet wyłącznik śledzenia po stronie dostawcy
(`X-TRACKING-OFF`, `App\Poczta\TransportEmailLabs`) — domyślnie włączony.
Do jedynego progu, po którym coś robimy („wypisy > 1% na wysyłkę",
`docs/product/RETENTION_LOOPS.md` §6 wiersz 5), wystarcza para
zakolejkowane/wypisane.

**Nie ma też `weekly_digest_delivered`** i to jest ta sama decyzja, nie
przeoczenie: doręczenie wymagałoby webhooka o odbiciach od dostawcy, którego
nie mamy (`docs/decyzje/POCZTA.md` §5 pkt 6). Zamknięty zbiór nazw pilnuje
tego również jako TEST: `SygnalDigestuMowiZakolejkowanoTest::
test_zamkniety_zbior_nazw_nie_obiecuje_doreczenia_ani_otwarcia` czyta CHECK
wprost z `pg_constraint` i oblewa się, gdy w słowniku pojawi się nazwa
mówiąca „doręczono", „otwarto" albo „kliknięto". Gdy prawdziwy webhook kiedyś
powstanie, zdejmuje się `delivered` z tamtej listy JAWNIE, jedną decyzją —
śledzenia otwarć i kliknięć nie zdejmuje się wcale.

`docs/research/ANALITYKA.md`, do którego issue #115 odsyła po schemat
i retencję, **ISTNIEJE** — wcześniejsza wersja tego akapitu twierdziła
inaczej i była nieprawdziwa (sprostowanie: dokument leżał na gałęziach
`research/*`, nigdy nie scalony, więc nie było go w drzewie roboczym; „nie
ma go tutaj" to nie to samo co „nikt go nie napisał"). Ta tabela powstała
z kształtu wypisanego wprost w treści issue, nie z tamtego dokumentu — i
**okazała się z nim zgodna**, w tym co do 90 dni retencji, których
uzasadnienie stoi w §3.5 tamtego pliku. Drugi punkt odniesienia to
`product_events` z `docs/seo/ANALYTICS.md` §7 —
ta tabela jest jego świadomie okrojoną wersją (dwa zdarzenia zamiast
dowolnych, bez `anonymous_id`/`session_id`/`platform`, bo dziś nic ich tu nie
potrzebuje).

| Kolumna | Uwagi |
|---|---|
| `id` | `bigserial`, nie UUID — wiersz nigdy nie jest adresowany z zewnątrz (ten sam wybór co `audit_log`). |
| `user_id` | Nullable, `nullOnDelete()`. Anonimizacja konta (`EraseAccountData`, D-018) NIE kasuje wiersza — sygnał ma wartość niezależnie od tego, kto go wywołał — ale **jawnie ustawia `user_id = NULL`** w tej samej transakcji (issue #1324). Kaskada klucza obcego by tego nie zrobiła, bo wiersza `users` się nie kasuje (D-022). Ponowienie wymazania już wymazanego konta odpina też sygnały, które zostały sprzed poprawki. Sygnał zapisywany w chwili wymazania: `ZapiszSygnal` bierze `FOR SHARE` na wierszu konta i przy ustawionym `data_erased_at` zapisuje zdarzenie bez `user_id`. Liczniki zbiorcze się nie zmieniają; wiersz znika po zwykłej retencji 90 dni. Bez migracji — rollback to cofnięcie kodu (odpięcia nie da się odwrócić i nie powinno się dać). |
| `signal_name` | `photo_upload_failed` \| `search_performed` \| `weekly_digest_queued` \| `weekly_digest_unsubscribed` \| `pwa_prompt_shown` \| `pwa_install_requested` \| `pwa_prompt_dismissed` \| `pwa_installed`. CHECK w bazie (`product_signals_signal_name_check`) — zamknięty zbiór, tak jak `reports.status`. |
| `properties` | `jsonb`. Dla `photo_upload_failed`: `reason` (patrz niżej) i gdzie to ma sens liczby (`bytes`, `max_bytes`, `megapixels`) — NIGDY nazwa pliku. Dla `search_performed`: **wyłącznie** `query_length` (int) i `has_results` (bool) — **nigdy** `query_text`. Drugi CHECK w bazie (`product_signals_no_query_text_check`, przez `jsonb_exists()`) odrzuca każdy wiersz, w którym klucz `query_text` w ogóle by się pojawił, niezależnie od tego, co akurat pisze kod aplikacji. Dla `weekly_digest_queued`: **wyłącznie liczby** — `wykonania`, `nowi_obserwujacy`, `wpisy` (ile pozycji miała każda sekcja listu), żeby dało się zobaczyć, czy listy nie robią się cienkie. Bez adresu, bez nazw, bez tytułów. Dla `weekly_digest_unsubscribed`: `properties` jest PUSTE — sam fakt i `user_id` wystarczą do progu wypisów. |
| `occurred_at` | `timestamptz`, `useCurrent()`. **SPROSTOWANIE (D-078):** wcześniej stało tu, że dla `weekly_digest_sent` kolumna jest czytana JAKO LICZNIK dobowego limitu poczty. Nieprawda — sprawdzone w kodzie: dobowy sufit liczy `App\Poczta\DziennyBudzetListow`, a ten trzyma licznik w **cache**, nie w tej tabeli, i nie sięga do `product_signals` ani razu. Ta kolumna służy dziś wyłącznie retencji (`kuking:sprzataj-sygnaly`) i porządkowaniu w czasie. |

#### `reason` dla `photo_upload_failed` — pięć kodów z issue, ale NIE pięć `throw` w kodzie

Issue #115 wymienia pięć powodów (`unreadable`, `too_large`, `not_an_image`,
`unsupported_format`, `too_many_megapixels`). W `StoreUploadedImage::handle()`
są naprawdę **cztery instrukcje `throw`**, nie pięć — a jedna z tych czterech
jest dziś nieosiągalna (dead code, opisany tak wprost w komentarzu kodu, na
długo przed tym zgłoszeniem). Trzy z pięciu kodów (`not_an_image`,
`unsupported_format`, `too_many_megapixels`) odpowiadają trzem gałęziom
WEWNĄTRZ jednego trzeciego `throw` (`RozpoznanieZdjecia::coJestNieTak()`),
które wcześniej zwracały tylko komunikat po polsku, bez kodu maszynowego.
`RozpoznanieZdjecia::rozpoznaj()` (nowa metoda, `WynikRozpoznania`) zwraca oba
naraz, żeby sygnał dostał właściwy kod bez zgadywania go z treści zdania.

| Kod | Skąd |
|---|---|
| `unreadable` | `$file->getSize()` zwraca `false`/`<=0`. |
| `too_large` | Rozmiar przekracza `config('kuking.media.max_bytes')`. |
| `not_an_image` | `getimagesize()` nie rozpoznaje pliku (w tym HEIC) — **oraz** nieosiągalny dziś drugi `getimagesize()` w `handle()`, zostawiony jako siatka bezpieczeństwa. |
| `unsupported_format` | Rozpoznany typ MIME spoza `LimityZdjec::dozwoloneTypy()`. |
| `too_many_megapixels` | Wymiary przekraczają `config('kuking.media.max_megapixels')`. |

Indeksy: `product_signals_name_time_idx (signal_name, occurred_at DESC)` —
dashboard „upload error rate" (`docs/seo/ANALYTICS.md` §6);
`product_signals_occurred_idx (occurred_at)` — retencja poniżej, która nie
filtruje po `signal_name`.
`product_signals_user_signal_idx (user_id, signal_name) WHERE user_id IS NOT NULL`
— `RecordPromptShown` pyta po koncie i sygnale pod blokadą wiersza `users`
(migracja `2026_09_25_100000_…`, rozdział „Indeksy kluczy obcych na gorących
ścieżkach” niżej).

**Retencja:** `config('kuking.analytics.signal_retention_days')` (domyślnie
90 dni), egzekwowana przez `kuking:sprzataj-sygnaly`
(`App\Domain\Analytics\PrzedawnioneSygnaly`), harmonogram codziennie o 04:00
(`routes/console.php`). `DELETE ... WHERE occurred_at < ? AND id IN (...)`
partiami — bez `chunkById` po modelach, bo wiersz nie ma odpowiednika po
stronie storage (w odróżnieniu od `OsieroconeZdjecia`).

**Retencja prostych tabel partiami (#1657)** — `product_signals`, `audit_log`,
zwykłe `notifications`, `sessions` i `potwierdzenia_zadan_rodo` kasuje
`App\Support\UsuwanieWPartiach`: partia identyfikatorów w stałym
porządku po kluczu głównym, potem `DELETE` z tym samym predykatem wieku
(i wyjątków: `NIGDY_NIE_KASUJ`, typy odwoławcze, wstrzymanie RODO, próg
`SESSION_LIFETIME`) we własnej krótkiej transakcji. Najwyżej
`kuking.retencja.budzet` wierszy z jednej tabeli na przebieg (domyślnie
50 000, partia `kuking.retencja.partia` = 1000); reszta schodzi w kolejne
noce, z ostrzeżeniem `stage=retention_budget_exhausted` (tabela i liczby,
bez identyfikatorów). Przerwany przebieg zachowuje zatwierdzone partie.
Wcześniej był tu jeden `DELETE` na cały backlog — przerwany cofał się
w całości. Schemat ani indeksy się nie zmieniają; pomiaru `EXPLAIN` na
danych produkcyjnych nie wykonano. Testy: `RetencjaPartiamiTest`.

**Zapis sygnału nigdy nie wywraca operacji, którą opisuje:**
`ZapiszSygnal::handle()` łapie każdy wyjątek i tylko go loguje
(`Log::warning`) — wyszukiwarka ma pokazać wyniki, a komunikat o nieudanym
wgraniu zdjęcia ma dojść do człowieka, nawet gdy zapis wiersza akurat się nie
uda.

**Rollback:** `DROP TABLE product_signals` bez zastrzeżeń — to są dane
telemetryczne, nie dane, na podstawie których podjęto decyzję.

### tags + tag_aliases + post_tags + tag_follows + tag_promotions + tag_highlights

Otwarta taksonomia użytkowników, zastępująca Tematy (D-021, migracje
`2026_09_07_100000_create_tags_tables` i `2026_09_07_100100_create_tag_promotions_table`).
Ta sekcja była wcześniej wpisana do „V1 / V2 — Później" — D-021 przenosi ją
do MVP.

**`topics`/`topic_follows`/`posts.topic_id` są USUNIĘTE** (migracja
`2026_09_07_300000_drop_topics`). Migracja sprawdza przed usunięciem, czy
`topic_follows` ma jakiekolwiek wiersze i czy jakikolwiek wpis ma niepusty
`topic_id` — jeśli tak, przerywa operację (`RuntimeException`) zamiast po
cichu skasować dane (SPEC §1.1, D-021: „właściciel musi to zrobić przed
migracją, bo od tego zależy, czy usunięcie tematów jest zmianą schematu, czy
rozmową z ludźmi, którym coś zniknie z profilu"). Stara migracja tworząca
Tematy (`2026_09_06_100000_create_topics_tables`) ZOSTAJE w repozytorium
bez zmian — inne środowiska mogły ją już wykonać, a przepisywanie historii
migracji złamałoby je przy kolejnym `php artisan migrate`.

**Cztery tabele rdzenia, nie sześć.** Specyfikacja właściciela projektowała
też `tag_relations` (podpowiedzi semantyczne) i `tag_merge_suggestions`
(skrzynka odbiorcza AI dla kandydatów do scalenia) — obie świadomie odłożone
jako czysto addytywne, bez zmierzonej potrzeby przy 20–50 kontach
(AGENTS.md §3). Uzasadnienie w komentarzu migracji `create_tags_tables`.

| Kolumna (`tags`) | Uwagi |
|---|---|
| `id` | `uuid` — encja publiczna (ma slug, ma własną stronę `/tag/{slug}`). |
| `name` | Nazwa kanoniczna, z zachowanymi polskimi znakami. |
| `normalized_name` | `UNIQUE`. Do UNIKALNOŚCI — `mb_strtolower(trim(...))` + redukcja białych znaków + Unicode NFC, **BEZ `unaccent`** (`App\Models\Tag::znormalizujNazwe`). **Nigdy** `kuking_normalize()` — ta funkcja robi `unaccent` i służy wyłącznie wyszukiwaniu/podpowiadaniu; użyta tutaj złamałaby wymóg, że `zurek` i `żurek` to dwa różne tagi. |
| `slug` | `UNIQUE`, CHECK `^[a-z0-9-]{1,40}$`, liczony osobno od `name`. |
| `status` | `active` \| `hidden` \| `merged`. CHECK w bazie. |
| `merged_into_tag_id` | Nullable, self-FK **bez `ON DELETE`** — domyślne `NO ACTION` Postgresa blokuje skasowanie tagu kanonicznego, dopóki są do niego przypięte tagi scalone. Dodatkowy CHECK `(status='merged') = (merged_into_tag_id IS NOT NULL)`, CHECK `tags_merged_not_self_check` (`merged_into_tag_id <> id`) i wyzwalacz `tags_scalenie_jednym_skokiem_trg` — cel scalenia jest zawsze aktywny (#996, niżej). |
| `is_seeded` | Tag z początkowej bazy redakcyjnej (SPEC §1.4) — atrybut pochodzenia danych, nie osobny system widoczny dla użytkownika. |
| `internal_category` | Techniczna, jedna z trzynastu kategorii słownika tagów (`potrawy`, `wypieki`, `skladniki`, `przygotowanie`, `przetwory`, `okazje`, `sezon`, `regiony`, `kuchnie-swiata`, `diety`, `okolicznosci`, `sprzet`, `pamiec`) — do raportu z importu i sortowania panelu, **nigdy** pokazywana użytkownikowi. Wcześniej było tu osiem innych wartości (`danie`, `skladnik`, `kuchnia`, `technika`, `okazja`, `dieta`, `urzadzenie`, `napoj`) — pochodziły z bazy wpisanej na sztywno w `TagSeeder`, zastąpionej słownikiem z pliku (D-026). `TagSeeder` aktualizuje tę kolumnę na istniejących wierszach, więc migracja danych nie była potrzebna. |

**Skąd bierze się początkowa baza (D-026).** Nie z kodu: `TagSeeder` czyta
`database/seeders/dane/slownik-tagow.json` (1250 nazw kanonicznych, 2366
aliasów, 13 kategorii, pole `uwagi` z 44 rozstrzygnięciami autora — nie
kasować) oraz `database/seeders/dane/slownik-tagow-uzupelnienia.json` (169
pojęć, których duży słownik nie ma). Razem 1419 tagów i 2448 aliasów.
Zawartość plików jest sprawdzana maszynowo BEZ uruchamiania seedera
(`tests/Feature/SlownikTagowTest.php`), bo kolizji aliasu z nazwą kanoniczną
innego tagu nie widać okiem. Pole `sezonowy` z pliku (226 tagów) świadomie
NIE MA kolumny w bazie — funkcja sezonowości nie istnieje, a kolumna bez
drogi zapisu i odczytu to ten sam błąd, który opisuje zadanie o minutniku
kroku.

**Scalanie tagów ma wreszcie drogę zapisu.** `status = 'merged'`
i `merged_into_tag_id` istniały od tej migracji, a mechanizm ich CZYTANIA
był kompletny (przekierowanie strony tagu, wykluczenie z podpowiedzi,
`ResolveTagsForPost` rozwiązujące nazwę do tagu kanonicznego) — ustawiał je
natomiast wyłącznie `forceFill` w testach. Od D-026 robi to nazwana akcja
`App\Domain\Tags\Actions\MergeTags` (zapowiadana w komentarzu modelu
`Tag` i w komentarzu przy indeksie `tag_aliases.tag_id` w tej migracji):
przepina wpisy i obserwujących, przepina aliasy źródła, dopisuje nazwę
źródła jako alias celu, przenosi promocję, ustawia `status`. Wiersz źródła
NIE JEST kasowany (SPEC §1.8), więc jego adres `/tag/{slug}` nadal działa
i przekierowuje. `audit_log` zapisuje wywołujący, nie ta akcja — scalenie
z panelu ma autora, scalenie z seedera nie ma go wcale.

**Graf scaleń ma w bazie jeden skok do aktywnego celu (#996).** Migracja
`2026_09_24_100000_scalenia_tagow_jednym_skokiem_do_aktywnego` egzekwuje
regułę, na której stoi `Tag::tagKanoniczny()` (dokładnie jeden skok):
każda krawędź `X.merged_into_tag_id = Y` ma `X ≠ Y` i `Y.status = 'active'`.
Skoro aktywny tag nie ma celu scalenia, łańcuch `A → B → C` i cykl nie mają
jak powstać — bez rekurencji. Pętlę `A → A` odrzuca CHECK
`tags_merged_not_self_check`; resztę wyzwalacz `BEFORE INSERT OR UPDATE OF
status, merged_into_tag_id` z obu stron krawędzi: (1) cel ukryty albo scalony
jest odrzucany, (2) tag, na który wskazuje inny scalony tag, nie może zostać
ukryty ani scalony („najpierw przepnij je na nowy cel” — `MergeTags` robi to
w tej kolejności). Cel jest czytany `FOR SHARE`, więc dwa równoległe
scalenia (`A → B` i `B → C`) serializują się na wierszu B i druga transakcja
odmawia — zmierzone w `tests/Dwa/ScalenieTagowNaDwochPolaczeniachTest`.
`MergeTags` zostaje czytelną walidacją domenową; bariera łapie każdą inną
drogę zapisu (import, seeder, konsola).

Istniejące dane: migracja blokuje zapisy do `tags` na czas kontroli
i DDL, liczy krawędzie łamiące regułę i przy choćby jednej **odmawia**
z liczbami, niczego nie zmieniając — dokąd ma prowadzić stary adres, to
decyzja redakcyjna. Diagnostyka (tylko odczyt, rekurencyjne CTE ze ścieżką
każdego złego scalenia): `docs/diagnostyka/996_graf_scalen_tagow.sql`.

**Rollback #996:** `down()` zdejmuje wyzwalacz, funkcję
`tags_scalenie_jednym_skokiem()` i CHECK. Bezstratnie — poluzowanie reguły
nie dotyka wierszy, więc nie ma czego odmawiać; gwarancję trzyma wtedy
już tylko `MergeTags`.

`tag_aliases`: `id` **bigserial**, nie `uuid` — wiersz nigdy nie jest
adresowany z zewnątrz (ten sam wybór co `product_signals`/`audit_log`).
**`alias varchar(30)`** — wariant nazwy w pisowni, w jakiej ktoś go naprawdę
wpisał albo zaimportował („serniki" przy kanonicznym „sernik"); to ta kolumna
niesie tekst od człowieka i tylko ona nadaje się do pokazania.
`normalized_alias` to ten sam napis po `kuking_normalize()` i to on ma
`UNIQUE` w całej tabeli — **nie `alias`**, bo „Serniki" i „serniki" mają być
jednym aliasem, a nie dwoma. `source`: `seed` \| `admin` \|
`ai_suggestion`, CHECK w bazie. Wejście na alias przekierowuje na tag
kanoniczny (`Tag::tagKanoniczny()`) — bez osobnej tabeli przekierowań, bo
scalony tag zostaje w `tags` ze swoim slugiem.

`post_tags`: pivot **bez własnego `id`**, `PRIMARY KEY(post_id, tag_id)` —
relacja, nie encja, dokładnie jak `post_media`. `position` (CHECK `>= 0`,
`UNIQUE(post_id, position)`) — kolejność, w jakiej autor dodawał tagi.
Limit 5 tagów/wpis egzekwowany w `App\Domain\Tags\Actions\ResolveTagsForPost`
(`App\Support\LimityTagow`), liczony na unikalnych `tag_id`, nie na wpisanych
frazach.

Od #647 `post_tags.dodany_recznie` jest `boolean NOT NULL DEFAULT true`.
Migracja `2026_09_18_000000_add_manual_origin_to_post_tags` zachowuje dawne
powiązania jako ręczne; nie zmienia opisów ani nie importuje ich tokenów.
Przy świadomej publikacji/edycji parser rozpoznaje tokeny `#slug` w końcowym
opisie. `ResolvePostTags` łączy ręczny zbiór M i zbiór tokenów I po kanonicznym
ID: relacja istnieje dla M OR I, a flaga zapisuje M. Skasowanie ostatniego
tokenu usuwa wyłącznie relację inline-only. Ręczne nazwy wielowyrazowe nadal
działają; slug istniejącego taga rozwiązuje się do jego nazwy bez tworzenia
duplikatu. Limit obejmuje unię obu źródeł po aliasach/scaleniu.

Ukrycie taga nie zmienia pochodzenia istniejącego powiązania. Zapis nie
reaktywuje ukrytego taga ani nie przypina go do nowego wpisu. Scalenie
zachowuje OR ręcznego pochodzenia dwóch pivotów i nie przepisuje body.
Współdzielona transakcyjna blokada `TagMutationLock` dopuszcza równoległe
zapisy wpisów, natomiast scalenie bierze wyłączność na czas zmiany słownika
i pivotów. Edycja dodatkowo blokuje swój wpis. Opis, nowe nazwy i pivoty
zapisują się atomowo. Eksport własnych wpisów zawiera nazwy, slugi, ID tagów
i flagę pochodzenia, również dla wpisów prywatnych.

**Rollback #647:** `down()` blokuje tabelę na czas kontroli i DDL. Odmawia,
jeżeli jakikolwiek pivot ma false, także przy usuniętym miękko wpisie:
odtworzenie kolumny zamieniłoby inline-only na ręczny wybór. Przy samych true
usunięcie kolumny jest bezstratne. Powrót samej aplikacji do starego obrazu
także nie jest bezstratny: stary formularz traktuje wszystkie relacje jako
ręczne. Zachowaj kolumnę i nowy kontrakt zapisu albo wstrzymaj edycje na czas
uzgodnionej migracji danych; nie zastępuj istniejących false przez true.

`tag_follows`: **bez własnego `id`**, `PRIMARY KEY(user_id, tag_id)` —
jeden do jednego z (usuwanym) `topic_follows`.

`tag_promotions` (D-021, „tag promowany — lista gospodarza"): promocja
zamiast drugiego typu obiektu. `PRIMARY KEY` to `tag_id` (relacja 1:1
z tagiem, jak `profiles.user_id`) — jeden tag ma najwyżej jedną promocję.
`position` (CHECK `>= 0`) i opcjonalna `note` (jedno zdanie od gospodarza,
odpowiednik `topics.description`). Panel: `Admin\TagPromotionController`,
za tą samą bramką co „kuKINGi na dziś" (`Gate` `moderate` na `User`).
„Kto i kiedy" zmienił listę zapisuje `audit_log`, bez osobnej kolumny
`promoted_by` — ten sam wzorzec co `daily_board.updated`.

`tag_highlights` (issue #18, migracja `2026_09_25_100000_create_tag_highlights_table`)
— **tag tygodnia**: zwykły tag wyróżniony na dni `starts_on`–`ends_on`
(`date`, dzień w strefie `kuking.strefa`), z opcjonalną `note` (200 znaków).
Osobna tabela, bo `tag_promotions` ma klucz `tag_id` i nie uniesie historii
ani powrotu tego samego tagu za rok. `id` UUID, FK `tag_id` → `tags`
`ON DELETE CASCADE`. W bazie: CHECK `ends_on >= starts_on` i
`EXCLUDE USING gist (daterange(starts_on, ends_on, '[]') WITH &&)` —
dwa wyróżnienia nie nachodzą na siebie, więc bieżące jest najwyżej jedno.
Czyta `TagHighlight::doPokazania()` (blok na `/home`), pisze
`Admin\TagHighlightController` (audyt `tag_highlight.added`/`.removed`).
Całość za flagą `KUKING_TAG_TYGODNIA` (domyślnie wyłączona).
**Rollback:** `DROP TABLE` bez strażnika D-088 — to plan redakcyjny, nie
zgoda ani prywatność; ginie plan i archiwum wyróżnień, tagi i wpisy zostają.

Indeksy trigramowe (Postgres, na `kuking_normalize()` z migracji
`2026_09_05_001300_fix_search_indexes`): `tags_name_trgm_idx`,
`tag_aliases_alias_trgm_idx` — używane przez `App\Domain\Tags\TagSuggester`
(SPEC §1.5: prefiks → alias dokładny → podobieństwo trigramowe →
popularność liczona `withCount('posts')`, bez utrzymywanego ręcznie licznika).

**Rollback:** `DROP TABLE` w kolejności `tag_promotions`, `tag_follows`,
`post_tags`, `tag_aliases`, `tags` — bezpieczne bez zastrzeżeń, to jest
funkcja budowana od zera przy zerowym ruchu produkcyjnym (D-021: „0 tematów,
0 wpisów z tematem" w chwili decyzji, a tagi jeszcze nie istniały).
