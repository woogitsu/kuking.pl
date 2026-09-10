# Analityka Kuking.pl — co jest ZBUDOWANE

Ten plik opisuje analitykę, która NAPRAWDĘ istnieje w kodzie: gdzie mieszka,
co liczy, czego nie zbiera i jak to uruchomić. Jest opisem stanu, nie planem.

## 0. Dlaczego ten plik w ogóle powstał — i sprostowanie

> **SPROSTOWANIE Z 7 WRZEŚNIA 2026.** Pierwsza wersja tego pliku nosiła
> nazwę `ANALITYKA.md` i twierdziła: *„Tego pliku nigdy nie było
> w repozytorium. Nikt go nie zacommitował."* **To było nieprawdziwe.**
>
> `docs/research/ANALITYKA.md` istnieje — 36 KB, 494 linie — na gałęziach
> `research/analityka-monetyzacja` i `claude/kuking-research-audit-16gpve`.
> Nigdy nie został scalony do `main` ani do gałęzi rozwojowej, więc nie
> widziałem go, patrząc tylko na swoje drzewo robocze. „Nie ma go tutaj"
> i „nikt go nie napisał" to dwa różne zdania, a ja napisałem drugie,
> mając dowód tylko na pierwsze.
>
> Wskazał to audyt zewnętrzny (GPT-6 Astra, U13) i miał rację. Oryginał
> wrócił pod swoją nazwę, a ten dokument dostał nazwę mówiącą, czym
> naprawdę jest: **opisem stanu wdrożenia**, nie planem.

Issues **#114** i **#115** odsyłały do `ANALITYKA.md` po zapytanie WAC,
schemat `product_signals` i politykę retencji — i ten dokument je ma. §1.3
oryginału opisuje lukę „WAC policzyłby konta, których nie powinien" wraz
z wykluczeniem gospodarza i kont testowych; §2.3 opisuje ścieżkę
`product_signals`; §3.5 ustala 90 dni retencji.

**Kod zgadza się z tym specem**, choć powstał z treści samych issues:
`kuking:wac` ma wykluczenia gospodarza i listy `test_usernames`
(`config/kuking.php`), a `product_signals` mają 90 dni z realnym zadaniem
w harmonogramie. To był szczęśliwy zbieg, nie zasługa — gdyby spec mówił
co innego, zbudowałbym co innego.

**Jedna rzecz z oryginału, której nie miał ten dokument, i która ma
znaczenie prawne:** §3.5 wyjaśnia, DLACZEGO 90 dni, a nie 6–14 miesięcy
z `docs/legal/COMPLIANCE.md` — bo `product_signals` niesie `user_id` per
wiersz, nie dane zagregowane z góry, więc zasada minimalizacji każe trzymać
je krócej. Obecna polityka prywatności obiecuje przy analityce „6–14
miesięcy", co jest niezgodne z kodem; uzasadnienie do poprawki siedzi
właśnie w tamtym paragrafie.

Poniżej opisuję **stan faktyczny wdrożenia**, żeby dało się odróżnić plan
od tego, co realnie działa.

**Podział ról między trzema plikami:**

| Plik | Co to jest |
|---|---|
| `docs/seo/ANALYTICS.md` | **Plan i research.** Pełna taksonomia zdarzeń PostHog, drzewo metryk, kohorty, dashboard. Opisuje docelowy kształt, w większości jeszcze niezbudowany. |
| `docs/research/ANALITYKA.md` (ten plik) | **Stan wdrożenia.** Co z tego istnieje w kodzie, gdzie i jak uruchomić. |
| `docs/DECISIONS.md` | Decyzje, których nie relitygujemy. |

**PostHog nie jest wpięty.** Cała taksonomia z `docs/seo/ANALYTICS.md` §1 to
plan. Wszystko, co dziś realnie mierzymy, liczy się z PostgreSQL.

---

## 1. North Star: Weekly Active Cooks

**Definicja operacyjna:** liczba różnych kont, które w danym tygodniu
kalendarzowym (poniedziałek–niedziela **czasu polskiego**) zrobiły
przynajmniej jedną z trzech rzeczy:

- opublikowały wpis (`posts`, `published()`),
- opublikowały przepis (`recipes`, `published()`),
- odnotowały „Ugotowałem" (`cooked_events`).

Uruchomienie:

```
php artisan kuking:wac                # wszystkie tygodnie z aktywnością
php artisan kuking:wac --tygodnie=8   # osiem ostatnich
```

### 1.1 Kto się NIE liczy

`App\Domain\Analytics\CookEligibility` — jedno miejsce dla obu metryk
(WAC i kohorty retencji). Wyklucza:

- konta ze statusem `banned` i `pending_delete`,
- gospodarza (`config('kuking.community.host_username')`),
- konta testowe (`config('kuking.account.test_usernames')`).

Nazwy porównywane bez rozróżniania wielkości liter, tak jak
`Profile::poNazwie()`.

**Po co:** zapytanie z `docs/seo/ANALYTICS.md` §2.2 liczyło wszystkich.
Przy 20-50 kontach zamkniętej alfy gospodarz — który pisze co tydzień
z definicji — jest zauważalnym zniekształceniem liczby czytanej jako dowód
sukcesu.

To NIE jest to samo co `User::scopeDostepnyJakoAutor()`. Tamta reguła mówi
„czyją treść wolno pokazać", ta mówi „kogo wolno policzyć". Gospodarz jest
w pełni widoczny — po prostu nie zasila metryki.

### 1.2 Gdzie co mieszka

| Klasa | Odpowiada za |
|---|---|
| `App\Domain\Analytics\CookActivity` | Trzy źródła aktywności zunifikowane do `(user_id, activity_at)` — `weekly_cook_activity` z `docs/seo/ANALYTICS.md` §2.2/§3.2. Jedna kopia dla obu metryk. |
| `App\Domain\Analytics\CookEligibility` | Kto się nie liczy (§1.1). |
| `App\Domain\Analytics\WeeklyActiveCooks` | WAC tygodniowo. |
| `App\Domain\Analytics\CookRetentionCohorts` | Kohorta retencji tygodniowej. `week_offset = 0` to tydzień rejestracji, `1` ≈ D7, `4` ≈ D30. |
| `App\Console\Commands\ReportWeeklyActiveCooks` | Tylko formatowanie wyniku. |

Soft delete jest wykluczony globalnym scope'em `SoftDeletes` na `Post`
i `Recipe`. `cooked_events` nie ma soft delete w MVP, więc liczone są
wszystkie wiersze.

### 1.3 Tydzień jest polski i to nie jest drobiazg

`date_trunc('week', activity_at)` na kolumnie `timestamptz` obcina tydzień
w strefie SESJI Postgresa. To repozytorium tej strefy **nigdzie nie ustawia** —
`config/database.php` nie ma klucza `timezone` dla `pgsql` — więc sesja brała
domyślną strefę SERWERA bazy. Wynikały z tego dwie rzeczy:

1. Aktywność z poniedziałku 00:30 czasu polskiego wpadała do tygodnia
   poprzedniego.
2. Te same dane dawały **inny WAC na innym serwerze**, bez jednej zmiany
   w kodzie i bez żadnego sygnału.

Oba zapytania używają teraz `Czas::wStrefieCzlowieka()`, które wstawia
`at time zone 'Europe/Warsaw'` jawnie. `WacLiczyTydzienWStrefieCzlowiekaTest`
pilnuje jednego i drugiego — drugi test przestawia strefę sesji w locie
(`SET TIME ZONE`) i wymaga, żeby wynik się nie zmienił.

Kohorty liczą tydzień rejestracji i tydzień aktywności tą samą funkcją,
celowo: `week_offset` to różnica między nimi, więc rozjazd o jeden dzień
przesuwałby całe kohorty.

---

## 2. Sygnały produktowe (`product_signals`)

Tabela z issue #115: wąska, na dokładnie **dwa** zdarzenia. Nie jest to
`product_events` z `docs/seo/ANALYTICS.md` §7 — tamto to osobny serwis na
wszystkie zdarzenia produktu, celowo okradziony tu z rozmachu.

### 2.1 Schemat

| Kolumna | Typ | Uwaga |
|---|---|---|
| `id` | `bigserial` | **Nie UUID** — wiersz nigdy nie trafia do adresu ani do odpowiedzi API. Ten sam przypadek co `audit_log`. |
| `user_id` | `uuid`, nullable, `ON DELETE SET NULL` | Sygnał przeżywa usunięcie konta, referencja do człowieka — nie (D-018). |
| `signal_name` | `varchar(60)` | CHECK na zamknięty zbiór. |
| `properties` | `jsonb`, domyślnie `{}` | CHECK zakazujący klucza `query_text`. |
| `occurred_at` | `timestamptz`, `useCurrent()` | |

