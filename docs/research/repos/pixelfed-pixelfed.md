# pixelfed/pixelfed — notatka researchowa

**Licencja: AGPL-3.0** (`LICENSE`, GNU Affero GPL v3). Konsekwencja jest ostrzejsza
niż przy GPL: AGPL obejmuje także **udostępnianie przez sieć**, więc skopiowanie
choćby jednej funkcji do Kuking oznaczałoby obowiązek udostępnienia źródeł
całego Kuking każdemu użytkownikowi serwisu. **Z tego repozytorium nie bierzemy
ani jednej linii kodu.** Bierzemy: kształt modelu danych (nazwy kolumn i tabel
nie są utworem), kolejność kroków w pipeline'ie zdjęć, listę przypadków
brzegowych i to, czego oni nauczyli się po ośmiu latach prowadzenia serwisu
zdjęciowego z moderacją.

Snapshot: `git clone --depth 1` z 2026-09-05. 245 migracji — czyli osiem lat
poprawek modelu danych, i właśnie ta historia jest tu najcenniejsza.

---

## 1. Co wynika z licencji

- Kopiowanie kodu: **wykluczone**, dopóki Kuking nie jest AGPL.
- Czytanie kodu w celu poznania problemu: dozwolone i to robimy.
- Odtworzenie funkcji „od zera”, mając w głowie ich rozwiązanie: ryzyko prawne
  jest realne przy przepisywaniu 1:1 nietrywialnego algorytmu, znikome przy
  przenoszeniu decyzji typu „najpierw zastosuj orientację EXIF, potem
  re-enkoduj”. Ta druga rzecz to fakt techniczny, nie utwór.
- Praktyczna zasada dla agentów: w komicie odwołującym się do tej notatki
  **nie wolno wklejać fragmentów ich kodu** nawet jako komentarza „tak robi Pixelfed”.

## 2. Użyteczny model danych

### 2.1 `user_filters` — blokada i wyciszenie w jednej tabeli

`database/migrations/2018_07_15_013106_create_user_filters_table.php`:

```
user_filters: user_id, filterable_id, filterable_type,
              filter_type default 'block',
              UNIQUE (user_id, filterable_id, filterable_type, filter_type)
```

Dwie rzeczy, których nie mamy:

1. **`filter_type` = `block` albo `mute`.** Blokada jest deklaracją wobec drugiej
   osoby (przestajemy się widzieć, follow leci w obie strony). Wyciszenie jest
   prywatne — „nie chcę tego widzieć w feedzie, ale nie chcę robić sceny”.
   U nas jest wyłącznie `blocks` (`database/migrations/2026_09_05_000300_create_follows_and_blocks_tables.php`).
   Dla grupy 50+, gdzie sąsiadka z tej samej wsi też jest na Kuking,
   „ukryj bez blokowania” jest funkcją realnie potrzebną — blokada jest zbyt
   mocnym gestem społecznym.
2. **`filterable_type`** — filtrować da się nie tylko konto. U nich to profil
   albo domena instancji; u nas mogłoby to być konto **albo hasztag/kategoria**
   („nie pokazuj mi już wnętrzności”, „nie pokazuj mi diet”).

### 2.2 `reports` — czego brakuje im, a co mamy my

```
reports: profile_id (zgłaszający), object_id, object_type,
         reported_profile_id, type, message,
         admin_seen (timestamp), meta json,
         UNIQUE (user_id, object_type, object_id)
```

- `admin_seen` jako **timestamp, nie boolean** — wiadomo nie tylko czy, ale kiedy
  moderator to zobaczył. Do liczenia SLA z `docs/legal/MODERATION_PLAYBOOK.md`
  (sekcja 3) potrzebujemy dokładnie tego. Nasze `reports` mają `status`
  i `resolved_at`, ale **nie mają momentu pierwszego kontaktu moderatora** —
  a to jest różnica między „zgłoszenie leżało 3 dni” i „moderator zobaczył je
  po 10 minutach, ale sprawa jest trudna”.
