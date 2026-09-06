# Model danych

## Zasady

- UUID dla publicznych encji;
- `timestamptz`;
- realne foreign keys;
- constraints w bazie;
- soft delete tam, gdzie pomaga odzyskiwaniu/moderacji;
- JSONB tylko dla półstrukturalnych danych;
- recipe versions od początku.

## Tabele MVP

### users
Konto:
- id;
- email;
- password;
- status;
- `status_expires_at` — kiedy kara mija (patrz niżej);
- `delete_requested_at` — kiedy zgłoszono usunięcie konta (status `pending_delete`);
- `data_erased_at` — kiedy karencja się WYKONAŁA, dane zostały zanonimizowane
  (patrz niżej);
- locale;
- text_scale;
- verified timestamps.

#### `status_expires_at` — termin wygaśnięcia kary

Migracja `2026_09_05_001400_add_status_expires_at_to_users` (issue #40).

`docs/legal/MODERATION_PLAYBOOK.md` przewiduje blokady czasowe („7 dni"), ale
do tej pory nie było gdzie zapisać, kiedy kara mija. Przy jednym moderatorze
(D-012) nikt nie odklikuje tego ręcznie po tygodniu, więc **każda blokada
czasowa stawała się w praktyce trwała** — playbook obiecywał coś, czego system
nie umiał zrobić.

```sql
ALTER TABLE users
ADD CONSTRAINT users_status_expires_at_check
CHECK (status_expires_at IS NULL OR status = 'suspended');
```

**Termin dotyczy WYŁĄCZNIE statusu `suspended`:**

- `banned` jest bezterminowy z definicji — odwołanie idzie ścieżką odwoławczą
  (#10), nie zegarem;
- `pending_delete` ma własny licznik (`delete_requested_at`);
- `active` nie jest karą.

Zawieszenie **bez** terminu nadal jest możliwe (`NULL`) — to jest zawieszenie
do decyzji człowieka.

Konsekwencja praktyczna, o której trzeba wiedzieć: eskalacja `suspended` →
`banned` **musi** wyczyścić termin, inaczej baza odrzuci wiersz. Robi to
`User::ban()`. Gdyby termin został, zadanie w harmonogramie przywróciłoby
dostęp osobie właśnie zbanowanej — CHECK zamyka tę drogę na poziomie bazy,
a nie tylko w PHP (`AGENTS.md` §6).

Indeks częściowy `users_status_expires_at_idx` obejmuje wyłącznie wiersze
z niepustym terminem — pyta o nie tylko `kuking:zdejmij-wygasle-kary`,
a zdecydowana większość kont ma tu `NULL`.

**Kto zdejmuje karę:**

1. `kuking:zdejmij-wygasle-kary` — co godzinę, dla kont, które nie wracają same;
2. middleware `EnsureAccountIsActive` — natychmiast, gdy karany wejdzie na
   stronę po terminie (żeby nie czekał na crona w dniu końca kary).

**Rollback:** `down()` zdejmuje CHECK, indeks i kolumnę. Tracimy terminy
aktywnych zawieszeń — wraca więc problem sprzed migracji — ale żadne konto nie
zmienia statusu i nikt nie traci dostępu. Konta zawieszone zostają zawieszone
do ręcznej decyzji moderatora.

#### `data_erased_at` — egzekucja karencji po zgłoszeniu usunięcia konta

Migracja `2026_09_06_110000_add_data_erased_at_to_users` (audyt A8).

`delete_requested_at` mówi tylko KIEDY zgłoszono usunięcie. Nic wcześniej nie
egzekwowało obietnicy „po 30 dniach dane znikną na stałe" z ekranu „Twoje
dane" i z `docs/legal/COMPLIANCE.md` — konto zostawało `pending_delete` bez
końca. `data_erased_at` to znacznik, że karencja się WYKONAŁA: komenda
`kuking:usun-wygasle-konta` (codziennie w nocy) go ustawia, a
`App\Domain\Users\Actions\EraseAccountData` w tym samym przebiegu anonimizuje
`email`, `password`, `remember_token` na koncie oraz `username`,
`display_name`, `bio`, `avatar_media_id`, `region`, `speciality` na profilu.

**Świadomie NIE dodajemy nowej wartości do `users_status_check`.** Konto
pozostaje `pending_delete` na zawsze — z punktu widzenia logowania i tak nic
się nie zmienia (nie logowało się od zgłoszenia usunięcia). Jedyna nowa
informacja to właśnie ten znacznik.

```sql
ALTER TABLE users
ADD CONSTRAINT users_data_erased_at_check
CHECK (data_erased_at IS NULL OR status = 'pending_delete');
```

**Treści (posty, przepisy, komentarze) NIE są kasowane** przez ten mechanizm —
zostają przy już zanonimizowanym koncie, zgodnie z `docs/legal/COMPLIANCE.md`
§2 (dopuszczalne zachowanie treści o wartości społecznej w formie
zanonimizowanej: „autor: konto usunięte"). Kasowane są wyłącznie dane, po
których da się rozpoznać konkretnego człowieka.

**Cofnięcie usunięcia** (`App\Domain\Users\Actions\CancelAccountDeletion`,
formularz `AccountDeletionController` — publiczny, bo osoba `pending_delete`
jest wylogowywana natychmiast i nie może się zalogować) jest możliwe TYLKO
dopóki `data_erased_at` jest puste. Po jego ustawieniu e-mail i hasło już nie
istnieją — nie ma czym się zalogować, więc formularz cofnięcia świadomie to
odmawia z wyjaśnieniem, zamiast po cichu wskrzeszać pustą powłokę konta.

Indeks częściowy `users_pending_erase_idx` obejmuje wyłącznie konta
`pending_delete` bez wykonanej jeszcze anonimizacji — dokładnie to, o co pyta
`kuking:usun-wygasle-konta`.

**Rollback:** `down()` zdejmuje CHECK, indeks i kolumnę. Kontom, którym dane
już wymazano, ten rollback NIE przywraca e-maila ani hasła — tych danych po
prostu już nie ma, to nie jest strata spowodowana cofnięciem migracji. Same
konta nie zmieniają zachowania: nadal się nie logują.

#### Weryfikacja dwuetapowa (2FA / TOTP) — moderator i admin

Migracja `2026_09_06_120000_add_two_factor_to_users_table` (issue #12).
`docs/SECURITY_PRIVACY_LEGAL.md`: „MFA obowiązkowe dla adminów" — konto
moderatora widzi zgłoszenia, cudze ukryte treści i odwołania, więc samo
hasło już nie wystarcza jako jedyna ochrona.

**DLACZEGO TOTP, NIE KOD E-MAILEM.** Serwis nie ma dziś działającego SMTP
(zadanie po stronie właściciela) — drugi składnik oparty o e-mail zależałby
od kanału, który nie działa. TOTP liczy kod lokalnie w aplikacji telefonu
(Google Authenticator, Aegis, 1Password…), offline, z samego sekretu
i aktualnego czasu. Biblioteka: `pragmarx/google2fa` (RFC 6238, jedna
zależność — `paragonie/constant_time_encoding`) plus `bacon/bacon-qr-code`
do narysowania kodu QR jako SVG bez żadnego wywołania sieciowego (patrz
`App\Domain\Security\TwoFactorAuthenticator` — uzasadnienie wyboru obu
bibliotek jest w komentarzu klasy).

Kolumny na `users`:

- `two_factor_secret` — sekret TOTP, **zaszyfrowany** (cast `encrypted`
  w `App\Models\User`). Wyciek kopii bazy nie może oddawać drugiego
  składnika logowania.
- `two_factor_backup_codes` — kody zapasowe, **wyłącznie jako tablica
  skrótów** (cast `encrypted:array`, każdy element to `Hash::make()`, nigdy
  kod wprost). Kod jest USUWANY z tablicy po zużyciu — to jednocześnie
  realizuje „kod działa raz" i nie potrzebuje osobnej kolumny na zliczanie.
- `two_factor_confirmed_at` — 2FA jest zapisane na koncie od razu przy
  wejściu na ekran włączenia (żeby kod QR nie zmieniał się przy
  odświeżeniu), ale NIEAKTYWNE, dopóki człowiek nie poda pierwszego
  poprawnego kodu. Dopiero wtedy ta kolumna się wypełnia — i dopiero wtedy
  `User::hasTwoFactorConfirmed()` zaczyna wymagać kodu przy logowaniu
  i wejściu do `/admin`.
- `two_factor_last_used_at` — **NIE jest to `timestamptz`**, mimo nazwy: to
  surowy licznik czasu Uniksa zwracany przez `Google2FA::verifyKeyNewer()`,
  używany wyłącznie do odrzucenia PONOWNIE wpisanego kodu (ochrona przed
  atakiem powtórzenia — bez tego ten sam sześciocyfrowy kod, ważny przez
  całe okno tolerancji ±30 s, dałoby się użyć dwukrotnie). Aplikacja nigdy
  nie odpytuje tej kolumny funkcjami dat, tylko przekazuje ją z powrotem do
  tej samej biblioteki — stąd `bigint`, nie `timestamptz`.

```sql
ALTER TABLE users
ADD CONSTRAINT users_two_factor_confirmed_requires_secret_check
CHECK (two_factor_confirmed_at IS NULL OR two_factor_secret IS NOT NULL);
```

Bez tego CHECK dałoby się (błędem aplikacji albo ręczną operacją na bazie)
zapisać konto z `confirmed_at` bez sekretu — czyli konto, które wymaga kodu
2FA, ale nie ma z czego go policzyć. Baza tego po prostu nie przyjmie
(AGENTS.md §6: ograniczenie ma być w bazie, nie tylko w walidacji PHP).

**Limit prób** kodu (`config('kuking.limits.two_factor')`, domyślnie 5 prób
na minutę) liczy się PO KONCIE, nie po adresie IP — kod ma sześć cyfr, więc
bez limitu jest do odgadnięcia, a limit tylko po IP omijałby rozproszony
atak z wielu adresów.

**Blokada `/admin/**`:** middleware `EnsureModeratorHasTwoFactor` (alias
`moderator.2fa`), zawsze DRUGI w trasie po `moderator` — dzięki temu zwykły
użytkownik nadal dostaje 404 z `EnsureUserIsModerator`, zanim dotrze do
sprawdzenia 2FA. Moderator bez potwierdzonego 2FA widzi jasny ekran
z przyciskiem do włączenia (403), nie ścianę.

**Rollback:** `down()` zdejmuje CHECK i wszystkie cztery kolumny. To NIE jest
bezstratne — każde konto z włączonym 2FA traci zapisany sekret i kody
zapasowe, czyli wraca do logowania samym hasłem. To świadomy powrót do stanu
SPRZED tej zmiany (nikt nie zostaje zablokowany — wymóg drugiego składnika
znika razem z danymi, które go przechowywały), sensowny wyłącznie jako
awaryjne wyłączenie całej funkcji, nie jako operacja codzienna.

**Zgubiony telefon i kody zapasowe naraz — jak wrócić do konta.** Serwis nie
ma dziś SMTP, więc nie ma samoobsługowego „wyślij link odzyskiwania".
Jedyna droga to `php artisan kuking:2fa-wylacz {login}` — komenda konsolowa
wymagająca dostępu do serwera, uruchamiana PO zweryfikowaniu tożsamości tej
osoby poza serwisem. Celowo bez ścieżki samoobsługowej: samoobsługowy reset
2FA zwykłym linkiem unieważniałby sens 2FA (ktoś, kto ukradnie samo hasło,
resetowałby drugi składnik tą samą drogą).

### profiles
- user_id;
- username;
- display_name;
- bio;
- avatar.

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

### users
Adres e-mail jest zapisywany **małymi literami** (mutator `User::email`,
normalizacja w `User::normalizeEmail()`). Wszystkie miejsca, które szukają
konta po adresie — rejestracja, logowanie, przypomnienie hasła i sam reset —
przepuszczają wpisaną wartość przez tę jedną funkcję. Wcześniej walidacja
pytała bazę o wartość surową, więc „Jan@Example.com" przechodziło
`Rule::unique` i dopiero PostgreSQL odbijał duplikat: **HTTP 500 na
rejestracji** zamiast komunikatu „na ten adres jest już konto" (audyt A25).

### follows
`follower_id + followed_id` unique.

### blocks
Blokada ma pierwszeństwo przed follow.

### media
Tylko metadata, nie binary:
- owner;
- disk;
- object key;
- MIME;
- bytes;
- width/height;
- status;
- checksum;
- perceptual hash;
- metadata.

### posts + post_media
Najprostszy content społecznościowy.

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

### recipes
Aktualny stan.

### recipe_versions
Snapshot po istotnych zmianach.

### ingredients + units
Podstawa search i późniejszego planera.

### recipe_ingredients
Musi mieć `ingredient_text`, nawet jeśli normalizacja nie rozpozna składnika.

### recipe_steps
Pozycja + instruction + opcjonalny timer/media.

### cooked_events
Jedno realne gotowanie. Brak unique `(user_id, recipe_id)`.

### comments
Komentarz dotyczy dokładnie jednego:
- post;
- recipe;
- cooked event.

### collections + collection_items
Osobisty zeszyt.

**`collection_items` ma `PRIMARY KEY (collection_id, recipe_id)`** od migracji
zakładającej tabelę (`2026_09_05_000800_create_collections_tables`). Ten sam
przepis nie może stanąć w tym samym zeszycie dwa razy. Issue #43 zgłaszało tu
brak ograniczenia — zgłoszenie było nieaktualne, klucz jest na miejscu.
Pilnuje tego `tests/Feature/UnikalnoscZeszytowTest`.

To samo dotyczy `post_media` — `PRIMARY KEY (post_id, media_id)` plus
`UNIQUE (post_id, position)` stoją tam od migracji zakładającej tabelę.

**Nazwa zeszytu jest unikalna w obrębie jednej osoby, bez rozróżniania
wielkości liter.** Unikalny indeks funkcyjny
`collections_owner_name_lower_unique` na `(owner_id, lower(name))`
(migracja `2026_09_06_090000_add_collections_name_unique_index`, issue #43).

```sql
CREATE UNIQUE INDEX collections_owner_name_lower_unique
ON collections (owner_id, lower(name));
```

Bez tego jedna osoba mogła mieć dwa zeszyty „Obiady”. Lista zeszytów pokazuje
nazwę, liczbę przepisów i widoczność — dwa takie wiersze są nie do odróżnienia
i trzeba wejść do obu, żeby sprawdzić, w którym leży szukany przepis. Zwykle
nie brało się to ze złego nazewnictwa, tylko z podwójnego wysłania formularza.

Indeks jest **funkcyjny**, a nie na kolumnie, z tego samego powodu co przy
`profiles_username_lower_unique`: nazwa zostaje zapisana tak, jak ktoś ją
wpisał („Na Święta” zostaje „Na Święta”), a bez rozróżniania wielkości liter
sprawdzamy tylko, czy jest już zajęta. Dla człowieka „Obiady” i „obiady” to
ta sama nazwa.

Ograniczenie jest **per właściciel** — dwie różne osoby mogą mieć zeszyt
„Obiady” i nic w tym dziwnego.

Migracja **nie scala i nie kasuje** zeszytów przy kolizji: sprawdza, czy takie
pary istnieją, i przerywa z ich listą. Dwa zeszyty o tej samej nazwie to dwa
różne pojemniki, z różną zawartością i możliwie różną widocznością — scalenie
albo skasowanie jednego jest nieodwracalne i mogłoby upublicznić prywatne
zapisy. Decyzję podejmuje człowiek. Rollback to `DROP INDEX`, bez utraty
danych.

Konsekwencja dla domyślnego zeszytu: `User::defaultCollection()` szuka
pierwszej wolnej nazwy („Zapisane”, „Zapisane 2”, …), bo ktoś mógł sam założyć
zeszyt „Zapisane”, zanim cokolwiek zapisał. Bez tego pierwsze „Zapisuję”
kończyłoby się błędem 500.

### notifications
In-app.

### reports
Zgłoszenia.

### moderation_actions
Decyzje moderatorów.

Od migracji `2026_09_06_100000_add_context_to_moderation_actions` (issues #65 i #10)
wiersz zapisuje dwie rzeczy więcej:

| Kolumna | Po co |
|---|---|
| `previous_status` | Status treści **sprzed** decyzji (`draft`, `published`…). Bez tego ukrycia nie da się cofnąć do właściwego stanu. |
| `subject_user_id` | Osoba, której decyzja dotyczy — autor treści albo zgłoszone konto. |

#### `previous_status` — dlaczego tutaj, a nie w tabelach z treścią

`posts.status`, `recipes.status` i `comments.status` trzymają wyłącznie stan
bieżący. Po ukryciu widać tylko `hidden`, więc przywracanie „na sztywno do
`published`" **upubliczniłoby cudzy szkic** — treść, której autor nigdy nikomu
nie pokazał. To jest wyciek, nie drobiazg.

Rozważana i odrzucona alternatywa: kolumna `status_before_moderation` na każdej
z trzech tabel z treścią. Powody odrzucenia:

1. trzy kolumny zamiast jednej, każda sensowna wyłącznie wtedy, gdy wiersz jest
   akurat ukryty — czyli prawie zawsze pusta i prawie zawsze myląca;
2. stan sprzed decyzji jest faktem **o decyzji**, nie o treści; tu leży już
   `reason_code`, `note` i `user_message` z tego samego powodu;
3. przy dwóch ukryciach pod rząd kolumna na treści zna tylko ostatnie, a log
   moderacji zna każde — przy odwołaniu liczy się historia, nie migawka.

Odczyt idzie po istniejącym indeksie
`moderation_actions_target_idx (target_type, target_id, created_at DESC)`.
Gdy wartości brak (treść ukryta przed tą migracją albo ręcznie w psql),
`App\Domain\Moderation\ModeratedContent` przywraca treść do **szkicu** —
pomyłkę w tę stronę autor cofa jednym kliknięciem, pomyłki w drugą nie cofnie
nikt.

Nowy indeks: `moderation_actions_subject_idx (subject_user_id, created_at DESC)`.

**Rollback:** `DROP` obu kolumn (`down()` migracji). Bezpieczny — czyta je
wyłącznie ścieżka przywracania i odwołań. Cena: dla treści już ukrytych ginie
zapisany stan sprzed ukrycia i po ponownym wdrożeniu wrócą one jako szkice.

### appeals
Odwołania od decyzji moderacyjnych (migracja
`2026_09_06_100100_create_appeals_table`, issue #10, DSA art. 17 i 20).

| Kolumna | Uwagi |
|---|---|
| `moderation_action_id` | **`UNIQUE`** — jedno odwołanie na jedną decyzję. |
| `user_id` | Odwołujący się. `cascadeOnDelete` — po usunięciu konta sprawa jest bezprzedmiotowa (RODO art. 17); ślad samej decyzji zostaje w `moderation_actions`. |
| `body` | Własne słowa człowieka, do 2000 znaków. |
| `status` | `open` · `upheld` (podtrzymana) · `overturned` (cofnięta). |
| `decided_by`, `decision_note`, `decided_at` | Odpowiedź — kto, co napisał, kiedy. |

Ograniczenia w bazie:

```sql
CHECK (status IN ('open','upheld','overturned'));

-- Rozpatrzone = jest data ORAZ jest uzasadnienie. Otwarte = nie ma ani jednego.
CHECK ((status = 'open'  AND decided_at IS NULL     AND decision_note IS NULL)
    OR (status <> 'open' AND decided_at IS NOT NULL AND decision_note IS NOT NULL));
```

Drugi CHECK jest wprost przepisaniem DSA art. 20: odpowiedź **musi** mieć
uzasadnienie, więc „podtrzymuję" bez zdania wyjaśniającego nie da się zapisać.

`UNIQUE (moderation_action_id)` jest limitem odwołań i stoi w bazie, bo to
jedyne miejsce, którego nie obejdzie drugi endpoint ani podwójne kliknięcie.
Termin 14 dni na złożenie liczy kod (`ModerationAction::appealDeadline()`) —
CHECK nie sięga do drugiej tabeli.

Indeksy: `appeals_status_created_idx (status, created_at)`,
`appeals_user_idx (user_id, created_at DESC)`.

**Rollback:** `DROP TABLE appeals` — to jest **utrata danych**. Przed cofnięciem
na produkcji zrób `COPY appeals TO ...`, inaczej tracisz dowód, że
odpowiedzieliśmy na odwołania (dokładnie to, o co zapyta regulator).

### audit_log
Wysokiego znaczenia zmiany.

## V1 / V2

Później:
- groups;
- group_members;
- recipe_forks;
- family_books;
- questions;
- answers;
- meal_plans;
- shopping_lists;
- pantry_items;
- tags;
- subscriptions;
- payments.

## Wyszukiwarka: funkcja `kuking_normalize()`

Migracja `2026_09_05_001300_fix_search_indexes` wprowadza funkcję:

```sql
CREATE FUNCTION public.kuking_normalize(text) RETURNS text
AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, lower($1)) $$
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE;
```

**Po co:** indeksy trigramowe muszą stać na DOKŁADNIE tym samym wyrażeniu,
którego używa zapytanie. Pierwotne indeksy stały na surowych kolumnach
(`gin (title gin_trgm_ops)`), a `SearchQuery` pytał o `unaccent(lower(title))` —
w efekcie żaden indeks nie był używany i każde wyszukiwanie skanowało całą
tabelę. Potwierdzone `EXPLAIN`-em przy `enable_seqscan = off`.

**Dlaczego własna funkcja, a nie `unaccent()` wprost:** `unaccent()` nie jest
`IMMUTABLE` (zależy od słownika), a PostgreSQL nie pozwala indeksować wyrażeń
nieimmutable. Opakowanie z jawnie wskazanym słownikiem to udokumentowane
obejście.

⚠️ **Dlaczego wszystko jest kwalifikowane `public.`:** od PostgreSQL 17
operacje utrzymaniowe — w tym `CREATE INDEX` i `REINDEX` — wykonują się
z ograniczonym `search_path` (`pg_catalog, pg_temp`). Ciało funkcji SQL jest
re-parsowane przy inliningu, więc niekwalifikowane `unaccent(...)` przestaje
być widoczne i budowanie indeksu pada:

```text
ERROR:  function unaccent(unknown, text) does not exist
CONTEXT:  SQL function "kuking_normalize" during inlining
```

Na PostgreSQL 16 to przechodziło, więc błąd był niewidoczny lokalnie
i wyszedł dopiero przy pierwszym przebiegu CI na `postgres:18`. Dlatego
rozszerzenia zakładamy jawnie `WITH SCHEMA public`, a funkcja woła
`public.unaccent` ze słownikiem `'public.unaccent'::regdictionary`.
**Nie polegaj tu na `search_path` — przy budowaniu indeksu go nie ma.**

Pilnują tego dwa testy: `RegressionTest::test_normalizacja_dziala_przy_ograniczonym_search_path`
oraz `::test_indeks_na_kuking_normalize_da_sie_zbudowac`. Oba wymuszają
ograniczony `search_path` ręcznie, więc łapią regresję także na PostgreSQL 16.

⚠️ **Konsekwencja:** podmiana słownika `unaccent` wymagałaby `REINDEX`.
Nie robimy tego.

**Zasada dla przyszłych zmian:** jeśli zmieniasz wyrażenie w
`App\Domain\Search\SearchQuery`, zmień też indeksy. Pilnuje tego test
`RegressionTest::test_wyszukiwarka_korzysta_z_indeksu_trigramowego`, który
wyłącza skan sekwencyjny i sprawdza plan zapytania.

Indeksy na tej funkcji: `profiles` (username, display_name, speciality),
`recipes` (title, summary), `ingredients` (normalized_name),
`recipe_ingredients` (ingredient_text).

## `daily_picks`

Wybór redakcyjny na tablicę „kuKINGi na dziś". Świadomie bez kolumny
z punktami, liczbą polubień ani wynikiem — to nie jest tabela rankingowa
(patrz `../AGENTS.md` §8).

## Normalizacja adresu e-mail

`User::email` ma mutator wymuszający małe litery i przycięcie spacji.
PostgreSQL porównuje teksty z uwzględnieniem wielkości liter, a klawiatury
telefonów kapitalizują pierwszą literę — bez tego konto założone jako
`Jan@example.com` było nie do zalogowania przez `jan@example.com`.

Pełny referencyjny DDL jest w `database/reference/schema_mvp.sql`.
