# fresns/fresns — notatka researchowa

**Licencja: Apache-2.0** (`LICENSE`). Najbardziej liberalna z całej Fazy 1:
wolno używać, modyfikować i wydawać w zamkniętym produkcie. Warunki, które
realnie nas dotyczą: (1) zachować notę o prawach autorskich i tekst licencji
dla przeniesionych fragmentów, (2) oznaczyć pliki, które zmieniliśmy,
(3) Apache-2.0 zawiera **jawny grant patentowy** — to plus, bo GPL/AGPL takiej
klarowności nie dają. Praktycznie: kod wolno kopiować, ale **nie będziemy**,
bo Fresns jest zbudowany wokół założeń (wtyczki, wielojęzyczność w JSONB,
konfiguracja w bazie), które są sprzeczne z `AGENTS.md` sekcja 3.

Snapshot: `git clone --depth 1` z 2026-09-05.

---

## 1. Co wynika z licencji

- Kopiowanie kodu dozwolone (inaczej niż Pixelfed/Tandoor/Mealie).
- Gdybyśmy jednak coś przenieśli: nota copyright + informacja o modyfikacji
  w nagłówku pliku, plus wpis w „Third-party notices”.
- Wniosek praktyczny: to jedyne repo z Fazy 1, gdzie na pytanie „możemy wziąć
  ten helper?” odpowiedź jest „tak, technicznie możemy” — ale w każdym
  sprawdzonym przypadku odpowiedź merytoryczna była „nie chcemy”.
  Powód opisany w sekcji 7.

## 2. Użyteczny model danych

### 2.1 Rola z terminem ważności i rolą powrotną

`database/migrations/0001_01_01_000000_create_users_table.php:146-156`:

```
user_roles: user_id, role_id, is_main,
            expired_at,           -- kiedy rola wygasa
            restore_role_id       -- do jakiej roli wtedy wracamy
```

To jest **najlepszy pomysł w całym repozytorium** i odpowiada na problem,
którego u nas nie ma rozwiązanego. `docs/legal/MODERATION_PLAYBOOK.md:34`
przewiduje „blokadę czasową 7 dni” i „blokadę czasową 30 dni”, a nasza baza
ma tylko `users.status` z CHECK-iem `('active','suspended','banned','pending_delete')`
(`database/migrations/0001_01_01_000001_create_users_table.php:57`).
Nie ma kolumny mówiącej, **kiedy zawieszenie się kończy** — czyli zawieszenie
na 7 dni musi ktoś odklikać ręcznie po tygodniu, a jeśli zapomni, kara trwa
bez końca. To nie jest hipotetyczne: przy dwóch osobach w zespole
(`docs/legal/MODERATION_PLAYBOOK.md:71`) to się stanie.

Dla nas przekłada się to na `users.status_expires_at` (+ komenda `artisan`
przywracająca konta, których termin minął) — bez tabeli ról, bez pivotu.

### 2.2 Blokada rzeczy, nie tylko osoby

`user_likes` z `mark_type` (like / dislike / follow / block) i `like_type`
(user / group / hashtag / geotag / post / comment) —
jedna tabela na wszystkie relacje „ja ↔ coś”. Trzy indeksy, każdy
z komentarzem mówiącym, do jakiego zapytania służy:

```php
$table->index(['mark_type','like_type','like_id'], 'user_like_users');    // kto to polubił
$table->index(['user_id','mark_type','like_type'], 'user_like_contents'); // co polubiłem
$table->unique(['user_id','like_type','like_id'], 'user_like_id');
```

Dwie rzeczy warte przeniesienia:

1. **Komentarz przy indeksie mówiący, jakie zapytanie on obsługuje.** Nasze
   migracje mają obszerne komentarze do kolumn i decyzji (`media`, `cooked_events`),
   ale indeksy stoją bez wyjaśnienia — np. `comments_author_idx`
   (`database/migrations/2026_09_05_000700_create_comments_table.php:53`).
   Za rok nikt nie będzie wiedział, czy wolno go usunąć.