- `reported_profile_id` zdenormalizowane obok `object_id` — pozwala odpowiedzieć
  „ile zgłoszeń dotyczy tego konta” bez rozwiązywania polimorfizmu. U nas to
  samo pytanie wymaga joinów przez `posts`/`recipes`/`comments`. Przy ekranie
  „historia konta” w panelu to policzalna różnica.
- `UNIQUE (user_id, object_type, object_id)` w bazie — dedup na poziomie
  constraintu. Nasza wersja (`app/Domain/Moderation/Actions/ReportContent.php:55-64`)
  jest **lepsza**, bo dedupuje tylko w otwartych statusach, czyli po zamknięciu
  sprawy da się zgłosić nawrót. Ich constraint blokuje to na zawsze.
- `type` + cztery boolean-y (`spam`, `nsfw`, `abusive`, `not_interested`)
  równolegle — ślad ewolucji, dziś nieczytelny. Nasze `reports.reason` z listą
  w `App\Models\Report::REASONS` jest czystsze. **Nie kopiować ich kształtu.**

### 2.3 `report_logs` — append-only log decyzji z flagą `system_message`

```
report_logs: profile_id, item_id, item_type, action,
             system_message boolean, metadata json
```

`system_message` odróżnia wpis zrobiony przez człowieka od wpisu zrobionego
automatem. Przy DSA art. 17 użytkownik musi dostać informację, **czy decyzja
była zautomatyzowana** (`docs/legal/COMPLIANCE.md:23`). Nasze
`moderation_actions` mają `moderator_id` NOT NULL — czyli automat nie ma jak
wpisać decyzji, a jak wpiszemy go jako „konto systemowe”, to stracimy
rozróżnienie. **To jest konkretna luka wobec wymogu, który już mamy zapisany.**

### 2.4 `account_interstitials` — uzasadnienie decyzji i odwołanie jako tabela

To najcenniejsza rzecz w całym repozytorium.

```
account_interstitials: user_id, type, view, item_id, item_type,
                       has_media, blurhash,
                       violation_header, violation_body, message,
                       appeal_message,
                       appeal_requested_at, appeal_handled_at, read_at
+ users.has_interstitial boolean (indeks)
```

Mechanika: gdy treść zostaje ograniczona, tworzy się wpis i użytkownik przy
następnym wejściu **zamiast feedu widzi ekran z uzasadnieniem**. Nie zamknie go,
dopóki nie potwierdzi (`read_at`), i z tego samego ekranu składa odwołanie
(`appeal_message` → `appeal_requested_at` → `appeal_handled_at`).
Typy są nazwane po zdarzeniu: `post.cw`, `post.unlist`, `post.removed`,
`post.autospam` (`app/Models/AccountInterstitial.php:39`).

Dlaczego to jest dla nas ważne:

- `docs/legal/MODERATION_PLAYBOOK.md:73-79` **już opisuje ścieżkę odwołania**,
  a `docs/legal/COMPLIANCE.md:249` stawia ją jako P1. Nie mamy pod to żadnej
  tabeli. `moderation_actions.user_message` przechowuje treść komunikatu, ale
  nie ma: potwierdzenia odczytania, treści odwołania, momentu złożenia
  i momentu rozpatrzenia.
- `users.has_interstitial` jako zdenormalizowany boolean z indeksem — dzięki
  temu middleware sprawdzający „czy ten użytkownik ma nierozczytane
  uzasadnienie” to jedno pole w już wczytanym rekordzie, nie zapytanie
  na każde żądanie. To ten sam wzorzec, którego potrzebowałby nasz middleware
  z rekomendacji R1 w notatce o laravel.io.

### 2.5 `media_blocklists` — hash zablokowanych plików

```
media_blocklists: sha256 unique, sha512 unique, name, description,
                  active boolean, metadata json
```

