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
- `delete_scope` — ZAKRES usunięcia wybrany przez człowieka: `minimum`
  (domyślny — teksty zostają zanonimizowane) albo `everything` (patrz niżej);
- locale;
- text_scale;
- theme (patrz niżej);
- `wants_weekly_digest` — zgoda na cotygodniowy przegląd (patrz niżej);
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
`App\Domain\Digest\OdbiorcyDigestu::oznaczWyslane()`. Masowe przypisanie
z żądania pozwoliłoby cofnąć czyjś znacznik i wysłać mu drugi list w tym
samym tygodniu, wbrew obietnicy z ekranu ustawień.

**Rollback.** `down()` kasuje kolumnę i indeks. Bezpieczny, ale nie bez
skutku i trzeba to nazwać: razem z kolumną znika pamięć o tym, komu już
wysłano, więc pierwszy przebieg po przywróceniu napisze także do tych,
którzy dostali list wczoraj. Rollback robi się WYŁĄCZNIE razem
z `KUKING_DIGEST_WLACZONY=false`, nie „przy okazji".

Pilnują tego `TygodniowePodsumowanieTest` (odstęp, powtórne uruchomienie,
dobowy limit) i `WypisanieZPodsumowaniaTest`.

**Czego ta migracja NIE naprawia:** nie ma kolumny z datą wyrażenia i datą
wycofania zgody, więc **wycofania nie da się dziś wykazać**. Jeśli przegląd
kiedyś powstanie, trzeba je dodać razem z nim — inaczej zostaje obietnica
bez dowodu.

**Rollback:** `php artisan migrate:rollback --step=1`. `down()` przywraca
`DEFAULT true` dla NOWYCH wierszy i świadomie **nie** dotyka istniejących:
cofnięcie migracji jest operacją techniczną i nie może samo z siebie
zapisać ludzi na wysyłkę. Powrót do stanu sprzed migracji w całości wymaga
osobnego, jawnego `UPDATE` — i wtedy jest to decyzja człowieka.

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

**Rollback:** `down()` zdejmuje CHECK, indeks i kolumnę. Kontom, którym dane
już wymazano, ten rollback NIE przywraca e-maila ani hasła — tych danych po
prostu już nie ma, to nie jest strata spowodowana cofnięciem migracji. Same
konta nie zmieniają zachowania: nadal się nie logują.

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

**Rollback:** `down()` cofa `erased` → `pending_delete`, zdejmuje oba nowe
CHECK-i, przywraca poprzedni `users_data_erased_at_check` i `users_status_check`
i kasuje kolumnę. Sprawdzone na bazie testowej w obie strony
(`migrate` → `migrate:rollback --step=1` → `migrate`). Skutek jest ZNANY:
wraca usterka opisana wyżej (teksty wymazanych kont znowu oddają 403). Żadne
dane nie giną — tracimy wyłącznie zapisany zakres kont, które JESZCZE czekają
w karencji, a te wracają wtedy do zachowania D-018, czyli do wariantu mniej
nieodwracalnego.

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
`follower_id + followed_id` unique.

### blocks
Blokada ma pierwszeństwo przed follow.

### media
Tylko metadata, nie binary:
- owner;
- disk (ORYGINAŁ — patrz niżej);
- variants_disk (PUBLICZNE WARIANTY — patrz niżej);
- object key;
- MIME;
- bytes;
- width/height;
- status;
- checksum;
- perceptual hash;
- metadata.

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
Aktualny stan.

### recipe_versions
Snapshot po istotnych zmianach.

### ingredients + units
Podstawa search i późniejszego planera.

### recipe_ingredients
Musi mieć `ingredient_text`, nawet jeśli normalizacja nie rozpozna składnika.

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

**Rollback:** `down()` zdejmuje obie kolumny i traci przy tym listę ukrytych
wspomnień — po cofnięciu człowiek zobaczy z powrotem to, co świadomie schował.
Na produkcji: najpierw kopia obu kolumn.

### recipe_steps
Pozycja + instruction + opcjonalny timer/media.

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

### comments
Komentarz dotyczy dokładnie jednego:
- post;
- recipe;
- cooked event.

### collections + collection_items
Osobisty zeszyt.

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

#### `priorytet` — kolejność w kolejce moderacji (D-070)

Migracja `2026_09_10_400000_priorytet_w_kolejce_zgloszen` (audyt moderacji
MOD-01, MOD-04).

