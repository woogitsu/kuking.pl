## D-072 · Zgoda na tygodniowy digest ma dziennik append-only `dziennik_zgod`; rollback migracji zgody nie przywraca `DEFAULT true`

**Data:** 10 września 2026 · Audyt 10.09.2026 (DB1, DB2, część 10 §4) ·
Status: **obowiązuje**

Dwa rozstrzygnięcia z jednego audytu, obydwa o tej samej rzeczy: o tym, żeby
mailing wychodził **wyłącznie** do ludzi, którzy o niego poprosili, i żeby dało
się to **wykazać**.

### 1. Dowód zgody: append-only `dziennik_zgod`, nie dwie kolumny z datami

**Stan przed zmianą, sprawdzony w kodzie:**
`PrivacySettingsController::update()` zapisywał wyłącznie boolean
`wants_weekly_digest`; `users` miało `weekly_digest_sent_at` (kiedy poszedł
ostatni list), ale ani śladu, **kiedy i skąd** zgoda została udzielona i kiedy
wycofana. `PodsumowanieTygodniaController` przestawiał ten sam boolean
`forceFill()`-em, a `EraseAccountData` gasił go przy anonimizacji konta —
trzy niezależne miejsca, zero zapisu historii.

RODO art. 7 ust. 1 wymaga, żeby administrator **był w stanie wykazać** zgodę.
„Dziś pole ma wartość `true`" jest ostatnią klatką filmu, którego nikt nie
nagrywał: nie odpowiada, kiedy człowiek kliknął, czy wcześniej tego nie
odklikał ani czy po wycofaniu wysyłka nie szła dalej. A wysyłka **już
istnieje** (D-057, `kuking:wyslij-podsumowania` od 10 września), więc to
przestało być rozważaniem teoretycznym.

**Odrzucone: dwie kolumny (`..._consented_at` / `..._withdrawn_at`).** Audyt
dopuszczał je jako minimum. To minimum jest za małe i widać to na jednym
przykładzie: przy ciągu włącz → wyłącz → włącz trzecia zmiana nadpisuje
pierwszą, więc zostaje obraz, w którym nie da się odróżnić osoby zapisanej raz
od osoby, która zmieniała zdanie. Dwie kolumny odpowiadają na „kiedy
ostatnio", a pytanie dowodowe brzmi „co się działo".

**Odrzucone: JSONB z historią na `users`.** AGENTS.md §6 — JSONB tylko dla
danych półstrukturalnych. Zdarzenie zgody ma cztery zawsze te same pola
i zamknięte zbiory wartości; to są dane strukturalne, więc dostają kolumny
i `CHECK`-i, których baza umie pilnować.

**Przyjęte:** tabela `dziennik_zgod` (`user_id`, `cel`, `czynnosc`, `zrodlo`,
`wersja_polityki`, `wystapilo_at`), pełny opis w `docs/DATABASE.md`. Wszystkie
cztery miejsca, w których zgoda się zmienia, idą teraz przez jedną klasę
domenową `App\Domain\Zgody\PrzestawZgodeNaDigest`: ekran
`/ustawienia/prywatnosc` (`ustawienia`), podpisany odnośnik ze stopki listu
i nagłówka `List-Unsubscribe` (`link_wypisania`), przycisk powrotny na ekranie
po wypisaniu (`link_powrotny`) i anonimizacja konta (`usuniecie_konta`).
Wiersz powstaje **tylko przy realnej zmianie** — formularz prywatności wysyła
stan haczyków przy każdym zapisie, a na odnośnik wypisania wchodzi się dwa
razy (odświeżenie, skaner odnośników w firmowej poczcie); dziennik pełen
„wycofań", których nikt nie wykonał, byłby gorszym dowodem niż brak dziennika.

