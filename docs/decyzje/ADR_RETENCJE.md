# ADR — Retencja dla `audit_log`, `notifications`, `reports`, `appeals`, `moderation_actions`

**Status: PROPOZYCJA DO ZATWIERDZENIA. Nic z tego dokumentu nie jest wdrożone.**
Data: 7 września 2026 · Dotyczy: issue #19 · Autor: agent badawczy (Claude),
na zlecenie właściciela · Stan repozytorium zmierzony na `HEAD` z 7 września 2026.

Ten dokument **nie zmienia kodu, migracji ani `resources/legal/polityka-prywatnosci.md`**.
Każde twierdzenie o stanie systemu poniżej ma cytat `plik:linia`. Tam, gdzie
proponuję liczbę, mówię to wprost i osobno od zmierzonego faktu — zgodnie
z rozróżnieniem, które `docs/DECISIONS.md` już przyjął w D-024 (patrz §0).

---

## 0. Zasada nadrzędna — już obowiązuje, ten ADR jej nie zmienia

`docs/DECISIONS.md:979-985` (D-024) rozstrzygnęło to wcześniej i poprawnie:

> „proponowanego okresu nie wolno opublikować, dopóki automatyczne zadanie
> go nie wykonuje. Zmierzone: kod egzekwuje dokładnie dwa okresy —
> `product_signals` 90 dni i paczki eksportu 7 dni. `audit_log`,
> `notifications`, `reports`, `appeals` i `moderation_actions` nie mają
> retencji żadnej, więc żadna liczba przy nich nie może się pojawić."

Zmierzyłem to zdanie niezależnie (§1.2 niżej) — **jest dziś prawdziwe**.
Ten ADR proponuje liczby. Same z siebie nic nie znaczą i nie wolno ich
przenieść do `resources/legal/polityka-prywatnosci.md` (ani do
`docs/legal/COMPLIANCE.md` jako czegoś więcej niż rekomendacji) **dopóki**:

1. właściciel zatwierdzi konkretną liczbę tam, gdzie ten dokument daje warianty (§6),
2. automat, który ją egzekwuje, istnieje, ma test i jest w harmonogramie (§5),
3. działanie automatu zostało zaobserwowane na produkcji co najmniej raz.

Odwrócenie tej kolejności — najpierw zdanie w polityce, potem kod — jest
dokładnie tym, co D-024 już nazwało problemem (i co nadal, dziś, wisi w
dwóch miejscach polityki — §2 niżej).

---

## 1. Stan faktyczny zmierzony w kodzie

### 1.1 Schemat — wszystkie pięć tabel powstają w jednej migracji

`database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php`
tworzy `reports` (linie 24-44), `moderation_actions` (46-62) i `audit_log`
(64-77) w jednym pliku. Dwie kolejne migracje rozszerzają je:
`2026_09_06_100000_add_context_to_moderation_actions.php` (kolumny
`previous_status`, `subject_user_id`) i
`2026_09_06_200000_add_legal_notice_fields_to_reports.php` (pola DSA art. 16
dla `reports`). `appeals` ma własną migrację:
`database/migrations/2026_09_06_100100_create_appeals_table.php`.
`notifications` — `database/migrations/2026_09_05_000900_create_notifications_table.php`.

**Żadna z tych sześciu migracji nie zawiera kolumny `expires_at`,
`retention_until` ani żadnego odpowiednika.** Dla porównania — tabela, która
MA egzekwowaną retencję, ma to widać w schemacie:
`database/migrations/2026_09_06_220000_create_product_signals_table.php`
+ indeks `product_signals_occurred_idx (occurred_at)` opisany w
`docs/DATABASE.md:854` jako służący właśnie retencji.

### 1.2 Zero automatyzacji — zmierzone przez `grep`, nie przez domysł

```
grep -rn "Report::|Appeal::|ModerationAction::|Notification::|AuditLogEntry::" app/Console
```

zwraca wyłącznie dwa wywołania **zapisu** do `audit_log`
(`app/Console/Commands/PurgeExpiredAccountDeletions.php:133` i
`app/Console/Commands/RestoreExpiredSuspensions.php:68`) — żadne z nich nie
kasuje ani nie ogranicza wieku żadnego wiersza. `app/Console/Commands/`
zawiera osiem komend (`ls app/Console/Commands/`); żadna nie ma w nazwie ani
w treści nic, co dotykałoby `reports`, `moderation_actions`, `appeals`,
`notifications` czy `audit_log` w trybie kasowania. `routes/console.php` ma
pięć wpisów w harmonogramie (`Schedule::call(...)`) — żaden nie wywołuje
komendy dla tych pięciu tabel.

**Wniosek: D-024 miało rację i nadal ma — to jest fakt, zmierzony niezależnie
w tej samej sesji badawczej, nie powtórzenie za dokumentem.**

### 1.3 Wzorzec z istniejących komend sprzątających

Zadanie każe opisać istniejącą komendę czyszczącą eksporty jako wzorzec.
W repozytorium są właściwie **trzy różne, świadomie odmienne wzorce** —
ten ADR używa wszystkich trzech, każdego tam, gdzie pasuje.

#### Wzorzec A — `kuking:sprzataj-eksporty` (`CleanUpDataExports.php`), gdy kasowanie dotyka STORAGE

`app/Console/Commands/CleanUpDataExports.php:36-41` wybiera `DataExport`
z `expires_at < now()` w statusie `ready` albo `expired`. Dla każdego wiersza:

- `skasujPlik()` (linie 123-159) kasuje plik, **a potem sprawdza `exists()`
  na dysku** (linia 135) — bo `delete()` na dysku `local` ma `throw => false`
  i milcząco zwraca `false` zamiast rzucić wyjątek (komentarz, linie 113-121).
  Bez tego sprawdzenia nieudane kasowanie wyglądałoby identycznie jak udane.
- **Status zmienia się ZAWSZE**, ale **adres pliku (`disk`, `object_key`)
  zostaje wyczyszczony tylko, gdy kasowanie faktycznie się powiodło**
  (linie 77-84). Komentarz wprost: wyczyszczenie adresu przy porażce
  zamieniłoby odwracalną awarię w plik, o którym nikt już nie wie, gdzie leży
  — czyli w bezterminowe przechowywanie wbrew zasadzie minimalizacji.