Po usunięciu zdjęcia za naruszenie, hash idzie na listę i **ten sam plik nie
przejdzie ponownego wgrania** — nawet z innego konta. My mamy już
`media.checksum_sha256` z indeksem (`media_checksum_idx`), czyli **połowa pracy
jest zrobiona**: brakuje tylko tabeli listy i sprawdzenia w
`App\Domain\Media\Actions\StoreUploadedImage`. To najtańsza możliwa obrona przed
powtórnym wgraniem tej samej treści przez spamera, który wraca z nowym kontem.

Uwaga na ograniczenie: checksum łapie **identyczny plik**, nie „to samo zdjęcie
przekompresowane”. Do tego drugiego jest `media.perceptual_hash`, którą mamy
w schemacie i której na razie nie liczymy `[do weryfikacji, czy
ProcessUploadedImage ją wypełnia — w kodzie z 2026-09-05 nie wypełnia]`.

### 2.6 Kolumny mediów, których warto się przyjrzeć

`media`: `order` (kolejność w albumie — u nas `post_media.position`, mamy),
`processed_at`, `skip_optimize`, `blurhash`, `orientation`,
`UNIQUE (status_id, media_path)`.

- `skip_optimize` — flaga „nie ruszaj tego pliku”. U nich do materiałów już
  zoptymalizowanych. Dla nas antywzorzec: każdy plik musi przejść
  re-enkodowanie, bo to jedyna gwarancja zdjęcia EXIF
  (`docs/legal/SECURITY_BASELINE.md:118`). **Nie przenosić.**
- `blurhash` — kilkudziesięcioznakowy string, z którego renderuje się rozmyty
  placeholder przed dociągnięciem zdjęcia. Na wolnym łączu (nasz scenariusz
  bazowy) to różnica między „skacząca strona” i „spokojne wypełnianie się
  treści”. Nasze `media.metadata` (JSONB) może to trzymać bez migracji.
- `UNIQUE (status_id, media_path)` — to samo zdjęcie nie może być dwa razy
  w tym samym wpisie. U nas `post_media` ma `UNIQUE (post_id, position)`,
  czyli pilnujemy kolejności, ale **nie duplikatu**: da się dodać ten sam
  `media_id` na pozycji 0 i 1.

## 3. Przepływy UX warte adaptacji

1. **Archiwizacja zamiast usunięcia** (`status_archiveds`,
   migracja `2018_12_28_012026`). Wpis znika z profilu i feedów, ale zostaje
   u autora i da się go przywrócić. Dla naszej grupy to ważniejsze niż dla
   dwudziestolatków: „usunąłem i już nie ma” jest źródłem realnego stresu.
   U nas `posts` mają `softDeletesTz()`, czyli **techniczna możliwość
   już istnieje** — brakuje przepływu „Schowaj” / „Przywróć” w interfejsie
   i rozróżnienia „schowane przez autora” od „usunięte”.
2. **Ekran uzasadnienia blokujący dalsze korzystanie** (sekcja 2.4).
   W wersji dla nas: pełnoekranowy komunikat po polsku, duży tekst,
   jeden przycisk „Rozumiem” i drugi „Nie zgadzam się — napisz do nas”.
   Bez ikony flagi, bez żargonu.
3. **Album do 4 zdjęć z jawną kolejnością** (`max_album_length`, `media.order`).
   My mamy limit 6 (`config/kuking.php:47`). Warto wiedzieć, że dojrzały
   serwis zdjęciowy zatrzymał się na 4 — przy większej liczbie ludzie wrzucają
   serie zamiast wybierać najlepsze zdjęcie.
4. **`alt_text` dodane migracją dwa razy** (`2018_08_27` i `2018_10_18`) —
   ślad tego, że opis alternatywny dorzucili późno i po omacku. My mamy
   `media.alt_text` od pierwszej migracji, świadomie nieobowiązkowy.
   **Zrealizowane lepiej niż u nich.**