**Bez IP i bez `User-Agent`.** Wiele bibliotek do zgód zapisuje jedno i drugie
„na wszelki wypadek". Do wykazania zgody nie są potrzebne: dowodem jest fakt,
moment, cel i droga, a nie numer, z którego ktoś wtedy korzystał. AGENTS.md §7
zabrania PII tam, gdzie nie musi być, a `audit_log` trzyma IP wyłącznie jako
HMAC i tylko tam, gdzie służy wykrywaniu nadużyć — zgoda nie jest nadużyciem.
Pilnuje tego asercja na **pełną listę kolumn** tabeli, więc oblewa się także
wtedy, gdy ktoś doda kolumnę nazwaną neutralnie (`kontekst`, `meta`) i włoży
tam to samo.

**Append-only naprawdę, nie z nazwy.** Wyzwalacze w bazie
(`dziennik_zgod_bez_zmian` na `UPDATE`/`DELETE`, `dziennik_zgod_bez_czyszczenia`
na `TRUNCATE`) rzucają wyjątek. Świadomie `RAISE EXCEPTION`, a nie reguła
`DO INSTEAD NOTHING`: reguła połknęłaby zmianę bez słowa i kod „poprawiający"
dziennik działałby dalej w przekonaniu, że coś zmienił. Model `WpisZgody`
blokuje `update`/`delete` również w PHP — to pierwsza linia (czytelniejszy
błąd), nie jedyna, bo `DB::table('dziennik_zgod')->update(...)` i ręczny `psql`
jej nie widzą. `DROP TABLE` **nie** jest blokowany: `migrate:refresh` w CI
i `RefreshDatabase` w testach muszą działać, a append-only dotyczy wierszy
w działającym serwisie, nie istnienia schematu.

### Napięcie, którego nie ma sensu ukrywać: dowód zgody vs prawo do usunięcia