| Kolumna | Po co |
|---|---|
| `priorytet` | `smallint` 0–3, **NOT NULL**, `DEFAULT 2`. 0 = P0 (krytyczny), 3 = P3 (niski). Mniejsza liczba znaczy PILNIEJSZE, żeby `ORDER BY priorytet ASC` czytało wprost z indeksu bez ani jednego `DESC`. Wartość **wynika z `reason`** i nadaje ją hak `Report::booted()` przez `App\Domain\Moderation\PriorytetSprawy::dlaPowodu()` — ta klasa jest JEDYNYM miejscem z mapowaniem kategoria → priorytet (podręcznik moderacji je odwzorowuje, nie definiuje). **Poza `$fillable`**: wartość nadana przez serwer, tak samo jak `numer_sprawy`. |
| `priorytet_powod` | `varchar(500)`, `NULL`. Uzasadnienie RĘCZNEJ zmiany priorytetu. Obowiązkowe przy zmianie — automat czyta tylko kategorię wybraną przez zgłaszającego, nie treść, więc człowiek musi móc podnieść i obniżyć, a zmiana bez powodu jest w logu nieodróżnialna od pomyłki. |
| `priorytet_zmieniony_przez` | FK → `users`, `nullOnDelete`. Kto zmienił. |
| `priorytet_zmieniony_o` | `timestamptz`, `NULL`. Kiedy. **To ta kolumna odpowiada na pytanie „czy priorytet zmieniono ręcznie"** — nie ta wyżej, bo tamta może zniknąć razem z kontem moderatora. |
| `przeglad_zaczety_przez` | FK → `users`, `nullOnDelete`. Kto wziął sprawę do przeglądu (status `reviewing`). |
| `przeglad_zaczety_o` | `timestamptz`, `NULL`. Kiedy. Bez tego znacznika sprawa nie umiałaby WRÓCIĆ do kolejki, a „wziąłem do przeglądu" byłoby sposobem na trwałe wyciszenie oznaczenia „P0 nieprzejrzane". |

**Kolumny na priorytet AUTOMATYCZNY świadomie nie ma** — da się go policzyć
z `reason` w każdej chwili, a druga kolumna z tą samą wiedzą to druga okazja,
żeby się rozjechały.

| Ograniczenie / indeks | Co pilnuje |
|---|---|
| `reports_priorytet_check` | `priorytet BETWEEN 0 AND 3`. Cztery znane priorytety i nic więcej. |
| `reports_priorytet_zmiana_check` | `num_nonnulls(priorytet_powod, priorytet_zmieniony_o) IN (0, 2)` — zmiana ręczna albo cała, albo żadna. **Kolumny z moderatorem w tym warunku NIE MA i to jest istotne:** ma `nullOnDelete`, więc gdyby wchodziła, skasowanie konta moderatora zamieniłoby poprawny wiersz w niepoprawny — a baza odmówiłaby wtedy usunięcia konta, czyli `CHECK` postawiony dla porządku w danych zablokowałby żądanie z RODO art. 17. |
| `reports_przeglad_check` | `status <> 'reviewing' OR przeglad_zaczety_o IS NOT NULL`. Sprawa w przeglądzie musi mieć czas rozpoczęcia. Po decyzji znacznik **zostaje** — to ślad, kto sprawę prowadził. |
| `reports_kolejka_priorytet_idx (priorytet, created_at)` | Indeks częściowy `WHERE status IN ('open','reviewing')`. Kolejka `ORDER BY priorytet, created_at` idzie po nim bez sortowania. Częściowy, bo pyta się wyłącznie o sprawy otwarte, a tabela rośnie z historią i nie maleje nigdy. `source` **nie jest** w warunku — ten sam indeks obsługuje licznik „P0 nieprzejrzane", który patrzy na wszystkie źródła. |
| `reports_status_check` (**zmieniony**) | `status IN ('open','reviewing','resolved','rejected')` — **`triage` wypada**. Nadawała go zero linii kodu i nie pokazywał go żaden ekran; `reviewing` od tej zmiany działa naprawdę, `triage` nie miał czego zacząć. Migracja **przerywa się**, gdy w tabeli leży choć jeden wiersz `triage`, i mówi, na co go przenieść. |

**Warunki `reports_one_open_per_pair` i `reports_automat_autor_idx` nadal
wymieniają `'triage'` i tak zostają.** To nadzbiór wartości dopuszczalnych, więc
nic nie psuje: PostgreSQL dalej dowodzi, że `status IN ('open','reviewing')`
z zapytania implikuje warunek indeksu, i dalej go używa. Przebudowa kosztowałaby
zdjęcie i postawienie od nowa indeksu chroniącego przed podwójnym zgłoszeniem,
za zero zysku.

