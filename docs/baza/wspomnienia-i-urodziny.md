# Wspomnienia „Rok temu gotowałaś…" i urodziny bez roku

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

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

**Wspomnienia z własnych wykonań (F6, 30.09.2026)** — migracja
`2026_09_30_141500_add_hide_as_memory_to_cooked_events`:

```sql
ALTER TABLE cooked_events ADD COLUMN hide_as_memory boolean NOT NULL DEFAULT false;
```

- **`cooked_events.hide_as_memory`** — ukrycie JEDNEGO własnego „Ugotowałem”
  w bloku „Rok temu…” na stronie głównej, bliźniacze do `posts.hide_as_memory`.
  Wykonanie zostaje na profilu i pod przepisem. Zapisuje je wyłącznie
  `WspomnienieController::ukryjWykonanie` (Policy `hideAsMemory` — tylko
  kucharz); poza `$fillable`. Wyłącznik całości to dalej
  `users.memories_enabled`.
- DDL: stała wartość domyślna, więc PostgreSQL nie przepisuje tabeli.
  Indeksu nie dodajemy — zapytanie idzie po `cooked_events_user_idx
  (user_id, cooked_at DESC)`.

**Rollback:** `DROP COLUMN hide_as_memory`, ale `down()` **odmawia** (D-088),
gdy choć jedno wykonanie ma `hide_as_memory = true` — kolejny `migrate`
odtworzyłby kolumnę z `DEFAULT false` i schowane wspomnienie wróciłoby na
stronę główną. Komunikat podaje `SELECT` do zapisania listy przed cofnięciem.
Na wartościach domyślnych przechodzi bez pytania —
`tests/Feature/CofniecieMigracjiUkryciaWykonanTest.php`.

### Urodziny bez roku (issue #1755)

Kolumny na `users`, bo to prywatne ustawienie konta, a nie dana profilu
publicznego (`profiles`). Decyzja właściciela z 25.09.2026 — **D-269**
w `docs/DECISIONS.md`, research `docs/research/PROFIL_FORMA_I_URODZINY.md`.

**Etap a** — migracja `2026_09_25_200000_add_birthday_to_users`:

- **`users.birthday_day`** (`smallint NULL`) i **`users.birthday_month`**
  (`smallint NULL`) — dzień i miesiąc urodzin. **Roku nie ma i nie będzie**:
  do życzeń nie jest potrzebny, a pełna data urodzenia stoi na liście danych,
  których nie zbieramy (`docs/SECURITY_PRIVACY_LEGAL.md`).
- CHECK `users_birthday_pair_check`: oba pola `NULL` albo oba wypełnione.
- CHECK `users_birthday_range_check`: miesiąc 1–12, dzień istnieje w danym
  miesiącu (29.02 dozwolone, 30.02 i 31.04 nie). Życzenia dla 29.02 wypadają
  28.02 w latach nieprzestępnych — to reguła wyświetlania
  (`App\Domain\Rocznice\Urodziny`), nie zapisu.
- Zapis wyłącznie przez `App\Domain\Users\Actions\UstawUrodziny` (kolumny
  poza `$fillable`), ekran `/ustawienia/urodziny` z przyciskiem „Usuń datę”.
- Eksport: `konto.urodziny` jako `DD-MM` albo `null`. Wymazanie konta
  (`EraseAccountData`) zeruje obie kolumny.

**Rollback etapu a:** `down()` **odmawia**, gdy choć jedno konto ma wpisaną datę
(D-088) — po cyklu `rollback` → `migrate` kolumny wróciłyby puste i życzenia
przestałyby przychodzić bez śladu błędu. Przy samych `NULL` i na świeżej bazie
przechodzi. Test: `tests/Feature/CofniecieMigracjiUrodzinTest.php`.

**Etap b** — migracja `2026_09_25_200100_add_birthday_wishes_enabled_to_users`:

- **`users.birthday_wishes_enabled`** (`boolean NOT NULL DEFAULT true`) —
  wyłącznik życzeń od gospodarza na `/home`. Domyślnie włączony, bo podanie
  daty już jest wyborem „chcę życzeń”; istnieje od pierwszego dnia z powodu
  zasady żałoby (jak `memories_enabled`). Przełącznik stoi przy dacie
  w `/ustawienia/urodziny`. Eksport: `konto.pokazuj_zyczenia_urodzinowe`.
- **Rollback:** `down()` odmawia, gdy choć jedno konto ma `false` (D-088) —
  `DEFAULT true` włączyłby życzenia osobie, która je wyłączyła. Test:
  `tests/Feature/ZyczeniaUrodzinoweNaStronieTest.php`.