2. **Możliwość zablokowania nie-osoby.** U nas blokada dotyczy wyłącznie
   konta. „Nie pokazuj mi już wpisów z tagiem *podroby*” jest dla części
   użytkowników ważniejsze niż blokowanie ludzi — i tańsze społecznie.
   To V1, nie MVP, ale kształt `blocks` warto zaprojektować tak, żeby dało się
   to dodać bez przebudowy (dziś `blocks` ma sztywne `blocker_id`/`blocked_id`).

### 2.3 Polityka komentowania jako pole użytkownika i wpisu

`users.comment_policy` + `posts.permissions` JSONB
(`app/Utilities/PermissionUtility.php:660-720`). Poziomy: wszyscy /
osoby, które obserwuję / obserwowani lub zweryfikowani / tylko wspomniani /
nikt. Rozstrzyganie konfliktu: domyślna polityka konta wygrywa nad ustawieniem
pojedynczego wpisu, chyba że konto ma „wszyscy”.

Dla Kuking to jest realnie potrzebne, i to właśnie dla naszej grupy:
osoba 50+, której pierwszy wpis dostanie 40 komentarzy, częściej się wycofuje,
niż cieszy. „Kto może komentować moje wpisy: wszyscy / osoby, które obserwuję /
nikt” to jedno pole w `profiles` i jeden warunek w
`App\Domain\Comments\Actions\PublishComment`. **Ale**: ich sposób rozstrzygania
konfliktu (konto kontra wpis) jest zagmatwany. Prostsza reguła dla nas:
**wygrywa ustawienie bardziej restrykcyjne**, bez wyjątków.

### 2.4 Wyróżnienie treści jako stan, nie ranking

`posts.digest_state` + `posts.digested_at`, `groups.post_digest_count`.
„Digest” = treść wybrana ręcznie przez redakcję/moderatora jako wartościowa.
To odpowiada na problem, który mamy zapisany w `AGENTS.md` sekcja 12: nie
chcemy algorytmicznego feedu ani publicznych rankingów, ale chcemy pokazywać
dobre rzeczy nowym użytkownikom (`docs/product/COLD_START.md`,
`App\Domain\Feed\DiscoverFeed`).

Ręczne wyróżnienie jest wyjściem: nie jest algorytmem, nie tworzy rankingu
osób, jest jawną decyzją człowieka. Dziś nasz `DiscoverFeed::paginate()`
(`app/Domain/Feed/DiscoverFeed.php:31-41`) daje czystą chronologię, a
`suggestedPeople()` sortuje po `posts_count`, czyli premiuje ilość, nie jakość
— to jest zalążek dokładnie tego rankingu, którego nie chcemy.

Kolumna `recipes.featured_at` (albo osobna tabela `featured_content`) jest
tańsza i bardziej zgodna z produktem.

### 2.5 Znaczniki czasu ostatniej zmiany tożsamości

`users.last_username_at`, `users.last_nickname_at`.
Służą do limitu „nazwę można zmienić raz na N dni”. Sens jest
antyoszukańczy: konto buduje zaufanie pod jedną nazwą, a potem zmienia ją na
podobną do cudzej. Dla grupy 50+ podszywanie się jest realnym wektorem
(`docs/legal/MODERATION_PLAYBOOK.md:35`).

U nas `profiles.username` jest unikalny z CHECK-iem na format, ale:
- brak limitu częstotliwości zmian,
- brak historii poprzednich nazw,
- brak przekierowania ze starego adresu profilu — mimo że dla przepisów
  mamy `recipe_slug_redirects` (świetne rozwiązanie), a dla profili nic.
  Ktoś, kto zmieni nazwę, zabija wszystkie linki do swojego profilu.

### 2.6 Osobna tabela na liczniki użytkownika

`user_stats` (1:1 z `users`, ~30 kolumn licznikowych,
`create_users_table.php:77`). Motyw: aktualizacja licznika to zapis, a zapis
w wiersz `users` blokuje wiersz używany przy każdym żądaniu.
Wydzielenie liczników trzyma „gorące” zapisy poza tabelą konta.

Nam to jeszcze nie jest potrzebne (nie mamy żadnych liczników na koncie),
ale gdy dojdzie „ile razy ugotowano moje przepisy”, to jest właściwy kształt:
`user_stats`, nie kolumny w `users`.

## 3. Przepływy UX warte adaptacji

