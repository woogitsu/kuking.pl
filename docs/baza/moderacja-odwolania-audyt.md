# Moderacja, odwołania, dziennik audytu

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### moderation_actions
Decyzje moderatorów.

**`action varchar(40) NOT NULL`** — co moderator zrobił: `no_action`, `hide`,
`unhide`, `remove`, `warn`, `suspend`, `ban`, albo wewnętrzny wynik
`target_unavailable`. Ten ostatni nie jest wyborem w formularzu: powstaje
wyłącznie wtedy, gdy moderator świadomie wybiera „Bez działania”, a wskazany
cel zniknął lub nie dał się ustalić przed pierwszą decyzją. Wtedy sprawa jest
zamknięta jako `resolved`, ale nie zapisujemy sankcji ani nie twierdzimy, że
oceniliśmy treść. Próba `hide`, `remove`, `warn`, `suspend` albo `ban` zostawia
sprawę otwartą i wraca z błędem, zamiast tworzyć pozorną decyzję.

**Bez CHECK-a w bazie**, bo
dopuszczalna wartość zależy od `target_type` (konta nie da się „ukryć",
zdjęcia nie da się „usunąć" osobno od wpisu) — macierz `target_type` →
dozwolone działania trzyma `App\Models\ModerationAction::DOZWOLONE`, a CHECK
na samej kolumnie przepuszczałby i tak każdą złą parę.

Trzy kolumny tekstowe wokół tej decyzji to **trzy różne adresaty**, nie
warianty tego samego pola:

| Kolumna | Kto to czyta |
|---|---|
| `reason_code varchar(80) NOT NULL` | Kod podstawy decyzji, nie zdanie. Formularz oferuje **zamkniętą listę** `App\Domain\Moderation\PodstawaDecyzji`, ale reguła walidacji jest świadomie miękka (`string`, nie `in:`) — w bazie leżą decyzje sprzed tej listy, a `RestoreContent` zapisuje tu `appeal_overturned`. Kod spoza listy nie dostaje numeru punktu zasad i tyle. |
| `note varchar(2000) NULL` | Tylko moderatorzy. Notatka wewnętrzna, nie wychodzi poza panel. |
| `user_message varchar(2000) NULL` | **Człowiek, którego decyzja dotyczy** — zdanie doklejane do powiadomienia. Wszystko, co tu stoi, zostanie mu pokazane. |

`moderator_id uuid` → `users`.

**`target_type = 'recipe_version'` (#2270, decyzja właściciela z 30.09.2026,
bez migracji).** Ukrycie jednej wersji przepisu przez moderację jest decyzją
z urzędu: `action = hide`, `target_id` = `recipe_versions.id`,
`subject_user_id` = autor przepisu, `report_id` i `appeal_id` NULL. Cofnięcie
(ręczne z historii zmian albo po uznanym odwołaniu, `reason_code =
appeal_overturned`) to `unhide` z tym samym celem. Kolumna `target_type` nie
ma CHECK-a (w przeciwieństwie do `reports.target_type`), więc nowy typ nie
wymaga zmiany schematu. Od #2390 wersję można też zgłosić (`reports.target_type
= 'recipe_version'`, niżej); wtedy `ukrycie` ze zgłoszenia ma `report_id`
tego zgłoszenia, a reszta pól jest jak przy decyzji z urzędu.
Wiersz przeżywa wersję (retencja wersji, usunięcie przepisu) tak jak każda
decyzja przeżywa treść — `target_id` nie ma klucza obcego. Zapis:
`App\Domain\Recipes\Historia\DecyzjaOWersjiPrzepisu`.

**`target_type = 'recipe_hint'` (#2352, decyzja właściciela z 1.10.2026).**
Ukrycie samej wskazówki od gotujących: `action = hide`, `target_id` =
`recipe_hints.id`, `subject_user_id` = **kucharz** (autor uwagi, nie autor
przepisu), `report_id` = zgłoszenie (albo `appeal_id` przy nowej decyzji po
odwołaniu zgłaszającego). Cofnięcie po uznanym odwołaniu to `unhide`
(`reason_code = appeal_overturned`). Kolumna nie ma CHECK-a, więc nowy typ nie
zmienia tu schematu.

Od migracji `2026_09_06_100000_add_context_to_moderation_actions` (issues #65 i #10)
wiersz zapisuje dwie rzeczy więcej:

| Kolumna | Po co |
|---|---|
| `previous_status` | Status treści **sprzed** decyzji (`draft`, `published`…). Bez tego ukrycia nie da się cofnąć do właściwego stanu. Dla `recipe_version`: `hidden_by_author`, gdy decyzja PRZEJĘŁA ukrycie zrobione przez autora (uznane odwołanie zwraca wtedy ukrycie autorowi, nie robi wersji publicznej; decyzja 30.09.2026) — inaczej `NULL`. |
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

#### `report_id IS NULL` przy decyzji odwoływalnej — decyzja z urzędu (G31, D-251)

Pusty `report_id` przy `action = 'remove'` znaczy „nikt tego nie zgłosił”:
moderator zdjął treść z własnego przeglądu („Zdejmij z urzędu”,
`App\Domain\Moderation\Actions\ZdejmijZUrzedu`). Nie ma przy tym sztucznego
zgłoszenia i nie ma nowej kolumny źródła — pusty `report_id` przy `unhide`
znaczy przywrócenie (`RestoreContent`), przy decyzji odwoływalnej znaczy
decyzję z urzędu, i tak czyta go `UzasadnienieDecyzji::skadSprawa()`.

Wyjątek od tej reguły ma własną kolumnę — patrz niżej `appeal_id`.

#### `appeal_id` — decyzja po uznaniu odwołania (#989)

Migracja `2026_09_24_120000_add_appeal_id_to_moderation_actions`.

| Kolumna | Po co |
|---|---|
| `appeal_id uuid NULL` → `appeals`, `ON DELETE SET NULL` | Odwołanie, po którego uznaniu zapadła ta decyzja. Dziś wyłącznie odwołanie **zgłaszającego** od `no_action`/`target_unavailable`: uznanie takiego odwołania wymaga nowej decyzji (DSA art. 20 ust. 4), a wykonuje ją `App\Domain\Moderation\Actions\DecyzjaPoOdwolaniu` w transakcji `ResolveAppeal`. Pierwotna decyzja i zgłoszenie są osiągalne przez `appeals.moderation_action_id` i `appeals.report_id`. |

- `moderation_actions_one_per_appeal` — częściowy `UNIQUE (appeal_id) WHERE
  appeal_id IS NOT NULL`: jedno odwołanie, najwyżej jedna decyzja po nim.
- `moderation_actions_appeal_or_report_check` — `CHECK (appeal_id IS NULL OR
  report_id IS NULL)`: decyzja po odwołaniu nie jest drugą decyzją pierwszej
  instancji, więc `moderation_actions_one_per_report` zostaje nietknięty.
- Poza `$fillable` — ustawia ją wyłącznie `DecyzjaPoOdwolaniu` (`forceFill`).
- `ON DELETE SET NULL`, nie `RESTRICT`: retencja kasuje `appeals` przed
  `moderation_actions`; `RESTRICT` zatrzymywałby ją na każdym takim
  odwołaniu na zawsze.
- `UzasadnienieDecyzji::skadSprawa()` przy niepustym `appeal_id` mówi autorowi,
  że sprawa zaczęła się od zgłoszenia i wróciła po odwołaniu — nie „nikt tego
  nie zgłosił”.

**Rollback:** `down()` **odmawia**, gdy istnieje choć jeden wiersz z
`appeal_id` (D-088): bez kolumny taka decyzja wyglądałaby jak decyzja z urzędu,
a uzasadnienie dla autora mówiłoby nieprawdę. Komunikat podaje liczbę wierszy
i zapytanie do zachowania powiązań. Bez takich wierszy (świeża baza, żadne
odwołanie od „Bez działania” nie zostało uznane) rollback zdejmuje CHECK,
indeks, klucz obcy i kolumnę bez pytania. Test odmowy i kontrola dodatnia:
`tests/Feature/CofniecieMigracjiDecyzjiPoOdwolaniuTest.php`.

**Retencja:** ten sam okres i **ta sama komenda** co `reports` (domyślnie
36 miesięcy, decyzja właściciela), liczony od `created_at` — kolumna jest
niemutowalna (`ModerationAction::UPDATED_AT === null`). Wiersz jest kandydatem
dopiero wtedy, gdy DODATKOWO nie zostaje po nim **żaden** wiersz w `appeals`;
inaczej kaskada `appeals.moderation_action_id` (`cascadeOnDelete`) zabrałaby
odwołanie przed jego własnym czasem (ADR §4). Kolejność w komendzie:
`appeals` → `moderation_actions` → `reports`. Zapytanie idzie wprost do tabeli
`appeals` przez `moderation_action_id`, a nie przez nazwaną relację Eloquent —
blokada działa przy każdym żywym odwołaniu, niezależnie od roli odwołującego.

### zabezpieczenia_dowodow
**Rejestr dowodów zabezpieczonych przed usunięciem** — ścieżka CSAM w panelu
moderacji („CSAM — natychmiast ukryj i zabezpiecz”), D-333 (wiersz z 1.10.2026).
Migracja `2026_10_01_150000_create_zabezpieczenia_dowodow_table`. Procedura:
`docs/legal/MODERATION_PLAYBOOK.md` §7.1, `docs/flota/CSAM_JEDNA_KARTKA.md`.

To **jedno miejsce**, które mówi „tego obiektu nie wolno skasować żadną drogą”.
Pytają o nie: `PrzedawnioneUsunieteTresci` (retencja usuniętych treści),
`PrzedawnioneWersjePrzepisow`, `PrzedawnioneSprawyModeracyjne` (sprawa o
zabezpieczony obiekt nie jest kasowana po 36 miesiącach), `KasujZdjecie`,
`EraseAccountData` (konto z zabezpieczonym dowodem nie jest wymazywane) i
`RestoreContent` (zabezpieczona treść nie wraca, także po wygranym odwołaniu).
Pytania zadaje `App\Support\ZabezpieczoneDowody`.

- `id uuid` PK;
- `target_type varchar(30) NOT NULL` — `post`, `recipe`, `comment` albo
  `media` (CHECK `zabezpieczenia_dowodow_target_type_check`); `target_id uuid
  NOT NULL`. Bez klucza obcego — obiekt bywa skasowany z innych powodów, a
  rejestr ma po tym zostać. **UNIQUE** (`target_type`, `target_id`): jeden
  obiekt zabezpiecza się raz;
- `subject_user_id uuid NULL` → `users` (`ON DELETE SET NULL`) — autor treści
  albo właściciel zdjęcia. **NULL = nie znamy autora.** Konto z takim wierszem
  nie jest wymazywane (anonimizacja zabrałaby dane, o które zapyta organ);
- `report_id uuid NULL` → `reports` (`ON DELETE SET NULL`) — zgłoszenie, z
  którego wyszła akcja. **NULL = akcja ze strony treści, bez zgłoszenia.**
- `moderation_action_id uuid NULL` → `moderation_actions` (`ON DELETE SET
  NULL`) — decyzja „Usuń treść” zapisana przy zabezpieczeniu;
- `secured_by uuid NULL` → `users` (`ON DELETE SET NULL`) — moderator;
- `previous_media_status varchar(20) NULL` — **tylko dla `target_type = 'media'`
  i wtedy obowiązkowe**: status zdjęcia SPRZED zabezpieczenia (`pending`,
  `processing`, `ready`, `rejected`). Dla wpisu, przepisu i komentarza **zawsze
  NULL** (CHECK `zabezpieczenia_dowodow_previous_media_status_check`).
  Potrzebne człowiekowi, który kiedyś — po decyzji prawnika — będzie
  przywracał zdjęcie;
- `note varchar(2000) NULL` — notatka wewnętrzna moderatora, **bez opisu
  materiału**;
- `secured_at timestamptz NOT NULL DEFAULT now()`.

**Zlecenie przeniesienia wariantów (#2437):** dla nowo zabezpieczonego zdjęcia
wiersz w `jobs` (kolejka `media`) powstaje w tej samej transakcji co
`zabezpieczenia_dowodow` i `media.status = secured` — wspólne połączenie
PostgreSQL, `after_commit=false`. Nie ma nowej tabeli ani migracji. Po
ukończeniu worker wpisuje do JSONB `media.metadata` znacznik
`warianty_dowodu_przeniesione_at`; istniejący `/health` wykrywa jego brak po
15 minutach. Wartość `null` albo pusta nie wycisza alarmu. Jeśli wariant nadal
leży na wspólnym publicznym `r2_legacy`, worker nie zapisuje znacznika i nie
usuwa oryginału. Znacznik nie znaczy, że osobny purge CDN już się zakończył.
Diagnostyka i bezpieczne pojedyncze ponowienie: `docs/infra/CSAM_KOLEJKA_2437.md`.

Zabezpieczenie przepisu obejmuje **całą jego historię wersji**
(`recipe_versions`) — wersje przepisu nie mają własnego wiersza w rejestrze,
chroni je wiersz przepisu.

**Nie ma `released_at` i nie ma drogi z panelu, która zdejmuje wiersz.** To
świadome: kiedy i na czyje polecenie wolno skasować dowód, ma rozstrzygnąć
prawnik (playbook §7.1a, pytanie 2). Do tego czasu zabezpieczenie jest
bezterminowe — błąd w bezpieczną stronę.

**Rollback (D-088): ODMAWIA**, gdy tabela nie jest pusta — zrzucenie rejestru
zdjęłoby ochronę ze wszystkich dowodów naraz, a najbliższa noc retencji by je
skasowała. Na pustej tabeli `down()` ją usuwa. Test odmowy i kontrola
dodatnia: `tests/Feature/CofniecieMigracjiZabezpieczonychDowodowOdmawiaTest.php`.

### appeals
Odwołania od decyzji moderacyjnych — **AUTORA treści I ZGŁASZAJĄCEGO**
(migracje `2026_09_06_100100_create_appeals_table` i
`2026_09_07_800000_appeals_open_to_reporters`, issues #10 i #23, DSA art. 17
i 20).

| Kolumna | Uwagi |
|---|---|
| `moderation_action_id` | FK → `moderation_actions`, `cascadeOnDelete`. |
| `appellant` | `author` \| `reporter` — **kto** się odwołuje. |
| `user_id` | Odwołujący się **autor**. NULL przy `appellant='reporter'`. `cascadeOnDelete` — po usunięciu konta sprawa jest bezprzedmiotowa (RODO art. 17); ślad samej decyzji zostaje w `moderation_actions`. |
| `report_id` | Zgłoszenie **zgłaszającego**. NULL przy `appellant='author'`. FK → `reports`, `nullOnDelete`. |
| `body` | Własne słowa człowieka, do 2000 znaków. |
| `status` | `open` · `upheld` (podtrzymana) · `overturned` (cofnięta). |
| `decided_by`, `decision_note`, `decided_at` | Odpowiedź — kto, co napisał, kiedy. |

Ograniczenia w bazie:

```sql
CHECK (status IN ('open','upheld','overturned'));

-- Rozpatrzone = jest data ORAZ jest uzasadnienie. Otwarte = nie ma ani jednego.
CHECK ((status = 'open'  AND decided_at IS NULL     AND decision_note IS NULL)
    OR (status <> 'open' AND decided_at IS NOT NULL AND decision_note IS NOT NULL));

-- appeals_appellant_check
CHECK (appellant IN ('author','reporter'));

-- appeals_appellant_identity_check
CHECK ((appellant = 'author'   AND user_id   IS NOT NULL AND report_id IS NULL)
    OR (appellant = 'reporter' AND report_id IS NOT NULL AND user_id   IS NULL));
```

Drugi CHECK jest wprost przepisaniem DSA art. 20: odpowiedź **musi** mieć
uzasadnienie, więc „podtrzymuję" bez zdania wyjaśniającego nie da się zapisać.

Czwarty CHECK jest wypisany **jawnie per rola**, a nie przez `num_nonnulls()`
jak w `comments` i `collection_items`. Tamten wzorzec sprawdza tylko, ile
kolumn jest wypełnionych — przepuściłby więc `appellant='reporter'`
z wypełnionym `user_id` zamiast `report_id`, czyli rolę niezgodną z danymi.

`UNIQUE (moderation_action_id, appellant)` — zastąpiło dawne
`UNIQUE (moderation_action_id)`. **Jedno odwołanie na rolę na decyzję:** od
jednej decyzji mogą dziś istnieć **dwa** niezależne odwołania, autora
i zgłaszającego. Limit stoi w bazie, bo to jedyne miejsce, którego nie
obejdzie drugi endpoint ani podwójne kliknięcie. Termin — **SZEŚĆ MIESIĘCY,
nie 14 dni** (DSA art. 20 ust. 1) — liczy kod
(`ModerationAction::appealDeadline()`), identycznie dla obu ról; CHECK nie
sięga do drugiej tabeli.

Indeksy: `appeals_status_created_idx (status, created_at)`,
`appeals_user_idx (user_id, created_at DESC)`,
`appeals_report_idx (report_id, created_at DESC) WHERE report_id IS NOT NULL`.

**Dostęp zgłaszającego** to podpisany, wygasający link
(`URL::temporarySignedRoute`, ten sam mechanizm co `settings.data.download`),
a nie sesja ani token w kolumnie — zgłaszający może nie mieć konta (art. 16
ust. 2 lit. c). UUID zgłoszenia w adresie **sam w sobie autoryzacją nie jest**;
podpisem HMAC z `APP_KEY` jest. Link wygasa dokładnie z `appealDeadline()`.
**Zgłoszenie anonimowe (bez adresu e-mail) dostępu NIE dostaje** — nie ma
kanału doręczenia linku, i to jest świadome: naprawa wymagałaby naruszenia
samej anonimowości, o którą art. 16 ust. 2 lit. c prosi.

**Retencja:** ten sam okres co `reports` i `moderation_actions` (domyślnie
36 miesięcy), liczony od `decided_at`, tylko dla
`status IN ('upheld','overturned')` — `open` nie jest kandydatem nigdy. Ta sama
liczba miesięcy co przy `moderation_actions` jest **celowa, nie przypadkowa**:
dłuższy okres tutaj wymuszałby przez kaskadę dłuższy realny okres
`moderation_actions`, niezależnie od tego, co wpisano wprost (ADR §5.5).

**Rollback:** migracja `2026_09_07_800000` **ODMAWIA** cofnięcia, gdy w bazie
jest choć jeden wiersz `appellant='reporter'` — stary schemat wymaga
`user_id NOT NULL`, więc cofnięcie musiałoby albo wymyślić takiemu wierszowi
autora, albo go skasować. Poza tym `DROP TABLE appeals` to **utrata danych**:
przed cofnięciem na produkcji zrób `COPY appeals TO ...`, inaczej tracisz dowód,
że odpowiedzieliśmy na odwołania (dokładnie to, o co zapyta regulator).

### audit_log
Wysokiego znaczenia zmiany.

- `actor_id uuid NULL` → `users` (`ON DELETE SET NULL`) — kto to zrobił.
  `NULL` znaczy „nie zalogowany człowiek": komenda z powłoki albo konto już
  zanonimizowane;
- **`action varchar(100) NOT NULL`** — nazwa zdarzenia w kropkowanej
  konwencji `obszar.co_się_stało` (`account.data_erased`,
  `user.role_changed`, `admin.user_viewed`,
  `moderation.hidden_post_viewed`, `moderation.media_viewed`,
  `moderation.hidden_recipe_viewed`). **Bez CHECK-a w bazie** i to jest
  wybór: dziennik ma przyjąć każde zdarzenie, które ktoś uzna za warte
  zapisania, a nie odmówić zapisu, bo lista wartości nie nadążyła za kodem.
  Ta sama kolumna rozstrzyga o retencji — patrz `AuditLogEntry::NIGDY_NIE_KASUJ`
  niżej;
- **`subject_type varchar(80) NULL`** + `subject_id uuid NULL` — czego
  zdarzenie dotyczyło, para „typ + identyfikator" bez klucza obcego (wiersz
  ma przeżyć skasowanie tego, co opisuje). `NULL` znaczy „zdarzenie nie
  dotyczy pojedynczej encji";
- **`ip_hash varchar(128) NULL`** — adres IP **wyłącznie jako skrót**, nigdy
  jawnie. Do wykrywania nadużyć skrót wystarcza, a danych osobowych nie
  trzymamy dłużej, niż to konieczne. `NULL` znaczy „zdarzenie nie przyszło
  z żądania HTTP" (komenda, harmonogram) **albo** „wpis dowodowy
  (`NIGDY_NIE_KASUJ`) starszy niż `audit_log.retention_months`" — ten sam
  `kuking:sprzataj-audyt`, który kasuje zwykłe wpisy, zeruje w dowodowych
  sam skrót (audyt B5 pkt 10; wpis zostaje). Bez zmiany schematu;
- `metadata jsonb NOT NULL DEFAULT '{}'` — reszta kontekstu;
- `created_at`.

**Retencja:** `config('kuking.audit_log.retention_months')` — **12 miesięcy**
od `created_at`. (Stało tu „24 miesiące"; pierwsza wersja tego automatu
rzeczywiście brała 24, ale ocena zewnętrzna nazwała je nieuzasadnionymi
i config ma 12 od 7 września. Dokument był ostatni, który o tym nie
wiedział — patrz D-038.) **Z WYJĄTKIEM** kategorii z `App\Models\AuditLogEntry::NIGDY_NIE_KASUJ`
(`account.data_erased`, `account.delete_requested`, `account.delete_cancelled`),
które nie są kandydatem **nigdy**, niezależnie od wieku. Powód: wiersz `users`
jest anonimizowany, a nie kasowany, więc te wpisy są jedynym dowodem, że
żądanie z art. 17 RODO zostało wykonane — a `User::cancelDeletion()` zeruje
`delete_requested_at`, więc bez nich nie ma śladu, że ktoś zgłosił i cofnął
usunięcie konta. Lista jest **zamkniętą stałą w kodzie**, nie w configu:
w configu dałaby się wyczyścić jedną zmianą wdrożeniową bez recenzji kodu.
Egzekwuje `kuking:sprzataj-audyt`, harmonogram codziennie o 04:10.

**Wpis atomowy albo pomocniczy (D-249, #1343, #1373, #1363).** Wpis będący
częścią decyzji (`moderation.decided`, `moderation.automat_dismissed`,
`user.role_changed`, `post.published`, `post.republished` (autor publikuje
ponownie wpis przywrócony jako szkic, #2461 — zwykły okres retencji dziennika),
wybór redakcyjny `daily_board.updated`,
`daily_board.cleared`, `hero_kolaz.updated`, `hero_kolaz.cleared`) idzie przez
`record()` **wewnątrz**
transakcji zmiany: awaria dziennika cofa decyzję, a ponowienie daje jeden
komplet. Wpis pomocniczy, powstający PO zatwierdzeniu czynności samego
człowieka (`account.registered`, `content.reported`) albo za zapisem sprawy
automatu (`content.flagged_by_automat` — nowa sprawa i dołożone sygnały),
idzie przez
`AuditLogEntry::recordBezWywracania()`: awaria zapisu trafia do `report()`
z nazwą brakującego wpisu, a człowiek dostaje odpowiedź udanej zmiany — nie
błąd przy koncie czy sprawie, które już istnieją.

**Klasyfikacja pięciu ścieżek konta (D-249, #1347, #1892–#1897).**
`account.delete_requested` i `account.delete_cancelled` — **klasa 1**: razem
są jedynym miejscem w bazie mówiącym, że ktoś zgłosił i (ewentualnie) cofnął
usunięcie konta (`NIGDY_NIE_KASUJ` niżej). `account.suspension_expired`
i `account.data_erased` — też **klasa 1**, mimo że decyzję podejmuje
harmonogram, nie moderator: to jedyny zapis TEGO zdarzenia, więc awaria ma
cofnąć zmianę konta i zostawić je do podjęcia przy następnym przebiegu tej
samej komendy. `user.unblocked` i trzy wpisy zmiany adresu e-mail
(`account.email_change_requested`, `account.email_changed`,
`account.email_change_cancelled`) — **klasa 2**: ich autorytatywny ślad żyje
w `blocks`/`pending_email_changes`/`users.email`. Pełne uzasadnienie
i dowody: D-249 w `docs/DECISIONS.md`.

**`user.role_changed`** — zmiana roli konta (`user` / `moderator` / `admin`),
zapisywana przez `kuking:nadaj-role`. `actor_id` jest **pusty**, bo komendę
uruchamia powłoka, a nie zalogowany człowiek; źródło stoi w metadanych
(`source`), razem z rolą poprzednią i nową. To jest jedyny ślad po tym, kto
w serwisie może zamknąć czyjeś odwołanie (D-039).

**`admin.user_viewed`** — wgląd moderatora w kartę pojedynczego konta
(`/admin/uzytkownicy/{user}`, `App\Http\Controllers\Admin\UzytkownicyController`).
Realizacja decyzji 3.2 z `docs/INSPIRATION_DECISIONS.md` („wpisy przy
OGLĄDANIU danych, nie tylko przy zmianie"): `actor_id` to moderator,
`subject_id` — osoba, której dane obejrzano, bez żadnych metadanych.
**Sama LISTA kont wpisu nie zostawia** i jest to decyzja, nie przeoczenie:
pokazuje adresy w masce (`j***@wp.pl`), otwiera się kilkanaście razy dziennie
po drodze do czegoś innego, a przy tysiącach kont wpisy z niej zalałyby
dziennik tak, że prawdziwe wejścia utonęłyby w szumie. Retencja zwykła —
ten wpis NIE należy do `AuditLogEntry::NIGDY_NIE_KASUJ`, bo nie jest jedynym
dowodem wykonania żądania z RODO art. 17.

**`moderation.hidden_post_viewed`** — wgląd obsługi we wpis niewidoczny bez
roli (#1018; od 30.09.2026 decyzją właściciela także wpis konta zbanowanego
albo oznaczonego do usunięcia): strona wpisu (`PostController::show()` przez
`StronaWpisu`) i `GET /api/v1/wpisy/{post}`, gdy otwiera go moderator inny
niż autor, a to samo konto z rolą `user` by go nie zobaczyło
(`DziennikWgladu::wpis()`, ten sam wzorzec co `moderation.hidden_recipe_viewed`).
Do 30.09.2026 wpis powstawał tylko przy `status = hidden`, więc wgląd w wpis
konta zbanowanego (`PostPolicy::view()`, `$isOwnerOrModerator`) nie zostawiał
śladu. Nazwa zdarzenia została, żeby nie rozcinać historii dziennika.
`actor_id` to moderator, `subject_type = 'Post'`, `subject_id` — obejrzany
wpis, `ip_hash` z żądania, `metadata = {"powod": "ukryta_tresc" | "rola_moderatora",
"status": <status wpisu>}` (wiersze sprzed 30.09.2026 mają `metadata = NULL`).
**Bez treści wpisu**: identyfikator wystarcza, a treść nie ma trafiać do
drugiej tabeli, gdzie przeżyłaby jej poprawkę albo usunięcie. Wejście autora na własny wpis wpisu
nie zostawia. Podgląd jest tylko do odczytu — zapis do zeszytu, zgłoszenie
i komentarz odmawia `PostPolicy` (`save`, `report`, `comment`), więc innych
wpisów z tej strony nie ma. Retencja zwykła, jak `admin.user_viewed` — wpis
NIE należy do `AuditLogEntry::NIGDY_NIE_KASUJ`, bo nie jest dowodem wykonania
żądania z RODO art. 17. Tabela i jej schemat się nie zmieniają: `action` nie
ma CHECK-a, więc nowa nazwa zdarzenia nie wymaga migracji ani rollbacku.

**`moderation.media_viewed`** — wgląd moderatora w zdjęcie, którego nie
zobaczyłby bez roli (D-333, dziennik wglądów; `App\Domain\Moderation\DziennikWgladu`,
zapis w `MediaController` dopiero gdy bajty naprawdę wychodzą). `actor_id` to
moderator, `subject_type = 'Media'`, `subject_id` — zdjęcie, `metadata.powod`:
`zgloszenie` (zdjęcie jest celem zgłoszenia albo należy do wpisu ze zgłoszeniem
automatu — wyjątek z `DostepDoZdjecia::celemZgloszeniaDlaObslugi()`),
`ukryta_tresc` (rodzic — wpis, przepis albo krok przepisu — jest ukryty lub
zdjęty) albo `rola_moderatora` (inna droga tylko dla obsługi, np. treść konta
zbanowanego). `metadata.sprawy` — posortowane identyfikatory zgłoszeń albo
`Typ:id` treści, które uzasadniają wgląd (najwyżej 10). „Bez roli" rozstrzyga
pytanie kontrfaktyczne: to samo konto z rolą `user` (`jakZwykleKonto()`,
w pamięci). **Nie zostawiają wpisu:** zdjęcia jawne, wejścia autora
i właściciela, zdjęcia, które moderator widzi też jako zwykłe konto, odmowy
(404). **Jeden wpis na godzinę na (moderator, zdjęcie, powód, sprawy)**
(`OKNO_ZDJECIA_MINUTY`) — otwarcie sprawy to kilka żądań o ten sam plik, ale
wgląd w inną sprawę o to samo zdjęcie jest osobnym wpisem. Bez treści zdjęcia.
Retencja zwykła, poza `NIGDY_NIE_KASUJ`. Bez migracji (`action` nie ma CHECK-a).

**`moderation.hidden_recipe_viewed`** — wgląd obsługi w przepis niewidoczny bez
roli (ukryty, zdjęty albo konta zbanowanego) na stronie przepisu, w trybie
gotowania i w API (`DziennikWgladu::przepis()` po `authorize('view')`), gdy
otwiera go moderator niebędący autorem. `subject_type = 'Recipe'`,
`metadata = {powod, status}`, `ip_hash` z żądania, retencja zwykła.

**Eksport i rejestr.** Wpisy wglądu nie wchodzą do eksportu danych konta
(tak samo jak reszta `audit_log`, w tym `admin.user_viewed`): są dziennikiem
działań obsługi, nie treścią użytkownika, i nie zawierają danych poza
identyfikatorami. Osobnej wzmianki w rejestrze czynności nie trzeba —
to ta sama czynność (moderacja) i ten sam dziennik.

**`recipe_version.hidden`, `recipe_version.restored`** — ukrycie i przywrócenie
jednej wersji przepisu z „Historii zmian" (#2270,
`App\Domain\Recipes\Historia\UkrywanieWersji`). `actor_id` — autor albo
moderator, `subject_type = 'RecipeVersion'`, `subject_id` — wersja,
`metadata`: `recipe_id`, `version_number`, `strona` (`author` | `moderator`),
przy przywróceniu także `ukryl` (kto ukrył). **Bez treści wersji** — dziennik
nie może być drugim miejscem, w którym ukryty tekst przeżywa. **Klasa 1
(D-249)**: `record()` wewnątrz transakcji ukrycia, bo `recipe_versions` mówi
tylko, po której stronie ukryto wersję, a KTÓRE konto — wyłącznie ten wpis.
Trzeci wpis tej rodziny, **`recipe_version.returned_to_author`**, zostaje po
uznanym odwołaniu od PRZEJĘCIA ukrycia: wersja wraca do ukrycia przez autora
(dalej ukryta), `metadata` jak przy przywróceniu (`ukryl = moderator`,
`moderation_action_id` decyzji `unhide`).

**`moderation.hidden_recipe_version_viewed`** — wgląd moderacji w wersję
ukrytą (strona wersji albo porównanie z nią), ta sama zasada 3.2 co
`moderation.hidden_post_viewed`. Autor oglądający własną wersję wpisu nie
zostawia. Wpis pomocniczy (`recordBezWywracania`), bez metadanych. Retencja
zwykła dla wszystkich trzech.
