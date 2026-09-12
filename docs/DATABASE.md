# Model danych

## Zasady

- UUID dla publicznych encji;
- `timestamptz`;
- realne foreign keys;
- constraints w bazie;
- soft delete tam, gdzie pomaga odzyskiwaniu/moderacji;
- JSONB tylko dla półstrukturalnych danych;
- recipe versions od początku.

## Czym się sprawdza, że wycofania naprawdę działają

`AGENTS.md` §6 wymaga przy każdej zmianie schematu opisu rollbacku. Opis to
za mało — rollback trzeba URUCHOMIĆ, i robią to dwie różne rzeczy:

| Narzędzie | Co mierzy | Ile trwa |
|---|---|---|
| `./scripts/proba-wycofania.sh` | podnosi 76 migracji na WŁASNEJ bazie `proba_wycofania*`, schodzi krok po kroku do zera, wraca na szczyt i porównuje `pg_dump --schema-only` ze wzorcem — na każdej z 76 głębokości z osobna | kilka minut |
| `tests/Feature/KazdaMigracjaMaWycofanieTest.php` | że każda migracja MA własny, niepusty `down()`; świadoma pustka musi być zadeklarowana stałą `WYCOFANIE_NIC_NIE_ROBI` z uzasadnieniem | ułamek sekundy, w każdym `php artisan test` |

Skrypt schodzi do zera na PUSTEJ bazie, więc nie mierzy zachowania `down()`
przy danych — tego pilnują osobne testy odmowy (`CofniecieMigracji*Test`),
po jednym na strażnika z D-088. Stan zmierzony 12 września 2026: 76 z 76
migracji wycofuje się i wraca, a schemat po cyklu jest identyczny ze wzorcem
na każdej głębokości.

## Tabele MVP

### users
Konto:
- id;
- email;
- password;
- status;
- `role varchar(20) NOT NULL DEFAULT 'user'` — `user` \| `moderator` \|
  `admin`. **Bez CHECK-a w bazie**: wartości pilnuje `App\Models\User`
  (stałe `ROLE_*`) i jedyna droga nadania roli, komenda `kuking:nadaj-role`,
  która zapisuje zmianę do `audit_log` (`user.role_changed`, D-039).
  **Nigdy w `$fillable`** (AGENTS.md §7) — razem ze `status` i `email`;
- `status_expires_at` — kiedy kara mija (patrz niżej);
- `delete_requested_at` — kiedy zgłoszono usunięcie konta (status `pending_delete`);
- `data_erased_at` — kiedy karencja się WYKONAŁA, dane zostały zanonimizowane
  (patrz niżej);
- `delete_scope` — ZAKRES usunięcia wybrany przez człowieka: `minimum`
  (domyślny — teksty zostają zanonimizowane) albo `everything` (patrz niżej);
- `locale` — język konta, `varchar(10) NOT NULL DEFAULT 'pl'`. Dziś
  **zawsze `pl`** (`ZalozKonto` wpisuje `'pl'` na sztywno, innego wyboru nie
  ma nigdzie w interfejsie); kolumna stoi, bo eksport danych ją oddaje
  (`jezyk`), a dołożenie języka po fakcie do tabeli z prawdziwymi kontami
  kosztuje więcej niż jedna kolumna dzisiaj;
- `age_confirmed_at timestamptz NULL` — kiedy padło oświadczenie o wieku.
  Wymóg prawny, nie preferencja użytkownika. Wpisuje ją `ZalozKonto`
  wartością `now()` na OBU drogach zakładania konta (hasłem i przez Google),
  a eksport danych oddaje ją jako `wiek_potwierdzony`. Kolumna jest
  `NULL`-owalna dla wierszy z fabryk i seederów — **`NULL` nie znaczy
  „ktoś odmówił"**: odmowa nie zakłada konta wcale, więc taki wiersz by nie
  powstał;
- text_scale;
- theme (patrz niżej);
- `wants_weekly_digest` — zgoda na cotygodniowy przegląd, stan BIEŻĄCY (patrz
  niżej); historia jej udzielania i wycofywania leży w `dziennik_zgod`;
- `weekly_digest_sent_at` — kiedy poszło ostatnie podsumowanie (patrz niżej);
- verified timestamps.

#### `wants_weekly_digest` — zgoda, o którą trzeba było zapytać

Migracja `2026_09_07_400000_default_weekly_digest_to_off`.

Kolumna powstała z `DEFAULT true`, a formularz rejestracji o tę zgodę
**nigdy nie pytał** — `resources/views/auth/register.blade.php` ma tylko
`age_confirmed` i `terms_accepted`. Każde nowe konto wstawało więc zapisane
na wysyłkę, o którą nikt go nie zapytał, a polityka prywatności opiera tę
wysyłkę na art. 6 ust. 1 lit. a RODO — czyli na zgodzie.

```sql
ALTER TABLE users ALTER COLUMN wants_weekly_digest SET DEFAULT false;
UPDATE users SET wants_weekly_digest = false WHERE wants_weekly_digest = true;
```

Migracja rusza także ISTNIEJĄCE wiersze, i wolno jej, bo nie ma czego
stracić — oba fakty zmierzone:

1. zgody nie da się wyrazić przy rejestracji (pola nie ma), więc żadne
   `true` w bazie nie pochodzi z decyzji człowieka, tylko z tego `DEFAULT`;
2. cotygodniowego przeglądu **nie ma w kodzie w ogóle** — zero mailable'i,
   zero notyfikacji, zero jobów, a żadne odczytanie tej kolumny nie jest
   klauzulą `where` wybierającą odbiorców. Nic nie zostało wysłane.

Kto chce ten przegląd, włącza go haczykiem na `/ustawienia/prywatnosc`.
Pilnuje tego test KONTROLNY w `ZgodaNaPrzegladNieJestDomyslnaTest` — bez
niego reszta tego testu przechodziłaby także wtedy, gdyby ktoś przez pomyłkę
zabetonował pole na `false` i odebrał ludziom możliwość zapisania się.