## 4. Przypadki brzegowe bezpieczeństwa i moderacji

To sekcja, dla której warto było klonować to repo.

1. **Orientację EXIF trzeba zastosować PRZED zdjęciem metadanych.**
   `app/Util/Media/Image.php:216` — `$img = $img->orient();` stoi **przed**
   skalowaniem i enkodowaniem. Bez tego zdjęcie z telefonu trzymanego pionowo
   leży na boku, bo informacja o obrocie siedzi w tagu `Orientation`, który
   re-enkodowanie usuwa.
   **U nas tego kroku nie ma**: `app/Jobs/ProcessUploadedImage.php:68-70`
   robi `read()` → `scaleDown()` → `toWebp()`, bez `orient()`.
   Sterownik GD w ogóle nie czyta EXIF-u, więc obrót przepada.
   → **propozycja issue nr 1**, najpoważniejsze znalezisko produktowe:
   pierwsze zdjęcie obiadu wrzucone z telefonu wychodzi obrócone o 90°,
   a użytkownik 50+ nie zgłosi tego jako błędu — po prostu przestanie wrzucać.
   `[do weryfikacji: czy Intervention 3 z driverem GD potrafi orient() bez
   Imagicka — jeśli nie, trzeba czytać exif_read_data() ręcznie przed dekodowaniem]`
2. **Plik oryginalny nie może zostać w storage pod adresem dostępnym publicznie.**
   `Image.php:294` nadpisuje `media_path` przetworzonym plikiem i wywołuje
   `deleteSupersededFile()` — po pipeline'ie nie istnieje żaden plik
   z nieusuniętym EXIF-em.
   **U nas jest odwrotnie**: `ProcessUploadedImage` zapisuje warianty pod
   nowymi kluczami, a `object_key` (surowy plik od użytkownika, z GPS)
   zostaje. I gorzej — `App\Models\Media::url()` (`app/Models/Media.php:80-84`)
   przy braku wariantu **robi fallback dokładnie na `object_key`**:
   ```php
   $key = $this->metadata['variants'][$variant]['key'] ?? $this->object_key;
   ```
   Wystarczy dodać do `config/kuking.php` nowy wariant (np. `xl`) i wszystkie
   starsze zdjęcia zaczną się serwować jako oryginały z EXIF-em i GPS-em.
   To jest wprost sprzeczne z `docs/legal/SECURITY_BASELINE.md:118` i z
   komentarzem w `ProcessUploadedImage`, który deklaruje `exif_stripped => true`.
   → **propozycja issue nr 2.**
3. **HEIC/AVIF trzeba skonwertować, nie tylko przeskalować.**
   `Image.php:255-260` mapuje `heic`/`avif` → JPEG. My akceptujemy
   `image/heic` i `image/heif` (`config/kuking.php:31-36`) i produkujemy warianty
   WebP — dobrze — ale przy fallbacku z punktu 2 przeglądarka dostanie plik
   HEIC, którego nie wyrenderuje. Dwa błędy nakładają się na siebie.
4. **Hash zablokowanego pliku jako obrona przed powrotem spamera** (sekcja 2.5).
   Sami byśmy o tym pomyśleli dopiero po drugim incydencie.
5. **Blokada musi działać także w powiadomieniach i komentarzach, nie tylko
   w feedzie.** `UserFilterService` (`app/Services/UserFilterService.php`) jest
   u nich wołany z wielu miejsc właśnie dlatego, że filtr „tylko w feedzie” jest
   dziurą: zablokowana osoba wciąż komentuje pod naszym wpisem.
   **U nas to jest już zrobione** — sprawdzone w kodzie:
   `app/Domain/Comments/Actions/PublishComment.php:42` i
   `app/Domain/Notifications/Actions/NotifyUser.php:33` wołają
   `User::hasBlockRelationWith()` (`app/Models/User.php:174`).
   `App\Domain\Recipes\Actions\RecordCookedEvent` sprawdza to samo
   (`app/Domain/Recipes/Actions/RecordCookedEvent.php:49`), czyli
   „Ugotowałem” też nie przechodzi przez blokadę. **Cała ta rekomendacja jest
   u nas zrealizowana i to jest dobra wiadomość** — reguła siedzi w akcjach
   domenowych, nie w kontrolerach, więc drugi endpoint jej nie obejdzie.
