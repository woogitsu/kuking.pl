# Przekazanie prac operacyjnych — noc 17/18 września 2026

**Zakres:** kopie danych i odtworzenie (#193 / #594), R2 i bezpieczeństwo
zdjęć (#619 / #120 / #617), budżet połączeń PostgreSQL (#598), monitoring
(#599). Poza zakresem i nietknięte: #646, #647, #648, PR-y #650 i #651,
port marki — to prowadzi drugi model.

**Punkt startowy:** `origin/main` = `27130f554a52375f35ad86eee1a58509e6b93a6a`.
W trakcie nocy `main` przesunął się na `81096be` (scalony #650). Wszystkie
gałęzie tego pakietu wychodzą z `27130f5`.

**Stan zastany, potwierdzony pomiarem:** CI dla `27130f5` — SUCCESS (12/12
zadań, run 35263057004). Wdrożenie Railway `fa8f012e` — SUCCESS, aktywne od
17.09 19:58:26 UTC. `GET https://kuking.pl/health` (17.09 21:10 UTC) → HTTP
200, `status: degraded`, jedyne czerwone pole to `kolejka: zadania_nieudane`.
To jest odbiór **dostępności**, nie odbiór funkcjonalny Alfy 0.57.

---

## 1. Gałęzie, SHA, PR-y

| Pakiet | PR | Scalone do `main` jako |
|---|---|---|
| #598 budżet połączeń | [#655](https://github.com/woogitsu/kuking.pl/pull/655) | `341d3508483e4dfee637fea9246f39501b3078ea` |
| #619 / #120 / #617 R2 | [#656](https://github.com/woogitsu/kuking.pl/pull/656) | `eda0cc81e7cf4358b760334d4385d5703df9c5e4` |
| #599 monitoring | [#653](https://github.com/woogitsu/kuking.pl/pull/653) | `432fa12e012cd591b76a9cf377a2387b6f2b0dc6` |
| #193 / #594 kopie | [#662](https://github.com/woogitsu/kuking.pl/pull/662) | `05f99ba53251e0a6aab27378f47186afda423f9a` |

Każdy PR miał **12/12 zielonych zadań CI** przed scaleniem i przeszedł pełny
`./scripts/check.sh --szybko` w hooku `pre-push`. Żadnego hooka nie obchodzono,
żadnego `--no-verify`, żadnego automerge.

**Scalanie:** pierwotny podział przewidywał, że review i scalenie wykonuje
drugi model. Właściciel zmienił to w trakcie nocy, dopuszczając scalenie po
zielonym CI. Odnotowuję wprost: **odbiór pakietu #598 nie był niezależny** —
scalił go ten sam agent, który go napisał.

Dowody wpisane do issues: [#598](https://github.com/woogitsu/kuking.pl/issues/598#issuecomment-5721446084),
[#599](https://github.com/woogitsu/kuking.pl/issues/599#issuecomment-5721446374).
Żadne issue nie zostało zamknięte — wszystkie mają otwarty odbiór produkcji.

---

## 2. Środowisko — dwie rzeczy, które kosztują godziny

### 2.1. `PGTZ=UTC` jest obowiązkowe przy każdym uruchomieniu testów

Lokalny klaster PostgreSQL `127.0.0.1:55439` ma strefę sesji
**Europe/Warsaw**, CI ma UTC. Bez `export PGTZ=UTC` oblewa **dziewięć**
testów: `AccountStatusTest`, `AkcjeKomentarzaWJednymRzedzieTest`,
`ArchiwumProfiluLiczyLataLokalnieTest` (2), `AutoryzacjaTrasZWiazaniemModeluTest`,
`CommentEditTest` (2), `DataWpisuGubiRokTylkoWTymRokuTest`,
`KazdaTrasaZIdentyfikatoremPodPolicyTest`.

**Sprawdzone kontrolnie, nie założone:** w osobnym worktree na czystym
`origin/main` `27130f5` i na osobnej bazie `kuking_598_kontrola` oblewa
dokładnie ta sama dziewiątka; z `PGTZ=UTC` przechodzi cały zestaw
(54 passed, 893 asercje). To jest stan środowiska, nie regresja.

### 2.1a. Oblane testy, które nie były regresją — dwie różne przyczyny

Dwa razy w ciągu nocy pełny zestaw oblał przy wysyłce i za każdym razem
przyczyna była inna, a żadna nie leżała w kodzie produkcyjnym:

- **Mój przebieg gałęzi #598.** Powtórka tego samego commita przeszła
  w całości: **3984 passed, 78 666 asercji**. W tym samym czasie jeden
  z podagentów użył globalnego `pkill -f 'artisan test'`, który ubija także
  cudze przebiegi, a zabity proces daje niezerowy kod wyjścia i `check.sh`
  raportuje to jako „Testy nie przechodzą". **To jest najbardziej prawdopodobne
  wyjaśnienie, ale nie dowiedzione** — nie mam logu tamtego przebiegu, bo
  `check.sh` kieruje wyjście testów do `/dev/null`.
- **Przebieg pakietu kopii.** Tu przyczyna została ustalona, nie zgadnięta:
  wyścig w samym teście (podstawiony `pg_dump` łapał wiersz z `ps`, a zrzut
  fikstury kończył się szybciej). Samotnie zielone, w pełnym przebiegu
  czerwone, powtarzalnie dwa razy z rzędu. Znalezione przez `--log-junit`.

Wnioski na przyszłość: **nigdy `pkill -f 'artisan test'`** — zabij konkretny
PID po sprawdzeniu jego `cwd`. I nie przyjmuj nagłej czerwieni za regresję,
zanim nie sprawdzisz, czy proces nie został zabity z zewnątrz.

### 2.2. Push wyłącznie z klonu w systemie plików WSL

Worktree na dysku windowsowym ma w pliku `.git` ścieżkę w zapisie
windowsowym (z literą dysku),
której git z WSL nie otworzy, a hook `pre-push` nie zadziała pod Windows
(tamtejszy PHP nie ma `pdo_pgsql` ani `mbstring`, brak `pg_isready`).
Rozwiązaniem **nie jest** `--no-verify`, tylko osobny klon w `/home/mateusz/…`
z `origin` na GitHub, `lokalne` na repo kanoniczne i jawnymi `DB_*` + `PGTZ`
przy wysyłce. Hook uruchamia PEŁNY `php artisan test` — przy maszynie
dzielonej z innymi modelami ponad pół godziny na jedną wysyłkę.

---

## 3. Pakiet #598 — budżet połączeń PostgreSQL

### Odtworzony problem

Brakowało **efektywnej równoległości HTTP działającego procesu FrankenPHP**.
Komentarz z 17.09 nazywał to wprost i ostrzegał przed zastępowaniem tej
liczby liczbą rdzeni hosta. Bez niej próg alarmowy byłby liczbą z sufitu.

### Zmierzone na produkcji (wyłącznie odczyty)

| Co | Wartość | Skąd |
|---|---|---|
| `GOMAXPROCS` | 2, „determined from CPU quota" | log startowy wdrożenia `fa8f012e`, 17.09 19:58:17 UTC |
| `num_threads` / `max_threads` | **4 / 4** | ten sam log |
| limit CPU / pamięci | 2 vCPU / 1,0 GB (szczyt 7 dni 0,53 GB) | metryki Railway |
| topologia | jeden serwis, `kuking-entrypoint all`, 1 replika | konfiguracja usługi |
| nakładanie kontenerów przy wdrożeniu | **potwierdzone** | `fa8f012e` aktywne 19:58:26 UTC, poprzednie `de103f9b` → `REMOVED` 19:58:30 UTC |

`max_connections = 500` i `superuser_reserved_connections = 3` pochodzą
z wcześniejszego odczytu zapisanego w #598 (17.09 ~00:24 CEST) — **nie
mojego**: produkcyjny Postgres nie ma proxy TCP, CLI Railway nie jest
zalogowane, SQL-a na produkcji nie uruchamiałem.

### Zmierzone lokalnie (baza `kuking_598_pomiar`, próbnik na `pg_stat_activity`)

6 równoległych cykli żądania → **6 backendów** (sesje, cache i kolejka na
bazie dzielą jedno połączenie). Pojedyncze żądanie → **1**, po zakończeniu
**0**. `queue:work` → 1 trwale. `schedule:run` i `migrate --force` → po 1.
**Kontrola metody:** ten sam próbnik pokazał 6 przy sześciu procesach.

### Policzone

`4 + 1 + 1 = 6` w spoczynku; `6 + 6 + 1 = 13` w oknie wdrożenia; `+3` zapasu
→ **budżet szczytowy 16 z 497 miejsc (3,2 %)**. Progi: ostrzegawczy **50**
(pyta „czy budżet nadal opisuje rzeczywistość"), krytyczny **125** (ćwiartka
puli — wyczerpanie `max_connections` jest awarią skokową).

**Wniosek dla #600: liczby nie uzasadniają dziś PgBouncera.** Zapas starcza
na ponad sto replik `web`. Nie zwiększano replik ani workerów, nie wykonano
testu obciążeniowego produkcji.

---

## 4. Pakiet #599 — monitoring

### Dwa odtworzone problemy

1. **Kanał alarmowy jest martwy.** W usłudze produkcyjnej **nie ma
   `LOG_BLAD_WEBHOOK_URL`** (odczyt listy zmiennych przez API Railway;
   `sealedVariableNames` puste, więc brak nazwy = brak zmiennej). Dziś nie
   dzwoni nic — ani błąd 500, ani czujka kopii. `AWS_KOPIE_BUCKET` też nie
   istnieje, więc `kuking:sprawdz-kopie` kończy się sukcesem i milczy.
2. **`/health` przestał odróżniać awarię od jej braku.** Pole `kolejka`
   liczy wszystkie wiersze `failed_jobs`, a leżą tam cztery zadania
   z 9 września — `/health` stoi w `degraded` nieprzerwanie od tygodnia.
   To nie jest usterka `/health`; brakowało drugiego sygnału, więc
   **`/health` zostaje bez zmian**.

Trzecia rzecz, której nie mierzy nic: **zaległość kolejki**. Worker, który
przestał chodzić, nie zgłasza żadnego błędu.

### Co dodano

`kuking:sprawdz-kolejke` (co 15 min) i `kuking:budzet-polaczen` (co godzinę),
oba na istniejącym kanale `blad_webhook` (D-041) — bez nowej platformy.
Czujka kolejki pyta o ZDARZENIE (okno 3 h) i o zaległość; ocena jest
bezstanowa, bo entrypoint czyści cache przy każdym starcie kontenera.
`docs/infra/MONITORING_BLEDOW.md` §7 dostał inwentarz alertów wraz
z kolejnością zamykania bramki.

### Dowód dostarczenia — prawdziwy lokalny odbiornik HTTP, nie atrapa

Pusta kolejka → cisza. Zadanie czekające 900 s → jedna wiadomość. Ta sama
awaria drugi raz → cisza. Powrót do normy → dokładnie jedna wiadomość
odwołująca. Kolejny spokojny przebieg → cisza. To samo dla budżetu połączeń.
Kontrola ujemna treści: brak nazwy klasy zadania, adresu e-mail z `payload`,
treści `exception` i nazwy bazy.

**Ta próba wykryła usterkę, której nie widział żaden test z `Http::fake`:**
`WebhookBleduHandler` sam dokleja nagłówek `[nazwa/środowisko]`, a klasy
alarmu doklejały go drugi raz. Naprawione w nowych klasach.
**`App\Domain\Kopie\AlarmKopii` ma tę samą usterkę i nie jest naprawiony** —
pracuje nad tym plikiem pakiet #193/#594. Skutek wyłącznie kosmetyczny.

### Kontrole ujemne

Trzynaście sabotaży, każdy z kopią bajtów poza repo i przywróceniem
zweryfikowanym przez MD5 oraz `mtime` — wszystkie zgodne. Dwie pierwsze
wersje sabotażu nie zmieniały badanej reguły, więc testy słusznie
przechodziły; poprawione i powtórzone. **Kontrola ujemna, która nie
czerwieni, jest albo dziurą w teście, albo błędem w sabotażu — trzeba
rozstrzygnąć, która to z tych dwóch rzeczy.**

---

## 4a. Pakiet #619 / #120 / #617 — R2 (podagent B)

Weryfikowałem zakres commitów niezależnie: cztery pliki, wszystkie
w przydzielonym obszarze, `config/filesystems.php` nietknięty, brak migracji.

**Dwie odtworzone usterki bramki #120:**

1. **Publiczny adres bez protokołu przechodził bramkę na zielono.** Panel
   Cloudflare pokazuje te adresy jako `cdn.kuking.pl` / `pub-abc123.r2.dev`;
   przepisane dosłownie nie mają hosta, wyjątek klienta HTTP zamieniał się
   w „brak odpowiedzi", a to przy działającym wyjściu na świat liczyło się
   jako **odmowa**. Bramka meldowała „Każdy zadeklarowany adres odmówił"
   i kod wyjścia 0 o środowisku, w którym nikt o nic nie zapytał.
2. **Sterownik dysku wariantów nie był sprawdzany.** Dysk lokalny w tej roli
   przechodził dalej, a sprawdzenie „w publicznym buckecie nie ma `incoming/`"
   listowało pusty katalog lokalny i odpowiadało TAK — odpowiedź prawdziwa,
   tylko nie o R2.

**Nowe sprawdzenie 12 (#619):** kształt `AWS_ENDPOINT`. Udany odczyt obiektu
przez endpoint **bez** segmentu jurysdykcji dowodzi, że buckety jurysdykcji
nie mają — to jest „wiemy, że nie", a nie „nie wiemy". Na ekran idzie sam
segment, nigdy host (identyfikator konta). Oczekiwana jurysdykcja jest stałą
w kodzie, nie zmienną środowiskową: wariant B z #619 ma wymagać zmiany
polityki prywatności i kodu naraz.

**Próba odtworzenia (#617)** na własnym zbiorze, bez R2: 20/20 kompletność,
20/20 rozmiar, 20/20 SHA-256, 5/5 wpisów. Kontrola ujemna: jeden podmieniony
bajt — SHA-256 wykryła, sam rozmiar **nie**.

**Rzecz, którą złapał dopiero pełny zestaw:** `TekstyNiePrzypisujaPlciTest`
oblał na nowej podpowiedzi bramki „z protokołem" — końcówka `-łem` pasuje do
wzorca męskiej formy przeszłej. Zdanie przebudowano zgodnie z `COPY_STYLE.md`
§2, bez zamiany na formę żeńską i bez wypisywania obu form. To jest argument
za pełnym przebiegiem przed wysyłką, nie za przebiegami wycinkowymi.

**Blokada:** panel Cloudflare niedostępny (przekierowanie na `/login`).
Bez niego nieosiągalne: typ lokalizacji każdego bucketu, Location Hint,
kompletna lista publicznych adresów, stan Bucket Locków. Lista do przepisania
z miejscem na datę: `docs/infra/LOKALIZACJA_DANYCH_R2.md` §5.

**Rekomendacja do decyzji właściciela (#617), nie wykonanie:** druga kopia
obiektów + Bucket Lock **wyłącznie na buckecie kopii**, retencja 30 dni,
propagacja usunięcia konta po tym okresie. Lock na żywym buckecie oryginałów
jest wykluczony — wszyscy użytkownicy leżą pod wspólnym prefiksem `incoming/`,
więc blokada uniemożliwiłaby wykonanie żądania usunięcia danych. Dziś RPO dla
obiektów jest faktycznie nieskończone, a jedno poświadczenie `AWS_*` ma prawo
kasowania we wszystkich czterech bucketach ze zdjęciami i paczkami RODO.

> **SPROSTOWANIE Z 19 IX 2026 (#691). RYGIEL NIE KASUJE I NIE JEST
> NIEODWRACALNY.** Akapitu wyżej **nie zmieniam** — to datowany zapis
> przekazania i ma pokazywać, co twierdzono w nocy 17/18 września. Poniżej
> jest to, co wiadomo dziś.
>
> **Co twierdzono wtedy:** „Bucket Lock **wyłącznie na buckecie kopii**,
> retencja 30 dni, propagacja usunięcia konta po tym okresie" — bez ani
> jednego słowa o regule lifecycle. Zdanie czyta się tak, jakby sam rygiel
> po 30 dniach kasował kopie i tym samym domykał żądanie usunięcia konta.
>
> **Co jest prawdą.** Dokumentacja Cloudflare „Bucket locks" (odczyt
> 19 IX 2026) mówi o ryglu jedno zdanie i nie ma w nim słowa o kasowaniu:
> *„Bucket locks prevent the deletion and overwriting of objects in an R2
> bucket for a specified period — or indefinitely."* Rygiel jest **zakazem
> usuwania i nadpisywania**, nie zegarem retencji. Po 30 dniach obiekt nadal
> leży w buckecie; zmienia się tylko tyle, że **wolno** go wtedy skasować.
> Kasuje dopiero **osobna reguła lifecycle** („Object lifecycles", akcja
> delete), która musi mieć okres **dłuższy** niż rygiel, bo *„Bucket lock
> rules take precedence over lifecycle rules"*, a jej skutek jest
> asynchroniczny: *„Objects will typically be removed from a bucket within
> 24 hours of the `x-amz-expiration` value."* Rygiel jest też **odwracalny** —
> dokumentacja ma rozdział „Remove bucket lock rules from your R2 bucket"
> i trzy drogi zdjęcia reguły (panel, Wrangler `r2 bucket lock remove`, API);
> warunek jest jeden — token z prawem do edycji konfiguracji bucketu. To
> **nie** jest tryb compliance S3 Object Lock z innego produktu i nie wolno
> ich utożsamiać. Stąd poprawka do modelu zagrożeń: rygiel broni przed
> tokenem aplikacji i tokenem procesu kopiującego (mają prawo do obiektów,
> nie do konfiguracji bucketu), a **nie** broni przed właścicielem konta ani
> żadnym tokenem z prawem do konfiguracji. Wniosek brzmi więc nie „rygiel =
> ochrona przed skasowaniem", tylko „rygiel = wyprowadzenie skasowania poza
> automat, do ręcznej czynności uprzywilejowanej".
>
> **Trzy rzeczy, które tamten akapit zlepiał w jedną:**
>
> | | Co to jest | Czym się to robi | Gdzie to stoi |
> |---|---|---|---|
> | **(a) ochrona przed usunięciem** | „przez 30 dni nikt tego nie skasuje" | Bucket Lock, `MaxAgeSeconds` | rozstrzygnięte — [`LOKALIZACJA_DANYCH_R2.md`](LOKALIZACJA_DANYCH_R2.md), kroki 1–2 |
> | **(b) koniec retencji** | „po 30 dniach tego ma nie być" | **osobna reguła lifecycle**, okres dłuższy niż rygiel | rozstrzygnięte — tamże, krok 2a |
> | **(c) rzeczywisty odbiór na prawdziwym buckecie** | „sprawdziliśmy, że naprawdę zniknęło" | własny obiekt kontrolny + `HEAD` po terminie | **otwarte** — należy do #120 / #617 / #619 |
>
> **Skąd to wiadomo:** odczyt dokumentacji dostawcy, nie pomiar na koncie
> Cloudflare. Do żadnego bucketu ani obiektu R2 nikt przy tym sprostowaniu
> nie sięgał. Źródła:
> <https://developers.cloudflare.com/r2/buckets/bucket-locks/> oraz
> <https://developers.cloudflare.com/r2/buckets/object-lifecycles/>.
> Pełna, sprostowana wersja zaleceń dla #617 jest
> w [`LOKALIZACJA_DANYCH_R2.md`](LOKALIZACJA_DANYCH_R2.md) (sekcja „Dlaczego
> NIE WOLNO zaryglować żywego bucketu oryginałów" i kroki 1–3 wraz z 2a).
> **To sprostowanie nie zamyka #120, #617 ani #619** — punkt (c) nadal
> nie ma dowodu.

---

## 4b. Pakiet #193 / #594 — kopie i odtworzenie (podagent A)

Trzy odtworzone usterki, każda zmierzona, nie wyczytana z kodu.

1. **Hasło do bazy stało w `ps` przez cały czas zrzutu.** Prawdziwe
   `ps -o args=` pokazało pełny DSN. Naprawione we wszystkich trzech
   skryptach: hasło idzie do prywatnego `PGPASSFILE` z prawami 600,
   a narzędzia dostają adres bez hasła.
2. **Blokada hosta nie widziała produkcji podanej przez tunel.** Ten sam
   klaster odrzucony jako `*.proxy.rlwy.net` (kod 21) został **przyjęty**
   jako `127.0.0.1`: baza powstała, `pg_restore` wlał komplet danych
   osobowych, a skrypt wypisał „serwer nie jest produkcyjny". Naprawione
   bezpiecznikiem tożsamości klastra — `system_identifier` z
   `pg_control_system()` nadaje `initdb` i żaden tunel go nie zmienia;
   każda instancja spoza repozytorium wymaga jawnego `--instancja <odcisk>`,
   a odmowa następuje **przed** `CREATE DATABASE`.
3. **Uszkodzony szyfrogram odszyfrowywał się z kodem 0** (AES-CBC bez
   uwierzytelnienia) i wykładał się dopiero na `pg_restore` — po wlaniu
   części danych. Naprawione porównaniem ze skrótem `sha256_jawnego`,
   który leżał w pliku `.meta` od początku i którego nikt nie czytał.
   Odmowa przed założeniem bazy.

Do tego naprawa `AlarmKopii` (podwojony nagłówek — moje znalezisko, ich plik)
razem z asercją na liczbę wystąpień.

**Pętla lokalna:** zrzut 164 897 B, `pg_restore` 2 s, **50/50 tabel co do
jednego wiersza**, `migrate:status` 80/0, rozszerzenia i 25 indeksów
częściowych jak w źródle, strefa UTC po obu stronach. Scenariusze błędów mają
rozdzielne kody wyjścia (pusta baza 40, brak uprawnień 30, zrzut obcięty 41,
zły klucz 43, uszkodzony szyfrogram 44) i żaden nie zostawia śmieci.

**Sześć kontroli ujemnych** z kopią bajtów poza repo i przywróceniem
sprawdzanym przez MD5 oraz `mtime`. Dwie z nich wykryły **martwe asercje
strukturalne** — zestaw zostawał zielony po przywróceniu usterki. Zastąpiono
je kontrolą zachowania przez podstawiony `psql`.

**Czego to NIE zamyka.** Produkcyjnego RTO ani RPO nie wpisano: 2 s dotyczy
192 wierszy na pętli lokalnej, a RPO **nie jest dziś mierzalne w ogóle**, bo
nie ma harmonogramu ani jednej udanej kopii. Odczyt produkcji potwierdza
dlaczego: serwis `kopia-bazy` **nie istnieje**, żaden serwis nie ma crona,
lista zmiennych współdzielonych jest pusta, po stronie Railwaya nie ma
bucketów. Dodatkowe ograniczenie środowiska: lokalny klaster 55439 stoi na
`trust`, więc **żaden tutejszy przebieg nie dowodzi uwierzytelniania** —
asercje o `PGPASSFILE` patrzą na plik i na argumenty, nie na logowanie.

---

## 5. Co zweryfikowano na produkcji, a co tylko lokalnie

**Na produkcji (wyłącznie odczyty, zero zapisów):** stan wdrożenia i CI,
konfiguracja usług i wolumenów, metryki CPU/pamięci, log startowy FrankenPHP,
lista nazw zmiennych środowiskowych, jeden publiczny `GET /health`.

**Tylko lokalnie:** cały pomiar połączeń na cykl żądania, obie czujki,
dostarczanie alarmów, wszystkie kontrole ujemne.

**Czego NIE zrobiono i nie wolno uznać za zrobione:** nie uruchomiono SQL-a
na produkcyjnym Postgresie, nie wykonano produkcyjnej kopii ani odtworzenia,
nie zmieniono żadnej zmiennej, nie wykonano restartu, redeployu ani
`railway config apply`, nie rozliczono czterech zadań w `failed_jobs`.

---

## 6. Blokady i decyzje właściciela

1. **`LOG_BLAD_WEBHOOK_URL` w panelu Railway + restart usługi.** Do tego
   czasu nie dzwoni nic i nie wolno ogłaszać działającego alarmu.
2. **Cztery zadania z 9 września w `failed_jobs`.** Rozliczyć przez
   `php artisan kuking:martwe-zadania` (bez `--skasuj` niczego nie usuwa).
   **Nie ponawiać zbiorczo** — żeton resetu hasła wygasa, więc `queue:retry`
   po tygodniu wysłałby czterem osobom martwy link.
3. **Zewnętrzny monitor `/health`** — nadal `NIEZROBIONE`; dopiero po p. 2,
   inaczej alarmowałby od pierwszej minuty.
4. **Brak proxy TCP do produkcyjnego Postgresa i niezalogowane CLI Railway** —
   bez tego nie ma szeregu czasowego połączeń ani pomiaru szczytu przy
   wdrożeniu.
5. **Panel Cloudflare** — bez dostępu nie da się domknąć #619 ani #120.

---

## 7. Aktywne procesy, bazy i artefakty

**Bazy na `127.0.0.1:55439`** (nigdy 5432): `kuking_598_tests`,
`kuking_598_pomiar`, `kuking_598_kontrola`, `kuking_599_tests`,
`kuking_599_odbiornik`, oraz `kuking_594_*` i `kuking_619_*` podagentów.
Wszystkie utworzone w tej sesji, wszystkie do skasowania po przeglądzie.

**Kopie wykonawcze w WSL:** `/home/mateusz/kuking-598-claude` (klon do
wysyłki), `/home/mateusz/kuking-main-kontrola` (worktree na `27130f5` do
kontroli), analogiczne u podagentów.

**Worktree na Windows:** `kuking-598-polaczenia`, `kuking-594-kopie`,
`kuking-619-r2` — wszystkie utworzone w tej sesji, z własną kopią `vendor`.

Sekretów, dumpów ani danych użytkowników nie ma w repo, w komentarzach,
w artefaktach CI ani w żadnym raporcie.

---

## 8. Stan wdrożenia i co zostaje następnej osobie

Wszystkie cztery pakiety są scalone do `main` i pojechały na produkcję.
Wdrożenie `58b4d059` (SUCCESS, 17.09 22:44 UTC) niosło pakiety #598 i R2;
kolejne wdrożenia niosą resztę — Railway pomija wdrożenia wyprzedzone przez
świeższy commit na `main`, więc pojedyncze `SKIPPED` nie znaczy, że kod nie
pojechał, tylko że pojechał z następnym.

**Sprawdzone z zewnątrz po wdrożeniu (17.09 22:57 UTC):** `GET /health`
HTTP 200 w 0,46 s, strona główna HTTP 200 w 0,17 s. Wszystkie pola `checks`
zielone poza znanym `kolejka: zadania_nieudane`. Harmonogram pracuje —
w logu widać `kuking:policz-kolejki` co pięć minut i zadania godzinne.

**Czujka budżetu połączeń naprawdę chodzi na produkcji.** Log wdrożenia
`58b4d059`, 17.09.2026 23:25:20 UTC:

```
2026-09-17 23:25:20 Running [kuking:budzet-polaczen] .......... 21.23ms DONE
```

Co to dowodzi: zadanie jest wpięte w harmonogram produkcyjny, uruchamia się
o właściwej minucie, kończy bez wyjątku i kosztuje 21 ms.

Czego NIE dowodzi: **zmierzonych liczb nie widać**. `Schedule::call()` woła
komendę przez `Artisan::call()`, które przechwytuje wyjście konsoli, więc
tabela pomiaru nie trafia do dziennika serwera. Żeby zobaczyć rzeczywiste
`max_connections` i zajętość, trzeba uruchomić komendę ręcznie
(`railway ssh -- php artisan kuking:budzet-polaczen`) — na to potrzebny jest
zalogowany CLI Railway, którego ta sesja nie miała. To jest też powód, dla
którego **szereg czasowy połączeń z produkcji nadal nie istnieje**, a #598
pozostaje otwarte mimo scalonego kodu.

### Co zostaje do zrobienia, w kolejności

1. **`LOG_BLAD_WEBHOOK_URL` w panelu Railway + restart usługi.** To jest
   jedna czynność, która zamienia trzy zaimplementowane czujki w działający
   monitoring. Do tego czasu **nie wolno ogłaszać działającego alarmu**.
2. **Rozliczyć cztery zadania z 9 września** (`kuking:martwe-zadania`,
   bez `--skasuj` niczego nie usuwa), żeby `/health` wyszedł z `degraded`
   i znowu coś znaczył. **Nie ponawiać zbiorczo.**
3. **Zewnętrzny monitor `/health`** — dopiero po punkcie 2.
4. **Cztery czynności właściciela z `KOPIE_I_ODTWORZENIE.md` §7.3**: bucket
   R2 na zrzuty, dwa tokeny, para kluczy, serwis `kopia-bazy` ze zmiennymi.
   Dopiero potem odtworzenie kopii **pobranej z bucketu** zamyka #193.
5. **Panel Cloudflare** — bez niego nie domkną się #619 ani #120; lista
   rzeczy do odczytania z miejscem na datę: `LOKALIZACJA_DANYCH_R2.md` §5.

### Czego ta noc nie rozstrzygnęła

Żadne issue nie zostało zamknięte. Wszystkie cztery (#193, #594, #598, #599)
oraz #120, #617 i #619 mają otwarty odbiór produkcji i dostały komentarze
z dowodami zamiast deklaracji.

**Dwie rzeczy nazwane wprost, bo łatwo je przeczytać jako sukces:**

- **Kod jest gotowy, infrastruktura nie.** Kopia bazy, alarm i bramka R2 mają
  dziś sprawny mechanizm i zero działających instancji: serwisu kopii nie ma,
  bucketu nie ma, zmiennej alarmowej nie ma. „Gotowe" dotyczy kodu.
- **Odbiór trzech pakietów nie był niezależny.** Napisał je i scalił ten sam
  agent (dwa przez podagentów tej samej sesji). Właściciel zmienił w trakcie
  nocy pierwotny podział, który przewidywał review przez drugi model.

### Jedna rzecz o metodzie, warta zapamiętania

Trzy z pięciu najpoważniejszych znalezisk tej nocy wyszły **dopiero
z pomiaru zachowania**, nie z czytania kodu i nie z testów jednostkowych:
podwojony nagłówek na prawdziwym odbiorniku webhooka, przyjęcie produkcji
podanej przez tunel jako `127.0.0.1` i uszkodzony szyfrogram kończący się
kodem 0. Wszystkie trzy miały wcześniej zielone testy.

Do tego dwie kontrole ujemne, które **nie zaczerwieniły się po sabotażu** —
w obu przypadkach okazało się, że winna jest martwa asercja albo źle napisany
sabotaż. Kontrola ujemna, która przechodzi, jest pytaniem, nie formalnością:
**albo test niczego nie pilnuje, albo sabotaż nie ruszył tego, co trzeba** —
i trzeba rozstrzygnąć, która to z tych dwóch rzeczy, zanim pójdzie się dalej.