**Rollback (`2026_09_10_400000_priorytet_w_kolejce_zgloszen`) — PRZYWRACA STAN
GORSZY i `down()` mówi to wprost.** Zdjęcie kolumny nie jest neutralne: kolejka
wraca do `ORDER BY created_at DESC`, czyli do porządku, w którym sprawa P0 sprzed
dwóch dni leży pod świeżym spamem, a oznaczenie „P0 nieprzejrzane" nie gaśnie
z komunikatem — przestaje istnieć, a moderator nie ma jak zauważyć braku
ostrzeżenia, którego nie widział. Dlatego `down()` **odmawia**, dopóki w kolejce
stoi choć jedna nierozpatrzona sprawa P0/P1 **albo** istnieje choć jedno ręczne
uzasadnienie priorytetu (ginie bezpowrotnie razem z kolumną i nie da się go
odtworzyć z `reason`). Świadome wymuszenie, po kopii tabeli:
`KUKING_ROLLBACK_KASUJE_PRIORYTETY=1`. Sprawy w `reviewing` `down()` **wraca do
`open`** — inaczej zostałyby w stanie, którego po wycofaniu nikt nie nadaje
i nikt nie zdejmuje, i leżałyby w zakładce „W trakcie" na zawsze.

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

Indeks: `(user_id, created_at)` — lista paczek danego użytkownika w kolejności.

### product_signals

Sygnały produktowe (issue #115), migracja
`2026_09_06_220000_create_product_signals_table`. Dziś **cztery** zdarzenia:
`photo_upload_failed` (próba wgrania zdjęcia, która się nie udaje —
`App\Domain\Media\Actions\StoreUploadedImage`), `search_performed`
(wykonane wyszukiwanie — `App\Http\Controllers\SearchController`) oraz para
od tygodniowego podsumowania: `weekly_digest_sent` i
`weekly_digest_unsubscribed` (issue #11, D-057; migracja
`2026_09_10_100100_add_digest_signals_to_product_signals`). Jedyne miejsce,
które tu pisze: `App\Domain\Analytics\ZapiszSygnal`.

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
wysłane/wypisane.

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
| `signal_name` | `photo_upload_failed` \| `search_performed` \| `weekly_digest_sent` \| `weekly_digest_unsubscribed`. CHECK w bazie (`product_signals_signal_name_check`) — zamknięty zbiór, tak jak `reports.status`. |
| `properties` | `jsonb`. Dla `photo_upload_failed`: `reason` (patrz niżej) i gdzie to ma sens liczby (`bytes`, `max_bytes`, `megapixels`) — NIGDY nazwa pliku. Dla `search_performed`: **wyłącznie** `query_length` (int) i `has_results` (bool) — **nigdy** `query_text`. Drugi CHECK w bazie (`product_signals_no_query_text_check`, przez `jsonb_exists()`) odrzuca każdy wiersz, w którym klucz `query_text` w ogóle by się pojawił, niezależnie od tego, co akurat pisze kod aplikacji. Dla `weekly_digest_sent`: **wyłącznie liczby** — `wykonania`, `nowi_obserwujacy`, `wpisy` (ile pozycji miała każda sekcja listu), żeby dało się zobaczyć, czy listy nie robią się cienkie. Bez adresu, bez nazw, bez tytułów. Dla `weekly_digest_unsubscribed`: `properties` jest PUSTE — sam fakt i `user_id` wystarczą do progu wypisów. |
| `occurred_at` | `timestamptz`, `useCurrent()`. Dla `weekly_digest_sent` czytany też JAKO LICZNIK: komenda wysyłkowa liczy wiersze z bieżącej doby, żeby nie przekroczyć dobowego limitu poczty (D-057). |

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
`normalized_alias` `UNIQUE` w całej tabeli. `source`: `seed` \| `admin` \|
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

## Normalizacja adresu e-mail

`User::email` ma mutator wymuszający małe litery i przycięcie spacji.
PostgreSQL porównuje teksty z uwzględnieniem wielkości liter, a klawiatury
telefonów kapitalizują pierwszą literę — bez tego konto założone jako
`Jan@example.com` było nie do zalogowania przez `jan@example.com`.

Sama reguła stoi jednak w bazie, nie w mutatorze: unikalny indeks funkcyjny
`users_email_lower_unique` — szczegóły i uzasadnienie przy tabeli `users` wyżej.

Pełny referencyjny DDL jest w `database/reference/schema_mvp.sql`.