6. **Walidacja URL-i pod SSRF ma własny test** (`tests/Unit/ActivityPub/SsrfUrlValidationTest.php`).
   Dla nas to wprost dotyczy V2 („import przepisu z adresu”): użytkownik podaje
   URL, serwer go pobiera — czyli mamy klasyczny SSRF, jeśli nie odrzucimy
   `127.0.0.1`, `169.254.169.254`, adresów prywatnych i przekierowań na nie.
   Zapisać jako wymóg do issue o importerze, jeszcze przed napisaniem importera.
7. **Zastrzeżone nazwy użytkownika** (`tests/Unit/Lexer/RestrictedNameTest.php`).
   Nazwa `admin`, `moderator`, `kuking`, `pomoc`, `support` nie może być wolna,
   bo podszycie się pod obsługę serwisu jest najskuteczniejszym phishingiem
   wobec osób starszych. U nas `profiles.username` ma tylko CHECK na format
   (`^[a-zA-Z0-9_]{3,40}$`) — **listy zastrzeżonych nazw nie ma**.
8. **Naiwny klasyfikator bayesowski na spam z ręcznym pretrenowaniem**
   (`app/Jobs/AutospamPipeline/`, `app/Util/Lexer/Classifier.php`) — uczony na
   treściach kont uznanych za spam („spam”) i zdrowych („ham”). Rezultat nigdy
   nie banuje sam, tylko tworzy interstitial typu `post.autospam`.
   Dla nas: dokładnie ta granica z `AGENTS.md` sekcja 9 — „moderacja
   pomocnicza, nigdy samodzielny ban”. **Zbieżność potwierdza naszą decyzję**,
   ale sam klasyfikator to V2, nie MVP.

## 5. Wzorce testowe i jakościowe

- **Osobny katalog `tests/Feature/Security/`** (`AdminAccessTest.php`,
  `ApiScopeSecurityTest.php`). U nas jest jeden `tests/Feature/SecurityTest.php` —
  przy rosnącej liczbie reguł katalog skaluje się lepiej niż jeden plik.
- **Testy jednostkowe na parsery i lexery** (`tests/Unit/Lexer/UsernameTest.php`,
  `StatusLexerTest.php`, `RestrictedNameTest.php`, `PurifierTest.php`).
  Wspólna cecha: to są testy na **czyste funkcje tekstowe**, bez bazy — więc
  są szybkie i można ich mieć setki. Nam odpowiadają: normalizacja nazwy
  składnika (`App\Models\Ingredient::normalized_name`), parsowanie
  `ingredient_text`, slug przepisu (`App\Domain\Recipes\Actions\GenerateRecipeSlug`),
  a te dziś testów jednostkowych nie mają.
- **`$deleteWhenMissingModels = true`** na jobach
  (`app/Jobs/ImageOptimizePipeline/ImageResize.php:25`) — job dotyczący
  usuniętego rekordu kasuje się sam, zamiast trzy razy wybuchnąć i wylądować
  w `failed_jobs`. Nasz `ProcessUploadedImage` obsługuje `Media::find() === null`
  ręcznie (`app/Jobs/ProcessUploadedImage.php:47`), co jest równoważne, ale
  **nasz job w bloku `catch` robi `throw $e` po ustawieniu statusu `rejected`**
  — czyli przy trwałym błędzie (np. plik nie jest obrazem) job będzie
  ponawiany 3 razy, a status i tak jest już finalny. Warto rozdzielić błędy
  trwałe (`fail()`, bez retry) od przejściowych (`release()`).