1. **Rozdzielenie konta od tożsamości** (`accounts` = e-mail/telefon/hasło,
   `users` = nick, avatar, bio; jedno konto może mieć wiele tożsamości).
   Wielotożsamościowość odrzucamy (sekcja 7), ale **sam rozdział potwierdza
   naszą decyzję**: `users` (logowanie) + `profiles` (tożsamość) w osobnych
   tabelach, dokładnie jak w komentarzu w
   `database/migrations/0001_01_01_000001_create_users_table.php:16-18`.
2. **Grupa z osobnym `privacy` i `visibility`**
   (`create_groups_table.php`): kto może wejść **i** czy grupa jest widoczna
   na liście. To dwie różne rzeczy — grupa może być widoczna i zamknięta
   („poproś o dostęp”) albo niewidoczna i otwarta (wejście z linku).
   Dla przyszłych Grup/Fotoforów w Kuking (V1) trzeba to rozdzielić od
   początku, bo scalenie w jedno pole zawsze kończy się migracją.
3. **`private_end_after`** — treść w grupie prywatnej staje się publiczna po
   N dniach. Ciekawe, ale dla nas to pułapka prywatności: użytkownik nie
   przewidzi konsekwencji. **Nie przenosić** (sekcja 7).
4. **Wpis anonimowy** (`posts.is_anonymous`, `notifications.action_is_anonymous`).
   Godne uwagi jest to, że anonimowość jest **przenoszona do powiadomienia** —
   inaczej autor wpisu dowiedziałby się z powiadomienia, kto to był. Klasyczny
   wyciek, o którym łatwo zapomnieć. Dla Kuking anonimowość nie jest w MVP,
   ale gdyby kiedyś była (pytania typu „wstyd się przyznać, ale nie wiem jak
   ugotować ryż”), to ta pułapka jest zapisana.

## 4. Przypadki brzegowe bezpieczeństwa i moderacji

1. **Anonimowość musi być spójna we wszystkich warstwach** — patrz punkt 3.4.
   Model danych, w którym `posts.is_anonymous` jest jedynym miejscem
   z tą informacją, przecieka przez powiadomienia, e-maile i eksport danych.
2. **Zakaz usuwania treści wyróżnionej lub przypiętej**
   (`PermissionUtility::checkContentIsCanDelete`, `app/Utilities/PermissionUtility.php:723-740`).
   Ich rozwiązanie: autor nie może usunąć wpisu, który redakcja wyróżniła.
   **Dla nas to jest antywzorzec** — użytkownik ma prawo usunąć swoją treść
   i tego nie ograniczamy. Ale problem jest realny: jeśli wyróżnimy przepis na
   stronie głównej, a autor go usunie, zostaje dziura.
   Nasze rozwiązanie powinno być odwrotne: **usunięcie jest zawsze dozwolone,
   a wyróżnienie sprawdza przy renderowaniu, czy treść jeszcze istnieje**.
3. **Powiadomienie przeżywające usuniętą treść.**
   Fresns trzyma w `notifications` zdenormalizowane `action_type`,
   `action_target`, `action_id`, `action_content_id` — czyli da się zapytać
   „usuń wszystkie powiadomienia dotyczące tego wpisu” jednym `DELETE`.
   U nas `notifications.data` to JSONB (`database/migrations/2026_09_05_000900_create_notifications_table.php`)
   bez indeksu GIN, więc takie zapytanie oznacza skan całej tabeli.
   Skutek praktyczny: moderator usuwa obraźliwy komentarz, a autor wpisu wciąż
   ma powiadomienie „Ktoś skomentował Twój przepis” prowadzące w pustkę —
   albo, gorzej, powiadomienie o „Ugotowałem” od konta, które właśnie
   zbanowaliśmy za spam. To jest przypadek brzegowy, którego sami byśmy
   nie wymyślili przed pierwszym incydentem.
4. **`users.expired_at` na koncie** — konto tymczasowe/testowe wygasa samo.
   Dla nas nieistotne, ale ten sam mechanizm rozwiązuje nasze zawieszenie
   czasowe (sekcja 2.1).
