# Analityka — definicja WAC, zdarzenia, wdrożenie

> Agent 4, audyt repozytorium. Odpowiada na issue #7. Nie duplikuje #33
> (monitoring/Sentry/PostHog na infrastrukturze) — to jest warstwa produktowa:
> co mierzymy i po co, nie jak podłączyć konto PostHog.

## 0. Stan wyjściowy — sprawdzone, nie założone

Zgrepowałem `posthog`, `analytics`, `track(`, `event(`, `gtag`, `plausible`,
`matomo`, `mixpanel` po `app/`, `resources/`, `config/`, `composer.json`,
`package.json`. Wynik: **zero trafień** poza dwoma wywołaniami frameworkowego
`event()` Laravela (`Registered`, `PasswordReset` —
`app/Http/Controllers/Auth/RegisterController.php:124`,
`app/Http/Controllers/Auth/PasswordResetController.php:90`), które nie mają nic
wspólnego z analityką produktową. **W kodzie nie ma dziś żadnej analityki.**
`.env`/`config` też nie mają klucza PostHog. Potwierdzone, nie na słowo.

**Ważniejsze odkrycie:** `docs/seo/ANALYTICS.md` **już istnieje** i jest
obszerny — pełna taksonomia ~35 zdarzeń, zweryfikowane zapytania SQL na WAC
i aktywację, drzewo metryk, dashboard, sekcja prywatności, plan B bez PostHoga.
Issue #7 się na niego powołuje. **Nie duplikuję tego pliku** — nie mam go
zresztą w zakresie zapisu. Ten dokument robi trzy rzeczy, których tamten
dokument nie robi:

1. **Znajduje i naprawia konkretną lukę** w definicji WAC (konta zawieszone/
   zbanowane/gospodarza nie są nigdzie wykluczone z zapytania SQL).
2. **Klasyfikuje każdą właściwość każdego zdarzenia pod RODO** (osobowa /
   pseudonimizowana / nie-osobowa) — `docs/seo/ANALYTICS.md` tego nie robi,
   ma tylko ogólną sekcję 5 o tym, co nigdy nie trafia do PostHoga.
3. **Sprawdza dla każdego zdarzenia, czy naprawdę wymaga PostHoga**, czy dane
   już leżą w Postgresie — i tnie listę mocniej, niż zrobił to sam
   `docs/seo/ANALYTICS.md` (oraz issue #7, który już przyciął pełną listę
   z ~35 do ~19 pozycji). Wynik jest zaskakujący: **większość „zdarzeń"
   z issue #7 nie potrzebuje żadnego trackingu — dane już są w bazie.**

Tam, gdzie się zgadzam z `docs/seo/ANALYTICS.md`, odsyłam do niego zamiast
przepisywać. Tam, gdzie się nie zgadzam, piszę to wprost z uzasadnieniem
(patrz §2.3 — fraza wyszukiwania).

---

## 1. Definicja Weekly Active Cooks (WAC)

### 1.1 Definicja jest już rozstrzygnięta — bronię jej, nie wymyślam nowej

Trzy niezależne dokumenty (`docs/PRODUCT.md` §North Star,
`docs/SEO_ANALYTICS_GROWTH.md` §North Star, `docs/product/RETENTION_LOOPS.md`
§5.1) i `docs/seo/ANALYTICS.md` §2.1 zgodnie definiują:

> **WAC = liczba unikalnych użytkowników, którzy w danym tygodniu kalendarzowym
> (poniedziałek–niedziela) opublikowali post, opublikowali przepis, albo
> utworzyli „Ugotowałem".**

To jest właściwa definicja i **nie proponuję jej zmiany**. Rozpatrzyłem
alternatywy, które sugeruje brief:

| Kandydat | Co premiuje | Co psuje | Werdykt |
|---|---|---|---|
| **Samo wejście / sesja** | Ruch, DAU | Zespół zaczyna optymalizować pod przeglądanie, dokładnie to, przed czym ostrzega `RETENTION_LOOPS.md` §6.1 („DAU — nikt nie gotuje nowego dania codziennie") | **odrzucone** |
| **Otworzenie przepisu** | Zasięg treści | To jest konsumpcja, nie gotowanie. Ktoś, kto przewinął 50 przepisów i nic nie zrobił, nie jest „aktywnym kucharzem" — jest czytelnikiem | **odrzucone** |
| **Komentarz** | Rozmowę | Komentarz „Pięknie!" bez treści (ostrzeżenie #7 w `RETENTION_LOOPS.md` §6) liczyłby się tak samo jak realna wymiana wiedzy. Zbyt łatwy do spamowania samemu sobie | **odrzucone jako składnik WAC** (ale zostaje jako metryka jakości, patrz §1.3) |
| **Zapis do Zeszytu** | Intencję | Deklaracja „zrobię to kiedyś" bez wykonania. `RETENTION_LOOPS.md` sam nazywa to ryzyko: „zapisane przepisy zamieniają się w cmentarz zakładek" | **odrzucone jako składnik WAC** (zostaje jako `save → cooked`, metryka jakości) |
| **Publikacja (post LUB przepis) LUB „Ugotowałem"** | **Tworzenie i pokazywanie gotowania** | Nic — to jest dokładnie to, co produkt ma robić | **przyjęte** |

### 1.2 Czy WAC oddaje, że „Ugotowałem" jest ważniejsze niż lajk

Tak — ale nie przez wagę wewnątrz WAC, tylko przez **miejsce w drzewie
metryk**. WAC jest metryką **zasięgu** („ile osób w ogóle coś zrobiło"), więc
logika OR (publikacja LUB „Ugotowałem") jest poprawna: nie chcemy, żeby ktoś,
kto tylko gotuje z cudzych przepisów i nigdy nie publikuje własnych, wypadał
z licznika — to byłaby dokładnie odwrotna pomyłka do tej, przed którą ostrzega
brief. Nadanie „Ugotowałem" wagi 2 wewnątrz WAC nie zmieniłoby, KTO się liczy
(większość ludzi w małej społeczności i tak robi tylko jedno z trojga w danym
tygodniu), tylko utrudniłoby czytanie liczby („WAC = 47" przestałoby znaczyć
„47 osób").

Waga „Ugotowałem" żyje więc w **metrykach drugiego poziomu**, które już są
rozstrzygnięte w `docs/product/RETENTION_LOOPS.md` §5.3 i które **przyjmuję
bez zmian**:

- `Ugotowałem / tydzień` — osobno raportowane, cel ≥40 przy 200 użytkownikach;
- `cooked → odpowiedź autora w 24h` — domknięcie głównej pętli produktu;
- `would_make_again rate` — jakość, nie zasięg;
- `save → cooked w 30 dni` — czy zapis kończy się realnym gotowaniem.

**`would_make_again rate` w `kuking:raport` (issue #1509).** Pole ma trzy
stany: `true` („zrobię ponownie”), `false` („raczej nie powtórzę”) i `NULL`
(brak odpowiedzi albo odpowiedź wycofana, #767). `NULL` nie jest „nie”.
Raport (`App\Domain\Analytics\ZrobiePonownie`) podaje:

- liczby `tak`, `nie`, `brak odpowiedzi` osobno;
- odsetek odpowiedzi = `(tak + nie) / wszystkie wykonania`;
- odsetek „tak” = `tak / (tak + nie)` — `NULL` jest poza mianownikiem;
  poniżej 20 odpowiedzi raport pisze „za mało danych” zamiast procentu.

Jednostką jest **każde realne wykonanie** (wiersz `cooked_events`), także
powtórne gotowanie tego samego przepisu przez tę samą osobę — raport pyta,
jak często gotowanie kończy się chęcią powtórki, a D-005 traktuje każde
wykonanie jako osobne wydarzenie. Okno: ostatnie 30 dni wstecz od chwili
liczenia, po `cooked_at` (przedział chwil `timestamptz`, więc strefa czasowa
nie przesuwa granicy). Cudze przepisy (`recipes.author_id <> user_id`)
i własne liczą się osobno; przepis ukryty moderacyjnie zostaje w liczbach;
gotujący z `CookEligibility` (gospodarz, konta testowe, zalążkowe,
zamknięte) są wyłączeni. Wynik jest tylko zbiorczy — bez nazw, tytułów,
notatek i bez rankingu przepisów lub autorów.

**Liczba główna: WAC.** Liczby pomocnicze, w tej kolejności ważności:

1. `% kont, które w tygodniu cokolwiek opublikowały` (post LUB przepis LUB
   „Ugotowałem") — to jest po prostu WAC / liczba kont aktywnych, podane jako
   odsetek zamiast liczby bezwzględnej, żeby było czytelne przy zmieniającej
   się bazie kont;
2. `D1 / D7 / D30` liczone **do dowolnej aktywności typu „cook"**, nie do
   samego zalogowania (zapytanie w §1.4);
3. `udział „Ugotowałem" wśród cudzych przepisów` — `cooked_events` gdzie
   `recipe.author_id <> cooked_events.user_id` / wszystkie `cooked_events`.
   To odróżnia „social proof" (ktoś ugotował coś CUDZEGO) od „dziennika
   własnego gotowania" (ugotował własny przepis, co też ma wartość, ale inną).
   Nie jest to rozróżnione w `docs/seo/ANALYTICS.md` §2, a jest tanie —
   `recipes.author_id` już tam jest.

### 1.3 Luka, którą znalazłem: WAC dziś policzyłby konta, których nie powinien

`docs/seo/ANALYTICS.md` §2.2 podaje zapytanie SQL na WAC. Sprawdziłem je pod
kątem `docs/DATABASE.md` i migracji (`0001_01_01_000001_create_users_table.php`,
`config/kuking.php`) i **zapytanie nie wyklucza**:

- **kont zbanowanych/zawieszonych** — `User::suspend()` i `User::ban()`
  (`app/Models/User.php:515-540`) zmieniają wyłącznie `users.status`. Nie
  dotykają `posts.status` ani `recipes.status` swoich treści. Treść
  opublikowana przed karą **zostaje `published`** — więc aktywność sprzed
  bana nadal trafia do WAC, co jest poprawne (działo się, gdy konto było
  aktywne), ale gdyby ktoś próbował liczyć WAC „na żywo" dla **bieżącego**
  tygodnia bez JOIN-a do `users`, zbanowane konto z aktywnością SPRZED bana
  w tym samym tygodniu kalendarzowym policzy się tak samo jak aktywne — co
  jest zgodne z intencją, ALE nikt tego świadomie nie ustalił, tylko wyszło
  przez brak JOIN-a;
- **konta gospodarza** — `config('kuking.account.host_username')`
  (`config/kuking.php:219`, domyślnie `woogitsu`). Gospodarz **ma** publikować
  codziennie z założenia (`docs/product/RETENTION_LOOPS.md` „Cisza gospodarza"
  jest sygnałem alarmowym #12), więc jest gwarantowanym, sztucznym wkładem do
  WAC co tydzień. Przy 20-50 kontach w zamkniętej alfie to **jeden fałszywy
  punkt na kilkanaście–kilkadziesiąt prawdziwych** — zauważalne zniekształcenie,
  które zniknie samo przy skali, ale w Bramce zamkniętej alfy (`docs/ROADMAP.md`
  „20+ realnych userów") liczy się każdy punkt;
- **kont testowych/deweloperskich** — nie ma w schemacie żadnej kolumny
  `is_test` / `is_staff` / `is_bot` (sprawdziłem `0001_01_01_000001_create_users_table.php`
  i całe `docs/DATABASE.md` — nie ma). Dziś jedyny sposób odróżnienia konta
  testowego od realnego to znajomość jego `id` albo `username` „na pamięć".
  To jest realna luka operacyjna, nie tylko analityczna: seed/demo dane
  (`database/seeders`, jeśli używane na stagingu) zanieczyszczą KAŻDĄ metrykę,
  nie tylko WAC.

**Poprawka — dodaję `JOIN users` i listę wykluczeń do zapytania z
`docs/seo/ANALYTICS.md` §2.2:**

```sql
WITH excluded_users AS (
    -- Gospodarz publikuje z założenia i systematycznie — nie jest "realnym"
    -- WAC do celów Bramki zamkniętej alfy (docs/ROADMAP.md).
    SELECT u.id FROM users u
    JOIN profiles p ON p.user_id = u.id
    WHERE lower(p.username) = lower(current_setting('kuking.host_username', true))

    UNION

    -- Konta testowe/demo: dopóki nie ma kolumny w bazie, lista żyje
    -- w konfiguracji (ten sam wzorzec co config('kuking.account.reserved_usernames')
    -- dla nazw zastrzeżonych, docs/DATABASE.md #profiles).
    SELECT u.id FROM users u
    JOIN profiles p ON p.user_id = u.id
    WHERE lower(p.username) = ANY (
        SELECT lower(unnest(string_to_array(current_setting('kuking.test_usernames', true), ',')))
    )
),
weekly_cook_activity AS (
    SELECT author_id AS user_id, published_at AS activity_at
    FROM posts
    WHERE status = 'published' AND published_at IS NOT NULL AND deleted_at IS NULL
    UNION ALL
    SELECT author_id, published_at FROM recipes
    WHERE status = 'published' AND published_at IS NOT NULL AND deleted_at IS NULL
    UNION ALL
    SELECT ce.user_id, ce.cooked_at
    FROM cooked_events ce
    JOIN users u ON u.id = ce.user_id
    WHERE u.status NOT IN ('banned', 'pending_delete')   -- patrz uzasadnienie niżej
)
SELECT date_trunc('week', activity_at)::date AS week_start,
       count(DISTINCT user_id) FILTER (WHERE user_id NOT IN (SELECT id FROM excluded_users))
           AS weekly_active_cooks
FROM weekly_cook_activity
GROUP BY 1
ORDER BY 1;
```

**Decyzje, które podjąłem przy tej poprawce, wprost:**

- **Wykluczam `banned` i `pending_delete`, ale NIE `suspended`.** Zawieszenie
  jest tymczasowe i odwracalne (`users.status_expires_at`,
  `docs/DATABASE.md` §`status_expires_at`) — ktoś ukarany na 7 dni za jedno
  zdarzenie nie przestaje być „realnym kucharzem" retrospektywnie. Ban i
  `pending_delete` to stany docelowe (dane w drodze do anonimizacji), więc
  te konta wypadają.
- **Gospodarz i konta testowe wykluczone z prostej konfiguracji, nie z nowej
  kolumny w bazie.** Nie proponuję migracji `users.is_test` — to należałoby
  do agenta piszącego kod, nie do mnie, i byłoby przedwczesne dla jednej
  kolumny używanej wyłącznie w raportowaniu. Rozwiązanie tekstowe
  (`current_setting`/zmienna środowiskowa z listą nazw, analogicznie do
  `reserved_usernames`) wystarcza przy zespole 1-2 osób i skali dziesiątek
  kont. **Jeśli lista kont testowych urośnie do potrzeby zarządzania nią przez
  moderatora w UI — to jest sygnał, żeby dodać kolumnę**, nie robić tego teraz.
- **Boty:** dziś nie ma żadnej integracji API (D-014 w `docs/DECISIONS.md` —
  świadomie brak API), więc nie ma technicznej drogi dla bota do publikowania.
  Jedyne „boty" to konta obsługi z zastrzeżonymi nazwami
  (`config('kuking.account.reserved_usernames')`), które i tak nie publikują
  treści społecznościowej. **Nic do zrobienia teraz** — pytanie wróci, gdyby
  kiedyś powstało API (warunek z D-014).

### 1.4 D1/D7/D30 — zapytanie

`docs/seo/ANALYTICS.md` §3.2 ma już poprawne zapytanie kohortowe na retencję
„twórczą" (tygodniowe okna). Jedyna poprawka: dodać ten sam
`WHERE u.status NOT IN ('banned', 'pending_delete')` i wykluczenie gospodarza/
kont testowych z `excluded_users` powyżej do `user_weeks` CTE. Nie przepisuję
całego zapytania drugi raz — patrz `docs/seo/ANALYTICS.md` §3.2 i zastosuj tę
samą poprawkę.

### 1.5 „Drugi wpis w 7 dni” — czy pierwszy wpis staje się nawykiem (issue #29)

`docs/product/COLD_START.md` §4.5 każe gospodarzowi w dniach 4–5 sprawdzić,
czy nowa osoba ma drugi wpis, a warunek STOP bramki A pyta, czy ludzie
publikują bez ręcznego przypominania. `kuking:raport` liczy to teraz jedną
liczbą (`App\Domain\Analytics\DrugiWpisW7Dni`):

- **kohorta** — autorzy, których pierwszy opublikowany wpis (`posts`, oba
  rodzaje, `Post::published()`, bez usuniętych) ma od 7 do 90 dni. Młodsi
  niż 7 dni nie mieli jeszcze szansy na drugi i zaniżaliby wynik;
- **licznik** — ci, których drugi wpis (kolejność `published_at`, potem `id`)
  przyszedł najpóźniej 7×24 h po pierwszym (`extract(epoch …)`, jak w §1.4
  i `PowrotPoDniach`);
- wykluczenia `CookEligibility`; wpis usunięty albo ukryty potem wypada,
  więc miernik jest ostrożny — może zaniżać, nie zawyża;
- **mała próba** — poniżej 10 osób raport podaje „X z Y” bez procentu.

Wynik to dwa liczniki. Żaden identyfikator, nazwa ani treść wpisu nie
wychodzi z zapytania.

---

## 2. Zdarzenia — co naprawdę wymaga trackingu, a co już jest w bazie

### 2.1 Zasada, która tnie listę mocniej niż gdziekolwiek indziej w projekcie

`docs/seo/ANALYTICS.md` proponuje ~35 zdarzeń PostHog. Issue #7 już przyciął
tę listę do ~19. Sprawdziłem **każde z 19** pod jednym pytaniem: *czy ta
informacja już istnieje w Postgresie jako wiersz z `created_at`/`published_at`/
`cooked_at`, czy naprawdę znika, jeśli nie złapiemy jej w locie?*

Wynik: **9 z 19 „zdarzeń" to zwykłe zapytanie SQL do tabeli, która i tak
istnieje.** Publikacja posta jest wierszem w `posts` z `published_at`.
„Ugotowałem" jest wierszem w `cooked_events` z `cooked_at`. Nie potrzeba
PostHoga, żeby wiedzieć, że coś się wydarzyło i kiedy — potrzeba tylko
zapytania. To jest dokładnie sedno instrukcji z briefu: „część odpowiedzi to
zwykłe zapytanie SQL, nie zewnętrzne narzędzie" — zastosowane rygorystycznie,
zdarzenie po zdarzeniu, nie jako ogólna deklaracja.

To ważne z powodu `AGENTS.md` §3 (zakaz overengineeringu): każde zdarzenie
wysłane do PostHoga to kod w akcji domenowej, sieciowe wywołanie, koszt
(nawet darmowy plan PostHoga ma limit zdarzeń/miesiąc) i coś, co może się
rozjechać z prawdą w bazie. Jeśli SQL i tak daje odpowiedź — PostHog dodaje
tylko ryzyko rozjazdu, zero nowej informacji.

**Tabela poniżej dzieli 14 zdarzeń na dwie ścieżki: A (już w bazie, żadnej
nowej pracy poza zapytaniem) i B (naprawdę wymaga nowej instrumentacji).**
Każde ma pełne właściwości, klasyfikację RODO i odpowiedź na „jaką decyzję to
zmienia". Cztery zdarzenia z listy issue #7 **wykreślam całkiem** — patrz
§2.4.

---

### 2.2 Ścieżka A — dane już są w Postgresie (0 nowej instrumentacji)

Dla tych dziewięciu „zdarzeń" **nie trzeba pisać wysyłki do PostHoga**.
Trzeba tylko wiedzieć, że dana istnieje, gdzie leży i co wolno z nią zrobić
na dashboardzie. Podaję źródło (tabela/kolumna), typ, klasyfikację RODO
i decyzję, którą wspiera.

| # | Nazwa (do raportowania, nie zdarzenie do wysłania) | Źródło w bazie | Właściwości i typy | RODO | Jaką decyzję zmienia |
|---|---|---|---|---|---|
| 1 | `account_created` | `users.created_at` (timestamptz) | `user_id` (uuid) | user_id: **pseudonimizowana** | Mianownik do WAC/aktywacji/retencji. Bez tego nie ma żadnej innej metryki |
| 2 | `post_published` | `posts.published_at`, `posts.author_id`, JOIN `post_media` na `COUNT` | `post_id` (uuid), `author_id` (uuid), `media_count` (int), `visibility` (enum: public\|followers\|private), `has_text` (bool, `body IS NOT NULL`) | wszystko **pseudonimizowana** albo **nie-osobowa** (nigdy `posts.body`!) | Licznik WAC; czy próg publikacji (zdjęcie + kilka słów) faktycznie jest niski w praktyce (media_count rozkład) |
| 3 | `recipe_published` | `recipes.published_at`, JOIN `recipe_ingredients`/`recipe_steps` na `COUNT` | `recipe_id` (uuid), `author_id` (uuid), `ingredient_count` (int), `step_count` (int), `has_photo` (bool, `hero_media_id IS NOT NULL`), `source_type` (enum: own\|family\|adaptation\|external) | pseudonimizowana / nie-osobowa | Licznik WAC; `source_type` mierzy „czy jesteśmy duszą, czy bazą danych" (`RETENTION_LOOPS.md` §5.3) |
| 4 | `recipe_draft_abandoned` | `recipes` gdzie `status='draft' AND updated_at < now() - interval '7 days'` / wszystkie rozpoczęte szkice w oknie | `recipe_id`, `author_id`, `age_days` (int) | pseudonimizowana | Metryka „porzucone szkice przepisów" — sygnał alarmowy #8 z `RETENTION_LOOPS.md` (próg >45% = kreator za trudny). **Nie trzeba zdarzenia `recipe_create_abandoned`** — szkic to trwały wiersz, nie znika, gdy ktoś zamknie kartę |
| 5 | `cooked_event_published` | `cooked_events.cooked_at`, `cooked_events.user_id`, `recipe_id` | `cooked_event_id`, `user_id`, `recipe_id`, `has_photo` (bool via `cooked_event_media`), `has_note` (bool), `would_make_again` (bool\|null — **trzy stany**, patrz `CookedEventController.php:89-91`), `actual_minutes` (int\|null), `perceived_difficulty` (enum: easy\|medium\|hard\|null), `is_own_recipe` (bool, `recipe.author_id = user_id`) | pseudonimizowana / nie-osobowa | **Najważniejszy sygnał produktu.** Licznik WAC; jakość przepisów; czy social proof działa na cudzych przepisach (`is_own_recipe`) |
| 6 | `comment_published` | `comments.created_at`, JOIN do rodzica przez `post_id`/`recipe_id`/`cooked_event_id` | `comment_id`, `author_id`, `target_type` (enum: post\|recipe\|cooked_event), `is_reply` (bool, `parent_id IS NOT NULL`), `char_count` (int, `length(body)`) | pseudonimizowana / nie-osobowa (**nigdy `comments.body`**) | `czas do 1. odpowiedzi` i `% wpisów z odpowiedzią` — metryka nr 1 po WAC wg `RETENTION_LOOPS.md` §5.3, policzalna wprost jako `MIN(comments.created_at) - posts.published_at` |
| 7 | `user_followed` | `follows.created_at` | `follower_id`, `followed_id` | pseudonimizowana | Aktywacja (≥5 obserwowanych w 7 dni); zdrowie grafu społecznego |
| 8 | `recipe_saved` | `collection_items.created_at` | `user_id` (przez `collections.owner_id`), `recipe_id`, `collection_id` | pseudonimizowana | `save → cooked w 30 dni` = `JOIN collection_items, cooked_events` po `recipe_id`+`user_id`, policzalne wprost |
| 9 | `content_reported` | `reports.created_at`, `reports.reason`, `reports.status` | `report_id`, `target_type` (enum), `reason` (enum z `Report::REASONS`), `status` (enum) | pseudonimizowana / nie-osobowa (**nigdy `reports.details`** — to wolne pole tekstowe zgłaszającego) | Sygnał alarmowy #11 (`>5/1000 postów`) wprost z `reports`; zdrowie moderacji |

**Skąd bierze się „decyzja, jaką zmienia" mimo braku eventu:** to samo pytanie
co dla zdarzenia — różnica jest wyłącznie w mechanizmie (`SELECT` zamiast
`posthog.capture()`). Dashboard tygodniowy z `docs/seo/ANALYTICS.md` §6 może
te wszystkie kafelki zasilić **bez PostHoga w ogóle**, jeśli ktoś napisze
widoki/zapytania nad Postgresem (`php artisan kuking:wac`, wspomniane już
w issue #7, jest dobrym wzorcem do rozszerzenia na resztę tej listy).

### 2.3 Ścieżka B — naprawdę potrzebuje nowej instrumentacji

Te cztery zdarzenia dotyczą rzeczy, które **dziś nie zostawiają żadnego śladu
w bazie** — nieudana próba, porzucenie w połowie drogi, zapytanie, które nic
nie znalazło. To jest właściwe miejsce na PostHoga albo (taniej) własną,
małą tabelę.

| Zdarzenie | Kiedy dokładnie (plik:linia) | Właściwości i typy | RODO | Jaką decyzję zmienia |
|---|---|---|---|---|
| **`photo_upload_failed`** | `app/Domain/Media/Actions/StoreUploadedImage.php`, każdy `throw new RuntimeException` (linie 39, 46, 55, 62, 68) | `reason` (enum: `unreadable`\|`too_large`\|`not_an_image`\|`unsupported_format`\|`too_many_megapixels`), `file_size_kb` (int\|null), `owner_id` (uuid) | reason/rozmiar: **nie-osobowa**; owner_id: **pseudonimizowana** | Jedyny sposób, żeby zobaczyć awarię uploadu — nieudana próba dziś **nie tworzy żadnego wiersza w `media`**, więc jest niewidoczna z samego Postgresa. Zdjęcie jest „sercem produktu" (`docs/MEDIA_PIPELINE.md`) — to zdarzenie ma najwyższy priorytet z całej listy B |
| **`search_performed`** | `app/Http/Controllers/SearchController.php:55` (po policzeniu `$przepisy->count()`) | `query_length` (int), `section` (enum: przepisy\|ludzie), `result_count` (int), `has_results` (bool) | **nie-osobowa** — patrz zastrzeżenie niżej | „Najtańsze źródło wiedzy o tym, czego ludzie nie znajdują" — dziś SearchController nic nie zapisuje, fraza ginie bezpowrotnie po odpowiedzi HTTP |
| **`search_zero_results`** | to samo miejsce, `result_count === 0` | jak wyżej | jak wyżej | Wprost wskazuje luki w treści/synonimach — SEO i produkt |
| **`onboarding_reached_feed`** | `app/Http/Controllers/OnboardingController.php:112-117` (`done()`) | `user_id`, `followed_topics_count` (int, z `topic_follows`), `followed_people_count` (int, z `follows` w oknie rejestracji) | pseudonimizowana / nie-osobowa | Jedyny brak: `done()` dziś **tylko renderuje widok, nic nie zapisuje** — nie ma w bazie żadnego znacznika „ta osoba dotarła do końca onboardingu". Bez tego zdarzenia „aktywacja" liczy się tylko pośrednio (czy w 7 dni cokolwiek zrobił), co już wystarcza do metryki aktywacji z §3.1 `docs/seo/ANALYTICS.md` — **to zdarzenie jest więc P2, nie P0**: dodaje wygodę diagnozowania (który dokładnie krok onboardingu utyka), ale aktywację da się policzyć bez niego |

**Zastrzeżenie do `query_text` — tu się nie zgadzam z `docs/seo/ANALYTICS.md`
§1.6 i §5.** Ten dokument proponuje wysyłanie `query_text` do PostHoga, tylko
„przepuszczonego przez filtr regex odrzucający e-mail/telefon" i uciętego do
100 znaków, z zastrzeżeniem, że **fraza może przypadkiem zawierać dane
osobowe** (przyznaje to sam, w tym samym akapicie). To za mało: regex łapie
adresy e-mail, nie łapie „przepis pani Jadwigi Kowalskiej z Konina" ani
„jak ugotować dla mamy po chemii" (stan zdrowia — kategoria szczególna RODO
art. 9). Fraza wyszukiwania jest **tekstem, który wpisał żywy człowiek**,
dokładnie tej samej natury co treść komentarza czy wpisu, którą brief
wprost zabrania wysyłać. Traktuję ją więc tak samo:

- do PostHoga (albo jakiegokolwiek podmiotu trzeciego) **nigdy nie trafia
  `query_text`** — tylko `query_length` i `has_results`;
- jeśli produkt kiedyś naprawdę potrzebuje oglądać surowe frazy (np. do
  budowania słownika synonimów), to żyje **wyłącznie pierwszostronnie**,
  we własnej tabeli Postgresa, z krótką retencją (rekomendacja: 30 dni na
  surowy tekst, potem tylko zagregowana lista fraz bez `user_id` i bez
  znacznika czasu co do sekundy) i bez eksportu na zewnątrz. To jest bardziej
  restrykcyjne niż `docs/seo/ANALYTICS.md`, i uzasadniam to tym samym
  zdaniem z briefu, które ten dokument sam cytuje o treści wpisów: „gdzie
  NIE wolno wysyłać treści użytkownika".

**Rekomendacja mechaniki dla Ścieżki B:** własna, mała tabela w Postgresie
(nie PostHog na start) — dokładnie wzorzec `product_events` z
`docs/seo/ANALYTICS.md` §7, ale ograniczony do tych czterech zdarzeń, nie
całej listy 35. Przy czterech zdarzeniach nie ma ryzyka „rosnącej tabeli
konkurującej o zasoby" (zastrzeżenie, które ten sam dokument słusznie
zgłasza dla wariantu pełnego) — wolumen jest rzędu pojedynczych zdarzeń na
sesję, nie autosave co kilka sekund. PostHog staje się uzasadniony dopiero,
gdy pojawi się potrzeba lejków/segmentacji behawioralnej na większą skalę
niż zamknięta alfa — czyli po Bramce zamkniętej alfy, nie przed nią.

### 2.4 Zdarzenia z issue #7, które wykreślam — i dlaczego

| Zdarzenie z issue #7 | Dlaczego wykreślam |
|---|---|
| `email_verified` | Weryfikacja **nie blokuje** żadnej akcji (`RegisterController.php:32-35`, komentarz wprost: „Weryfikacja e-maila NIE BLOKUJE pierwszej publikacji"). Skoro nic od tego nie zależy, żadna decyzja się nie zmienia. Jeśli kiedyś ktoś zechce wskaźnik weryfikacji — to `SELECT count(*) FILTER (WHERE email_verified_at IS NOT NULL) FROM users`, zero potrzeby na zdarzenie |
| `onboarding_abandoned` (jako zdarzenie kliencie, `navigator.sendBeacon`) | `docs/seo/ANALYTICS.md` sam przyznaje: „nie zawsze wykryje 100% przypadków". Zamiast zawodnego zdarzenia klienckiego, to samo pytanie („kto nie doszedł do końca") odpowiada brak `onboarding_reached_feed` w oknie 24h od `account_created` — czysto z danych, bez heurystyki w przeglądarce, i działa identycznie z JavaScriptem i bez niego (D-007) |
| `recipe_step_completed` / `recipe_step_viewed` / `recipe_draft_autosaved` | Trzy osobne zdarzenia po to, żeby wiedzieć to samo, co mówi już istniejący wiersz `recipes` ze statusem `draft` i `updated_at`: czy szkic idzie do przodu. `recipe_draft_abandoned` (§2.2, poz. 4) daje tę samą odpowiedź z zera nowego kodu. Osobno: kreator 3-krokowy wymaga JavaScriptu (`RecipeController.php:24-31`, `/dodaj/przepis/jedna-strona` to jedyna droga bez JS) — zdarzenia klienckie na krokach istniałyby wyłącznie dla ścieżki z JS, tworząc ślepą plamę na ścieżce bez JS, której D-007 każe traktować jako pierwszorzędną, nie zapasową |
| `text_scale_changed` | Aktualna wartość jest trwałą kolumną (`users.text_scale`), nie efemerycznym stanem — rozkład w danym momencie (`SELECT text_scale, count(*) FROM users GROUP BY 1`) odpowiada na pytanie „czy domyślny rozmiar jest za mały" bez potrzeby historii zmian. Historia zmian miałaby sens tylko przy pytaniu „ile razy dana osoba próbowała", co nie jest pytaniem, na które ten produkt dziś szuka odpowiedzi |

To są **cztery konkretne cięcia z uzasadnieniem**, zgodnie z zasadą briefu:
„Zdarzenie, które nie zmienia żadnej decyzji, wykreśl z listy i napisz, że je
wykreśliłeś." Razem z §2.2/§2.3: z 19 zdarzeń issue #7 zostaje **9 policzalnych
z samego SQL + 4 wymagające nowej, lekkiej instrumentacji = 13**, plus jedno
opcjonalne P2 (`onboarding_reached_feed`). To jest bliżej „dwunastu zdarzeń,
których ktoś użyje" niż jakikolwiek wcześniejszy wariant tej listy w projekcie.

**Czego nie pokrywam, a brief prosił:** „pierwszy obserwowany" jako osobne
zdarzenie — to jest dokładnie `user_followed` (§2.2, poz. 7) z filtrem
`WHERE created_at = (SELECT MIN(created_at) FROM follows WHERE follower_id = X)`,
nie osobne zdarzenie. Podobnie „powiadomienia wysłane" to `notifications.created_at`
(już w bazie, `notifications` istnieje od dnia zero, `docs/DATABASE.md` #notifications).
**„Powiadomienia otwarte" to jedyna prawdziwa luka tutaj** — `NotificationController::markAllRead`
(`NotificationController.php:36-41`) ustawia `read_at` **zbiorczo dla wszystkich
naraz**, nie per powiadomienie w momencie kliknięcia w konkretne. Świadomie
**nie rekomenduję** budowania precyzyjnego trackingu „otwarcia" per typ
powiadomienia teraz — przy zespole 1-2 osób i skali zamkniętej alfy to
byłby dokładnie ten rodzaj instrumentacji, który nikt nie zdąży przejrzeć
(`AGENTS.md` §3). `read_at` zbiorczy jest wystarczającym proxy na start;
wracam do tego, gdyby wskaźnik `>8% wyłączających powiadomienia`
(sygnał alarmowy #6, `RETENTION_LOOPS.md`) wymagał głębszej diagnozy.

---

## 3. Minimalny plan wdrożenia

### 3.1 Co NIE wymaga niczego poza SQL (zrób to najpierw, kosztuje jeden dzień)

Wszystkie dziewięć pozycji z §2.2, plus poprawiona definicja WAC z §1.3-1.4.
Konkretnie: rozszerzyć istniejący wzorzec `php artisan kuking:wac` (już
wspomniany w issue #7) o osobne komendy albo widoki SQL dla każdej pozycji
z §2.2, czytane przez prosty wewnętrzny panel Blade (nie osobna aplikacja —
`AGENTS.md` zakazuje SPA, a panel administracyjny już istnieje dla moderacji,
więc to rozszerzenie istniejącego, nie nowy komponent). **To pokrywa WAC,
aktywację, retencję D1/D7/D30 i większość drzewa metryk z `RETENTION_LOOPS.md`
§5.3 bez PostHoga, bez zewnętrznego konta, bez banera zgody** (patrz §3.3).

### 3.2 Co wymaga nowej instrumentacji (Ścieżka B, §2.3)

Cztery zdarzenia → własna tabela Postgresa, wzorowana na `product_events`
z `docs/seo/ANALYTICS.md` §7, ale okrojona:

```sql
CREATE TABLE product_signals (
    id bigserial PRIMARY KEY,
    user_id uuid REFERENCES users(id) ON DELETE SET NULL,
    signal_name varchar(60) NOT NULL,   -- 'photo_upload_failed' | 'search_performed' | ...
    properties jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX product_signals_name_time_idx ON product_signals(signal_name, occurred_at DESC);
```

Nazwa celowo inna niż `product_events` z `docs/seo/ANALYTICS.md` (`signals`,
nie `events`) — żeby dwa dokumenty projektu nie sugerowały tej samej tabeli
o różnym kształcie, gdyby ktoś kiedyś łączył oba plany. Ta tabela **nigdy nie
zawiera** `query_text` w postaci surowej (§2.3), treści wpisów/komentarzy ani
adresu IP w postaci jawnej (jeśli w ogóle potrzebny — hash, wzorem
`audit_log.ip_hash`, `2026_09_05_001000_create_trust_and_safety_tables.php:595-597`).

**PostHog dopiero po Bramce zamkniętej alfy**, gdy pojawi się potrzeba
lejków i segmentacji na skalę, przy której ręczne SQL-e przestają
wystarczać (setki, nie dziesiątki kont). To jest zgodne z `AGENTS.md` §3:
nie dokładać narzędzia bez zmierzonej potrzeby. **Nie neguję decyzji
`docs/seo/ANALYTICS.md` o docelowym użyciu PostHoga** (stack w `AGENTS.md`
wprost go wymienia) — mówię tylko, że dzień zero nie jest tym momentem.

### 3.3 Zgodność z „ważne funkcje działają bez JavaScriptu" (D-007)

Wszystkie 13 zdarzeń z §2.2-2.3 **da się i trzeba** wysyłać **z akcji
domenowej po stronie serwera** (`app/Domain/*/Actions/*.php`), nigdy z kodu
w przeglądarce. To nie jest tylko zgodność z D-007 — to też najprostsza
droga do uniknięcia banera zgody na cookies (patrz §3.4). Konkretne miejsca
wstawienia w istniejącym kodzie (dla Ścieżki B, jedynej wymagającej nowego
kodu):

- `photo_upload_failed` → `App\Domain\Media\Actions\StoreUploadedImage::handle`,
  w każdym miejscu, gdzie dziś jest `throw new RuntimeException(...)`
  (`StoreUploadedImage.php:39,46,55,62,68`);
- `search_performed`/`search_zero_results` → `SearchController::index`, po
  linii 55 (`$jestWiecej = $przepisy->count() > $ile;`), po dwóch stronach:
  `przepisy` i `people`;
- `onboarding_reached_feed` (P2) → `OnboardingController::done`
  (`OnboardingController.php:112`).

Dla dziewięciu pozycji Ścieżki A nie trzeba niczego wstawiać nigdzie — to
czyste zapytania nad istniejącymi tabelami, uruchamiane przez komendę
artisan/cron, zero zmiany w akcjach domenowych.

### 3.4 Do Not Track i baner zgody — rozstrzygnięcie

`docs/legal/COMPLIANCE.md` §5.3 zostawia to jako otwarte pytanie („Do
rozstrzygnięcia razem z issue o zgodności... Polska nie ma wyjątku dla
analityki"). Plan z §3.1-3.2 **rozstrzyga to praktycznie, nie tylko
prawnie**: skoro cała Ścieżka A to zapytania SQL po stronie serwera bez
żadnego kodu w przeglądarce użytkownika, a Ścieżka B to też zapisy
wywoływane z akcji domenowej (serwer → własna tabela Postgresa, nie
zewnętrzny SDK JavaScript w przeglądarce) — **nie ma żadnego zapisu ani
odczytu na urządzeniu końcowym użytkownika**, więc **nie ma zdarzenia
podlegającego pod art. 173 PKE / ePrivacy**, a więc **nie potrzeba banera
zgody na analitykę na tym etapie**. To jest bezpieczniejsza wersja
argumentu z `docs/legal/COMPLIANCE.md` §5.3 („cookieless server-side
capture") — bezpieczniejsza, bo tu nie ma nawet identyfikatora klienckiego
do rozważenia, jest tylko `user_id` z sesji zalogowanego użytkownika
(potrzebnego i tak do działania konta), plus (dla wyszukiwania na stronach
publicznych bez logowania) brak identyfikatora osobowego w ogóle.

**`respect_dnt`** — nieistotne przy czysto serwerowym podejściu (DNT jest
nagłówkiem przeglądarki adresowanym do trackerów klienckich; serwer i tak
nie wysyła nic do przeglądarki osób trzecich). Gdy w V1/V2 pojawi się
PostHog z ewentualnym trackingiem klienckim (heatmapy, session replay —
**żadne z nich nie jest dziś planowane i nie rekomenduję ich**, patrz §4),
wtedy `docs/legal/COMPLIANCE.md` §5.3-5.4 (baner opt-in, bez ściany zgody)
staje się aktualne i należy do niego wrócić.

### 3.5 Retencja danych analitycznych

- **Ścieżka A** — to nie są osobne dane, to te same wiersze `posts`/`recipes`/
  `cooked_events`/itd., które już mają swój cykl życia opisany w
  `docs/legal/COMPLIANCE.md` §Retention (do usunięcia treści/konta). Zero
  nowej polityki retencji potrzebne.
- **Ścieżka B** (`product_signals`) — rekomendacja: **90 dni na surowe wiersze**
  z `properties` (wystarczy do porównań tydzień do tygodnia i miesiąc do
  miesiąca przy małej skali), potem albo usunięcie, albo agregacja do
  zwykłej tabeli liczników bez `user_id` (np. `photo_upload_failures_daily
  (date, reason, count)`). Krótsza niż rekomendacja 6-14 miesięcy z
  `docs/legal/COMPLIANCE.md` dla PostHoga — bo `product_signals` niesie
  `user_id` per wiersz (nie zagregowane z góry jak w PostHog), więc zasada
  minimalizacji każe trzymać to krócej.

---

## 4. Anty-metryki — czego świadomie nie mierzymy i nie eksponujemy

Wprost z `AGENTS.md` §12 (streaki i punkty za liczbę postów, publiczne
rankingi użytkowników, algorytmiczny feed, liczniki lajków wyeksponowane
w interfejsie) i `docs/product/RETENTION_LOOPS.md` §6.1:

| Czego nie mierzymy jako celu | Dlaczego panel bywa pierwszym krokiem do interfejsu |
|---|---|
| **Ranking użytkowników wg liczby publikacji** | Nie ma takiej tabeli i nie będzie — `daily_picks` (`docs/DATABASE.md`) jest świadomie bez kolumny z punktami. Ale gdyby ktoś zbudował wewnętrzny dashboard z listą „top publikujący", to jest jedno query od pokazania tego samego w interfejsie jako rankingu — **dlatego nawet w panelu wewnętrznym nie sortuję ludzi po liczbie postów**, tylko po metrykach zdrowia (czas do odpowiedzi, wskaźnik jakości) |
| **Liczba „Ładne!" jako metryka do optymalizacji** | Reakcja „Ładne!" nie ma dziś nawet tabeli w bazie (sprawdziłem migracje — nie istnieje, jest tylko wzmiankowana jako przyszły element powiadomień w `RETENTION_LOOPS.md` §3.1). Jeśli powstanie, **nie powinna mieć własnego zdarzenia analitycznego eksponowanego na dashboardzie obok WAC** — z tego samego powodu, dla którego nie jest publiczna w interfejsie: łatwo zamienia się w metrykę próżności, którą zespół zaczyna optymalizować zamiast realnego gotowania |
| **Streak (dni z rzędu z publikacją)** | Prosta do policzenia z `posts.published_at` — i dokładnie dlatego kusząca. Nie liczę jej w ogóle, nawet wewnętrznie, żeby nie było pokusy „skoro już mamy to policzone, pokażmy to użytkownikowi" |
| **DAU jako kafelek obok WAC** | `RETENTION_LOOPS.md` §6.1 już to rozstrzyga: „Nikt nie gotuje nowego dania codziennie". Umieszczenie DAU na tym samym dashboardzie co WAC sugeruje, że oba są tak samo ważne — nie są |
| **Liczba kont zarejestrowanych jako metryka sukcesu** | `RETENTION_LOOPS.md` §6.1: „konto bez wpisu jest zerem". Rejestracje bez aktywacji nie trafiają na główny dashboard — trafiają tylko do lejka aktywacji, gdzie liczy się to, ilu NIE aktywowało się, nie ilu się zarejestrowało |

---

## 5. Czego nie sprawdziłem / nie zmierzyłem

- **Nie sprawdziłem, czy `database/seeders` faktycznie tworzy konta testowe
  na stagingu** — jeśli tak, luka z §1.3 (brak kolumny `is_test`) jest
  pilniejsza, niż zakładam. Nie czytałem katalogu `database/seeders` w całości
  (poza kontekstem tego zadania), więc nie potwierdzam ich zawartości.
- **Nie zmierzyłem żadnego rzeczywistego zapytania na produkcyjnych danych** —
  bo produkcja nie istnieje (D-011: deploy odłożony). Zapytania w §1.3 są
  poprawne składniowo względem schematu z migracji, ale nie uruchomiłem ich
  na żywej bazie z realnymi danymi (w przeciwieństwie do `docs/seo/ANALYTICS.md`,
  który deklaruje takie uruchomienie na lokalnej instancji).
- **Nie rozstrzygnąłem ostatecznie kwestii prawnej z `docs/legal/COMPLIANCE.md`
  §5.3** („czy serwerowa analityka bez identyfikatorów klienckich naprawdę
  nie wymaga zgody w Polsce") — to pytanie prawne bez oficjalnych wytycznych
  UODO, `[do weryfikacji]` tam i tu. Mój wniosek w §3.4 jest inżynierską
  konsekwencją wyboru architektury (nic nie zapisujemy na urządzeniu klienta),
  nie opinią prawną — rekomendowałbym mimo to jednozdaniowe potwierdzenie
  u prawnika przed startem, tak jak już zaleca `COMPLIANCE.md`.
- **Nie sprawdziłem kosztu PostHoga** (plany cenowe, limit zdarzeń) — poza
  zakresem tego dokumentu, bo rekomendacja w §3.2 i tak odsuwa tę decyzję
  poza dzień zero.
- **Nie przetestowałem żadnego z podanych zapytań SQL na PostgreSQL 18** —
  weryfikacja składniowa (zgodność z typami kolumn z migracji), nie
  wykonanie. `EXPLAIN`/plan zapytania też nie sprawdzony.
