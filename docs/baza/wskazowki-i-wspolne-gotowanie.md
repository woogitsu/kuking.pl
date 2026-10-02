# Wskazówki od gotujących i wspólne gotowanie

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### recipe_hints

Wskazówki od gotujących (#2352; decyzja właściciela z 1.10.2026, **D-333**,
wiersz „#2352”). Migracja `2026_10_01_160000_create_recipe_hints_table`.
Autor przepisu PROPONUJE, żeby uwagę z czyjegoś wykonania („Ugotowałem”
z notatką) stała przy jego przepisie jako wskazówka; kucharz dostaje prośbę
i odpowiada „Zgadzam się” albo „Nie”. **Zgoda na wniosek:** brak odpowiedzi
to brak publikacji. Zgodę można wycofać w każdej chwili — wskazówka znika.

**Tabela NIE kopiuje tekstu.** Treścią wskazówki jest `cooked_events.note`,
które jest niezmienne (nie ma edycji wykonania) — jedna kopia danych kucharza
zamiast dwóch, a usunięcie wykonania zabiera wskazówkę kaskadą.

| kolumna | typ | uwagi |
|---|---|---|
| `id` | `uuid` | `DEFAULT gen_random_uuid()` |
| `recipe_id` | `uuid` | → `recipes(id)` `ON DELETE CASCADE`: przepis, przy którym stoi wskazówka (soft delete przepisu chowa ją z widoku, bo strona przepisu znika) |
| `cooked_event_id` | `uuid` | → `cooked_events(id)` `ON DELETE CASCADE`; **UNIKALNE** (patrz niżej) |
| `author_id` | `uuid` | → `users(id)` `ON DELETE CASCADE` (pas bezpieczeństwa; konta się anonimizuje): autor przepisu, który prosi |
| `cook_id` | `uuid` | → `users(id)` `ON DELETE CASCADE`: kucharz, który odpowiada |
| `status` | `varchar(10)` | `proposed` (czeka), `accepted` (stoi przy przepisie), `declined` („Nie”, ostateczne), `withdrawn` (kucharz wycofał zgodę, ostateczne), `cancelled` (autor sam wycofał czekającą prośbę, ostateczne; migracja `2026_10_01_190000`) |
| `recipe_version_number` | `integer` NULL | numer wersji przepisu z chwili prośby (bez klucza obcego — wersje podlegają retencji); strona przepisu dopisuje „Przepis był zmieniany po tej wskazówce.”, gdy najnowsza wersja jest wyższa |
| `decided_at` | `timestamptz` NULL | kiedy kucharz odpowiedział („Zgadzam się” albo „Nie”); porządkuje wskazówki na stronie przepisu (kolejność zgód, bez rankingu) |
| `withdrawn_at` | `timestamptz` NULL | kiedy kucharz wycofał zgodę |
| `moderation_hidden_at` | `timestamptz` NULL | od kiedy moderacja ukryła **samą wskazówkę** (migracja `2026_10_01_200000_wskazowka_jako_cel_zgloszenia`, #2352); `NULL` = nie ukryta. Pole sterujące: poza `$fillable`, ustawia je wyłącznie decyzja `hide` (`RecipeHint::ukryjPrzezModeracje()`), zdejmuje uznane odwołanie albo ręczne „Przywróć wskazówkę” (`PrzywrocWskazowke`). To osobny znacznik, nie `status` — stan zgody kucharza zostaje, jaki był |
| `created_at` / `updated_at` | `timestamptz` | `created_at` = data prośby (limit dobowy autora) |

**Pola sterujące poza `$fillable`.** `RecipeHint::$fillable` jest PUSTE:
`status`, klucze osób i treści oraz znaczniki decyzji ustawiają wyłącznie
akcje domenowe (`App\Domain\Wskazowki\ZaproponujWskazowke`,
`PrzyjmijWskazowke`, `OdrzucWskazowke`, `WycofajWskazowke`), a przejścia stanu
to nazwane metody modelu (`przyjmij()`, `odrzuc()`, `wycofaj()`) — nigdy
`update()` z żądania (AGENTS.md §7). Test: `tests/Feature/WskazowkiOdGotujacychTest.php`.

Ograniczenia (nowa tabela, więc razem z `CREATE TABLE` — AGENTS.md §6):

- `recipe_hints_status_check` — `status IN ('proposed', 'accepted', 'declined', 'withdrawn', 'cancelled')`;
- `recipe_hints_stan_spojny_check` — stan i znaczniki mówią to samo:
  `proposed` i `cancelled` bez `decided_at` i `withdrawn_at` (kucharz nie
  odpowiedział; moment anulowania to `updated_at`); `accepted`/`declined` z
  `decided_at`, bez `withdrawn_at`; `withdrawn` z oboma;
- `recipe_hints_autor_nie_kucharz_check` — `author_id <> cook_id`;
- `recipe_hints_wersja_check` — numer wersji `NULL` albo `>= 1`;
- `recipe_hints_cooked_event_unique` — **jedno wykonanie ma najwyżej jedną
  wskazówkę w całym swoim życiu.** To jest świadome: „Nie” i wycofanie zgody
  są ostateczne, więc autor nie może ponawiać prośby (presja na kucharza), a
  ponowna prośba o to samo wykonanie jest odrzucana tym samym zdaniem bez
  względu na to, czy poprzednia czeka, została przyjęta, odrzucona czy
  wycofana — autor nie poznaje po komunikacie, że ktoś odmówił;
- `recipe_hints_ukrycie_check` — `moderation_hidden_at IS NULL OR status IN ('accepted',
  'withdrawn')`: ukryć można tylko wskazówkę, na którą kucharz się zgodził
  (`NOT VALID` + `VALIDATE`, #2352);
- `recipe_hints_przyjete_idx (recipe_id, decided_at, id) WHERE status = 'accepted'`
  (strona przepisu), `recipe_hints_recipe_idx` (klucz obcy),
  `recipe_hints_cook_idx (cook_id, status)` (kucharz, eksport, wymazanie),
  `recipe_hints_author_idx (author_id, created_at)` (limity, eksport).

**Cache brzegu (`KUKING_HTML_EDGE_CACHE_SECONDS` > 0).** Sekcja wskazówek jest
częścią HTML `recipes.show`, a aplikacja nie czyści tego cache przy zmianie
wskazówki (czyszczenie dotyczy tylko zdjęć). Gość może więc widzieć wskazówkę po
wycofaniu zgody (albo ukryciu przez moderację) **do TTL** (najwyżej 300 s);
wycofanie zgody działa w aplikacji od razu, opóźnienie dotyczy wyłącznie kopii u
brzegu. Opis okna: `docs/infra/CLOUDFLARE_CACHE_597_610.md`.

**Reguły (Policy `RecipeHintPolicy`, akcje pod blokadą).**

- *Tylko autor przepisu proponuje* (`propose`): wykonanie cudzego, z niepustą
  uwagą (`note`), przepis opublikowany, autor aktywny (zawieszenie odcina od
  pisania), kucharz może czytać i nie jest zbanowany/w karencji, **brak
  blokady** w którąkolwiek stronę. Limity: `kuking.wskazowki.na_przepis_max`
  (10 czekających i przyjętych łącznie, **bez ukrytych przez moderację** — ukryta
  wskazówka zwalnia miejsce, `RecipeHint::scopeZajmujaceMiejsce`) i `na_dobe_max`
  (10 próśb dziennie). Przywrócenie ukrytej wskazówki (ręczne albo po uznanym
  odwołaniu) **nie sprawdza limitu**, więc przepis może mieć ponad 10 wskazówek;
  nowe prośby czekają, aż liczba zajętych miejsc spadnie poniżej limitu, a strona
  przepisu pokazuje najwyżej `na_stronie_max` (20) wskazówek w kolejności zgód.
- *Tylko kucharz decyduje* (`answer` → `accept` / `decline` / `withdraw`).
  „Zgadzam się” wymaga braku blokady z autorem, aktywnego konta i dostępnego
  przepisu; **„Nie” i „Wycofaj zgodę” zostają kucharzowi także przy blokadzie
  i zawieszeniu** (RODO art. 7 ust. 3 — cofnięcie zgody tak łatwe jak jej
  udzielenie; `EnsureAccountIsActive` ma obie trasy na liście dozwolonych).
- Kolejność zamków jak w całym serwisie, **we wszystkich czterech akcjach**:
  `ZamekPary` (oba konta rosnąco po `id`, D-080), potem wiersz wskazówki
  `FOR UPDATE` (prośba: konta, potem wiersz wykonania). Wykonania ani przepisu
  przy odpowiedzi nie blokujemy — usunięcie wykonania kasuje wiersz kaskadą i
  blokada na wykonaniu obok blokady na wskazówce dałaby zakleszczenie. Odmowa
  i wycofanie też idą najpierw przez konta: wpis w `audit_log` trzyma
  współdzieloną blokadę wiersza konta (klucz obcy), więc kolejność „wskazówka,
  potem konto” zakleszczała się ze „Zgadzam się” (konta, potem wskazówka) —
  złapane przez `tests/Dwa/WskazowkiNaDwochPolaczeniachTest.php`. Akcje są
  idempotentne (podwójne „Zgadzam się” nic nie zmienia).
- *Widoczność:* strona przepisu pokazuje wyłącznie `accepted` z wykonań, które
  widz może zobaczyć (`CookedEvent::scopeWidoczneDla` — blokady, konta
  zbanowane i w karencji usunięcia), w kolejności `decided_at`, bez rankingu,
  jednym zapytaniem z kucharzem, profilem i awatarem.
- *Powiadomienia:* `Notification::TYPE_HINT_PROPOSED` (`recipe_hint.proposed`)
  do kucharza, aktorem jest autor; `Notification::TYPE_HINT_ACCEPTED`
  (`recipe_hint.accepted`, 1.10.2026) do autora przepisu, aktorem jest kucharz,
  tylko przy „Zgadzam się”, w tej samej transakcji. Oba tylko w serwisie, bez
  Web Push. „Nie”, wycofanie zgody, anulowanie i wygaśnięcie **nie powiadamiają
  nikogo**.
- *Moderacja (od decyzji właściciela z 1.10.2026, migracja
  `2026_10_01_200000_wskazowka_jako_cel_zgloszenia`):* wskazówka ma **własny cel
  zgłoszenia** `recipe_hint` — „Zgłoś” przy wskazówce w sekcji przy przepisie
  otwiera zgłoszenie SAMEJ wskazówki (`target_id` = `recipe_hints.id`), a decyzja
  `hide` zdejmuje ją z sekcji (`moderation_hidden_at`), **nie ruszając wykonania**
  ani uwagi pod nim. Szczegóły niżej w „Zgłoszenie wskazówki”. Zgłoszenie całego
  wykonania (`cooked_event`, decyzja `remove` kasuje wykonanie, a z nim kaskadą
  wskazówkę) zostaje osobno, na stronie wykonania; ban kucharza dalej chowa
  wskazówkę (`scopeWidoczneDla`).

**Zgłoszenie wskazówki (#2352, `reports.target_type = 'recipe_hint'`).**

- *Kto zgłasza* (`RecipeHintPolicy::report`): każdy, kto wskazówkę **widzi w
  sekcji przy przepisie** — gość przez logowanie (jak przy innych celach), obca
  osoba i autor przepisu. Wskazówka musi być `accepted`, nieukryta, przepis
  opublikowany i widoczny dla zgłaszającego, kucharz dostępny jako autor, brak
  blokady zgłaszającego z kucharzem. Czekająca, odrzucona, wycofana, anulowana i
  ukryta przez moderację jest dla zgłaszającego **404** (zgłoszenie nie zdradza
  istnienia). Kucharz nie ma przycisku przy własnej wskazówce (ma „Wycofaj
  zgodę”), choć bramka — jak przy każdej własnej treści — go nie blokuje.
- *Decyzje* (`ModerationAction::DOZWOLONE['recipe_hint']`): bez działania,
  ostrzeżenie, **ukrycie wskazówki**, zawieszenie, ban — **bez `remove`**
  (wskazówka nie jest osobną treścią kucharza, a skasowanie wiersza pozwoliłoby
  autorowi prosić o to samo wykonanie drugi raz). Adresatem decyzji jest
  **kucharz** (autor uwagi), nie autor przepisu: `ModeratedContent::osoba()` ma
  dla wskazówki osobną gałąź, bo relacja `author` wskazówki to autor PRZEPISU.
  Ukrycie idzie pod blokadą (`RecipeHint::zablokujDoDecyzji()`: konta kucharza i
  autora rosnąco po `id`, potem wiersz — ta sama kolejność co „Wycofaj zgodę”);
  wskazówka, która nie stoi już przy przepisie (wycofana, już ukryta), nie
  przyjmuje „ukryj” — decyzja wraca błędem przy zgłoszeniu zamiast pozornego sukcesu.
  `user_message` zaczyna się zdaniem wskazującym wskazówkę (`WskazanieWskazowki`).
- *Odwołanie kucharza* jak od każdej decyzji `hide` (DSA art. 17); uznane
  odwołanie (`ResolveAppeal`) zdejmuje `moderation_hidden_at` i zapisuje `unhide`.
  **Zgoda kucharza jest ważniejsza niż decyzja moderacji:** jeśli w międzyczasie
  wycofał zgodę, wskazówka zostaje `withdrawn`, znika tylko ślad moderacji. Moderator
  nie przywraca wskazówki, której sam jest autorem (`WlasnejTresciNiePrzywracasz`).
- *Ręczne „Przywróć wskazówkę” bez odwołania* (`PrzywrocWskazowke`, trasa
  `admin.hints.restore`, decyzja właściciela z 1.10.2026): czynna moderacja
  zdejmuje `moderation_hidden_at` i zapisuje decyzję `unhide` (`report_id` NULL,
  powód obowiązkowy, wpis `moderation.restored` w dzienniku). Nie w sprawie, w
  której moderator jest stroną (kucharz albo autor przepisu —
  `RecipeHintPolicy::restore`), decyzję administratora cofa administrator
  (`RestoreContent::wolnoCofnac`). Zamek jak ukrycie: konta aktora, kucharza i
  autora przepisu w jednym przebiegu rosnąco po `id`, potem wiersz wskazówki.
  Kucharz dostaje powiadomienie „jest znowu widoczna” tylko wtedy, gdy zgoda
  nadal obowiązuje; po wycofaniu zgody znika ślad moderacji, ale nic nie wraca
  publicznie i nie ma wiadomości. Bez zmiany schematu.
  Uznane odwołanie *zgłaszającego* od „Bez działania” może wydać nową decyzję
  `hide` (`DecyzjaPoOdwolaniu`).
- *Widoczność:* `RecipeHint::scopePrzyjeteDlaPrzepisu` pomija ukryte; ukryta
  zwalnia miejsce w limicie przepisu (przywrócenie wolno ponad limit — patrz wyżej).
  Kucharz widzi na stronie wykonania „Ta wskazówka została ukryta przez
  moderację”, autor przepisu — to samo neutralne zdanie co przy „Nie”. Paczka RODO
  kucharza ma `ukryta_przez_moderacje_dnia`; paczka autora przepisu nie ujawnia
  ukrycia.
- *Retencja:* przepis, którego wskazówka jest przedmiotem zgłoszenia lub decyzji,
  nie przechodzi w nagrobek (`PrzedawnioneUsunieteTresci`).
- *Raport przejrzystości* ma osobny wiersz „wskazówka od gotujących” (sekcja 1a).
- **Rollback migracji ODMAWIA** (D-088) w dwóch przypadkach: w `reports` leży
  zgłoszenie wskazówki (węższy CHECK by je odrzucił) albo choć jedna wskazówka
  jest ukryta (zdjęcie kolumny odsłoniłoby ją publicznie, a ponowna migracja nie
  przywróciłaby ukrycia). Bez takich wierszy przechodzi. Test:
  `ModeracjaWskazowekTest::test_rollback_odmawia_gdy_jest_zgloszenie_albo_ukryta_wskazowka_a_bez_nich_przechodzi`.

**Rollback.** `down()` ODMAWIA, gdy w tabeli są wiersze (D-088): kasowanie
tabeli zabrałoby ludziom odpowiedzi „Nie” i wycofania zgód, a po ponownej
migracji autorzy mogliby prosić o zgodę drugi raz. Komunikat mówi, co zrobić
ręcznie (kopia tabeli, ponowny rollback z `KUKING_ROLLBACK_KASUJE_WSKAZOWKI=1`).
Na świeżej i pustej bazie — w CI i przy `migrate:refresh` — przechodzi bez
pytania. Odmowa i kontrola dodatnia: `tests/Feature/WskazowkiOdGotujacychTest.php`.

**Wymazanie konta** (`EraseAccountData`), niezależnie od zakresu usunięcia,
kasuje wszystkie wiersze z `cook_id` tego konta (zgoda na wskazówkę przy
cudzym przepisie jest zgodą tej osoby; po wymazaniu nie ma kto jej
podtrzymać) oraz **czekające** prośby wysłane przez to konto jako autora
(kucharz nie odpowiada osobie, której już nie ma). Przyjęte wskazówki przy
przepisie zostającym po koncie autora (zakres `minimum`) stoją dalej — to treść
kucharza, który się zgodził. Zakres `everything` kasuje przepisy, a z nimi
kaskadą wskazówki. **Paczka RODO** wydaje obie strony: `wskazowki_z_moich_wykonan`
(moja uwaga, stan zgody, daty; tytuł i autor przepisu tylko przy przepisie
widocznym dla osoby) i `wskazowki_do_moich_przepisow` (stan próśb o moich
przepisach **bez** nazwy kucharza i bez jego tekstu; „Nie” i wycofanie zgody
wyglądają tu tak samo — „nie jest dostępna jako wskazówka”).

**Wygasanie czekającej prośby (1.10.2026).** Prośba `proposed` starsza niż
`kuking.wskazowki.prosba_wygasa_po_dniach` (30) od `created_at` **wygasa bez
zmiany stanu i bez zadania w tle**: `RecipeHint::wygasla()` liczy wiek wiersza
przy każdym odczycie. Wygasła nie przechodzi przez `accept`, `decline` ani
`cancel` (Policy), nie zajmuje miejsca w limicie przepisu
(`RecipeHint::scopeZajmujaceMiejsce` — przyjęte i niewygasłe czekające), a
kucharz widzi na stronie wykonania zdanie „Ta prośba wygasła” bez przycisków.
**Wygasła prośba jest końcem**: `recipe_hints_cooked_event_unique` zostaje, więc
nowej prośby o to samo wykonanie nie ma (bez presji na kucharza). Bez migracji.

**Anulowanie (1.10.2026).** Autor anuluje własną czekającą prośbę
(`AnulujProsbeOWskazowke`, trasa `hints.cancel`, `RecipeHintPolicy::cancel`)
pod tym samym zamkiem co pozostałe akcje (`ZamekPary`, potem wiersz
`FOR UPDATE`), więc anulowanie kontra „Zgadzam się” ustawia się w kolejce —
test na dwóch połączeniach w `tests/Dwa/WskazowkiNaDwochPolaczeniachTest.php`.
Przejście `proposed → cancelled` (metoda modelu `anuluj()`), wpis w
`audit_log` `recipe_hint.cancelled`, brak powiadomienia. Wymazanie konta autora
kasuje też jego wiersze `cancelled`.

**Rollback migracji `2026_10_01_190000_add_cancelled_status_to_recipe_hints`.**
Oba CHECK-i wchodzą `NOT VALID` + `VALIDATE` poza transakcją (AGENTS.md §6).
`down()` **ODMAWIA**, gdy są wiersze `cancelled` (D-088): stare CHECK-i nie znają
tego stanu, a zamiana na `proposed` wskrzesiłaby wycofaną prośbę, skasowanie
pozwoliłoby prosić drugi raz. Komunikat mówi, co zrobić ręcznie (kopia tabeli,
decyzja co do wierszy). Bez wierszy `cancelled` cofnięcie przechodzi bez
pytania. Test: `tests/Feature/WskazowkiWygasanieAnulowanieZgodaTest.php`.

### cooking_sessions + cooking_session_participants + cooking_session_steps + cooking_session_invitations — wspólne gotowanie (#2385)

Sesja jednego przepisu dla gospodarza i do trzech pomocników (`max_pomocnikow` = 3, decyzja właściciela z 1.10.2026).
Migracja `2026_10_01_170420_create_cooking_sessions_tables`. Projekt,
autoryzacja i retencja: `docs/product/PROJEKT_WSPOLNE_GOTOWANIE_2385.md`.
Wszystkie modele mają pusty `$fillable`: wiersze powstają i zmieniają się
wyłącznie przez `App\Domain\Recipes\Gotowanie\Wspolne\*`.

**`cooking_sessions`**

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK, `DEFAULT gen_random_uuid()` | UUID w adresie NIE jest autoryzacją (`CookingSessionPolicy`: nie-członek dostaje 404) |
| `recipe_id` | `uuid NOT NULL` → `recipes` (`ON DELETE CASCADE`) | przepis (aktualna wersja, jak tryb gotowania) |
| `host_id` | `uuid NOT NULL` → `users` (`ON DELETE CASCADE`) | gospodarz; poza `$fillable` |
| `status` | `varchar(16) NOT NULL DEFAULT 'active'` | pole STEROWNICZE; `CHECK status IN ('active')` — zakończenie kasuje sesję, nie ma stanu „zakończona” |
| `revision` | `integer NOT NULL DEFAULT 1` | rośnie o 1 przy każdej REALNEJ zmianie postępu; druga osoba po niej widzi zmianę; `CHECK revision >= 1` |
| `expires_at` | `timestamptz NOT NULL` | `kuking.wspolne_gotowanie.retencja_godziny` (24 h) od założenia, stały termin; `CHECK expires_at > created_at` |
| `created_at`, `updated_at` | `timestamptz` | |

Indeksy: `UNIQUE (host_id, recipe_id)` (jedna sesja na parę), `expires_at`
(nocne sprzątanie), `recipe_id` (klucz obcy).

**`cooking_session_participants`** — pomocnicy (gospodarz jest w `host_id`).
`PRIMARY KEY (session_id, user_id)`; `session_id` → `cooking_sessions`
(`CASCADE`), `user_id` → `users` (`CASCADE`); `role varchar(16)`
(`CHECK role IN ('helper')`, nowa rola = świadoma zmiana schematu);
`joined_at timestamptz`. Liczbę pomocników ogranicza akcja pod blokadą wiersza
sesji (`max_pomocnikow`, domyślnie 3), nie baza. Link zaproszenia jest wielorazowy
(decyzja z 1.10.2026): jeden żywy link wpuszcza kolejne osoby, aż jest komplet; częściowy unikalny
indeks `…_one_pending_idx` to najwyżej jeden żywy link na sesję. Bez zmian schematu względem etapu 1.

**`cooking_session_steps`** — wspólny postęp. `PRIMARY KEY (session_id,
step_id)`; `step_id` → `recipe_steps` (`CASCADE`: krok usunięty z przepisu
znika z postępu); `done_by_id` → `users` (`ON DELETE SET NULL`: po usunięciu
konta podpis znika, krok zostaje zrobiony); `done_at timestamptz`. Dwa
równoczesne odhaczenia tego samego kroku = `INSERT … ON CONFLICT DO NOTHING`
= jeden wiersz bez błędu; cofnięcie to `DELETE`.

**`cooking_session_invitations`** — wielorazowy link do `max_pomocnikow` osób (przyjęcie niczego tu nie zmienia).

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK | |
| `session_id` | `uuid NOT NULL` → `cooking_sessions` (`CASCADE`) | |
| `token_hash` | `varchar(64) NULL` | SHA-256 tokenu (40 znaków losowych); poświadczenie, poza `$fillable`, `$hidden`; kasowany przy odwołaniu; token jawny istnieje tylko w odpowiedzi tworzącej link |
| `status` | `varchar(16) NOT NULL DEFAULT 'pending'` | `pending`/`accepted`/`revoked` (`cooking_session_invitations_status_check`) |
| `expires_at` | `timestamptz NOT NULL` | `link_godziny` (24 h), nigdy dalej niż sesja |
| `accepted_by_id` | `uuid NULL` → `users` (`ON DELETE SET NULL`) | nieużywana od decyzji z 1.10.2026 o linku wielorazowym (nikt nie „zużywa” linku); zostaje jako pozostałość, kod jej nie ustawia, wymazanie konta zeruje |
| `responded_at` | `timestamptz NULL` | przyjęcie/odwołanie; `CHECK status <> 'accepted' OR responded_at IS NOT NULL` |

CHECK `cooking_session_invitations_token_check`: oczekujący MA skrót, odwołany
go NIE MA; `accepted` (z pierwotnego projektu „jeden link = jedna osoba”) schemat dopuszcza, ale kod go
nie ustawia — link wielorazowy zostaje `pending` do wygaśnięcia, odwołania albo nowego linku. Częściowe unikalne indeksy: `token_hash` (tam, gdzie nie
NULL) i `(session_id) WHERE status = 'pending'` — najwyżej jeden żywy
link w sesji (nowy unieważnia stary).

**Prywatność i retencja.** Sesja wygasa 24 h po założeniu; wygasła jest dla
serwisu nieistniejąca (odczyt ją ignoruje), a
`kuking:sprzataj-wspolne-gotowanie` (03:20) ją kasuje z całą zawartością.
Zakończenie przez gospodarza kasuje sesję od razu. Blokada gospodarz ↔ pomocnik oraz
pomocnik ↔ pomocnik (wypada zablokowany) i wymazanie konta kończą udział przez kontrakt
`Users\KoniecWspolnegoGotowania` (wołany z `BlockUser` i `EraseAccountData`,
D-302/#971). Paczka danych ma sekcję `wspolne_gotowanie` (bez tokenów i bez
danych drugiej osoby). Minutniki nie są synchronizowane (jak w #2016).

**Rollback.** `down()` usuwa cztery tabele i **odmawia** (D-088), gdy jest
choć jedna NIEWYGASŁA sesja; na świeżej bazie, w CI (`migrate:refresh`) i przy
samych wygasłych sesjach przechodzi bez pytania. Wymuszenie:
`KUKING_ROLLBACK_KASUJE_WSPOLNE_GOTOWANIE=1` albo wcześniej
`php artisan kuking:sprzataj-wspolne-gotowanie --wszystkie`. Test:
`WspolneGotowanieMigracjaTest`.
