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

Buckety do objęcia:

1. oryginały zdjęć (`AWS_BUCKET`) — leży tam pełny EXIF, czyli GPS kuchni,
2. warianty zdjęć (`AWS_PUBLIC_BUCKET`),
3. paczki RODO (`AWS_EXPORTS_BUCKET`) — kopia całego konta człowieka,
4. stary, jeden bucket (`AWS_LEGACY_BUCKET`), jeśli nadal istnieje,
5. bucket kopii bazy z #193 (dziś na produkcji nieskonfigurowany),
6. przyszła kwarantanna z #602.

Plus jedna rzecz spoza panelu: **kształt `AWS_ENDPOINT`** — sam segment
jurysdykcji albo jego brak, **bez identyfikatora konta**.

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

## 7. Powiązane

- #120 — bramka bezpieczeństwa R2 (`docs/infra/BRAMKA_R2.md`); sprawdzenie 12
  chodzi w tej samej komendzie,
- #8 — przegląd prawny i prawdziwość polityki prywatności,
- #193 — kopia bazy do R2 (osobny bucket, też do sprawdzenia),
- #602 — przyszła kwarantanna,
- #617 — ochrona obiektów R2 przed logicznym usunięciem,
- `docs/infra/INFRA_DECISION.md` — dlaczego w ogóle R2.
