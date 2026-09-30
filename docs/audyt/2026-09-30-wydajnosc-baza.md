# Audyt wydajności bazy danych (30 września 2026)

Obszar: wydajność bazy. Baza kodu: `origin/claude/paczka-i-kandydat` w `5548c7e16`.
Tylko raport: kod aplikacji nie jest zmieniany. Jedyny eksperyment na kodzie (zamiana jednego scope’u
w `DiscoverFeed`, F1) był lokalny i został cofnięty przed commitem.

## Podsumowanie

Przy kilku tysiącach kont i kilkudziesięciu tysiącach wpisów N+1 nie ma. Liczba zapytań na stronę
jest stała (16–52), a najczęstsze powtórzenie to odczyt `cache` (do 7 razy). Większość stron ma
50–200 ms SQL na mocno obciążonej maszynie. Są trzy wyjątki i wszystkie mają tę samą przyczynę co
#599: szacowany koszt planu przekracza `jit_above_cost` (100 000), więc PostgreSQL przy każdym
żądaniu kompiluje zapytanie przez JIT.

1. **`/odkryj` dla zalogowanej osoby: 1,4–2,7 s** na jednym zapytaniu, z czego JIT to 1,3–2,5 s.
   Przeniesienie poprawki z #599 (scope `…BezKorelacji`) daje koszt 527 644 → 9 926 i 86–105 ms.
2. **`/powiadomienia`: `COUNT(*)` z `paginate()` trwa 0,4–0,7 s**, gdy osoba ma ponad ok. 610
   widocznych powiadomień (retencja 3 miesiące).
3. **`/` i `/odkryj` dla gościa:** JIT 20–120 ms przy koszcie ok. 317 tys.

Poza JIT: liczenie rozmowy pod przepisem skanuje całą tabelę `comments`. Tabela `cache` nie ma
sprzątania wygasłych kluczy limitów. Połączenia HTTP nie mają `statement_timeout`.

Znaleziska: **P0: 0, P1: 1, P2: 3, P3: 3.**

## Środowisko i metoda

- PostgreSQL **16.13** na 127.0.0.1:5432 (patrz F7). Ustawienia domyślne: `jit = on`,
  `jit_above_cost = 100000`, `max_connections = 100`, `shared_buffers = 128MB`, `work_mem = 4MB`.
  Maszyna ma 4 CPU i obciążenie ok. 7, bo dzieli ją z innymi agentami. Czasy pokazują proporcje, nie
  pojemność produkcji. Rozrzut między przebiegami sięga 2×.
- Baza jednorazowa `kuking_audyt_perf_af7a` (spoza rodziny testowej, bez PHPUnita): `migrate`,
  potem `db:seed` (`DatabaseSeeder` z `DemoSeeder`), potem `INSERT … SELECT generate_series` na
  prawdziwym schemacie. Fabryki przy tej liczbie są za wolne, tak samo jak w #1952. Wszystkie
  ograniczenia CHECK i FK obowiązywały. Zbiór:

  | Tabela | Wierszy |
  |---|---:|
  | `users` / `profiles` | 5 016 (92% aktywnych, reszta zawieszeni, zbanowani, w usuwaniu, wymazani) |
  | `recipes` | 6 041 (85% publicznych, 90% opublikowanych) |
  | `recipe_ingredients` / `recipe_steps` | 48 015 / 30 011 |
  | `posts` | 30 088 (co 30. pytanie, co 4. z przepisem, 29 tys. ze zdjęciem) |
  | `post_tags` / `post_reactions` | 60 224 / 30 000 |
  | `cooked_events` | 20 002 |
  | `comments` | 40 061 |
  | `follows` | 99 984 (ok. 20 na osobę; `marek` obserwuje 150, obserwuje go 300) |
  | `collections` / `collection_items` | 2 000 / 40 000 |
  | `notifications` | 62 002 (`marek`: 2 000, reszta ok. 12 na osobę) |
  | `reports` | 1 500 (375 otwartych) |
  | `media` | 41 014 |

  Po zasianiu `ANALYZE`. Rozmiar 163 MB. Generator:
  `/tmp/…/scratchpad/perf/gen_real.sql` i `gen_reports.sql` (poza repozytorium). Opis wyżej wystarcza
  do odtworzenia.