5. **Uprawnienia policzone w jednym miejscu.**
   `app/Utilities/PermissionUtility.php` to ~750 linii wszystkich reguł
   „czy wolno” w jednym pliku. Trudny w utrzymaniu, ale ma jedną zaletę,
   którą warto docenić: **nie da się obejść reguły, dodając drugi endpoint**.
   To ten sam cel, który u nas realizują Policies + akcje w `app/Domain`
   (`AGENTS.md` sekcja 4). Nasza wersja jest lepsza (małe, testowalne klasy),
   ale ich plik pokazuje, jak wygląda alternatywa i dlaczego jej nie chcemy.
6. **Weryfikacja tożsamości w oddzielnych kolumnach**
   (`accounts.verify_real_name`, `verify_cert_type`, `verify_cert_number`,
   `verify_log`) — dane dokumentu tożsamości **w tej samej tabeli co e-mail
   i hasło**, w postaci jawnej. Z punktu widzenia RODO to jest wzorzec
   negatywny i warto go zapisać jako przestrogę: nasz
   `docs/legal/MODERATION_PLAYBOOK.md:35` wspomina o „prośbie o dowód
   tożsamości” przy podszywaniu się. Jeśli kiedykolwiek to zrobimy,
   dokumentu **nie zapisujemy w bazie** — moderator go oglądą i odrzuca,
   a w bazie zostaje wyłącznie `verified_at` i `moderator_id`.

## 5. Wzorce testowe i jakościowe

**W repozytorium nie ma katalogu `tests/`.** Fresns nie ma testów
automatycznych w publicznym repo — przy 23 migracjach tworzących ~60 tabel
i pliku uprawnień na 750 linii. To sama w sobie jest informacja:

- Nie da się na tym wzorować, ale da się zobaczyć skutek. Kod jest pełen
  `?? Config::get(...)`, konfiguracji czytanej z bazy w środku reguł
  biznesowych i kodów błędów jako liczb (`38209`, `38211` w
  `PermissionUtility.php`). To jest kształt, jaki przyjmuje projekt, w którym
  nie ma testów wymuszających prostotę: każda reguła jest konfigurowalna,
  bo nie ma jak jej przetestować, więc łatwiej ją oddać użytkownikowi.
- **Wniosek dla nas, wprost do `AGENTS.md` sekcja 10**: wymóg „bugfix zawiera
  test regresyjny” chroni nie tylko przed regresją, ale też przed
  konfigurowalnością na zapas. Rzecz, którą trzeba przetestować, projektuje
  się prościej.
- Kody błędów jako liczby (38109, 38209…) to dokładnie to, czego zabrania
  `docs/UX_50_PLUS.md` („błędy po polsku, mówiące co zrobić, nie
  »422 Unprocessable Entity«”). Tu widać, skąd się takie komunikaty biorą:
  z i18n przez klucze numeryczne.

## 6. Wzorce wydajnościowe

- **Liczniki w kolumnach zamiast `COUNT(*)`** — konsekwentnie:
  `posts.comment_count`, `groups.post_count`, `user_stats.*`,
  plus `last_post_at` / `last_comment_at` do sortowania bez joina.
  Nasz `DiscoverFeed::suggestedPeople()` (`app/Domain/Feed/DiscoverFeed.php:56-64`)
  robi `withCount('posts')` + `whereHas('posts')` + eager load trzech ostatnich
  wpisów z mediami — na ekranie, który widzi **każdy nowy użytkownik**.
  To jest pierwszy kandydat do pomiaru; `profiles.last_post_at` (jedna kolumna
  aktualizowana w `PublishPost`) usuwałby `whereHas` i `withCount` naraz.
- **Osobna tabela liczników** (`user_stats`) — patrz 2.6.
- **Denormalizacja URL-a pliku obok jego ID** (`avatar_file_id` +
  `avatar_file_url`, tak samo dla okładek grup i ról). Cel: wyrenderowanie
  awatara bez joina do `files`. U nas `profiles.avatar_media_id` wymaga joina
  do `media` i odczytu `metadata->variants->thumb->key`
  (`app/Models/Media.php:80-84`) — czyli na liście 15 wpisów w feedzie jest to
  15 razy eager-loadowana relacja `author.profile.avatar`
  (`app/Domain/Feed/FollowingFeed.php:41`). Denormalizacja rozwiązuje to
  kosztem spójności (URL trzeba odświeżać przy zmianie awatara).
  **Do pomiaru, nie do wdrożenia z góry** — ale warto wiedzieć, że to jest
  standardowe wyjście przy feedzie.