- **Larastan + Psalm + Pint jednocześnie** (`larastan/`, `psalm.xml`,
  `psalm-baseline.xml`, `pint.json`). Mają baseline zamiast czystego wyniku —
  dojrzałe podejście do długu: nowy kod musi być czysty, stary nie blokuje.
  U nas dziś tylko Pint (`scripts/check.sh`). Larastan na `app/Domain`
  byłby tani i wyłapałby literówki w nazwach relacji.

## 6. Wzorce wydajnościowe

- **Cache'owanie pustego wyniku jawnym sentynelem.**
  `UserFilterService::EMPTY_SENTINEL = '-1'` — użytkownik bez blokad też ma
  wpis w cache, więc nie odpytuje bazy przy każdym żądaniu. Bez tego
  „brak wyniku” jest nieodróżnialny od „nie ma w cache” i konto bez blokad
  (czyli większość) uderza w bazę zawsze.
  Dla nas do zastosowania w `App\Domain\Feed\DiscoverFeed::hiddenAuthorIdsFor()`
  (`app/Domain/Feed/DiscoverFeed.php:70-77`), które dziś robi dwa zapytania
  przy każdym wejściu na stronę główną, także dla osób bez żadnej blokady.
  Uwaga: u nich to Redis; u nas wystarczy `Cache` na sterowniku `database`.
- **Liczniki jako kolumny, nie `COUNT(*)`.** `statuses.replies_count`
  (migracja `2019_03_31_191216`), `status_views` jako osobna tabela.
  Nasz `FollowingFeed` robi `withCount('comments')`
  (`app/Domain/Feed/FollowingFeed.php:46`) — dla 15 wpisów na stronę to
  15 podzapytań. Do zmierzenia, ale to pierwszy kandydat, gdy feed zwolni.
- **Historia indeksów jako lekcja**: `2022_10_07_055133_remove_old_compound_index_from_statuses_table.php`
  i cała seria `add_*_index` z tego samego dnia. Osiem lat po starcie serwisu
  poprawiali indeksy pod realne zapytania. Wniosek: nie zgadywać indeksów
  z góry, ale mieć `whenQueryingForLongerThan` (patrz notatka o laravel.io),
  żeby wiedzieć, które dodać.
- **Fanout-on-write feedu** (`app/Jobs/HomeFeedPipeline/` — 11 jobów:
  `FeedInsertPipeline`, `FeedFollowPipeline`, `FeedUnfollowPipeline`,
  `FeedWarmCachePipeline`…). To jest dokładnie ta złożoność, której
  `AGENTS.md` sekcja 8 zabrania w MVP, i widać dlaczego: każda operacja
  społeczna (follow, unfollow, blokada domeny, usunięcie wpisu) wymaga
  własnego joba naprawiającego materializowane feedy. **Potwierdzenie naszej
  decyzji, nie inspiracja.**

## 7. Czego świadomie nie przenosić

