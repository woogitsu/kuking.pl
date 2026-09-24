# Gdzie naprawdę leżą zdjęcia — lokalizacja danych w Cloudflare R2

**Zgłoszenie:** issue #619 (P0, `obszar: infra`), audyt spójności 16.09.2026.
**Ostatnia aktualizacja tego pliku:** 2026-09-17.
**Stan:** dowód maszynowy DOŁOŻONY do bramki (§3) · odczyt panelu **NIEWYKONANY**
— brak dostępu do konta Cloudflare (§5).

> **Twarda zasada tego dokumentu:** `resources/legal/polityka-prywatnosci.md`
> mówi użytkownikowi, że zdjęcia leżą w Unii Europejskiej. To jest obietnica
> złożona człowiekowi, nie komentarz w konfiguracji. Dopóki nie ma dowodu,
> **nie wolno jej ani powtarzać jako faktu, ani poprawiać „na oko"** — bo
> jedno i drugie zamienia brak wiedzy w zdanie twierdzące.

---

## 1. Dwie rzeczy, które nazywają się podobnie i znaczą co innego

To jest cała treść issue #619 i najłatwiejsza rzecz do pomylenia w całym
temacie. Źródło: dokumentacja Cloudflare **„Data location"**
(<https://developers.cloudflare.com/r2/reference/data-location/>), odczytana
z tego środowiska **17.09.2026**.

| | **Location Hint** | **Jurisdictional Restriction** |
|---|---|---|
| Wartości | `wnam`, `enam`, `weur`, `eeur`, `apac`, `oc` | `eu`, `us`, `fedramp` |
| Co obiecuje Cloudflare | „a best effort and not a guarantee" — narzędzie do **optymalizacji opóźnień** | „guarantee objects in a bucket are stored within a specific jurisdiction" |
| Po co istnieje | żeby dane leżały bliżej użytkowników | żeby spełnić wymóg rezydencji (Cloudflare wymienia wprost RODO) |
| Widać to z endpointu? | **NIE** | **TAK** — endpoint ma wtedy inny kształt (§2) |
| Da się zmienić po utworzeniu? | hint jest honorowany tylko przy pierwszym utworzeniu bucketu o danej nazwie | **NIE. Nigdy.** |

**`weur` ani `eeur` nie są dowodem rezydencji w UE.** Deklaracja w repozytorium
też nie — repozytorium nie ma dostępu do panelu Cloudflare i nie ma jak
sprawdzić, co komu ustawiono.

**Trzeci możliwy stan to „Automatic"** — domyślny przy tworzeniu bucketu.
Cloudflare wybiera wtedy region najbliższy temu, kto wysłał żądanie utworzenia.
To nie jest ani hint, ani jurysdykcja.

---

## 2. Co się da udowodnić Z SERWERA, bez panelu — i dlaczego to działa

Dokumentacja Cloudflare mówi dwie rzeczy, które razem dają dowód:

1. **Bucket z jurysdykcją jest osiągalny WYŁĄCZNIE przez endpoint
   jurysdykcyjny:**

   ```text
   https://<KONTO>.<JURYSDYKCJA>.r2.cloudflarestorage.com
   ```

   Cytat z dokumentacji: *„When interacting with R2 resources that belong to
   a defined jurisdiction with the S3 API […] you must specify the jurisdiction
   in your S3 endpoint"* oraz *„When using a jurisdiction endpoint, you will
   not be able to access R2 resources outside of that jurisdiction."*

2. **Jurysdykcji istniejącego bucketu nie da się zmienić.**

Zwykły endpoint konta wygląda inaczej — bez środkowego segmentu:

```text
https://<KONTO>.r2.cloudflarestorage.com
```

Stąd wniosek, i to wniosek w obie strony:

- endpoint **z** segmentem `eu` ⇒ wszystko, po co aplikacja przez niego sięga,
  jest w jurysdykcji UE;
- endpoint **bez** segmentu jurysdykcji, przez który aplikacja **naprawdę
  odczytała obiekt**, ⇒ te buckety jurysdykcji **nie mają**. To nie jest „nie
  wiemy" — to jest „wiemy, że nie", bo gdyby miały, tym endpointem nie dałoby
  się ich dotknąć.

Ta druga strona jest tu najważniejsza: **odczyt musi się udać.** Sama nazwa
hosta w konfiguracji mówi tylko, co ktoś wpisał. Dlatego sprawdzenie w bramce
(§3) orzeka „wiemy, że nie" **tylko wtedy**, gdy w tym samym przebiegu
sprawdzenie 1 (oryginał widoczny przez API S3) się powiodło. Bez tego mówi
`NIE WIEMY`.

### Czego ten dowód NIE daje

- **Nie mówi, w którym kraju stoi dysk.** Jurysdykcja `eu` to zbiór krajów,
  nie adres serwerowni.
- **Nie widzi Location Hintu ani trybu „Automatic".** Z endpointu ich nie
  widać i widać ich nie będzie.
- **Nie widzi bucketów, po które ta aplikacja nie sięga** — bucketu kopii bazy
  (#193) i przyszłej kwarantanny (#602). Te trzeba przeczytać w panelu.
- **Nie zastępuje odczytu panelu.** Mówi, czy jurysdykcja JEST, a nie co
  właściciel widzi przy każdym buckecie z osobna.

### Region Railway to NIE JEST lokalizacja zdjęć

`europe-west4-drams3a` — tak, to jest region UE (Holandia), i tak, stoją tam
**aplikacja i PostgreSQL**. Ze zdjęciami nie ma to nic wspólnego: zdjęcia leżą
w Cloudflare R2, u innego dostawcy, pod inną konfiguracją. Te dwie rzeczy
trzeba w dokumentach i w zgłoszeniach trzymać osobno, bo pomylenie ich daje
zdanie prawdziwe o bazie i fałszywe o zdjęciach.

---

## 3. Sprawdzenie 12 w `kuking:bramka-r2`

Od 17.09.2026 komenda z `docs/infra/BRAMKA_R2.md` ma dodatkowe sprawdzenie:

```
railway ssh -- php artisan kuking:bramka-r2 --zapis
```

| Co widzi komenda | Werdykt | Co to znaczy |
|---|---|---|
| endpoint `<konto>.eu.r2.cloudflarestorage.com` | **TAK** | buckety, po które aplikacja sięga, są w jurysdykcji UE |
| endpoint `<konto>.us.…` albo inna jurysdykcja | **NIE** | polityka obiecuje UE, a dane są gdzie indziej |
| endpoint bez jurysdykcji **i** udany odczyt obiektu | **NIE** | te buckety nie mają ograniczenia jurysdykcyjnego |
| endpoint bez jurysdykcji, **bez** udanego odczytu | **NIE WIEMY** | nazwa hosta to nie dowód; `NIE WIEMY` oblewa bramkę |
| endpoint o nieznanym kształcie | **NIE WIEMY** | trzeba przeczytać panel |

**Na ekran idzie sam segment jurysdykcji, nigdy host.** Host endpointu jest
jedynym miejscem w konfiguracji, w którym stoi identyfikator konta Cloudflare,
a wyjście tej komendy wkleja się do zgłoszeń na GitHubie. Pilnuje tego
`BramkaR2MowiPrawdeTest::test_sprawdzenie_jurysdykcji_nie_wypisuje_identyfikatora_konta`.

**Oczekiwana jurysdykcja jest stałą w kodzie, nie zmienną środowiskową.** To
jest świadome: obietnicę składa `resources/legal/polityka-prywatnosci.md`
(wiersz „Cloudflare R2 · Przechowywanie zdjęć · Unia Europejska"), więc
wariant **B** z issue #619 — zostawiamy architekturę, poprawiamy opis prawny —
jest zmianą **dwóch plików naraz**: polityki i stałej `JURYSDYKCJA_Z_POLITYKI`
w `app/Console/Commands/BramkaR2.php`. Gdyby wartość siedziała w zmiennej
środowiskowej, dałoby się uciszyć to sprawdzenie bez tknięcia dokumentu,
który kłamie.

---

## 3a. Strażnik hosta w aplikacji (D-255, 24.09.2026)

Od decyzji właściciela z 24.09.2026 aplikacja **sama odmawia** pracy z endpointem
bez jurysdykcji UE. `App\Support\Storage\DozwolonyHostR2` dopuszcza wyłącznie
`https://<32 znaki hex>.eu.r2.cloudflarestorage.com` (bez portu, ścieżki,
danych logowania) dla każdego dysku R2/S3 — `r2`, `r2_publiczne`, `r2_legacy`,
`r2_eksporty`, `r2_kopie`, `s3`. Zły adres → dysk się nie buduje, a `/health`
pokazuje `checks.magazyn.error = magazyn_r2_zly_host`.

To domyka wariant **A** z §6 po stronie kodu: wariant **B** (zostawić buckety
bez jurysdykcji) wymaga od teraz zmiany D-255 i `WZOR_HOSTA`, nie samej
polityki. Serwis `kopia-bazy` (skrypt powłoki, `KOPIA_S3_ENDPOINT`) nie
przechodzi przez PHP i tym strażnikiem **nie** jest objęty.

**Przed wdrożeniem** sprawdź w panelu R2, że każdy bucket (oryginały, warianty,
eksporty, kopie) ma „Jurisdiction: European Union”, i że `R2_ENDPOINT` na
Railway ma segment `.eu.`. Endpoint `eu` nie widzi bucketów bez jurysdykcji —
aplikacja by się zbudowała, ale każdy odczyt dostałby `NoSuchBucket`.

## 4. Co wiadomo na 17.09.2026 — i skąd

| Ustalenie | Źródło | Data |
|---|---|---|
| Location Hint jest „best effort", jurysdykcja jest gwarancją; endpoint jurysdykcyjny ma segment `<JURYSDYKCJA>`; jurysdykcji nie da się zmienić | dokumentacja Cloudflare „Data location", odczytana przeglądarką z tego środowiska | 2026-09-17 |
| Panel Cloudflare **niedostępny**: `dash.cloudflare.com` przekierowuje na `/login`, sesji nie ma | próba wykonana w tej sesji | 2026-09-17 |
| W repozytorium **nie ma ani jednego** wystąpienia `jurisdiction`, `location hint`, `weur` ani `eeur` | `grep -rn` po całym drzewie poza `vendor/` | 2026-09-17 |
| `.railway/railway.ts` opisuje kształt endpointu komentarzem `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` — **bez segmentu jurysdykcji** | odczyt kodu, linia z `AWS_ENDPOINT` | 2026-09-17 |
| Produkcja ma zmienne `AWS_ENDPOINT`, `AWS_BUCKET`, `AWS_PUBLIC_BUCKET`, `AWS_EXPORTS_BUCKET` | Railway MCP, usługa `kuking.pl`, środowisko `production` | 2026-09-17 |
| **Wartości tych zmiennych są niedostępne** (`valuesRedacted`), więc kształtu produkcyjnego endpointu tą drogą przeczytać się NIE DA | Railway MCP, połączenie OAuth zwraca same nazwy | 2026-09-17 |
| `KUKING_R2_PUBLICZNE_ADRESY` **nie istnieje** na produkcji, więc bramka #120 daje tam dziś `NIE WIEMY` i oblewa | Railway MCP, lista nazw zmiennych | 2026-09-17 |

**Komentarz w `railway.ts` jest poszlaką, nie dowodem.** Mówi, co ktoś
zamierzał wpisać, a nie co w tej zmiennej dziś stoi. Rozstrzyga dopiero
sprawdzenie 12 uruchomione na produkcji (§3).

---

## 5. Czego dziś zrobić się NIE DA i czego do tego trzeba

| Brakuje | Po co | Kto może dać |
|---|---|---|
| zalogowana sesja albo token API Cloudflare z prawem odczytu R2 | odczytać przy KAŻDYM buckecie: typ lokalizacji (Automatic / Location Hint / Jurisdiction), a jeśli jurysdykcja — czy dokładnie `eu` | właściciel |
| wartość `AWS_ENDPOINT` z produkcji **albo** jeden przebieg `kuking:bramka-r2` na produkcji | rozstrzygnąć kształt endpointu bez panelu | właściciel (`railway ssh`) |

**Nie zakładam konta i nie wpisuję haseł.** Brak dostępu jest ograniczeniem,
a nie wynikiem pozytywnym.

### Lista do odczytania w panelu — dokładnie to i nic więcej

Panel R2 → każdy bucket → zakładka **Settings**, karta z lokalizacją. Dla
każdego z bucketów wypisać cztery rzeczy: **nazwę**, **typ lokalizacji**,
**wartość** (region hintu albo kod jurysdykcji) i **datę odczytu**.

**Tabela jest PUSTA i to jest cały jej sens.** Wiersz bez daty nie mówi nic
o dzisiejszym stanie (ta sama zasada, co w `BRAMKA_R2.md` §3). Wypełnia ją
**właściciel**, po zalogowaniu do panelu.

| # | Bucket | Co odczytać | Typ lokalizacji<br>(Automatic / Hint / Jurisdiction) | Wartość<br>(region hintu albo kod jurysdykcji) | Bucket Lock<br>(jest / nie ma / zakres) | `r2.dev` | Własna domena | Data odczytu | Kto |
|---|---|---|---|---|---|---|---|---|---|
| 1 | oryginały zdjęć (`AWS_BUCKET`) — leży tam pełny EXIF, czyli GPS kuchni | lokalizacja + publiczność | | | | | | | |
| 2 | warianty zdjęć (`AWS_PUBLIC_BUCKET`) | lokalizacja + publiczność | | | | | | | |
| 3 | paczki RODO (`AWS_EXPORTS_BUCKET`) — kopia całego konta człowieka | lokalizacja + publiczność | | | | | | | |
| 4 | stary, jeden bucket (`AWS_LEGACY_BUCKET`), jeśli nadal istnieje | lokalizacja + publiczność | | | | | | | |
| 5 | bucket kopii bazy z #193 — dziś na produkcji **nieskonfigurowany** | lokalizacja + publiczność | | | | | | | |
| 6 | przyszła kwarantanna z #602 | lokalizacja + publiczność | | | | | | | |
| 7 | bucket kopii zdjęć z #617 — **jeszcze nie istnieje**, §6a | lokalizacja + rygiel | | | | | | | |

Plus trzy rzeczy spoza tej tabeli, też z datą:

| Co odczytać | Gdzie | Wartość | Data odczytu |
|---|---|---|---|
| **kształt `AWS_ENDPOINT`** — sam segment jurysdykcji albo jego brak, **BEZ identyfikatora konta** | Railway → `kuking.pl` → Variables (albo jeden przebieg `kuking:bramka-r2`) | | |
| czy `KUKING_R2_PUBLICZNE_ADRESY` jest ustawione (bez niego bramka #120 daje `NIE WIEMY` w punktach 7 i 8) | Railway → `kuking.pl` → Variables | | |
| zakładka **Backups** przy serwisie `Postgres`: harmonogramy do wyboru czy komunikat o planie Pro (`KOPIE_I_ODTWORZENIE.md` §5.3) | Railway → `Postgres` → Backups | | |

**Nie wpisuj tu identyfikatora konta Cloudflare ani nazw tokenów.** Ten plik
jest w repozytorium, a wiersze z tej tabeli trafiają do zgłoszeń na GitHubie.

---

## 6. Jeśli okaże się, że jurysdykcji nie ma

Issue #619 stawia dwa warianty i **oba są decyzją właściciela**, nie
programisty. Tu jest tylko to, czego każdy z nich wymaga.

### Wariant A — nowe buckety `eu` i migracja

Jurysdykcji istniejącego bucketu **nie da się zmienić**, więc to jest:
utworzenie nowych bucketów pod endpointem `…eu.r2.cloudflarestorage.com`,
przepisanie wszystkich obiektów, przestawienie `AWS_ENDPOINT` i nazw bucketów,
przepisanie kolumn `media.disk` i `media.variants_disk`, a potem skasowanie
starych bucketów. Operacja **kosztowna i częściowo nieodwracalna** — nie
wykonuje się jej przy okazji innego zadania. Rollback: stare buckety zostają
nietknięte do czasu odbioru, a powrót to cofnięcie zmiennych.

### Wariant B — zostaje jak jest, poprawiamy opis prawny

Wymaga zmiany **dwóch rzeczy naraz** (§3): tekstu w
`resources/legal/polityka-prywatnosci.md` i stałej `JURYSDYKCJA_Z_POLITYKI`.
Tekst rozstrzyga się z #8, nie samodzielnie: trzeba napisać, gdzie dane
faktycznie są i na jakiej podstawie, zamiast obiecywać UE.

**Czego nie wolno w żadnym z wariantów:** poprawić polityki bez ustalenia
faktów albo zostawić w niej „UE" z komentarzem, że pewnie tak jest.

---

## 6a. #617 — ochrona przed logicznym usunięciem. `[REKOMENDACJA — NIE WYKONANA]`

**Stan na 18 IX 2026: nic nie zostało założone ani zmienione.** To jest
procedura gotowa do wykonania, nie jej wykonanie. Panel Cloudflare jest poza
zasięgiem tej sesji (§5), a nawet gdyby nie był — ta decyzja ma skutki prawne
i należy do właściciela.

### Dlaczego NIE WOLNO zaryglować żywego bucketu oryginałów

Odczyt dokumentacji Cloudflare **„Bucket locks"** (18 IX 2026):

- rygiel „prevent the deletion and overwriting of objects […] for a specified
  period — or indefinitely";
- regułę da się zawęzić **prefiksem**, a reguła bez prefiksu obejmuje cały
  bucket; do 1000 reguł na bucket;
- warunek: wiek w sekundach, konkretna data albo bezterminowo;
- gdy kilka reguł dotyczy tego samego klucza, **wygrywa najostrzejsza**;
- rygiel **nie kasuje niczego** — to jest wyłącznie zakaz usuwania
  i nadpisywania. Usuwaniem zajmuje się osobny mechanizm (lifecycle, §6a
  krok 2a niżej);
- reguły rygla **da się zdjąć**: dokumentacja ma na to własny rozdział
  („Remove bucket lock rules from your R2 bucket") i trzy drogi — panel,
  Wrangler (`r2 bucket lock remove`) i API. Warunek jest jeden:
  „An API token with permissions to edit R2 bucket configuration".

> **SPROSTOWANIE Z 18 IX 2026.** Do tego dnia stało w tym miejscu zdanie,
> że rygiel jest **nieodwracalny**, bo „reguły rygla z założenia nie dają
> się poluzować". To jest **niezgodne z dokumentacją Cloudflare** —
> reguła jest zwykłym elementem konfiguracji bucketu i zdejmuje się ją
> panelem, Wranglerem albo API. Zdanie brało się z pomylenia R2 Bucket
> Locks z **trybem compliance S3 Object Lock w innym produkcie**, gdzie
> okres retencji faktycznie nie daje się skrócić. **To nie jest ten sam
> mechanizm i nie wolno ich utożsamiać.** Reszta tej sekcji była budowana
> na tamtym błędnym założeniu, więc uzasadnienie i model zagrożeń są niżej
> napisane od nowa.

I tu jest sedno problemu, które zamyka drogę „po prostu włączmy rygiel":
**wszystkie oryginały leżą pod jednym prefiksem `incoming/`**, a prefiks nie
niesie informacji o właścicielu zdjęcia. Rygiel na `incoming/` obejmie więc
tak samo zdjęcia, których nikt nie zgłosił do usunięcia, jak i te, o których
usunięcie ktoś **właśnie poprosił** — a `EraseAccountData` i procedura RODO
muszą móc je skasować.

**Model zagrożeń, poprawiony.** Rygiel nie jest ścianą, tylko **dodatkowym
uprawnieniem po drugiej stronie**:

- **czego broni:** tokenu aplikacji i tokenu procesu kopiującego. Te tokeny
  mają prawo do OBIEKTÓW, a nie do konfiguracji bucketu, więc przejęta
  aplikacja nie skasuje zaryglowanych zdjęć i nie zdejmie reguły. To jest
  prawdziwy zysk i to jest cała treść #617;
- **przed czym NIE broni:** przed kimś, kto ma prawo edytować konfigurację
  bucketu R2 — czyli przed właścicielem konta Cloudflare i przed każdym
  tokenem z tym uprawnieniem. Taki ktoś zdejmuje regułę i kasuje. Rygiel
  **nie jest** więc zabezpieczeniem przed złośliwym administratorem ani
  dowodem niezmienności dla audytu;
- **co z tego wynika dla RODO:** rygiel na `incoming/` nie jest
  nieodwracalnym zobowiązaniem, ale **wyprowadza skasowanie danych poza
  automat** (`EraseAccountData`) i przenosi je do ręcznej czynności
  uprzywilejowanej: zdejmij regułę, skasuj, załóż regułę z powrotem.
  Procedura, która przy każdym żądaniu usunięcia wymaga wejścia
  z uprawnieniem do konfiguracji bucketu, jest procedurą, która kiedyś
  nie zostanie wykonana. **Dlatego nadal: nie na buckecie oryginałów** —
  ale z tego powodu, a nie z powodu „nieodwracalności".

> **Zasada, którą trzeba tu utrzymać (z samego #617):** retencja techniczna
> to co innego niż aktywne dane użytkownika. Kopia wolno, żeby przeżyła
> żądanie usunięcia o **ograniczony, zapisany** czas. Nie wolno, żeby
> wracała do produktu i żeby trwała bezterminowo.

### Co rekomendować zamiast tego — wariant B z #617, w trzech krokach

**Krok 1. Osobny bucket kopii, nie rygiel na produkcyjnym.**

- nowy bucket, np. `kuking-zdjecia-kopia`, **ta sama jurysdykcja** co
  oryginały (inaczej kopia rozjeżdża się z polityką prywatności — §2),
- **bez własnej domeny, `r2.dev` WYŁĄCZONE.** Ten bucket nie jest serwowany
  nikomu, nigdy. To jest ta sama zasada, co przy buckecie kopii bazy
  (`KOPIE_I_ODTWORZENIE.md` §7.3 krok 1),
- **osobny token**, i to dwa: zapisu — wyłącznie dla procesu kopiującego,
  odczytu — dla ewentualnego odtworzenia. **Aplikacja nie dostaje żadnego
  z nich.** Gdyby dostała prawo zapisu, udany atak na nią kasowałby razem
  z oryginałami także ich kopie, czyli dokładnie to, przed czym ta warstwa
  ma chronić.

**Krok 2. Rygiel — ale TYLKO na buckecie kopii i TYLKO na czas.**

- jedna reguła, warunek **wieku**, `MaxAgeSeconds = 2592000` (30 dni),
- **nigdy `indefinite`, nigdy data odległa.** Bezterminowy rygiel nie jest
  wprawdzie nieodwracalny (sprostowanie wyżej), ale zamienia każde usunięcie
  danych osobowych w ręczną czynność uprzywilejowaną — a takiej procedury
  nikt nie wykona rok później,
- 30 dni to ta sama liczba, co retencja kopii bazy (`KOPIA_RETENCJA_DNI`),
  i to jest celowe: jedno okno do zapamiętania, jedno do wytłumaczenia
  w rejestrze czynności przetwarzania.

> **RYGIEL NIE KASUJE. SPROSTOWANIE Z 18 IX 2026.**
> Do tego dnia ta sekcja obiecywała, że kopia „zostaje maksymalnie 30 dni,
> po czym **wygasa sama**". **Nieprawda.** `MaxAgeSeconds = 2592000` znaczy
> wyłącznie tyle, że przez 30 dni obiektu **nie da się** usunąć ani nadpisać.
> Po tych 30 dniach obiekt **nadal leży w buckecie** — zmienia się tylko to,
> że od tej chwili wolno go skasować. Dokumentacja Cloudflare mówi o ryglu
> jedno zdanie i nie ma w nim słowa o kasowaniu: *„Bucket locks prevent the
> deletion and overwriting of objects in an R2 bucket for a specified
> period — or indefinitely."*
>
> To są **trzy różne rzeczy** i dotąd były w tym dokumencie zlepione w jedną:
>
> | | Co to jest | Czym się to robi | Czego dowodzi |
> |---|---|---|---|
> | **(a) minimalna ochrona przed usunięciem** | „przez 30 dni nikt tego nie skasuje" | Bucket Lock, `MaxAgeSeconds` | że kopia przetrwa pomyłkę i przejęty token aplikacji |
> | **(b) polityka usuwania kopii** | „po 30 dniach tego ma nie być" | **osobna reguła lifecycle** (Object lifecycles), „delete objects" | że istnieje koniec retencji — sam rygiel go NIE daje |
> | **(c) rzeczywisty pomiar** | „sprawdziliśmy, że po 30 dniach naprawdę zniknęło" | kontrolny obiekt własny + `HEAD` po terminie | że (b) faktycznie zadziałało na tym koncie |
>
> **Wykonanie kroków 1–3 w obecnej postaci zostawia kopie BEZ automatycznego
> końca retencji.** Będzie rygiel, nie będzie usuwania, a zdjęcia osób, które
> poprosiły o usunięcie konta, będą leżeć w buckecie kopii bezterminowo —
> czyli dokładnie odwrotnie do zasady z ramki wyżej. Dlatego doszedł krok 2a.

**Krok 2a. Reguła lifecycle — to ona, i tylko ona, kasuje kopie.**

- na TYM SAMYM buckecie kopii: reguła *Object lifecycles* typu „delete
  objects" po **31 dniach** od zapisu. Nie 30: reguła lifecycle nie może
  próbować kasować obiektu wcześniej, niż pozwala rygiel, bo wtedy nie
  skasuje nic. Dokumentacja Cloudflare mówi o tym wprost — *„Bucket lock
  rules take precedence over lifecycle rules. For example, if a lifecycle
  rule attempts to delete an object at 30 days but a bucket lock rule
  requires it be retained for 90 days, the object will not be deleted until
  the 90-day requirement is met."*;
- **usuwanie jest ASYNCHRONICZNE i nie ma twardego terminu.** Dokumentacja:
  *„Objects will typically be removed from a bucket within 24 hours of the
  `x-amz-expiration` value."* — czyli „31 dni" znaczy w praktyce „31 dni
  plus zwykle do doby", i tak to trzeba zapisać w polityce prywatności,
  a nie jako datę co do godziny;
- **jedna uwaga operacyjna z tej samej dokumentacji:** *„A bucket cannot be
  emptied while any bucket lock rules are configured. Remove all lock rules
  before emptying a bucket."* Bucket kopii nie da się więc „wyczyścić
  jednym kliknięciem" dopóki rygiel stoi — i to jest cecha, nie usterka.

**Krok 3. Pogodzenie z prawem do usunięcia — zapisane, zanim się włączy.**

| Co się dzieje | Produkcja | Bucket kopii |
|---|---|---|
| użytkownik prosi o usunięcie konta | obiekty znikają **natychmiast**, dotychczasową drogą (`EraseAccountData`) | kopia zostaje objęta ryglem przez 30 dni, a **kasuje ją reguła lifecycle z kroku 2a** — po 31 dniach od zapisu, zwykle w ciągu doby od tego terminu |
| ktoś odtwarza z kopii | — | odtwarza się **wyłącznie** obiekty, których nie objęło żądanie usunięcia; listę bierze się z bazy, nie z bucketu |
| ktoś pyta, gdzie są jego dane | odpowiedź obejmuje kopię i jej okno 30 dni | — |

Tego ostatniego wiersza nie da się załatwić kodem: **to jest zdanie do
dopisania w polityce prywatności i rozstrzygnięcia razem z #8**, dokładnie
tak jak wariant B z §6. Bez niego rygiel nie ma prawa powstać.

**Bez kroku (c) nic z powyższego nie jest zamknięte.** Reguła lifecycle
w panelu to zamiar, nie skutek: dopóki nikt nie położył własnego,
kontrolnego obiektu i nie sprawdził `HEAD`-em po terminie, że naprawdę
zniknął, w dokumencie wolno napisać wyłącznie „reguła jest założona",
a nie „kopie wygasają". Ten pomiar należy do listy z „Obowiązkowa próba
odtworzenia" niżej.

### Koszt — wg cennika R2 z 18 IX 2026

Standard storage **0,015 USD / GB-miesiąc**, egress **darmowy**, klasa A
(zapis/lista) **4,50 USD / mln żądań**, klasa B (odczyt) **0,36 USD / mln**.
Darmowy próg miesięczny: **10 GB-miesiąc**, 1 mln żądań klasy A, 10 mln klasy B.

Dopóki komplet zdjęć mieści się w kilku GB, **kopia mieści się w darmowym
progu** albo kosztuje grosze; kopiowanie raz na dobę to żądania klasy A liczone
w tysiącach, nie milionach. **Koszt nie jest tu argumentem za odkładaniem
decyzji** — czasem jest, tu nie jest.

### Obowiązkowa próba odtworzenia (definicja gotowości #617)

Na **koncie testowym albo na kilku kontrolnych obiektach własnych**, nigdy na
cudzych zdjęciach: skasować obiekt finalny, odtworzyć go z bucketu kopii,
porównać `sha256` i rozmiar, sprawdzić, że wariant i wpis znów się pokazują,
zapisać RPO (odstęp kopiowania) i RTO (czas odtworzenia). Dopiero ten wiersz
zamyka #617 — tak samo jak tabela w `KOPIE_I_ODTWORZENIE.md` §5 zamyka #193.

Do tej samej listy należą **dwa pomiary rygla i lifecycle**, bo bez nich
kroki 2 i 2a są zamiarem, a nie stanem:

1. **Rygiel trzyma:** położyć własny obiekt kontrolny, spróbować go skasować
   tokenem procesu kopiującego i zapisać, że `DELETE` został odrzucony.
   Próba usunięcia, która się udała, znaczy, że reguła nie obejmuje tego
   prefiksu — a wygląda to identycznie jak „reguła jest".
2. **Lifecycle kasuje:** ten sam obiekt kontrolny sprawdzić `HEAD`-em po
   terminie z kroku 2a i zapisać DATĘ oraz GODZINĘ, o której naprawdę
   zniknął. Usuwanie jest asynchroniczne (dokumentacja: zwykle do 24 h), więc
   jeden `HEAD` dokładnie w terminie niczego nie rozstrzyga — potrzebny jest
   pomiar do skutku. Dopóki go nie ma, w tym dokumencie i w polityce
   prywatności wolno napisać „reguła jest założona", a nie „kopie wygasają".

### Czego świadomie NIE rekomendujemy

- **Rygla na buckecie oryginałów** — powód wyżej;
- **wersjonowania obiektów zamiast kopii** — R2 trzyma wtedy stare wersje
  w TYM SAMYM buckecie i pod tymi samymi poświadczeniami, więc token z prawem
  zapisu nadal je dosięga. To nie chroni przed scenariuszem z #617;
- **nazywania trwałości R2 kopią zapasową.** Jedenaście dziewiątek dotyczy
  awarii nośnika, a nie poprawnie wykonanego `DELETE` (wariant C z #617 wolno
  wybrać, ale trzeba go wtedy **nazwać** akceptacją ryzyka, z datą powrotu);
- **nazywania R2 Bucket Locks trybem compliance ani „niezmiennością".**
  Reguła rygla jest elementem konfiguracji bucketu i zdejmuje ją panel,
  Wrangler albo API — patrz sprostowanie w „Dlaczego NIE WOLNO zaryglować
  żywego bucketu oryginałów". Rygiel broni przed tokenem aplikacji, a nie
  przed właścicielem konta; obiecywanie tego drugiego audytowi albo
  w polityce prywatności byłoby obietnicą bez pokrycia;
- **traktowania rygla jako końca retencji.** Rygiel nie kasuje. Koniec
  retencji daje wyłącznie reguła lifecycle z kroku 2a — i dopiero jej
  zmierzone zadziałanie.

---

## 7. Powiązane

- #120 — bramka bezpieczeństwa R2 (`docs/infra/BRAMKA_R2.md`); sprawdzenie 12
  chodzi w tej samej komendzie,
- #8 — przegląd prawny i prawdziwość polityki prywatności,
- #193 — kopia bazy do R2 (osobny bucket, też do sprawdzenia),
- #602 — przyszła kwarantanna,
- #617 — ochrona obiektów R2 przed logicznym usunięciem,
- `docs/infra/INFRA_DECISION.md` — dlaczego w ogóle R2.