RODO art. 17 każe usunąć dane na żądanie; art. 7 ust. 1 i art. 5 ust. 2 każą
móc wykazać zgodę — także po tym, jak ktoś konto usunął, bo właśnie wtedy
najczęściej pojawia się spór („nigdy się na to nie zapisałem"). Skasowanie
dziennika razem z kontem oznacza brak dowodu; zachowanie go w pierwotnej
postaci oznacza dane po osobie, która poprosiła o usunięcie.

**Wybór:** dziennik zgód **zostaje**, bez anonimizacji własnych wierszy,
i jest to możliwe wyłącznie dzięki temu, co Kuking robi już od D-022:
**konta się nie kasuje, tylko anonimizuje.** Po `EraseAccountData` wiersz
`users` nie ma adresu e-mail, hasła, nazwy ani awatara, więc `user_id`
w dzienniku wskazuje na rekord, który sam z siebie nikogo nie identyfikuje —
zostaje dowód, że wysyłka miała podstawę prawną, bez trzymania danych
osobowych dłużej niż konto. To ten sam wzorzec, którym `EraseAccountData`
świadomie nie tyka zgłoszeń, odwołań ani `audit_log`, i ta sama logika, która
trzyma `account.data_erased` w `AuditLogEntry::NIGDY_NIE_KASUJ`.

Do tego anonimizacja **domyka historię**: dopisuje wiersz `wycofana` ze
źródłem `usuniecie_konta`. Bez niego dziennik kończyłby się na „udzielona"
i w papierach wyglądałby na zgodę obowiązującą do dziś, choć konto zostało
wymazane.

**Kasowanie konta nadal działa i to jest warunek, nie nadzieja** —
`DowodZgodyNaDigestTest` uruchamia prawdziwe `EraseAccountData` na koncie ze
zgodą i na koncie bez zgody. Klucz obcy ma `ON DELETE RESTRICT`: nie `CASCADE`
(skasowałby dowód dokładnie wtedy, gdy jest potrzebny) i nie `SET NULL`
(byłby `UPDATE` na tabeli append-only, a dowód niczyj to dowód żaden).
**Nazwany skutek uboczny:** twardy `DELETE FROM users` dla konta, które
kiedykolwiek ruszyło tę zgodę, odmówi wykonania. Nic w serwisie tego nie robi;
gdyby kiedyś było naprawdę potrzebne, jest to świadoma decyzja człowieka po
zdjęciu wyzwalacza, a nie skutek uboczny kaskady.

**Punkt otwarty, świadomie niedokończony w tym PR-ze: retencja.** Dziennik
zgód nie ma komendy sprzątającej (dlatego nie ma też indeksu po samym czasie —
nie budujemy indeksu bez czytelnika). Wiersz to siedem krótkich pól na jedną
zmianę zgody, więc tabela rośnie wolniej niż `product_signals`. Docelowy okres
przechowywania dowodów zgód należy rozstrzygnąć razem z resztą retencji,
w `docs/decyzje/ADR_RETENCJE.md`, przy przeglądzie prawnym (issue #8) — a nie
tu, zgadując liczbę miesięcy.

**Drugi punkt otwarty:** dziennik nie jest jeszcze widoczny dla samego
człowieka — nie ma go ani w paczce danych (`CollectUserExportData`), ani na
ekranie prywatności. To osobna zmiana produktowa (dwie decyzje UX: co pokazać
i jak nazwać), a nie warunek dowodowy z art. 7 ust. 1, który ten PR zamyka.

### 2. Rollback migracji zgody nie przywraca groźnego zachowania

**Stan przed zmianą:**
`database/migrations/2026_09_07_400000_default_weekly_digest_to_off.php`
w `down()` wykonywał `ALTER TABLE users ALTER COLUMN wants_weekly_digest SET
DEFAULT true`.

Ta migracja powstała **właśnie dlatego**, że `DEFAULT true` zapisywał ludzi na
mailing bez ich decyzji — formularz rejestracji o tę zgodę nie pyta ani jednym
polem i nadal nie pyta. Gdy migrację pisano, `DEFAULT true` był niegroźny:
digestu nie było w kodzie w ogóle. **Od 10 września wysyłka istnieje**, więc
techniczny rollback tworzyłby NOWE konta z aktywnym mailingiem bez zgody.
Komentarz w migracji słusznie pilnował, żeby nie ruszać istniejących wierszy,
ale o nowych milczał.

**Decyzja: `down()` jest pusty i `DEFAULT false` zostaje także po cofnięciu.**
Asymetria jest pełna i jawna: `up()` przestawia `DEFAULT` oraz istniejące
wiersze, `down()` nie przywraca ani jednego, ani drugiego. Tak, tej migracji
nie da się cofnąć „wiernie historycznie" — i tak ma być. **Wierny rollback
przywraca też wadę, którą migracja naprawiła**, a przy poczcie wychodzącej ta
wada jest nieodwracalna: listu wysłanego bez zgody nie da się odwołać.
Bezpieczny rollback bije wierny wszędzie tam, gdzie wierny wraca do stanu
groźnego. Metoda `down()` **zostaje** (pusta, z uzasadnieniem): bez niej
`migrate:rollback` przewracałby się na tej migracji, a `migrate:refresh` — CI
je uruchamia — nie zszedłby poniżej niej.

**Do tego twarda bramka.** `App\Domain\Digest\BramkaDomyslnejZgody` pyta
`information_schema` przy każdym uruchomieniu `kuking:wyslij-podsumowania`
i przy `DEFAULT true` kończy kodem 1, z komunikatem mówiącym, co zrobić.
Komentarz w migracji przeczyta ten, kto ją otworzy; bramka łapie **także** trzy
inne drogi powrotu tej samej wady, przy których nikt nie czyta migracji:
ręczny `ALTER` przy grzebaniu w bazie, przywrócenie bazy z kopii sprzed
migracji i `migrate:rollback` uruchomiony na starszym wydaniu kodu, w którym
`down()` jeszcze przywracał `true`. Przebieg `--na-sucho` przechodzi mimo
zamkniętej bramki (nic nie wysyła, nic nie zapisuje) i tylko ostrzega —
przy takim schemacie chce się właśnie policzyć, ilu ludzi dotyczyłaby pomyłka.

**Zmiana wymaga:** przy (1) — nowej informacji prawnej, z której wynika, że
historia zdarzeń zgody jest zbędna albo że IP jest do jej wykazania konieczne;
przy retencji — rozstrzygnięcia w `ADR_RETENCJE.md`. Przy (2) — dopisania pola
zgody do formularza rejestracji; dopóki rejestracja o zgodę nie pyta, żaden
`DEFAULT true` nie jest zgodą i decyzja stoi.

### Czego pilnują testy, a czego nie — spisane po kontroli ujemnej

Każdy test z obu plików był zepsuty osobnym sabotażem i każdy oblał; tabelka
`test → sabotaż → wynik` jest w opisie PR #270. Przy tym przeglądzie wyszło,
że trzy rozstrzygnięcia z tej decyzji były opisane, ale przez nikogo
niesprawdzane — i dostały własne testy:

1. **Asymetria udzielenia i wycofania przy awarii zapisu dowodu.**
   `PrzestawZgodeNaDigest` nazywa ją najważniejszą rzeczą w pliku, a nie
   pilnował jej ani jeden test. Awarię wymusza się bez ruszania kodu
   produkcyjnego: `kuking.zgody.wersja_polityki` dłuższa niż `varchar(20)`
   psuje wyłącznie `INSERT` do dziennika. Udzielenie ma się wtedy cofnąć
   w całości, wycofanie ma dojść do skutku mimo wszystko.
2. **Zamknięte zbiory wartości.** CHECK-i `cel`, `czynnosc` i `zrodlo` były
   obietnicą w komentarzu; każdy ma teraz własne sprawdzenie (asercja idzie
   na NAZWĘ naruszonego ograniczenia, żeby jeden CHECK nie zaliczył się trzy
   razy) i kontrolę dodatnią na komplecie poprawnych wartości.
3. **`restrictOnDelete()` zamiast kaskady.** Rozstrzygnięcie napięcia „dowód
   zgody vs prawo do usunięcia" nie miało testu, więc podmiana na
   `cascadeOnDelete()` przechodziła bez jednego czerwonego przebiegu.

Czego te testy NADAL nie dowodzą, i trzeba to nazwać: nikt nie broni bazy
przed człowiekiem z prawami właściciela tabeli, który zdejmie wyzwalacz
(`ALTER TABLE … DISABLE TRIGGER`) albo klucz obcy. Append-only pilnuje przed
POMYŁKĄ i przed kodem, nie przed świadomą decyzją administratora — i tak ma
być, bo `down()` migracji też musi działać.

📄 `database/migrations/2026_09_10_400000_create_dziennik_zgod_table.php` ·
`database/migrations/2026_09_07_400000_default_weekly_digest_to_off.php` ·
`app/Models/WpisZgody.php` · `app/Domain/Zgody/PrzestawZgodeNaDigest.php` ·
`app/Domain/Digest/BramkaDomyslnejZgody.php` ·
`app/Http/Controllers/Settings/PrivacySettingsController.php` ·
`app/Http/Controllers/PodsumowanieTygodniaController.php` ·
`app/Domain/Users/Actions/EraseAccountData.php` ·
`app/Console/Commands/WyslijPodsumowaniaTygodnia.php` ·
`config/kuking.php` (`zgody.wersja_polityki`) ·
`tests/Feature/DowodZgodyNaDigestTest.php` ·
`tests/Feature/RollbackNieWlaczaDigestuTest.php` ·
`docs/DATABASE.md` (`dziennik_zgod`) ·
`docs/research/audyt-2026-09-10/03_BAZA_DANYCH_I_INTEGRALNOSC.md` (DB1, DB2) ·
`docs/research/audyt-2026-09-10/10_RODO_DSA_PRAWO_I_PRYWATNOSC.md` §4 ·
D-022 · D-057