- Przyrząd stron: skrypt PHP ładuje `bootstrap/app.php` na nowo dla każdego żądania i puszcza
  `Request::create()` przez kernel HTTP. Zalogowanie to `guard('web')->setUser()`, a middleware
  `moderator.2fa` jest wyłączone (2FA nie jest mierzone). `DB::listen` zbiera liczbę zapytań, sumę
  czasów i surowy SQL (`toRawSql()`). Wynik to mediana z 2–3 przebiegów, bez pierwszego (zimnego).
  Pamięć to przyrost szczytu PHP w żądaniu.
- Plany: `EXPLAIN (ANALYZE, BUFFERS[, FORMAT JSON])` na tym samym SQL, który wysłała aplikacja,
  z `jit` włączonym i wyłączonym (`SET jit = off`). Skalowanie sprawdzone w transakcjach
  z `ROLLBACK`: +360 tys. komentarzy, +54 tys. przepisów, 50 tys. zadań w kolejce.

## Pomiar stron (przed, kod bazy)

| Strona | Status | Zapytań | SQL [ms] | Żądanie [ms] | Pamięć [MB] | Najwolniejsze [ms] |
|---|---:|---:|---:|---:|---:|---:|
| `/` gość | 200 | 34 | 241–467 | 336–628 | 2,3 | 166–338 |
| `/odkryj` gość | 200 | 29 | 275–489 | 378–606 | 2,7 | 216–412 |
| `/przepisy/{slug}` gość (najwięcej wykonań) | 200 | 26 | 74–119 | 118–171 | 1,8 | 17–45 |
| `/wpisy/{uuid}` gość (najwięcej komentarzy) | 200 | 24 | 59–93 | 106–171 | 1,6 | 14–32 |
| `/@{nick}` gość (najwięcej obserwujących) | 200 | 19 | 78–145 | 136–226 | 1,6 | 23–54 |
| `/szukaj?q=rosół` | 200 | 31 | 170–306 | 262–435 | 2,7 | 85–179 |
| `/szukaj?q=babci` | 200 | 31 | 124–180 | 217–385 | 2,7 | 27–55 |
| `/szukaj?q=cebula` (składnik) | 200 | 31 | 126 | 241 | 2,7 | 62 |
| `/szukaj?q=kowalska&co=ludzie` | 200 | 32 | 120–243 | 187–345 | 2,7 | 28–35 |
| `/tag/{slug}` (najczęstszy) | 200 | 22 | 63–287 | 170–423 | 2,1 | 15–95 |
| `/ugotowane/{uuid}` gość | 200 | 16 | 44 | 70 | 1,4 | 15 |
| `/home` (feed obserwowanych, 150 obserwowanych) | 200 | 49 | 143–359 | 331–659 | 2,8 | 16–46 |
| **`/odkryj` zalogowany** | 200 | 49–52 | **1 742–3 195** | **1 924–3 422** | 2,7 | **1 662–3 098** |
| `/przepisy/{slug}` zalogowany | 200 | 35 | 55–153 | 111–249 | 1,8 | 18–30 |
| `/@{nick}` własny | 200 | 23 | 71–163 | 121–233 | 1,7 | 22–63 |
| `/@{nick}` popularny, zalogowany | 200 | 24 | 93 | 168 | 1,7 | 15 |
| `/zeszyt` (lista) | 200 | 21 | 68–146 | 116–205 | 1,7 | 20–35 |
| `/zeszyt/{uuid}` „Zapisane” (20 pozycji) | 200 | 37 | 80–197 | 197–354 | 2,1 | 15–42 |
| **`/powiadomienia`** (2000 powiadomień) | 200 | 11 | **603–756** | **681–819** | 2,0 | **555–719** |
| `/admin/zgloszenia` (375 otwartych) | 200 | 16–17 | 49–63 | 124–161 | 2,1 | 21–28 |
| `/admin/kolejka` (admin) | 200 | 13 | 54 | 86 | 1,5 | 26 |
| `/admin/uzytkownicy?szukaj=kowal` | 200 | 17 | 83 | 159 | 1,8 | 21 |
| `/admin/kuking-na-dzis?szukaj=anna` | 200 | 22 | 110 | 206 | 2,8 | 64 |