> **Uzupełnienie, 10 września 2026 (issue #11, D-057).** Punkt 2 wyżej
> („cotygodniowego przeglądu nie ma w kodzie w ogóle") opisywał stan
> z 7 września i **przestał być prawdą**: wysyłka istnieje
> (`kuking:wyslij-podsumowania`, harmonogram codziennie o 08:30). Zapis
> zostaje tutaj nienaruszony, bo uzasadnia MIGRACJĘ, która wtedy ruszyła
> istniejące wiersze — a wtedy naprawdę nie było czego stracić. Dziś taka
> migracja byłaby odebraniem komuś zgody, którą świadomie wyraził.

**Rollback tej migracji NIE przywraca `DEFAULT true`** (poprawka z 10 września,
audyt DB2, D-072). `down()` jest świadomie pusty: `DEFAULT false` zostaje także
po cofnięciu, bo wysyłka już istnieje i techniczny rollback zapisywałby NOWE
konta na prawdziwy mailing, o który formularz rejestracji nadal nie pyta.
Asymetria jest pełna i jawna — `up()` przestawia `DEFAULT` oraz istniejące
wiersze, `down()` nie przywraca ani jednego, ani drugiego. Tej migracji nie da
się więc cofnąć „wiernie historycznie" i tak ma być: wierny rollback wraca do
stanu groźnego, a listu wysłanego bez zgody nie da się odwołać. Powrót do
opt-outu wymaga jawnego `ALTER TABLE users ALTER COLUMN wants_weekly_digest SET
DEFAULT true` z ręki i dopisania pola zgody do rejestracji.

Niezależnie od drogi, którą `DEFAULT true` mógłby wrócić (ręczny `ALTER`,
przywrócenie bazy z kopii sprzed migracji, rollback na starszym wydaniu kodu),
**wysyłka odmawia startu**: `App\Domain\Digest\BramkaDomyslnejZgody` pyta
`information_schema` przy każdym uruchomieniu `kuking:wyslij-podsumowania`
i przy domyślnym `true` kończy kodem 1, z komunikatem mówiącym, co zrobić.
Przebieg `--na-sucho` przechodzi (nic nie wysyła), ale ostrzega. Pilnuje tego
`RollbackNieWlaczaDigestuTest` — asercją na `information_schema`, nie na
komentarzu w migracji.

#### `weekly_digest_sent_at` — kiedy poszedł ostatni list

Migracja `2026_09_10_100000_add_weekly_digest_sent_at_to_users_table`.

`timestamptz NULL`, bez wartości domyślnej. `NULL` znaczy „jeszcze nigdy nie
dostał" i w dniu tej migracji jest w tym stanie **każde** konto, bo digest do
tej pory nie wyszedł ani razu.

Kolumna robi trzy rzeczy naraz i każda z nich jest konieczna:

1. **pilnuje obietnicy „jeden e-mail tygodniowo, nigdy więcej"** złożonej
   wprost na ekranie `/ustawienia/prywatnosc` — wybór odbiorców odrzuca
   każdego, kto dostał list w ciągu ostatnich
   `config('kuking.digest.odstep_dni')` dni;
2. **czyni zadanie odpornym na powtórne uruchomienie tego samego dnia** —
   `withoutOverlapping()` chroni tylko przed dwoma JEDNOCZESNYMI przebiegami,
   nie przed dwoma po kolei;
3. **wyznacza kolejność wysyłki**, bo ta nie mieści się w jednej dobie:
   konto pocztowe ma limit 300 listów dziennie na cały serwis
   (`docs/decyzje/POCZTA.md` §1), więc podsumowania idą partiami przez kilka
   dni, w kolejności `weekly_digest_sent_at ASC NULLS FIRST` — „kto czeka
   najdłużej, ten pierwszy".

```sql
ALTER TABLE users ADD COLUMN weekly_digest_sent_at timestamptz NULL;

CREATE INDEX users_weekly_digest_kolejka_idx
    ON users (weekly_digest_sent_at ASC NULLS FIRST)
    WHERE wants_weekly_digest;
```

Indeks jest **częściowy**, bo jedyne zapytanie czytające tę kolumnę zawsze
zaczyna od `wants_weekly_digest = true`, a takich kont jest mniejszość (zgoda
jest opt-in od migracji wyżej). `NULLS FIRST` w indeksie zgadza się
z `ORDER BY` w `App\Domain\Digest\OdbiorcyDigestu`, żeby Postgres nie musiał
i tak sortować wyniku.

Kolumna **nie jest w `$fillable`** — ten sam powód co `ostatnio_widziany_at`
(AGENTS.md §7). Zapisuje ją wyłącznie
`App\Domain\Digest\OdbiorcyDigestu::zarezerwuj()` (a przy liście próbnym
`--tylko` — `::oznaczWyslane()`). Masowe przypisanie z żądania pozwoliłoby
cofnąć czyjś znacznik i wysłać mu drugi list w tym samym tygodniu, wbrew
obietnicy z ekranu ustawień.

> **Uzupełnienie, 10 września 2026 (audyt QUEUE-01, D-077).** Punkt 2 wyżej
> („czyni zadanie odpornym na powtórne uruchomienie") był prawdą tylko dla
> przebiegu, który **doszedł do końca**. Znacznik stawiało jedno zapytanie po
> całej pętli, więc awaria po zakolejkowaniu listów nie zostawiała po nich
> żadnego śladu i następny przebieg wysyłał je drugi raz. Odporność daje
> teraz bariera w bazie — `weekly_digest_sends`, sekcja niżej — a ta kolumna
> jest od 10 września zapisywana **osobno dla każdej osoby, w jednej
> transakcji z rezerwacją i PRZED wysłaniem listu**. Sama, bez tabeli
> rezerwacji, nadal by nie wystarczyła: jest porównaniem, czyli odczytem
> przed zapisem, a między nimi jest luka na dwa przebiegi równoległe.

**Rollback.** `down()` kasuje kolumnę i indeks. Bezpieczny, ale nie bez
skutku i trzeba to nazwać: razem z kolumną znika pamięć o tym, komu już
wysłano, więc pierwszy przebieg po przywróceniu napisze także do tych,
którzy dostali list wczoraj. Rollback robi się WYŁĄCZNIE razem
z `KUKING_DIGEST_WLACZONY=false`, nie „przy okazji".

Pilnują tego `TygodniowePodsumowanieTest` (odstęp, powtórne uruchomienie,
dobowy limit) i `WypisanieZPodsumowaniaTest`.

**Dowód udzielenia i wycofania zgody NIE JEST w `users`** — ma własną tabelę
`dziennik_zgod` (opisaną niżej w tym dokumencie, D-072). Dwóch kolumn z datami
(`..._consented_at` / `..._withdrawn_at`) świadomie tu nie ma: przy ciągu
włącz → wyłącz → włącz trzecia zmiana nadpisuje pierwszą i historia,
o którą chodzi, ginie.

#### `text_scale` — rozmiar tekstu, od 11 września także W DÓŁ

Kolumna powstała z migracją tworzącą `users`, z `CHECK (text_scale BETWEEN
90 AND 140)`. Migracja `2026_09_11_600000_rozszerz_skale_tekstu_w_dol`
przesuwa dolną granicę na **70**.

```sql
ALTER TABLE users DROP CONSTRAINT users_text_scale_check;
ALTER TABLE users ADD CONSTRAINT users_text_scale_check
    CHECK (text_scale BETWEEN 70 AND 140);
```

**Co to znaczy w pikselach.** Token `--text-body` to `1.125rem × skala`:

| skala | `--text-body` | podpis w ustawieniach |
|---|---|---|
| 70 | 12,6 px | Bardzo mały |
| 80 | 14,4 px | Mały |
| 90 | 16,2 px | Trochę mniejszy |
| **100** | **18,0 px** | **Zwykły — domyślny, bez zmian** |
| 112 | 20,2 px | Trochę większy |
| 125 | 22,5 px | Duży |
| 140 | 25,2 px | Bardzo duży |

**Dlaczego to nie łamie zasady „tekst ≥ 18 px" z `AGENTS.md`.** Ta zasada
opisuje, co człowiek widzi, ZANIM czegokolwiek dotknie — czyli domyślny
wygląd serwisu. Domyślna skala zostaje 100%. Niżej schodzi wyłącznie ten, kto
sam tak ustawi, i tylko na swoim koncie. Ustawienie czytelności działające
w jedną stronę jest ustawieniem połowicznym; zgłosił to właściciel serwisu,
dla którego 18 px jest za duże.

**Trzy miejsca muszą się zgadzać** — `kuking.text.scales`,
`resources/css/tokens.css` i ten CHECK. Rozjechały się już raz: 8 września
konfiguracja oferowała 140, arkusz znał 150, a CHECK nie pozwalał 150
powstać — skutkiem czego „Bardzo duży" zapisywał się na koncie i NIE ROBIŁ
NIC. Pilnuje tego `SkalaTekstuDzialaTest`, od 11 września razem z podpisami
(`kuking.text.scale_labels`).

**Rollback ODMAWIA** (D-088), gdy choć jedno konto ma zapisane mniej niż 90 —
bo zwężenie CHECK-a wymagałoby podniesienia tym kontom skali, czyli zmiany
cudzego świadomego ustawienia bez słowa. Komunikat mówi, ilu kont to dotyczy,
i podaje drogę bez migracji: usunięcie trzech mniejszych rozmiarów z
konfiguracji i z arkusza (nowe konta ich nie zobaczą, stare zachowają swój
wybór). Wymuszenie: `KUKING_ROLLBACK_PODNIES_SKALE_TEKSTU=true`. Sprawdza to
`CofniecieSkaliTekstuOdmawiaTest`, razem z kontrolą dodatnią (na świeżej
bazie cofnięcie ma przejść bez pytania) i z kontrolą wąskości (konto
z rozmiarem 140 nie może zostać przestawione przy okazji).

#### `theme` — jasny/ciemny wygląd (D-019)

**Zgłoszenie właściciela:** telefon sam przełączał stronę w tryb nocny, choć
nikt o to nie prosił — arkusz stylów szedł za `prefers-color-scheme`
systemu, migracja `2026_09_06_210000_add_theme_to_users`.

```sql
ALTER TABLE users ADD COLUMN theme varchar(10) NOT NULL DEFAULT 'light';
ALTER TABLE users ADD CONSTRAINT users_theme_check
    CHECK (theme IN ('light','dark'));
```

- `light` — domyślny dla KAŻDEGO konta, także już istniejącego;
- `dark` — wyłącznie na jawne życzenie, `/ustawienia/czytelnosc` albo
  szybki przełącznik w stopce.

Świadomie tylko dwie wartości, bez trzeciej „jak w systemie" — patrz
uzasadnienie w samej migracji i w `docs/DECISIONS.md` (D-019): dodanie jej
przywróciłoby dokładnie to zachowanie (motyw zmieniający się sam, bez
pytania), które ta kolumna ma wyłączyć.

Gość (bez konta) dostaje ten sam wybór w ciasteczku, nie na koncie —
`App\Http\Controllers\ThemeController`, nazwa ciasteczka w
`config('kuking.theme.cookie')`.

CHECK jest w bazie, nie tylko w PHP — z tego samego powodu co
`posts.display_mode` wyżej w tym dokumencie: walidator da się ominąć nowym
endpointem, `CHECK` nie.

**Rollback:** `php artisan migrate:rollback --step=1`. `down()` zdejmuje CHECK
i kasuje kolumnę; traci się wyłącznie WYBÓR WYGLĄDU. Żadne konto, wpis ani
zdjęcie nie ginie — bezpieczne na produkcji w trakcie awarii.

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

**Rollback ODMAWIA** (D-088, poprawka z 12 września 2026), gdy choć jedno
konto ma zapisany termin końca kary. Do tego dnia stało tu, że rollback jest
bezpieczny, bo „żadne konto nie zmienia statusu i nikt nie traci dostępu" —
prawda o wierszu, nieprawda o człowieku. Zmierzone na prawdziwej bazie cyklem
`migrate:rollback` → `migrate` (czyli tym, co robi `migrate:refresh` w CI
i awaryjny rollback wdrożenia):

```text
PRZED:    status=suspended  status_expires_at=2026-09-19
PO CYKLU: status=suspended  status_expires_at=NULL
```

Kolumna wraca pusta, status zostaje — **zawieszenie na siedem dni zamienia się
w zawieszenie na zawsze**. `punishmentHasExpired()` nie ma czego porównać,
`kuking:zdejmij-wygasle-kary` tych kont nie widzi (pyta o niepusty termin),
a ekran zawieszenia przestaje pisać człowiekowi, kiedy wróci.

Odmowa jest WĄSKA: zawieszenia bezterminowe, bany i konta zdrowe jej nie
wywołują, więc na świeżej bazie i na stagingu rollback przechodzi bez pytania.
Kary, które już minęły, zdejmuje `php artisan kuking:zdejmij-wygasle-kary` —
razem z terminem, więc odmowa znika sama. Świadome wyjście:

```bash
KUKING_ROLLBACK_KASUJ_TERMINY_KAR=true php artisan migrate:rollback
```

Pilnuje tego `CofniecieMigracjiNieRobiKaryBezterminowejTest` (odmowa + dwie
kontrole dodatnie).

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

> ⚠️ **AKAPIT PONIŻEJ BYŁ BŁĘDNY I ZOSTAŁ ZASTĄPIONY** przez migrację
> `2026_09_07_500000_add_erased_status_and_delete_scope_to_users` (D-022).
> Zostaje tu w całości, bo jest to najtańszy zapisany dowód, jak wyglądało
> rozumowanie, które kosztowało dowiezienie połowy D-018 — patrz sekcja
> „`status = 'erased'` i `delete_scope`" niżej.

**~~Świadomie NIE dodajemy nowej wartości do `users_status_check`.~~** Konto
pozostaje `pending_delete` na zawsze — z punktu widzenia logowania i tak nic
się nie zmienia (nie logowało się od zgłoszenia usunięcia). Jedyna nowa
informacja to właśnie ten znacznik.

```sql
-- STAN SPRZED D-022 (nieaktualne):
ALTER TABLE users
ADD CONSTRAINT users_data_erased_at_check
CHECK (data_erased_at IS NULL OR status = 'pending_delete');
```

**Treści (posty, przepisy, komentarze) NIE są kasowane** przez ten mechanizm —
zostają przy już zanonimizowanym koncie, zgodnie z `docs/legal/COMPLIANCE.md`
§2 (dopuszczalne zachowanie treści o wartości społecznej w formie
zanonimizowanej: „autor: konto usunięte"). Kasowane są wyłącznie dane, po
których da się rozpoznać konkretnego człowieka. **Od D-022 zależy to od
`delete_scope`** — człowiek może poprosić o usunięcie także treści.

**Cofnięcie usunięcia** (`App\Domain\Users\Actions\CancelAccountDeletion`,
formularz `AccountDeletionController` — publiczny, bo osoba `pending_delete`
jest wylogowywana natychmiast i nie może się zalogować) jest możliwe TYLKO
dopóki `data_erased_at` jest puste. Po jego ustawieniu e-mail i hasło już nie
istnieją — nie ma czym się zalogować, więc formularz cofnięcia świadomie to
odmawia z wyjaśnieniem, zamiast po cichu wskrzeszać pustą powłokę konta.

Indeks częściowy `users_pending_erase_idx` obejmuje wyłącznie konta
`pending_delete` bez wykonanej jeszcze anonimizacji — dokładnie to, o co pyta
`kuking:usun-wygasle-konta`.

**Rollback ODMAWIA** (D-088, poprawka z 12 września 2026), gdy choć jedno
konto ma wykonane wymazanie. Do tego dnia stało tu, że rollback nie przywraca
e-maila ani hasła, „to nie jest strata spowodowana cofnięciem migracji" —
prawda, i właśnie dlatego reszta była fałszem: dane zostają wymazane, ale
ŚLAD po tym ginie razem z kolumną. Zmierzone na prawdziwej bazie cyklem
`migrate:rollback` → `migrate`:

```text
PRZED:    status=erased          data_erased_at=2026-09-11
PO CYKLU: status=pending_delete  data_erased_at=NULL
```

**Konto, którego dane skasowano bezpowrotnie, wraca do stanu „czeka
w karencji".** `CancelAccountDeletion` odmawia wyłącznie przy
`data_erased_at !== null || isErased()`, więc po cyklu wskrzesi pustą powłokę;
`kuking:usun-wygasle-konta` weźmie je do anonimizacji po raz drugi (pierwsza
kolejka pyta o `whereNull('data_erased_at')`); a migracja
`2026_09_07_500000` przenosi na `erased` tylko konta z niepustym znacznikiem —
więc zanonimizowany tekst tych osób znów zniknie z serwisu (odwrócenie D-022).

Odmowa jest WĄSKA: konta zdrowe i te, które dopiero czekają w karencji, jej
nie wywołują. Furtka istnieje, bo tego znacznika — inaczej niż terminu kary —
nie da się „przeczekać":

```bash
KUKING_ROLLBACK_KASUJ_ZNACZNIKI_WYMAZANIA=true php artisan migrate:rollback
```

Pilnuje tego `CofniecieMigracjiNieZapominaWymazaniaTest` (odmowa + dwie
kontrole dodatnie).

#### `status = 'erased'` i `delete_scope` — stan końcowy konta oraz zakres usunięcia

Migracja `2026_09_07_500000_add_erased_status_and_delete_scope_to_users`
(decyzja D-022, weryfikacja W1).

**Co było zepsute.** Akapit wyżej („nie dodajemy nowej wartości do
`users_status_check`, bo z punktu widzenia logowania nic się nie zmienia")
patrzył wyłącznie na logowanie. Na statusie `pending_delete` stoi jednak także
`User::jestDostepnyJakoAutor()`, sześć Policy i kilka zapytań budujących
listy. Skutek, zmierzony na żywej bazie i w
`tests/Feature/UsunieteKontoTresciZostajaWidoczneTest.php`:

| Co | Przed anonimizacją | Po anonimizacji (przed D-022) |
|---|---|---|
| przepis | 200 | **403** |
| wpis | 200 | **403** |
| profil | 200 | **403** |
| przepis w CUDZYM zeszycie | widoczny | **wypadał z listy** |
| komentarz w cudzym wątku | widoczny | **niewidoczny** |

Czyli D-018 obiecało „tekst zostaje zanonimizowany", a serwis go ukrywał —
na zawsze, bo `pending_delete` nigdy z tego konta nie schodziło.

**Dwa stany, dwie wartości:**

| Status | Co znaczy | Czy treść widać | Czy da się zalogować/odzyskać |
|---|---|---|---|
| `pending_delete` | trwa 30-dniowa karencja | **nie** | nie / **tak** |
| `erased` | karencja wykonana, dane wymazane | **tak** (zanonimizowana) | nie / nie |

```sql
ALTER TABLE users
ADD CONSTRAINT users_status_check
CHECK (status IN ('active','suspended','banned','pending_delete','erased'));

-- RÓWNOWAŻNOŚĆ, nie implikacja: nie da się ani mieć wymazanych danych bez
-- statusu końcowego, ani postawić statusu końcowego bez wymazania danych.
ALTER TABLE users
ADD CONSTRAINT users_data_erased_at_check
CHECK ((data_erased_at IS NOT NULL) = (status = 'erased'));

ALTER TABLE users
ADD CONSTRAINT users_delete_scope_check
CHECK (delete_scope IS NULL OR delete_scope IN ('minimum','everything'));
```

**`delete_scope` — zakres wybiera człowiek (D-022).** Haczyk „Usuń także moje
przepisy, wpisy, komentarze, wykonania i zeszyty" na ekranie „Twoje dane" jest
**domyślnie pusty**:

- `minimum` — znikają zdjęcia (wszystkie, D-018) i dane osobowe, teksty
  zostają zanonimizowane i **widoczne**;
- `everything` — `EraseAccountData::usunTresci()` kasuje na stałe
  (`withTrashed()->forceDelete()`) komentarze, wykonania, wpisy, przepisy
  i zeszyty tej osoby. Kaskady zabierają razem z nimi cudze komentarze
  i cudze wykonania stojące pod tą treścią — i ekran mówi to wprost.

Wybór jest zapisywany **przy zgłoszeniu** (`User::markForDeletion($scope)`),
nie odczytywany przy egzekucji: między jednym a drugim mija 30 dni.
`cancelDeletion()` zeruje kolumnę razem ze statusem.

`NULL` w tej kolumnie znaczy `minimum` — `User::chceUsunacTresci()` porównuje
wprost do `everything`. Świadomie NIE MA CHECK-a wymuszającego wartość przy
statusach usuwania: `NULL` ma już bezpieczne znaczenie („nie kasuj tekstów"),
a jedyną drogą do `pending_delete` w kodzie produkcyjnym jest
`markForDeletion()`, które zakres ustawia zawsze (`status` jest poza
`$fillable`).

**Trzy granice widoczności konta — jedna definicja każdej** (`App\Models\User`):

| Metoda / zakres | Statusy odrzucone | Kto pyta |
|---|---|---|
| `mozeCzytac()` | `banned`, `pending_delete`, `erased` | logowanie, `EnsureAccountIsActive`, powiadomienia |
| `jestDostepnyJakoAutor()` / `scopeDostepnyJakoAutor` | `banned`, `pending_delete` | Policy treści, feed, zeszyty, mapa strony dla treści |
| `jestWidocznyJakoOsoba()` / `scopeWidocznyJakoOsoba` | `banned`, `pending_delete`, `erased` | listy obserwujących i ich liczniki, mapa strony dla profili, analityka, panel „bez odpowiedzi" |

Profil konta `erased` jest **dostępny** (`UserPolicy::viewProfile`), bo to
adres, pod który prowadzi każdy podpis „Użytkownik usunięty". Nie jest za to
nigdzie podpowiadany: wyszukiwarka osób pyta o `status = 'active'`, listy osób
i mapa strony — o `widocznyJakoOsoba()`.

**Migracja danych istniejących:** konta z niepustym `data_erased_at` przechodzą
na `erased` (ich zanonimizowany tekst wraca wtedy na serwis, zgodnie z D-018),
a konta w usuwaniu dostają `delete_scope = 'minimum'` — jedyny zakres, jaki
wtedy istniał.

**Rollback — poprawiony po #287 (D-088).** `down()` cofa `erased` →
`pending_delete`, zdejmuje oba nowe CHECK-i, przywraca poprzedni
`users_data_erased_at_check` i `users_status_check` i kasuje kolumnę —
**ale najpierw ODMAWIA**, jeśli w tabeli jest choć jedno konto z
`delete_scope = 'everything'`.

Powód: kolumna jest `nullable`, więc samo `dropColumn` nie zgłasza błędu —
ale kolejny `migrate` (np. `migrate:refresh` w CI, albo awaryjny rollback
WDROŻENIA, nie tylko bazy) **backfillowałby ją z powrotem jako `minimum`**,
bo to jedyna wartość, jaką backfill `up()` umie nadać istniejącym kontom
w usuwaniu. Człowiek, który poprosił o usunięcie WSZYSTKICH swoich treści,
dostawałby po cichu odwrotność swojej decyzji — bez błędu, z poprawną
kolumną i poprawną wartością ze słownika. **Ta sama choroba co DB2**
(`2026_09_07_400000_default_weekly_digest_to_off`, `down()` przywracający
`DEFAULT true` dla zgody na cotygodniowy przegląd) — potwierdzone na
prawdziwej bazie testowej, nie w teorii (`migrate` → `migrate:rollback` →
`migrate` dawało `delete_scope = 'minimum'` na koncie zgłoszonym jako
`everything`).

Naprawa: `down()` liczy `delete_scope = 'everything'` PRZED jakąkolwiek
operacją i rzuca `RuntimeException` z instrukcją, co zrobić (patrz komentarz
w migracji) — ten sam wzorzec odmowy co
`2026_09_10_400100_one_active_data_export_per_user` i
`2026_09_07_800000_appeals_open_to_reporters`. Na koncie z `minimum` (albo
bez wyboru w ogóle) rollback nadal przechodzi bez pytania — test
`tests/Feature/CofniecieMigracjiNiePodmieniaZakresuUsunieciaTest.php`
sprawdza obie strony na prawdziwym cyklu `migrate` → `markForDeletion()` →
`migrate:rollback`. Skutek udanego rollbacku jest wciąż ZNANY i niezmieniony:
wraca usterka z akapitu wyżej (teksty wymazanych kont znowu oddają 403).

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

#### Indeksy pod listę kont w panelu moderacji

Migracja `2026_09_09_400000_add_moderation_list_indexes_to_users`
(`/admin/uzytkownicy`).

| Indeks | Do czego |
|---|---|
| `users_created_at_idx (created_at DESC)` | domyślna kolejność listy („kto przyszedł ostatnio") i filtr zakresu dat rejestracji |
| `users_email_trgm_idx gin (kuking_normalize(email) gin_trgm_ops)` | szukanie konta po fragmencie adresu e-mail |

**Dlaczego dopiero teraz.** `users` nie było tabelą, po której się CHODZI —
czytało się z niej pojedyncze konto po `id` albo po `lower(email)` przy
logowaniu, a na jedno i drugie indeks jest od pierwszego dnia. Przy dwudziestu
kontach (D-012) sortowanie całej tabeli było bez znaczenia. Założenie
o skali się zmieniło: właściciel zapowiada przejście grupy użytkowniczek
z Garnek.pl, czyli setki, a potem tysiące kont.

**Indeks na WYRAŻENIU, nie na kolumnie** — ten sam powód co przy
`ingredients_name_trgm_idx`: warunek pyta o `kuking_normalize(email)`, więc
indeks na surowym `email` nie zostałby użyty. Pilnuje tego test
`PanelUzytkownicyTest::test_indeksy_listy_kont_sa_uzywalne_dla_swoich_zapytan`,
który pyta o to PLANER (`EXPLAIN` przy `enable_seqscan = off`), a nie samą
obecność indeksu w `pg_indexes`.

**Świadomie BEZ `(status, created_at)`** — zakładki filtra zawężają po
statusie, ale `active` to będzie zdecydowana większość wierszy, więc dla
najczęstszego widoku taki indeks nie daje nic ponad `users_created_at_idx`,
a kosztowałby każdy zapis do `users` (te lecą przy odświeżaniu
`ostatnio_widziany_at`). Wraca, gdy zawieszonych kont będą tysiące.

**Świadomie BEZ generowanej kolumny `email_search`.** Wzorzec `*_search`
(niżej) jest domyślny i słuszny tam, gdzie recheck operatora `%` chodzi po
dziesiątkach tysięcy kandydatów. Tutaj zapytanie robi jeden moderator kilka
razy dziennie, a kolumna oznaczałaby DRUGĄ kopię adresu e-mail w tabeli —
więcej danych osobowych w bazie za oszczędność, której na tym ekranie nie
da się zauważyć. Indeks przechowuje trigramy, nie adres.

**Rollback:** `down()` kasuje oba indeksy. Bezstratny — indeks nie trzyma
danych, których nie ma w tabeli; ekran działa bez nich dalej, tylko wolniej.

#### Wejście kontem Google — powiązanie leży w `tozsamosci_zewnetrzne`

Kolumn `users.google_sub` ani `users.google_connected_at` **nie ma**
i nigdy nie było na produkcji: pierwsza wersja tej migracji je dokładała,
ale została przepisana przed scaleniem (D-098). Powiązania z dostawcami
tożsamości mieszkają w osobnej tabeli — patrz `tozsamosci_zewnetrzne` niżej.

### tozsamosci_zewnetrzne

Migracja `2026_09_10_500000_create_tozsamosci_zewnetrzne_table`
(issue #258, D-069, **D-098**).

Jeden wiersz = „to konto Kuking wchodzi także kontem u TEGO dostawcy,
o TYM identyfikatorze, od TEJ chwili".

- `id` — `bigserial`, klucz główny. Encja nie jest publiczna (nie ma
  własnego adresu i nikt jej nie widzi), więc UUID-a tu nie ma —
  `AGENTS.md` §6 wymaga UUID dla encji **publicznych**;
- `user_id` — `uuid NOT NULL`, klucz obcy na `users` z `ON DELETE CASCADE`;
- `dostawca` — `varchar(20) NOT NULL`, `google` albo `facebook`; listę
  rozszerza MIGRACJA, nie stała w PHP (patrz niżej);
- `identyfikator` — `varchar(255) NOT NULL`, identyfikator konta u dostawcy
  (`sub` z tokenu tożsamości Google, `user_id` z Graph API Facebooka);
- `connected_at` — `timestamptz NOT NULL DEFAULT now()`, od kiedy;
- `dostep_odebrany_at` — `timestamptz NULL` (migracja
  `2026_09_11_700000_dodaj_znacznik_odebrania_dostepu`, issue #259), kiedy
  człowiek odebrał nam dostęp u dostawcy. `NULL` znaczy „powiązanie żywe".

#### `dostep_odebrany_at` — dlaczego znacznik, a nie skasowanie wiersza

Facebook woła `POST /wejdz/facebook/odebranie-dostepu` (pole `Deauthorize
callback URL` w panelu Meta), gdy ktoś usunie naszą aplikację w swoich
ustawieniach Facebooka. Bez tej kolumny nie mieliśmy gdzie tego zapisać:
wiersz zostawał jakby nic się nie stało, a człowiek dowiadywał się dopiero
z nieudanego logowania, którego nikt mu nie tłumaczył.

**Skasowanie wiersza byłoby najgorszą z możliwych reakcji.** Kto wszedł do
Kuking wyłącznie kontem Facebooka i nigdy nie ustawił hasła (w `password`
leży skrót wartości losowej, której nie zna nikt, także my), straciłby
JEDYNĄ drogę wejścia, jaką zna — przez kliknięcie w ustawieniach Facebooka,
którego skutków nikt mu nie zapowiedział. Poprawne dane u nas nie znikają
(`AGENTS.md`).

Znacznik **gaśnie sam**, gdy człowiek znów przejdzie przez ekran zgody
Facebooka (`FacebookLoginController::wpusc()`). Odmowa wejścia komuś, kto
właśnie tę zgodę oddał na nowo, byłaby karą za skorzystanie z własnych
ustawień.

Ekran `Ustawienia → Bezpieczeństwo` ma dzięki temu **trzy** stany, nie dwa:
niepołączone, połączone i **uśpione** („Facebook przestał nas wpuszczać…"),
z działającym przyciskiem prowadzącym na ekran zgody (D-053 — żadnego
martwego przycisku).

**Kolumna jest ogólna, nie „facebookowa"** — odebranie dostępu ma też Google
w panelu swojego konta. Dziś powiadamia nas o tym tylko Facebook, bo tylko
on wysyła `signed_request` na nasz adres.

**Ograniczenie panelu Meta:** pole `Deauthorize callback URL` jest jedno na
aplikację, a jedna aplikacja obsługuje produkcję i staging — **staging tych
powiadomień nie dostanie.** To nie jest usterka do naprawienia w kodzie.

**Rollback ODMAWIA** (D-088), gdy w kolumnie jest choć jedna data: usunięcie
kolumny kasuje informację „ta osoba odebrała nam dostęp", po ponownym
`migrate` kolumna wraca pusta i serwis znów twierdzi, że powiązanie jest
żywe — bez błędu do zauważenia. Wymuszenie:
`KUKING_ROLLBACK_KASUJ_ZNACZNIKI_ODEBRANIA=true`.

**Dlaczego tabela, a nie kolumny na `users`.** D-069 rozstrzygnęło inaczej
(dwie kolumny) i wtedy miało rację: jeden dostawca, a tabela byłaby
budowaniem „na przyszłość", czego zabrania `AGENTS.md` §3. Przyszłość
została w tym czasie **nazwana i zamówiona** — właściciel poprosił wprost
o logowanie kontem Google **oraz** kontem Facebooka. Przy dwóch dostawcach
byłyby cztery kolumny, przy trzecim sześć, a przy każdym z nich osobny
indeks częściowy i osobny CHECK „obie kolumny albo żadna". Tabela zamienia
to na jeden kształt wiersza, a warunek „obie kolumny albo żadna" znika
całkiem: wiersz istnieje albo nie istnieje. D-069 samo wskazało ten kształt
jako właściwy „przy drugim dostawcy".

**Co trzymamy i dlaczego akurat tyle.** `identyfikator` to `sub` z tokenu
tożsamości — **jedyna wartość, którą Google obiecuje jako trwałą**. Adres
e-mail da się u Google zmienić, a w Google Workspace da się nadać adres
osoby, która odeszła z firmy, komuś innemu; `sub` zostaje ten sam przez całe
życie konta. Dlatego kolejne wejścia rozpoznajemy po `sub`, a **adres służy
dokładnie raz** — przy pierwszym połączeniu. `connected_at` odpowiada na
pytanie „od kiedy", którego `audit_log` nie utrzyma (jest sprzątany
z czasem), a które jest cechą konta.

**Czego w tej tabeli nie ma, świadomie:** tokenu dostępu, tokenu
odświeżania (żądanie idzie z `access_type=online`, więc Google go NAM NIE
WYSTAWIA), tokenu tożsamości, zdjęcia z Google (D-061 — zdjęcie z zewnątrz
weszłoby poza naszą moderację), adresu e-mail (mamy go na `users`; dwie
kopie rozjechałyby się przy pierwszej zmianie adresu) i nazwy konta
u dostawcy. Pilnuje tego wprost
`LogowanieKontemGoogleTest::test_w_bazie_nie_ma_gdzie_zapisac_tokenu_google`.

**Co pilnuje BAZA, a nie PHP:**

```sql
ALTER TABLE tozsamosci_zewnetrzne ADD CONSTRAINT tozsamosci_dostawca_check
  CHECK (dostawca IN ('google'));

ALTER TABLE tozsamosci_zewnetrzne ADD CONSTRAINT tozsamosci_identyfikator_check
  CHECK (identyfikator ~ '^\S{1,255}$');

ALTER TABLE tozsamosci_zewnetrzne ADD CONSTRAINT tozsamosci_dostawca_identyfikator_unique
  UNIQUE (dostawca, identyfikator);

ALTER TABLE tozsamosci_zewnetrzne ADD CONSTRAINT tozsamosci_dostawca_konto_unique
  UNIQUE (dostawca, user_id);
```

1. **jedno konto u dostawcy = jedno konto Kuking.** Bez tego dwa nasze konta
   mogłyby wskazywać ten sam `sub`, a „wejdź kontem Google" wybierałoby to,
   które baza akurat poda pierwsze;
2. **jedno konto Kuking nie ma DWÓCH Google'i.** Tego ograniczenia wersja na
   kolumnach nie potrzebowała (kolumna jest jedna) i właśnie dlatego trzeba
   je było napisać wprost: bez niego „połącz" wołane dwa razy dokładałoby
   drugi wiersz;
3. **zamknięta lista dostawców** — literówka („googel") nie ma prawa cicho
   założyć nowego rodzaju powiązania. Listę rozszerza MIGRACJA, czyli
   decyzja widoczna w przeglądzie kodu. **Facebooka na tej liście NIE MA
   i to jest celowe** — wchodzi razem ze swoim kodem, bo warunki wejścia są
   u niego inne (nie oddaje `email_verified`, patrz D-098);
4. **kształt identyfikatora** — niepusty, bez znaków białych, do 255 znaków
   (OpenID Connect Core §2). Nie zawężamy do samych cyfr, choć dziś Google
   nadaje wartości 21-cyfrowe: zawężenie do dzisiejszego kształtu CUDZEGO
   identyfikatora zamknęłoby logowanie w dniu, w którym Google go zmieni,
   i nie chroniłoby przed niczym;
5. **`ON DELETE CASCADE`** — powiązanie nie ma sensu bez konta. Kont
   w Kuking się jednak **nie kasuje, tylko anonimizuje** (D-022), więc
   kaskada nie jest drogą, którą powiązanie znika w praktyce: robi to jawnie
   `EraseAccountData` (`$fresh->tozsamosciZewnetrzne()->delete()`, razem
   z nadpisaniem hasła). Kaskada jest siatką na wypadek realnego `DELETE`
   (`migrate:fresh`, sprzątanie danych zasianych, przyszłe twarde usunięcie):
   wiersz-sierota trzymałby identyfikator konta Google wskazujący w pustkę
   i **blokowałby** ponowne połączenie tego konta Google z czymkolwiek.

**`TozsamoscZewnetrzna` ma PUSTE `$fillable`** (`AGENTS.md` §7, ta sama
zasada co `status`, `role` i `email` na `users`). Wiersz w tej tabeli JEST
drogą wejścia na konto: kto go założy, wchodzi jednym kliknięciem. Wiersze
powstają wyłącznie przez `User::connectGoogle()`, a ta metoda jest wołana
pod blokadą wiersza konta (`ZamekKonta`, D-079), żeby o dostępie nie
rozstrzygał stan sprzed sprawdzenia warunków.

**ROLLBACK — ODMAWIA, gdy komuś zabrałby wejście na konto.** Konto założone
drogą Google **nigdy nie miało hasła** (w `password` leży skrót wartości
losowej, której nie zna nikt), więc `DROP TABLE` zabiera tej osobie jedyną
drogę wejścia, jaką zna — i robi to nieodwracalnie, bo razem z tabelą znikają
identyfikatory. `down()` sprawdza więc, czy jest choć jeden wiersz, i odmawia,
mówiąc, ilu kont to dotyczy i co zrobić zamiast tego:

```bash
# WYCOFANIE FUNKCJI BEZ MIGRACJI (to jest właściwa droga):
KUKING_WEJSCIE_GOOGLE=false   # + restart serwisu

# JEŚLI NAPRAWDĘ trzeba skasować powiązania — powiedz to wprost:
KUKING_ROLLBACK_KASUJ_TOZSAMOSCI_ZEWNETRZNE=true php artisan migrate:rollback --step=1
```

Kolejność przy wycofywaniu kodu i migracji razem: **NAJPIERW KOD, POTEM
MIGRACJA** — inaczej trasy `/wejdz/google` odwołują się do nieistniejącej
tabeli. Sprawdza to `CofniecieMigracjiGoogleOdmawiaTest` (obie strony:
odmowa przy powiązanych kontach ORAZ przejście na świeżym środowisku, bo
migracja, która nie cofa się nigdy, jest równie zła).

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
- `display_name_search`, `username_search`, `speciality_search` — patrz
  „Kolumny `*_search`".

Wszystkie cztery pola opisowe (`display_name`, `bio`, `region`,
`speciality`) idą do anonimizacji przy wykonaniu żądania z art. 17 RODO —
patrz `data_erased_at` wyżej.

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

**Unikalność bez rozróżniania wielkości liter.** Unikalny indeks funkcyjny
`users_email_lower_unique` na `lower(email)` (migracja
`2026_09_06_120000_add_email_case_insensitive_unique_index`, issue #109).

```sql
CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email));
```

Mutator wyżej to warstwa PHP i obowiązuje **tylko** zapisom przez Eloquenta.
`DB::table('users')->insert()`, seeder, przyszły import i ręczna naprawa danych
w `psql` podczas incydentu omijają go w całości, a `UNIQUE (email)` porównuje
bajty, więc `Jan@example.com` wchodziło obok `jan@example.com`. Przy dwóch
takich kontach `User::findByLogin()` trafia raz na jedno, raz na drugie:
człowiek loguje się i widzi „zniknięte" przepisy, a link do zmiany hasła
dotyczy tylko jednego z kont — bez sposobu, żeby zgadnąć którego.
AGENTS.md §6: walidacja w PHP jest dodatkiem, nie zamiennikiem.

Indeks jest **funkcyjny**, a nie `citext` na kolumnie: `citext` zmieniłby
zachowanie każdego porównania w kodzie, także tam, gdzie nikt się tego nie
spodziewa. Indeks robi jedną rzecz — pilnuje, kto może zająć adres — i jest
tym samym rozwiązaniem co `profiles_username_lower_unique`.

Stary `users_email_unique` **zostaje**. Nowy jest od niego silniejszy, ale to
stary obsługuje zwykłe `where('email', ?)` z `findByLogin()`; indeks funkcyjny
takiego zapytania nie obsłuży.

Migracja **nie scala ani nie kasuje kont**. Gdyby w bazie były już dwa adresy
różniące się tylko wielkością liter, zgłasza je po nazwie i przerywa — decyzję,
które konto zostaje, podejmuje człowiek. Rollback to `DROP INDEX IF EXISTS`,
bez utraty danych; `users_email_unique` zostaje nietknięty przez cały czas,
więc nawet w trakcie rollbacku adres nie zduplikuje się co do znaku.

#### `email` i `email_verified_at` poza `$fillable` (issue #195)

Ta sama reguła co przy `status` i `role` (AGENTS.md §7): adres e-mail to jedyna
droga odzyskania konta, więc jego zmiana jest zmianą **stanu konta**, nie
edycją profilu. Gdyby stał w `$fillable`, dowolny `update($request->all())` —
także taki, który o adresie w ogóle nie myśli — potrafiłby przestawić konto na
cudzą skrzynkę, a stamtąd wystarczy „nie pamiętam hasła".

Adres zapisują wyłącznie dwie nazwane drogi: `User::assignEmail()`
(rejestracja i potwierdzona zmiana) oraz `EraseAccountData` (anonimizacja,
D-022). Sama zmiana adresu na koncie idzie przez
`pending_email_changes` — patrz niżej. Pilnuje tego
`AdresEmailPozaMasowymPrzypisaniemTest`.

#### `is_seeded` — treść zalążkowa na produkcji, ale jawnie oznaczona (D-025)

Migracja `2026_09_07_700000_add_is_seeded_to_users`. Boolean, domyślnie
`false`, bez backfillu (żadne wcześniejsze konto nie pochodzi z pliku).

**Ten sam kształt co `tags.is_seeded`** (migracja
`2026_09_07_100000_create_tags_tables`), i z tego samego powodu: **atrybut
POCHODZENIA danych, nie nowy system widoczności obok `status`/`role`.**
Konto z `is_seeded = true` przechodzi przez dokładnie te same bramki co
każde inne — `status` rozstrzyga, czy może czytać/pisać, `role`, czy
moderuje. Ta kolumna nic w tych bramkach nie zmienia; dokłada tylko dwie
rzeczy, obie **czytające** kolumnę, żadna jej nie interpretująca jako nowy
poziom uprawnień:

1. **Etykietę w interfejsie**, wszędzie tam, gdzie serwis pokazuje AUTORA —
   profil, karta wpisu, karta przepisu, komentarz (komponent
   `x-konto-przykladowe`, czytany przez `User::isSeeded()`). D-025 wprost:
   „przy koncie, nie tylko w regulaminie — nikt nie czyta regulaminu, żeby
   dowiedzieć się, czy pisze do człowieka".
2. **Wykluczenie z Weekly Active Cooks i z kohorty retencji**
   (`App\Domain\Analytics\CookEligibility::excludedUserIds()`) — dwanaście
   person publikuje z definicji plikowej i nie ma zasilać liczby, która ma
   mierzyć żywą społeczność (issue #114, ten sam powód, dla którego tamta
   klasa już wyklucza gospodarza i konta testowe).

**Dlaczego na `users`, nie na `profiles`.** Wszystkie cztery miejsca z punktu
1 i tak już ładują `User` (`$post->author`, `$recipe->author`,
`$comment->author`), a `is_seeded` jest faktem o KONCIE (kto może się nim
posługiwać), nie o publicznej twarzy — ten sam podział, jaki `profiles` już
ma wobec `users` gdzie indziej w tym pliku.

**Świadomie poza `User::$fillable`**, tym samym powodem co `status`/`role`
(komentarz przy `$fillable` w `User`, AGENTS.md §7): jedyne miejsce, które to
ustawia, to `Database\Seeders\TrescZalazkowaSeeder`, wprost przez
`DB::table('users')->insert()` — ten sam wzorzec zapisu, którego używa
`TagSeeder::utworzBrakujaceTagi()` dla `tags.is_seeded`.

**Skąd te konta.** `database/seeders/dane/tresc-zalazkowa.json` — dwanaście
person, czterdzieści przepisów, osiemdziesiąt wpisów, sześćdziesiąt
komentarzy (D-025, `docs/DECISIONS.md`). Seeder jest idempotentny: drugie
uruchomienie na tej samej bazie nic nie zmienia (dopasowanie po
`lower(username)` dla kont, po `(author_id, title)`/`(author_id, body)` dla
treści) i nigdy nie dotyka konta, którego ta sama nazwa użytkownika należy
już do prawdziwego człowieka — wtedy import tego jednego konta jest
pomijany i zgłaszany w raporcie, dokładnie jak `TagSeeder` przy kolizji
z tagiem utworzonym ręcznie.

**Co ta kolumna świadomie NIE rozstrzyga** — i D-025 zostawia to wprost
otwarte: co się stanie z tymi dwunastoma kontami, gdy do serwisu dołączą
prawdziwi ludzie. Zostawienie ich na zawsze zamienia etykietę w stały
element serwisu; usunięcie kont zabrałoby treść, do której realni
użytkownicy mogli już coś dopisać (komentarz, „Ugotowałem"). Decyzja
właściciela, do podjęcia przed otwarciem rejestracji.

**Rollback:** `down()` zdejmuje kolumnę. Nic poza etykietą w interfejsie
i wykluczeniem z WAC nie czyta `is_seeded`, więc rollback nie kasuje żadnego
wiersza `users`/`posts`/`recipes`/`comments` — dwanaście kont z pliku staje
się po prostu nie do odróżnienia od kont zwykłych, a ich treść (i wpływ na
WAC) wraca do tego, jak wygląda dla każdego innego konta. To jest znany,
opisany skutek, nie utrata danych — ale też dokładnie powód, dla którego
rollback tej migracji na produkcji wymaga tej samej decyzji właściciela
co akapit wyżej: bez etykiety te konta stają się nieodróżnialne od ludzi.

#### `ostatnio_widziany_at` — znacznik ostatniej wizyty (bramka V1, issue #114/#115)

Migracja `2026_09_08_200000_add_last_seen_to_users_table`. `timestampTz`,
nullable, bez wartości domyślnej, z osobnym B-tree indeksem
(`users_ostatnio_widziany_idx`).

**Po co.** `docs/ROADMAP.md` kończy bramkę V1 zdaniem „Planner/groups/forks
dopiero gdy WAC i D30 pokazują powroty" — a przed tą kolumną nic w bazie nie
mówiło, kiedy ktokolwiek ostatnio był w serwisie, więc D7/D30 nie dały się
policzyć wcale. `php artisan kuking:raport` czyta tę kolumnę przez
`App\Domain\Analytics\AktywniWTygodniu` i `App\Domain\Analytics
\PowrotPoDniach`.

**Kolumna, nie trzeci sygnał w `product_signals` — rozstrzygnięcie, nie
domysł.** Pełne uzasadnienie stoi w komentarzu samej migracji; w skrócie:
retencja `product_signals` (90 dni) NIE jest tym, co przesądza — jest dłuższa
niż 30 dni, więc D30 dałoby się policzyć nawet z sygnałów. Przesądza kształt
tabeli: `product_signals_signal_name_check` zamyka `signal_name` na dwa
rzadkie zdarzenia techniczne i tabela ma tak zostać (migracja
`2026_09_06_220000_create_product_signals_table`, kontrastowana tam wprost
z generalnym serwisem `product_events`, którego to repozytorium nie buduje —
AGENTS.md §3). Log odwiedzin na KAŻDE żądanie każdego konta zmieniłby ten
charakter i rósłby bez końca, wymagając własnej retencji; nadpisywana
kolumna na `users` nie rośnie w ogóle — jeden wiersz na konto, zawsze.

**Zapis throttlowany, nie przy każdym żądaniu.** `App\Http\Middleware
\AktualizujOstatniaWizyte` (globalny, w grupie `web`, w `bootstrap/app.php`,
zaraz PO `EnsureAccountIsActive`) woła `App\Domain\Analytics
\ZanotujOstatniaWizyte`, która zapisuje co najwyżej raz na
`config('kuking.analytics.last_seen_throttle_minutes')` minut (domyślnie 15)
na osobę — próg i uzasadnienie liczby stoją w `config/kuking.php`. Zapis idzie
przez `DB::table('users')->update()`, nie przez `$user->save()`: kolumna jest
świadomie POZA `User::$fillable` (ten sam powód co `status`/`role` — nikt nie
ma ustawić jej masowym przypisaniem z żądania) i zwykły zapis Eloquenta
dotknąłby też `updated_at`, fałszując „kiedy dane konta naprawdę się
zmieniły".

**`NULL` znaczy „nigdy niewidziany od czasu wdrożenia kolumny"**, nie „przed
chwilą" ani „bardzo dawno" — `AktywniWTygodniu`/`PowrotPoDniach` traktują
`NULL` jako „nie liczy się do aktywnych/do powrotu", nigdy jako zero.

**Dana osobowa — w eksporcie RODO.** `App\Domain\Users\Exports
\CollectUserExportData` przepisuje ją do paczki jako `konto.ostatnio_widziany`
z tego samego powodu co `usuniecie_konta_zgloszone` obok niej.

**Zerowana przy anonimizacji konta.** `App\Domain\Users\Actions
\EraseAccountData::handle()` ustawia tę kolumnę na `NULL` w tym samym
`forceFill()`, który anonimizuje e-mail i hasło — `resources/legal
/polityka-prywatnosci.md` (sekcja 2) obiecuje wprost, że ten znacznik znika
wraz z usunięciem/anonimizacją konta, więc kod musi to robić, nie tylko
dokument to twierdzić (`tests/Feature/AccountDeletionPurgeTest.php` pilnuje
tego assercją).

**Rollback:** bezpieczny bez zastrzeżeń. Kolumna jest WYŁĄCZNIE czytana przez
raport — żadna reguła autoryzacji, limitu ani widoczności jej nie używa.
`down()` kasuje indeks i kolumnę; jedyny skutek to utrata najnowszego
znacznika dla każdego konta, a middleware odbuduje go od nowa przy
najbliższej wizycie.

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

### media
Tylko metadata, nie binary:
- `owner_id uuid NOT NULL` → `users` (`ON DELETE CASCADE`) — właściciel
  pliku. To on, a nie wpis czy przepis, decyduje o dostępie do zdjęcia
  (`DostepDoZdjecia`);
- `disk` (ORYGINAŁ — patrz niżej);
- `variants_disk` (PUBLICZNE WARIANTY — patrz niżej);
- **`media.object_key varchar(700) UNIQUE`** — ścieżka pliku w buckecie.
  **Generujemy ją sami**; nazwa pliku od człowieka nigdy do niej nie trafia
  (`StoreUploadedImage`) — to zamyka drogę do path traversal i do plików
  udających skrypty. `UNIQUE`, bo dwa wiersze wskazujące ten sam obiekt
  znaczyłyby, że skasowanie jednego zdjęcia zabiera plik drugiemu;
- MIME;
- bytes;
- `width integer NULL` i `height integer NULL` — wymiary **oryginału**
  w pikselach, odczytane z ZAWARTOŚCI pliku przez `getimagesize()`
  w `StoreUploadedImage`, tak samo jak `mime_type`. Wymiary poszczególnych
  wariantów (`thumb`, `feed`, `large`) leżą osobno, w `metadata.variants` —
  `Media::width()` bierze najpierw wariant, a do tych dwóch kolumn schodzi
  dopiero, gdy wariantu nie ma. `NULL` to wiersz z fabryki albo z seedera;
- status;
- checksum;
- metadata.

Dwie z tych kolumn nie mówią o sobie samą nazwą:

- **`mime_type varchar(120) NULL`** — typ pliku **odczytany z jego zawartości**
  przez `getimagesize()` w `StoreUploadedImage`, a NIE nagłówek `Content-Type`
  przysłany przez przeglądarkę: tamten deklaruje nadawca, a plik udający
  obrazek deklaruje cokolwiek. Wpisywany razem z wierszem, więc `NULL` na
  produkcji się nie zdarza — kolumna dopuszcza go dla wierszy z fabryk
  i seederów.
- **`alt_text varchar(500) NULL`** — opis alternatywny, **wolny tekst od
  człowieka**. Dla dostępności bezcenny, ale **nigdy wymagany**: wymóg opisu
  zabiłby publikację „zdjęcie + kilka słów", czyli główną akcję serwisu.
  `NULL` i pusty opis są stanem normalnym, a nie brakiem do uzupełnienia.

**Kolumny `media.perceptual_hash` JUŻ NIE MA** (migracja
`2026_09_12_100000_usun_martwa_kolumne_perceptual_hash`). Było to miejsce na
skrót percepcyjny obrazu — wartość rozpoznającą to samo zdjęcie mimo innej
kompresji, przydatną moderacji przy zdjęciu wstawianym ponownie po decyzji.
Coś innego niż `checksum_sha256`, który jest dokładnym skrótem bajtów i łapie
wyłącznie identyczny plik.

Kolumna stała w schemacie od pierwszej migracji mediów i **przez cały ten czas
nic jej nie wypełniało ani nie czytało**: jedynym wystąpieniem w kodzie był
`$fillable` modelu `Media`, a każdy wiersz miał `NULL`. Pusta kolumna nie jest
darmowa — czytający schemat widzi pole, które wygląda na działający mechanizm
wykrywania duplikatów, i planuje na nim pracę (tak stało się dwa razy
w `docs/legal/MODERATION_PLAYBOOK.md`). Usunięcie jest wykonaniem zauważenia
z D-166.

**Jeśli wykrywanie duplikatów zdjęć kiedyś powstanie**, kolumna wróci razem
z kodem, który ją liczy — a nie przed nim. Skrót percepcyjny jest wartością
WYLICZANĄ z pliku, więc odtworzenie go dla istniejących zdjęć jest przeliczeniem,
nie odzyskiwaniem utraconych danych.

**Dwie kolumny dysku, bo to dwie różne kategorie danych** (migracja
`2026_09_06_170000_add_variants_disk_to_media`, audyt G-01).

```sql
ALTER TABLE media ADD COLUMN variants_disk varchar(40);   -- NULL = tam, gdzie oryginał
```

`disk` mówi, gdzie leży ORYGINAŁ — plik dokładnie taki, jaki przyszedł od
człowieka, z pełnym EXIF-em, czyli ze współrzędnymi GPS kuchni. `variants_disk`
mówi, gdzie leżą PRZETWORZONE warianty WebP, z których re-enkodowanie zdjęło
metadane.

Do tej pory obie rzeczy leżały w jednym buckecie R2, a prywatność oryginału
opierała się na zapisaniu go jako „private" pod prefiksem `incoming/`.
**Na R2 to nie działa:** Cloudflare nie implementuje S3-owych ACL na obiektach
(`x-amz-acl` jest oznaczony jako nieobsługiwany dla `PutObject`), a publiczność
jest cechą BUCKETU — własnej domeny albo `r2.dev`. Bucket wystawiony pod
`cdn.kuking.pl` wystawiał więc też `incoming/`. Adres oryginału dawał się przy
tym wyprowadzić z publicznego adresu wariantu:

```text
media/{uuid_wlasciciela}/{rok}/{mc}/{uuid}_feed.webp    ← publiczny, znany
incoming/{uuid_wlasciciela}/{rok}/{mc}/{uuid}.jpg       ← oryginał
```

**`NULL` znaczy „tam, gdzie oryginał"** i tak ma każdy wiersz sprzed tej
migracji — bo tam te warianty naprawdę leżą. Kolumny NIE backfillujemy:
wpisanie nazwy nowego dysku byłoby stwierdzeniem nieprawdy o położeniu plików,
a `KasujZdjecie` szukałoby ich w niewłaściwym buckecie i zostawiało publiczne
kopie na zawsze — także po wymazaniu konta. Przeniesienie starych wariantów to
osobna praca: kopiowanie obiektów plus aktualizacja tej kolumny po każdym
udanym kopiowaniu.

Rollback: `DROP COLUMN`, bezstratnie — wiedza wraca do „ten sam dysk co
oryginał", czyli do stanu sprzed rozdzielenia. **Cofać przed migracją danych,
nie po:** po przeniesieniu wariantów ta kolumna niesie już prawdziwą wiedzę
i jej utrata znaczy, że aplikacja szuka ich w starym buckecie.

#### `status = 'deleted'` — kasowanie TRWA, a wiersz jest uchwytem do ponowienia

Wprowadzone przez **D-083** (issue #285, MEDIA-01). **Bez migracji i bez
zmiany schematu:** wartość `deleted` dopuszcza `media_status_check` od
pierwszej migracji tabeli (`2026_09_05_000100_create_media_table`) — do tej
pory po prostu nikt jej nie zapisywał.

```sql
-- stan NIEZMIENIONY, cytowany tu tylko po to, żeby nie trzeba było
-- otwierać migracji, żeby sprawdzić, czy ta wartość jest legalna:
ALTER TABLE media ADD CONSTRAINT media_status_check
    CHECK (status IN ('pending','processing','ready','rejected','deleted'));
```

Znaczenie: **zdjęcie zostało przejęte do skasowania, ale jeszcze nie zniknęło
z dysku.** To nie jest „skasowane" — to jest „kasowanie trwa".

- Znacznik ustawia `KasujZdjecie::przejmij()` w krótkiej transakcji, pod
  `SELECT … FOR UPDATE` na tym wierszu i po ponownym sprawdzeniu, że nic go
  nie używa. Pliki kasują się dopiero **po** commicie tej transakcji.
- Dopóki znacznik stoi, `App\Domain\Media\ZdjeciaDoPrzypiecia` nie pozwoli
  przypiąć tego zdjęcia do wpisu ani do wykonania. Bez tego okno na utratę
  pliku wracałoby zaraz po zwolnieniu blokady, a przed skasowaniem plików.
- Nieudane kasowanie plików **zostawia wiersz ze znacznikiem** — i to jest
  cały mechanizm ponowienia, ten sam co przy issue #17: kolejny przebieg
  `kuking:sprzataj-osierocone-zdjecia` wybiera go po wieku tak samo jak każdy
  inny wiersz. Wiersz bez plików da się zauważyć; pliki bez wiersza są dla
  aplikacji niewidoczne na zawsze.

To jest odpowiednik `users.data_erased_at` z `EraseAccountData`: zatwierdzona
deklaracja „to odchodzi", widoczna dla innych transakcji.

**Rollback:** nie ma czego cofać w schemacie — CHECK się nie zmienił, kolumny
nie przybyło. Cofnięcie SAMEJ ZMIANY KODU (revert PR-a #285) jest bezpieczne
dla danych, ale wymaga jednego ruchu operacyjnego: wiersze, które zostały
z `status = 'deleted'` po nieudanym kasowaniu plików, przestaną cokolwiek
znaczyć dla starego kodu i będą wyglądać jak zwykłe osierocone zdjęcia —
stary sprzątacz podejmie je normalnie, po wieku, więc nie zablokują się
w bazie. Nic nie trzeba backfillować.

### posts + post_media
Najprostszy content społecznościowy.

**`posts.recipe_id` — wpis WSKAZUJĄCY przepis** (issue #368). Kolumna istnieje
od pierwszej migracji (`2026_09_05_000500_create_posts_tables`, `nullable`,
`nullOnDelete`) i **nie zmienia się tą pracą ani o jeden bajt** — zmienia się
to, kto ją wypełnia i co z niej wynika. Nie ma tu migracji, bo nie ma zmiany
schematu.

Od issue #368 publikacja przepisu tworzy dokładnie JEDEN wiersz `posts`
z `recipe_id` wskazującym przepis, `body = null` i bez ani jednego wiersza
w `post_media` (`App\Domain\Recipes\WpisWskazujacyPrzepis`). Bez tego
opublikowany przepis nie trafiał do żadnego strumienia — wszystkie trzy pytają
wyłącznie o `posts`.

**Ten wiersz niczego z przepisu nie kopiuje.** Tytuł, zdjęcie i widoczność
karta i zapytania biorą z relacji, a nie z kolumn wpisu:

- tytuł i zdjęcie — `resources/views/components/post-card.blade.php`
  z `$post->recipe` i `$post->recipe->heroMedia`;
- widoczność — `Post::scopeZWidocznymPrzepisem()`, czyli
  `Recipe::scopeWidoczneDla()` na wskazywanym przepisie.

Dlatego usunięcie przepisu (także miękkie), ukrycie go przez moderację,
zawężenie widoczności i zmiana tytułu **nie wymagają ani jednego zapisu
na `posts`**. Wiersz zostaje w bazie nietknięty i po prostu przestaje
wychodzić ze strumieni. Kopiowanie tych czterech rzeczy na wpis dałoby cztery
niezależne miejsca do rozjechania się.

`posts.visibility` takiego wiersza to zawsze `'public'` i **nie jest to kopia
widoczności przepisu**, tylko brak własnego zawężenia: wpis nie niesie treści,
której miałby strzec.

**Brak `UNIQUE (recipe_id)` jest świadomy.** Jeden wpis na przepis pilnuje
bramka w transakcji publikacji, idąca po blokadzie wiersza `recipes`. Twardy
indeks unikalny zabroniłby czegoś, co jest dozwolone i pożądane osobno: wpisu
„ugotowałem z tego przepisu", który TEŻ niesie `recipe_id` i ma własne zdjęcie
(patrz `cooked_events` i `PublishPost`). Ograniczenie w bazie musiałoby
odróżniać te dwa rodzaje wierszy, a do tego potrzebna byłaby kolumna, której
świadomie nie dodajemy.

**Przepisy opublikowane przed tą zmianą** uzupełnia komenda
`kuking:dopisz-wpisy-przepisow` (idempotentna, z `--na-sucho`) — nie migracja,
bo to zmiana danych, nie schematu.

**Rollback:** brak migracji do cofnięcia. Wycofanie zachowania to usunięcie
wywołania `WpisWskazujacyPrzepis::dopisz()` z `PublishRecipe`; wiersze, które
już powstały, kasuje się wtedy ręcznie
(`delete from posts where recipe_id is not null and body is null` — z uwagą, że
wpisy „ugotowałem" mają `body` albo zdjęcia, a te są cudzą treścią i zostają).

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


**`klucz_wyslania` — jedno wysłanie formularza to jeden wiersz** (D-027,
migracja `2026_09_07_900200_add_klucz_wyslania_to_posts`).

```sql
ALTER TABLE posts ADD COLUMN klucz_wyslania uuid NULL;
CREATE UNIQUE INDEX posts_one_per_klucz_wyslania
    ON posts (author_id, klucz_wyslania)
    WHERE klucz_wyslania IS NOT NULL;
```

Klucz jest w indeksie razem z `author_id`, nie sam: klucz wygenerowany
w cudzej przeglądarce nie ma prawa wskazywać na wpis innej osoby, a przy
kolizji kontroler odsyła człowieka do **jego** pierwszego wpisu. Bez
`author_id` byłoby to odesłanie pod cudzy adres.

**Kolumna jest `NULL`-owalna i nie ma backfillu.** Wiersze sprzed tej
migracji, wiersze z seederów i wiersze z fabryk mają `NULL` i indeks ich nie
obejmuje — w PostgreSQL indeks częściowy z `WHERE klucz_wyslania IS NOT NULL`
mówi to wprost, zamiast liczyć na to, że czytelnik pamięta, iż zwykły UNIQUE
przepuszcza dowolnie wiele `NULL`-i. `NOT NULL` rozwaliłoby `database/seeders/`
i każdy test tworzący wiersz fabryką.

**Wyłącznik:** `kuking.formularze.klucz_wyslania_wlaczony` (`false` →
formularz nie renderuje ukrytego pola, kolumna dostaje `NULL`, indeks
przestaje cokolwiek odbijać). To jedyna droga wycofania bez wdrażania
migracji — dlatego jest w konfiguracji.

**Rollback:** `DROP INDEX IF EXISTS posts_one_per_klucz_wyslania`, potem `DROP COLUMN
klucz_wyslania`. Bezstratnie i dlatego `down()` niczego nie odmawia: kolumna
niesie wyłącznie identyfikator wysłania wygenerowany przez serwer, ani jednego
słowa napisanego przez człowieka.

### recipes
Aktualny stan przepisu; wersje historyczne leżą w `recipe_versions`.

- `id`, `author_id`, `klucz_wyslania` (patrz niżej), `title`, `slug`
  (`UNIQUE`, 220 znaków);
- `summary` — patrz niżej;
- `servings`, `prep_minutes`, `cook_minutes`, `difficulty`
  (CHECK: `easy` \| `medium` \| `hard`);
- `visibility` (`public` \| `followers` \| `private`),
  `status` (`draft` \| `published` \| `hidden` \| `removed`), `hero_media_id`;
- pochodzenie: `source_type`, `source_url`, `source_person`, `source_note`,
  `family_since_year`, `source_scan_media_id` — patrz niżej;
- `published_at`, `created_at`, `updated_at`, `deleted_at` (soft delete);
- `title_search`, `summary_search` — patrz „Kolumny `*_search`".

**`klucz_wyslania` — jedno wysłanie formularza to jeden przepis** (D-027,
migracja `2026_09_12_600000_add_klucz_wyslania_to_recipes`).

```sql
ALTER TABLE recipes ADD COLUMN klucz_wyslania uuid NULL;
CREATE UNIQUE INDEX recipes_one_per_klucz_wyslania
    ON recipes (author_id, klucz_wyslania)
    WHERE klucz_wyslania IS NOT NULL;
```

Zmierzone przed tą migracją (audyt podwójnego wysłania, 12 września 2026):
dwa razy `POST /dodaj/przepis` z identycznym ciałem dawały **dwa** wiersze
w `recipes`, drugi pod adresem z doklejoną dwójką (`…-2`), razem z drugim
kompletem składników i kroków, drugą wersją w `recipe_versions`, drugim
wpisem `recipe.published` w dzienniku audytowym i drugim wpisem w strumieniu
obserwujących. Po migracji: **jeden** wiersz, a drugie kliknięcie odsyła pod
ten sam adres.

Klucz jest w indeksie razem z `author_id`, nie sam — dokładnie jak w `posts`
i `cooked_events`: klucz wygenerowany w cudzej przeglądarce nie ma prawa
wskazywać na przepis innej osoby.

**Kolumnę wypełnia wyłącznie ZAŁOŻENIE przepisu.** `recipes.update` nie
przysyła klucza i go nie nadpisuje — inaczej pierwsze dopisanie szczegółów
zdejmowałoby ochronę po cichu.

**Kolumna jest `NULL`-owalna i nie ma backfillu.** Przepisy sprzed tej
migracji, z seederów i z fabryk mają `NULL`, a indeks częściowy
(`WHERE klucz_wyslania IS NOT NULL`) ich nie obejmuje.

**Wyłącznik:** `kuking.formularze.klucz_wyslania_wlaczony` (`false` →
formularz nie renderuje ukrytego pola, kolumna dostaje `NULL`, indeks
przestaje cokolwiek odbijać).

**Rollback:** `DROP INDEX IF EXISTS recipes_one_per_klucz_wyslania`, potem
`DROP COLUMN klucz_wyslania`. Bezstratnie i dlatego `down()` niczego nie
odmawia (D-088 dotyczy wartości semantycznych): kolumna niesie wyłącznie
identyfikator wysłania wygenerowany przez serwer, ani jednego słowa
napisanego przez człowieka i ani jednej decyzji, którą ktoś podjął.

**`summary varchar(2000) NULL`** — „Krótko o przepisie", zdanie albo dwa nad
składnikami. Idzie też do `<meta name="description">` (przycięte do 155 znaków)
i do `description` w JSON-LD, więc jest tekstem, który człowiek zobaczy
w wynikach wyszukiwania. `NULL` jest stanem normalnym — przepis bez opisu
publikuje się tak samo.

#### Pochodzenie przepisu: `source_type`, `source_person`, `source_note`, `source_url`

Cztery kolumny z pierwszej migracji przepisów
(`2026_09_05_000400_create_recipes_tables`). To nie jest metadana — „skąd znam
ten przepis" odróżnia Kuking od bazy receptur, a przy prawach autorskich jest
deklaracją pochodzenia (`docs/MODERATION.md`).

| Kolumna | Typ | Co w niej naprawdę jest |
|---|---|---|
| `source_type` | `varchar(20) NOT NULL DEFAULT 'own'` | Zamknięta lista, CHECK `recipes_source_type_check`: `own` \| `family` \| `adaptation` \| `external`. Etykiety dla człowieka trzyma `Recipe::SOURCE_LABELS`. |
| `source_person` | `varchar(120) NULL` | **Wolny tekst od człowieka.** Patrz niżej — to nie jest osoba. |
| `source_note` | `varchar(2000) NULL` | Historia przepisu, wspomnienie. Pokazywane pod nagłówkiem „Skąd ten przepis", PRZED składnikami, z zachowaniem łamań wierszy (`whitespace-pre-line`). |
| `source_url` | `text NULL` | Adres strony, z której przepis pochodzi. Widok pokazuje go **tylko przy `source_type = 'external'`**, jako `rel="nofollow noopener"`. W bazie bez limitu długości; formularz przyjmuje najwyżej 2000 znaków i wymaga poprawnego adresu (`'url'` w regułach `RecipeController`). |

Puste i złożone z samych spacji wartości `PublishRecipe` zamienia na `NULL`
**przed** zapisem (`nullIfBlank`), więc „pole wyczyszczone" i „pole nigdy nie
wypełnione" to w bazie ten sam stan. Wszystkie cztery idą do snapshotu wersji
(`SnapshotRecipeVersion`) i do eksportu danych (`CollectUserExportData`:
`skad_przepis`, `zrodlo_adres`, `od_kogo`, `notatka_o_zrodle`).

**`source_person` NIE JEST OSOBĄ — i to jest fakt o danych, nie ostrożność**
(D-156, PR #403). Nazwa kolumny obiecuje człowieka, a pole pyta **„Od kogo albo
skąd masz ten przepis"** z podpowiedzią `od mamy · z gazety · z bloga Nasze
smaki`. Właściciel potwierdził, że wpisuje tam **nazwę grupy na Facebooku**.
W jednej kolumnie `varchar(120)` leżą więc obok siebie: nazwa grupy, tytuł
gazety, imię babci i zdanie „od mamy" — i **z wiersza nie da się rozpoznać,
który to przypadek**.

Wynikają z tego dwie twarde reguły, obie już wdrożone:

1. **Widok pokazuje tę wartość DOSŁOWNIE** — bez doklejonego przyimka i bez
   kropki (D-153, PR #397). Nagłówek „Skąd ten przepis" niesie całe znaczenie;
   doklejane „Po " dawało „Po po mamie." i „Po Nasze smaki.". Pierwsza litera
   idzie przez `Str::ucfirst()` (wielobajtowe). Podpis przepisu składa się
   z członów rozdzielonych „·" (`Recipe::attributionLine()`), nigdy z formy
   wymagającej przypadka.
2. **Ta wartość nie trafia do pola, które wymusza typ encji** (D-156). W JSON-LD
   `author` opisuje wyłącznie konto publikujące, a `source_person` idzie do
   `citation` jako zwykły `Text`. `@type: Person` z tą wartością deklarowałby
   typ, którego nikt nie zna.

**Czego świadomie nie zrobiono: migracji danych.** Wartości wpisane pod starym
pytaniem („po mamie") zostają w bazie takie, jakie są — automatyczna zamiana
cudzego tekstu byłaby zgadywaniem (D-153).

`family_since_year smallint NULL` (CHECK `1850..2100`) — sam rok, bez daty
dziennej; więcej nie zbieramy. `source_scan_media_id uuid NULL` → `media`
(`ON DELETE SET NULL`) — zdjęcie kartki z zeszytu albo wycinka, bez OCR.
To zdjęcie bywa skanem odręcznej kartki z nazwiskami, więc dostęp do niego
idzie tą samą drogą co do każdego innego zdjęcia przepisu
(`App\Domain\Media\DostepDoZdjecia`).

### recipe_slug_redirects
Stary adres przepisu nadal działa po zmianie tytułu — link wysłany córce
SMS-em nie może umrzeć, bo autor poprawił literówkę
(`docs/seo/SEO_TECHNICAL.md`).

- **`recipe_slug_redirects.slug varchar(220) PRIMARY KEY`** — porzucony slug.
  Klucz główny jest tu SAMYM SLUGIEM, nie osobnym `id`: wiersz jest
  odwzorowaniem „adres → przepis" i pytamy o niego wyłącznie po adresie,
  a PK na sluggu z urzędu zabrania dwóch przepisów pod jednym starym adresem;
- `recipe_id uuid NOT NULL` → `recipes` (`ON DELETE CASCADE`) — dokąd
  przekierować;
- `created_at`.

### recipe_versions
Snapshot po istotnych zmianach.

- `recipe_id`, `editor_id` (`ON DELETE RESTRICT` — wersji nie wolno osierocić
  przez skasowanie konta edytora);
- `version_number integer` (CHECK `> 0`, `UNIQUE(recipe_id, version_number)`) —
  numer kolejny w obrębie jednego przepisu, nie w całym serwisie;
- `snapshot jsonb NOT NULL` — pełna treść przepisu w chwili zapisu, składana
  przez `App\Domain\Recipes\Actions\SnapshotRecipeVersion` (tytuł, opis,
  czasy, wszystkie cztery kolumny pochodzenia, składniki, kroki);
- `change_note varchar(500) NULL` — **wolny tekst od człowieka**: czym ta
  wersja różni się od poprzedniej. `NULL` znaczy „nic nie napisał" i jest
  stanem normalnym;
- `created_at`.

### ingredients + units
Podstawa search i późniejszego planera.

`ingredients` — słownik składników **wspólny dla serwisu**, budowany
z tego, co ludzie wpisują:

- `canonical_name varchar(160)` — nazwa w pisowni, którą pokazujemy
  („cebula czerwona"). To jest tekst pochodzący od człowieka, nie z żadnej
  zewnętrznej bazy;
- `normalized_name varchar(160) UNIQUE` — ta sama nazwa po `kuking_normalize()`,
  czyli klucz dopasowania; indeks `gin_trgm_ops` pod wyszukiwarkę.

`units` — jednostki miary. Tabela słownikowa, którą wypełnia seeder, a nie
człowiek przy przepisie:

- `code varchar(30) UNIQUE` — identyfikator maszynowy (`g`, `ml`, `lyzka`);
- `name varchar(80)` i `name_plural varchar(80) NULL` — forma pojedyncza
  i mnoga do pokazania („łyżka" / „łyżki"). Kolumna dopuszcza `NULL`, ale
  `UnitSeeder` wypełnia ją przy każdej z 15 jednostek;
- `unit_type varchar(30) NULL` — rodzaj jednostki. Seeder wpisuje `waga`,
  `objetosc` albo `ilosc`, ale **w bazie nie ma CHECK-a** i nie ma zamkniętej
  listy w kodzie. Dopóki przeliczania jednostek nie ma (V2), ta kolumna
  niczego nie rozstrzyga — i dlatego nie zamykamy jej przedwcześnie.

### recipe_ingredients
Musi mieć `ingredient_text`, nawet jeśli normalizacja nie rozpozna składnika.

**Wiersz niesie DWIE postacie tego samego składnika i to jest celowe:**

- `ingredient_text varchar(240) NOT NULL` — to, co naprawdę napisał autor
  („mąka pszenna typ 500"). Zapisywane dosłownie i tylko to jest pokazywane
  człowiekowi. Kolumna generowana `ingredient_text_search` trzyma obok wersję
  znormalizowaną dla wyszukiwarki (patrz „Kolumny `*_search`" niżej);
- `ingredient_id uuid NULL` → `ingredients` (`ON DELETE SET NULL`) — ten sam
  składnik jako hasło słownikowe. Wpisuje je `PublishRecipe::syncIngredients()`
  przez `Ingredient::findOrCreateByName()`, czyli **wiersz publikowany dziś
  ma to pole zawsze wypełnione** — hasła, którego nie ma, słownik się
  dorabia. `NULL` zostaje w schemacie dla wierszy z fabryk i seederów oraz
  jako skutek `ON DELETE SET NULL`. `SET NULL`, a nie `CASCADE`, bo usunięcie
  hasła ze słownika nie ma prawa zabrać komuś linijki z przepisu — zabiera
  wyłącznie dopasowanie. Normalizacja jest DODATKIEM do tekstu autora i nigdy
  go nie nadpisuje (`App\Models\RecipeIngredient`);

**Ilość jest rozbita na liczbę i jednostkę, obie opcjonalne:**
`quantity numeric(12,4) NULL` (CHECK `quantity IS NULL OR quantity >= 0`)
i `unit_id uuid NULL` → `units` (`ON DELETE SET NULL`). `numeric`, a nie
`float`, bo „1/3 szklanki" ma się zapisać i odczytać tak samo po skalowaniu
porcji (V2); cztery miejsca po przecinku wystarczają na ułamki z kuchni.
`NULL` w obu znaczy „ilości nie podano" i jest czymś innym niż `no_amount`
niżej, które znaczy „ilości NIE MA".

**`recipe_ingredients.note varchar(300) NULL`** — dopisek przy JEDNYM
składniku („najlepiej wiejskie", „albo margaryna"), **wolny tekst od
człowieka**. Coś innego niż `ingredient_text`, który jest samym składnikiem
w postaci wpisanej przez autora: dopisek da się pominąć przy liście zakupów,
składnika nie. `NULL` jest stanem normalnym.

**`no_amount boolean NOT NULL DEFAULT false`** (migracja
`2026_09_06_130000_add_no_amount_to_recipe_ingredients`, issue #44) —
„ten składnik nie ma wymiernej ilości": sól do smaku, pieprz, mleko — ile
weźmie. Przy skalowaniu porcji (V2) takiego składnika **się nie mnoży**:
przepis razy trzy poprosiłby inaczej o trzy szczypty soli i o trzy razy
„ile weźmie".

Kolumna weszła **przed** funkcją, która jej używa, i to jest jedyny powód,
dla którego istnieje już teraz: dopisanie jej dziś kosztuje jedną linijkę,
a po tym, jak w tabeli znajdą się przepisy prawdziwych ludzi, kosztowałoby
migrację danych i **zgadywanie**, które składniki są „do smaku".

CHECK `recipe_ingredients_no_amount_check`: `no_amount = false OR (quantity
IS NULL AND unit_id IS NULL)`. Bez niego dałoby się zapisać wiersz mówiący
naraz „nie mam ilości" i „mam 200 ml" — wtedy pytanie „czy to skalować"
nie ma poprawnej odpowiedzi. `PublishRecipe` rozstrzyga konflikt **przed**
zapisem, kasując ilość, żeby CHECK nie zamienił się w błąd 500 na publikacji.

**Rollback:** `down()` zdejmuje CHECK i kolumnę. Bezstratny tylko dopóki
skalowanie porcji nie jest wdrożone — potem cofnięcie tej migracji znaczy
utratę informacji, której nie da się odtworzyć, więc wtedy najpierw kopia
tabeli.

#### `group_name` — „Ciasto", „Farsz", „Do podania" (D-033)

**`group_name varchar(120) NULL`** (kolumna z pierwszej migracji przepisów
`2026_09_05_000400_create_recipes_tables`; CHECK dołożony migracją
`2026_09_08_100000_add_group_name_check_to_recipe_ingredients`) — śródtytuł
części przepisu. `NULL` znaczy „ten składnik nie należy do żadnej części"
i jest **stanem normalnym**: większość przepisów nie ma grup i nic w bazie
ani w interfejsie nie traktuje pustej wartości jako braku do uzupełnienia.

**Kolejność grup nie ma własnej kolumny.** Bierze się z `position`
składników: grupa pojawia się tam, gdzie stoi jej pierwszy składnik. Autor
pisze listę od góry do dołu i to jest cała informacja o kolejności, jaką ma;
druga liczba obok byłaby drugim miejscem, w którym kolejność może się
rozjechać z pierwszym.

**Odrzucona osobna tabela grup** (`recipe_ingredient_groups` z `position`
plus `group_id` przy składniku). Grupa nie ma własnego życia — nikt nie
zakłada „Farszu", żeby potem wkładać do niego składniki — więc skasowanie
ostatniego składnika zostawiałoby pusty nagłówek. Do tego obie drogi zapisu
kasują składniki i piszą je od nowa (`PublishRecipe::syncIngredients`), więc
każdy zapis przepisu stawałby się synchronizacją dwóch list zamiast jednej,
a UNIQUE na nazwie działa i tak wyłącznie w obrębie jednego przepisu — czyli
daje tyle, co ujednolicenie nazw przy zapisie, za cenę klucza obcego i JOIN-a
na najczęściej czytanej stronie serwisu. Pełna lista odrzuconych wariantów
(słownik nazw wspólny dla serwisu, `group_position`, nagłówek jako wiersz
składnika z flagą `is_header`) stoi w komentarzu migracji.

CHECK `recipe_ingredients_group_name_check`: `group_name IS NULL OR
btrim(group_name) <> ''`. Pusty ciąg znaków to nagłówek bez treści — pusta
linia na ekranie, a w czytniku ekranu „nagłówek poziomu trzeciego" i cisza.
`PublishRecipe` zamienia puste i same spacje na `NULL` **przed** zapisem
i przycina nazwę do 120 znaków, żeby CHECK i długość kolumny nie zamieniły
się w błąd 500 na publikacji.

**Czego baza NIE pilnuje: ciągłości grup.** Da się zapisać „poz. 0 Ciasto,
poz. 1 Farsz, poz. 2 Ciasto" — CHECK nie widzi sąsiednich wierszy
(`docs/research/repos/TandoorRecipes-recipes.md` §2.2, rekomendacja R8).
Pilnują tego dwie warstwy nad bazą: `PublishRecipe` ujednolica pisownię nazw
w obrębie przepisu (wygrywa pierwsza pisownia autora, więc „Farsz" i „farsz"
to jedna grupa), a `App\Domain\Recipes\GrupySkladnikow` układa listę do
wyświetlenia — składniki bez grupy na górze i bez nagłówka, grupy w kolejności
autora, wiersze jednej grupy pod jednym nagłówkiem. Ten sam kod czyta strona
przepisu, podgląd w kreatorze i przepis w eksporcie danych.

**Rollback:** `down()` zdejmuje sam CHECK i nie rusza danych ani kolumny —
nazwy grup zostają. Nieodwracalna jest jedna rzecz z `up()`: nazwy będące
pustym ciągiem znaków stają się `NULL`. To nie jest utrata informacji, bo
pusty ciąg nigdy nie był nazwą grupy.

### Wspomnienia „Rok temu gotowałaś…" (issue #34)

Dwie kolumny z migracji `2026_09_06_140000_add_memories_to_users_and_posts`,
bo są **dwa różne „nie chcę tego widzieć"**:

- **`users.memories_enabled`** (`boolean NOT NULL DEFAULT true`) — wyłącznik
  całej mechaniki. To nie jest ustawienie wygody: wpis z przepisem po mamie,
  która zmarła w tym roku, wyświetlony bez ostrzeżenia na stronie głównej,
  jest okrutny. Człowiek w żałobie ma to wyłączyć jednym kliknięciem, a nie
  odklikiwać wspomnienia po kolei.
- **`posts.hide_as_memory`** (`boolean NOT NULL DEFAULT false`) — ukrycie
  JEDNEGO wpisu przy zachowaniu mechaniki, bo zwykle boli jedna rzecz,
  a nie wszystkie. Wpis **zostaje** w archiwum profilu: „nie przypominaj mi
  o tym" to nie to samo co „usuń to".

Kolumna siedzi na `posts`, a nie w tabeli `(user_id, post_id)`, bo wspomnienie
to zawsze **własny** wpis oglądającego — właściciel i osoba ukrywająca to ta
sama osoba, więc druga kolumna zawsze wynikałaby z pierwszej.

**Rollback — poprawiony po #287 (D-088).** `down()` zdejmuje obie kolumny,
**ale najpierw ODMAWIA**, jeśli ktokolwiek ma wartość inną od domyślnej: choć
jedno konto z `memories_enabled = false` albo choć jeden wpis
z `hide_as_memory = true`.

Wcześniej stało tu „`down()` zdejmuje obie kolumny i traci przy tym listę
ukrytych wspomnień […] Na produkcji: najpierw kopia obu kolumn". Opis był
prawdziwy i bezwartościowy jako zabezpieczenie — przenosił ochronę na czyjąś
pamięć w trakcie awaryjnego wdrożenia. Obie kolumny są `NOT NULL DEFAULT`, więc
cykl `migrate:rollback` → `migrate` (czyli `migrate:refresh` w CI oraz rollback
WDROŻENIA, nie tylko bazy) nadpisuje decyzję wartością domyślną, **odwrotną do
wybranej**. Zmierzone na prawdziwej bazie testowej:

```text
PRZED:    memories_enabled=false  hide_as_memory=true
PO CYKLU: memories_enabled=true   hide_as_memory=false
```

Po ludzku: wyłącznik, którym osoba w żałobie wyłączyła wspomnienia, włącza się
sam, a wpis z przepisem po mamie, który świadomie schowała, wraca na stronę
główną — bez błędu, z poprawnymi kolumnami i poprawnymi wartościami `boolean`.
**Ta sama choroba co MIG-01** (`users.delete_scope`, wyżej) i co DB2; przegląd
migracji przy #287 wymienił trzy inne pliki do pominięcia i ten PRZEOCZYŁ,
mimo że reguła D-088 nazywa widoczność wprost.

Naprawa: dwa liczniki PRZED pierwszym `dropColumn` (osobne, bo to dwie różne
decyzje i każda ginie osobno) i `RuntimeException` z instrukcją, co zrobić
ręcznie. Na wartościach domyślnych i na świeżej bazie rollback przechodzi bez
pytania — test `tests/Feature/CofniecieMigracjiNieWlaczaWspomnienTest.php`
sprawdza obie gałęzie odmowy osobno i obie kontrole dodatnie. Skutek udanego
rollbacku jest wciąż ZNANY: mechanika wspomnień znika razem z kolumnami.

### recipe_steps
Pozycja + instruction + opcjonalny timer/media.

**`instruction text NOT NULL`** — treść jednego kroku, **wolny tekst od
człowieka**, bez górnego limitu w bazie. Zapisywana dosłownie: nic jej nie
skraca, nie numeruje i nie przepisuje — numer kroku bierze się z `position`
(`UNIQUE(recipe_id, position)`, CHECK `>= 0`), a nie z tego, co autor napisał
na początku zdania. Pusty krok nie jest zapisywany: `PublishRecipe::cleanSteps()`
odrzuca wiersze bez treści, zanim dojdą do bazy, więc `NOT NULL` nie ma szansy
zamienić się w błąd 500 na publikacji.

`timer_seconds` i `media_id` ustawia od migracji poza schematem — czyli od
issue #21 — **formularz przepisu**, obiema drogami: `/dodaj/przepis/jedna-strona`
(zwykły POST) i kreator Livewire. Wcześniej obie kolumny czytał tryb gotowania,
a nie zapisywała ich żadna droga dostępna człowiekowi.

Człowiek wpisuje **minuty**; zamiana na sekundy należy do
`App\Domain\Recipes\StepTimer` — jedynego miejsca tego przelicznika — i tam
też stoją granice (0–10080 minut, pełne minuty, zero znaczy „bez minutnika",
nie „minutnik na zero"). CHECK `timer_seconds IS NULL OR timer_seconds >= 0`
zostaje ostatnią linią obrony dla dróg omijających aplikację.

Zdjęcie kroku idzie tym samym potokiem co każde inne (`ObslugiwaneZdjecie`
w walidacji, `StoreUploadedImage` w zapisie) i liczy się do budżetu
`App\Support\LimityZdjec::maksZdjecKrokowNaZapis()` (= `max_per_post − 2`,
dziś 4 na jeden zapis, bo dwa pola plikowe formularz ma zawsze).

Formularz identyfikuje krok **ukrytym `steps[i][id]`, nie pozycją**:
`PublishRecipe::cleanSteps()` pomija puste wiersze, więc numer wiersza w
formularzu nie równa się pozycji w bazie. `PublishRecipe` dziedziczy zdjęcie po
tożsamości kroku, dzięki czemu wyczyszczenie albo przestawienie wiersza nie
przenosi zdjęcia na sąsiedni krok. Mapa tożsamości jest budowana wyłącznie
z kroków tego przepisu i **przed** `delete()` — to jest cała autoryzacja tego
identyfikatora.

### collection_items — przepisy ORAZ wpisy

Od migracji `2026_09_06_150000_collection_items_accept_posts` zeszyt przyjmuje
także wpisy (UI kit v2, ekran 01 — decyzja właściciela). To dwie różne
potrzeby: zapisany przepis znaczy „chcę to ugotować i mam listę składników",
zapisane zdjęcie — „chcę kiedyś zrobić coś **takiego**".

Wzorzec jest ten sam co przy komentarzach: dwie kolumny dopuszczające NULL
i CHECK `collection_items_single_target_check`
(`num_nonnulls(recipe_id, post_id) = 1`). **Nie polimorfizm** z
`item_type`/`item_id`: tamten zapis nie ma kluczy obcych, więc skasowany wpis
zostawia wiersz wskazujący w próżnię, a baza nie ma jak tego zauważyć.

Klucz główny `(collection_id, recipe_id)` **musiał zniknąć** — kolumna klucza
głównego nie może być NULL. Zastępują go dwa indeksy częściowe:
`collection_items_recipe_unique` i `collection_items_post_unique`. Pilnują
dokładnie tego samego co stary klucz: ta sama pozycja nie stanie w tym samym
zeszycie dwa razy (issue #43).

**Rollback jest STRATNY.** `down()` przywraca stary klucz główny, więc musi
najpierw skasować wiersze z `post_id` — zapisane wpisy znikają z zeszytów
bezpowrotnie. Przy cofaniu na produkcji: najpierw kopia tabeli.

**Widoczność:** zeszyt jest pojemnikiem na CUDZE treści, więc `CollectionController`
przepuszcza wpisy przez `widoczneDla()` i `tylkoOdDostepnychAutorow()`. Wpis,
który przestał być widoczny, **zostaje w bazie**, a ekran mówi ile takich
pozycji jest, nie mówiąc jakich — ciche zniknięcie wygląda jak utrata danych,
a pokazanie treści łamie ustawienie autora.

### cooked_events
Jedno realne gotowanie. Brak unique `(user_id, recipe_id)`.

- `user_id`, `recipe_id` — kto i co gotował. **Klucza do `posts` tu nie ma**:
  wpis ze zdjęciem jest osobną encją, a gotowanie da się zgłosić bez wpisu;
- `note varchar(2000) NULL` — „Jak wyszło?", czyli **wolny tekst od
  człowieka** o tym jednym gotowaniu;
- `would_make_again boolean NULL` — „zrobię jeszcze raz". `NULL` znaczy
  „nie odpowiedział" i jest czymś innym niż `false`;
- `perceived_difficulty varchar(12) NULL` (CHECK: `easy` \| `medium` \| `hard`)
  — trudność **odczuta przez gotującego**, osobna od `recipes.difficulty`
  deklarowanej przez autora przepisu;
- `actual_minutes integer NULL` (CHECK `>= 0`) — ile to naprawdę zajęło;
- **`changes_note varchar(1000) NULL`** — „co zmieniłem po swojemu". **Wolny
  tekst od człowieka** i najczęściej czytana część komentarza pod przepisem;
  pierwszy krok do „Mojej wersji" (V1);
- `cooked_at timestamptz NOT NULL DEFAULT now()` — kiedy gotowano. Osobne od
  `created_at`, bo wpis o niedzielnym obiedzie bywa pisany we wtorek;
- `klucz_wyslania` — patrz niżej.

**`klucz_wyslania` — jedno wysłanie formularza to jeden wiersz** (D-027,
migracja `2026_09_07_900100_add_klucz_wyslania_to_cooked_events`).

```sql
ALTER TABLE cooked_events ADD COLUMN klucz_wyslania uuid NULL;
CREATE UNIQUE INDEX cooked_events_one_per_klucz_wyslania
    ON cooked_events (user_id, klucz_wyslania)
    WHERE klucz_wyslania IS NOT NULL;
```

**To NIE jest `UNIQUE (user_id, recipe_id)` i zakaz z AGENTS.md §6 zostaje
nienaruszony.** Ta sama osoba może gotować ten sam przepis dziesiątki razy
przez lata i każde wykonanie jest osobnym wydarzeniem — indeks pilnuje
wyłącznie tego, żeby JEDNO wysłanie formularza dało JEDEN wiersz. Nowe
gotowanie otwiera nowy formularz, więc dostaje nowy klucz i przechodzi
(zmierzone, ADR §3.4 wiersz 3).

Stawka jest tu wyższa niż przy wpisie: podwójne „Ugotowałem" dawało dwa
wykonania **i dwa powiadomienia** u autora przepisu — a to jest
najcenniejsze powiadomienie w całym serwisie i nie może przychodzić podwójnie
za jedno gotowanie.

**Kolumna jest `NULL`-owalna i nie ma backfillu.** Wiersze sprzed tej
migracji, wiersze z seederów i wiersze z fabryk mają `NULL` i indeks ich nie
obejmuje — w PostgreSQL indeks częściowy z `WHERE klucz_wyslania IS NOT NULL`
mówi to wprost, zamiast liczyć na to, że czytelnik pamięta, iż zwykły UNIQUE
przepuszcza dowolnie wiele `NULL`-i. `NOT NULL` rozwaliłoby `database/seeders/`
i każdy test tworzący wiersz fabryką.

**Wyłącznik:** `kuking.formularze.klucz_wyslania_wlaczony` (`false` →
formularz nie renderuje ukrytego pola, kolumna dostaje `NULL`, indeks
przestaje cokolwiek odbijać). To jedyna droga wycofania bez wdrażania
migracji — dlatego jest w konfiguracji.

**Rollback:** `DROP INDEX IF EXISTS cooked_events_one_per_klucz_wyslania`, potem `DROP COLUMN
klucz_wyslania`. Bezstratnie i dlatego `down()` niczego nie odmawia: kolumna
niesie wyłącznie identyfikator wysłania wygenerowany przez serwer, ani jednego
słowa napisanego przez człowieka.

### cooked_event_media
Zdjęcia z JEDNEGO gotowania. Tabela łącząca `cooked_events` z `media`,
bliźniacza do `post_media` i z tego samego powodu: jedno wykonanie bywa
udokumentowane kilkoma zdjęciami, a to samo zdjęcie nie należy do wykonania
„na własność" — należy do właściciela, a wykonanie je tylko przypina.

```sql
CREATE TABLE cooked_event_media (
    cooked_event_id uuid NOT NULL REFERENCES cooked_events(id) ON DELETE CASCADE,
    media_id        uuid NOT NULL REFERENCES media(id)          ON DELETE CASCADE,
    position        smallint NOT NULL DEFAULT 0,
    PRIMARY KEY (cooked_event_id, media_id)
);
ALTER TABLE cooked_event_media
    ADD CONSTRAINT cooked_event_media_cooked_event_id_position_unique
    UNIQUE (cooked_event_id, position);
ALTER TABLE cooked_event_media
    ADD CONSTRAINT cooked_event_media_position_check CHECK (position >= 0);
```

- **Nie ma tu kolumny `id`** i jest to ta sama decyzja co przy `follows`:
  przypięcie jest tożsamością pary, nie osobnym bytem. Klucz główny
  `(cooked_event_id, media_id)` załatwia przy okazji „to samo zdjęcie dwa razy
  przy jednym gotowaniu";
- `position smallint NOT NULL DEFAULT 0` (CHECK `>= 0`) — kolejność zdjęć
  ustawiona przez człowieka. `UNIQUE (cooked_event_id, position)` mówi, że
  w obrębie jednego wykonania dwa zdjęcia nie stoją na tym samym miejscu;
  przestawianie kolejności wymaga więc zapisu przenoszącego całą serię, a nie
  podmiany jednej liczby. Kolejność czyta relacja `CookedEvent::media()`
  (`orderBy('cooked_event_media.position')`), nie kolejność wierszy;
- **oba klucze obce są `ON DELETE CASCADE`, i każdy kasuje co innego.**
  Kasowanie wykonania zabiera przypięcia i zostawia zdjęcia — plik dalej
  należy do właściciela i może wisieć gdzie indziej. Kasowanie wiersza `media`
  zabiera przypięcie, ale nie wykonanie: opis „jak wyszło" zostaje bez
  zdjęcia, zamiast zniknąć razem z nim.

**Ta tabela jest na obu listach odwołań do `media`** —
`App\Domain\Media\KasujZdjecie::ODWOLANIA`
i `App\Domain\Media\DostepDoZdjecia::ODWOLANIA`. Pierwsza pilnuje, żeby
sprzątacz osieroconych zdjęć nie skasował pliku przypiętego do gotowania;
druga, żeby takie zdjęcie miało rodzica przy pytaniu o dostęp. Wypadnięcie
stąd z którejkolwiek z nich jest cichą awarią i pilnują tego osobne testy
(`ZdjeciaChronioneNieWyciekajaTest`, `AutoryzacjaZdjeciaJednymPrzejsciemTest`).

**Zdjęcia przypina się pod blokadą, w tej samej transakcji co wiersz
`cooked_events`** (`RecordCookedEvent`, issue #285, D-083). Powód jest
zapisany przy tamtej akcji: przy wyborze zdjęć poza transakcją sprzątacz
osieroconych mieścił się w środku, a `cooked_event_media.media_id` kasuje się
kaskadowo — więc wykonanie zostawało bez zdjęcia i bez pliku.

**Rollback:** tabela powstaje i znika razem z `cooked_events`
(`2026_09_05_000600_create_cooked_events_tables`). Osobnego `down()` nie ma
i nie potrzebuje strażnika z D-088: nie leży tu ani jedna wartość semantyczna —
tylko dwa identyfikatory i liczba porządkowa.

### comments
Komentarz dotyczy dokładnie jednego:
- post;
- recipe;
- cooked event.

Pilnuje tego CHECK `comments_single_target_check`:
`num_nonnulls(post_id, recipe_id, cooked_event_id) = 1`.

**`comments.body varchar(4000) NOT NULL`** — treść komentarza, **wolny tekst
od człowieka**, zapisywana dosłownie. 4000 znaków to nie jest limit
„dla porządku": pod przepisem pisze się przepis po swojemu, a ucięcie
takiego komentarza w połowie zdania byłoby zabraniem komuś głosu bez
uprzedzenia. `parent_id uuid NULL` → `comments` — odpowiedź na komentarz;
`NULL` znaczy „komentarz pierwszego poziomu". Kasowanie jest miękkie
(`deleted_at`), a `status` (`published` \| `hidden` \| `removed`) trzyma
decyzję moderacji osobno od skasowania przez autora.

**Podwójne kliknięcie „Wyślij" NIE jest tu pilnowane przez schemat —
i to jest świadome.** Zmierzone przed poprawką (audyt podwójnego wysłania,
12 września 2026): dwa identyczne `POST /wpisy/{post}/komentarz` dawały
**dwa** wiersze i **dwa** powiadomienia u autora wpisu; po poprawce jeden
i jedno. Ochrona stoi w akcji domenowej `PublishComment` i jest BLOKADĄ
W BAZIE z rewalidacją pod nią (`pg_advisory_xact_lock` na tożsamości
wysłania: autor + miejsce + wątek + treść), a nie ograniczeniem w tabeli.

Powód, dla którego nie ma tu `klucz_wyslania` jak w `posts`, `recipes`,
`cooked_events` i `reports`: klucz musi przyjechać z formularza, a formularz
komentarza jest **jeden dla trzech ekranów**
(`resources/views/components/comment-thread.blade.php`) i nie ma w nim
miejsca na własne pole bez zmiany tego komponentu.

Powód, dla którego nie ma tu `UNIQUE` na treści: to samo zdanie pod tym samym
wpisem po tygodniu jest **nową reakcją, nie duplikatem**, a zakaz bez okna
czasowego wyciszałby rozmowę. Okno stoi
w `kuking.formularze.okno_powtorzenia_komentarza_sekund` (domyślnie 60 s,
`0` wyłącza mechanizm).

### collections + collection_items
Osobisty zeszyt.

- **`collections.name varchar(120) NOT NULL`** — nazwa zeszytu nadana przez
  właściciela, **wolny tekst**. Unikalna w obrębie JEDNEGO konta i bez
  rozróżniania wielkości liter — szczegóły i powód niżej, przy indeksie
  `collections_owner_name_lower_unique`;
- **`collections.description varchar(500) NULL`** — zdanie o tym, co właściciel
  w tym zeszycie zbiera. `NULL` jest stanem normalnym;
- `visibility varchar(20) NOT NULL DEFAULT 'private'` (CHECK: `public` \|
  `private`) — **domyślnie prywatny**, bo zeszyt jest notatnikiem, a nie
  publikacją;
- `is_default boolean NOT NULL DEFAULT false` — zeszyt zakładany kontu
  automatycznie, ten, do którego trafia „Zapisz" bez wyboru;
- **`collection_items.note varchar(500) NULL`** — dopisek właściciela przy
  zapisanej rzeczy („na urodziny taty"). **Kolumna jest ŻYWA.** Zapisują ją
  `SavePostToCollection.php:41,49` i `SaveRecipeToCollection.php:50,58` (przy
  zapisie i przy ponownym zapisie tej samej rzeczy), a **wychodzi w eksporcie
  danych osobowych** jako `moja_notatka` —
  `app/Domain/Users/Exports/CollectUserExportData.php:335,347`, pilnuje tego
  `tests/Feature/DataExportTest.php:188`. `NULL` jest stanem normalnym: dopisek
  jest nieobowiązkowy.

  > **Sprostowanie z 12 września 2026.** Do tego dnia stało tu, że „dziś nic
  > go nie zapisuje ani nie pokazuje" i że kolumna jest pusta. **To była
  > nieprawda** — pochodziła z sekcji „przy okazji zauważone" w D-166, czyli
  > z hipotezy podanej bez pomiaru. Próbne skasowanie tej kolumny oblewa
  > testy. Gdyby ktoś zaufał tamtemu zdaniu i usunął kolumnę, z eksportu RODO
  > zniknęłaby treść napisana przez człowieka.

**`collection_items` NIE MA DZIŚ KLUCZA GŁÓWNEGO** i to jest stan zamierzony.
Migracja zakładająca tabelę (`2026_09_05_000800_create_collections_tables`)
dała `PRIMARY KEY (collection_id, recipe_id)`, ale migracja
`2026_09_06_150000_collection_items_accept_posts` musiała go zdjąć: kolumna
klucza głównego nie może być NULL, a od tamtej pory `recipe_id` bywa NULL —
w zeszycie stoją także wpisy. Zastępują go **dwa indeksy częściowe**,
`collection_items_recipe_unique` i `collection_items_post_unique`, i pilnują
dokładnie tego samego: ta sama pozycja nie stanie w tym samym zeszycie dwa
razy (issue #43). Pełny opis razem z CHECK-iem stoi wyżej, w sekcji
[`collection_items — przepisy ORAZ wpisy`](#collection_items--przepisy-oraz-wpisy).

> **Nie przywracaj tu klucza głównego.** Wpisanie z powrotem
> `PRIMARY KEY (collection_id, recipe_id)` wymaga `recipe_id NOT NULL`, czyli
> skasowania wszystkich zapisanych wpisów z zeszytów. Stan schematu pilnuje
> `tests/Feature/ZeszytBezKluczaGlownegoTest`, a unikalność —
> `tests/Feature/UnikalnoscZeszytowTest`.

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

**`type varchar(80) NOT NULL`** — rodzaj powiadomienia (`cooked_event.created`,
`comment.created`, `follow.created`, `moderation.decision`…). **Bez CHECK-a
w bazie**: zamknięta lista stoi stałymi `TYPE_*` w `App\Models\Notification`,
a nowy typ dochodzi razem z kodem, który go wysyła i tłumaczy na zdanie po
polsku — CHECK kazałby do tego dokładać migrację i nie chroniłby przed jedyną
realną pomyłką, czyli typem bez tłumaczenia. Ta kolumna **rozstrzyga o retencji**: typy z
`Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA` żyją do terminu
odwołania, a nie 3 miesiące (patrz niżej). `data jsonb` niesie resztę —
identyfikatory treści i to, co trzeba pokazać w zdaniu.

**Retencja:** `config('kuking.notifications.retention_months')` — **3 miesiące**
od `created_at`, **niezależnie od `read_at`** (wariant A z `docs/decyzje/ADR_RETENCJE.md`
§6: jeden wiek dla wszystkich; wariant B trzymałby bezterminowo powiadomienia,
których nikt nigdy nie otworzy). Tę samą liczbę widzi człowiek w polityce
prywatności — `resources/legal/polityka-prywatnosci.md`, wiersz „Powiadomienia
w serwisie".

*(Stał tu dłuższy okres, przepisany z ADR §5.2 jako rekomendacja agenta
z pierwszej wersji tego dokumentu. Kod i polityka prywatności mówiły wtedy to,
co mówią teraz, więc poprawiliśmy dokument, nie kod — D-038. Audyt zewnętrzny,
pozycja G13. Liczba stoi w tej sekcji RAZ i pilnuje tego
`tests/Feature/ZeszytBezKluczaGlownegoTest`.)*

**WYJĄTEK, którego nie wolno przeoczyć przy zmianie tej liczby.** Trzy miesiące
są KRÓTSZE niż sześć miesięcy, przez które ma działać prawo do odwołania od
decyzji moderacyjnej (DSA art. 20 ust. 1, `ModerationAction::appealDeadline()`).
Powiadomienie o decyzji niesie **jedyny w serwisie link „Odwołaj się"**, więc
typy z `App\Models\Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`
nie są kasowane według tej liczby — ich termin wylicza się z powiązanej decyzji.
Ta lista jest **zamkniętą stałą w kodzie, nie w configu**, celowo: zmiana ma
przechodzić przez code review, nie przez zmienną środowiskową (ten sam wzorzec
co `AuditLogEntry::NIGDY_NIE_KASUJ`).

Egzekwuje `kuking:sprzataj-powiadomienia`
(`App\Domain\Compliance\PrzedawnionePowiadomienia`), harmonogram codziennie
o 04:20. Zwykły masowy `DELETE` — wiersz nie ma odpowiednika w storage.

### reports
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

Kolumny dołożone dla drogi prawnej:

| Kolumna | Po co |
|---|---|
| `notifier_name` | Imię i nazwisko albo nazwa instytucji (art. 16 ust. 2 lit. b). |
| `notifier_email` | **Może być `NULL`** — art. 16 ust. 2 lit. c zwalnia z podania danych przy zgłoszeniach dotyczących przestępstw z art. 3–7 dyrektywy 2011/93/UE. Wtedy nie ma komu odpowiedzieć i to jest zgodne z przepisem, a nie brak w danych. |
| `target_url` | Adres wpisany przez człowieka, zapisany dosłownie (art. 16 ust. 2 lit. b — „dokładna lokalizacja elektroniczna”). |
| `illegality_explanation` | Uzasadnienie, osobne od swobodnego `details` (art. 16 ust. 2 lit. a). |
| `good_faith_at` | Oświadczenie o dobrej wierze jako **znacznik czasu**, nie `boolean` — przy sporze liczy się, kiedy je złożono. |
| `receipt_sent_at` | Potwierdzenie odbioru przekazane zgłaszającemu (ust. 4). |
| `decision_sent_at` | Informacja o decyzji przekazana zgłaszającemu (ust. 5). |

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

#### `target_type = 'media'` — zdjęcie jako osobny cel (issue #237)

Migracja `2026_09_10_300000_zdjecie_jako_cel_oznaczenia` dopisuje do
`reports_target_type_check` wartość **`media`**. Dziś trafia tu wyłącznie
zdjęcie profilowe: model ocenia je po przetworzeniu (`PrzeanalizujAwatar`),
a oznaczenie wskazuje `media.id`.

**Dlaczego zdjęcie, a nie konto.** Indeks `reports_jeden_automat_na_tresc`
przepuszcza jedno oznaczenie automatu na (typ, identyfikator) na zawsze.
Przy celu `user` oceniony zostałby pierwszy awatar konta i żaden następny,
a podmiana zdjęcia to sekunda pracy.

**Dlaczego `media`, a nie `avatar`.** `ModeratedContent::TYPY` mapuje klasę
modelu, a klasa (`App\Models\Media`) jest ta sama dla awatara i dla zdjęcia
we wpisie. Nazwa `avatar` byłaby prawdziwa dziś i kłamliwa pierwszego dnia,
w którym oznaczymy zdjęcie z wpisu osobno.

`ModerationAction::DOZWOLONE['media']` to `none`, `warn`, `suspend`, `ban` —
**bez `hide`** (`Media` nie ma statusu w rozumieniu moderacji) i **bez
`remove`** (kasowanie zdjęcia jest nieodwracalne, a odwołanie od `remove` ma
treść przywrócić — DSA art. 17).

**Rollback:** `php artisan migrate:rollback --step=1` przywraca CHECK bez
`media`, ale **odmawia**, gdy w tabeli leży choć jedno takie oznaczenie —
to są sprawy moderacyjne z decyzjami i odwołaniami, a rollback schematu nie
jest decyzją o ich wyrzuceniu. Komunikat mówi, co zrobić.

#### `target_type = 'unknown'` i puste `target_id`

Adres bywa nierozpoznawalny: ktoś wkleja link z pamięci albo ze zrzutu
ekranu, treść mogła już zniknąć, adres bywa z innego serwisu. **Zgłoszenie
i tak musi zostać przyjęte** — odmowa byłaby odmówieniem mechanizmu, który
przepis nakazuje udostępnić. Dlatego:

- `reports_target_type_check` dopuszcza typ `unknown` (dziś siódmy, po dodaniu `media`);
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
`moderation_actions` i `appeals`, w jednej komendzie, transakcja per wiersz,
harmonogram codziennie o 04:30.

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

### moderation_actions
Decyzje moderatorów.

**`action varchar(40) NOT NULL`** — co moderator zrobił: `no_action`, `hide`,
`unhide`, `remove`, `warn`, `suspend`, `ban`. **Bez CHECK-a w bazie**, bo
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

**Retencja:** ten sam okres i **ta sama komenda** co `reports` (domyślnie
36 miesięcy, decyzja właściciela), liczony od `created_at` — kolumna jest
niemutowalna (`ModerationAction::UPDATED_AT === null`). Wiersz jest kandydatem
dopiero wtedy, gdy DODATKOWO nie zostaje po nim **żaden** wiersz w `appeals`;
inaczej kaskada `appeals.moderation_action_id` (`cascadeOnDelete`) zabrałaby
odwołanie przed jego własnym czasem (ADR §4). Kolejność w komendzie:
`appeals` → `moderation_actions` → `reports`. Zapytanie idzie wprost do tabeli
`appeals` przez `moderation_action_id`, a nie przez nazwaną relację Eloquent —
blokada działa przy każdym żywym odwołaniu, niezależnie od roli odwołującego.

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
  `user.role_changed`, `admin.user_viewed`). **Bez CHECK-a w bazie** i to jest
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
  z żądania HTTP" (komenda, harmonogram);
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

### dziennik_zgod
Kiedy i skąd zgoda została udzielona, a kiedy wycofana — tabela
**append-only** (migracja `2026_09_10_400000_create_dziennik_zgod_table`,
audyt DB1, `docs/DECISIONS.md` **D-072**).

Do 10 września całym dowodem był boolean `users.wants_weekly_digest`. RODO
art. 7 ust. 1 każe zgodę **wykazać**, a „dziś pole ma wartość `true`" nie
odpowiada na pytanie, kiedy człowiek to kliknął, czy wcześniej tego nie
odklikał i czy po wycofaniu wysyłka nie szła dalej.

| Kolumna | Znaczenie |
|---|---|
| `id` | `bigserial`. Nie UUID — wiersz nigdy nie jest adresowany z zewnątrz (tak samo jak `audit_log` i `product_signals`). Rosnący klucz trzyma KOLEJNOŚĆ dwóch zdarzeń z tej samej sekundy. |
| `user_id` | `uuid`, **NOT NULL**, FK do `users` z `ON DELETE RESTRICT` (patrz niżej). |
| `cel` | Cel zgody. Dziś jedna wartość: `tygodniowy_digest`. CHECK `dziennik_zgod_cel_check` — zbiór zamknięty, druga zgoda wymaga migracji i recenzji. |
| `czynnosc` | `udzielona` \| `wycofana`. CHECK `dziennik_zgod_czynnosc_check`. Dwie wartości, bo to są dwie rzeczy, które RODO każe umieć wykazać (art. 7 ust. 1 i ust. 3). |
| `zrodlo` | `ustawienia` \| `link_wypisania` \| `link_powrotny` \| `usuniecie_konta`. CHECK `dziennik_zgod_zrodlo_check`. Część dowodu: „gdzie człowiek wtedy był". |
| `wersja_polityki` | Wersja polityki prywatności z chwili zdarzenia, z `config('kuking.zgody.wersja_polityki')`. Bez niej dowód mówi „zgodził się", ale nie mówi NA CO. |
| `wystapilo_at` | `timestamptz`, `useCurrent()`. Moment ZDARZENIA, nie zapisu wiersza — dlatego tabela nie ma `created_at`/`updated_at`. |

```sql
CREATE TABLE dziennik_zgod (
    id              bigserial PRIMARY KEY,
    user_id         uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    cel             varchar(40) NOT NULL,
    czynnosc        varchar(20) NOT NULL,
    zrodlo          varchar(30) NOT NULL,
    wersja_polityki varchar(20) NOT NULL,
    wystapilo_at    timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX dziennik_zgod_konto_cel_idx ON dziennik_zgod (user_id, cel, wystapilo_at DESC);
```

Indeks jest jeden, bo pytanie jest jedno: „historia zgody TEJ osoby na TEN
cel, od najnowszej" — tak wygląda odpowiedź na żądanie z art. 7 ust. 1.

**Dlaczego tabela, a nie dwie kolumny z datami.** Audyt dopuszczał
`weekly_digest_consented_at` + `weekly_digest_withdrawn_at` jako minimum i to
minimum jest za małe: przy ciągu włącz → wyłącz → włącz trzecia zmiana
nadpisuje pierwszą. To ODWROTNE rozstrzygnięcie niż przy
`users.weekly_digest_sent_at` (tam pytanie naprawdę brzmi „kiedy ostatnio")
i nie ma tu sprzeczności — tutaj poprzednia wartość jest całym dowodem.
**JSONB odpada:** cztery zawsze te same pola z zamkniętymi zbiorami wartości
to dane strukturalne, a AGENTS.md §6 dopuszcza JSONB tylko dla
półstrukturalnych.

**Czego tu celowo nie ma: adresu IP i `User-Agent`.** Do wykazania zgody nie
są potrzebne — dowodem jest fakt, moment, cel i droga. Brak tych kolumn
pilnuje `DowodZgodyNaDigestTest` **asercją na pełną listę kolumn**, więc
oblewa się także wtedy, gdy ktoś doda kolumnę nazwaną neutralnie
(`kontekst`, `meta`) i włoży tam to samo.

**Append-only wymuszone przez bazę, nie przez intencje.** Wyzwalacze
`dziennik_zgod_bez_zmian` (`BEFORE UPDATE OR DELETE … FOR EACH ROW`)
i `dziennik_zgod_bez_czyszczenia` (`BEFORE TRUNCATE … FOR EACH STATEMENT`)
wołają funkcję `dziennik_zgod_tylko_dopisywanie()`, która rzuca wyjątek —
`RAISE EXCEPTION`, a nie reguła `DO INSTEAD NOTHING`, bo reguła połknęłaby
zmianę bez słowa. Model `App\Models\WpisZgody` blokuje `update`/`delete`
także po stronie PHP (czytelniejszy błąd dla programisty), ale to jest
pierwsza linia, nie jedyna: `DB::table('dziennik_zgod')->update(...)`
i ręczny `psql` jej nie widzą. `DROP TABLE` **nie** jest blokowany —
`migrate:refresh` w CI i `RefreshDatabase` w testach muszą działać.

**Usunięcie konta a dowód zgody — rozstrzygnięcie napięcia (D-072).** Konta
w Kuking się nie kasuje, tylko anonimizuje (D-022), więc:

- `EraseAccountData` **dopisuje** wiersz `wycofana` ze źródłem
  `usuniecie_konta` (domknięcie historii — inaczej dziennik kończyłby się na
  „udzielona" i wyglądałby na zgodę obowiązującą do dziś);
- **nic nie kasuje.** Po anonimizacji wiersz `users` nie ma adresu, hasła ani
  nazwy, więc `user_id` w dzienniku nie wskazuje na dane osobowe, a dowód
  podstawy prawnej wysyłki zostaje;
- FK ma `ON DELETE RESTRICT`, nie `CASCADE` (skasowałby dowód dokładnie wtedy,
  gdy jest potrzebny) i nie `SET NULL` (byłby `UPDATE` na tabeli append-only,
  a dowód niczyj to dowód żaden). Skutek uboczny, który trzeba nazwać: twardy
  `DELETE FROM users` dla konta, które kiedykolwiek ruszyło tę zgodę, odmówi
  wykonania. W serwisie nic takiego nie robi.

**Retencja: brak i jest to decyzja, nie przeoczenie** (D-072, punkt otwarty).
Dziennik zgód nie ma dziś komendy sprzątającej — dlatego nie ma też indeksu po
samym czasie. Wiersz to siedem krótkich pól na jedną zmianę zgody, więc tabela
rośnie wolniej niż `product_signals`. Docelowy okres należy dopisać do
`docs/decyzje/ADR_RETENCJE.md` razem z resztą dowodów zgód, przy przeglądzie
prawnym (issue #8).

**Rollback:** `php artisan migrate:rollback --step=1`. `down()` **ODMAWIA**,
gdy w dzienniku jest choć jeden zapis (D-088) — bo razem z tabelą znika jedyny
dowód na to, kto i kiedy wyraził zgodę, a boolean na `users` tego nie odtworzy.
Dla DZIAŁANIA serwisu skasowanie tej tabeli jest niegroźne (wysyłka nie czyta
jej ani razu) i właśnie dlatego było groźne dowodowo: po `down()` prawie zawsze
idzie kolejny `migrate`, tabela wraca pusta, serwis chodzi i **nie ma błędu do
zauważenia**.

Odmowa podaje liczbę zapisów, które by znikły, i drogę wyjścia — eksport poza
bazę (`\copy dziennik_zgod to 'dziennik_zgod.csv' csv header`). Na pustym
dzienniku, czyli na świeżym wdrożeniu, cofnięcie przechodzi bez pytania.
Skasowanie mimo wszystko wymaga wypowiedzenia tego wprost:

```
KUKING_ROLLBACK_KASUJ_DZIENNIK_ZGOD=true php artisan migrate:rollback --step=1
```

> Do 11 września ten akapit **ostrzegał**, a `down()` robił `dropIfExists` bez
> słowa. Ostrzeżenie w dokumencie nie jest zabezpieczeniem (issue #341).

Pilnuje tego `DowodZgodyNaDigestTest` (udzielenie, wycofanie, ciąg
włącz → wyłącz → włącz, trzy źródła, brak PII, append-only w modelu i w bazie,
usunięcie konta) oraz `CofniecieDziennikaZgodOdmawiaTest` (odmowa, kontrola
dodatnia na pustym dzienniku, zgoda wypowiedziana wprost, wąskość skutków).

### pending_email_changes
Zamówiona, ale **jeszcze nieobowiązująca** zmiana adresu e-mail (issue #195,
migracja `2026_09_09_300000_create_pending_email_changes_table`).

Do 9 września 2026 zalogowany człowiek nie widział własnego adresu **nigdzie**
w serwisie poza ekranem „Potwierdź e-mail" i paczką RODO — a reset hasła idzie
właśnie na ten adres. Literówka przy rejestracji znaczyła więc konto bez drogi
powrotu, i to od dnia, w którym poczta zaczęła realnie wysyłać listy. Prawo do
sprostowania danych (RODO art. 16) nie miało tu żadnej realizacji.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID. Trafia do podpisanego odnośnika w liście — **sam w sobie nie jest autoryzacją** (AGENTS.md §7), właściciela sprawdza kontroler osobno. |
| `user_id` | **UNIKALNY** — jedno oczekujące żądanie na konto. Nowe zastępuje poprzednie, więc odnośnik z wcześniejszego listu natychmiast przestaje działać. `cascadeOnDelete`. |
| `new_email` | Adres, na który konto ma się przenieść. **Bez indeksu unikalnego** — patrz niżej. |
| `created_at` | Kiedy zamówiono. |
| `expires_at` | Kiedy odnośnik przestaje działać: `config('kuking.account.email_change_ttl_hours')` (domyślnie 24 h) od zamówienia. |

```sql
ALTER TABLE pending_email_changes
ADD CONSTRAINT pending_email_changes_new_email_lower_check
CHECK (new_email = lower(new_email) AND new_email <> '');

ALTER TABLE pending_email_changes
ADD CONSTRAINT pending_email_changes_expires_after_created_check
CHECK (expires_at > created_at);
```

#### Dlaczego osobna tabela, a nie kolumny na `users`

Rozważana była druga droga (`users.pending_email` + `pending_email_expires_at`).
Odpadła z czterech powodów:

1. to nie jest cecha konta, tylko **żądanie z własnym życiorysem** — powstaje,
   wygasa, zostaje skasowane albo skonsumowane. Wiersz znikający w całości jest
   prostszy niż dwie kolumny, które trzeba wyzerować **razem**;
2. spójność za darmo: przy kolumnach trzeba by CHECK-a wiążącego ich
   nullowość (`num_nonnulls()`), tu warunek nie ma jak nie być spełniony;
3. `users` czyta **każde** żądanie zalogowanej osoby — nie dokładamy do niej
   kolumn, które w 99,9% wierszy są NULL-em;
4. minimalizacja danych: adres znika jednym `DELETE`, a nie `UPDATE`-em
   na najważniejszej tabeli w bazie.

#### Dlaczego `new_email` nie jest unikalny

Bo unikalny indeks tutaj zamieniłby formularz w wyrocznię „kto ma konto
w Kuking": odpowiedź „ten adres jest zajęty" da się wyklikać seriami.
Unikalność pilnuje `users_email_lower_unique` w chwili **potwierdzenia** —
czyli dowiaduje się o niej wyłącznie ten, kto czyta pocztę pod tym adresem
(`App\Domain\Users\Actions\ConfirmEmailChange`).

#### Co kasuje wiersz

Potwierdzenie, przycisk „Anuluj zmianę", **zmiana albo reset hasła** (bo list
ostrzegawczy do starego adresu radzi właśnie to i ta rada musi być prawdziwa),
kolejne żądanie tej samej osoby, anonimizacja konta (`EraseAccountData`) oraz
wygaśnięcie — `kuking:sprzataj-zmiany-adresu`, harmonogram codziennie o 04:40
(`App\Domain\Compliance\PrzedawnioneZmianyAdresu`).

Termin stoi w kolumnie, a **nie** jest liczony przy odczycie — dzięki temu nie
da się tu powtórzyć pułapki `subMonths()` kontra `subMonthsNoOverflow()`
z `PrzedawnionePowiadomienia` (A6-04): próg jest policzony raz, przy
zamówieniu, i od tego momentu jest faktem, a nie wynikiem arytmetyki na datach.
Sprzątanie **nie jest** bramką bezpieczeństwa — odnośnik przestaje działać co
do minuty dzięki `PendingEmailChange::jestWazne()`, a nie dzięki nocnej komendzie.

**Rollback:** `php artisan migrate:rollback --step=1` — `down()` kasuje tabelę.
Bezstratne dla kont: żaden wiersz `users` nie jest przez tę migrację dotykany,
żaden adres nie zmienia się w ani jedną stronę. Ginie tylko to, co było
w drodze — listy wysłane, ale jeszcze niepotwierdzone; kto kliknie taki
odnośnik, przeczyta „zamów zmianę jeszcze raz". Kolejność wycofywania:
**najpierw kod, potem migracja** — sam ekran `/ustawienia/e-mail` bez tabeli
odda 500.

### login_link_tokens
Jednorazowy token logowania linkiem e-mail — „magic link" (issue #25, D-056,
migracja `2026_09_10_100000_create_login_link_tokens_table`).

Ten wiersz **jest hasłem jednorazowym**: kto ma token, wchodzi na konto. Stąd
wszystko poniżej — jeden wiersz na konto, krótki termin, kasowanie przy użyciu
i skrót zamiast wartości.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID. **Nie trafia nigdzie** — link niesie sam token, nie identyfikator wiersza. |
| `user_id` | **UNIKALNY** — jeden ważny link na konto. Nowa prośba zastępuje poprzednią, więc link z wcześniejszego listu natychmiast przestaje działać. `cascadeOnDelete`. |
| `token_hash` | **UNIKALNY. HMAC-SHA256 tokenu** (`App\Support\Skrot`), nigdy token. Po tej kolumnie szukamy wiersza przy kliknięciu w link. |
| `created_at` | Kiedy poproszono. |
| `expires_at` | Kiedy link przestaje działać: `config('kuking.login_link.waznosc_minut')` (domyślnie 30 min) od prośby. |

```sql
ALTER TABLE login_link_tokens
ADD CONSTRAINT login_link_tokens_expires_after_created_check
CHECK (expires_at > created_at);

ALTER TABLE login_link_tokens
ADD CONSTRAINT login_link_tokens_token_hash_format_check
CHECK (token_hash ~ '^[0-9a-f]{64}$');
```

#### `UNIQUE (user_id)` wymaga serializacji, nie tylko constraintu (D-075)

Constraint pilnuje niezmiennika „jeden ważny link na konto", ale **sam nie
ustawia próśb w kolejce**. Dwie równoległe prośby o link na to samo konto
przechodziły obie `DELETE` (każda kasując zero wierszy) i obie szły do
`INSERT` — druga odbijała się o unikalność i, przed poprawką, kończyła się
**500**. A 500 zdarzało się wyłącznie tam, gdzie konto istnieje, czyli para
równoległych żądań stawała się wyrocznią „kto ma konto w Kuking".

Dlatego wymiana tokenu idzie pod **blokadą wiersza `users`**
(`WyslijLinkDoLogowania::wymienToken()`), branej PRZED `DELETE`, plus
defensywnym przechwyceniem konfliktu. Kolejność blokad w tym repozytorium
jest jedna i obowiązuje wszędzie: **konto najpierw**, potem tabela żądania
(tu `login_link_tokens`, przy zmianie adresu `pending_email_changes`). Kto
dopisze drugie miejsce zapisujące tę tabelę, musi wejść tą samą drogą; dwie
różne kolejności blokad to zakleszczenie, które PostgreSQL rozwiązuje
zabiciem jednego z żądań.

#### Dlaczego skrót szybki, a nie bcrypt jak w `password_reset_tokens`

Bo bcrypt istnieje po to, żeby spowolnić zgadywanie wartości o **niskiej
entropii** (hasło człowieka), a tu wartością jest 64 losowe znaki
z `Str::random()` — zgadywania nie ma czego spowalniać. Za to bcrypt
uniemożliwiłby wyszukanie wiersza po skrócie: trzeba by wstawić do adresu
w liście jeszcze identyfikator wiersza, czyli wynieść do poczty i do historii
przeglądarki jedną informację więcej bez żadnego zysku.

Drugi CHECK nie jest ozdobą: token z `Str::random()` ma wielkie litery, więc
**zapisany wprost łamie ten warunek i baza go odrzuci**. Dlatego token nie jest
szesnastkowy i nie wolno go na taki zmienić — zabrałoby to CHECK-owi całą
wartość.

#### Dlaczego osobna tabela, a nie kolumny na `users`

Ten sam wywód co przy `pending_email_changes`: to nie jest cecha konta, tylko
żądanie z własnym życiorysem; wiersz znikający w całości nie wymaga CHECK-a
wiążącego nullowość dwóch kolumn; `users` czyta każde żądanie zalogowanej
osoby i nie dokładamy do niej kolumn NULL-owych w 99,9% wierszy.

#### Czego w tej tabeli świadomie nie ma

**`used_at`** — zużyty link **znika** (`DELETE`), a nie zostaje oznaczony.
Wiersz po użyciu nie odpowiadałby na żadne pytanie, którego nie odpowiada wpis
`account.login_link_used` w `audit_log`, a byłby kolejnym miejscem, w którym
trzeba pamiętać o warunku „AND used_at IS NULL". Zapomnienie o takim warunku
znaczy link wielokrotnego użytku — czyli dokładnie ta usterka, przed którą ta
tabela ma bronić.

**Adresu IP i `user_agent`** — nie ma pytania, na które musiałyby odpowiedzieć,
a byłby to kolejny zbiór adresów IP w bazie (AGENTS.md §7). Fakt prośby notuje
`audit_log`, z adresem e-mail w skrócie i z adresem IP w haszu.

#### Co kasuje wiersz

Użycie linku, kolejna prośba tej samej osoby, **zmiana i reset hasła**,
**„wyloguj mnie z innych urządzeń"**, blokada, zawieszenie i zgłoszenie
usunięcia konta (wszystkie przez `User::invalidateSessions()` →
`invalidateLoginLinks()`) oraz wygaśnięcie — sprzątane przy okazji następnej
prośby o link (`WyslijLinkDoLogowania::posprzatajPrzedawnione()`), bez osobnej
komendy w harmonogramie: tabela mieści najwyżej jeden wiersz na konto i żyje
minutami.

Sprzątanie **nie jest** bramką bezpieczeństwa — link przestaje działać co do
minuty dzięki `LoginLinkToken::jestWazny()`, a nie dzięki `DELETE`.

**Rollback:** `php artisan migrate:rollback --step=1` — `down()` kasuje tabelę.
Bezstratne dla kont: migracja nie dotyka ani jednego wiersza `users`, nie
zmienia haseł i nie zamyka logowania hasłem. Ginie tylko to, co w drodze —
linki wysłane, a jeszcze niekliknięte; kto kliknie taki link po wycofaniu,
przeczyta „ten link już nie działa, poproś o nowy". Kolejność wycofywania:
**najpierw kod, potem migracja** — trasy `/logowanie/link` bez tabeli oddadzą
500. Samo wyłączenie funkcji migracji nie wymaga wcale:
`KUKING_LOGOWANIE_LINKIEM=false`.

### registration_invites
Zaproszenie do założenia konta dla adresu, na którym konta **nie ma** (D-085,
migracja `2026_09_10_400000_create_registration_invites_table`).

Powstaje wtedy, gdy ktoś poprosi o „link do zalogowania" dla adresu bez konta.
Ten wiersz **nie jest hasłem jednorazowym** — nie wpuszcza na żadne konto, bo
konta nie ma. Jest **dowodem dostępu do skrzynki**: kto kliknął link z tej
skrzynki, udowodnił, że jest jej właścicielem, więc adres wchodzi do rejestracji
jako już potwierdzony i drugiej wiadomości weryfikacyjnej nie wysyłamy. Stąd
termin dłuższy niż przy logowaniu linkiem (24 h kontra 30 min): poświadczenie
jest słabsze, a droga po jego kliknięciu dłuższa (cały formularz rejestracji).

| Kolumna | Uwagi |
|---|---|
| `id` | UUID. **Nie trafia do listu** — link niesie sam token. Trafia za to do SESJI po przyjęciu zaproszenia (`rejestracja.zaproszenie_id`), i to on, a nie pole formularza, rozstrzyga, na jaki adres powstaje konto. |
| `email` | **UNIKALNY.** Jedno ważne zaproszenie na adres — kolejna prośba zastępuje poprzednią. Zapisywany ZNORMALIZOWANY (małe litery), tak jak `users.email`, inaczej UNIQUE nic by nie pilnował. |
| `token_hash` | **UNIKALNY. HMAC-SHA256 tokenu** (`App\Support\Skrot`), nigdy token. Po tej kolumnie szukamy wiersza przy kliknięciu w link. |
| `created_at` | Kiedy wysłano zaproszenie. Bez `updated_at` — wiersza się nie edytuje. |
| `expires_at` | Kiedy link przestaje działać: `config('kuking.login_link.zaproszenia.waznosc_godzin')` (domyślnie 24 h) od wysłania. Indeksowana — chodzi po niej sprzątanie. |

```sql
ALTER TABLE registration_invites
ADD CONSTRAINT registration_invites_email_lower_check
CHECK (email = lower(email) AND email <> '');

ALTER TABLE registration_invites
ADD CONSTRAINT registration_invites_expires_after_created_check
CHECK (expires_at > created_at);

ALTER TABLE registration_invites
ADD CONSTRAINT registration_invites_token_hash_format_check
CHECK (token_hash ~ '^[0-9a-f]{64}$');
```

Ostatni CHECK nie jest ozdobą: token powstaje przez `Str::random(64)`, więc ma
wielkie litery — **zapisany wprost łamie ten warunek i baza go odrzuci**.
Dlatego token nie jest szesnastkowy i nie wolno go na taki zmienić.

#### `UNIQUE (email)` też wymaga przechwycenia konfliktu (D-085)

Ta sama lekcja co przy `login_link_tokens` i D-075, tylko bez konta, na którym
dałoby się postawić blokadę wiersza. Dwie równoległe prośby o ten sam adres bez
konta przechodziły oba `DELETE` (każda kasując zero wierszy) i obie szły do
`INSERT`; druga odbijała się o unikalność i kończyła **500**. A 500 zdarzało się
wyłącznie na ścieżce adresu BEZ konta — adres z kontem swój wyścig sprowadza do
302 (D-075) — czyli para równoległych żądań znowu odpowiadała na pytanie „kto ma
konto w Kuking", tylko odwrotnie niż przed D-075.

`SELECT ... FOR UPDATE` nie ma tu na czym stanąć (konta nie ma, a blokada
nieistniejącego wiersza nie blokuje niczego), więc zostaje druga połowa tamtej
konstrukcji: `WyslijZaproszenieDoRejestracji` **przechwytuje
`UniqueConstraintViolationException`** i oddaje tę samą neutralną odpowiedź co
każda inna odmowa, oddając przy okazji miejsce w dobowym suficie.

#### Co kasuje wiersz — pełna lista

1. **założenie konta** — `RegisterController::store()` kasuje wiersz w tej samej
   transakcji, w której powstaje konto, pod `lockForUpdate()`
   (`ZaproszenieWSesji::zuzyj()`). To jest cała jednorazowość tej drogi;
2. **kolejna prośba z tego samego adresu** — `email` jest unikalne;
3. **wygaśnięcie** — `kuking:sprzataj-zaproszenia` raz na dobę
   (`App\Domain\Compliance\PrzedawnioneZaproszenia`), plus sprzątanie przy
   okazji każdej prośby.

Sprzątanie **nie jest bramką bezpieczeństwa**: link przestaje działać co do
minuty dzięki `RegistrationInvite::jestWazne()`, a nie dzięki `DELETE`. Jest
higieną danych — i ważniejszą niż przy `login_link_tokens`, bo tutaj w wierszu
leży adres e-mail osoby, która **nie ma u nas konta** i nigdy nie musi mieć.

#### Wycofanie (rollback)

`down()` **odmawia**, dopóki w tabeli leży choć jedno WAŻNE zaproszenie — każde
z nich to człowiek, który ma w skrzynce wiadomość i jeszcze jej nie kliknął.
Dwie drogi wyjścia:

1. poczekać, aż zaproszenia wygasną (najwyżej 24 h), i wtedy
   `php artisan migrate:rollback` — wygasłe wiersze odmowy nie wywołują;
2. `php artisan kuking:sprzataj-zaproszenia --wszystkie` (pyta o potwierdzenie
   i mówi, ilu osób to dotyczy), a potem wycofać świadomie.

Wycofanie samej funkcji **nie wymaga wycofywania migracji**: wystarczy
`KUKING_ZAPROSZENIA_DO_REJESTRACJI=false`. Adres bez konta wraca wtedy do
zachowania z D-056, a linki będące w drodze dostają ekran po polsku.

### data_exports
Paczka ZIP z danymi jednego użytkownika (RODO art. 15 i 20), budowana w tle
przez `App\Jobs\GenerateUserExport` (migracja `2026_09_05_001100_create_data_exports_table`).

| Kolumna | Uwagi |
|---|---|
| `user_id` | Właściciel paczki. `cascadeOnDelete` — po usunięciu konta paczka i jej wpis nie mają już czego dotyczyć. |
| `status` | `queued` → `processing` → `ready` **albo** `failed`, docelowo `expired`. CHECK w bazie (`data_exports_status_check`). |
| `disk`, `object_key` | Gdzie leży gotowe archiwum — wypełniane dopiero przy `ready`. |
| `bytes` | Rozmiar gotowego pliku. |
| `completed_at` | Kiedy paczka była gotowa. |
| `expires_at` | Kiedy paczka przestaje być do pobrania — nie trzymamy w storage kopii całego konta bez końca; sprząta `App\Console\Commands\CleanUpDataExports`. |
| `failure_reason` | Patrz niżej — **kod, nie zdanie**. |

#### `failure_reason` — kod, nie wolny tekst (audyt W7-07)

Kolumna jest renderowana wprost na ekranie ustawień
(`resources/views/pages/settings/data.blade.php`), więc nie może zawierać
technicznego szczegółu wyjątku (SQLSTATE, ścieżka na dysku tymczasowym).
Trzyma jeden z zamkniętego zbioru kodów z `App\Models\DataExport::REASONS`
(ten sam wzorzec co `Report::REASONS`):

| Kod | Kiedy |
|---|---|
| `account_missing` | Konto zniknęło, zanim job zdążył zbudować paczkę. |
| `storage` | Zapis gotowej paczki do magazynu plików się nie udał. |
| `timeout` | Budowa paczki przekroczyła limit czasu joba (15 minut). |
| `unknown` | Worek na resztę — każda inna awaria. |

`DataExport::failureReasonLabel()` zamienia kod na zdanie po polsku (z adresem
kontaktowym z `config('kuking.community.contact_email')`) i **nigdy** nie
pokazuje surowego kodu ani starego wolnego tekstu — nieznany albo pusty kod
dostaje tekst spod `unknown`. Pełny `$e->getMessage()` zostaje wyłącznie
w logu aplikacji (`Log::warning` w `GenerateUserExport::handle()`).

Kolumna świadomie NIE ma CHECK-a ograniczającego ją do tych czterech
wartości — dokładnie jak `reports.reason` (patrz wyżej), które też jest
kodem z zamkniętym mapowaniem w PHP, a nie w bazie.

Migracja `2026_09_06_210000_convert_data_export_failure_reason_to_codes`
zamienia istniejące wiersze z wolnego tekstu na kody (backfill po dokładnym
dopasowaniu dwóch znanych literałów, reszta na `unknown`) i cofa się do
`NULL` — oryginalne komunikaty nigdy nie były tu źródłem prawdy i zostają
wyłącznie w logu.

Indeksy: `(user_id, created_at)` — lista paczek danego użytkownika
w kolejności; `data_exports_one_active_per_user` — patrz niżej.

#### `data_exports_one_active_per_user` — jeden AKTYWNY eksport na konto (D-078)

Migracja `2026_09_10_400100_one_active_data_export_per_user`, audyt
10.09.2026 ustalenia QUEUE-04 / RACE-05.

```sql
CREATE UNIQUE INDEX data_exports_one_active_per_user
    ON data_exports (user_id)
 WHERE status IN ('queued', 'processing');
```

**Co ten indeks naprawia.** `DataSettingsController::requestExport()` robił
`exists()` na stanach aktywnych, a potem OSOBNY `INSERT`. Między tymi dwoma
zapytaniami nie było nic: przy izolacji `read committed` dwa równoległe
żądania widzą „nie ma aktywnego eksportu" jednocześnie i oba wstawiają swój
wiersz. Skutkiem są DWA `GenerateUserExport` na jedno konto — czyli dwa razy
spakowane te same zdjęcia (15 minut limitu czasu, kolejka `low`, jeden
worker) i dwa listy z tego samego dobowego wiadra poczty. Wejściem jest
podwójne kliknięcie „Zamów swoje dane", a w grupie 60+ dwuklik jest
scenariuszem typowym.

`exists()` w PHP **zostaje** — daje spokojny komunikat („już przygotowujemy
Twoją paczkę"). Gwarancję daje indeks; konflikt jest w kontrolerze
przechwytywany (`UniqueConstraintViolationException`) i sprowadzany do tego
**samego** zdania, nigdy do 500. `lockForUpdate()` by tego nie naprawił:
`SELECT ... FOR UPDATE`, który nie zwrócił wiersza, nie blokuje niczego —
to wstawienie fantomu, nie konflikt na wierszu.

**Dlaczego indeks CZĘŚCIOWY.** Inwariant brzmi „jeden AKTYWNY", nie „jeden
w historii" — RODO art. 15 nie jest jednorazowe i ekran ustawień pokazuje
pięć ostatnich paczek. Wiersz wypada z indeksu, gdy job go domknie (`ready`,
`failed`) albo paczka wygaśnie (`expired`), i kolejne zamówienie znów
przechodzi.

**Migracja odmawia, gdy w tabeli już leżą dwa aktywne eksporty jednego
konta** — z komunikatem mówiącym, co zrobić (zostaw najstarszy aktywny
wiersz, nadmiarowe skasuj; gotowy `DELETE` stoi w komentarzu migracji).
Kasowanie jest tu bezpieczne, w odróżnieniu od `reports`: wiersz w stanie
aktywnym nie ma jeszcze `object_key` ani `disk` (brak osieroconego pliku),
`GenerateUserExport::handle()` przy braku wiersza po prostu wraca, a paczka
z pozostawionego wiersza jest bajt w bajt tą samą paczką.

**Rollback:** `DROP INDEX IF EXISTS`, bezstratnie — indeks nie przechowuje
niczego, czego nie ma w tabeli, i jego zdjęcie nie kasuje żadnego wiersza.
Po cofnięciu wraca stan sprzed zmiany: `exists()` łapie zwykły dwuklik, baza
nie broni niczego, a `catch` w kontrolerze jest gałęzią, w którą nic nie
wchodzi. Pilnują tego `JedenAktywnyEksportNaKontoTest`
i `Wyscigi\EksportDanychRaceTest`.

### product_signals

Sygnały produktowe (issue #115), migracja
`2026_09_06_220000_create_product_signals_table`. Dziś **cztery** zdarzenia:
`photo_upload_failed` (próba wgrania zdjęcia, która się nie udaje —
`App\Domain\Media\Actions\StoreUploadedImage`), `search_performed`
(wykonane wyszukiwanie — `App\Http\Controllers\SearchController`) oraz para
od tygodniowego podsumowania: `weekly_digest_queued` i
`weekly_digest_unsubscribed` (issue #11, D-057; migracja
`2026_09_10_100100_add_digest_signals_to_product_signals`). Jedyne miejsce,
które tu pisze: `App\Domain\Analytics\ZapiszSygnal`.

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
| `user_id` | Nullable, `nullOnDelete()`. Anonimizacja konta (`EraseAccountData`, D-018) NIE kasuje wiersza — sygnał ma wartość niezależnie od tego, kto go wywołał — ale referencja do usuniętego konta znika razem z nim. |
| `signal_name` | `photo_upload_failed` \| `search_performed` \| `weekly_digest_queued` \| `weekly_digest_unsubscribed`. CHECK w bazie (`product_signals_signal_name_check`) — zamknięty zbiór, tak jak `reports.status`. |
| `properties` | `jsonb`. Dla `photo_upload_failed`: `reason` (patrz niżej) i gdzie to ma sens liczby (`bytes`, `max_bytes`, `megapixels`) — NIGDY nazwa pliku. Dla `search_performed`: **wyłącznie** `query_length` (int) i `has_results` (bool) — **nigdy** `query_text`. Drugi CHECK w bazie (`product_signals_no_query_text_check`, przez `jsonb_exists()`) odrzuca każdy wiersz, w którym klucz `query_text` w ogóle by się pojawił, niezależnie od tego, co akurat pisze kod aplikacji. Dla `weekly_digest_queued`: **wyłącznie liczby** — `wykonania`, `nowi_obserwujacy`, `wpisy` (ile pozycji miała każda sekcja listu), żeby dało się zobaczyć, czy listy nie robią się cienkie. Bez adresu, bez nazw, bez tytułów. Dla `weekly_digest_unsubscribed`: `properties` jest PUSTE — sam fakt i `user_id` wystarczą do progu wypisów. |
| `occurred_at` | `timestamptz`, `useCurrent()`. **SPROSTOWANIE (D-078):** wcześniej stało tu, że dla `weekly_digest_sent` kolumna jest czytana JAKO LICZNIK dobowego limitu poczty. Nieprawda — sprawdzone w kodzie: dobowy sufit liczy `App\Domain\Security\DziennyBudzetListow`, a ten trzyma licznik w **cache**, nie w tej tabeli, i nie sięga do `product_signals` ani razu. Ta kolumna służy dziś wyłącznie retencji (`kuking:sprzataj-sygnaly`) i porządkowaniu w czasie. |

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

**Retencja:** `config('kuking.analytics.signal_retention_days')` (domyślnie
90 dni), egzekwowana przez `kuking:sprzataj-sygnaly`
(`App\Domain\Analytics\PrzedawnioneSygnaly`), harmonogram codziennie o 04:00
(`routes/console.php`). Zwykły masowy `DELETE ... WHERE occurred_at < ?` —
bez `chunkById`, bo wiersz nie ma odpowiednika po stronie storage (w
odróżnieniu od `OsieroconeZdjecia`).

**Zapis sygnału nigdy nie wywraca operacji, którą opisuje:**
`ZapiszSygnal::handle()` łapie każdy wyjątek i tylko go loguje
(`Log::warning`) — wyszukiwarka ma pokazać wyniki, a komunikat o nieudanym
wgraniu zdjęcia ma dojść do człowieka, nawet gdy zapis wiersza akurat się nie
uda.

**Rollback:** `DROP TABLE product_signals` bez zastrzeżeń — to są dane
telemetryczne, nie dane, na podstawie których podjęto decyzję.

### tags + tag_aliases + post_tags + tag_follows + tag_promotions

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
| `merged_into_tag_id` | Nullable, self-FK **bez `ON DELETE`** — domyślne `NO ACTION` Postgresa blokuje skasowanie tagu kanonicznego, dopóki są do niego przypięte tagi scalone. Dodatkowy CHECK `(status='merged') = (merged_into_tag_id IS NOT NULL)`. |
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

Indeksy trigramowe (Postgres, na `kuking_normalize()` z migracji
`2026_09_05_001300_fix_search_indexes`): `tags_name_trgm_idx`,
`tag_aliases_alias_trgm_idx` — używane przez `App\Domain\Tags\TagSuggester`
(SPEC §1.5: prefiks → alias dokładny → podobieństwo trigramowe →
popularność liczona `withCount('posts')`, bez utrzymywanego ręcznie licznika).

**Rollback:** `DROP TABLE` w kolejności `tag_promotions`, `tag_follows`,
`post_tags`, `tag_aliases`, `tags` — bezpieczne bez zastrzeżeń, to jest
funkcja budowana od zera przy zerowym ruchu produkcyjnym (D-021: „0 tematów,
0 wpisów z tematem" w chwili decyzji, a tagi jeszcze nie istniały).

### contact_messages

Wiadomości z formularza **„Napisz do nas"** (`/napisz-do-nas`), migracja
`2026_09_09_100000_create_contact_messages_table`. Kontakt z **operatorem
serwisu**: „coś nie działa", „mam pomysł", „chcę wam coś powiedzieć".

**To NIE JEST `reports` i dlatego nie jest w `reports`.** Różnica nie jest
kosmetyczna:

| | `reports` | `contact_messages` |
|---|---|---|
| Czego dotyczy | cudzej treści (`target_type` + `target_id`) | działania serwisu |
| Czym się kończy | decyzją moderatora w `moderation_actions` | odpowiedzią człowieka albo poprawką w kodzie |
| Odwołanie | tak, DSA art. 20, `appeals` | nie ma od czego |
| Retencja | **36 miesięcy** od zamknięcia sprawy | **12 miesięcy** od załatwienia |
| Kolejka w panelu | `/admin/zgloszenia`, najnowsze na górze | `/admin/wiadomosci`, **najstarsze na górze** |

Wrzucenie jednego w drugie kosztuje w obie strony: skarga na wpis w tej
tabeli nigdy nie dostanie decyzji, a opis awarii w `reports` zapycha
najwęższe gardło serwisu (D-012 — zespół to 1–2 osoby) i żyje w bazie trzy
razy dłużej, niż potrzeba. Pilnuje tego
`tests/Feature/WiadomosciDoOperatoraSaOddzielneOdZgloszenTest.php`.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID, `gen_random_uuid()` — wiersz jest adresowany z zewnątrz (`/admin/wiadomosci/{id}`), więc nie `bigserial`. |
| `user_id` | Nullable, `nullOnDelete()`. `NULL` znaczy „gość bez konta" **albo** „konto usunięte" — wiadomość zostaje, bo może być w trakcie załatwiania. |
| `klucz_wyslania` | Tożsamość jednego wysłania formularza (D-027). Częściowy `UNIQUE` `contact_messages_one_per_klucz_wyslania` `WHERE klucz_wyslania IS NOT NULL` — wyłącznik `kuking.formularze.klucz_wyslania_wlaczony` zdejmuje mechanizm, wpisując `NULL`. |
| `kind` | `blad` \| `pomysl` \| `inne`. CHECK w bazie (`contact_messages_kind_check`). **Świadomie rozłączne z `Report::REASONS`** — gdyby tu było „Mowa nienawiści", ludzie zgłaszaliby sąsiada formularzem technicznym. |
| `message` | `text`, nie `string`: to jedyne miejsce, gdzie człowiek OPISUJE awarię. Górną granicę (5000 znaków) trzyma walidacja; w bazie stoi CHECK `contact_messages_message_not_blank`, żeby nie dało się zapisać samych spacji. |
| `contact_email` | Tylko dla GOŚCIA. Dla zalogowanego zostaje `NULL` — jego adres jest już na koncie, a kopiowanie go tutaj byłoby powielaniem danych osobowych bez powodu (RODO, minimalizacja). Odpowiedni adres podaje `ContactMessage::adresDoOdpowiedzi()`. |
| `page_path` | **Sama ścieżka z naszego serwisu**, bez domeny, bez parametrów zapytania i bez fragmentu. Kontroler przycina (`NapiszDoNasController::oczyscSciezke()`): adres z cudzego serwisu i fraza wyszukiwania w `?q=` do bazy nie trafiają. |
| `wydanie` | `App\Support\Wersja::opisWydania()` w chwili wysłania. Nie jest daną osobową — to numer naszej wersji, i przy „u mnie nie działa" połowa diagnozy. |
| `status` | `new` \| `in_progress` \| `done`, CHECK `contact_messages_status_check`. **Nie ma go w `$fillable`** — ta sama zasada, co dla `status` i `role` użytkownika (AGENTS.md §7). Jedyna droga zmiany: `ContactMessage::oznaczJako()`. |
| `handled_by`, `handled_at` | Kto i kiedy. CHECK `contact_messages_handled_complete` (przez `num_nonnulls()`) wymusza komplet: status inny niż `new` MUSI mieć oba, a `new` — żadnego. Bez tego retencja nie miałaby od czego liczyć i wiersz zostawałby w bazie na zawsze. |
| `handler_note` | Notatka operatora, widoczna wyłącznie w panelu. |

Indeksy: `contact_messages_status_created_idx (status, created_at, id)` —
kolejka; `contact_messages_handled_at_idx (handled_at) WHERE handled_at IS
NOT NULL` — nocna retencja.

**Retencja:** `config('kuking.kontakt.retention_months')` (domyślnie 12)
miesięcy od `handled_at`, komenda `kuking:sprzataj-wiadomosci`, harmonogram
04:40 (`routes/console.php`). Wiadomość **otwarta nie jest kasowana nigdy**,
niezależnie od wieku — ta sama zasada, co przy otwartych sprawach
moderacyjnych: kasowanie tego, czego nikt nie przeczytał, byłoby sprzątaniem
dowodu zaniedbania, nie ochroną danych. Próg liczy
`subMonthsNoOverflow()`, nie `subMonths()` (A6-04).

**Rollback:** `DROP TABLE contact_messages` — migracja nie rusza żadnej
innej tabeli, więc cofnięcie nie może uszkodzić niczego poza tym, co samo
utworzyło (sprawdza to `CofniecieMigracjiWiadomosciTest`). Znika formularz
i ekran w panelu, zostaje adres e-mail w stopce, czyli stan sprzed zmiany.
**Strata jest jednak NIEODWRACALNA** — w tabeli leżą zdania napisane przez
ludzi. Przed cofnięciem na czymkolwiek z prawdziwym ruchem:
`pg_dump --data-only --table=contact_messages > wiadomosci.sql`.

### contact_message_replies

Odpowiedzi operatora na wiadomości z „Napisz do nas", migracja
`2026_09_10_200000_create_contact_message_replies_table` (**D-058**).
Do 10 września 2026 panel miał tu wyłącznie odnośnik `mailto:` — odpisywało
się z własnego programu poczty, a w serwisie nie zostawał żaden ślad, że
odpowiedź poszła.

**Osobna tabela, nie trzy kolumny w `contact_messages`**, z dwóch powodów.
Pierwszy: odpowiedź nie jest jedna („sprawdzamy" dziś, „naprawione" w piątek),
a kolumna na wierszu wiadomości kazałaby drugą odpowiedź albo nadpisać, albo
uniemożliwić. Drugi, ważniejszy: **każdy list ma własny stan wysyłki** —
pierwszy mógł nie wyjść, drugi wyjść, i jedna kolumna nie ma jak tego
opowiedzieć.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID, `gen_random_uuid()`. |
| `contact_message_id` | **`ON DELETE CASCADE`** i to jest wymóg RODO, nie wygoda: retencja (`kuking:sprzataj-wiadomosci`) robi masowy `DELETE` na `contact_messages`, omijając modele. Bez kaskady W BAZIE odpowiedzi zostałyby sierotami, których nic już nigdy nie usunie. |
| `author_id` | Moderator, który wysłał. `nullOnDelete()` — konto może zniknąć, fakt wysłania zostaje (ekran pokazuje wtedy „obsługa Kuking"). |
| `body` | Treść listu, dokładnie ta, którą dostał człowiek. `text`; górną granicę (5000 znaków, tyle samo co wiadomość) trzyma walidacja, w bazie stoi CHECK `contact_message_replies_body_not_blank`. |
| `status` | `w_toku` \| `wyslana` \| `nieudana`, CHECK `contact_message_replies_status_check`. **Nie ma go w `$fillable`** — ustawia go wyłącznie `App\Domain\Contact\Actions\WyslijOdpowiedz`, po tym jak dostawca poczty coś powiedział. `w_toku` zapisujemy PRZED wysyłką, żeby przerwanie procesu zostawiło „nie wiadomo, czy wyszło", a nie ciszę. |
| `sent_at` | Kiedy dostawca potwierdził przyjęcie. CHECK `contact_message_replies_sent_complete` wiąże to ze stanem: `wyslana` MUSI mieć `sent_at`, każdy inny stan NIE MOŻE go mieć. |
| `error` | Powód odmowy, przepuszczony przez redakcję adresów (`WyslijOdpowiedz::bezpiecznyPowod()` — ta sama lekcja co audyt A6-01). Ma odpowiadać moderatorowi na pytanie „co teraz zrobić", nie przechowywać cudzych danych. |

**Czego tu świadomie NIE MA: adresu, na który list poszedł.** Adres jest już
w bazie raz — `contact_messages.contact_email` (gość) albo `users.email`
(konto) — i wskazuje go `ContactMessage::adresDoOdpowiedzi()`. Trzecia kopia
tej samej danej osobowej przeżywałaby anonimizację konta i zamieniłaby wiersz
techniczny w mały zbiór adresów e-mail. Ta sama minimalizacja, dla której
`contact_email` jest `NULL` u zalogowanego.

Indeks: `contact_message_replies_message_idx (contact_message_id, created_at,
id)` — historia jednej sprawy, najstarsza odpowiedź na górze.

**Retencja:** własnej nie ma i nie potrzebuje. Odpowiedzi znikają razem
z wiadomością (kaskada wyżej), czyli 12 miesięcy od jej załatwienia —
pilnuje tego `OdpowiedzNaWiadomoscDoNasTest::test_odpowiedzi_znikaja_razem_z_wiadomoscia_przy_retencji`.

**Rollback:** `DROP TABLE contact_message_replies` — nie rusza żadnej innej
tabeli. **Kolejność ma znaczenie:** `contact_messages` nie da się cofnąć,
dopóki ta tabela stoi (PostgreSQL odmawia `DROP TABLE` z zależnym kluczem
obcym, `SQLSTATE 2BP01`), więc rollback idzie od najnowszej migracji —
tak jak `php artisan migrate:rollback`. Po cofnięciu znika formularz
odpowiedzi w panelu i wraca stan sprzed zmiany (`mailto:` na karcie
wiadomości); same wiadomości zostają nietknięte. **Strata jest jednak
NIEODWRACALNA** — w tabeli leżą listy, które naprawdę poszły do ludzi.
Przed cofnięciem na czymkolwiek z prawdziwym ruchem:
`pg_dump --data-only --table=contact_message_replies > odpowiedzi.sql`.

### weekly_digest_sends

Trwały klucz idempotencji tygodniowego podsumowania: **jeden list na parę
(osoba, tydzień)**, pilnowany przez bazę. Migracja
`2026_09_10_400000_create_weekly_digest_sends_table`, audyt 10.09.2026
QUEUE-01 / MAIL-02 / RACE-04, **D-077**.

| Kolumna | Uwagi |
|---|---|
| `user_id` | Kogo dotyczy. `cascadeOnDelete` — druga linia, nie pierwsza: kont z Kuking się nie kasuje, tylko anonimizuje (D-022). |
| `week_start` | **DATA PONIEDZIAŁKU** tygodnia, za który poszedł list, liczona w strefie człowieka (`App\Support\Czas::poczatekTygodniaData()`). Nie numer tygodnia ISO. |
| `reserved_at` | Kiedy zajęto klucz. **Nie** „kiedy list doszedł" — tego Kuking nie wie i nie ma się dowiadywać (otwarta sprawa #204: żadnego śledzenia doręczeń ani otwarć). |

```sql
CREATE TABLE weekly_digest_sends (
    user_id     uuid  NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    week_start  date  NOT NULL,
    reserved_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, week_start)
);

ALTER TABLE weekly_digest_sends
ADD CONSTRAINT weekly_digest_sends_week_start_monday_check
CHECK (extract(isodow from week_start) = 1);
```

#### Po co, skoro jest już `users.weekly_digest_sent_at`

Bo tamten znacznik jest **porównaniem w PHP**, a nie barierą. Do 10 września
komenda wysyłkowa robiła dla każdej osoby: `Mail::queue()` → budżet → sygnał,
a znacznik stawiała **jednym zapytaniem po całej pętli**. Awaria pomiędzy
zostawiała do sześćdziesięciu listów w kolejce i **zero** śladu w bazie, więc
następny przebieg pisał do tych samych osób drugi raz.
`withoutOverlapping()` z harmonogramu tego nie łapie: chroni przed dwoma
przebiegami JEDNOCZEŚNIE, nie przed kolejnym przebiegiem PO awarii.

Kolejność jest teraz odwrotna: **wiersz rezerwacji, potem list.** `INSERT`
i znacznik `weekly_digest_sent_at` idą w JEDNEJ transakcji
(`OdbiorcyDigestu::zarezerwuj()`), a `Mail::queue()` wykonuje się tylko wtedy,
gdy `INSERT` się udał. Konflikt unikalności znaczy „ta osoba ma ten okres
obsłużony" i wtedy po prostu ją pomijamy — bez błędu i bez listu.

Obie warstwy są potrzebne i mówią różne rzeczy:

- `weekly_digest_sends` → **najwyżej jeden list na tydzień kalendarzowy**,
  bez luki między odczytem a zapisem (czyli także przy dwóch przebiegach
  równolegle — RACE-04);
- `users.weekly_digest_sent_at` → **nie częściej niż raz na siedem dni**
  plus kolejność „kto czeka najdłużej"; sam tydzień kalendarzowy pozwoliłby
  na list w niedzielę i w poniedziałek.

Kalendarzowy tydzień nikogo nie opóźnia: dzień `x` i dzień `x + 7` zawsze
mają różne poniedziałki, więc bariera nie blokuje wysyłki, na którą odstęp
już pozwala.

#### Dlaczego data poniedziałku, a nie numer tygodnia ISO

Numer tygodnia sam z siebie nie jest identyfikatorem: `2026-12-28` należy do
tygodnia 1 **roku 2027**, więc numer wymagałby pary (rok ISO, tydzień) —
a klucz idempotencji zapisany niepełny przestaje być unikalny. Data
poniedziałku to jedna kolumna `date`: porównywalna, sortowalna, czytelna
w zrzucie bazy i zgodna z tym, co w PostgreSQL znaczy `date_trunc('week', …)`.

Strefa nie jest ozdobą: poniedziałek UTC zaczyna się w Polsce w niedzielę
o 22:00, więc przebieg uruchomiony w poniedziałek nad ranem trafiałby do
tygodnia poprzedniego. Stąd `Czas`, nie `now()`.

CHECK „to musi być poniedziałek" pilnuje, żeby klucz nadal ZNACZYŁ tydzień.
Data ze środka tygodnia dałaby tej samej osobie dwa różne, oba wolne klucze
w jednym tygodniu — czyli dwa listy przy nietkniętym `UNIQUE`.

#### Co się dzieje, gdy rezerwacja się udała, a wysyłka padła

**Rezerwacja zostaje i ta osoba nie dostaje listu za ten tydzień.** To jest
wybrana strona pomyłki, nie przeoczenie: po wyjściu z `Mail::queue()` nie da
się odróżnić „wiadomość nie weszła do kolejki" od „weszła, a proces padł
sekundę później", więc nie można mieć naraz „nikt nie dostanie dwa razy"
i „nikt nie zostanie pominięty". Uzasadnienie wyboru: **D-077**.

#### Czego tu świadomie nie ma

Treści listu, adresu, liczników, śladu doręczenia. Wiersz mówi wyłącznie
„ta osoba ma ten tydzień obsłużony" — ta sama klasa faktu co
`users.weekly_digest_sent_at`, więc bez nowej kategorii danych osobowych.
Nie ma też retencji ani komendy sprzątającej: przy przepustowości 420 osób
tygodniowo (D-057) to około 22 tysiące wierszy po dwóch kolumnach na rok.

**Rollback.** `down()` kasuje tabelę. Nie ginie ani jedno słowo od człowieka
i nie ginie pamięć o wysyłce (`users.weekly_digest_sent_at` zostaje) — ale
**ginie bariera**, a to trzeba nazwać wprost: po wycofaniu jedyną ochroną
przed drugim listem zostaje porównanie w PHP, czyli dokładnie ten mechanizm,
którego luka jest powodem tej migracji. Dlatego rollback robi się
**wyłącznie razem z `KUKING_DIGEST_WLACZONY=false`**, nigdy „przy okazji".
Kolejność: **najpierw kod, potem migracja** — nowy kod bez tabeli pada na
pierwszej osobie i nie wysyła nikomu nic (kierunek awarii bezpieczny, ale
wysyłka staje).

Pilnuje tego `tests/Feature/DigestNieWysylaDwaRazyTest.php` (awaria w połowie
przebiegu, bariera bez znacznika odstępu, kontrola dodatnia, następny
tydzień, brak zgody, oba ograniczenia bazy osobno).

### mail_failures

Listy, które **nie wyszły i już nie wyjdą**, migracja
`2026_09_10_500000_create_mail_failures_table` (**D-062**, issue #234).
Do 10 września 2026 odmowa dostawcy kończyła się tak: trzy próby workera
(`--tries=3 --backoff=10,60,300`, czyli około sześciu minut), wiersz
w `failed_jobs` — i cisza. Adresat nie dowiadywał się nigdy, właściciel
tylko wtedy, gdy sam z siebie zajrzał w `php artisan queue:failed`. Kolejka
pusta, `/health` zielony: **awaria wyglądała identycznie jak sukces**,
a dotyczyło to potwierdzeń rejestracji, przypomnień hasła i logowania linkiem.

**Osobna tabela, nie `failed_jobs`.** Tamta trzyma wszystkie nieudane
zadania (zdjęcia, eksporty, analizy), nie ma miejsca na kategorię odmowy
(„wyczerpany limit" ≠ „zły adres"), znika przy `queue:retry`/`queue:flush`
i nie da się w niej niczego odhaczyć. Ta tabela **nie dubluje** tamtej —
wskazuje na nią kolumną `failed_job_uuid`.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID, `gen_random_uuid()`. |
| `failed_job_uuid` | Wskaźnik na `failed_jobs.uuid`, **UNIKALNY** (jedno przepadnięcie = jeden wiersz, także gdy zdarzenie `JobFailed` dojdzie dwa razy). **Bez klucza obcego świadomie:** `queue:retry` kasuje tamten wiersz, a ten ma zostać. `NULL` przy wysyłce bez kolejki (tryb `sync`, konsola, testy). |
| `powod` | Kategoria z `App\Poczta\PowodOdmowy`: `limit_dobowy` \| `przejsciowa` \| `trwala` \| `nieznana`, CHECK `mail_failures_powod_check`. Ustala ją transport w chwili odmowy, bo tylko on widzi kod HTTP dostawcy. |
| `status_http` | Kod odpowiedzi dostawcy, CHECK `mail_failures_status_http_check` (100–599). `NULL`, gdy nie odpowiedział w ogóle (zerwane połączenie). |
| `rodzaj` | `displayName` z payloadu, czyli **klasa powiadomienia** (`App\Notifications\PotwierdzenieAdresu`). To ona mówi, CO przepadło. |
| `kolejka` | `high` \| `default` \| `low`. |
| `prob` | Ile prób wykonał worker (na produkcji 3, w trybie `sync` 1), CHECK `mail_failures_prob_check`. |
| `user_id` | **KTO CZEKAŁ NA LIST**, `nullOnDelete()`. Najważniejsza kolumna dla właściciela: w grupie 50+ osoba bez potwierdzenia nie napisze reklamacji, tylko odejdzie. Ustalane „best effort" z payloadu — `NULL` jest poprawnym wynikiem. |
| `komunikat` | Powód po redakcji (`App\Poczta\BezpiecznyKomunikat`): jedna linia, bez adresów e-mail, przycięta. |
| `failed_at` | Kiedy list przepadł. Zapisane wprost, nie jako `created_at` — wiersz opisuje zdarzenie, nie encję (stąd brak `timestampsTz()`). |
| `zauwazony_at` | „Właściciel to przeczytał" (`kuking:nieudane-listy --odhacz`). Dopóki `NULL`, `/health` zgłasza `degraded`. Jedyna kolumna, którą się tu aktualizuje. CHECK `mail_failures_zauwazony_po_awarii_check`: nie może być wcześniejsze niż `failed_at`. |

**Czego tu świadomie NIE MA: adresu odbiorcy, tematu ani treści listu.**
Wszystko to jest w payloadzie zadania, który pokazuje `php artisan
queue:failed`; druga kopia adresu w bazie to druga rzecz do skasowania przy
żądaniu RODO (AGENTS.md §7). Nie ma też kolumny „powiadomiono właściciela" —
alarmu pocztą o awarii poczty świadomie nie wysyłamy (D-062 §3).

Indeksy (oba **częściowe**, bo oba zapytania i tak filtrują):
`mail_failures_nieodhaczone_idx (failed_at DESC) WHERE zauwazony_at IS NULL`
— jedyne zapytanie chodzące w żądaniu HTTP (`/health`), oraz
`mail_failures_adresat_idx (user_id, rodzaj, failed_at DESC) WHERE user_id IS
NOT NULL` — pod ekran „Potwierdź adres e-mail".

**Retencja:** `kuking.poczta.retencja_dni` (domyślnie 90) i **tylko dla
wierszy ODHACZONYCH** — sprząta je `App\Poczta\ZapiszNieudanyList` przy
okazji zapisu następnej awarii, bez osobnego zadania w harmonogramie (jedno
`DELETE` na zdarzenie, które w zdrowym tygodniu nie zachodzi ani razu).
**Nieodhaczonych nie kasuje nic i nigdy**: to jedyne miejsce, w którym
istnieje wiedza o tym, że komuś nie doszedł list, a wiek jej nie unieważnia.
Wiersz nie niesie danych osobowych, więc nie jest to termin z RODO, tylko
higiena.

**Rollback:** `php artisan migrate:rollback --step=1` — `down()`
**ODMAWIA**, jeśli w tabeli leży choć jeden nieodhaczony wiersz, i mówi, co
zrobić (przeczytać, odhaczyć, powtórzyć). Powód: skasowanie tabeli razem
z taką informacją byłoby powtórzeniem dokładnie tej usterki, którą ta
migracja naprawia. Wiersze odhaczone giną razem z tabelą i to jest
w porządku — właściciel je przeczytał, a `failed_jobs` i panel dostawcy
zostają. **Kolejność wycofywania: NAJPIERW KOD, POTEM MIGRACJA** — inaczej
`/health`, `kuking:nieudane-listy` i słuchacz kolejki stoją przy
nieistniejącej tabeli (sonda zgłasza wtedy `slad_listow_niesprawdzalny`,
a słuchacz zapisuje porażkę do dziennika i milczy dalej, żeby nie zabrać
`failed_jobs` ostatniego zapisu).

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

Indeksy na tej funkcji zostały już tylko dla `ingredients` (normalized_name).
Reszta stoi na kolumnach generowanych — patrz niżej.

## Kolumny `*_search` — znormalizowany tekst leży w tabeli

Migracja `2026_09_09_100000_materialize_search_columns` (issue #116) dokłada
sześć kolumn `GENERATED ALWAYS AS (public.kuking_normalize(...)) STORED`
i przenosi na nie indeksy GIN (nazwy indeksów bez zmian):

| tabela | kolumna generowana | liczona z | indeks |
|---|---|---|---|
| `recipes` | `title_search` | `title` | `recipes_title_trgm_idx` |
| `recipes` | `summary_search` | `coalesce(summary, '')` | `recipes_summary_trgm_idx` |
| `recipe_ingredients` | `ingredient_text_search` | `ingredient_text` | `recipe_ingredients_text_trgm_idx` |
| `profiles` | `display_name_search` | `display_name` | `profiles_display_name_trgm_idx` |
| `profiles` | `username_search` | `username` | `profiles_username_trgm_idx` |
| `profiles` | `speciality_search` | `coalesce(speciality, '')` | `profiles_speciality_trgm_idx` |

**Po co, skoro indeks na wyrażeniu działał.** Bo działał tylko do połowy.
Indeks GIN dla operatora `%` jest **stratny**: oddaje kandydatów, których
PostgreSQL sprawdza jeszcze raz na wierszu tabeli. Przy progu podobieństwa
0,12 (`App\Support\ProgPodobienstwa`) kandydatów jest 35–60% tabeli, a każdy
recheck liczył `unaccent()` po słowniku od nowa. Zmierzone na 10 000 kont /
40 000 przepisów: `SearchQuery::recipes('pierogi')` 160 ms → 119 ms, a sama
gałąź trigramowa 118,8 → 81,8 ms przy **identycznym** zbiorze wyników.
Pełny pomiar, plany zapytań i to, czego ta zmiana NIE naprawia:
`docs/research/WYDAJNOSC.md` §3.4a.

**AKTUALIZACJA 9 września 2026 (issue #187): tych kolumn i indeksów używa dziś
INNY OPERATOR.** Wyszukiwarka i podpowiedzi tagów pytają operatorem `<%`
(`word_similarity`, próg **0,5**, `App\Support\ProgPodobienstwa`), a nie `%`
z progiem 0,12. Powód jest produktowy, nie kosztowy: `%` mierzy podobieństwo
frazy do CAŁEGO tytułu, więc przy tak niskim progu „rosół" znajdował
„Rogaliki", a „sajgonki z krewetkami" — 1 526 wierszy w bazie bez jednej
sajgonki. **Schemat się przez to nie zmienił i nie było migracji:**
`gin_trgm_ops` obsługuje oba operatory tym samym indeksem (dla `<%` przez
komutator `%>`, widać to w `Index Cond`). Zmieniło się natomiast to, co
indeks oddaje: przy `%` 12–20 tysięcy kandydatów na frazę i recheck
odrzucający 90% z nich, przy `<%` tyle kandydatów, ile trafień. Pomiar,
tabela zgubionych trafień i uzasadnienie progu: `docs/research/WYDAJNOSC.md`
§3.4b. Pilnuje tego `TrafnoscWyszukiwarkiTest`.

**Dlaczego kolumna generowana, a nie zwykła + trigger.** Kolumny generowanej
nie da się rozjechać ze źródłem: nie ma do niej drogi zapisu. Trigger da się
wyłączyć, a `UPDATE` z pominięciem triggera zostawiłby wyszukiwarkę szukającą
po starym tytule — usterkę widoczną dopiero wtedy, gdy ktoś nie znajdzie
własnego przepisu. Pilnuje tego `KolumnySzukaniaTest`.

⚠️ **Konsekwencja mocniejsza niż przy indeksie na wyrażeniu:** podmiana
słownika `unaccent` wymaga tu nie `REINDEX`, tylko przeliczenia kolumn
(`ALTER TABLE ... ALTER COLUMN ... DROP EXPRESSION` i dodanie od nowa).
Nie robimy tego.

**Rollback:** `down()` odtwarza indeksy na wyrażeniu i kasuje kolumny —
dokładny stan sprzed migracji, bezstratnie (kolumny są wyliczone z danych,
które zostają). Kosztuje przepisanie trzech tabel pod `ACCESS EXCLUSIVE`,
tak samo jak `up()`; na 40 000 / 80 000 / 10 000 wierszy trwało to ~6 s.
Sprawdza to `KolumnySzukaniaTest::test_cofniecie_migracji_odtwarza_indeksy_na_wyrazeniu`.

## `daily_picks`

Wybór redakcyjny na tablicę „kuKINGi na dziś". Świadomie bez kolumny
z punktami, liczbą polubień ani wynikiem — to nie jest tabela rankingowa
(patrz `../AGENTS.md` §8).

- `shown_on date NOT NULL` — DZIEŃ, na który wskazanie obowiązuje, a nie
  data wpisania. Wybór na jutro da się przygotować dziś;
- `daily_picks.subject_type varchar(20) NOT NULL` (CHECK: `user` \| `post`)
  + `subject_id uuid NOT NULL` — para „typ + identyfikator" bez klucza obcego,
  bo tablica pokazuje dwie różne rzeczy: konto i wpis (`DailyPick::TYPE_USER`,
  `TYPE_POST`);
- `position smallint NOT NULL DEFAULT 0` (CHECK `>= 0`) — kolejność na
  tablicy, ustawiana ręcznie przez gospodarza;
- `curator_id uuid NULL` → `users` (`ON DELETE SET NULL`) — kto wskazał;
- `daily_picks.note varchar(300) NULL` — zdanie gospodarza przy wskazaniu.
  **Kolumna jest ŻYWA i widoczna dla człowieka.** Zapisuje ją formularz panelu
  (`DailyBoardController.php:188`, odczyt do formularza w `:40`), pobiera
  `DailyBoard.php:161-165`, a **wyświetla tablica dnia** —
  `components/kuking-board.blade.php:138` (przy koncie) i `:278` (przy wpisie).
  Asercje: `DailyBoardTest.php:65,317`. `NULL` jest stanem normalnym: gospodarz
  nie musi nic dopisywać;

  > **Sprostowanie z 12 września 2026.** Do tego dnia stało tu „dziś nic tej
  > kolumny nie czyta". **Nieprawda**, z tego samego źródła co przy
  > `collection_items.note` — patrz sprostowanie tam;
- `created_at`.

## `hero_picks`

Zdjęcia wskazane ręcznie do **kolażu w hero strony powitalnej** (migracja
`2026_09_11_800000_utworz_hero_picks`). Zgłoszenie właściciela: „na stronie
głównej na samej górze po prawej stronie można zrobić kolaż w którym będą
najładniejsze (albo wybrane przez admina) zdjęcia użytkowników".

```sql
CREATE TABLE hero_picks (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    media_id    uuid NOT NULL REFERENCES media(id) ON DELETE CASCADE,
    post_id     uuid NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    position    smallint NOT NULL DEFAULT 0,
    curator_id  uuid REFERENCES users(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE hero_picks ADD CONSTRAINT hero_picks_media_id_unique UNIQUE (media_id);
ALTER TABLE hero_picks ADD CONSTRAINT hero_picks_position_check CHECK (position >= 0);
CREATE INDEX hero_picks_position_index ON hero_picks (position);
```

Kształt bliźniaczy do `daily_picks` i z tego samego powodu: **nie ma tu ani
jednej kolumny z punktami, liczbą polubień ani wynikiem.** To nie jest tabela
rankingowa (`../AGENTS.md` §12).

**Dlaczego dwa klucze obce, a nie sam `media_id`.** O tym, czy zdjęcie wolno
pokazać nieznajomemu, nie decyduje wiersz w `media`, tylko WPIS, przy którym
ono wisi (`posts.visibility`, `posts.status`, stan konta autora). To samo
zdjęcie bywa przypięte do kilku wpisów (`post_media` jest wiele-do-wielu),
więc bez zapisania, którego wpisu dotyczy wskazanie, nie da się później
sprawdzić, czy wciąż jest publiczny.

**Kaskada nie jest zabezpieczeniem prywatności.** `ON DELETE CASCADE` sprząta
wiersz po skasowanym wpisie albo zdjęciu — i tyle. Wpis przełączony na
prywatny, schowany przez moderatora, autor zawieszony: to wszystko zostawia
wiersz na miejscu. Filtr widoczności (`publiclyVisible()` +
`tylkoOdAktywnychAutorow()` + `Media::isReady()`) stoi w
`App\Domain\Feed\HeroKolaz` i liczy się **przy każdym wyświetleniu strony
powitalnej**, nie przy zapisie. Sprawdza to
`KolazPowitalnyPokazujeTylkoPubliczneZdjeciaTest`.

**UNIQUE na `media_id`** pilnuje, żeby to samo zdjęcie nie weszło do kolażu
dwa razy, i jest zarazem hamulcem na wyścig przy podwójnym kliknięciu
„Zapisz" (`Admin\HeroKolazController` przechwytuje zderzenie i kończy cicho —
ten sam wzorzec co `Admin\DailyBoardController`).

**Rollback: `down()` ODMAWIA, gdy w tabeli jest wybór człowieka (D-088).**
`DROP TABLE` kasuje cały wybór, a po `down()` prawie zawsze idzie kolejny
`migrate` — tabela wraca pusta, kolaż po cichu przechodzi w tryb automatyczny
i strona powitalna pokazuje cztery zdjęcia, których nikt nie oglądał. Błędu
nie ma czego zauważyć. Odmowa jest **wąska**: pusta tabela i świeża baza
przechodzą bez pytania. Świadome skasowanie:

```bash
KUKING_ROLLBACK_KASUJ_KOLAZ_POWITALNY=true php artisan migrate:rollback
```

Przed cofnięciem warto zapisać wybór:

```sql
\copy (SELECT media_id, post_id, position FROM hero_picks ORDER BY position)
TO 'hero_picks.csv' CSV HEADER
```

Strażnika i obie kontrole dodatnie sprawdza
`CofniecieMigracjiNieKasujeKolazuTest`.

## Dwie reguły, które obowiązują CAŁY schemat

Wszystko wyżej opisuje tabele po kolei. Te dwie rzeczy nie należą do
żadnej z nich z osobna — obowiązują wszystkie i dlatego stoją tu razem,
z asercją w `tests/Feature/SchematBazyTrzymaSieDokumentuTest.php`.

### 1. Każdy klucz obcy ma ZAPISANE zachowanie przy kasowaniu

Klucz obcy bez klauzuli `ON DELETE` nie jest kluczem bez zachowania — dostaje
`NO ACTION` z definicji SQL-a. Różnica jest cała w tym, czy ktoś tę odmowę
WYBRAŁ, czy tylko jej nie napisał; jedno i drugie wygląda w `\d` tabeli
identycznie, a pierwszy raz widać je dopiero przy kasowaniu konta na produkcji.

Zmierzone 12 września 2026 na pełnym schemacie (`pg_constraint`, `contype='f'`):
**71 kluczy obcych**, z tego 44 × `ON DELETE CASCADE`, 23 × `ON DELETE SET NULL`,
3 × `ON DELETE RESTRICT` i **jeden bez klauzuli**.

Trzy `RESTRICT` to nie przeoczenie, tylko ślad, którego nie wolno zgubić:
`dziennik_zgod.user_id`, `moderation_actions.moderator_id`
i `recipe_versions.editor_id`. Baza odmawia skasowania wiersza `users`,
dopóki wisi na nim zgoda, decyzja moderacyjna albo autorstwo wersji przepisu —
kasowanie konta idzie więc przez anonimizację (`data_erased_at`), a nie przez
`DELETE FROM users`.

Jeden klucz bez klauzuli też jest wyborem, jedynym takim w schemacie:
`tags.merged_into_tag_id` → `tags`. Domyślne `NO ACTION` blokuje skasowanie
tagu kanonicznego, dopóki są do niego przypięte tagi scalone (opis przy tabeli
`tags` wyżej, uzasadnienie w migracji `2026_09_07_100000_create_tags_tables`).
Test zna ten jeden wyjątek z nazwy i **sam pilnuje, żeby wyjątek nie zgnił**:
gdy kiedyś dostanie jawne `ON DELETE`, test każe wykreślić go z listy.

Nowy klucz obcy bez `ON DELETE` oblewa test i jest to pytanie, nie zakaz:
„co ma się stać z tym wierszem, gdy zniknie rodzic". Odpowiedzią bywa
`NO ACTION` — ale wpisaną tutaj, nie milczeniem.

### 2. E-mail i nazwa użytkownika są unikalne BEZ WZGLĘDU NA WIELKOŚĆ LITER

Zwykły `UNIQUE (email)` tego nie daje: PostgreSQL porównuje teksty co do
znaku, więc `Jan@example.com` i `jan@example.com` to dla niego dwa różne
adresy. Dla człowieka to jeden adres — a dla klawiatury telefonu, która
kapitalizuje pierwszą literę, to jest zachowanie domyślne, nie wyjątek.

Regułę trzymają dwa **funkcyjne** indeksy unikalne, nie mutatory w PHP:

```sql
CREATE UNIQUE INDEX users_email_lower_unique       ON users    (lower(email));
CREATE UNIQUE INDEX profiles_username_lower_unique ON profiles (lower(username));
```

Mutator `User::email` i `NazwaUzytkownika` dalej normalizują wejście i dalej
są potrzebne — ale jako sposób na ŁADNY komunikat, nie jako gwarancja
(AGENTS.md §6: „walidacja w PHP jest dodatkiem, nie zamiennikiem"; D-079:
„gwarancję daje constraint albo blokada, nie `exists()` w PHP"). Zwykłe
`users_email_unique` i `profiles_username_unique` zostają obok, bo są tańsze
przy wyszukiwaniu po dokładnej wartości. Reguły nie osłabiają: każdy duplikat,
który przeszedłby przez nie, zatrzymuje indeks funkcyjny. **Same z siebie nie
wystarczają** i to jest cały powód, dla którego te dwa funkcyjne istnieją.

Dlaczego akurat te dwie kolumny, a nie „każda kolumna tekstowa z UNIQUE":
to są jedyne dwie, po których człowiek **wraca do własnego konta**. Duplikat
tutaj nie jest brzydkim wierszem w tabeli, tylko drugim kontem tej samej
osoby albo cudzym profilem pod adresem, który ktoś rozdał znajomym.

## Normalizacja adresu e-mail

`User::email` ma mutator wymuszający małe litery i przycięcie spacji.
PostgreSQL porównuje teksty z uwzględnieniem wielkości liter, a klawiatury
telefonów kapitalizują pierwszą literę — bez tego konto założone jako
`Jan@example.com` było nie do zalogowania przez `jan@example.com`.

Sama reguła stoi jednak w bazie, nie w mutatorze: unikalny indeks funkcyjny
`users_email_lower_unique` — szczegóły i uzasadnienie przy tabeli `users` wyżej.

## `database/reference/schema_mvp.sql` NIE jest stanem bazy

Do 12 września 2026 stało tu zdanie „Pełny referencyjny DDL jest
w `database/reference/schema_mvp.sql`". Słowo **pełny** było nieprawdą i jest
to dokładnie ta nieprawda, przed którą ostrzega `AGENTS.md` §3 przy tabeli
stacku: wpis opisujący ZAMIAR, czytany jako opis STANU.

Zmierzone tego dnia: ten plik ma **22 wyrażenia `CREATE TABLE`**, a schemat
po migracjach ma **50 tabel** (42 nasze i 8 frameworka). Brakuje w nim 28,
z czego **20 naszych** — wszystkie pięć tabel tagów, `dziennik_zgod`,
`appeals`, `pending_email_changes`, `login_link_tokens`,
`registration_invites`, `data_exports`, `product_signals`,
`contact_messages`, `contact_message_replies`, `mail_failures`,
`weekly_digest_sends`, `tozsamosci_zewnetrzne`, `daily_picks`, `hero_picks`
i `recipe_slug_redirects`. Te, które są, też bywają nieaktualne —
`users.text_scale` ma tam `CHECK (BETWEEN 90 AND 140)`, a w bazie jest
`>= 70 AND <= 140` od migracji `2026_09_11_600000_rozszerz_skale_tekstu_w_dol`.

Czym ten plik jest naprawdę: **szkicem MVP z pierwszych dni projektu**,
przydatnym do czytania kształtu, bezużytecznym do sprawdzania faktu. Nic go
nie generuje i nic go nie pilnuje.

**Prawdą o schemacie jest żywa baza po `php artisan migrate`.** Ten dokument
opisuje ją zdaniami, a `SchematBazyTrzymaSieDokumentuTest` pilnuje, żeby żadna
tabela nie została w nim pominięta ani nie została opisana po skasowaniu.
