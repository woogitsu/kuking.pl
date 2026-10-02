# Konta (`users`) — kary, usuwanie konta, wymazanie

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

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

#### `punishment_status` — kara odłożona na czas usuwania konta (issue #980)

Migracja `2026_09_24_100000_add_punishment_status_to_users`.

`status` niósł dwa niezależne procesy — karę moderacyjną i cykl usunięcia —
a każda operacja nadpisywała go bez patrzenia na drugi: ban po zgłoszeniu
usunięcia wyjmował konto spod `kuking:usun-wygasle-konta` (ten wybiera
`status = 'pending_delete'`), a zgłoszenie usunięcia po banie gubiło karę
(cofnięcie usunięcia stawiało `active`).

**Kontrakt.** Dopóki konto jest w cyklu usunięcia (`pending_delete`,
`erased`), `status` mówi o usuwaniu, a kara czeka tutaj. Macierz przejść
(`App\Models\User`, każde przejście na świeżym wierszu pod `ZamekKonta`):

| Stan przed | `suspend()`/`ban()` | `markForDeletion()` | `cancelDeletion()` | `reinstate()` |
|---|---|---|---|---|
| `active` | `status` = kara | `pending_delete` | — | — |
| `suspended`/`banned` | `status` = kara | `pending_delete`, kara → `punishment_*` | — | `active` |
| `pending_delete` | kara → `punishment_*` | odmowa (`BladDlaCzlowieka`) | `status` = odłożona kara albo `active` | zeruje `punishment_*` |
| `erased` | kara → `punishment_*` | odmowa | (odmawia `CancelAccountDeletion`) | zeruje `punishment_*` |

Wymazanie danych zostawia `punishment_status` na wierszu jako zapis stanu
konta w chwili wymazania; autorytatywny zapis decyzji żyje
w `moderation_actions` (retencja: `docs/decyzje/ADR_RETENCJE.md`).
Mechanizmu blokady ponownej rejestracji w projekcie nie ma i ta migracja go
nie wprowadza.

```sql
CHECK (punishment_status IS NULL OR punishment_status IN ('suspended','banned'))  -- users_punishment_status_check
CHECK (punishment_status IS NULL OR status IN ('pending_delete','erased'))        -- users_punishment_status_deletion_check
CHECK (punishment_expires_at IS NULL OR punishment_status = 'suspended')           -- users_punishment_expires_at_check
```

**Rollback ODMAWIA** (D-088), gdy choć jedno konto ma odłożoną karę: stary
schemat nie ma gdzie jej zapisać, a stary kod przy cofnięciu usunięcia
ustawiłby `active`. Bez takich kont cofnięcie przechodzi i nic nie ginie.
Testy: `BanIUsuniecieKontaNieNadpisujaSieTest`,
`tests/Dwa/BanIUsuniecieKontaRownolegleTest`.

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
| `jestDostepnyJakoAutor()` / `scopeDostepnyJakoAutor` | `banned`, `pending_delete` | Policy treści, feed, zeszyty, mapa strony dla treści i profili, `noindex` profilu (od 30.09, D-333) |
| `jestWidocznyJakoOsoba()` / `scopeWidocznyJakoOsoba` | `banned`, `pending_delete`, `erased` | listy obserwujących i ich liczniki, analityka, panel „bez odpowiedzi" |

Profil konta `erased` jest **dostępny** (`UserPolicy::viewProfile`), bo to
adres, pod który prowadzi każdy podpis „Użytkownik usunięty". Nie jest za to
nigdzie podpowiadany: wyszukiwarka osób pyta o `status = 'active'`, listy osób
— o `widocznyJakoOsoba()`. Wyjątek od 30.09 (D-333): profil konta `erased`
z zachowanymi publicznymi treściami jest w mapie strony i bez `noindex`
(granica `dostepnyJakoAutor()`); bez publicznych treści odpada jak każdy
pusty profil.

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