- Porażka **nie przerywa pętli** (błąd jednego wiersza nie blokuje reszty,
  linia 146-158) i **nie ginie bez śladu** — `Log::error` z identyfikatorem
  rekordu (linie 136-140, 150-155), a wiersz wraca do kolejki przy
  następnym uruchomieniu, bo zapytanie na wejściu obejmuje też `expired`
  z adresem wciąż ustawionym.
- `--dry-run` (linia 28) pokazuje, co by zrobiła, bez zmian.

Ten wzorzec pasuje tam, gdzie kasowanie ma efekt uboczny poza bazą
(plik, cache CDN). **Żadna z pięciu tabel tego ADR-u takiego efektu nie ma**
— więc ten wzorzec nie jest tu bezpośrednio potrzebny, ale jego zasada
(„status zmienia się zawsze, dane znikają dopiero gdy operacja faktycznie
się powiodła, błąd nie blokuje reszty i zostawia log") jest tym, czego
trzyma się §5 niżej.

#### Wzorzec B — `kuking:sprzataj-sygnaly` / `PrzedawnioneSygnaly` — czyste dane tabelaryczne

`app/Domain/Analytics/PrzedawnioneSygnaly.php` — cała logika to jedna linia:

```php
$zapytanie = ProductSignal::query()->where('occurred_at', '<', now()->subDays($dniKarencji));
return $naSucho ? $zapytanie->count() : $zapytanie->delete();
```

Komentarz w pliku uzasadnia to wprost: wiersz `product_signals` **nie ma
odpowiednika po stronie storage** (w odróżnieniu od zdjęć), więc masowy
`DELETE ... WHERE occurred_at < ?` jest i szybszy, i bezpieczny na
przerwanie w połowie — baza gwarantuje atomowość jednego zapytania, kolejny
przebieg po prostu dobierze to, co zostało. Okres wchodzi z
`config('kuking.analytics.signal_retention_days')`
(`config/kuking.php:518`, domyślnie 90, `KUKING_SIGNAL_RETENTION_DAYS`) i da
się nadpisać opcją `--dni` na jedno uruchomienie
(`app/Console/Commands/SprzatajSygnaly.php:17-19,25-27`). Harmonogram:
`routes/console.php` (blok „Retencja sygnałów produktowych"), codziennie
o 04:00, `withoutOverlapping()`.

**Ten wzorzec pasuje wprost do `audit_log` i `notifications`** — żadna
z nich nie ma efektu ubocznego w storage, więc zwykły `DELETE` na warunku
czasowym jest wystarczający i najprostszy.

#### Wzorzec C — `kuking:usun-wygasle-konta` / `EraseAccountData` — gdy trzeba PISAĆ do audytu i pilnować zależności między wierszami

`app/Console/Commands/PurgeExpiredAccountDeletions.php:72-78` wybiera
konta `pending_delete` z `delete_requested_at <= now() - grace_days`
(`config('kuking.account.delete_grace_days')`, `config/kuking.php:232`,
30 dni). Osobna, druga kolejka (linie 91-95, audyt/issue #17) dobiera konta
już zanonimizowane, którym z poprzedniego przebiegu zostały nieskasowane
zdjęcia — nieistotne dla retencji tych pięciu tabel, pomijam ją dalej.
Dla każdego konta z pierwszej kolejki:

- `EraseAccountData::handle()` (`app/Domain/Users/Actions/EraseAccountData.php:72-224`)
  robi anonimizację w JEDNEJ transakcji z `lockForUpdate()` na świeżo
  odczytanym wierszu (linia 108) — **idempotentne**: konto już oznaczone
  `data_erased_at` albo które w międzyczasie przestało być
  `pending_delete` jest pomijane (linie 110-115), zwraca `false`, nic nie
  zapisuje. Metoda ma też osobną, wcześniejszą gałąź ponowienia (linie
  76-98) dla konta już zanonimizowanego, ale z zdjęciami, których
  poprzednia próba kasowania nie dokończyła (audyt/issue #17) — sam wiersz
  `users` nigdy nie jest w tym pliku celem `delete()`, wyłącznie `forceFill()->save()`
  (linie 135-151, 167) — patrz §3.2.
- Kasowanie plików idzie **poza transakcją, po zatwierdzeniu** (komentarz,
  linie 209-218) — bo `ROLLBACK` nie przywróci skasowanego pliku.
- **Wpis do `audit_log` powstaje TYLKO gdy operacja faktycznie coś zmieniła**
  (`PurgeExpiredAccountDeletions.php:124-135`) — `AuditLogEntry::record('account.data_erased', null, $user, metadata: [...])`,
  aktor `null`, bo decyzję podjął zegar, nie moderator (ten sam wzorzec co
  `account.suspension_expired` w `RestoreExpiredSuspensions.php:64-68`).
  Komentarz przy wywołaniu (linie 129-132) mówi wprost, dlaczego zakres
  usunięcia (D-022, `delete_scope`) idzie do `metadata`, a nie tylko do
  `action` — „co dokładnie zrobiliśmy temu kontu musi dać się odczytać po
  fakcie, bez odtwarzania decyzji z pamięci".
- Błąd na jednym koncie nie blokuje reszty listy — każde konto to osobna
  transakcja (komentarz, linie 33-36).
- `--dry-run` (linie 56-57).

**Ten wzorzec jest właściwy dla `reports` + `moderation_actions` + `appeals`
razem** — bo w odróżnieniu od `product_signals`, te trzy tabele **zależą od
siebie nawzajem przez klucze obce z różnym zachowaniem przy kasowaniu**
(§4 niżej), więc zwykły, ślepy `DELETE` per tabela jest niebezpieczny.

#### Wspólny mianownik harmonogramu — `routes/console.php`

Każdy z pięciu istniejących wpisów w `routes/console.php` używa
**`Schedule::call(fn () => Artisan::call(...))`, nigdy `Schedule::command()`**.
Komentarz na górze pliku wyjaśnia dlaczego: `Schedule::command()` idzie przez
Symfony Process, który wymaga `proc_open` — wyłączonego w `docker/php.ini`
jako hardening, którego AGENTS.md zabrania osłabiać. `Schedule::call()`
wykonuje domknięcie w tym samym procesie PHP i nie potrzebuje `proc_open`.
**Każdy nowy wpis, który ten ADR proponuje, musi trzymać się tego samego
wzorca** — inaczej padnie na produkcji dokładnie tak, jak opisano w tym
komentarzu. Każdy istniejący wpis ma też `->withoutOverlapping()` i nazwę
(`->name(...)`) — to samo dotyczy propozycji w §5.

Zadania z dokładnym momentem wygaśnięcia (`kuking:zdejmij-wygasle-kary`)
chodzą **co godzinę**; zadania liczone w dniach (`kuking:usun-wygasle-konta`,
`kuking:sprzataj-sygnaly`, `kuking:sprzataj-eksporty`,
`kuking:sprzataj-osierocone-zdjecia`) chodzą **raz na dobę, w nocy**.
Wszystkie pięć propozycji w tym ADR-ze liczy się w miesiącach — więc
wszystkie idą do koszyka „raz na dobę".

---

## 2. Sprzeczności między dokumentacją a kodem — zmierzone

| # | Dokument | Twierdzenie | Zmierzony stan kodu | Rodzaj problemu |
|---|---|---|---|---|
| 1 | `resources/legal/polityka-prywatnosci.md:25` | „Obsługa zgłoszeń i moderacji … Dłużej niż inne dane — [do ustalenia z prawnikiem, orientacyjnie 12–24 miesiące od zamknięcia sprawy]" | Zero automatu dla `reports`/`moderation_actions` (§1.2) | **Publikowana obietnica liczbowa bez egzekucji** — dokładnie to, co D-024 (`docs/DECISIONS.md:981`) już zabroniło. Strona jest żywa: `routes/web.php:85` → `StaticPageController::privacy()` → `app/Http/Controllers/StaticPageController.php:43` renderuje ten plik pod `/prywatnosc`, tą samą metodą pomiaru, którą D-024 użyło („`GET .../prywatnosc` → HTTP 200"). |
| 2 | `resources/legal/polityka-prywatnosci.md:26` | „Bezpieczeństwo (logi, próby logowania, adresy IP) … Krótko, orientacyjnie do 90 dni" | Zero automatu dla `audit_log` (§1.2) | To samo co #1. Dodatkowo: liczba 90 dni pochodzi z modelu „logi ogólne o próbach logowania" (`docs/legal/COMPLIANCE.md:73`), a `audit_log` dziś rejestruje **25 różnych kategorii zdarzeń** (§3.1 niżej), z których część to dowód wykonania praw RODO/DSA — 90 dni skasowałoby je, zanim ktokolwiek zdążyłby się na nie powołać. |
| 3 | `resources/legal/polityka-prywatnosci.md:27` | „Powiadomienia w serwisie … Do przeczytania/usunięcia + rozsądny bufor techniczny" | Zero automatu dla `notifications` (§1.2) | Bez liczby, więc nie łamie litery reguły D-024 wprost — ale „rozsądny bufor techniczny" dziś w praktyce znaczy **bezterminowo**, co nie jest tym, co przeczyta 50-letni użytkownik w tym zdaniu. |
| 4 | `docs/legal/COMPLIANCE.md:72-74` | Tabela „sugerowana retencja" z tymi samymi liczbami (12–24 mies. dla zgłoszeń, 90 dni dla logów) | — | **Nie jest to samo w sobie sprzeczność** — kolumna nazywa się „sugerowana", nie „egzekwowana", i dokument jest jawnie oznaczony jako materiał dla prawnika/właściciela, nie kod. Ale to jest **źródło**, z którego niemal dosłownie przepisano #1 i #2 do polityki — czyli sugestia prawna przeciekła do publikowanego dokumentu, gubiąc po drodze zastrzeżenie, że nic jej nie wykonuje. |
| 5 | `docs/DATABASE.md:601-602` | Cała treść sekcji `notifications`: „In-app." | — | Nie ma tu twierdzenia do sprawdzenia — jest **brak** twierdzenia. `docs/SECURITY_PRIVACY_LEGAL.md:78-82` wymaga, żeby każda kategoria danych miała udokumentowany „cel; okres; sposób usunięcia; relację z backupami" — dla `notifications` nie ma żadnego z tych czterech punktów nigdzie w `docs/DATABASE.md`. |
| 6 | `docs/DATABASE.md:751-752` | Cała treść sekcji `audit_log`: „Wysokiego znaczenia zmiany." | — | To samo co #5, dla `audit_log`. Dla porównania: sekcja `product_signals` (`docs/DATABASE.md:857-865`) ma osobny akapit „**Retencja:** …" (`docs/DATABASE.md:857-862`) z konfigiem, komendą i harmonogramem — to jest wzorzec, którego brakuje tu i przy `reports`/`moderation_actions`/`appeals`. |
| 7 | `docs/legal/MODERATION_PLAYBOOK.md` | (brak wzmianki) | `grep -in "retenc\|przechowywan\|kasowan" docs/legal/MODERATION_PLAYBOOK.md` → 0 trafień | Playbook, z którego moderator korzysta na co dzień, nigdzie nie mówi, jak długo sprawa (zgłoszenie + decyzja + ewentualne odwołanie) zostaje w systemie po zamknięciu. To nie jest sprzeczność — to luka, którą §8 tego ADR-u każe zamknąć, gdy retencja wejdzie. |
| 8 | `docs/DECISIONS.md:979-985` (D-024) | „`audit_log`, `notifications`, `reports`, `appeals` i `moderation_actions` nie mają retencji żadnej" | Potwierdzone w §1.2 | **Ten dokument jest dziś dokładnie zgodny z kodem.** Wymieniam go tu nie jako sprzeczność, tylko żeby było jasne: ten ADR nie naprawia D-024 — kontynuuje je i wypełnia treścią, którą D-024 świadomie zostawiło jako przyszłą decyzję. |

**Poboczne, poza zakresem pięciu tabel, ale tego samego kształtu** —
odnotowuję, bo znalazłem to przy okazji, nie dlatego, że to zadanie:
`resources/legal/polityka-prywatnosci.md:79` ma nawias „[X dni — do
ustalenia, orientacyjnie 30 dni]" dla karencji usunięcia konta, mimo że
**ta akurat liczba JEST egzekwowana** — `config/kuking.php:232`
(`'delete_grace_days' => 30`) i `PurgeExpiredAccountDeletions` (§1.3,
wzorzec C). Nawias jest tu przestarzały w drugą stronę: ostrożniejszy, niż
wymaga stan kodu. To osobna, jednozdaniowa poprawka, nie wymaga automatu —
zostawiam ją właścicielowi, bo nie była częścią zlecenia.

---

## 3. Trzy trudności z treści zadania

### 3.1 `audit_log` a dowód wykonania art. 17

Zgadzam się z ramą zadania: skasowanie wiersza `account.data_erased`
niszczyłoby dowód, że prawo do usunięcia zostało wykonane. Zmierzyłem
dodatkowo **drugi, mocniejszy przypadek tego samego problemu**, którego
zadanie nie wymieniało wprost:

`app/Models/User.php:731-737` — `cancelDeletion()`:

```php
$this->forceFill([
    'status' => self::STATUS_ACTIVE,
    'delete_requested_at' => null,
    'delete_scope' => null,
])->save();
```

**Cofnięcie zgłoszenia usunięcia konta ZERUJE `delete_requested_at` na
wierszu `users`.** Po cofnięciu jedynym miejscem w całej bazie, które mówi,
że ktoś w ogóle zgłosił usunięcie konta i potem zmienił zdanie, są dwa wpisy
w `audit_log`: `account.delete_requested`
(`app/Http/Controllers/Settings/DataSettingsController.php:161-167`, z
zapisanym w `metadata` wyborem `delete_scope` — D-022) i
`account.delete_cancelled`
(`app/Http/Controllers/AccountDeletionController.php:100`). Skasowanie ich
razem z resztą `audit_log` po ogólnym okresie retencji **usuwa jedyny ślad
własnej decyzji użytkownika** — dokładnie ten typ dowodu, o który zapyta
regulator albo sam użytkownik przy sporze („nigdy nie prosiłem o usunięcie
konta").

**Rozstrzygnięcie (§5.1, §6):** obok `account.data_erased` do kategorii
NIGDY-NIE-KASUJ dołączam `account.delete_requested` i
`account.delete_cancelled` — z tego samego, zmierzonego powodu, nie z
ostrożności.

### 3.2 `moderation_actions.moderator_id` z `restrictOnDelete`

Zmierzone: `restrictOnDelete` stoi w
`database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php:48`.
**Ale dziś ten FK jest martwy — nic go nie uruchamia.**

`grep -rn "User::destroy\|User::forceDelete\|\$user->delete()\|\$fresh->delete()\|\$osoba->delete()" app/`
zwraca dokładnie jedno trafienie w całym `app/`, i to w komentarzu opisującym
błąd, który NAPRAWIONO: `app/Models/ModerationAction.php:56` mówi, że kiedyś
`remove` na zgłoszeniu osoby wywoływało `$user->delete()` — i to zostało
uznane za katastrofę (kasowało konto, profil, wszystkie treści kaskadowo,
bez potwierdzenia), naprawioną macierzą `DOZWOLONE` (linie 77-93), która
**nie dopuszcza `remove` przy `target_type = 'user'`** w ogóle. `User` w
ogóle nie ma `SoftDeletes` ani żadnej ścieżki `delete()`/`forceDelete()`/`destroy()`
wywołanej na nim samym, w żadnym pliku aplikacji.

`app/Domain/Users/Actions/EraseAccountData.php:72-224` — jedyna ścieżka,
która egzekwuje karencję po zgłoszeniu usunięcia konta — na wierszu `users`
robi wyłącznie `forceFill(...)->save()` (linie 135-151, 167): anonimizacja
e-maila, hasła, tokenu, przełączenie statusu na `erased`. **Nigdy nie
kasuje samego wiersza `users`.** Osobno, tylko gdy dana osoba przy usuwaniu
konta zaznaczyła zakres `delete_scope = everything` (D-022), metoda
`usunTresci()` (linie 294-306) robi `forceDelete()` na **jej własnych**
komentarzach/wpisach/przepisach — to kasuje TREŚĆ tej osoby, nie jej konto,
i nie dotyczy żadnej z pięciu tabel tego ADR-u (`reports`,
`moderation_actions`, `appeals`, `audit_log`, `notifications` nie są tu
ruszane w ogóle). Wniosek dla `restrictOnDelete` na `moderator_id` (niżej)
zostaje ten sam: nic w kodzie nie próbuje skasować wiersza `users` moderatora.

**Wniosek, którego zadanie nie zakładało wprost:** dziś retencja
`moderation_actions` **nie blokuje** porządkowania kont moderatorów, bo nic
nigdy nie próbuje fizycznie skasować wiersza `users` moderatora. `restrictOnDelete`
jest zabezpieczeniem na wypadek, gdyby ktoś **kiedyś** dodał taką ścieżkę
(ręczne czyszczenie w psql, przyszła funkcja „twarde usunięcie" różna od
dzisiejszego `markForDeletion()` + anonimizacji) — i wtedy, i tylko wtedy,
okres retencji `moderation_actions` zacznie mieć realny wpływ na to, kiedy
konto byłego moderatora będzie można fizycznie usunąć zamiast tylko
zanonimizować. Ponieważ ta ścieżka nie istnieje, **ten ADR nie musi
rozwiązywać konfliktu, który dziś nie występuje** — ale odnotowuję go
w §7 jako pytanie otwarte, żeby nie zniknęło.

### 3.3 Zgłoszenia i odwołania jako dowód w sporze DSA, a dane osób trzecich

Prawda, i to w obie strony jednocześnie:

- **Dowód:** `app/Models/ModerationAction.php:16-17` — `user_message` to
  „treść, którą realnie zobaczył użytkownik. Trzymamy ją, bo przy odwołaniu
  musimy wiedzieć, co mu powiedzieliśmy (DSA art. 17)". `appeals.decision_note`
  ma CHECK w bazie (`database/migrations/2026_09_06_100100_create_appeals_table.php:77`)
  wymuszający, że rozpatrzone odwołanie MUSI mieć uzasadnienie — to jest
  bezpośrednie przepisanie DSA art. 20 na ograniczenie bazy, nie tylko na
  walidację formularza.
- **Dane osób trzecich:** `reports.reporter_id`, `reports.notifier_name`,
  `reports.notifier_email` (dla `source = 'legal_notice'`,
  `database/migrations/2026_09_06_200000_add_legal_notice_fields_to_reports.php:60-61`)
  to dane osoby, która NIE jest stroną decyzji moderacyjnej — może nawet nie
  mieć konta (art. 16 ust. 1 DSA: zgłaszać może każdy). `moderation_actions.subject_user_id`
  (dodane migracją `2026_09_06_100000_add_context_to_moderation_actions.php:59-60`)
  wskazuje osobę UKARANĄ, czyli inną stronę tej samej sprawy.

**To nie da się rozstrzygnąć jednym „usuwaj po X" bez dodatkowego podziału,
bo te dwie role (zgłaszający / zgłoszony) różnią się podstawą prawną
przechowywania** — zgłaszający ma słabszą podstawę do bycia trzymanym długo
(jego jedyna rola to bycie źródłem informacji), a zgłoszony/ukarany ma silną
podstawę po stronie operatora (obrona przed zarzutem bezpodstawnej decyzji).
Rozstrzygam to w §5.3 wyborem: **jeden okres na całą tabelę `reports`**, bo
podział wg roli w obrębie jednej tabeli i jednego okresu nie zmniejsza
niczyjego ryzyka (oba pola żyją w tym samym wierszu, więc skasowanie jednego
pola przy zachowaniu drugiego wymagałoby nowej migracji, nie retencji), ale
**nazywam podział wg `source` (`community` vs `legal_notice`) jako wariant
do decyzji właściciela w §6**, bo zgłoszenia prawne niosą więcej danych
zgłaszającego (imię, e-mail) niż zgłoszenia społecznościowe i mogłyby
uzasadniać inny reżim.

---

## 4. Czwarta trudność — znaleziona w migracjach, nie w treści zadania: kaskada `appeals` ← `moderation_actions`

`database/migrations/2026_09_06_100100_create_appeals_table.php:52-53`:

```php
$table->foreignUuid('moderation_action_id')->unique()
    ->constrained('moderation_actions')->cascadeOnDelete();
```

**Skasowanie wiersza `moderation_actions` kasuje jego `appeals` razem z
nim, bez pytania.** To jest zamierzone dla ręcznego `DELETE` (komentarz
migracji, linie 50-51: „odwołanie bez decyzji nie znaczy nic") — ale ma
konsekwencję dla automatu retencji, której migracja nie musiała rozważać,
bo automat wtedy nie istniał: **jeśli komenda retencji usunie
`moderation_actions` na podstawie WYŁĄCZNIE jego własnego wieku, nie
sprawdzając odwołania, to odwołanie zniknie, nawet jeśli jego WŁASNY okres
retencji jeszcze nie minął.** To jest dokładnie ten sam kształt błędu,
przed którym ostrzega `reports_legal_notice_complete_check` i inne CHECK-i
w tym samym pliku — cichy skutek uboczny operacji, która z osobna wygląda
na poprawną.

Dla porównania: `moderation_actions.report_id` ma `nullOnDelete()`
(`database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php:49`)
— skasowanie `reports` NIE kasuje `moderation_actions`, tylko zeruje
odniesienie. Ten kierunek jest bezpieczny sam z siebie.

**Rozstrzygnięcie (reguła dla automatu, szczegóły w §5.4-5.5):**

> Wiersz `moderation_actions` jest kandydatem do usunięcia dopiero, gdy
> (a) minął jego własny okres retencji, **oraz** (b) albo nie ma żadnego
> powiązanego `appeals`, albo powiązane `appeals` samo już przekroczyło
> swój własny okres retencji. Odwołanie w stanie `open` blokuje usunięcie
> decyzji bezwarunkowo, niezależnie od wieku.

To nie jest komplikacja dla samej komplikacji — to warunek konieczny, żeby
retencja `appeals` (§5.5) w ogóle znaczyła to, co ma znaczyć, zamiast być
iluzoryczną liczbą, którą kasuje FK zanim zdąży zadziałać.

---

## 5. Propozycja retencji, tabela po tabeli

Każda pozycja: okres (i od czego liczony), wyjątek, co robi automat, jak
często, co przy błędzie. Nazwy komend i configu są PROPOZYCJĄ do przeglądu,
nie są utworzone.

### 5.1 `audit_log`

| | |
|---|---|
| **Okres (domyślny, nie-wyjątkowe kategorie)** | Wariant do decyzji właściciela — §6. Rekomendacja: **24 miesiące** od `created_at`. |
| **Wyjątek — nigdy nie kasować automatem** | `account.data_erased`, `account.delete_requested`, `account.delete_cancelled` — z powodów w §3.1. |
| **Co robi automat** | Wzorzec B (§1.3): `DELETE FROM audit_log WHERE created_at < próg AND action NOT IN (wyjątki)`. Bez efektu ubocznego poza bazą — jeden `DELETE`, bez `chunkById`. |
| **Harmonogram** | Codziennie w nocy, np. 04:10 (po `kuking:sprzataj-sygnaly` o 04:00) — `Schedule::call()`, `withoutOverlapping()`, `dailyAt`. |
| **Błąd** | Pojedyncze zapytanie DB — albo się wykona w całości, albo w ogóle (atomowość jednej instrukcji SQL). Przy porażce: `Log::error` z komunikatem, następny przebieg dobiera to samo (predykat to sam wiek wiersza, nic do „zapamiętania" między przebiegami). |

**Dlaczego lista wyjątków nie jest dłuższa.** `moderation.decided`,
`moderation.restored`, `content.reported`, `appeal.filed`, `appeal.resolved`
— te pięć kategorii TEŻ dotyczy spraw moderacyjnych, ale ich pełny,
autorytatywny zapis żyje w `moderation_actions`/`appeals`/`reports`, którym
ten ADR daje **dłuższy** okres (36 miesięcy, §5.3-5.5) niż domyślny okres
`audit_log` (24 miesiące, rekomendacja). Wpis w `audit_log` o tych
zdarzeniach jest więc **cieńszą kopią**, która może wygasnąć wcześniej bez
utraty dowodu — dowód pełny nadal stoi w tabeli dedykowanej. To wymaga
utrzymania relacji: **domyślny okres `audit_log` nie powinien być dłuższy
niż okres `moderation_actions`/`appeals`/`reports`** — inaczej echo w logu
przeżyłoby własne źródło, co nie jest błędem technicznym, ale jest
nielogiczne i warte uniknięcia przy wyborze liczb w §6.

### 5.2 `notifications`

| | |
|---|---|
| **Okres** | Wariant — §6. Rekomendacja: **24 miesiące** od `created_at`, niezależnie od `read_at`. |
| **Wyjątek** | Brak kategorii dowodowej — to nie jest tabela o charakterze audytowym. |
| **Co robi automat** | Wzorzec B: `DELETE FROM notifications WHERE created_at < próg`. |
| **Harmonogram** | Codziennie w nocy, np. 04:20. |
| **Błąd** | Jak w §5.1 — pojedynczy `DELETE`, naturalny retry następnego dnia. |

**Napięcie, które trzeba nazwać, nie ukryć.** `AGENTS.md` (część 1) mówi
wprost: „«Ugotowałem» … generuje najcenniejsze powiadomienie w całym
serwisie" — `Notification::TYPE_COOKED` (`app/Models/Notification.php:27`).
To jest jedyna z pięciu tabel, gdzie retencja koliduje nie z prawem, tylko
z **produktem**: usunięcie starego powiadomienia „ktoś ugotował z Twojego
przepisu" kasuje coś, co dla użytkownika 50+ ma wartość emocjonalną, nie
tylko informacyjną. Rekomendacja 24 miesięcy jest kompromisem — dłużej niż
`audit_log` nie musi być, bo `notifications.data` jest kopią treści
zdenormalizowaną w chwili powstania (`app/Domain/Moderation/Actions/NotifyModerationDecision.php:109-131` —
`title`/`message`/`decision` zapisane wprost, nie przez `join`), więc nawet
gdy powiadomienie moderacyjne przeżyje swoją `moderation_actions`, treść,
którą użytkownik zobaczył, zostaje czytelna sama w sobie.

**Skutek uboczny do odnotowania (nie do rozwiązania tutaj, patrz §7):**
`notifications.data.action_id` bywa identyfikatorem `moderation_actions`
używanym w linku „Odwołaj się"
(`resources/views/pages/notifications.blade.php:126-128`,
`route('appeals.show', $data['action_id'])`). Gdyby `moderation_actions`
zostało skasowane (po 36 miesiącach), a powiadomienie o nim przeżyło (bo ma
inny, dłuższy zegar) — link prowadziłby donikąd. W praktyce to się nie
zdarzy w ciągu okna odwołania (14 dni, `config/kuking.php:560`), ale przy
rekomendowanych liczbach (`notifications` 24 mies. < `moderation_actions`
36 mies.) powiadomienie i tak wygaśnie pierwsze — kolejność w tym ADR-ze
jest dobrana właśnie tak, żeby to ryzyko było zerowe przy zalecanych
wartościach domyślnych, nie przypadkiem.

### 5.3 `reports`

| | |
|---|---|
| **Okres** | Wariant — §6 (12 / 24 / 36 miesięcy). **Rekomendacja: 36 miesięcy** od zamknięcia sprawy. |
| **Liczone od** | `resolved_at` dla `status IN ('resolved','rejected')`. Wiersze w `status IN ('open','triage','reviewing')` **nigdy nie są kandydatem do usunięcia**, niezależnie od `created_at` — sprawa otwarta nie ma „zamknięcia", od którego liczyć. |
| **Wyjątek wewnątrz tabeli** | Brak osobnej kategorii do wyłączenia (w odróżnieniu od `audit_log`) — cała tabela ma jeden reżim. Zobacz jednak wariant „podział wg `source`" w §6, wynikający z §3.3. |
| **Co robi automat** | Wzorzec C (transakcja per wiersz, nie ślepy masowy `DELETE`) — bo usunięcie `reports` jest bezpieczne samo z siebie (`nullOnDelete` na `moderation_actions.report_id`), ale komenda powinna i tak iść wiersz po wierszu w RAMACH tej samej rutyny co §5.4-5.5, żeby dziennik działania (`--dry-run`, logi, liczniki) opisywał całą „sprawę" spójnie, a nie trzema niezależnymi komendami, które ktoś uruchomi w złej kolejności. |
| **Harmonogram** | Codziennie w nocy, np. 04:30 — razem z §5.4 i §5.5 jako jedna komenda, patrz niżej. |
| **Błąd** | Osobna transakcja na sprawę (wzorzec C) — porażka jednej sprawy nie blokuje reszty, log z identyfikatorem `reports.id`, naturalny retry następnego dnia. |

**Uzasadnienie 36, nie 12 ani 24 (odpowiedź na wprost zadane pytanie).**
Polskie ogólne terminy przedawnienia roszczeń cywilnych to zwykle 6 lat
(art. 118 k.c.), a dla czynów niedozwolonych — 3 lata od dowiedzenia się
o szkodzie i osobie odpowiedzialnej (art. 442¹ k.c.). Spór o decyzję
moderacyjną ([rzekomo] bezpodstawne ukrycie/zablokowanie) najbliżej pasuje
do tego drugiego reżimu. DSA nie ma jeszcze w Polsce ugruntowanej,
odrębnej instytucji przedawnienia dla skarg do Koordynatora ds. Usług
Cyfrowych — więc oparcie się o ogólny, trzyletni termin deliktowy jest
najbardziej bronioną z trzech opcji, a nie liczbą wybraną „w sam raz między
12 a 36". **12 miesięcy** minimalizuje dane najmocniej, ale zostawia
operatora bez obrony, jeśli ktoś zgłosi sprawę do UODO albo pozwie
w 13. miesiącu. **24 miesiące** to bezpieczny środek, spójny z domyślnym
`audit_log` (§5.1) — dobra opcja, jeśli właściciel chce jednej liczby
w całym systemie zamiast dwóch.

### 5.4 `moderation_actions`

| | |
|---|---|
| **Okres** | Ten sam wariant co `reports` — **rekomendacja 36 miesięcy** od `created_at` (kolumna jest niemutowalna — `public const UPDATED_AT = null;`, `app/Models/ModerationAction.php:23` — decyzja jest ostateczna w chwili zapisu). |
| **Wyjątek/blokada** | **Nigdy, jeśli ma powiązany `appeals` w stanie `open`** — reguła z §4. To nie jest wyjątek kategorii zdarzenia (jak w `audit_log`), tylko wyjątek stanu relacji. |
| **Co robi automat** | Wzorzec C, część tej samej komendy co §5.3/§5.5. Kolejność wewnątrz jednego przebiegu: **najpierw `appeals`, potem `moderation_actions`, na końcu `reports`** (patrz reguła §4 — usuwając `appeals` jako pierwsze, `moderation_actions` już „wie", czy ma jeszcze żywe odwołanie, bez dodatkowego zapytania specjalnego). |
| **Harmonogram** | Jak §5.3 — jedna komenda, jedno uruchomienie dziennie. |
| **Błąd** | Transakcja per wiersz. Ważne: błąd przy kasowaniu `appeals` dla danej decyzji musi **zablokować** kasowanie tej konkretnej `moderation_actions` w tym przebiegu (nie może zgadywać, że się udało) — retry następnego dnia obejmie oba wiersze razem. |

**`moderator_id` i `restrictOnDelete` (§3.2).** Ten ADR nie proponuje zmiany
zachowania FK — bo dziś nic go nie uruchamia (zmierzone). Gdyby w
przyszłości powstała ścieżka fizycznego usuwania konta moderatora, okres
36 miesięcy zostawia praktyczne ryzyko: były moderator z choćby jedną
decyzją sprzed niespełna trzech lat nie dałby się fizycznie skasować, dopóki
ten wiersz `moderation_actions` nie wygaśnie z retencji albo nie zostanie
przypisany do innego moderatora. To jest zachowanie **zamierzone** (log
decyzji przeżywa odejście osoby, która ją podjęła) — odnotowuję je jako
świadomą konsekwencję, nie usterkę.

### 5.5 `appeals`

| | |
|---|---|
| **Okres** | Ten sam wariant co `reports`/`moderation_actions` — **rekomendacja 36 miesięcy** od `decided_at`. |
| **Liczone od** | `decided_at`, tylko dla `status IN ('upheld','overturned')`. `status = 'open'` **nigdy** nie jest kandydatem — CHECK w bazie (`appeals_decision_complete_check`, `database/migrations/2026_09_06_100100_create_appeals_table.php:77`) i tak wymusza, że otwarte odwołanie nie ma `decided_at`, więc formalnie nie da się go objąć warunkiem czasowym opartym o tę kolumnę — dodatkowy `WHERE status <> 'open'` jest tu obroną w głąb, nie samą tylko konsekwencją CHECK-a. |
| **Wyjątek** | Brak osobnej kategorii — cała tabela jeden reżim, jak `reports`. |
| **Co robi automat** | Wzorzec C, **pierwszy krok** wspólnej komendy (§4, §5.4). |
| **Harmonogram** | Jak §5.3-5.4. |
| **Błąd** | Transakcja per wiersz; porażka nie blokuje innych odwołań ani nie odblokowuje kasowania rodzica (§5.4). |

**Dlaczego 36, nie inny okres niż `moderation_actions`.** Gdyby `appeals`
miało DŁUŻSZY okres niż `moderation_actions`, reguła z §4 skutecznie
wymuszałaby, że `moderation_actions` nigdy się nie starzeje szybciej niż
jego odwołanie — czyli realny okres `moderation_actions` byłby okresem
`appeals`, niezależnie od tego, co wpisano przy `moderation_actions`. Równe
liczby dla obu usuwają tę pułapkę wprost, zamiast zostawiać ją do odkrycia
przy pierwszym audycie.

---

## 6. Warianty do decyzji właściciela — zbiorczo

| Decyzja | Warianty | Rekomendacja | Uzasadnienie skrótowo |
|---|---|---|---|
| Wspólny okres `reports` + `moderation_actions` + `appeals` | 12 / 24 / **36** miesięcy od zamknięcia sprawy | **36** | Art. 442¹ k.c. (3 lata na roszczenie deliktowe) jako najbardziej broniona kotwica przy braku ugruntowanego terminu DSA w polskim prawie (§5.3). |
| Domyślny okres `audit_log` (kategorie nie-wyjątkowe) | 12 / **24** / 36 miesięcy od `created_at` | **24** | Krócej niż „sprawy moderacyjne" (bo pełny dowód i tak żyje w tabelach dedykowanych, §5.1), dłużej niż `product_signals` (bo `audit_log` z definicji dokumentuje „zmiany wysokiego znaczenia", `docs/DATABASE.md:752`, nie czystą telemetrię). |
| Okres `notifications` | 12 / **24** / 36 miesięcy od `created_at` | **24** | Zgodność z `audit_log`, z zastrzeżeniem napięcia produktowego opisanego w §5.2. |
| `notifications`: liczyć wiek od `created_at` niezależnie od `read_at`, czy inaczej dla przeczytanych/nieprzeczytanych | (A) jeden wiek dla wszystkich (B) nieprzeczytane nigdy nie wygasają, przeczytane liczą wiek od `read_at` | **(A)** | Prostsze, zgodne z zasadą minimalizacji wprost — (B) ryzykuje bezterminowe trzymanie powiadomień, których ktoś nigdy nie otworzy (czyli już nigdy nie otworzy). |
| `reports`: jeden okres dla całej tabeli, czy osobno dla `source = 'community'` i `source = 'legal_notice'` | (A) jeden okres (B) `legal_notice` dłużej, bo niesie więcej PII zgłaszającego i ma własny reżim DSA art. 16 | **(A) na start**, (B) jeśli przyszły audyt DSA tego zażąda | Podział zwiększa złożoność automatu (dwa progi w jednej tabeli) bez zmierzonej dziś potrzeby — `reports_source_status_idx` (`2026_09_06_200000...php:131`) już rozróżnia źródło, więc dodanie drugiego progu później jest tanie, gdyby było potrzebne. |
| `audit_log`: czy `moderation.decided`/`content.reported`/`appeal.*` też mają wejść na listę NIGDY-NIE-KASUJ | (A) nie — echo krótsze niż źródło (rekomendowane, §5.1) (B) tak — podwójne zabezpieczenie kosztem większej tabeli bezterminowej | **(A)** | Patrz uzasadnienie w §5.1 — (B) jest bronioną opcją, jeśli właściciel woli redundancję nad rozmiar tabeli. |

**Każda liczba w tej tabeli jest rekomendacją agenta badawczego, nie decyzją
właściciela.** Dopóki właściciel nie wybierze wiersza w każdej z pięciu
pozycji, żadna z nich nie trafia do `resources/legal/polityka-prywatnosci.md`
(§0, §9).

---

## 7. Czego ten ADR nie rozstrzyga

- **Backupy bazy danych.** `docs/SECURITY_PRIVACY_LEGAL.md:82` i
  `docs/legal/COMPLIANCE.md:132-134` wymagają osobno podanego maksymalnego
  czasu życia kopii zapasowej z danymi po usunięciu wiersza — to jest
  decyzja infrastrukturalna (Railway/`pg_dump`/harmonogram backupów), poza
  zasięgiem retencji na poziomie aplikacji, którą opisuje ten dokument.
  Skasowany wiersz `reports` może żyć w backupie miesiącami dłużej, niż
  mówi ten ADR — to musi być osobne zdanie w polityce, nie ukryte w tym.
- **Co dzieje się z `restrictOnDelete` na `moderator_id`, jeśli kiedyś
  powstanie ścieżka fizycznego usuwania konta** (§3.2, §5.4) — dziś
  teoretyczne, bo taka ścieżka nie istnieje.
- **Podział `reports` wg `source`** (§6, wariant B) — zostawiony jako
  opcja, nie decyzja.
- **Martwe linki `notifications.data.action_id`/`appeal_id`** po wygaśnięciu
  `moderation_actions`/`appeals` (§5.2) — dziś ryzyko praktycznie zerowe przy
  rekomendowanych liczbach, ale widok (`resources/views/pages/notifications.blade.php:126-128`)
  nie ma dziś obsługi „ten cel już nie istnieje" i ten ADR jej nie dodaje.
- **Retencja `product_signals` i `data_exports`** — już mają automat i
  własne decyzje (issue #115, D-018/A8) — nie są tu ruszane ani na nowo
  uzasadniane.
- **Anonimizacja treści (`posts`/`recipes`/`comments`)** przy usunięciu
  konta — to D-018, inna tabela, inny mechanizm, poza zakresem zlecenia.
- **Archiwizacja zamiast twardego `DELETE`.** Ten ADR zakłada, że wygasłe
  wiersze są kasowane, nie przenoszone do zimnego storage. Jeśli właściciel
  chce zachować je do celów statystycznych w formie zanonimizowanej,
  to jest osobna decyzja architektoniczna (nowa tabela/eksport), nie
  rozszerzenie tego ADR-u.
- **Dokładna treść komunikatu dla moderatora** w `docs/legal/MODERATION_PLAYBOOK.md`
  o tym, że sprawa zniknie z systemu po N miesiącach — mechanizm tak,
  redakcja tekstu nie (to zadanie dla kogoś, kto pisze wg
  `docs/brand/COPY_STYLE.md`, nie dla tego ADR-u).
- **Nazwy komend i kluczy configu podane w §5 są propozycją do przeglądu
  przy implementacji, nie ostatecznym API.**

---

## 8. Co trzeba zaktualizować, gdy retencja wejdzie (poza samą polityką)

- `docs/DATABASE.md:602` (`notifications`) i `docs/DATABASE.md:752`
  (`audit_log`) potrzebują akapitu „**Retencja:** …" w kształcie, w jakim
  ma go `product_signals` (`docs/DATABASE.md:857-862`) — AGENTS.md §6 wymaga
  aktualizacji `docs/DATABASE.md` przy każdej zmianie schematu/zachowania,
  a to jest dokładnie taka zmiana.
- `docs/DATABASE.md` — sekcje `reports` (604-672), `moderation_actions`
  (673-713), `appeals` (714-750) potrzebują tego samego akapitu.
- `docs/legal/COMPLIANCE.md:72-74` — kolumna „Sugerowana retencja" traci
  nawias „[do ustalenia z prawnikiem]" i dostaje **zatwierdzoną** liczbę,
  z odnośnikiem do tego ADR-u.
- `docs/legal/MODERATION_PLAYBOOK.md` — dopisać, ile sprawa (zgłoszenie +
  decyzja + ewentualne odwołanie) zostaje w systemie po zamknięciu, żeby
  moderator wiedział, czego szukać, a czego już nie znajdzie.
- `docs/DECISIONS.md` — nowy wpis (D-0xx) odnotowujący, że ten ADR
  wszedł w życie, w tym samym stylu co D-024, z datą wdrożenia automatu
  i pierwszym zaobserwowanym uruchomieniem na produkcji.

---

## 9. Co trzeba zmienić w polityce prywatności, gdy to wejdzie — dosłowne brzmienie

**Warunek wstępny dla każdej z trzech zmian: automat istnieje, ma test,
jest w `routes/console.php` i zaobserwowano co najmniej jedno jego
uruchomienie na produkcji. Żadna z nich nie wchodzi wcześniej — to jest
literalne zastosowanie reguły z §0/D-024, nie nowa reguła.**

### 9.1 `resources/legal/polityka-prywatnosci.md:25`

Dziś: *„Obsługa zgłoszeń i moderacji … Dłużej niż inne dane —
[do ustalenia z prawnikiem, orientacyjnie 12–24 miesiące od zamknięcia
sprawy]"*

Po wdrożeniu (przy przyjęciu rekomendacji §6 — 36 miesięcy), nowe zdanie
w tej samej komórce tabeli:

> „36 miesięcy od zamknięcia sprawy (decyzji moderatora albo rozstrzygnięcia
> odwołania, jeśli je złożono)."

### 9.2 `resources/legal/polityka-prywatnosci.md:26`

Dziś: *„Bezpieczeństwo (logi, próby logowania, adresy IP) … Krótko,
orientacyjnie do 90 dni"*

Po wdrożeniu (przy przyjęciu rekomendacji §6 — 24 miesiące), nowe zdanie —
**musi zawierać wyjątek**, bo RODO wymaga przejrzystości co do wyjątków od
ogólnej zasady, nie tylko co do samej zasady:

> „24 miesiące od zapisania wpisu w dzienniku zdarzeń — z wyjątkiem wpisów
> potwierdzających zgłoszenie, cofnięcie albo wykonanie usunięcia Twojego
> konta, które zachowujemy bezterminowo jako dowód, że Twoje żądanie
> zostało wykonane."

### 9.3 `resources/legal/polityka-prywatnosci.md:27`

Dziś: *„Powiadomienia w serwisie … Do przeczytania/usunięcia + rozsądny
bufor techniczny"*

Po wdrożeniu (przy przyjęciu rekomendacji §6 — 24 miesiące):

> „24 miesiące od otrzymania powiadomienia, niezależnie od tego, czy
> zostało przeczytane."

**Żadne z tych trzech zdań nie wolno wkleić do pliku, dopóki liczba w nim
nie jest tą samą liczbą, którą wykonuje kod — nie przybliżoną, nie
zaokrągloną „dla ładności zdania".** To jest dokładnie błąd, który D-024
już raz znalazło i nazwało (opublikowany placeholder retencji,
`docs/DECISIONS.md:966`) — ten ADR istnieje, żeby drugi raz się nie
powtórzył.