**Etap c** — migracja `2026_09_25_200200_add_birthday_email_consent_to_users`:

- **`users.wants_birthday_email`** (`boolean NOT NULL DEFAULT false`) —
  **osobna** zgoda na list z życzeniami (PKE art. 398); podanie daty jej nie
  daje. Zapis wyłącznie przez `App\Domain\Zgody\PrzestawZgodeNaZyczeniaMailem`,
  które dopisuje wiersz do `dziennik_zgod` (D-072). „Usuń datę” i wymazanie
  konta wycofują zgodę z wpisem w dzienniku.
- **`users.birthday_email_sent_on`** (`date NULL`) — dzień (Europe/Warsaw),
  w którym transport pocztowy PRZYJĄŁ list z życzeniami (`App\Mail\
  ZyczeniaUrodzinowe::send()`, po `parent::send()` bez wyjątku). Do
  26 września 2026 (issue #1956) ustawiała ją komenda zaraz po
  `Mail::queue()`, czyli po zakolejkowaniu, nie po wysyłce — awaria enqueue
  albo trwała porażka workera zostawiały znacznik mimo braku listu, a to
  jest jedyny list w roku dla tej osoby. Patrz `birthday_email_queued_on`.
- **`users.birthday_email_queued_on`** (`date NULL`, migracja
  `2026_09_26_200000_add_birthday_email_queued_on_to_users`, issue #1956) —
  dzień, w którym komenda ZAJĘŁA miejsce dla tej osoby, niezależnie od tego,
  czy list ostatecznie wyszedł. Bariera przed dublem:
  `kuking:wyslij-zyczenia-urodzinowe` zajmuje dzień warunkowym
  `UPDATE … WHERE birthday_email_queued_on IS NULL OR
  birthday_email_queued_on <> dziś` przed `Mail::queue()`, a `kandydaci()`
  wyklucza po TEJ kolumnie, nie po `birthday_email_sent_on`. Awaria samego
  `Mail::queue()` zwalnia tę rezerwację w tym samym przebiegu (ponowienie
  tego samego dnia wysyła dokładnie jeden list); trwała porażka workera
  zostawia ją ustawioną (dzień jest „zużyty" wobec dostawcy) — świadomy
  wybór „pominięcie zamiast duplikatu" DLA TEGO DNIA, ten sam co
  `weekly_digest_sends` (D-077), ale bez wpływu na kolejne lata: rocznica
  sprzed roku wraca normalnie, bo to już inny dzień.
- `dziennik_zgod_cel_check` rozszerzony o `zyczenia_urodzinowe`.
- **Rollback `wants_birthday_email` / `birthday_email_sent_on`:** `down()`
  odmawia, gdy ktoś ma zgodę albo dziennik ma choć jeden wiersz celu
  `zyczenia_urodzinowe` (wierszy dziennika nie wolno kasować, więc starego
  CHECK-a nie da się przywrócić bez utraty dowodu). Test:
  `tests/Feature/ZyczeniaUrodzinoweMailemTest.php`.
- **Rollback `birthday_email_queued_on`** (migracja
  `2026_09_26_200000_add_birthday_email_queued_on_to_users`): `down()` odmawia
  tylko wtedy, gdy ktoś ma dzisiejszą rezerwację bez potwierdzonej wysyłki.
  Cofnięcie schematu razem ze starym kodem zgubiłoby wtedy barierę i mogło
  zakolejkować drugi list. Po zakończeniu dnia albo przy potwierdzonym
  `birthday_email_sent_on` rollback jest dozwolony. Test odmowy i przejścia:
  `tests/Feature/CofniecieRezerwacjiListuUrodzinowegoTest.php`.

**Sobotnie przypomnienie o produktach do zużycia (#1903, D-333)** — migracja
`2026_10_01_102000_add_pantry_reminder_consent_to_users`
(`$withinTransaction = false`):

- **`users.wants_pantry_reminder`** (`boolean NOT NULL DEFAULT false`) —
  **osobna**, domyślnie WYŁĄCZONA zgoda na jeden list tygodniowo, w sobotę rano,
  o produktach z listy „Co mam w domu”, których termin minął albo upływa w ciągu
  `kuking.pantry.pilne_dni` dni (bez mrożonych). Założenie listy ani wpisanie
  terminu jej nie daje. Zapis wyłącznie przez
  `App\Domain\Zgody\PrzestawZgodeNaPrzypomnienieSpizarni`, które dopisuje wiersz
  do `dziennik_zgod` z celem `przypomnienie_spizarni` (D-072); brak zmiany =
  brak wiersza. Poza `$fillable`. `EraseAccountData` ustawia `false`.
- **Deduplikacja bez kolumny na `users`:** `PrzypomnienieDobowe::zarezerwuj(
  'przypomnienie-spizarni', adres, dzień)` (tabela `przypomnienia_dobowe`: skrót
  adresu i doba, retencja 30 dni) przed `Mail::queue()`. **Doba to dzień
  w Polsce** (`Czas::dzisiajData()`, Europe/Warsaw), nie data UTC — polska
  sobota obejmuje dwie daty UTC, a klucz z UTC dopuszczał dwa listy w jednej
  sobocie (#2364); `PrzypomnienieDobowe` bez podanej doby liczy UTC jak dawniej
  (`PilnujTerminowOdwolan`). Dzień tygodnia pilnuje
  sama komenda `kuking:wyslij-przypomnienia-spizarni` (tylko sobota w
  `Europe/Warsaw`), harmonogram `weeklyOn(6, '09:00')` UTC.
- **Indeks częściowy `users_wants_pantry_reminder_idx`** (`ON users (id)
  WHERE wants_pantry_reminder`, `CREATE INDEX CONCURRENTLY`, z zdjęciem
  niedokończonej budowy INVALID przed próbą): komenda skanuje konta ze zgodą,
  a ma ją mała mniejszość — bez indeksu byłby to przegląd całej tabeli w każdą
  sobotę. W transakcji (testy) migracja pomija `CONCURRENTLY`.
- `dziennik_zgod_cel_check` rozszerzony o `przypomnienie_spizarni`
  (`NOT VALID` + `VALIDATE`).
- **Rollback:** `down()` odmawia (D-088), gdy ktoś ma zgodę albo dziennik ma
  choć jeden wiersz celu `przypomnienie_spizarni` — zgoda wróciłaby jako
  `false` bez śladu, a wierszy dziennika nie wolno kasować. Test:
  `CofniecieMigracjiNieKasujeTerminowSpizarniTest`. Eksport:
  `konto.chce_sobotniego_przypomnienia_o_produktach`.

**Etap d** — migracja `2026_09_25_200300_add_birthday_visible_to_followers_to_users`:

- **`users.birthday_visible_to_followers`** (`boolean NOT NULL DEFAULT false`) —
  „Pokaż moje urodziny obserwującym”. Tylko po jawnym włączeniu (decyzja
  właściciela). `kuking:przypomnij-o-urodzinach` (harmonogram 07:50 UTC)
  tworzy wtedy obserwującym powiadomienie `notifications.type =
  'birthday.today'` z `actor_id` = solenizant: najwyżej jedno na parę na dobę,
  najwyżej `kuking.urodziny.przypomnienia_na_odbiorce_dziennie` (3) na odbiorcę
  na dobę, nigdy w ciszy nocnej (21–8, klucze
  `kuking.notifications.zewnetrzne.cisza_*`). **Nie jest to wpis w feedzie.**
  „Usuń datę” i wymazanie konta ustawiają `false`. Eksport:
  `konto.pokazuj_urodziny_obserwujacym`.
- Stary formularz ustawień nie może ponownie włączyć widoczności po jej
  wyłączeniu w innej karcie (#2864). Stan widoczności z chwili otwarcia
  formularza jest sprawdzany pod tą samą blokadą co zgoda na mail;
  rozbieżność odmawia całego zapisu i prosi o otwarcie aktualnych ustawień.
- Po wstępnym wyborze solenizanta komenda sprawdza jego **świeży** stan,
  widoczność i datę pod `FOR SHARE` w transakcji zapisu (#2880). Zmiana
  decyzji albo daty, która zdążyła się zatwierdzić, odcina nowe
  powiadomienie. Jeśli powiadomienie zapisano pierwsze, pozostaje, a
  późniejsze wyłączenie nie kasuje historii. Najpierw blokada odbiorcy
  pilnuje dobowego limitu, potem blokada wiersza solenizanta; zapis
  ustawień bierze wyłącznie blokadę własnego konta. Dowód obu kolejności:
  `PrzypomnieniaUrodzinPoZmianieDecyzjiTest` (dwa połączenia PG18).
  Cofnięcie tej poprawki przywraca okno ujawnienia prywatnych urodzin;
  bezpieczny rollback to powrót do poprzedniego kodu tylko po ocenie
  powiadomień utworzonych w tym oknie, bez usuwania historii w ciemno.
- **Rollback:** `down()` odmawia, gdy choć jedno konto ma `true` (D-088:
  decyzja o widoczności). Test: `tests/Feature/PrzypomnienieOUrodzinachTest.php`.
