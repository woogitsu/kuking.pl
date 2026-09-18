# Przekazanie prac operacyjnych — 18 września 2026

Dokument dla osoby, która przejmuje pracę. Pisany tak, żeby dało się ją podjąć
bez czytania rozmów: każde twierdzenie ma powiedziane, **skąd wiadomo**, a każda
rzecz niewykonana ma powiedziane, **co konkretnie zrobić dalej**.

Poprzednie przekazanie: [`PRZEKAZANIE_PRAC_OPERACYJNYCH_2026_09_17.md`](PRZEKAZANIE_PRAC_OPERACYJNYCH_2026_09_17.md).
Odniesienie dla całej tej pracy: `main` na `bdc56b8cf9b664eda104b628d85149b08d84d8d9`.

> **Zdanie, od którego trzeba zacząć, żeby nie przeczytać reszty źle.**
> Ta sesja **nie uruchomiła kopii produkcyjnej bazy i nie doprowadziła alarmu
> do żywego odbiorcy.** Liczba kopii produkcyjnej bazy nadal wynosi **zero**,
> a zmiennej `LOG_BLAD_WEBHOOK_URL` na produkcji **nadal nie ma** — sprawdzone
> dzisiaj, punkt 4. Przybyło narzędzi, dowodów i jedna naprawiona usterka;
> **nie przybyło ani jednej działającej warstwy infrastruktury.**

---

## 1. Gałęzie, SHA, PR-y