`/pytania` zwraca 404 (`KUKING_QUESTIONS_ENABLED=false` w `.env`) i nie jest mierzone.

**Powtarzające się kształty zapytań** (N+1 szukane po identycznym SQL w jednym żądaniu): najwięcej
7× `select * from cache where key in (?)` na stronach gościa (limitery i stopka, B4 N1), 4×
`follows … follower_id` na `/home`, 3× listy blokad na `/odkryj` (B4 N3), 4× `exists blocks` na
przepisie i 4× `count reports` w panelu (B4 N9). Nic nie rośnie z liczbą kart na stronie.

## Znaleziska

| ID | Waga | Tytuł | Dowód (plik:linia na BAZIE) | Odtworzenie | Wpływ | Proponowana poprawka i test regresyjny | Rozmiar | Duplikat? |
|---|---|---|---|---|---|---|---|---|
| F1 | **P1** | `/odkryj` (i `/` gościa) kompiluje główne zapytanie przez JIT przy każdym żądaniu: 1,4–2,7 s dla zalogowanej osoby | `app/Domain/Feed/DiscoverFeed.php:134` (`->zWidocznymPrzepisemAlboWlasnaTrescia($viewer)`, skorelowane `EXISTS` w `OR`: `app/Models/Post.php:573-579`), `DiscoverFeed.php:215-221` (`bezUkrytychPrzez`) | Plan: koszt **524 600–527 644**, `JIT: Functions: 201`, Inlining + Optimization + Emission = **1 332–2 546 ms** z 1 444–2 789 ms wykonania. `SET jit=off`: 116–150 ms. Gość `/` i `/odkryj`: koszt 316 811–317 831, JIT 20–120 ms. **Eksperyment (po):** ten sam wiersz 134 zamieniony na istniejący `zWidocznymPrzepisemAlboWlasnaTresciBezKorelacji($viewer)` z #599 daje koszt **9 926**, bez JIT, wykonanie **86–105 ms** (zalogowany), gość 8 664 i 69–102 ms; SQL strony `/odkryj` zalogowany 1 742 → 203 ms, żądanie 1 924 → 362 ms. Zmiana cofnięta. | Osoba zalogowana czeka 2–3 s na „Odkrywaj”. FrankenPHP ma `max_threads = 4` na replikę (D-312), więc cztery takie wejścia naraz zajmują całą replikę i jej połączenia na 2 s. Każde wejście powyżej progu 1000 ms daje alarm „wolna baza” na Discordzie (D-333, `CzasZapytan`). | Przenieść wzorzec #599 do `DiscoverFeed`: `…BezKorelacji` dla przepisu i treści, `NOT IN` z `IS NOT NULL` dla ukrytych osób. Test na wzór `FeedObserwowanychKosztPlanuTest`: (1) najwyższy koszt SELECT-ów `DiscoverFeed::paginate()` (gość i zalogowany z blokadą i ukryciami) w skali #605 < `jit_above_cost`; (2) ta sama lista i kolejność rund co stare zapytanie na trzech stronach kursora. Kontrola ujemna: przywrócenie wiersza 134 wywraca test kosztu. **Równoważność wyniku w eksperymencie NIE była sprawdzana.** | M | Nie. #599 poprawił tylko `FollowingFeed` (`docs/infra/FEED_OBSERWOWANYCH_JIT_599.md`: „Ten sam wzorzec … w innych listach. Nie mierzyłem”). #1952 (zamknięte) mierzył gościa bez JIT na innych danych (wpisy z fabryk bez zdjęć i przepisów). |
| F2 | **P2** | `/powiadomienia`: pełny `COUNT(*)` z `paginate()` przekracza próg JIT od ok. 610 powiadomień | `app/Domain/Notifications/OdczytPowiadomien.php:45-51` (`->visibleTo($odbiorca)->…->paginate($naStrone)`), filtr `visibleTo` w `app/Models/Notification.php` | `COUNT` dla tej samej osoby przy różnej liczbie powiadomień (transakcja + `ROLLBACK`): 100 → koszt 16 223, 2,3 ms; 300 → 48 665, 2,4 ms; **600 → 99 193, 2,6 ms; 1000 → 166 859, 433 ms (JIT 383 ms); 2000 → 335 811, 544 ms (JIT 521 ms)**. `SET jit=off` przy 2000: 6–14 ms. Samo zapytanie strony (LIMIT) ma koszt 5 358 i 1,7 ms. | Retencja to 3 miesiące (`config/kuking.php:1067`). Konto gospodarza albo popularnej autorki przy ok. 7 powiadomieniach dziennie wchodzi na listę o pół sekundy wolniej, przy każdym wejściu. | `simplePaginate()` albo kursor zamiast `paginate()`, bo liczba stron nie jest pokazywana. Wtedy `COUNT` znika (−0,4…0,6 s przy 2000). Test: dla 1000 widocznych powiadomień żaden SELECT `OdczytPowiadomien::strona()` nie przekracza `jit_above_cost`, a strona 2 dalej działa. Kontrola ujemna: powrót do `paginate()`. `unreadNotificationsCount()` bez sufitu (wołany w `NotificationController.php:44`, gdy strona nie ma nieprzeczytanych) ma ten sam próg dla nieprzeczytanych, więc też z limitem. | S | Częściowo: trzeci punkt B4 S1 (`docs/audyt/2026-09-25-B4.md:125`) proponował `simplePaginate`. Plakietka dostała sufit (koszt 17 249, 2 ms), lista nie. Issue nie znaleziono. Nowe: próg JIT i liczby. |
| F3 | **P2** | Połączenia HTTP bez `statement_timeout`: wolne zapytanie trzyma wątek i połączenie bez górnej granicy | `config/database.php:89-104` (brak `options`); w `app/` jedyne limity czasu to `lock_timeout` migracji (`app/Support/Baza/LimitBlokadMigracji.php:53`) | Na bazie: `SHOW statement_timeout` = 0. Pomiar z F1 (2,7 s) i historyczny z `docs/infra/evidence/wydajnosc-n1/tabela.txt:7-20` (przed `ANALYZE`: `/odkryj` 8,3 s, `/tag` > 10 s) pokazują, że takie zapytania się zdarzają. | Jedno patologiczne zapytanie (brak statystyk po odtworzeniu bazy, JIT, zła ścieżka planu) zajmuje jeden z 4 wątków repliki na dowolnie długo. Osoba 50+ widzi kręcącą się stronę zamiast komunikatu. | `statement_timeout` tylko dla procesu web (np. 5–8 s przez `options` w połączeniu albo `SET` po `ConnectionEstablished` w kontekście HTTP). Worker, harmonogram i migracje osobno albo bez limitu (D-312 wspomina `SET` sesyjne przy PgBouncerze). Test: żądanie HTTP widzi `current_setting('statement_timeout')` = wartość z konfiguracji, a komenda konsoli widzi 0. Drugi test: trasa testowa z `pg_sleep` kończy się stroną błędu po polsku, nie wiszącym żądaniem. | S | Nie (issue nie znaleziono; `docs/research/2026-09-10-kolejnosc-blokad.md:494` dotyczy tylko testów wyścigów). |
| F4 | **P2** (decyzja infrastruktury) | JIT włączony globalnie przy obciążeniu OLTP; każde nowe zapytanie powyżej 100 tys. kosztu płaci setki ms | Ustawienie bazy (`jit = on`, `jit_above_cost = 100000`). Kod rozwiązuje to punktowo: `app/Models/Post.php:581-604`, `app/Jobs/WyslijPowiadomieniePush.php:361` | `SET jit=off` na tych samych zapytaniach: `/odkryj` zalogowany 2 008–2 652 → 116–150 ms, `/powiadomienia` 523–682 → 6–14 ms, `/` gość 149–317 → 125–146 ms. Wszystkie JIT-y z tego audytu kosztowały więcej, niż oszczędziły. | Trzeci przypadek tej samej klasy po #585 i #599. Każda nowa lista z regułami widoczności wpada w to ponownie, dopiero po alarmie. | Do decyzji właściciela: `jit = off` dla roli aplikacji (`ALTER ROLE … SET jit = off`) albo `options: -c jit=off` w połączeniu `pgsql`, jako siatka pod F1 i F2 (nie zamiast nich). Test: `current_setting('jit')` w połączeniu aplikacji = `off`. Przed decyzją odczyt `SHOW jit` na produkcji (`docs/flota/sesja-koordynatora-2909-b/HANDOVER.md:84`). | S | Nie. `FEED_OBSERWOWANYCH_JIT_599.md` świadomie tego nie zmienił („to decyzja infrastruktury”). |
| F5 | **P3** | Licznik rozmowy pod przepisem i wykonaniem czyta całą tabelę `comments` | `app/Models/Comment.php:160-175` (`policzRozmowe`: `whereIn('comments.id', …)->orWhereIn('comments.parent_id', …)`) | Plan: `Seq Scan on comments`, `Rows Removed by Filter: 40061`, 7,2–8,9 ms przy 40 tys. Po dołożeniu 372 tys. (transakcja): `Parallel Seq Scan`, **54–72 ms**. Wariant z dwiema gałęziami `UNION ALL` (korzenie + `parent_id IN korzenie`) na tych samych danych: **0,08–0,53 ms**, ten sam wynik (40 = 40). | Rośnie liniowo z liczbą WSZYSTKICH komentarzy w serwisie, na każdym wejściu na przepis i na „Ugotowałem” (także gościa). Dziś kilka ms. | Zapisać sumę jako dwie gałęzie po indeksach (`comments_recipe_idx` / `comments_cooked_idx` i `comments_parent_id_index`). Test: równoważność na scenie z D-281/#1396 (odcięty korzeń, ślad usuniętego) i brak `Seq Scan on comments` w planie przy kilkudziesięciu tysiącach komentarzy innych podmiotów. | S | Nie |
| F6 | **P3** | Tabela `cache` nie ma sprzątania; klucze limiterów per IP zostają po wygaśnięciu | `vendor/laravel/framework/src/Illuminate/Cache/DatabaseStore.php:427-440` (wygasły klucz usuwany tylko przy odczycie tego samego klucza); `routes/console.php` bez zadania na `cache` | W bazie pomiarowej po przebiegach 10 z 14 wierszy `cache` to wygasłe klucze `landing…`, `discover…` (limiter per adres). Każde wejście gościa z nowego adresu zapisuje wiersz klucza i `:timer` (zrzut `/`: `insert into cache` ×2, `update`, `select … for update`). | Boty z wielu adresów zostawiają wiersze na zawsze. Tabela i indeks `cache_pkey` puchną, a to ta sama tabela, której używają limitery, blokady harmonogramu i budżet listów (D-076). | Zadanie harmonogramu kasujące `expiration < now()` partiami (wspólny mechanizm z #1657). Test: wygasłe znikają, żywe i blokady (`cache_locks`) zostają, a partia ma sufit. | S | Nie. `docs/infra/REDIS_HA_DECYZJE_603_604.md:132` tylko odnotowuje brak `cache:prune`. |
| F7 | **P3** | Lokalny PostgreSQL na 5432 to 16.13, a dokumenty i D-227 podają 18 | `AGENTS.md` §3 (D-227: „wymagane 18+ lokalnie”), `docs/infra/FEED_OBSERWOWANYCH_JIT_599.md` („PostgreSQL 18.6 lokalnie (127.0.0.1:5432)”), `docs/infra/ODKRYJ_KOSZT_1952.md` (to samo) | `SELECT version()` na 127.0.0.1:5432 z tego kontenera: `PostgreSQL 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)` | Lokalne testy i pomiary floty mogą chodzić na innej wersji niż CI i produkcja. Plany i JIT różnią się między wersjami. | Sprawdzić, który klaster słucha na 5432 w obrazie floty. Test bootstrapu, który odmawia startu poniżej 18, jak `BezpiecznikBazyTestowej`. | S | Nie |

## Czasy i pamięć zadań kolejki

| Zadanie | Czas | Zapytań | Szczyt PHP | Szczyt RSS | Uwagi |
|---|---:|---:|---:|---:|---|
| `ProcessUploadedImage` 4000×3000 (1,0 MB JPEG) | 1 298 ms | 6 | 39 MB | 192 MB | RSS obejmuje wygenerowanie pliku w tym samym procesie |
| `ProcessUploadedImage` 8165×6124 (50 Mpx) | 4 058 ms | 6 | 48 MB | 491 MB | zgodne z `docs/MEDIA_PIPELINE.md:70-74` (452 MB, 4,6 s) |
| `GenerateUserExport` | nie zmierzono | | | | pliki zdjęć z seedera nie istnieją na dysku tej kopii, zadanie przerywa `DataExportPhotoUnreadable` (zachowanie poprawne) |
| `ImportujPrzepisZ*`, `WyslijPowiadomieniePush` | nie zmierzono | | | | wymagają usług zewnętrznych (OpenAI, Web Push); zasady zabraniają łączenia |

**Pobieranie zadania z kolejki `database`** (Laravel używa `FOR UPDATE SKIP LOCKED` na
PostgreSQL: `vendor/laravel/framework/src/Illuminate/Queue/DatabaseQueue.php:532-535`, to rozstrzyga
otwarte pytanie z `docs/AUDYT_SKALOWANIE_2026-09.md` §4.3):

| Scenariusz (transakcja, `ROLLBACK`) | Czas jednego pobrania |
|---|---:|
| 50 tys. zadań `default` przeplatanych z `media` | 0,03–0,04 ms |
| 49 990 `default` przed 10 `media`, pobranie z `media` | 0,07–1 ms (planer bierze `jobs_queue_index`) |
| 40 tys. opóźnionych (`available_at` w przyszłości) przed dostępnymi | **29–38 ms** (skan `jobs_pkey`, 40–44 tys. wierszy odrzuconych) |

Trzeci przypadek wymaga tysięcy zadań odłożonych w czasie. Dziś jedynym takim producentem jest
list tygodniowy z odstępem 20 s i limitem 60 na dobę (`KUKING_DIGEST_*`), więc to obserwacja, nie
znalezisko.

## Budżet połączeń

`php artisan kuking:budzet-polaczen` na bazie pomiarowej: 1 z 97 miejsc, budżet 16, próg 50.
Wniosek D-312 („nie liczba połączeń, lecz ich czas trzymania”) się potwierdza. Przy 4 wątkach na
replikę czas trzymania połączenia to czas żądania, więc F1 (2–3 s) jest ryzykiem przepustowości,
a nie puli. PgBouncer niczego tu nie zmienia.

## Cache: co jest i czego brakuje

- Jest: kandydaci „Kuking na dziś” (osoby i dania, 300 s, `app/Domain/Feed/DailyBoard.php:292,493`),
  sitemapa, numer wersji, licznik kukingów (przeliczany komendą), limitery, blokady harmonogramu.
- Brakuje (bez nowego stacku, w `CACHE_STORE=database`):
  - pierwsza strona `/odkryj` i `/` dla gościa. #1952 odłożył to jako etap 2. Po F1 koszt spada
    i potrzeba jest mniejsza.
  - brzeg Cloudflare dla HTML gościa (#610): gotowy w kodzie (`app/Support/PublicznyHtmlGoscia.php`),
    domyślnie wyłączony (`KUKING_HTML_EDGE_CACHE_SECONDS=0`). Włączenie to decyzja operacyjna.
- Przy wygaśnięciu kandydatów pierwsze żądanie liczy `DISTINCT ON` (136 ms przy 30 tys. wpisów)
  bez blokady. Przy równoległych żądaniach liczą je wszystkie. Dziś pomijalne.

## Sprawdzone i w porządku

- **N+1:** brak na wszystkich 24 stronach. Liczba zapytań nie zależy od liczby kart (zgodnie z B4 i
  `docs/infra/evidence/wydajnosc-n1/tabela.txt`). Eager loading zdjęć, tagów, autorów i profili idzie
  jednym `IN` na stronę.
- **Feed obserwowanych po #599:** główne zapytanie ma koszt 4 964, 16 ms, bez JIT, przy 150
  obserwowanych i 30 tys. wpisów.
- **Wyszukiwarka:** `LIKE '%…%'` w `SearchQuery.php:188-192,517-519`, `CollectionController.php:158-164`,
  `ListaKont.php:139-147`, `CoUgotuje.php:92`, `PodpowiedziSkladnikow.php:51` i `TagSuggester.php:135`
  ma indeksy GIN trigram na tych samych wyrażeniach (`recipes_title_trgm_idx`, `…summary…`,
  `recipe_ingredients_text_trgm_idx`, `profiles_*_trgm_idx`, `users_email_trgm_idx`,
  `ingredients_name_trgm_idx`, `tags_name_trgm_idx`). Przy 60 tys. przepisów gałąź `<%` idzie po
  `recipes_title_trgm_idx`. Przy 6 tys. planer woli skan sekwencyjny (21 ms, a indeks dałby 2,6 ms),
  co przy tej wielkości nie ma znaczenia. „rosół” przy 60 tys. przepisów i 5 tys. trafień to 110 ms.
  Bez indeksu trigramowego zostaje `ILIKE` na surowych kolumnach w `DailyBoardCandidates.php:38`
  (panel, 16 ms przy 5 tys. kont; to B4 S8) i `jobs.payload LIKE` w komendzie `OdbiorZdjec.php:301`.
- **Indeksy kluczy obcych:** bez indeksu zostały tylko zimne ścieżki (`recipe_ingredients.ingredient_id`,
  `.unit_id`, `recipe_versions.editor_id`, `reports.resolved_by`, `tags.merged_into_tag_id`, tabele
  panelu). `notifications.actor_id` z B4 N13 ma już indeks. Zbędnych indeksów-prefiksów nie ma
  (zapytanie po `pg_index`: 0 wierszy).
- **Sortowania:** jedyne duże sortowania w pamięci to rotacja `/odkryj` i `/` (24 tys. wierszy,
  quicksort 2,2 MB, znane z #1952) i `DISTINCT ON` kandydatów (w cache). Reszta list sortuje po
  indeksie albo top-N.
- **Plakietka powiadomień:** sufit działa (koszt 17 249, 2 ms przy 2000 powiadomieniach).
- **Panel moderacji:** `/admin/zgloszenia` przy 1500 zgłoszeniach ma 16 zapytań i 49–63 ms.
  Powtórzone `count` zakładek to B4 N9.
- **Sesje:** `sessions_last_activity_index` jest, więc GC (loteria 2/100) idzie po indeksie. Wpis
  sesji przy każdym żądaniu gościa bez ciasteczka to znane zachowanie. Obejście jest w #610.

## Powiązane wcześniejsze prace (nie powtarzam)

`docs/audyt/2026-09-25-B4.md` (W1–W5, S1–S12, N1–N14), `docs/AUDYT_SKALOWANIE_2026-09.md`,
`docs/infra/FEED_OBSERWOWANYCH_JIT_599.md`, `docs/infra/ODKRYJ_KOSZT_1952.md`, D-311, D-312.
Duplikaty szukane przez wyszukiwarkę issues GitHuba (JIT, odkryj, powiadomienia, `statement_timeout`,
cache). Zapytanie `curl` do API było zablokowane przez strażnika środowiska, więc użyto narzędzia
MCP w trybie odczytu.
