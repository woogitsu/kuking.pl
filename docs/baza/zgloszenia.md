# Zgłoszenia i pilne alarmy

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### reports

`alarm_czlowieka_obsluzony_at` (migracja
`2026_09_28_120000_add_human_urgent_alarm_handled_at_to_reports`, #2066):
trwały znacznik, że pilne zgłoszenie od człowieka zostało już obsłużone
alarmem — zadanie pocztowe trafiło do kolejki albo inne zgłoszenie tego
samego celu zajęło bieżące okno. `NULL` pozwala ponowić próbę po awarii,
braku adresu lub wyczerpaniu budżetu. Nie jest to dowód doręczenia listu.
Zapis znacznika, klucza celu, budżetu i zadania odbywa się na tym samym
połączeniu PostgreSQL w jednej transakcji. `down()` odmawia skasowania
niepustego śladu; świadome cofnięcie wymaga
`KUKING_ROLLBACK_KASUJ_SLAD_ALARMOW_CZLOWIEKA=true`, gdyż powrót starego
formularza mógłby wtedy ponownie zlecić list.

### human_urgent_alarm_attempts

Jedna próba alarmu od człowieka to jeden wiersz z UUID `id` przekazanym do
`PilneZgloszenieOdCzlowieka`. `report_id` wskazuje sprawę i znika wraz z nią
po okresie retencji zgłoszenia. Wiersz nie zawiera adresu, treści zgłoszenia
ani komunikatu wyjątku. `state` ma zamknięty CHECK: `queued` (zadanie zapisano
we wspólnej transakcji z budżetem), `started` (worker podjął), `accepted`
(dostawca przyjął, **nie** potwierdzenie doręczenia), `rejected` (dostawca
potwierdził odmowę możliwą do ponowienia), `uncertain` (np. timeout po
wysyłce), `blocked` (odmowa trwała lub nierozpoznana), `retried` (nowa próba
zajęła budżet i została zakolejkowana). `failure_kind` jest wyłącznie kodem
`PowodOdmowy`, bez wiadomości dostawcy. Znaczniki czasu odpowiadają kolejnym
stanom i pozwalają odróżnić zlecenie od pracy workera.

Komenda `kuking:ponow-pilne-alarmy-od-ludzi` bierze tylko potwierdzone
odmowy nadal otwartych pilnych spraw od ludzi młodszych niż 72 h. Blokada
wiersza `reports` i celu oraz transakcja obejmują zmianę `retried`, nową
próbę, rezerwację budżetu i wpis w `jobs`. Timeout i każdy niejednoznaczny
wynik pozostają do ręcznej interwencji; komenda zgłasza je kodem błędu,
bez ujawniania treści. Próba ma jedną wysyłkę workera; ponowne uruchomienie
tego samego zadania nie wysyła listu, także po stanie `started`. Stany
`queued` i `started` starsze niż dwie godziny dają sygnał operacyjny,
ponieważ worker mógł umrzeć bez zapisu wyniku.

Rollback migracji tworzącej tabelę działa tylko przy tabeli pustej. Przy
niepustej odmawia usunięcia śladów: utrata stanu po ponownym wdrożeniu
mogłaby wysłać duplikat alarmu. Wycofanie produkcyjne wymaga najpierw
zatrzymania nowej komendy i workerów, wyjaśnienia wszystkich prób oraz
osobno zatwierdzonego planu danych; nie uruchamiać `down()` na skróty.

Zgłoszenia — **dwie różne drogi w jednej tabeli**, rozróżniane kolumną
`source` (migracja `2026_09_06_200000_add_legal_notice_fields_to_reports`,
audyt G-08 / W5-01 / W5-02).

| `source` | Co to jest | Kto może zgłosić |
|---|---|---|
| `community` | Nasze zasady: spam, chamstwo, niebezpieczna porada. Przycisk „Zgłoś” pod treścią. | Tylko zalogowani. |
| `legal_notice` | Treść **niezgodna z prawem** w rozumieniu DSA art. 16. | **Każdy, także bez konta.** |
| `automat` | **Oznaczenie do przeglądu postawione przez wykrywacz sygnałów** (D-052, migracja `2026_09_09_400000_sygnaly_automatu_w_zgloszeniach`). Nikt tego nie zgłosił. | Nikt — wiersz tworzy `OznaczDoPrzegladu` z zadania `PrzeanalizujTresc`. |

Nie robimy dwóch tabel, bo obie drogi kończą się tą samą decyzją moderatora,
tym samym wpisem w `moderation_actions` i tą samą ścieżką odwołania. Dwie
tabele znaczyłyby dwie kolejki, dwa ekrany i dwie okazje, żeby jedna z nich
została z tyłu.

#### `source = 'automat'` (D-052)

Trzecia droga i **jedyna, w której nie ma człowieka po stronie zgłaszającego**.
Nie uruchamia obowiązków z DSA art. 16 ust. 4 i 5 (potwierdzenie odbioru,
informacja o decyzji) — nie ma komu odpowiedzieć, bo nikt nic nie zgłosił.

Dwie rzeczy różnią ją od pozostałych w schemacie:

- `reporter_id` jest **zawsze puste**, a `autor_tresci_id` — wypełnione
  (kolumna dołożona tą samą migracją; `nullOnDelete`, tak jak `reporter_id`);
- oznaczenie powstaje **najwyżej raz na treść, na zawsze** — także po
  odrzuceniu przez moderatora. To nie jest deduplikacja, tylko obietnica:
  „to nic takiego" ma zamknąć sprawę i automat już z tym nie wraca.

Pełny opis sygnałów, progów i fałszywych alarmów:
`docs/legal/SYGNALY_AUTOMATU.md`.

##### Ślad po pilnym alarmie (issue #1051)

Migracja `2026_09_23_110000_dodaj_slad_pilnego_alarmu_do_reports` dokłada
dwie kolumny, jeden indeks częściowy i CHECK `reports_alarm_pilny_spojny_check`
(stoi po `2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem`
z #1441 — obie zmieniają `reports`, ale różne kolumny):

| Kolumna | Po co |
|---|---|
| `alarm_pilny_stan` | **`NULL` = sprawa nie jest pilna** i tak wygląda ogromna większość wierszy. Każda inna wartość znaczy „automat uznał tę sprawę za pilną" i mówi, co się z alarmem stało: `zalegly`, `zlecony`, `bez_adresu`, `nieudany` (stałe `Report::ALARM_*`). |
| `alarm_pilny_zlecony_at` | Kiedy alarm został **zlecony kanałowi pocztowemu**. Nazwa mówi `zlecony`, a nie `wyslany`, celowo: `PilnyAlarmModeracyjny` jest `ShouldQueue`, więc w tym miejscu nie da się wiedzieć, czy EmailLabs list przyjął. Za dalszy odcinek drogi odpowiadają `failed_jobs` i `mail_failures`. |

Bez tych kolumn pilność sprawy **nie dawała się odtworzyć z bazy**: żyła
w liście obiektów `Sygnal` w pamięci workera, a do `reports` trafiał sam kod
powodu (`automat_model`), identyczny dla sprawy pilnej i niepilnej. Skutek
był taki, że sprawa zapisana bez wysłanego alarmu wyglądała dokładnie tak
samo jak dzień bez ani jednego pilnego zgłoszenia.

`alarm_pilny_stan` zapisuje **ta sama transakcja**, która zapisuje wiersz
(`OznaczDoPrzegladu`) — obowiązek alarmu nie może mieć własnej szczeliny,
skoro powstał po to, żeby szczelinę zamknąć.

Indeks częściowy `reports_pilny_alarm_bez_sladu` obejmuje dokładnie
`alarm_pilny_stan IS NOT NULL AND alarm_pilny_zlecony_at IS NULL`, czyli
wiersze, z których biorą sonda `alarmy_moderacji` w `/health` i komenda
`kuking:doslij-pilne-alarmy` (`Report::scopePilneBezAlarmu()`, zawężane
w `scopePilneDoDoslania()` do spraw otwartych). Wiersz **wychodzi** z indeksu w chwili,
w której alarm dochodzi do skutku, więc indeks zostaje mały na zawsze.

CHECK `reports_alarm_pilny_spojny_check` pilnuje zamkniętego słownika
i spójności pary:

```sql
(alarm_pilny_stan IS NULL AND alarm_pilny_zlecony_at IS NULL)
OR (alarm_pilny_stan IS NOT NULL AND alarm_pilny_stan IN ('zalegly','bez_adresu','nieudany') AND alarm_pilny_zlecony_at IS NULL)
OR (alarm_pilny_stan IS NOT NULL AND alarm_pilny_stan = 'zlecony' AND alarm_pilny_zlecony_at IS NOT NULL)
```

`alarm_pilny_stan IS NOT NULL` w dwóch ostatnich gałęziach nie jest
nadmiarowe: CHECK przepuszcza wynik NULL, a `NULL IN (...)` daje NULL —
bez tego przeszedłby wiersz niepilny ze znacznikiem zlecenia.

Znacznik jest ustawiony **wtedy i tylko wtedy**, gdy stan to `zlecony`.
`AlarmujModeratora` zapisuje stany porażki tylko pod
`alarm_pilny_zlecony_at IS NULL`, więc drugie zadanie, któremu padła poczta,
nie nadpisze alarmu zleconego przez pierwsze; CHECK jest tym samym na
poziomie bazy. Od `reports_resolution_complete_check` (#1441) jest
niezależny: alarm nie zmienia statusu sprawy, a zamknięcie sprawy nie
zmienia stanu alarmu.

Kolumny wypełnia dziś **tylko automat** (`OznaczDoPrzegladu`,
`AlarmujModeratora`). Zgłoszenia od człowieka (`ReportContent`,
`ZglosNielegalnaTresc`) mają `alarm_pilny_stan = NULL` i sonda
`alarmy_moderacji` ich nie widzi.

Wierszom sprzed tej migracji obie kolumny zostają **puste** — świadomie.
`'zlecony'` byłoby kłamstwem (nikt tego nie zmierzył), `'zalegly'`
zapaliłoby sondę dla setek spraw, które alarmu nigdy nie potrzebowały.
Granica przebiega w dacie wdrożenia. Cofnięcie migracji **odmawia**, gdy
którakolwiek sprawa ma zapisany stan (D-088) —
`KUKING_ROLLBACK_KASUJ_SLAD_ALARMOW=true` mówi to wprost.

Kolumny dołożone dla drogi prawnej:

| Kolumna | Po co |
|---|---|
| `notifier_name` | Imię i nazwisko albo nazwa instytucji (art. 16 ust. 2 lit. b). |
| `notifier_email` | **Może być `NULL`** — art. 16 ust. 2 lit. c zwalnia z podania danych przy zgłoszeniach dotyczących przestępstw z art. 3–7 dyrektywy 2011/93/UE. Wtedy nie ma komu odpowiedzieć i to jest zgodne z przepisem, a nie brak w danych. |
| `target_url` | Adres wpisany przez człowieka, zapisany dosłownie (art. 16 ust. 2 lit. b — „dokładna lokalizacja elektroniczna”). To dowód, nie zaufany cel: `target_type`/`target_id` wyznaczamy z niego tylko dla bezwzględnego adresu http(s) na `kuking.pl`, `www.kuking.pl` albo hoście z `APP_URL`, bez `user@` i nieoczekiwanego portu (`App\Domain\Moderation\AdresZgloszenia`, #1636). W listach do zgłaszającego wartość idzie jako tekst, nie Markdown. |
| `illegality_explanation` | Uzasadnienie, osobne od swobodnego `details` (art. 16 ust. 2 lit. a). |
| `good_faith_at` | Oświadczenie o dobrej wierze jako **znacznik czasu**, nie `boolean` — przy sporze liczy się, kiedy je złożono. |
| `receipt_sent_at` | Potwierdzenie odbioru przekazane zgłaszającemu (ust. 4). |
| `decision_sent_at` | Informacja o decyzji przekazana zgłaszającemu (ust. 5). Przy drodze prawnej stawia go list `DecyzjaWSprawieZgloszenia` **po wysłaniu**, nie akcja przy zakolejkowaniu (#1838, D-293). |

Bez dwóch ostatnich kolumn nie da się odpowiedzieć na pytanie „czy
powiadomiliśmy”, a przy audycie to jest pierwsze pytanie.

**Obie kolumny znaczą „powiadomiliśmy”, nie „poszedł list”** (issue #10).
Powstały przy drodze prawnej, gdzie jedynym kanałem jest poczta, ale od
domknięcia art. 16 po stronie zgłoszeń społecznościowych znaczy je także
powiadomienie w serwisie (`NotifyReporterReceipt`, `NotifyReporterDecision`).
Kanał wynika z wiersza: `reporter_id` niepuste to zgłoszenie z konta,
`notifier_email` niepuste — zgłoszenie prawne z adresem; nigdy oba naraz.
Indeks częściowy `reports_pending_receipt_idx` dalej dotyczy **wyłącznie**
zgłoszeń prawnych z adresem, więc ta zmiana znaczenia go nie rusza.

**Zakolejkowany list to jeszcze nie „powiadomiliśmy”** (issue #1838, D-293).
Przy drodze prawnej `decision_sent_at` stawia `afterSending()` listu z decyzją,
gdy transport pocztowy przyjął wiadomość. Między decyzją a pracą workera
kolumna jest pusta; po ostatecznej porażce zostaje pusta. Rozstrzygnięte
sprawy prawne z adresem i pustym znacznikiem liczy
`Report::decyzjaNieprzekazanaMailem()`; czy list czeka, czy przepadł, mówi
`failed_jobs` (`php artisan queue:failed`), nie ta kolumna.

#### `target_type = 'media'` — zdjęcie jako osobny cel (issue #237)

Migracja `2026_09_10_300000_zdjecie_jako_cel_oznaczenia` dopisuje do
`reports_target_type_check` wartość **`media`**. Wprowadził ją automat oceny
zdjęć profilowych (issue #237), a oznaczenie wskazywało `media.id`.

**Stan od D-240: wartości `media` nic dziś nie produkuje.** Zdjęcie profilowe
nie jest wysyłane do modelu — `AvatarSettingsController` nie zleca
`PrzeanalizujAwatar`, a samo zadanie jest pustym no-opem zostawionym wyłącznie
dla zleceń sprzed wdrożenia. Awatar zgłoszony przez człowieka ma cel `user`
(„Zgłoś” na profilu), nie `media`. Wartość zostaje w ograniczeniu dla
**historycznych** oznaczeń awatarów sprzed D-240: to sprawy moderacyjne
z decyzjami i odwołaniami, które dalej dają się rozpatrzyć. Ponowne włączenie
oceny awatarów wymaga osobnej decyzji z celem zgody, ekranem jej udzielania
i wycofania oraz sprawdzeniem zgody przed wysyłką
(`docs/legal/SYGNALY_AUTOMATU.md` §9.1).

#### `target_type = 'collection'` — publiczny zeszyt jako cel zgłoszenia (#2279)

Migracja `2026_09_30_163500_zeszyt_jako_cel_zgloszenia` dopisuje do
`reports_target_type_check` wartość **`collection`**. Regulamin §7 obiecuje
„Zgłoś” przy każdej treści, a nazwa i opis publicznego zeszytu są tekstem
właściciela widocznym dla wszystkich. `target_id` to `collections.id`.
Decyzje (`ModerationAction::DOZWOLONE['collection']`): brak działań,
ostrzeżenie, zawieszenie, ban właściciela — **bez `hide` i `remove`**, bo zeszyt
nie ma statusu ani miękkiego kasowania.

DDL jak w AGENTS.md §6: `DROP CONSTRAINT` i `ADD CONSTRAINT … NOT VALID` w jednym
`ALTER TABLE` (bez chwili bez CHECK-a), potem osobno `VALIDATE CONSTRAINT`,
migracja poza transakcją. **Rollback (D-088):** `down()` odmawia, gdy w `reports`
leży choć jedno zgłoszenie zeszytu — to sprawy moderacyjne z decyzjami; bez
takich wierszy przywraca poprzednią listę wartości. Test:
`ZglosPrzyUgotowanymIZeszycieTest::test_rollback_odmawia_gdy_jest_zgloszenie_zeszytu_a_bez_niego_przechodzi`.

**Dlaczego zdjęcie, a nie konto (uzasadnienie z #237).** Indeks `reports_jeden_automat_na_tresc`
przepuszcza jedno oznaczenie automatu na (typ, identyfikator) na zawsze.
Przy celu `user` oceniony zostałby pierwszy awatar konta i żaden następny,
a podmiana zdjęcia to sekunda pracy.

**Dlaczego `media`, a nie `avatar`.** `ModeratedContent::TYPY` mapuje klasę
modelu, a klasa (`App\Models\Media`) jest ta sama dla awatara i dla zdjęcia
we wpisie. Nazwa `avatar` byłaby prawdziwa w #237 i kłamliwa pierwszego dnia,
w którym oznaczymy zdjęcie z wpisu osobno.

`ModerationAction::DOZWOLONE['media']` to `none`, `warn`, `suspend`, `ban` —
**bez `hide`** (`Media` nie ma statusu w rozumieniu moderacji) i **bez
`remove`** (kasowanie zdjęcia jest nieodwracalne, a odwołanie od `remove` ma
treść przywrócić — DSA art. 17).

**Rollback:** `php artisan migrate:rollback --step=1` przywraca CHECK bez
`media`, ale **odmawia**, gdy w tabeli leży choć jedno takie oznaczenie —
to są sprawy moderacyjne z decyzjami i odwołaniami, a rollback schematu nie
jest decyzją o ich wyrzuceniu. Komunikat mówi, co zrobić.

#### `target_type = 'recipe_version'` — konkretna wersja przepisu jako cel zgłoszenia (#2390)

Migracja `2026_10_01_100200_wersja_przepisu_jako_cel_zgloszenia` dopisuje do
`reports_target_type_check` wartość **`recipe_version`** (decyzja właściciela
z 1.10.2026, D-333: osoba trzecia — także gość — zgłasza konkretną wersję
z historii zmian przepisu; usunięcie danych z historii to ukrycie CAŁEJ wersji,
bez redakcji migawki). `target_id` to `recipe_versions.id` (bez klucza obcego,
jak przy `moderation_actions`). Zgłosić wolno tylko wersję widoczną dla
zgłaszającego i **nie najnowszą** (`RecipeVersionPolicy::report`); wersję ukrytą
zgłaszają tylko autor i moderacja.

Decyzje (`ModerationAction::DOZWOLONE['recipe_version']`): brak działań,
ostrzeżenie, **ukrycie wersji** (`hidden_at`, przez
`DecyzjaOWersjiPrzepisu::ukryjPoZgloszeniu`), zawieszenie i ban autora
przepisu — **bez `remove`** (wersję usuwa wyłącznie retencja). Decyzja ze
zgłoszenia ma `report_id`, `subject_user_id` = autor przepisu, a
`user_message` zaczyna się zdaniem wskazującym wersję (`WskazanieWersji`).

DDL jak w AGENTS.md §6: `DROP CONSTRAINT` i `ADD CONSTRAINT … NOT VALID` w jednym
`ALTER TABLE`, potem osobno `VALIDATE CONSTRAINT`, migracja poza transakcją.
**Rollback (D-088):** `down()` odmawia, gdy w `reports` leży choć jedno zgłoszenie
wersji — to sprawy moderacyjne z decyzjami i odwołaniami; bez takich wierszy
przywraca poprzednią listę wartości. Test:
`ZgloszenieWersjiPrzepisuTest::test_rollback_odmawia_gdy_jest_zgloszenie_wersji_a_bez_niego_przechodzi`.

#### `target_type = 'unknown'` i puste `target_id`

Adres bywa nierozpoznawalny: ktoś wkleja link z pamięci albo ze zrzutu
ekranu, treść mogła już zniknąć, adres bywa z innego serwisu. **Zgłoszenie
i tak musi zostać przyjęte** — odmowa byłaby odmówieniem mechanizmu, który
przepis nakazuje udostępnić. Dlatego:

- `reports_target_type_check` dopuszcza typ `unknown` (jedna z dziewięciu wartości po dodaniu `media`, `collection` i `recipe_version`);
- `target_id` w `reports` **i** w `moderation_actions` jest teraz `NULL`-owalne.

`NULL`, a nie UUID z samych zer: identyfikator, który wygląda jak
identyfikator i niczego nie wskazuje, prędzej czy później trafiłby do
zapytania albo na ekran moderatora.

Zgłoszenie **społecznościowe** dalej musi mieć cel — pilnuje tego
`reports_community_target_check`. Tam przycisk stoi pod konkretną treścią,
więc brak celu znaczyłby błąd w kodzie, a nie sytuację życiową.

Konsekwencja w kodzie: `ModeratedContent::znajdz()` zwraca `null` dla typu
`unknown`, a `ModerationAction::dozwoloneDla()` zwraca wtedy samo `none` —
moderator może taką sprawę zamknąć i odpowiedzieć, ale nie ukryje treści,
której mu nie wskazano.

#### Zamknięcie zgłoszenia: `resolution_note`, `resolved_by`, `resolved_at`

**`resolution_note varchar(2000) NULL`** — notatka moderatora, **widoczna
wyłącznie wewnętrznie**. Nie jest tym samym co `moderation_actions.user_message`
(zdanie wysyłane człowiekowi) ani co `moderation_actions.note`: tamte dwie
należą do DECYZJI o treści, ta należy do ZGŁOSZENIA i tłumaczy, dlaczego
kolejka je zamknęła — także wtedy, gdy żadna decyzja nie zapadła
(`status = 'rejected'`). Wolny tekst, bez CHECK-a.

`resolved_by uuid NULL` → `users` (`ON DELETE SET NULL`) i `resolved_at
timestamptz NULL` — kto i kiedy zamknął. `SET NULL` jest tu świadome: konto
moderatora bywa anonimizowane, a zgłoszenie ma zostać zamknięte dalej.

#### Pozostałe ograniczenia i indeksy

| Nazwa | Co pilnuje |
|---|---|
| `reports_source_check` | `source IN ('community','legal_notice')`. |
| `reports_legal_notice_complete_check` | Zgłoszenie prawne **musi** mieć uzasadnienie i `good_faith_at`. CHECK, a nie sama walidacja formularza: przy audycie liczy się to, czego baza nie mogła przyjąć. **Imienia (`notifier_name`) już NIE wymaga** — migracja `2026_09_07_600000_allow_anonymous_legal_notices`. Art. 16 ust. 2 lit. c DSA zwalnia z podania DANYCH zgłaszającego (nie tylko adresu e-mail) przy zgłoszeniach dotyczących przestępstw z art. 3-7 dyrektywy 2011/93/UE, a wcześniej reguła była w kodzie w połowie: brak adresu wolno, brak nazwiska nie. `down()` tej migracji **przerywa się**, jeśli w bazie są już anonimowe zgłoszenia — przywrócenie starego warunku wymagałoby albo wpisania im wymyślonego nazwiska (kłamstwo w kolumnie), albo skasowania (zniszczenie dowodu w najcięższej możliwej sprawie). |
| `reports_numer_sprawy_unique (numer_sprawy)` | Numer sprawy jest UNIKALNY — pilnuje tego baza, nie PHP (D-029, migracja `2026_09_07_910000_add_numer_sprawy_to_reports`). |
| `reports_numer_sprawy_check` | Format numeru: `^KU-[23456789ABCDEFGHJKMNPQRSTVWXYZ]{4}-[…]{4}$`. CHECK, nie sam wzór w PHP: ten numer trafia do korespondencji i do pisma, więc wartość w innym formacie nie ma prawa wejść żadną drogą. |
| `reports_source_status_idx (source, status, created_at)` | Kolejka moderatora filtruje po źródle — zgłoszenia prawne mają termin odpowiedzi, społecznościowe nie. |
| `reports_pending_receipt_idx` | Indeks częściowy: zgłoszenia prawne z adresem, którym jeszcze nie potwierdzono odbioru. |
| `reports_one_open_per_pair (reporter_id, target_type, target_id)` | Indeks częściowy `WHERE reporter_id IS NOT NULL AND status IN ('open','triage','reviewing')`: jedno OTWARTE zgłoszenie na parę osoba–treść (D-027, migracja `2026_09_07_900000_one_open_report_per_pair`). Dedup w PHP już to robił w zwykłym ruchu, ale nie chronił przed seederem, komendą ani wyścigiem. Nowe zgłoszenie po zamknięciu poprzedniego przechodzi — bo zamknięte statusy są poza indeksem. **Zgłoszeń bez konta ten indeks nie obejmuje** (`reporter_id IS NULL`); te pilnuje `klucz_wyslania`. |
| `autor_tresci_id` | Autor OZNACZONEJ treści — wypełniany **wyłącznie** przy `source = 'automat'` (D-052). FK → `users`, `nullOnDelete`: skasowanie konta nie kasuje sprawy moderacyjnej. Istnieje po to, żeby kolejka automatu grupowała po autorze bez odczytywania go z czterech różnych tabel dla każdego wiersza — dziesięć wpisów tego samego spamera ma być jedną pozycją do przejrzenia, nie dziesięcioma. |
| `reports_source_check` (zmieniony) | `source IN ('community','legal_notice','automat')`. |
| `reports_automat_target_check` | Oznaczenie automatu **musi** mieć cel (`target_type` inny niż `unknown`, `target_id` niepuste). Automat ogląda konkretną treść, więc wiersz bez celu znaczyłby błąd w kodzie, a nie sytuację życiową — ta sama zasada co `reports_community_target_check`. |
| `reports_jeden_automat_na_tresc (target_type, target_id)` | Indeks częściowy `WHERE source = 'automat'`: **jedno oznaczenie na treść, na zawsze**. Warunek celowo NIE zawęża się do spraw otwartych (inaczej niż `reports_one_open_per_pair`) — tam nowe zgłoszenie po zamknięciu poprzedniego składa człowiek, który widzi coś nowego; tu wracałby ten sam automat z tym samym powodem. |
| `reports_automat_autor_idx (autor_tresci_id, created_at DESC)` | Indeks częściowy `WHERE source = 'automat' AND status IN ('open','triage','reviewing')`: kolejka automatu grupowana po autorze. Obejmuje **tylko pozycje otwarte**, więc kolejka, którą moderator opróżnia, naprawdę tanieje. |
| `reports_one_per_klucz_wyslania (klucz_wyslania)` | Indeks częściowy `WHERE klucz_wyslania IS NOT NULL`: jedno wysłanie formularza to jeden wiersz (D-027, migracja `2026_09_07_900300_add_klucz_wyslania_to_reports`). Bez `reporter_id` w kluczu, inaczej niż w `posts` i `cooked_events` — droga prawna jest otwarta dla osób bez konta, więc `reporter_id` bywa `NULL` i nie może być częścią warunku unikalności. |

**Rollback (D-052, `2026_09_09_400000_sygnaly_automatu_w_zgloszeniach`):**
`down()` zdejmuje oba indeksy i `reports_automat_target_check`, po czym
**przerywa**, jeśli w tabeli są oznaczenia automatu już ROZSTRZYGNIĘTE
(`resolved`/`rejected`) — niosą powód, dla którego moderator coś ukrył albo
kogoś zawiesił, i są dokumentem przy odwołaniu. Strażnik stoi PRZED pierwszym
`DELETE`, nie po nim. Oznaczenia OTWARTE giną bez pytania: nikt niczego przy
nich nie postanowił, a automat postawi je z powrotem, gdy migracja wróci.
Świadome wymuszenie (najpierw kopia tabeli):
`KUKING_ROLLBACK_KASUJE_SYGNALY_AUTOMATU=1`. Wiersze w `moderation_actions`
przeżywają skasowanie sprawy — `report_id` ma `nullOnDelete`, więc decyzja
zostaje i traci tylko odnośnik. Sprawdza to
`tests/Feature/CofniecieMigracjiSygnalowAutomatuTest.php`.

**Rollback:** `down()` **odmawia**, gdy w tabeli są zgłoszenia prawne —
usunięcie kolumn skasowałoby imię, adres i uzasadnienie, zostawiając samo
`reason`, czyli zgłoszenie bez treści. To są dane, na podstawie których
podjęto decyzje moderacyjne i na które ktoś mógł się powołać w odwołaniu.
Świadome wymuszenie: `KUKING_ROLLBACK_KASUJE_ZGLOSZENIA_PRAWNE=1` (najpierw
kopia tabeli).

**Retencja:** `config('kuking.moderation.case_retention_months')` (domyślnie
36 miesięcy — **decyzja właściciela**, 2026-09-07, art. 442¹ k.c.) od
`resolved_at`, tylko dla `status IN ('resolved','rejected')`. Sprawy
`open`/`triage`/`reviewing` nie są kandydatem **nigdy**, niezależnie od wieku.
Egzekwuje `kuking:sprzataj-sprawy-moderacyjne`
(`App\Domain\Compliance\PrzedawnioneSprawyModeracyjne`) razem z
`moderation_actions` i `appeals`, w jednej komendzie, harmonogram codziennie
o 04:30. Kasuje partiami po 500 identyfikatorów (partia w transakcji; gdy
padnie — powtórka wiersz po wierszu) i najwyżej 20 000 wierszy z każdej
tabeli na przebieg; resztę bierze następna noc, a ostrzeżenie w dzienniku
mówi, ile jej zostało (issue #998).

**`numer_sprawy` — to, co człowiek zapisuje na kartce** (D-029, migracja
`2026_09_07_910000_add_numer_sprawy_to_reports`).

```sql
ALTER TABLE reports ADD COLUMN numer_sprawy varchar(12) NOT NULL;
CREATE UNIQUE INDEX reports_numer_sprawy_unique ON reports (numer_sprawy);
```

Do 7 września 2026 numer nie był kolumną — liczył się w pięciu miejscach kodu
jako osiem pierwszych znaków UUID-a wiersza. **Nie był przez to unikalny:**
w UUID-zie v7 pierwsze 48 bitów to znacznik czasu w milisekundach, więc osiem
znaków szesnastkowych to jego 32 górne bity i zmieniają się raz na 65,5
sekundy. Zmierzone: `Str::uuid7('19:00:30')` i `Str::uuid7('19:01:10')` dają
oba `01A07D3E`. Dwie sprawy przyjęte w tym samym okienku miały ten sam numer,
a dla zgłaszającego bez konta ten numer jest jedynym śladem sprawy.

Format `KU-XXXX-XXXX` z 30-znakowego alfabetu bez `0`, `1`, `I`, `L`, `O`
i `U` — numer jest przepisywany ręcznie z ekranu i dyktowany przez telefon,
a `0`/`O`, `1`/`I` i `1`/`L` są wtedy tym samym znakiem
(`App\Support\NumerSprawy`). 30^8 to 656 miliardów kombinacji; szansa
kolizji przekracza 50% dopiero przy ~954 tys. spraw, a powtórzenia i tak nie
zapisze indeks.

**Nadaje go model, nie kontroler** (`Report::booted()`, hak `creating`), więc
każda droga powstania wiersza — formularz, seeder, komenda, `tinker` — numer
dostaje. `numer_sprawy` **nie jest w `$fillable`**: to tożsamość nadana przez
serwer, nie dana od człowieka (ta sama zasada co `status` i `role`
użytkownika). Surowy `INSERT` omijający model nie przechodzi wcale, bo kolumna
jest `NOT NULL`.

**Rollback:** `DROP CONSTRAINT reports_numer_sprawy_check`, `DROP INDEX`,
`DROP COLUMN`. `down()` **ODMAWIA**, gdy w tabeli są zgłoszenia prawne:
numery są losowe, więc po skasowaniu kolumny nie da się ich odtworzyć,
a zgłaszający bez konta traci jedyny sposób rozpoznania własnej sprawy.
Świadome wymuszenie: `KUKING_ROLLBACK_KASUJE_NUMERY_SPRAW=1`.

**Backfill** nadał numery istniejącym wierszom. Bezpieczny dokładnie dziś:
poczty serwis nie wysyła (`docs/decyzje/POCZTA.md`), więc żaden numer nie
został jeszcze nikomu przekazany. Po pierwszym wysłanym liście ta sama
migracja byłaby zmianą numeru pod ręką zgłaszającego.

**`klucz_wyslania` — dwa mechanizmy, nie jeden** (D-027,
`docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`). Ta tabela ma dwa różne indeksy
częściowe, bo chroni dwie różne rzeczy, i żaden nie zastępuje drugiego:

- `reports_one_open_per_pair` pilnuje **stanu**: ta sama osoba nie ma dwóch
  otwartych spraw o tę samą treść, niezależnie od tego, którą drogą przyszły.
  Działa tylko dla zgłoszeń z konta.
- `reports_one_per_klucz_wyslania` pilnuje **wysłania**: jedno kliknięcie
  „Wyślij zgłoszenie" to jeden wiersz, także gdy zgłasza ktoś bez konta.

**Wyłącznik `kuking.formularze.klucz_wyslania_wlaczony` cofa tylko drugi
z nich.** Pierwszy nie zależy od niczego, co wysyła formularz, więc jego
wycofanie to osobna migracja i osobne wdrożenie (`DROP INDEX IF EXISTS
reports_one_open_per_pair`) — to jest zapisane, żeby nikt w trakcie awarii nie
liczył na to, że zmiana zmiennej środowiskowej wystarczy.

**Rollback (`klucz_wyslania`):** `DROP INDEX IF EXISTS
reports_one_per_klucz_wyslania`, potem `DROP COLUMN klucz_wyslania`.
Bezstratnie — inaczej niż `down()` dla kolumn drogi prawnej wyżej, które
odmawia: w `klucz_wyslania` nie ma ani jednego słowa napisanego przez
człowieka, a samo zgłoszenie (adres, uzasadnienie, dobra wiara, numer sprawy)
zostaje nietknięte.

**Rollback (`reports_one_open_per_pair`):** `DROP INDEX IF EXISTS`,
bezstratnie. `up()` tej migracji natomiast **ODMAWIA**, gdy w bazie są już
duplikaty — dokładnie jak `2026_09_06_190000_one_decision_per_report` i z tego
samego powodu: ciche skasowanie „nadmiarowego" zgłoszenia byłoby skasowaniem
sprawy DSA, na którą ktoś mógł się powołać. Który wiersz obowiązuje,
rozstrzyga człowiek.

**`reports_resolution_complete_check` — status związany z datą rozstrzygnięcia**
(issue #997, migracja `2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem`).

```sql
CHECK (
  (status IN ('open','triage','reviewing')
     AND resolved_at IS NULL AND resolved_by IS NULL AND resolution_note IS NULL)
  OR (status IN ('resolved','rejected') AND resolved_at IS NOT NULL)
)
```

Retencja liczy od `resolved_at` i bierze tylko `resolved`/`rejected`, więc
zamknięta sprawa bez daty nie zostałaby skasowana **nigdy**, a otwarta z datą
wisiałaby w kolejce z fałszywym śladem rozstrzygnięcia. To trzeci przypadek
tego samego niezmiennika co `appeals_decision_complete_check`
i `contact_messages_handled_complete`.

- **`resolved_by` w stanie końcowym nie jest wymagane** — klucz ma świadome
  `nullOnDelete()`; fizyczne usunięcie konta operatora nie może unieważnić
  historycznej sprawy. Kto rozstrzygnął, zapisuje też niemutowalny
  `moderation_actions.moderator_id`.
- **`resolution_note` w stanie końcowym nie jest wymagane** — wewnętrzna
  notatka, formularz decyzji i odrzucenie oznaczeń automatu pozwalają ją
  pominąć (uzasadnienie dla człowieka: `moderation_actions.user_message`).
- **Lista statusów wypisana wprost** — nowy status w `reports_status_check`
  bez przemyślenia tej reguły odbije się o bazę. Celowo.
- Status zmieniają dziś dwie ścieżki i obie zapisują status, datę
  i moderatora jednym `update()`: `ModerationController::decide()`
  i `SygnalyController::odrzucGrupe()` (`StatusZgloszeniaZwiazanyZRozstrzygnieciemTest`).

**`up()` najpierw liczy niespójne wiersze i ODMAWIA**, gdy jakiekolwiek są —
z liczbami w komunikacie. Nie zgaduje: `created_at` jako data zamknięcia
przyspieszyłoby retencję i skasowało sprawę przed czasem. Potem
`ADD CONSTRAINT … NOT VALID` i osobno `VALIDATE CONSTRAINT`
(`$withinTransaction = false`, więc walidacja nie blokuje zapisów). Gdy
`VALIDATE` padnie (niespójny zapis w trakcie wdrożenia), CHECK jest zdejmowany,
żeby ponowne `migrate` zaczęło od czystego stanu.

**Zapytanie kontrolne przed wdrożeniem (tylko odczyt, dla właściciela):**

```sql
SELECT id, numer_sprawy, source, status, resolved_at, resolved_by,
       resolution_note IS NOT NULL AS ma_notatke, created_at,
       (SELECT min(ma.created_at) FROM moderation_actions ma WHERE ma.report_id = r.id) AS data_decyzji
FROM reports r
WHERE (status IN ('resolved','rejected') AND resolved_at IS NULL)
   OR (status IN ('open','triage','reviewing')
       AND (resolved_at IS NOT NULL OR resolved_by IS NOT NULL OR resolution_note IS NOT NULL))
ORDER BY created_at;
```

Pusty wynik = migracja przejdzie. Wiersze w wyniku poprawia człowiek: datę
zamknięcia bierze z `data_decyzji`, a nie z `created_at`; otwarta sprawa
z polami rozstrzygnięcia jest albo zamknięta (popraw status), albo otwarta
(wyczyść trzy pola).

**Rollback:** `DROP CONSTRAINT IF EXISTS reports_resolution_complete_check`.
Bezstratnie — poluzowanie reguły nie dotyka żadnego wiersza, więc nie ma
czego odmawiać (inaczej niż w przypadkach z D-088).