| Pakiet | Gałąź | SHA | PR | Stan |
|---|---|---|---|---|
| Kopie i R2 (#193 #594 #120 #617 #619) | `infra/594-odbior-kopii` | `31a0b40b7601fefa0c37ed7a8efad124eb8eb34d` | **#674** | Draft, CI **12/12 pass**, bez automerge, **niescalony** |
| Czujki i odbiór alarmów (#598 #599) | `infra/599-odbior-alarmow` | `7c300ccccc5a81f098e85667498e3afdaf89c89c` | **#676** | Draft, bez automerge, **niescalony**; hook `pre-push` przeszedł w całości |
| Obciążenie mieszane (#605) | `perf/605-obciazenie-mieszane` | `047460da…` + `ae6781a…` | **brak — commity lokalne** | przyrząd, zbiór danych i metoda gotowe; **serii pomiarowych nie zdjęto** — powód w punkcie 6 |

**Wszystkie PR-y zostają do końcowego review i scalenia przez Codeksa.**
Automerge nigdzie nie jest włączony. Żadnego issue nie zamknięto — sprawdzone:
#193, #594, #120, #617, #619 są `OPEN`.

Zakresy Codeksa (#667/PR #672, #666, #492, przygotowanie #371/#372) nie były
dotykane. Żadna z tych gałęzi nie zmienia wyglądu, nawigacji, tekstów produktu,
globalnego CSS, numeru Alfy ani changelogu.

---

## 2. Środowisko — trzy rzeczy, które kosztują godziny

### 2.1. `PGTZ=UTC` jest obowiązkowe

Klaster `127.0.0.1:55439` ma strefę sesji `Europe/Warsaw`, a CI chodzi w UTC.
Bez `PGTZ=UTC` oblewa dziewięć testów **niezwiązanych ze zmianą**. Sprawdzone
kontrolnie 17 IX na czystym `origin/main`, w osobnym drzewie i osobnej bazie:
tam oblewa **ten sam zestaw dziewięciu**. To stan środowiska, nie regresja.

### 2.2. `phpunit.xml` ma na sztywno port **5432** — klaster współdzielony

```xml
<env name="DB_PORT" value="5432"/>
```

To znaczy, że kto uruchomi pełny zestaw **bez wyeksportowanego `DB_PORT`**,
pojedzie po klastrze współdzielonym, a nie po własnym. PHPUnit nie nadpisuje
zmiennej już obecnej w środowisku (brak `force="true"`), więc eksport wygrywa —
ale **to trzeba było sprawdzić, a nie założyć.** Sprawdzone 18 IX jednorazowym
testem, który zapytał serwer, z czym naprawdę rozmawia:

```
BAZA=kuking_c599_tests PORT=55439 STREFA=UTC UZYTKOWNIK=kuking
```

Test był tymczasowy i został skasowany — nie ma go w commicie. **Przy każdym
uruchomieniu zestawu warto powtórzyć ten odczyt**, zamiast ufać, że eksport
zadziałał. Powiązane: #66.

### 2.3. `APP_BASE_PATH` wpisywać dosłownie, nigdy przez `$(pwd)`

Powłoka Windows rozwija `$(pwd)` do repozytorium kanonicznego. Subagent A
stracił na tym cały przebieg: sześć testów oblało bez żadnego związku ze zmianą.
Po wpisaniu ścieżki dosłownie — zero oblanych.

### 2.4. Push wyłącznie z klonu w systemie plików WSL

Worktree na dysku Windows ma w `.git` ścieżkę nieużywalną z WSL, a Windowsowy
PHP nie ma `pdo_pgsql` ani `mbstring`, więc hook `pre-push` nie ma tam jak się
uruchomić. Stąd trzy natywne klony WSL. **Hooków nie obchodzimy** — w repozytorium
jest wyłącznie `pre-push` (sprawdzone: `.git/hooks/` nie zawiera `pre-commit`,
`core.hooksPath` nie jest ustawione).

---

## 3. Pakiet #598 / #599 — czujki zapisują pomiar, alarm daje się sprawdzić

### Odtworzony problem — harmonogram mierzył w próżnię

Zmierzone na produkcji **17 IX 23:25:20 UTC**: harmonogram uruchamia
`kuking:budzet-polaczen`, melduje „DONE" w 21 ms i **to wszystko, co zostaje**.
Zmierzonych liczb nie ma nigdzie, bo `Schedule::call()` woła komendę przez
`Artisan::call()`, a to przechwytuje wyjście konsoli do bufora, który kończy się
razem z przebiegiem.

Skutek był konkretny: definicji gotowości #598 („znany peak active connections
przy obecnej topologii") **nie dało się spełnić mimo w pełni działającej
czujki**. Każdy przebieg mierzył i natychmiast zapominał.

### Co dodano

1. `BudzetPolaczen` i `SprawdzKolejke` zapisują jedną linię `Log::info` z samymi
   liczbami. Do produkcyjnego Postgresa nie ma dostępu z zewnątrz — nie ma proxy
   TCP ani zalogowanego CLI — więc **dziennik serwera jest jedyną drogą**, którą
   ten szereg czasowy może powstać. Poziom `info`, bo zdrowy pomiar nie jest
   ostrzeżeniem.
2. `kuking:sprawdz-alarm` — jedna próbna wiadomość na kanał alarmowy.

### Pięć warstw monitoringu — i dlaczego nie wolno ich mylić

| # | Warstwa | Stan na 18 IX 2026 |
|---|---|---|
| 1 | kod czujki | **jest**, scalony, otestowany |
| 2 | konfiguracja produkcji (`LOG_BLAD_WEBHOOK_URL`) | **BRAK** — sprawdzone dziś, punkt 4 |
| 3 | faktyczne wywołanie (harmonogram) | **jest**, potwierdzone odczytem logu produkcji 17 IX |
| 4 | **odebranie wiadomości** | **niesprawdzone na produkcji** — bo warstwa 2 nie istnieje. Lokalnie sprawdzone na prawdziwym odbiorniku |
| 5 | wyciszanie duplikatów i powrót do normy | **jest**, otestowane |

Do 18 IX warstwy 4 nie dało się sprawdzić inaczej niż doprowadzając do
prawdziwej awarii albo zaniżając próg czujki na żywym serwisie. `kuking:sprawdz-alarm`
zamienia to w jedno polecenie, które niczego nie psuje: nie dotyka bazy, nie
czyta kolejki i **nie zapisuje pamięci wyciszania alarmów** — inaczej sprawdzenie
kanału głuszyłoby prawdziwy alarm tuż po nim.

### Dowód dostarczenia — prawdziwy odbiornik, nie atrapa

Lokalny odbiornik HTTP na porcie 8599, trzy przebiegi:

| Układ | Wynik |
|---|---|
| kanał wyłączony | kod wyjścia **1**, cisza na odbiorniku, komunikat wymienia `LOG_BLAD_WEBHOOK_URL` |
| `--bez-wysylki` | kod **0**, cisza na odbiorniku |
| kanał włączony | kod **0**, **dokładnie jedna** wiadomość, **jeden** nagłówek `[Kuking/local]`, bez adresu webhooka, bez nazwy bazy |

Nagłówek liczony przez `substr_count(...) === 1`, nie przez „treść zawiera".
Ta różnica ma historię: 17 IX prawdziwy odbiornik pokazał wiadomości
zaczynające się od `[Kuking/local] [Kuking/local] …`, czego `Http::fake`
z asercją „zawiera" nie widział ani razu.

---

## 4. Co sprawdzono na produkcji — 18 IX 2026, wyłącznie odczyty

Dostęp sprawdzony **dzisiaj od nowa**, bo historyczny brak dostępu nie jest
dowodem dzisiejszego braku.

| Co | Wynik | Jak sprawdzone |
|---|---|---|
| Railway, połączenie | **działa**, tylko odczyt | Railway MCP (OAuth) |
| Wartości zmiennych | **nieczytelne** — `valuesRedacted: true` | jw. |
| `LOG_BLAD_WEBHOOK_URL` | **NIE ISTNIEJE** | nie ma go na liście nazw zmiennych serwisu `kuking.pl`, a `sealedVariableNames` jest **puste** — czyli brak nazwy jest dowodem braku zmiennej, nie skutkiem ukrycia wartości |
| `LOG_LEVEL` | **istnieje, wartość nieczytelna** | jw. — więc **nie da się dziś potwierdzić**, że szereg czasowy z punktu 3 w ogóle się zapisze |
| `KUKING_MODEL_ALARM_EMAIL` | **istnieje, wartość nieczytelna** | jw. |
| Cloudflare | **ściana logowania** | próby logowania nie podejmowano |
| Railway CLI | **brak zalogowanej sesji** | — |

**Konkretny brak, bez wymyślania odbiorcy:** kanał alarmowy nie ma dziś
odbiorcy. Nie wiadomo też, czy `KUKING_MODEL_ALARM_EMAIL` wskazuje na
skrzynkę, którą ktoś czyta — wartości nie da się odczytać z tej sesji.
**Żadnego adresu nie wymyślono i żadnej zmiennej nie ustawiono.**

---

## 5. Pakiet #193 / #594 / #120 — kopie i R2 (subagent A)

Pełny opis: PR #674 oraz `KOPIE_I_ODTWORZENIE.md` §5.1, §5.3, §7.6
i `BRAMKA_R2.md` §1a. Tutaj tylko to, co przejmujący musi wiedzieć.

**Pierwszy raz w historii tego repozytorium obraz `docker/kopia` został
uruchomiony.** Wcześniej był tylko budowany przez CI. Cała ścieżka przeszła
z kontenera: żywa baza PostgreSQL 18.6 → `pg_dump` → `openssl cms` → **PUT na
prawdziwy serwer S3** → **GET z bucketu** → odszyfrowanie → `pg_restore` →
weryfikacja. Odtworzona baza zgodna **co do jednego wiersza w 50 tabelach**
(202 = 202), `migrate:status` 80/0.

**Czym to NIE jest.** W miejscu R2 stało MinIO. Baza źródłowa była lokalna
i miała 202 wiersze. Klucze RSA były testowe i zostały skasowane. **Żadna
z tych liczb nie jest produkcyjnym RTO**, RPO pozostaje niemierzalne (nie ma
harmonogramu), a liczba kopii produkcyjnej bazy nadal wynosi **zero**. Wynik
trafił do tabeli prób deweloperskich (§5.1); **tabela odbioru produkcyjnego
pozostaje pusta** — sprawdziłem to osobno w diffie.

**Jedna prawdziwa usterka znaleziona i naprawiona.** `s3_lista_kluczy` pisze na
standardowe wyjście, więc wołano ją przez `$( )` — czyli w **podpowłoce**, razem
z którą ginęła zmienna `S3_KOD`. Komunikat o nieudanym listowaniu bucketu
**zawsze** brzmiał „HTTP brak", także gdy serwer odpowiedział 403 albo 404.
„Token nie ma uprawnień" i „bucketu nie ma pod tą nazwą" to dwie różne awarie
z dwiema różnymi naprawami, a w logu były **nie do odróżnienia**.

Sprawdziłem to twierdzenie sam, osobnym doświadczeniem, a nie czytając kod:

```
STARA droga  $( ) : kod_powrotu=1 S3_KOD=[]
NOWA  droga  > plik: kod_powrotu=1 S3_KOD=[403]
```

**Sprostowanie do D-043.** Dokumentacja Railway z 18 IX **nie potwierdza**, że
Volume Backups i PITR wymagają planu Pro; odczyt panelu z 17 IX mówił, że tak.
Dwa źródła, dwa zdania — **żadne nie jest dziś ustaleniem**. Rozstrzyga jedno
kliknięcie właściciela (punkt 8). Planu **nie zmieniano**, niczego **nie
włączano**, bucketów **nie przenoszono**, Bucket Locka **nie włączano**.

---

## 6. Pakiet #605 — obciążenie mieszane (subagent B)

**Sprostowanie do mojego własnego briefu.** Napisałem subagentowi B, że katalog
`docs/infra/evidence/load581/` nie istnieje. **To była nieprawda.** Na `bdc56b8`
katalog istnieje: 50 plików, dodany commitem `9a44ccc` z 15 IX; `git log --all
--diff-filter=D` nie pokazuje usunięcia. Pomyłka wzięła się stąd, że repozytorium
kanoniczne na Windows stoi na gałęzi `fix/579-kolejka-gospodarza` (`ac5ff9d`),
gdzie **nie ma całego drzewa `docs/infra/evidence`**. #605 **ma** baseline.

Stan: przyrząd, zbiór danych i metoda gotowe; serii pomiarowych **nie zdjęto
w oknie ciszy**, bo takiego okna nie było — i, co ważniejsze, **nie należy go
oczekiwać**.

### Dlaczego ciche okno tu nie nadejdzie — rzecz do zapamiętania

Ta maszyna jest **wspólnym hostem CI dla pięciu projektów**. Stoi na niej
**17 runnerów**: `kuking` 4, `woogitsu` 4, `lockstate` 3, `metro` 3,
`osadale` 3. W trakcie tej sesji **13 z nich miało aktywne procesy**, a
`load average` chodził między **9 a 26**.

To znaczy, że obciążenie tej maszyny **nie pochodzi od nas** — pochodzi od CI
cudzych repozytoriów. Wspólnej puli runnerów **nie wolno ruszać**, więc
czekanie na 45 minut ciszy jest czekaniem na coś, co zależy od tego, czy ktoś
w którymkolwiek z pięciu projektów akurat nie zrobi pusha. Kto przejmie pracę
i zobaczy wysokie obciążenie — **niech nie szuka winnego wśród naszych
procesów i niech nie zatrzymuje cudzych runnerów.**

### Co przyjęto zamiast czekania — bramkowanie obciążeniem

Zamiast „poczekamy na ciszę" obowiązuje protokół, w którym warunki pomiaru są
**mierzone i zapisywane**, a nie zakładane:

1. obce obciążenie próbkowane przez **cały** czas trwania stopnia, nie tylko na
   jego brzegach;
2. stopień zaczyna się dopiero, gdy obce obciążenie utrzyma się poniżej
   **zapisanego progu** przez pełne 60 s;
3. stopień skażony w trakcie jest **oznaczany i powtarzany**, a nie raportowany
   — skażone przebiegi też są zapisane, razem z powodem, żeby było widać, że
   bramka działała, a nie że wyniki dobierano;
4. obce obciążenie jest **osobną kolumną** przy każdej liczbie, obok kosztu
   własnego generatora;
5. stopień, którego nie da się zdjąć czysto, zostaje zapisany jako
   **niewykonany, z powodem**. Wiersz „przy 110 rps trzy próby skażone" jest
   wartościowy. Zmyślony punkt nasycenia nie jest.

Zbiór jest realistycznie nierówny: 200 000 wpisów, 20 000 przepisów,
200 000 komentarzy, 399 697 powiązań z tagami, 60 % wpisów od 20 kont, 40
przepisów po 300–600 komentarzy, tagi po Zipfie. Stanowisko to **obraz
produkcyjny tego commita** uruchomiony jako `all` z `--cpus=2 --memory=1g`,
a log startowy daje **te same wartości co produkcja wg #598**: `GOMAXPROCS=2`,
`num_threads=4 max_threads=4`. Koszt własny generatora raportowany osobno
(0,03 rdzenia, 80 MB RSS przy 5 rps).

**Ścieżka R2/CDN pozostaje NIEZMIERZONA** i jest tak oznaczona — dysk lokalny
nie jest zamiennikiem CDN-a.

### Próg 25 Mpx a podgląd z #430 — sprawdzone, **celowo niezgłoszone**

Koszt `POST /dodaj/zdjecie` **nie rośnie monotonicznie** z rozmiarem zdjęcia
(12 Mpx ≈ 265 ms, 24 Mpx ≈ 460 ms, **48 Mpx ≈ 82 ms**), bo
`media.podglad.max_megapixels = 25` sprawia, że powyżej progu synchroniczny
podgląd z #430 **w ogóle nie powstaje** i cała praca idzie do kolejki.

Sprawdzone celowo, nie wywnioskowane — i **nie jest to pomiar czasu**, więc
hałas maszyny niczego tu nie psuje; wynikiem jest obecność albo brak wariantu:

- **poziom danych**: żadne wgranie 48 Mpx nie ma `metadata.variants.podglad`;
  wszystkie 12 i 24 Mpx mają;
- **poziom strony**: przy 24 Mpx świeże zdjęcie jest na stronie wpisu już
  w pierwszym renderze; przy 48 Mpx **identyfikatora zdjęcia nie ma w HTML-u
  wcale**, dopóki kolejka nie skończy. Powtórzone dwukrotnie, identycznie.

**Sprostowanie do pierwszej wersji tej obserwacji:** autor **nie** widzi
komunikatu „zdjęcie się przygotowuje" ani znaku zastępczego. Widok pomija
zdjęcie warunkiem `isReady()` (AUDYT A3 w `layout.blade.php`), a w dzienniku
nie ma ostrzeżenia „Zdjęcie bez wygenerowanych wariantów", czyli `Media::url()`
nie jest w ogóle wołany. **Zdjęcia po prostu nie ma na stronie.** To co innego
niż komunikat o przygotowywaniu i dlatego pierwsza wersja nie została zgłoszona.

**Do #430 ani #605 nie zgłoszono tego nadal**, bo brakuje jednej liczby:
**jak długo to okno trwa pod obciążeniem**. Zapis z zapytaniem SQL i skryptem
powtórzenia stoi w `docs/infra/evidence/obciazenie605/prog-25mpx-a-podglad.md`.

---

## 7. Aktywne procesy, porty, bazy i katalogi

### Moje (koordynator)

- katalog: `/home/mateusz/kuking-C-alarmy` (klon natywny WSL)
- baza: **`kuking_c599_tests`** na `127.0.0.1:55439`, `PGTZ=UTC`
- port odbiornika alarmów **8599** — zwolniony
- dzienniki przebiegu: `/home/mateusz/dowody-c599-testy.log`, `dowody-c599-testy.xml`,
  `dowody-c599-push.log` (poza repozytorium)
- **żadnych działających procesów w tle**

> **Pułapka, która kosztowała jeden pełny przebieg.** Pierwsza wysyłka oblała
> na hooku, a `check.sh` wysyła wyjście testów do `/dev/null`, więc z samego
> hooka nie dało się powiedzieć, co oblało. Pełny zestaw z `--log-junit`
> pokazał jeden test: `ZaufaneHostyTest::test_x_forwarded_host_nie_zmienia_generowanego_adresu`.
> Przyczyną było **moje własne** `APP_URL=http://127.0.0.1:8599` — ten test
> porównuje wygenerowany host z `localhost`, czyli z hostem, który klient
> testowy bierze z `APP_URL`. Sprawdzone kontrolnie: ten sam test, jedna
> zmieniona zmienna, werdykt się odwraca (`FAIL` → `PASS`). **`APP_URL` nie
> należy eksportować przy uruchamianiu zestawu.**

### Subagent A — posprzątane, sprawdzone przeze mnie

Porty 9594, 9595, 9596, 8591 — **wszystkie wolne** (`ss -ltnp`). Bazy próbne
`kuking_a594_zrodlo`, `kuking_a594_bramka`, `proba_odtworzenia_a594`,
`proba_odtworzenia_a594b` — **skasowane**; została `kuking_a594_tests`.
Kontenery `kuking-a594-minio` i obraz `kuking-kopia:a594-probe` — usunięte.
Dowody poza repozytorium: `/home/mateusz/dowody-a594` (klucze testowe,
szyfrogramy i dane MinIO skasowane).

### Subagent B — **zostawione świadomie, do skasowania na słowo**

- kontener **`kuking-b605-app`**, healthy, port **8605** — zostawiony, żeby okno
  ciszy dało się wykorzystać od razu
- bazy `kuking_b605_obciazenie`, `kuking_b605_proba`, `kuking_b605_testy`,
  `kuking_b605_tests`
- `log_min_duration_statement='500ms'` ustawione **tylko dla bazy B** — wymaga
  `RESET` po zakończeniu serii
- artefakty poza repozytorium: `/home/mateusz/kuking-b605-run/` — **zawiera
  ciasteczka sesji i `APP_KEY` stanowiska, nic z tego nie poszło do gita**

**`pkill` nie był użyty ani razu przez nikogo.** Cudzych procesów nie przerywano.

---

## 8. Blokady i decyzje właściciela — z dokładną następną czynnością

Kolejność jest ułożona według skutku, nie według wygody.

| # | Rzecz | Dokładnie co zrobić | Co to zmienia |
|---|---|---|---|
| 1 | **Kanał alarmowy nie ma odbiorcy** | Założyć webhook (Discord „Slack-Compatible Webhook" albo Slack), wpisać jako `LOG_BLAD_WEBHOOK_URL` w panelu Railway, **zrestartować usługę** (konfiguracja zapieka się przy starcie kontenera), potem `railway ssh -- php artisan kuking:sprawdz-alarm` | Zamyka warstwy 2 i 4. **Dziś nie dojdzie żaden alarm** — ani błąd 500, ani czujka kopii, ani budżet połączeń, ani kolejka |
| 2 | **Zero kopii produkcyjnej bazy** | Cztery czynności z `KOPIE_I_ODTWORZENIE.md` §7.3: bucket R2, dwa tokeny, zmienne, serwis z cronem | **Tylko to** zmienia liczbę kopii z zera. Kod jest gotowy i po raz pierwszy przejechany w kontenerze |
| 3 | **Sprzeczność o planie Railway** | Panel → serwis `Postgres` → zakładka **Backups**; zapisać z datą, czy są harmonogramy, czy komunikat o Pro | Jedno kliknięcie; rozstrzyga §5.3 wobec D-043. Jeśli wymaga Pro — decyzja o **+15 USD/mies.** należy do właściciela |
| 4 | **Jurysdykcja R2 nieznana** | Panel Cloudflare → wypełnić tabelę `LOKALIZACJA_DANYCH_R2.md` §5: 7 bucketów × typ lokalizacji, wartość, Bucket Lock, `r2.dev`, własna domena, **data odczytu** | Zamyka #619/#120. **Polityki prywatności nie wolno opierać na samym Location Hint** |
| 5 | **`LOG_LEVEL` nieznany** | Odczytać wartość w panelu | Jeśli jest wyżej niż `info`, szereg czasowy z punktu 3 **nie powstanie** mimo poprawnego kodu |
| 6 | **#617 — rygiel na zdjęcia** | Najpierw rozstrzygnąć z #8 zdanie do polityki o oknie 30 dni, **potem** osobny bucket kopii z ryglem **czasowym** (30 dni) | **Nigdy rygiel na żywym buckecie oryginałów** — wspólny prefiks `incoming/` uniemożliwiłby wykonanie żądania usunięcia danych |
| 7 | **Zewnętrzny monitoring dostępności** | Wskazać usługę i adres | #599 — czynność właściciela, nie kodu. Dziś nie ma trzeciego niezależnego świadka |
| 8 | **Historyczne `failed_jobs`** | Rozpoznać rodzaj i skutki **przed** jakimkolwiek ponowieniem | Zbiorcze ponowienie może wysłać **wygasłe linki albo nieaktualne wiadomości** do prawdziwych ludzi. Nie kasować ich po to, żeby health stał się zielony |

---

## 9. Czego ta sesja nie rozstrzygnęła

- **Czy alarm dochodzi na produkcji.** Nie dochodzi, bo nie ma odbiorcy. Kod
  i komenda są gotowe; to jest brak konfiguracji, nie brak kodu.
- **Czy szereg czasowy powstanie.** Zależy od `LOG_LEVEL`, którego nie da się
  odczytać z tej sesji.
- **Nic o Cloudflare.** MinIO mówi tym samym protokołem S3, ale nie ma ani
  jurysdykcji, ani `r2.dev`, ani Bucket Locks.
- **Punkt nasycenia aplikacji.** Przyrząd gotowy, okna ciszy nie było.
- **Uwierzytelnianie hasłem w ścieżce kopii.** Lokalny klaster stoi na `trust`;
  przebieg dowodzi tylko, że hasło nie wychodzi w argumentach procesu.

---

## 10. Jedna rzecz o metodzie, warta zapamiętania

**Kontrola ujemna, która przeszła, jest pytaniem, a nie formalnością.** 17 IX
dwa sabotaże nie zaczerwieniły testów — i przez chwilę wyglądało to jak dobra
wiadomość. Prawdziwy powód był taki, że sabotaż **nie ruszał reguły, której
test pilnował**. Poprawione i powtórzone. Dlatego przy każdej z siedmiu kontroli
w tej sesji zapisane jest, **ile dokładnie asercji** się zaczerwieniło.

Druga rzecz tej samej rodziny: **atrapa potrafi milczeć tam, gdzie prawdziwy
odbiornik krzyczy.** Podwojony nagłówek `[Kuking/local]` przeszedł przez
`Http::fake` z asercją „treść zawiera" i wyszedł dopiero na prawdziwym
odbiorniku HTTP.