| Rzecz | Dlaczego nie |
|---|---|
| ActivityPub i federacja (`app/Services/Federation/`, ~40% repo) | Nie jesteśmy w Fediverse; wnosi SSRF, weryfikację podpisów, deduplikację obiektów i moderację treści z cudzych instancji |
| Fanout-on-write (`app/Jobs/HomeFeedPipeline/`, 11 jobów) | `AGENTS.md` sekcja 8; przy naszej skali proste `WHERE author_id IN (...)` wystarczy i jest o rząd łatwiejsze w utrzymaniu |
| **Shadow filtering** (`app/Services/AdminShadowFilterService.php`) — ciche ukrywanie konta z publicznych feedów bez informowania go | Sprzeczne z DSA art. 17 i z naszą zasadą „jasny powód decyzji” (`docs/MODERATION.md`). Użytkownik, który nie wie, że jest ograniczony, nie może się odwołać. **REJECT bez dyskusji** |
| `media.skip_optimize` | Każdy plik musi być re-enkodowany, inaczej EXIF zostaje (`docs/legal/SECURITY_BASELINE.md:118`) |
| Redis jako warunek działania (`UserFilterService` woła `Redis::` bezpośrednio) | `AGENTS.md` sekcja 3: bez Redisa do pomiaru. Ich kod nie ma ścieżki bez Redisa — to długu technicznego przykład, nie wzorzec |
| Stories, live streaming, DM-y, reblogi | Poza MVP i poza produktem (`AGENTS.md` sekcja 12) |
| `is_nsfw` / content warning jako centralny mechanizm | Serwis o gotowaniu; jeden powód zgłoszenia w `reports.reason` wystarczy |
| Cztery boolean-y w `reports` obok `type` | Nasze `reason` z listą wartości jest czystsze i już działa |
| `int` auto-increment jako ID publiczne | Mamy UUID; ich `/p/username/12345` pozwala policzyć wpisy w serwisie |

## 8. Rekomendacje dla Kuking

| # | Rekomendacja | Nasz plik / tabela | Waga |
|---|---|---|---|
| R1 | **`$image->orient()` (albo ręczne czytanie `Orientation` z EXIF) przed skalowaniem** | `app/Jobs/ProcessUploadedImage.php:68-70` | **P0 — psuje pierwsze zdjęcie nowego użytkownika** |
| R2 | **Usunąć fallback `?? $this->object_key` z `Media::url()`**; brak wariantu = nie renderujemy zdjęcia. Osobno: usuwać oryginał po wygenerowaniu wariantów albo trzymać go na dysku bez publicznego URL-a | `app/Models/Media.php:80-84`, `app/Jobs/ProcessUploadedImage.php`, `docs/legal/SECURITY_BASELINE.md:118` | **P0 — wyciek GPS z EXIF** |
| R3 | Tabela `moderation_notices` (uzasadnienie decyzji + `read_at` + `appeal_message` + `appeal_requested_at` + `appeal_handled_at`) i zdenormalizowany `users.has_pending_notice` z indeksem | nowa migracja, `docs/DATABASE.md`, `docs/legal/MODERATION_PLAYBOOK.md:73-79`, `docs/legal/COMPLIANCE.md:249` | P1 — obowiązek z art. 17 DSA, dziś bez tabeli |
| R4 | `moderation_actions`: dopuścić decyzję automatu (`moderator_id` nullable + `is_automated boolean`), żeby dało się spełnić wymóg „czy decyzja była zautomatyzowana” | `database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php`, `docs/legal/COMPLIANCE.md:23` | P1 |
| R5 | `reports.first_seen_at` (moment pierwszego otwarcia przez moderatora) do liczenia SLA | `database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php`, `docs/legal/MODERATION_PLAYBOOK.md` sekcja 3 | P1 |
| R6 | Tabela `media_blocklists` (`checksum_sha256` unique, `reason`, `active`) + sprawdzenie w `StoreUploadedImage`. Bazę mamy: `media.checksum_sha256` z indeksem | `app/Domain/Media/Actions/StoreUploadedImage.php`, `database/migrations/2026_09_05_000100_create_media_table.php` | P1 przed publiczną betą |
| R7 | Wyciszenie konta („Ukryj wpisy tej osoby”) obok blokady — `blocks.type` albo nowa tabela `mutes` | `database/migrations/2026_09_05_000300_create_follows_and_blocks_tables.php`, `app/Domain/Social/Actions/`, `docs/MODERATION.md` | P1 — blokada jest zbyt mocnym gestem społecznym w małej społeczności |
| R8 | Lista zastrzeżonych nazw użytkownika (`admin`, `moderator`, `kuking`, `pomoc`, `kontakt`, `regulamin`…) + test jednostkowy | `app/Http/Controllers/OnboardingController.php`, `config/kuking.php`, CHECK na `profiles.username` | P1 — obrona przed podszywaniem się pod obsługę |
| R9 | `UNIQUE (post_id, media_id)` obok istniejącego `UNIQUE (post_id, position)` | `database/migrations/2026_09_05_000500_create_posts_tables.php` | P2 |
| R10 | Blurhash (albo dominujący kolor) w `media.metadata` jako placeholder — bez migracji | `app/Jobs/ProcessUploadedImage.php`, `docs/MEDIA_PIPELINE.md` | P2 — realny zysk na wolnym łączu |
| R11 | Przepływ „Schowaj wpis” / „Przywróć” dla autora, oparty na istniejącym soft delete, odróżniony od usunięcia przez moderatora | `app/Http/Controllers/PostController.php`, `posts.deleted_at`, `docs/FLOWS_AND_SCREENS.md` | P2 |
| R12 | Cache listy zablokowanych z jawnym sentynelem pustki | `app/Domain/Feed/DiscoverFeed.php:70-77` | P2 — po pomiarze |
| R13 | Wymóg walidacji SSRF wpisany do issue o imporcie z URL, **przed** napisaniem importera | `docs/ROADMAP.md` (V2), przyszły `app/Domain/Recipes/Import/` | P2 (V2), ale zapisać teraz |
| R14 | Testy jednostkowe na funkcje tekstowe: normalizacja składnika, slug przepisu, parsowanie `ingredient_text` | `tests/Unit/`, `app/Domain/Recipes/Actions/GenerateRecipeSlug.php`, `app/Models/Ingredient.php` | P2 |
| R15 | Rozdzielić w `ProcessUploadedImage` błąd trwały (`fail()`, bez retry) od przejściowego (`release()`) | `app/Jobs/ProcessUploadedImage.php:88-105` | P2 |

