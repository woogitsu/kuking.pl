# Analityka Kuking.pl — co jest ZBUDOWANE

Ten plik opisuje analitykę, która NAPRAWDĘ istnieje w kodzie: gdzie mieszka,
co liczy, czego nie zbiera i jak to uruchomić. Jest opisem stanu, nie planem.

## 0. Dlaczego ten plik w ogóle powstał

Issues **#114** i **#115** powołują się na `docs/research/ANALITYKA.md` —
po zapytanie WAC, po schemat `product_signals` i po politykę retencji.
**Tego pliku nigdy nie było w repozytorium.** Nikt go nie zacommitował, a
oba zadania i tak dało się zrobić: zapytanie WAC stoi w
`docs/seo/ANALYTICS.md` §2.1-2.2, a schemat tabeli issue #115 wypisuje wprost
w swojej treści. Zostały jednak dwa wiszące odnośniki i cztery komentarze
w kodzie, które tłumaczą się z nieistniejącego dokumentu.

Nie odtwarzam tu dokumentu, którego nikt nie napisał — nie wiadomo, co w nim
miało być, a zgadywanie skończyłoby się dokumentacją funkcji, których nie ma.
Zamiast tego opisuję stan faktyczny, żeby odnośnik prowadził do czegoś
prawdziwego.

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