Indeksy: `(signal_name, occurred_at DESC)` pod pytanie „ile danego zdarzenia
w oknie czasu" i osobny `(occurred_at)` pod retencję, która nie filtruje po
nazwie.

Migracja: `database/migrations/2026_09_06_220000_create_product_signals_table.php`.

### 2.2 Dwa CHECK-i, nie jeden

- `product_signals_signal_name_check` — zamknięty zbiór nazw. Liczy się to,
  czego baza NIE MOGŁA przyjąć, nie to, co akurat sprawdzał kod aplikacji
  w chwili przeglądu.
- `product_signals_no_query_text_check` — **druga linia obrony prywatności**,
  niezależna od tego, co dziś pisze `SearchController`:
  `CHECK (NOT jsonb_exists(properties, 'query_text'))`. Zamyka drogę temu
  kluczowi w CAŁYM `properties`, więc pomyłka w przyszłym kodzie kończy się
  odrzuconym INSERT-em, a nie cichym wyciekiem.

### 2.3 Co dokładnie zbieramy

**`photo_upload_failed`** — `App\Domain\Media\Actions\StoreUploadedImage`.
`properties.reason` to kod z zamkniętego zbioru:

| Kod | Znaczenie |
|---|---|
| `unreadable` | Nie dało się odczytać pliku. |
| `too_large` | Ponad limit; dochodzi `bytes` i `max_bytes`. |
| `not_an_image` | Zawartość nie jest obrazem (`RozpoznanieZdjecia`). |
| `unsupported_format` | Format spoza listy. |
| `too_many_megapixels` | Za duża rozdzielczość. |
| `heic_unsupported` | Plik HEIC/HEIF — rozpoznany po magic bytes, ale nieobsługiwany (issue #119, D-064). Osobny kod od `not_an_image` WŁAŚNIE PO TO, żeby to zapytanie dało odpowiedź: `SELECT count(*) FROM product_signals WHERE signal_name = 'photo_upload_failed' AND properties->>'reason' = 'heic_unsupported' AND occurred_at > now() - interval '30 days'`. |

**Nigdy** nazwa pliku ani nic innego wpisanego przez człowieka.

**`search_performed`** — `App\Http\Controllers\SearchController`, PO
policzeniu wyników. Zapisujemy wyłącznie:

- `query_length` — długość frazy,
- `has_results` — czy cokolwiek znaleziono.

**Nigdy sama fraza.** Jest tekstem wpisanym przez człowieka, tej samej natury
co treść komentarza.

### 2.4 Zapis: `ZapiszSygnal` i tylko on

`App\Domain\Analytics\ZapiszSygnal` jest jedynym miejscem, które pisze do tej
tabeli. Dwa niezależne wywołania zapisu to dwie okazje, żeby jedno z nich
zaczęło pisać coś, czego nie powinno, i żeby nikt tego nie zauważył.

Trzy rzeczy w nim są nieoczywiste i wszystkie trzy wynikają z pomiarów:

1. **Zapis sygnału nigdy nie rzuca wyjątku dalej.** Sygnał jest efektem
   ubocznym prawdziwej operacji, nie jej warunkiem. Wyszukiwarka ma pokazać
   wyniki nawet wtedy, gdy wiersza nie da się zapisać.
2. **`DB::transaction()` WEWNĄTRZ try/catch, nie samo `create()`.** Samo
   try/catch nie wystarcza na PostgreSQL: nieudany INSERT w trakcie szerszej
   transakcji zatruwa ją całą („current transaction is aborted"), łącznie
   z operacją, którą ten kod ma chronić. `DB::transaction()` otwiera wtedy
   SAVEPOINT i cofa tylko jego.
3. **W logu klasa wyjątku i SQLSTATE, nigdy `getMessage()`.** Gdy CHECK
   odrzuca wiersz z frazą wyszukiwania — czyli dokładnie wtedy, gdy ochrona
   DZIAŁA — komunikat zawiera tę frazę dwa razy (postgresowe „DETAIL: Failing
   row contains…" i doklejone przez Laravela „SQL: insert into … values (…)").
   Zalogowanie go przeniosłoby dane osobowe z tabeli do pliku z logami.

### 2.5 Retencja

**90 dni**, `config('kuking.analytics.signal_retention_days')`
(`KUKING_SIGNAL_RETENTION_DAYS`). Kasuje `kuking:sprzataj-sygnaly`,
codziennie o 04:00 (`routes/console.php`), przez
`App\Domain\Analytics\PrzedawnioneSygnaly`.

Jeden masowy `DELETE ... WHERE occurred_at < ?`, nie `chunkById` jak przy
zdjęciach: wiersz `product_signals` nie ma odpowiednika na dysku, więc nie ma
czego powtarzać per wiersz.

```
php artisan kuking:sprzataj-sygnaly --na-sucho   # policz, nie kasuj
```

---

## 3. Czego NIE zbieramy — i to jest decyzja, nie zaniedbanie

- **Fraz wyszukiwania.** Tylko długość i „czy były wyniki". Pilnuje tego
  CHECK w bazie, nie tylko kod.
- **Nazw wgrywanych plików.** Tylko kod powodu odrzucenia.
- **Adresów IP w sygnałach.** `product_signals` nie ma takiej kolumny.
- **Treści naruszeń CSP.** `CspReportController` loguje trzy świadomie
  wybrane pola; `script-sample` bywa treścią użytkownika.
- **Czegokolwiek przez PostHog, Google Analytics ani inny skrypt firmy
  trzeciej.** Nie ma ich w kodzie, a CSP jest wymuszające.

`docs/seo/ANALYTICS.md` §5 opisuje szerszą politykę prywatności analityki.
Jeśli kiedyś wejdzie PostHog, to §5 jest miejscem, gdzie trzeba zacząć.

---

## 4. Co z planu z `docs/seo/ANALYTICS.md` NIE istnieje

Żeby nikt nie szukał kodu, którego nie ma:

- taksonomia zdarzeń PostHog (§1) — cała, oprócz dwóch sygnałów z §2 tutaj,
- lejki i kohorty poza WAC i kohortą tygodniową (§4),
- dashboard tygodniowy (§6) — nie ma ani widoku, ani eksportu; dane są
  w bazie, wykresu nie ma,
- serwis `product_events` (§7).

## 5. Testy, które to pilnują

- `tests/Feature/WeeklyActiveCooksTest.php` — definicja WAC i wykluczenia.
- `tests/Feature/SygnalyProduktoweTest.php` — 18 testów: co trafia do
  `properties` przy każdym z pięciu powodów odrzucenia zdjęcia, brak frazy
  w sygnale wyszukiwania, `user_id → NULL` po usunięciu konta, retencja
  z trybem „na sucho", oraz to, że awaria zapisu sygnału nie wywraca ani
  wyszukiwania, ani wgrywania zdjęcia. Wszystko w jednym pliku, także
  retencja — nie ma osobnego `RetencjaSygnalowTest`.

Oba CHECK-i są sprawdzone zachowaniowo, przez to, że baza faktycznie odrzuca
wiersz (SQLSTATE 23514), a nie przez czytanie definicji ograniczenia:
`test_odrzucony_wiersz_nie_przenosi_frazy_wyszukiwania_do_logu` dla
`query_text` i `test_baza_odrzuca_nazwe_zdarzenia_spoza_zamknietego_zbioru`
dla nazwy zdarzenia. Ten drugi powstał przy pisaniu tego dokumentu — do tego
momentu nie było przypadku „wstaw nieznaną nazwę i sprawdź, że baza odmawia",
czyli ograniczenie mogłoby zniknąć niezauważone.

---

## 6. Powroty — `php artisan kuking:raport` (issue #114/#115)

`docs/ROADMAP.md` kończy bramkę V1 zdaniem „Planner/groups/forks dopiero gdy
WAC i D30 pokazują powroty". Do tej komendy nic w bazie nie mówiło, kiedy
ktokolwiek ostatnio był w serwisie — bramka była więc niemierzalna. Ta sekcja
opisuje, co z tego istnieje NAPRAWDĘ, tym samym stylem co reszta pliku.

### 6.1 Nowy znacznik: `users.ostatnio_widziany_at`

**Kolumna na `users`, NIE trzeci sygnał w `product_signals`.** Pełne
uzasadnienie stoi w komentarzu migracji
`2026_09_08_200000_add_last_seen_to_users_table` i w `docs/DATABASE.md`
(sekcja `users`); w skrócie: retencja `product_signals` (90 dni) nie jest
tym, co przesądza — jest dłuższa niż 30 dni. Przesądza to, że
`product_signals` ma zostać wąska (dwa rzadkie zdarzenia techniczne, CHECK
w bazie tego pilnuje), a log odwiedzin na każde żądanie zmieniłby ten
charakter i rósłby bez końca. Kolumna na `users` jest nadpisywana — jeden
wiersz na konto, zero przyrostu, zero potrzeby retencji.

**Zapis throttlowany.** `App\Http\Middleware\AktualizujOstatniaWizyte`
(globalny, w grupie `web`, `bootstrap/app.php`) woła
`App\Domain\Analytics\ZanotujOstatniaWizyte`, która zapisuje co najwyżej raz
na `config('kuking.analytics.last_seen_throttle_minutes')` minut (domyślnie
15) na osobę. Zapis nigdy nie rzuca wyjątku dalej — ten sam wzorzec
(`DB::transaction()` + log klasy wyjątku, nigdy `getMessage()`) co
`ZapiszSygnal` w §2.4 wyżej, z tego samego, zmierzonego powodu.

### 6.2 Trzy klasy domenowe, jedna komenda

| Klasa | Odpowiada za |
|---|---|
| `App\Domain\Analytics\AktywniWTygodniu` | Ile kont było widzianych w ostatnich 7 dniach — TO NIE JEST `WeeklyActiveCooks`: tamta liczy, kto coś OPUBLIKOWAŁ, ta liczy, kto PO PROSTU BYŁ. Migawka na teraz, nie historia tydzień po tygodniu — `ostatnio_widziany_at` jest nadpisywana, więc nie da się z niej odtworzyć przeszłości. |
| `App\Domain\Analytics\PowrotPoDniach` | D7/D30 — jedną klasą, parametryzowaną liczbą dni. Definicja: konto starsze niż N dni, którego `ostatnio_widziany_at` sięga co najmniej N dni po `created_at`. To jest DOLNA granica prawdziwego powrotu, nigdy zawyżenie — pełne uzasadnienie (w tym dlaczego `extract(epoch from …)`, nie `+ interval`, żeby uniknąć tej samej pułapki strefy sesji co WAC w §1.3) w komentarzu klasy. |
| `App\Domain\Analytics\ZasiegUgotowalem` | Ile przepisów dostało choć jedno „Ugotowałem" i ilu autorów dostało to powiadomienie. ŚWIADOMIE bez wykluczeń `CookEligibility` — to nie jest metryka porównawcza w czasie jak WAC, tylko fakt o tym, czy najważniejszy mechanizm produktu (AGENTS.md, część 1) działa. |
| `App\Console\Commands\RaportPowrotow` | Formatuje wynik po polsku, jedna liczba na linię, z podpisem — `php artisan kuking:raport`. |

`AktywniWTygodniu` i `PowrotPoDniach` używają TEJ SAMEJ `CookEligibility` co
`WeeklyActiveCooks`/`CookRetentionCohorts` (§1.1) — gospodarz i konta
testowe/zalążkowe nie mają zasilać liczby czytanej jako dowód żywej
społeczności. `ZasiegUgotowalem` celowo tego wykluczenia NIE ma.

### 6.3 Dana osobowa w eksporcie RODO

`ostatnio_widziany_at` trafia do paczki RODO jako `konto.ostatnio_widziany`
(`App\Domain\Users\Exports\CollectUserExportData`) — z tego samego powodu co
`usuniecie_konta_zgloszone` obok niej. Wiersz o tym jest też w
`resources/legal/polityka-prywatnosci.md` (sekcja 2).

### 6.4 Testy

- `tests/Feature/OstatniaWizytaTest.php` — throttl (zapis co najwyżej raz na
  próg z configu), brak zapisu dla gościa, brak zapisu dla konta
  wylogowanego wcześniej przez `EnsureAccountIsActive` (kolejność middleware
  w `bootstrap/app.php` jest częścią specyfikacji).
- `tests/Feature/RaportPowrotowTest.php` — definicje `AktywniWTygodniu`/
  `PowrotPoDniach`/`ZasiegUgotowalem` (w tym wykluczenia i ich brak), pusta
  baza nie wywala komendy, oraz kontrola: kohorta pusta daje `procent = null`,
  nie `0.0`.
