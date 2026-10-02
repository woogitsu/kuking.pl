# Profile, obserwowanie, blokady, reakcje, ukrywanie

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### profiles
Wszystko, co człowiek pokazuje o sobie. `user_id` jest kluczem głównym —
jedno konto ma dokładnie jeden profil.

- `username varchar(40) NOT NULL` — nazwa w adresie `/@nazwa`. CHECK
  `^[a-zA-Z0-9_]{3,40}$`, unikalność bez rozróżniania wielkości liter
  (patrz niżej);
- **`display_name varchar(100) NOT NULL`** — „Jak mamy Cię nazywać?".
  **Wolny tekst**, pokazywany dosłownie. Nigdy nie doklejamy do niego
  przyimka ani słowa niosącego przypadek (D-153): „przez Krzysztof" jest
  formą błędną, a odmiany dowolnego ciągu znaków policzyć się nie da;
- **`bio varchar(500) NULL`** — „Kilka słów o sobie". Wolny tekst;
- `avatar_media_id uuid NULL` → `media` (`ON DELETE SET NULL`);
- **`region varchar(80) NULL`** — „Skąd jesteś", czyli REGION, nie adres
  (podpowiedź „Podkarpacie", pomoc mówi wprost: „Nie podawaj dokładnego
  adresu"). To jest wolny tekst, więc ludzie wpiszą tu, co zechcą — pole nie
  jest słownikiem województw i nie nadaje się do filtrowania ani do liczenia
  statystyk „skąd są nasi ludzie";
- **`speciality varchar(120) NULL`** — „Na czym się znasz" („zupy i
  kiszonki"). Wolny tekst, ten sam zastrzeżony status co przy `region`:
  nie jest tagiem ani kategorią;
- **`form_of_address varchar(10) NULL`** — „Jak mamy do Ciebie pisać?”
  (D-332, issue #1752). `feminine`, `masculine` albo `NULL` = **forma
  neutralna**, domyślna i pełnoprawna. To preferencja językowa, **nie płeć**:
  nie zgadujemy jej z imienia ani nie bierzemy z Google/Facebooka. Jest
  **widoczna dla innych** (teksty o tej osobie, np. „Ania ugotowała”), dlatego
  stoi na `profiles`, a nie przy ustawieniach wygody na `users`. Zwykła
  preferencja, nie pole sterujące — jest w `$fillable`, zapis wyłącznie przez
  `FormOfAddressController` z walidacją `in:`. Eksport RODO:
  `profil.forma_zwracania_sie` (`żeńska`/`męska`/`neutralna`);
- `display_name_search`, `username_search`, `speciality_search` — patrz
  „Kolumny `*_search`".

```sql
CHECK (form_of_address IS NULL OR form_of_address IN ('feminine','masculine'))  -- profiles_form_of_address_check
```

CHECK dodany jako `NOT VALID` i zwalidowany osobno (`$withinTransaction = false`,
AGENTS.md §6) — na istniejącej tabeli `profiles` nie blokuje zapisów.

**Rollback `form_of_address`** (migracja
`2026_09_25_140000_add_form_of_address_to_profiles`, D-088): `down()`
**odmawia**, gdy choć jeden profil ma wybraną formę — stary schemat nie ma
gdzie jej zapisać, a cofnięcie po cichu zamieniłoby wybór człowieka na formę
neutralną. Przy awaryjnym rollbacku wdrożenia nie cofa się tej migracji:
stary kod kolumny nie czyta. Na bazie bez wyborów cofnięcie przechodzi.

Wszystkie pola opisowe (`display_name`, `bio`, `region`, `speciality`)
oraz `form_of_address` idą do anonimizacji przy wykonaniu żądania z art. 17
RODO — patrz `data_erased_at` wyżej.

**Nazwy zastrzeżone** (`admin`, `moderacja`, `pomoc`, `platnosci`…) są
pilnowane w warstwie aplikacji: lista mieszka w `config/kuking.php`
(`account.reserved_usernames`), a sprawdza ją `App\Rules\ReservedUsername`
na obu drogach nadania nazwy — przy rejestracji i przy zmianie w ustawieniach
profilu. Świadomie NIE ma tu CHECK-a w bazie, choć AGENTS.md §6 każe
przedkładać ograniczenia bazodanowe nad walidację w PHP: ta lista będzie rosła
przy każdym nowym pomyśle na phishing, a CHECK oznaczałby migrację
za każdym razem. Sam kształt nazwy (`^[a-zA-Z0-9_]{3,40}$`) pilnuje CHECK,
bo on się nie zmienia.

Konta obsługi mają zastrzeżone nazwy legalnie, więc reguła działa tylko przy
ZMIANIE nazwy — inaczej @moderacja nie zapisałaby już nigdy własnego bio.

**Unikalność bez rozróżniania wielkości liter.** Unikalny indeks funkcyjny
`profiles_username_lower_unique` na `lower(username)` (migracja
`2026_09_05_220000_...`). Zwykły `UNIQUE` na `username` nie wystarczał, bo
PostgreSQL porównuje przez `=`: „Basia" rejestrowała się obok „basia", a
logowanie szuka nazwy JUŻ bez rozróżniania — przy dwóch pasujących wierszach
`->first()` bez `ORDER BY` oddawał ten, który baza akurat podała pierwszy.
Prawdziwa Basia mogła przez to dostawać „nieprawidłowe hasło" przy poprawnym
haśle (audyt A25).

Indeks jest funkcyjny, a nie na kolumnie, bo **nazwy zostają zapisane tak, jak
ktoś je wpisał**: „AniaGotuje" zostaje „AniaGotuje". Rozróżnienie dotyczy
wyłącznie tego, kto może nazwę zająć. Ten sam indeks obsługuje wyszukiwanie po
`lower(username)` w logowaniu i na profilu publicznym.

Migracja **nie przemianowuje** kont przy kolizji — sprawdza, czy takie pary
istnieją, i przerywa z listą nazw. Migracja zmieniająca komuś nazwę po cichu
jest gorsza niż migracja, która się nie wykonuje: kto zatrzymuje nazwę,
decyduje człowiek. Rollback to `DROP INDEX`, bez utraty danych.

### follows
`follower_id + followed_id` unique (to jest KLUCZ GŁÓWNY — relacja jest
tożsamością, nie ma sztucznego `id`). `CHECK (follower_id <> followed_id)`.

**Wyzwalacz `follows_blokada_ma_pierwszenstwo_trg` (`BEFORE INSERT`)** —
migracja `2026_09_10_400000_obserwowanie_nie_wspolistnieje_z_blokada`,
decyzja **D-080**. Odrzuca `INSERT`, jeśli dla tej pary istnieje
zatwierdzona blokada w KTÓRĄKOLWIEK stronę. Jest to twarda bariera dla
każdej drogi zapisu — także takiej, która omija `App\Domain\Social\Actions\
FollowUser` (druga akcja dopisana kiedyś, komenda konsolowa, seeder, ręczny
`INSERT` w psql podczas awarii).

Czego wyzwalacz NIE daje: odporności na równoległość. Przy `READ COMMITTED`
nie widzi blokady, która nie jest jeszcze zatwierdzona, więc dwa równoległe
żądania mogłyby przejść oba. Za to odpowiada `App\Domain\Social\ZamekPary`
(blokada wierszy OBU kont, **rosnąco po identyfikatorze**, plus ponowne
sprawdzenie warunku pod blokadą). Bariera pilnuje DRÓG ZAPISU, blokada
pilnuje RÓWNOLEGŁOŚCI — trzeba obu.

Kolejność blokowania wierszy jest częścią kontraktu, nie detalem: gdyby dwie
operacje na tej samej parze brały wiersze w przeciwnych kolejnościach,
zakleszczyłyby się i PostgreSQL zabiłby jedną z transakcji.

Koszt: jedno indeksowane `EXISTS` na `blocks` przy każdym `INSERT` do
`follows` (nie ma `UPDATE` na tej tabeli). `blocks` ma klucz główny
`(blocker_id, blocked_id)` i indeks `blocks_blocked_idx`, więc oba kierunki
idą po indeksie.

**Rollback:** `down()` zdejmuje wyzwalacz i funkcję. Bezstratny — nie zmienia
danych — i wolno go wykonać na produkcji pod ruchem, bo żaden kod nie zależy
od wyzwalacza. Migracja **nie sprząta danych istniejących**: gdyby leżał już
wiersz-sierota z wyścigu sprzed D-080, wyzwalacz go nie ruszy. Znalezienie
takich wierszy (sprzątanie to osobna, jawna decyzja — kasowanie relacji
społecznych migracją jest destrukcyjną operacją z zakazu `AGENTS.md` §6):

```sql
SELECT f.follower_id, f.followed_id
FROM follows f
JOIN blocks b
  ON (b.blocker_id = f.follower_id AND b.blocked_id = f.followed_id)
  OR (b.blocker_id = f.followed_id AND b.blocked_id = f.follower_id);
```

Uwaga przy odtwarzaniu bazy: `migrate:fresh` (`db:wipe`) kasuje TABELE, nie
funkcje, więc `follows_blokada_ma_pierwszenstwo()` przeżywa czyszczenie.
Migracja robi `CREATE OR REPLACE`, więc nie ma z tego kolizji — ale nie
zdziw się, widząc tę funkcję w bazie, w której nie ma jeszcze tabeli
`follows`.

### blocks
Blokada ma pierwszeństwo przed follow — i to jest wymuszone w bazie, nie
tylko w PHP (patrz `follows` wyżej). `blocker_id + blocked_id` jako klucz
główny, `CHECK (blocker_id <> blocked_id)`, indeks `blocks_blocked_idx`.

**Na `blocks` NIE MA wyzwalacza i nie będzie** (D-080). Blokada musi się
udać zawsze: jest jedyną czynnością, jaką człowiek ma, gdy ktoś staje się
dla niego problemem, a bariera potrafiąca jej odmówić byłaby zamkniętymi
drzwiami w najgorszym momencie. Konflikt na tej stronie rozstrzyga
`App\Domain\Social\Actions\BlockUser`, kasując obserwowanie w obie strony
pod blokadą wierszy.

### post_reactions
Reakcja „Smakowicie wygląda” (issue #1813, D-280). Migracja
`2026_09_26_110000_create_post_reactions_table.php`.

- `id uuid` (PK, `gen_random_uuid()`),
- `post_id uuid NOT NULL` → `posts` (`ON DELETE CASCADE`),
- `user_id uuid NOT NULL` → `users` (`ON DELETE CASCADE`) — kto napisał,
- `notified_at timestamptz NULL` — kiedy weszła do zbiorczego powiadomienia
  (raz dziennie, `kuking:powiadom-smakowicie`); `NULL` = czeka,
- `created_at timestamptz`.

`UNIQUE (post_id, user_id)` — reakcja to STAN jednej osoby przy jednym wpisie
(odwrotnie niż `cooked_events`, gdzie unikalność jest zakazana). Indeks
częściowy `post_reactions_pending_idx (created_at) WHERE notified_at IS NULL`
pod zbiorcze powiadomienie, indeks `user_id` pod kaskadę konta.

Bez licznika: żadna lista nie sortuje ani nie przycina po tej tabeli
(`FeedNieSortujePoMierzeReakcjiTest` zna słowo „reaction”). Stan widza na karcie
to `EXISTS` w `ZapisyWpisu::dolicz()`. Kto zareagował — każdy widz na stronie
wpisu (od 26.09.2026; wcześniej tylko autor), bez liczby, bez osób z blokadą
autora albo widza i bez kont niedostępnych (`App\Domain\Reakcje\Smakowicie::ktoDla()`). Eksport:
`moje_reakcje` i `reakcje_otrzymane` — ta druga z nazwą konta tylko przy
osobach, które autor zobaczyłby przy wpisie (`osobyWidoczneDlaAutora()`, filtry autora z `ktoDla()`),
reszta jako liczba w `reakcje_otrzymane_od_osob_niewidocznych`.

**Kaskada działa tylko przy twardym usunięciu.** Konta się anonimizuje
(D-022), więc reakcje wymazywanego konta (`user_id`) kasuje jawnie
`EraseAccountData` — przy każdym `delete_scope`. Reakcja pod wpisem usuniętym
(soft delete) zostaje w tabeli, ale zbiorcze powiadomienie jej nie liczy.

**Rollback:** `down()` ODMAWIA, gdy w tabeli są reakcje (słowa ludzi do autorów,
`up()` ich nie odtworzy — D-088); na pustej przechodzi. Ręcznie:
`\copy post_reactions TO reakcje.csv CSV HEADER`, decyzja właściciela, potem
usunięcie wierszy. Test: `SmakowicieWygladaTest::test_rollback_odmawia…`.

### hides
Prywatne ukrycia jednego widza (issue #1810, D-278): „Ukryj ten wpis” i „Ukryj
tę osobę”. Migracja `2026_09_26_100000_create_hides_table.php`.

- `id uuid` (PK, `gen_random_uuid()`),
- `user_id uuid NOT NULL` → `users` (`ON DELETE CASCADE`) — kto ukrywa,
- `post_id uuid NULL` → `posts` (`ON DELETE CASCADE`) — ukryty wpis,
- `hidden_user_id uuid NULL` → `users` (`ON DELETE CASCADE`) — ukryta osoba,
- `hidden_until timestamptz NULL` — do kiedy; `NULL` = na stałe („Zostaw
  ukryte”). Po terminie wiersz nic nie ukrywa (`Hide::scopeAktywne()`),
- `created_at`, `updated_at`.

Ograniczenia: `hides_one_target_check` (`num_nonnulls(post_id, hidden_user_id)
= 1`), `hides_not_self_check` (`hidden_user_id <> user_id`), unikalne indeksy
częściowe `hides_user_post_unique (user_id, post_id)` i
`hides_user_person_unique (user_id, hidden_user_id)` — ponowne ukrycie
przedłuża wiersz. Indeksy na `post_id` i `hidden_user_id` pod kaskadę
oraz na `user_id` pod listę widza, eksport i wymazanie konta (indeksy
częściowe `WHERE … IS NOT NULL` tego zapytania nie obsłużą).

**Retencja (polityka prywatności, wiersz „Ukrywanie wpisów i osób”, #1816).**
Wiersz po `hidden_until` nic nie ukrywa, ale **żadne zadanie go nie czyści** —
zostaje do „Przywróć” (tylko przy aktywnym ukryciu — lista pokazuje wyłącznie
aktywne) albo do wymazania konta. Polityka mówi to wprost; sprzątanie wygasłych
wierszy wymagałoby decyzji właściciela i osobnej komendy.

**Kaskada działa tylko przy twardym usunięciu.** Konta się anonimizuje
(D-022), więc ukrycia wymazywanego konta (`user_id`) kasuje jawnie
`EraseAccountData` — przy każdym `delete_scope`. Wpisy mają soft delete:
ukrycie usuniętego wpisu zostaje, a lista pokazuje je jako „Ten wpis jest już
niedostępny” z „Przywróć”; treść i autora wpisu lista i eksport pokazują
tylko wtedy, gdy widz dziś ten wpis zobaczy (`Ukrycia::widoczneWpisy()`).

Czytają ją wyłącznie filtry strumieni TEGO widza (`Post::scopeBezUkrytychWpisow`,
`Post::scopeBezUkrytychOsob`, `DailyBoard::ukryteOsobyDla()`, warunek w
`ZbierzTresciDigestu`), lista `/ustawienia/ukryte`, eksport i wymazanie konta. **Nigdy**
moderacja ani analityka — pilnuje `UkryjWpisIOsobeTest::test_bez_agregacji…`.
Eksport: `hides.user_id` w sekcji `ukryte`; `hides.hidden_user_id` na żądanie
(art. 15 ust. 4, jak `blocks.blocked_id`).

**Rollback:** `down()` ODMAWIA, gdy jest choć jedno aktywne ukrycie (na stałe
albo z terminem w przyszłości) — zrzucenie tabeli cicho przywróciłoby ludziom
schowane wpisy i osoby (D-088). Na pustej tabeli i przy samych wygasłych
ukryciach przechodzi. Ręcznie: `\copy hides TO hides.csv CSV HEADER`, decyzja
właściciela, potem usunięcie wierszy. Test: `CofniecieMigracjiUkrycNieOdslaniaTest`.