- **Klucz publiczny osobno od klucza głównego** (`pid`, `uid`, `gid`, `nmid`,
  `rid` — krótkie stringi obok `bigIncrements`). Ich powód: publiczne ID nie
  ujawnia liczby rekordów, a klucz główny zostaje liczbą (mniejsze indeksy,
  szybsze joiny). To jest realna alternatywa dla naszego UUID jako klucza
  głównego. My wybraliśmy UUID (`docs/DATABASE.md`), co jest w porządku, ale
  **jeśli kiedyś okaże się, że indeksy na UUID bolą** (losowe UUID v4 psują
  lokalność wstawiania w B-drzewie), to jest droga wyjścia:
  `bigserial` wewnętrznie + UUID publicznie. `[do weryfikacji przy pomiarze —
  przy naszej skali to nie problem, PostgreSQL 18 radzi sobie z UUID dobrze]`

## 7. Czego świadomie nie przenosić

| Rzecz | Dlaczego nie |
|---|---|
| **Architektura wtyczkowa** (`plugins/`, `themes/`, `app_fskey` w kilkunastu tabelach) | Kuking to jeden produkt, nie platforma. `app_fskey` w każdej tabeli to kolumna, której nigdy nie użyjemy, w każdym indeksie i każdym zapytaniu |
| **Konfiguracja produktu w bazie** (`configs`, `ConfigHelper::fresnsConfigByItemKeys()` wołane wewnątrz reguł uprawnień) | Nasze progi żyją w `config/kuking.php` — wersjonowane w gicie, testowalne, widoczne w code review. Konfiguracja w bazie oznacza, że nie da się odtworzyć decyzji z historii repo |
| **`archives` / `extends` — definicje pól formularzy w bazie** (`form_type`, `form_options`, `is_required`, `input_pattern`, `input_min/max`) | To EAV. Przenosi walidację z kodu (testowalna) do wierszy w bazie (nietestowalna). Wprost zabronione przez `AGENTS.md` sekcja 3 |
| **Wielojęzyczność jako JSONB w każdej nazwie** (`roles.name`, `groups.name`, `groups.description` jako JSON) | Kuking jest po polsku (`AGENTS.md` sekcja 11). JSONB w `name` uniemożliwia `UNIQUE`, sensowny indeks i `ORDER BY name` |
| **Stany jako `unsignedTinyInteger`** (`sticky_state`, `digest_state`, `rank_state`, `privacy`, `visibility`, `gender`) | To samo co u Pixelfeda: `digest_state = 2` jest nieczytelne w `psql`. Nasze CHECK-i ze stringami są lepsze |
| **Wiele tożsamości na jedno konto** (`accounts` 1:N `users`) | Moderacyjny koszmar (ten sam człowiek pod trzema nickami w jednym wątku) i niezrozumiałe dla naszej grupy |
| **`dislike_count`** we wszystkich tabelach | Kuking nie ma i nie będzie miał „nie lubię”. Publiczne liczniki negatywne przy przepisie rodzinnym to najkrótsza droga do wyłączenia publikowania |
| **`private_end_after`** — treść prywatna publikowana automatycznie po N dniach | Użytkownik nie przewidzi konsekwencji; to pułapka prywatności, nie funkcja |
| **`conversations` / `conversation_messages`** (wiadomości prywatne) | Poza MVP (`AGENTS.md` sekcja 12), a moderacyjnie najdroższa funkcja w każdym serwisie społecznościowym |
| **Kody błędów jako liczby** (38109, 38209…) | `docs/UX_50_PLUS.md`: błąd mówi po polsku, co zrobić |
| **Dane dokumentu tożsamości w tabeli konta** | RODO; patrz 4.6 |

## 8. Rekomendacje dla Kuking