### Propozycje issues (nie naprawiam, zgodnie z zakresem)

**Issue 1 — „Zdjęcia z telefonu publikują się obrócone”.**
`app/Jobs/ProcessUploadedImage.php:68-70` nie stosuje orientacji EXIF przed
re-enkodowaniem, a sterownik GD nie czyta EXIF-u wcale. Zdjęcie zrobione
telefonem w pionie ląduje w feedzie na boku. Kryterium akceptacji: test
z plikiem JPEG mającym `Orientation = 6`, w którym wariant `feed` ma
`height > width`.

**Issue 2 — „`Media::url()` może serwować oryginał z EXIF/GPS”.**
`app/Models/Media.php:80-84` przy braku wariantu w `metadata` zwraca URL do
`object_key`, czyli do surowego pliku od użytkownika. Wystarczy dodać nowy
wariant w `config/kuking.php` albo mieć rekord z uciętym `metadata`, żeby
zaczęły się serwować oryginały z lokalizacją GPS — wprost przeciwnie do
`docs/legal/SECURITY_BASELINE.md:118` i do deklaracji `exif_stripped => true`
zapisywanej przez `ProcessUploadedImage`. Kryteria akceptacji: (1) brak
wariantu → widok nie renderuje zdjęcia (traktujemy jak `pending`);
(2) test, że `url()` nigdy nie zwraca `object_key`; (3) decyzja, czy oryginał
w ogóle zostaje w storage — jeśli tak, to na dysku bez publicznego dostępu.

**Issue 3 — „Ten sam plik można dodać dwa razy do jednego wpisu”.**
`post_media` ma `UNIQUE (post_id, position)`, ale nie `UNIQUE (post_id, media_id)`
(`database/migrations/2026_09_05_000500_create_posts_tables.php`). To samo
dotyczy `cooked_event_media`. Drobne, ale to jedna linijka w migracji i jeden
test — a bez tego w albumie może pojawić się to samo zdjęcie dwa razy.
