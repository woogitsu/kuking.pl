# RODO — potwierdzenia, dziennik zgód, eksport danych

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### potwierdzenia_zadan_rodo
Minimalne potwierdzenie, że żądanie usunięcia konta (RODO art. 17) zostało
obsłużone — **zamiast** bezterminowego dziennika osobowego.

Powstało z `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §C, która nie kwestionuje
liczby, tylko kształt: trzy kategorie `audit_log` z listy
`AuditLogEntry::NIGDY_NIE_KASUJ` trzymają dziś BEZTERMINOWO wpis z `actor_id`,
`ip_hash` i dowolnym `metadata jsonb`. Ocena każe je zastąpić zamkniętym
zestawem pól z określonym okresem trzymania; ocena proponowała **36 miesięcy od
zakończenia obsługi**, ale **właściciel wstrzymał automatyczne kasowanie do
potwierdzenia okresu przez prawnika** (D-233 — patrz „Retencja" niżej). Pełny projekt,
razem z rozstrzygnięciem powiązania z wnioskodawcą i jego słabościami:
`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`.

**Kto do niej pisze (od 21.09.2026):** wyłącznie
`App\Domain\Compliance\RejestrPotwierdzenRodo`. Wiersz `w_toku` powstaje przy
zgłoszeniu żądania z `/ustawienia/twoje-dane` (w jednej transakcji
z `users.markForDeletion()`), a domknięcie — `wykonane` albo `cofniete` — idzie
**w tej samej transakcji** co `EraseAccountData` i `CancelAccountDeletion`.
Potwierdzenie zapisane osobną transakcją potrafiłoby opisywać wykonanie,
którego nie było, albo przemilczeć wykonanie, które było; dowodzi tego
`tests/Feature/PotwierdzenieRodoIdzieWTejSamejTransakcjiTest.php`.

Model `App\Models\PotwierdzenieZadaniaRodo` ma w `$fillable` **wyłącznie opis
sprawy** (`rodzaj`, `otrzymano`, `wersja_procedury`, `wyjatki`). `numer`,
`wynik`, `zakres`, `zakonczono`, `konto_id`, `wstrzymanie_do`
i `wstrzymanie_sprawa` stoją **poza** `$fillable` — ta sama ostrożność co przy
`status` i `role` użytkownika, tylko stawką jest tu prawdziwość dowodu, wskaźnik
na dane osobowe i zegar retencji.

**Czego nadal nie ma:** backfillu istniejących wpisów `account.*` i skasowania
ich pełnych kopii — to osobne kroki właściciela (lista w projekcie wyżej), więc
do ich wykonania dziennik i potwierdzenia stoją **obok siebie**, nie zamiast
siebie. Numer sprawy też nie jest jeszcze nigdzie pokazywany ani wysyłany
człowiekowi (brzmienie pisma to krok 2 właściciela).

- `id uuid` PK, `DEFAULT gen_random_uuid()`;
- **`numer varchar(19) UNIQUE`** — `RODO-XXXX-XXXX-XXXX`, losowany
  z `App\Support\NumerZadaniaRodo` (alfabet bez `0`, `1`, `I`, `L`, `O`, `U`
  wspólny z `NumerSprawy`, 12 znaków ≈ 59 bitów). To jest **jedyne powiązanie
  wiersza z człowiekiem po wykonaniu usunięcia** — numer dostaje wnioskodawca,
  baza nie trzyma niczego, z czego dałoby się go odtworzyć. Przedrostek inny
  niż `KU` zgłoszeń moderacyjnych, żeby dwa rejestry nie mówiły tym samym
  numerem. Wzór pilnuje CHECK `potwierdzenia_zadan_rodo_numer_check`,
  **zamrożony w dniu migracji** — zmiana `NumerSprawy::ALFABET` wymaga nowej
  migracji (tak samo jak przy `reports`);
- **`rodzaj varchar(40)`** — CHECK po `SlownikPotwierdzenRodo::RODZAJE`. Dziś
  jedna wartość: `usuniecie_konta`;
- **`wynik varchar(30)`** — `w_toku` / `wykonane` / `cofniete` / `odmowa`.
  Ta kolumna zastępuje TRZY kategorie dziennika jednym wierszem: cofnięcie
  żądania jest **wynikiem**, nie osobnym zdarzeniem („Przechowuj właściwy stan
  końcowy, nie trzy niekasowalne kopie wszelkich danych" — §C);
- **`zakres varchar(20) NULL`** — `minimum` / `everything`, te same wartości co
  `users.delete_scope` (D-022). CHECK wiąże je z wynikiem w OBIE strony:
  `wykonane` musi mieć zakres, każdy inny wynik mieć go nie może;
- **`otrzymano date`** + **`zakonczono date NULL`** — daty wpływu i zakończenia.
  **`date`, nie `timestamptz`, i to jest minimalizacja**: sekunda zamknięcia
  sprawy daje się zestawić z chwilą, w której czyjeś wpisy zmieniły autora na
  „konto usunięte", czyli sama identyfikuje. Doba do wykazania terminu z art. 12
  ust. 3 i do policzenia 36 miesięcy wystarcza. `zakonczono` jest **początkiem
  zegara retencji**; `NULL` znaczy „sprawa w toku" i CHECK wiąże to z
  `wynik = 'w_toku'` w obie strony, żeby bezterminowość nie wróciła przez pustą
  kolumnę;
- **`wersja_procedury varchar(20)`** — która wersja procedury usunięcia to
  wykonała (`RRRR-MM-DD` daty obowiązywania). Bez niej „wykonane" znaczy tylko
  „zrobiliśmy wtedy to, co wtedy robiliśmy";
- **`wyjatki text NULL`** — czego NIE usunięto i z jakiej reguły to wynika
  (przy `minimum` treści zostają zanonimizowane, `COMPLIANCE.md` §2; sprawa
  moderacyjna ma własne 36 miesięcy). Tekst wskazuje **regułę**, nie opowiada
  o człowieku;
- **`konto_id uuid NULL`** → `users` (`ON DELETE SET NULL`) — powiązanie
  z wnioskodawcą, **zostające także po wykonaniu żądania**.

  **DECYZJA WŁAŚCICIELA Z 21.09.2026, nie rekomendacja oceny zewnętrznej.**
  Pierwotny schemat miał tu CHECK
  `potwierdzenia_zadan_rodo_wykonane_bez_konta_check`, zabraniający `konto_id`
  przy `wynik = 'wykonane'`: z chwilą wykonania konto jest anonimizowane,
  a wskaźnik wiąże dowód usunięcia danych osobowych ze wszystkim, co po tym
  koncie w serwisie zostało. Właściciel zdecydował inaczej — ma się dać
  odpowiedzieć regulatorowi o konkretną osobę bez pytania jej o numer sprawy —
  i CHECK zdejmuje migracja
  `2026_09_21_140000_zdejmij_zakaz_konta_przy_wykonanym_zadaniu_rodo` (jej
  `down()` zakłada go z powrotem i odmawia wąsko, gdy stoi wiersz, który by go
  złamał).

  **CENA TEJ DECYZJI I JEDYNE, CO PO NIEJ ZOSTAŁO Z OCHRONY.** Rejestr umie
  teraz odpowiedzieć na pytanie „czy ta osoba usunęła konto" każdemu, kto ma
  dostęp do bazy — a przy zakresie `minimum` treści tej osoby zostają pod tym
  samym `user_id` (D-022), więc wskaźnik prowadzi od potwierdzenia wprost do
  jej zachowanego dorobku. Decyzja brzmiała „ma się dać odpowiedzieć
  regulatorowi", a **nie** „ma być wyszukiwarka", więc:
  - **nie ma i nie będzie ekranu, trasy ani endpointu** czytającego tę tabelę
    po `konto_id` — pilnuje tego
    `tests/Feature/RejestrPotwierdzenRodoNieMaEkranuTest.php` (skan tras, skan
    warstwy HTTP i widoków, zamknięta lista publicznych metod klasy piszącej,
    zakaz wiązania modelu z adresu — każdy z kontrolą dodatnią);
  - **kto i w jakim trybie ma prawo z tego skorzystać:** właściciel serwisu,
    **odczytem ręcznym wprost w bazie**, przy konkretnej sprawie od organu
    nadzorczego albo sądu, notując przy sprawie, czego odczyt dotyczył. To nie
    jest funkcja produktu i nie ma być wygodne — niewygoda jest tu jedynym, co
    zostało z ochrony zdjętej razem z CHECK-iem.

  Pełny zapis decyzji, argumentów przeciw i tego zawężenia:
  `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` §3.2 punkt 3 i §3.3 punkt 7;
- **`wstrzymanie_do date NULL`** + **`wstrzymanie_sprawa varchar(100) NULL`** —
  udokumentowane wstrzymanie kasowania (§C: „o ile konkretna udokumentowana
  sprawa nie wymaga dalszego zachowania"). CHECK wymusza parę: wstrzymanie bez
  wskazanej sprawy to znów retencja bezterminowa, tylko pisana inną kolumną.
  Data, nie flaga — blokada ma wygasać sama;
- `created_at` / `updated_at` (`timestamptz`) — kiedy wiersz powstał. To **nie**
  jest `otrzymano`; rozjazd między nimi jest jedynym sygnałem daty wpisanej
  wstecz.

**Czego tu nie ma, świadomie:** adresu e-mail (jawnego ani jako skrót), nazwy,
biogramu, zdjęć, treści wniosku, korespondencji, `ip_hash` — oraz `metadata
jsonb`, czyli tej jednej kolumny, przez którą wszystkie powyższe wróciłyby bez
migracji i bez recenzji schematu.

**Indeksy** (wszystkie częściowe — każdy pod jedno zapytanie):
`potwierdzenia_zadan_rodo_retencja_idx (zakonczono) WHERE zakonczono IS NOT NULL`,
`..._w_toku_idx (otrzymano) WHERE zakonczono IS NULL` (przegląd zaległości, §F.7
oceny), `..._konto_idx (konto_id) WHERE konto_id IS NOT NULL`.

**Jedna otwarta sprawa na konto (#1346):** UNIKALNY
`potwierdzenia_zadan_rodo_jedna_w_toku_na_konto (konto_id) WHERE wynik = 'w_toku'
AND konto_id IS NOT NULL` (migracja `2026_09_24_160000_jedna_sprawa_rodo_w_toku_na_konto`).
`RejestrPotwierdzenRodo::domknij()` zamyka jedną sprawę `w_toku` konta — druga
zostałaby otwarta na zawsze przy żądaniu już wykonanym albo cofniętym.
Aplikacja nie zakłada drugiej (świeży wiersz konta pod `ZamekKonta`
w `User::markForDeletion()`, #980; drugie równoległe żądanie dostaje komunikat
„już oznaczone" i nie nadpisuje zakresu ani daty pierwszego); indeks pilnuje
tego dla każdej innej drogi zapisu. Oznaczenie konta, sprawa `w_toku`
i wpis `account.delete_requested` powstają w jednej transakcji
(`RequestAccountDeletion`, #1347, D-249 klasa 1): awaria dziennika
cofa całe żądanie, konto zostaje czynne i zalogowane.
**Migracja odmawia** założenia indeksu, gdy w bazie są już konta z więcej niż
jedną sprawą `w_toku` — podaje ich LICZBĘ (nie identyfikatory: komunikat
idzie do logu wdrożenia) i zapytanie SQL, które je wskaże, oraz każe domknąć
nadmiarowe ręcznie (nie kasować: to dowody). **Rollback:** `DROP INDEX` — nie
usuwa żadnego wiersza, więc nie odmawia (D-088). Odmowę, kontrolę dodatnią
i cofnięcie pilnuje `tests/Feature/JednaSprawaRodoWTokuMigracjaTest.php`.

**Retencja: WYŁĄCZONA — decyzja właściciela z 22.09.2026, `docs/DECISIONS.md`
D-233.** Wiersze nie są dziś kasowane przez nic i przez nikogo.

Powód nie jest niechęcią do retencji, tylko dwiema konkretnymi rzeczami:
okresu **nie potwierdził jeszcze prawnik** (36 miesięcy było analogią do
sprawy moderacyjnej, nie ustaleniem), a kasowanie jest **twardym `DELETE`,
nieodwracalnym** — bez soft-delete i bez eksportu. Po jego włączeniu, dla kont,
których ostatnie zdarzenie RODO jest starsze od progu, na pytanie „czy i kiedy
usunęliście dane tej osoby" nie zostaje nic. Polityka prywatności mówi przy tym
o kopiach zapasowych: „Nie podajemy tu liczby dni, bo nie ustaliliśmy jej
jeszcze z dostawcą" — czyli nie wiadomo nawet, jak długo istnieje droga odzysku.

Wyłączenie stoi na **dwóch niezależnych barierach**: `retencja_wlaczona` jest
`false`, a `kuking:sprzataj-potwierdzenia-rodo` **nie jest wpięte
w `routes/console.php`**, więc nie wystartuje nawet przy przypadkowo ustawionej
zmiennej. `retention_months` jest `null`, nie 36 — żeby samo przestawienie
flagi nie uruchomiło kasowania według zgadniętego progu.

Sam predykat istnieje, jest przetestowany i gotowy:
`App\Domain\Compliance\PrzedawnionePotwierdzeniaRodo` liczy od `zakonczono`,
pomija wiersze z `wstrzymanie_do` w przyszłości, a sprawy w toku (`zakonczono IS
NULL`) nie są kandydatem w ogóle, bo kasowanie otwartej sprawy zamieniłoby
retencję w sprzątanie dowodów zaniedbania. Próg liczony `subMonthsNoOverflow`,
nie `subMonths` (A6-04) — przepełnienie daty przesuwa go w stronę nowszych
wierszy i kasowałoby dowód wykonania art. 17 przed czasem.

**Do przygotowania danych historycznych** służy
`kuking:sprzataj-potwierdzenia-rodo --na-sucho --miesiace=N`, które działa mimo
wyłączenia i nie wykonuje żadnego `DELETE` — pokazuje wyłącznie, ile wierszy
wpadłoby pod dany próg.

**Jak to włączyć, gdy prawnik potwierdzi okres:** trzy kroki opisane przy kluczu
`potwierdzenia_rodo` w `config/kuking.php` i w `PROJEKT_POTWIERDZENIA_RODO.md`
§6. `tests/Feature/RetencjaPotwierdzenRodoTest.php` pilnuje obu stron: że
domyślnie nic się nie kasuje (z kontrolą dodatnią, że wiersz naprawdę był
kandydatem) i że po jawnym włączeniu automat kasuje oraz omija wstrzymane.

**Rollback:** `down()` kasuje tabelę, ale **odmawia**, gdy stoi w niej choć
jeden wiersz z wypełnionym `zakonczono` — to dowód obsługi żądania, którego nie
ma gdzie indziej. Odmowa jest wąska (AGENTS.md §6, D-088): pusta tabela i tabela
z samymi sprawami w toku cofają się bez pytania, bo sprawa w toku żyje nadal
w `users.delete_requested_at`. Kolejność: **najpierw kod, potem migracja**.
Pilnuje tego `MinimalnePotwierdzenieRodoTest` (odmowa + dwie kontrole dodatnie).

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
| `cel` | Cel zgody: `regulamin` (akceptacja regulaminu przy rejestracji, #2217 — od migracji `2026_09_29_234500_dziennik_zgod_akceptacja_regulaminu`) \| `tygodniowy_digest` \| `zyczenia_urodzinowe` (od migracji `2026_09_25_200200_add_birthday_email_consent_to_users`, #1755) \| `odczyt_ai` (od migracji `2026_09_26_100200_dziennik_zgod_cel_odczyt_ai`, D-296 — zgoda na odczyt zdjęć kartek przez OpenAI) \| `przypomnienie_spizarni` (od migracji `2026_10_01_102000_add_pantry_reminder_consent_to_users`, #1903 — sobotnie przypomnienie o produktach do zużycia). CHECK `dziennik_zgod_cel_check` — zbiór zamknięty, każda kolejna zgoda wymaga migracji i recenzji. |
| `czynnosc` | `udzielona` \| `wycofana`. CHECK `dziennik_zgod_czynnosc_check`. Dwie wartości, bo to są dwie rzeczy, które RODO każe umieć wykazać (art. 7 ust. 1 i ust. 3). |
| `zrodlo` | `ustawienia` \| `link_wypisania` \| `link_powrotny` \| `usuniecie_konta` \| `ekran_importu` (zgoda „odczyt AI” dana na ekranie „Przepisz z kartki”, D-296) \| `rejestracja_haslo` \| `rejestracja_google` \| `rejestracja_facebook` (droga rejestracji przy celu `regulamin`, #2217). CHECK `dziennik_zgod_zrodlo_check`. Część dowodu: „gdzie człowiek wtedy był". |
| `wersja_polityki` | Wersja polityki prywatności OBOWIĄZUJĄCA w chwili zdarzenia: `WersjaDokumentu::polityka()->obowiazujaca()` (D-327). Zwykle `config('kuking.zgody.wersja_polityki')`; w okresie przejściowym zmiany istotnej (14 dni od publikacji) — wersja poprzednia. Bez niej dowód mówi „zgodził się", ale nie mówi NA CO. |
| `wersja_regulaminu` | `varchar(20) NULL` (#2217). Wersja regulaminu OBOWIĄZUJĄCA w chwili akceptacji (`WersjaDokumentu::regulamin()->obowiazujaca()`, D-327). Wypełniona **dokładnie** przy `cel = 'regulamin'` (CHECK `dziennik_zgod_wersja_regulaminu_check`: `(cel = 'regulamin') = (wersja_regulaminu IS NOT NULL)`); przy pozostałych celach `NULL`. Przy celu `regulamin` `czynnosc` może być tylko `udzielona` (CHECK `dziennik_zgod_regulamin_tylko_udzielony_check`). |
| `wystapilo_at` | `timestamptz`, `useCurrent()`. Moment ZDARZENIA, nie zapisu wiersza — dlatego tabela nie ma `created_at`/`updated_at`. |

```sql
CREATE TABLE dziennik_zgod (
    id              bigserial PRIMARY KEY,
    user_id         uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    cel             varchar(40) NOT NULL,
    czynnosc        varchar(20) NOT NULL,
    zrodlo          varchar(30) NOT NULL,
    wersja_polityki varchar(20) NOT NULL,
    wersja_regulaminu varchar(20) NULL,
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

**Akceptacja regulaminu przy rejestracji (#2217).** Formularz z hasłem, wejście
przez Google i przez Facebooka kończą się w `App\Domain\Users\Actions\ZalozKonto`,
które w TEJ SAMEJ transakcji co konto woła
`App\Domain\Zgody\ZapiszAkceptacjeRegulaminu`: wiersz `cel = regulamin`,
`czynnosc = udzielona`, `zrodlo = rejestracja_*`, `wersja_regulaminu` i `wersja_polityki`
obowiązujące w chwili akceptacji, `wystapilo_at`. Awaria zapisu wycofuje całe
założenie konta; `ZalozKonto` wymaga argumentu `zrodloAkceptacji`, więc nowa droga
rejestracji nie zapomni o dowodzie. Nowsza wersja regulaminu dopisuje NOWY wiersz
przy nowej akceptacji — starego nie da się zmienić (wyzwalacz append-only).
**To nie jest** `users.terms_notice_dismissed_version`: zamknięcie paska „Zmieniliśmy
regulamin” niczego tu nie zapisuje (test `DowodAkceptacjiRegulaminuTest`). **Backfill:
celowo brak** — konta sprzed migracji nie mają wiersza `regulamin`, co znaczy „brak
dowodu”; dopisanie im akceptacji z datą założenia konta byłoby wystawieniem dokumentu,
którego nikt nie wystawił. Eksport danych konta (`dziennik_zgod`) zawiera nową
kolumnę. Nie zapisujemy IP ani `User-Agent` (jak przy pozostałych celach).
Wersji interfejsu (pole z propozycji issue) nie ma: wersja regulaminu i polityki
jednoznacznie wskazują tekst, który człowiek widział. **Retencja:** jak dla całego
dziennika — brak komendy sprzątającej, okres do ustalenia z prawnikiem (#8,
`docs/decyzje/ADR_RETENCJE.md`); po anonimizacji konta wiersz zostaje bez danych
osobowych.

**Rollback migracji `2026_09_29_234500_dziennik_zgod_akceptacja_regulaminu`:**
`php artisan migrate:rollback --step=1`. `down()` **ODMAWIA** (D-088), gdy w dzienniku
jest choć jeden wiersz `cel = regulamin`, `zrodlo = rejestracja_*` albo niepuste
`wersja_regulaminu` — zwężenie CHECK-ów i `DROP COLUMN` zabrałoby dowód akceptacji,
a wyzwalacz nie pozwala skasować wierszy. Odmowa podaje liczbę zapisów i eksport
(`\copy (SELECT * FROM dziennik_zgod WHERE cel = 'regulamin') to 'akceptacje_regulaminu.csv' csv header`).
Bez takich wierszy cofnięcie przechodzi bez pytania i wraca `up()`
(`DowodAkceptacjiRegulaminuTest`).

**Zgoda `odczyt_ai` (D-296) nie ma kolumny na `users`.** Jej stanem jest
OSTATNI wpis osoby dla tego celu (`App\Domain\Zgody\PrzestawZgodeNaOdczytAi`),
czytany świeżo z bazy także w zadaniu odczytu tuż przed wysłaniem zdjęcia.
Anonimizacja konta dopisuje `wycofana` / `usuniecie_konta` jak przy digeście.
Rozszerzenie CHECK-ów (`2026_09_26_100200_…`, `NOT VALID` + `VALIDATE`) cofa
się bez pytania, dopóki nie ma wierszy z `odczyt_ai` ani `ekran_importu`;
przy takich wierszach `down()` **odmawia** (D-088) — dziennik jest append-only,
więc węższy CHECK nie ma jak wrócić bez skasowania dowodu zgody
(`CofniecieZgodyOdczytuAiOdmawiaTest`).

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

### data_exports
Paczka ZIP z danymi jednego użytkownika (RODO art. 15 i 20), budowana w tle
przez `App\Jobs\GenerateUserExport` (migracja `2026_09_05_001100_create_data_exports_table`).

**Otrzymane zaproszenia do zeszytu (#2869).** Nowa paczka zachowuje datę i stan
własnego zaproszenia, ale bierze bieżącą nazwę cudzego zeszytu tylko wtedy,
gdy `CollectionPolicy::view()` nadal pozwala go czytać. Po odebraniu dostępu
do prywatnego zeszytu albo odejściu pole `zeszyt` jest `null`; nie odtwarzamy
ani nie zgadujemy dawnej nazwy. Publiczny, nadal dostępny zeszyt i własne
zeszyty zachowują nazwę. Token linku i jego skrót nie trafiają do ZIP-u.
Nie ma zmiany schematu; rollback kodu przywraca ujawnianie późniejszej nazwy
byłemu członkowi, więc wymaga ponownego wdrożenia poprawki.

| Kolumna | Uwagi |
|---|---|
| `user_id` | Właściciel paczki. `cascadeOnDelete` — po usunięciu konta paczka i jej wpis nie mają już czego dotyczyć. |
| `status` | `queued` → `processing` → `ready` **albo** `failed`, docelowo `expired`. CHECK w bazie (`data_exports_status_check`); komplet metadanych przy `ready` — CHECK `data_exports_ready_complete_check`, niżej. |
| `disk`, `object_key` | Gdzie leży gotowe archiwum — wypełniane dopiero przy `ready`. |
| `bytes` | Rozmiar gotowego pliku. |
| `completed_at` | Kiedy paczka była gotowa. |
| `notified_at` | Nullable `timestamptz`: kiedy `App\Jobs\NotifyUserExportReady` zajął list „paczka gotowa" (issue #820). Poza `$fillable`. CHECK `data_exports_notified_after_completed_check`: bez `completed_at` nie ma listu. Szczegóły niżej. Migracja `2026_09_23_180000_add_notified_at_to_data_exports`. |
| `expires_at` | Kiedy paczka przestaje być do pobrania — nie trzymamy w storage kopii całego konta bez końca; sprząta `App\Console\Commands\CleanUpDataExports`. |
| `failure_reason` | Patrz niżej — **kod, nie zdanie**. |

**Plik bez wiersza (audyt B5 pkt 4, 25.09.2026).** `GenerateUserExport` wgrywa
ZIP przed `finalize()`, więc próba, która padła pomiędzy, zostawiała w magazynie
paczkę pod kluczem nieznanym wierszowi (`failed`, `object_key IS NULL`). Teraz:
catch i `failed()` kasują `ExportFileNames::objectKey($export)` (klucz da się
policzyć), `kuking:sprzataj-eksporty` przechodzi co noc po `failed` z ostatnich
7 dni jako siatka, a `EraseAccountData` kasuje cały katalog
`eksporty/<user_id>/` (`ExportFileNames::katalogKonta()`) po commicie.
Bez zmiany schematu.

#### `data_exports_ready_complete_check` — gotowa paczka ma komplet metadanych (issue #1365)

Migracja `2026_09_24_200000_require_complete_ready_data_exports`. Przy
`status = 'ready'` wymagane są: niepusty `disk` i `object_key`, `bytes > 0`,
`completed_at` i `expires_at`. Wcześniej baza przyjmowała `ready` bez tych
pól, a `DataExport::isDownloadable()` pokazywał taki wiersz jako gotową
paczkę — „Pobierz” kończyło się 404. `isDownloadable()` sprawdza teraz ten
sam komplet (obrona dla modelu w pamięci).

Celowo **bez** warunku na czas: `EraseAccountData` unieważnia gotową paczkę,
przestawiając `expires_at` w przeszłość (także przed `completed_at`),
a `expires_at > now()` nie jest wyrażeniem niezmiennym, jakiego PostgreSQL
wymaga od CHECK. `expired` nie ma wymagań — `CleanUpDataExports` zostawia
adres po nieudanym kasowaniu, żeby ponowić.

**Audyt przed CHECK:** migracja liczy `ready` bez kompletu i **odmawia**
(`RuntimeException` z zapytaniem `SELECT` i instrukcją), zamiast zgadywać
dysk, klucz albo rozmiar. Naprawa ręczna: uzupełnić prawdziwe wartości, gdy
plik istnieje, albo `status = 'failed'`, `failure_reason = 'unknown'`, gdy
go nie ma — człowiek zamówi paczkę ponownie.

**Rollback:** `down()` zdejmuje CHECK bez odmowy. Ograniczenie nie przechowuje
żadnej wartości (niczyjej decyzji, zgody ani zakresu w rozumieniu D-088) — po
cofnięciu wraca poprzednia, luźniejsza granica, dane zostają bez zmian.

#### `notified_at` — list „paczka gotowa" najwyżej raz (issue #820)

List wysyła osobne zadanie `NotifyUserExportReady` (kolejka `default`,
5 prób, przerwy 1, 5, 15 i 60 minut), a nie `GenerateUserExport` — tamto
łapało awarię poczty i nikt listu nie ponawiał. Zadanie jest zlecane
w transakcji, która ustawia `ready` (`GenerateUserExport::finalize()`): na
kolejce bazodanowej wiersz w `jobs` i `ready` zatwierdzają się razem albo
wcale. Kolejka `sync` (testy) wysyła list po commicie, bez ponowień.

Zadanie **zajmuje** list jednym `UPDATE … SET notified_at = teraz WHERE
notified_at IS NULL AND status = 'ready' AND expires_at > teraz`; z dwóch
przebiegów naraz przechodzi jeden. Gdy wysyłka padnie, zajęcie jest
zwalniane (tylko po tym samym znaczniku) i kolejka ponawia. Granice:
zerwane połączenie PO przyjęciu listu przez dostawcę daje drugi list;
proces zabity między zajęciem a wysyłką — żadnego (zajęcie zostaje).
Wartość znaczy więc „list zajęty do wysyłki i nie zgłoszono awarii", a nie
„list doręczony".

Pusta kolumna przy paczce gotowej do pobrania to na ekranie ustawień zdanie
„E-mail o tej paczce jeszcze nie wyszedł. Nie musisz na niego czekać —
paczkę pobierzesz tutaj."

**Backfill:** wiersze `ready` i `expired` sprzed migracji dostają
`notified_at = completed_at` — stary kod próbował wysłać list w tym samym
przebiegu, a nikt tej próby już nie powtórzy; pusta kolumna kazałaby ekranowi
mówić o nich „jeszcze nie wyszedł".

**Rollback:** `down()` usuwa CHECK i kolumnę bez odmowy. To nie jest wartość
semantyczna w rozumieniu D-088: ponowne `up()` odtwarza znaczniki gotowych
paczek z `completed_at`, więc cykl down/up może najwyżej zgubić list
czekający w kolejce, nie wysłać drugiego. Przy cofaniu wdrożenia: zatrzymać
workery; zadania `NotifyUserExportReady` pozostałe w `jobs` po powrocie do
starego kodu nie znajdą klasy i trafią do `failed_jobs` — paczka czeka
w ustawieniach. `down()` bez tabeli albo kolumny nic nie robi.

#### `failure_reason` — kod, nie wolny tekst (audyt W7-07)

Kolumna jest renderowana wprost na ekranie ustawień
(`resources/views/pages/settings/data.blade.php`), więc nie może zawierać
technicznego szczegółu wyjątku (SQLSTATE, ścieżka na dysku tymczasowym).
Trzyma jeden z zamkniętego zbioru kodów z `App\Models\DataExport::REASONS`
(ten sam wzorzec co `Report::REASONS`):

| Kod | Kiedy |
|---|---|
| `account_missing` | Konto zniknęło, zanim job zdążył zbudować paczkę. |
| `storage` | Zapis gotowej paczki do magazynu plików się nie udał — również gdy `writeStream()` zwróci `false` bez wyjątku. Paczka nie przechodzi wtedy do `ready` i nie wysyła się informacji o gotowości. |
| `photo_unreadable` | Zdjęcie `ready` nie dało się odczytać z magazynu albo magazyn oddał mniej bajtów, niż sam podaje w `size()` — paczka bez niego byłaby niepełna, więc nie jest wydawana (issue #1388). Skutek dla obsługi: patrz „Trwale brakujące zdjęcie blokuje eksport” niżej. |
| `timeout` | Budowa paczki przekroczyła limit czasu joba (15 minut). |
| `unknown` | Worek na resztę — każda inna awaria, w tym awaria **lokalnego** dysku tymczasowego workera przy kopii zdjęcia (`App\Exceptions\DataExportTempFailure`: nieudany `fopen`, pełny dysk, kopia krótsza niż odczyt). To nie jest wina zdjęcia, więc ekran o zdjęciu nie mówi. |

`DataExport::failureReasonLabel()` zamienia kod na zdanie po polsku (z adresem
kontaktowym z `config('kuking.community.contact_email')`) i **nigdy** nie
pokazuje surowego kodu ani starego wolnego tekstu — nieznany albo pusty kod
dostaje tekst spod `unknown`. Pełny `$e->getMessage()` zostaje wyłącznie
w logu aplikacji (`Log::warning` w `GenerateUserExport::handle()`).

Kolumna świadomie NIE ma CHECK-a ograniczającego ją do tych pięciu
wartości — dokładnie jak `reports.reason` (patrz wyżej), które też jest
kodem z zamkniętym mapowaniem w PHP, a nie w bazie.

Migracja `2026_09_06_210000_convert_data_export_failure_reason_to_codes`
zamienia istniejące wiersze z wolnego tekstu na kody (backfill po dokładnym
dopasowaniu dwóch znanych literałów, reszta na `unknown`) i cofa się do
`NULL` — oryginalne komunikaty nigdy nie były tu źródłem prawdy i zostają
wyłącznie w logu.

#### Eksport a wymazanie konta i pliki pośrednie (issues #1307, #993)

Bez zmiany schematu — zmiana dotyczy tego, KIEDY wiersz dostaje `ready`.

- `GenerateUserExport` nie zaczyna pracy dla konta `erased` ani dla eksportu,
  któremu `EraseAccountData` przestawiło `expires_at` w przeszłość (`failed`,
  `account_missing`). Karencja `pending_delete` eksportu nie blokuje.
- Przejście w `ready` idzie w krótkiej transakcji z `lockForUpdate()` na
  wierszu `users` (ta sama kolejność blokad co w `EraseAccountData`) i na
  wierszu eksportu, z ponownym sprawdzeniem obu warunków. Przegrany wyścig:
  wiersz dostaje `expired` z **zachowanym** `disk`/`object_key` i terminem
  w przeszłości, job kasuje plik z weryfikacją `exists()` i dopiero wtedy
  zeruje adres. Gdy kasowanie się nie uda, adres zostaje, a
  `kuking:sprzataj-eksporty` ponawia je jak przy każdej wygasłej paczce.
- Pliki pośrednie (ZIP w budowie, `dane.json`, kopie zdjęć) leżą w
  osobnym katalogu każdego eksportu (podkatalog `kuking-eksport.u<uid>`
  katalogu tymczasowego systemu — osobny dla użytkownika systemu procesu, albo
  `KUKING_EXPORT_TEMP_DIR` / `kuking.exports.temp_dir` — tworzony z prawami
  0700, a w nim katalog nazwany identyfikatorem eksportu) na dysku **workera** i znikają
  w `finally`, w `failed()` (po identyfikatorze, także na odtworzonej
  instancji joba) oraz na starcie kolejnej próby. Katalog nieruszany od
  godziny (`ExportTempDirectory::STALE_AFTER_SECONDS`, cztery limity czasu
  jednej próby) usuwa start każdego następnego eksportu na tym workerze
  oraz pętla samego workera (zdarzenie `Looping`, najwyżej raz na 10 minut,
  `ExportTempDirectory::SWEEP_EVERY_SECONDS`; każdy worker sprząta własny
  dysk) — czyli po twardym przerwaniu procesu pliki pośrednie żyją przy
  działającym workerze najdłużej ok. 85 minut (60 min progu + 10 min
  odstępu + 15 min najdłuższego zadania blokującego pętlę), a gdy worker
  nie wstaje — do restartu kontenera (dysk Railway jest ulotny). Nieudane usunięcie zostawia `Log::warning`
  z identyfikatorem eksportu, bez ścieżek. Nieczytelny katalog albo wpis
  (np. założony przez innego użytkownika systemu) nie wywraca eksportu:
  jeden `Log::warning` z klasą wyjątku, bez ścieżki, i sprzątanie idzie
  dalej. Stary wspólny podkatalog `kuking-eksport` (sprzed #1436) nie jest już
  czytany — znika z restartem kontenera. Sprzątanie stoi na samym
  początku `handle()`, **przed** wczesnymi powrotami (konto wymazane,
  eksport już `ready`, brak wiersza) — inaczej kopia z przerwanej próby
  wymazanego konta czekałaby na cudzy eksport.
- List „paczka gotowa” wychodzi po **świeżym** odczycie wiersza eksportu
  i konta, po commicie `ready`. Wymazanie, które czekało na blokadę
  finalizacji i zatwierdziło się tuż po niej, odcina list (adres jest już
  zanonimizowany, a paczka niepobieralna). Wymazanie wchodzące między tym
  odczytem a wysyłką daje co najwyżej jeden list na prawdziwy adres
  właściciela z linkiem, który już nie wyda paczki — bez danych w treści.

#### Trwale brakujące zdjęcie blokuje eksport RODO (issue #1388)

Skutek wybranego kontraktu „paczka niepełna nie jest wydawana”, nazwany
wprost: jeśli plik JEDNEGO zdjęcia `ready` zniknął z magazynu na stałe
(np. utracony przy przenosinach bucketów, #1031), **każda** próba eksportu
tego konta kończy się `failed` / `photo_unreadable` — ponowienie nic nie
zmieni, a człowiek nie dostanie paczki z pozostałymi danymi. Ekran mówi mu,
żeby spróbował za kilka minut, a gdy się powtarza — napisał do nas. Nie ma
tu automatu i świadomie go nie dokładamy: „pomiń zdjęcie i wydaj resztę”
po cichu to dokładnie usterka #1388.

Ścieżka dla obsługi (admin z dostępem do produkcji, **za jawną zgodą
właściciela danych** — to zapis na produkcji, AGENTS.md):

1. W dzienniku znaleźć `Nie udało się zbudować paczki z danymi użytkownika`
   dla tego `data_export_id` — `error` niesie identyfikator zdjęcia
   i przyczynę (`brak pliku w storage`, klasa wyjątku albo
   „odczytano X z Y bajtów”). Bez ścieżek i bez komunikatu dostawcy.
2. `php artisan kuking:sprawdz-zdjecia-po-przenosinach` (tylko odczyt)
   rozstrzyga: **DO ODZYSKANIA** — plik leży w starym buckecie; naprawa jak
   w opisie tej komendy, potem człowiek zamawia paczkę ponownie. Przyczyna
   chwilowa (plik jest, odczyt się urywał) — zwykłe ponowienie.
3. **UTRACONE** — bajtów nie odzyska nic. Decyzja właściciela danych:
   zdjęcie przestaje być `ready` — `status = rejected` („przygotowanie
   pliku padło, oryginał wolno wgrać jeszcze raz”), NIE `deleted`, które
   README tłumaczy jako decyzję samego człowieka (D-083). Wtedy
   `ExportPhotoPlan` go nie planuje, a README paczki liczy je jawnie
   (`ExportPhotoPlan::rejectedCount()`). Dopiero potem nowy eksport. Człowiekowi
   odpisujemy, którego zdjęcia brakuje — paczka nie może tego przemilczeć.

Nie ma do tego komendy; jeśli zgłoszeń będzie więcej niż pojedyncze, to
jest powód na osobne issue, nie na obejście w `GenerateUserExport`.

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