| # | Rekomendacja | Nasz plik / tabela | Waga |
|---|---|---|---|
| R1 | `users.status_expires_at` + komenda `artisan kuking:przywroc-konta` przywracająca konta po terminie. Bez tego „blokada czasowa 7 dni” z playbooka jest w praktyce blokadą na zawsze | `database/migrations/0001_01_01_000001_create_users_table.php`, `docs/legal/MODERATION_PLAYBOOK.md:34`, `routes/console.php`, `docs/DATABASE.md` | **P1 — funkcja opisana w dokumentach, nieistniejąca w bazie** |
| R2 | Usuwanie powiadomień wskazujących na treść usuniętą/ukrytą przez moderację (i decyzja: kolumny `subject_type`/`subject_id` w `notifications` albo indeks GIN na `data`) | `database/migrations/2026_09_05_000900_create_notifications_table.php`, `app/Domain/Moderation/`, `app/Models/Notification.php` | P1 — dziś powiadomienie prowadzi w pustkę albo do treści zbanowanego konta |
| R3 | `profiles.comment_policy` (`wszyscy` / `tylko obserwowani` / `nikt`) sprawdzane w akcji publikowania komentarza; przy konflikcie z ustawieniem wpisu wygrywa bardziej restrykcyjne | `app/Domain/Comments/Actions/PublishComment.php`, `profiles`, `docs/UX_50_PLUS.md` | P1 dla naszej grupy — brak tego ustawienia zniechęca do publikowania |
| R4 | Wyróżnienie treści jako jawna decyzja człowieka (`recipes.featured_at` / `posts.featured_at`) zamiast sortowania `DiscoverFeed::suggestedPeople()` po `posts_count` | `app/Domain/Feed/DiscoverFeed.php:56-64`, `docs/product/COLD_START.md`, `AGENTS.md` sekcja 12 | P1 — dzisiejsze sortowanie po liczbie wpisów to zalążek rankingu, którego nie chcemy |
| R5 | Limit częstotliwości zmiany nazwy (`profiles.username_changed_at`) + `profile_slug_redirects` na wzór działającego `recipe_slug_redirects` | `database/migrations/2026_09_05_000200_create_profiles_table.php`, `database/migrations/2026_09_05_000400_create_recipes_tables.php` (wzór) | P2 — obrona przed podszywaniem się i przed zabiciem linków do profilu |
| R6 | Komentarz przy każdym indeksie w migracjach: jakie zapytanie obsługuje | wszystkie migracje w `database/migrations/`, `AGENTS.md` sekcja 6 | P2 — tanie, a decyduje o tym, czy za rok wolno indeks usunąć |
| R7 | `profiles.last_post_at` aktualizowane w `PublishPost`, żeby propozycje osób nie potrzebowały `whereHas` + `withCount` | `app/Domain/Posts/Actions/PublishPost.php`, `app/Domain/Feed/DiscoverFeed.php:56-64` | P2 — po pomiarze |
| R8 | Zaprojektować `blocks` tak, żeby dało się później dodać wyciszenie tagu/kategorii, nie tylko konta (spójne z rekomendacją R7 z notatki o Pixelfedzie) | `database/migrations/2026_09_05_000300_create_follows_and_blocks_tables.php` | LATER (V1) — dziś tylko notatka projektowa, bez migracji |
| R9 | Zasada zapisana w `docs/MODERATION.md`: autor **zawsze** może usunąć swoją treść, także wyróżnioną; to widok wyróżnień sprawdza istnienie treści, nie odwrotnie | `docs/MODERATION.md`, przyszły widok wyróżnień | P2 — decyzja produktowa, tania teraz, droga po fakcie |
| R10 | Zasada w `docs/legal/MODERATION_PLAYBOOK.md`: przy weryfikacji tożsamości dokument nie jest zapisywany — w bazie zostaje tylko `verified_at` i kto weryfikował | `docs/legal/MODERATION_PLAYBOOK.md:35`, `docs/legal/COMPLIANCE.md` | P2 |

### Uwaga metodologiczna

Fresns był w Fazie 1 jako „ogólny framework społecznościowy, przydatny przy
przyszłych Grupach”. Po przejrzeniu: **wartość jest w dwóch pojedynczych
pomysłach** (rola/status z terminem ważności, polityka komentowania) i w dużej
liczbie wzorców negatywnych. Jako źródło architektury nie nadaje się — jest
platformą z wtyczkami, a Kuking jest produktem. Przy pracach nad Grupami (V1)
warto wrócić do jednej rzeczy: rozdziału `privacy` od `visibility` w grupie
(sekcja 3.2). Poza tym repozytorium można zamknąć.
