# Kopie zapasowe i odtworzenie

**Dla kogo:** właściciel, w chwili gdy coś już poszło źle (albo raz, na sucho,
zanim cokolwiek pójdzie źle).
**Cel:** dać komendy i decyzje, nie lekturę. Kontekst i uzasadnienia
architektoniczne zostają w `INFRA_DECISION.md` §9-10 i `DEPLOYMENT_RUNBOOK.md`
KROK 6.4/13/14/15 — **ten dokument jest teraz jedynym miejscem z pełną,
aktualną procedurą** i w razie sprzeczności wygrywa on.

> **Dlaczego ten dokument istnieje.** `docs/ROADMAP.md` stawia w bramce alfy
> warunek „restore przetestowany". Railway prawdopodobnie robi kopie bazy —
> ale **nieprzetestowane odtworzenie nie jest kopią, tylko nadzieją.** Serwis
> zaraz przyjmie pierwszych ludzi; od tej chwili utrata bazy to utrata cudzych
> treści, nie tylko danych testowych.

## Jak czytać ten dokument

| Oznaczenie | Znaczenie |
|---|---|
| 🔴 **BEZPOWROTNA UTRATA** | nie istnieje nigdzie indziej — jak przepadnie, przepadło na zawsze |
| 🟡 **NIEDOGODNOŚĆ** | da się odtworzyć albo wygenerować ponownie, kosztem czasu i/lub przestoju |
| **[DZIŚ]** | obowiązuje TERAZ, zanim powstanie bucket R2 |
| **[PO R2]** | zacznie obowiązywać dopiero, gdy bucket R2 powstanie (`DEPLOYMENT_RUNBOOK.md` KROK 2) — **dziś nieaktualne, bucket nie istnieje** (potwierdzone przez właściciela, 8 września 2026) |
| `[PYTANIE DO WŁAŚCICIELA]` | nie da się rozstrzygnąć z repozytorium — zebrane też w §2.3 |
| ⚠️ nad blokiem komend | komenda może **zapisać albo skasować dane produkcyjne** — blok zaczyna się od sposobu sprawdzenia, że jesteś na właściwym środowisku |

Każda liczba RTO/RPO w tym dokumencie jest **oszacowaniem, nie pomiarem**,
dopóki ćwiczenie z §4 nie zostanie wykonane i wpisane do tabeli w §5.
Tam, gdzie to ma znaczenie, jest to powiedziane wprost drugi raz — bo to jest
dokładnie ta różnica, o którą chodzi w „restore przetestowany".

---

## 1. Co dokładnie trzeba uratować

„Baza" to za mało precyzyjne słowo na potrzeby awarii. Poniżej — z repozytorium,
nie z pamięci — co dokładnie, gdzie leży, i co się stanie, jak zniknie.

### 1.1 🔴 Bezpowrotna utrata — nie istnieje nigdzie indziej

| Co | Gdzie fizycznie | Dlaczego nie da się odtworzyć z gita |
|---|---|---|
| **Baza PostgreSQL** — konta, przepisy, wpisy, komentarze, „Ugotowałem", obserwowanie/blokady, zgłoszenia i decyzje moderacyjne, odwołania, dziennik audytu, powiadomienia, zeszyty, tagi, eksporty RODO, sygnały produktowe — 25 tabel wymienionych w `docs/DATABASE.md` | serwis `postgres`, środowisko `production`, projekt Railway `kuking` | Migracje w `database/migrations/` odtwarzają wyłącznie **pusty schemat**. Zero wierszy. To jest treść, którą ludzie napisali — nie ma jej kopii poza bazą i jej backupami |
| **`APP_KEY` produkcji** | Railway → `production` → Shared Variables (Sealed) + menedżer haseł właściciela | `.env.example` ma tę wartość celowo pustą (`APP_KEY=`). Utrata klucza **nie kasuje bazy fizycznie**, ale czyni nieczytelnym na zawsze wszystko, co nim zaszyfrowano: `users.two_factor_secret` i `users.two_factor_backup_codes` (`app/Models/User.php:248-249`, cast `encrypted`/`encrypted:array`) oraz wszystkie aktywne sesje (`SESSION_ENCRYPT=true` na produkcji, `.railway/railway.ts:205`). Mitygacja dla 2FA: `railway ssh -- php artisan kuking:2fa-wylacz <login>` czyści te kolumny bez potrzeby ich odczytania — ale to działa tylko, jeśli `APP_KEY` wciąż jest ten sam co przy zapisie, czyli **zanim** go stracisz |
| **Zdjęcia — warianty pokazywane użytkownikom** | **[PO R2]** bucket `r2_publiczne` (`AWS_PUBLIC_BUCKET`) · **[DZIŚ]** dysk `local` w kontenerze `web` — patrz ramka niżej | To jest zdjęcie, które ktoś realnie zrobił w swojej kuchni. Bez oryginału (patrz niżej) nie da się go odtworzyć w żadnej postaci |
| **Zdjęcia na koncie `r2_legacy`** (sprzed rozdzielenia bucketów, migracja `kuking:przenies-zdjecia` jeszcze niedokończona — `app/Console/Commands/PrzeniesZdjeciaDoNowychBucketow.php:33-35`) | stary, pojedynczy bucket, wciąż publiczny | Ten bucket jest dziś **jedyną kopią** tych plików — cytat z komentarza w kodzie: „dopóki nie ma pewności, że komplet się przeniósł, stary bucket jest jedyną kopią zapasową". Sprawdź, ile zostało: `Media::where('disk', 'r2_legacy')->count()` |
| **Konfiguracja DNS, WAF i Cache Rules w Cloudflare** | panel Cloudflare, klikane ręcznie (`DEPLOYMENT_RUNBOOK.md` KROK 10) | Brak Infrastructure-as-Code dla Cloudflare w tym repozytorium (tylko Railway ma `.railway/railway.ts`). Kroki są opisane słownie w runbooku, więc odtworzenie jest możliwe, ale ręczne i z przestojem — patrz §1.2 |

> ## ⚠️ Najpilniejsza pozycja z tej tabeli nie jest baza — jest ta ramka
>
> `DEPLOYMENT_RUNBOOK.md` KROK 8 nazywa `FILESYSTEM_DISK=local` wartością
> **tymczasową**, „w porządku na pierwszy zielony deploy i **nie do
> przyjęcia**, gdy wpuszczasz prawdziwych ludzi". Dysk `local` to
> `storage/app/private` **wewnątrz kontenera** — ulotny, kasowany przy
> **każdym** redeployu, restarcie i awarii kontenera.
>
> Bucket R2 nie istnieje jeszcze. Jeśli produkcja ma dziś `FILESYSTEM_DISK=local`
> (do potwierdzenia — patrz §2.3, pytanie 8) **i** przyjmuje już prawdziwe
> zdjęcia od ludzi, to nie jest ryzyko na przyszłość — **zdjęcia już teraz
> giną przy każdym wdrożeniu.** Żadna procedura odtworzenia w tym dokumencie
> tego nie naprawi, bo nie ma z czego odtwarzać. Jedyna naprawa to KROK 2
> `DEPLOYMENT_RUNBOOK.md` (założenie bucketów R2) wykonane **przed**, nie po,
> przyjęciu pierwszych ludzi.

### 1.2 🟡 Niedogodność — odtwarzalne, kosztem czasu

| Co | Gdzie żyje | Jak wraca |
|---|---|---|
| Zdjęcia — **oryginały** z pełnym EXIF/GPS | **[PO R2]** bucket `r2` (`AWS_BUCKET`) | Utrata oryginału nie kasuje wariantu widocznego użytkownikom (osobny bucket) — traci się tylko możliwość przetworzenia zdjęcia na nowo (nowy rozmiar, format) |
| Paczki eksportu RODO (`data_exports`) | **[PO R2]** bucket `r2_eksporty`, TTL 7 dni (`config/kuking.php` → `exports.ttl_days`) | Generowane na żądanie: `POST /ustawienia/twoje-dane/eksport`. Zniknięcie paczki niczego nie kasuje — dane źródłowe są w bazie i mediach |
| Klucze R2, hasło SMTP, `SENTRY_LARAVEL_DSN`, `POSTHOG_KEY`, tokeny Railway/GitHub | Railway Shared Variables (Sealed) + GitHub Actions Secrets + menedżer haseł właściciela | Rotacja jest już opisaną procedurą (`DEPLOYMENT_RUNBOOK.md` §15 „Rotacja kluczy R2" — ta sama ścieżka dla SMTP i tokenów Railway): nowy sekret → podmiana w Railway → redeploy → sprawdzenie → usunięcie starego |
| Konfiguracja DNS/WAF/Cache Rules (z tabeli 1.1) | panel Cloudflare | Odtwarzalne ręcznie wg `DEPLOYMENT_RUNBOOK.md` KROK 10, z przestojem na propagację DNS (do ~15 min z rekordem TXT, dłużej bez niego — patrz „Najczęstszy błąd" w tamtym dokumencie) |

### 1.3 Odtwarzalne w całości z gita

Kod aplikacji, migracje (schemat, nie dane), `.railway/railway.ts`,
konfiguracja, workflowy CI/CD — wszystko w GitHub `woogitsu/kuking.pl` i
w każdym lokalnym klonie. Brak ryzyka: `git clone` odtwarza to w całości
w minutę. To jest dokładnie dlatego, że `railway.ts` nie zawiera ani jednego
sekretu (`INFRA_DECISION.md` §9) — sam plik można stracić bez konsekwencji
dla danych, tylko dla wygody wdrożenia.

---

## 2. Stan faktyczny kopii dzisiaj

### 2.1 Co repozytorium POTWIERDZA

- **Instrukcja włączenia backupów istnieje i jest jednoznaczna.**
  `DEPLOYMENT_RUNBOOK.md` KROK 6.4 każe włączyć Daily + Weekly + PITR
  „**teraz, nie później**" — to jest instrukcja z dnia wdrożenia, **nie dowód**,
  że ktoś rzeczywiście kliknął te przełączniki na `production`.
- **Trzy warstwy są zaplanowane, nie zautomatyzowane w całości.**
  `INFRA_DECISION.md` §10 opisuje Volume Backups (Railway), PITR (Railway)
  i zrzuty logiczne `pg_dump` **offsite** (poza Railway) jako trzecią,
  niezależną warstwę „ostatniej linii obrony".
- **Warstwa offsite nie ma dziś żadnej automatyzacji w kodzie — i literalny
  plan z `INFRA_DECISION.md` jej nie dostanie bez zmiany.** Sprawdzone
  bezpośrednio: `.github/workflows/*.yml` nie zawiera ani `pg_dump`, ani
  żadnego zadania backupowego (`deploy.yml:368` tylko *odsyła* do sekcji
  „Backupy i restore"). A harmonogram aplikacyjny (`routes/console.php`)
  celowo używa `Schedule::call()` zamiast `Schedule::command()` wszędzie,
  bo `docker/php.ini:103` ma `disable_functions=...,proc_open,...`
  (hardening, którego AGENTS.md zabrania osłabiać) — a `pg_dump` uruchomiony
  z PHP wymaga właśnie `proc_open` (klasa `Symfony\Process`). **Zaplanowany
  w `INFRA_DECISION.md` „zrzut na schedulerze" nie da się dziś uruchomić
  wewnątrz kontenera aplikacji bez łamania własnego hardeningu.** To nie jest
  drobiazg do poprawienia przy okazji — to jest powód, dla którego warstwa
  offsite nie istnieje, i decyzja, GDZIE ją uruchomić (osobny, minimalny
  serwis Railway bez `disable_functions`; albo scheduled workflow GitHub
  Actions z `railway`/`psql` na runnerze; albo Railway Cron jako osobny
  serwis), należy do właściciela — patrz pytanie 7 niżej.
- **Nikt nie zostawił w repozytorium dowodu wykonanego restore.** Tabela
  wyników w §5 (i ta sama, wcześniej, w `DEPLOYMENT_RUNBOOK.md` KROK 13)
  jest pusta. To zgadza się z tym, że `docs/ROADMAP.md` wciąż stawia
  „restore przetestowany" jako niespełniony warunek bramki alfy, i że
  `docs/legal/BRAMKA_BETY.md` (macierz zamknięcia fali 7, 6-7 września 2026)
  **w ogóle nie wymienia backupów ani restore'u** — to nie było w zakresie
  tamtego audytu, więc jego milczenie nic tu nie potwierdza ani nie zaprzecza.
- **Bucket R2 nie istnieje.** Potwierdzone wprost przez właściciela (nie
  z repozytorium) — ale zgadza się to z tym, co repo pokazuje: gdyby bucket
  istniał, `DEPLOYMENT_RUNBOOK.md` KROK 8 nie musiałby opisywać
  `FILESYSTEM_DISK=local` jako wartości tymczasowej „na pierwszy zielony
  deploy".

### 2.2 Czego repozytorium NIE MOŻE potwierdzić

Wartości Railway Shared Variables i ustawienia w panelach (Railway, Cloudflare)
nie są w tym repozytorium **z założenia** — `railway.ts` je tylko referencuje
(`ctx.shared.X`), nigdy nie przechowuje (`INFRA_DECISION.md` §9). Poniższe
pytania nie mają odpowiedzi w kodzie, bo kod nie mówi, co ktoś kliknął w panelu.

### 2.3 `[PYTANIA DO WŁAŚCICIELA]`

| # | Pytanie | Gdzie sprawdzić |
|---|---|---|
| 1 | Czy na serwisie `postgres` (środowisko `production`) są włączone **Daily** i **Weekly** Volume Backups? | Railway → projekt `kuking` → środowisko `production` → serwis `postgres` → zakładka **Backups** |
| 2 | Czy **PITR** jest włączony, i jeśli tak — **od kiedy liczy się okno**? (Okno startuje od pierwszego backupu PO włączeniu, nie retroaktywnie) | tam samo, sekcja PITR pokaże datę początku okna albo komunikat, że nie jest włączony |
| 3 | Jaki plan Railway jest dziś aktywny (Hobby czy Pro)? Ma to znaczenie m.in. dla restart policy `ALWAYS` na serwisie `scheduler`, która na Free jest niedostępna (`.railway/railway.ts:691`) | Railway → **Workspace Settings** → **Plan** |
| 4 | Czy istnieje dziś JAKAKOLWIEK kopia bazy poza Railway (ręczny `pg_dump` wykonany kiedykolwiek przez kogokolwiek), czy warstwa offsite jest całkowicie pusta? | brak automatyzacji w repo (§2.1) — to pytanie o to, czy ktoś zrobił to ręcznie i gdzie ten plik leży |
| 5 | Czy serwis `postgres` w środowisku `production` w ogóle już istnieje (czy wdrożenie z `DEPLOYMENT_RUNBOOK.md` zostało wykonane), czy dokument nadal opisuje plan? | Railway → kanwa projektu `kuking` → środowisko `production` |
| 6 | Jaka jest dziś wartość `FILESYSTEM_DISK` na `production`? (Repozytorium sugeruje `local`, bo bucket R2 nie istnieje, ale to wniosek, nie odczyt) | Railway → `production` → serwis `web` → **Variables** → `FILESYSTEM_DISK` |
| 7 | Gdzie ma docelowo działać zautomatyzowany, offsite `pg_dump` — skoro kontener aplikacji ma `proc_open` zablokowany na stałe (hardening)? Osobny minimalny serwis Railway? GitHub Actions na harmonogramie? Coś innego? | decyzja właściciela, nie coś do sprawdzenia w panelu |
| 8 | Ile zdjęć ma dziś `disk = 'r2_legacy'` (czyli ile kont wciąż zależy WYŁĄCZNIE od tego jednego, starego bucketu jako jedynej kopii)? | `railway ssh -- php artisan tinker` → `App\Models\Media::where('disk', 'r2_legacy')->count()` |
| 9 | Czy serwis ma już realnych użytkowników wgrywających zdjęcia PRZY `FILESYSTEM_DISK=local` — czyli czy problem z ramki w §1.1 już się dzieje, a nie dopiero może się zdarzyć? | do ustalenia z właścicielem wprost, nie z panelu |

---

## 3. Procedura odtworzenia — trzy scenariusze

Wspólna zasada dla całej sekcji: **rollback kodu nie cofa migracji.**
Jeśli deploy dodał kolumnę, stary kod ją zignoruje. Jeśli usunął albo
zmienił jej nazwę — rollback kodu da natychmiastowe błędy SQL. Poprawne
migracje są `expand → migrate → switch → contract` (`docs/DEPLOYMENT.md`),
a `contract` (jedyny nieodwracalny krok) robi się po dniach, nie minutach.

### 3(a) Skasowana tabela albo `UPDATE` bez `WHERE`

**Nie idź dalej w panice.** Kolejny `UPDATE` „naprawiający" pomyłkę na żywej
bazie to najczęstszy sposób, żeby pomyłkę było dwie. Zanim cokolwiek zrobisz:

1. Ustal **dokładny czas** pomyłki co do minuty — z logu terminala, z Sentry,
   z `audit_log` (jeśli tabela, którą zepsuto, to nie ten sam `audit_log`).
2. **Nie restartuj i nie redeployuj** serwisu `web` — nic z tego nie cofa
   zapisu w bazie, a niepotrzebnie miesza logi.

**Narzędzie na ten scenariusz to PITR**, bo pozwala cofnąć się o dokładnie
jedną minutę bez utraty WSZYSTKICH zapisów, które nastąpiły PO pomyłce
w innych tabelach.

```text
Railway → postgres → Backups → PITR → wybierz moment MINUTĘ PRZED pomyłką
→ „Restore to this moment"
```

To tworzy **NOWY** serwis (`postgres-restored-<data>`) **obok** produkcyjnego.
Produkcja działa dalej przez cały czas — nic dodatkowo nie tracisz, próbując.

3. Połącz się z przywróconym serwisem i sprawdź, że dane, których szukasz,
   tam są:
   ```bash
   railway connect postgres-restored-<data> --tunnel-only
   # w drugim terminalu:
   psql "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
     -c "SELECT count(*) FROM <uszkodzona_tabela>;"
   ```
4. Wyeksportuj **tylko** brakujące/nadpisane wiersze — nie całą bazę:
   ```bash
   pg_dump "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
     --data-only --table=<uszkodzona_tabela> \
     --file="naprawa-${uszkodzona_tabela}.sql"
   ```

   > ## ⚠️ Krok 5 PISZE do produkcji. Sprawdź środowisko, zanim wykonasz:
   > ```bash
   > railway status   # musi pokazać: Project kuking, Environment production
   > ```
   > oraz w `psql`, do którego się faktycznie łączysz:
   > ```sql
   > SELECT current_database(), inet_server_addr();
   > ```

5. Wczytaj naprawę do produkcji **w transakcji, z podglądem przed commitem**:
   ```bash
   psql "$DB_URL_PRODUKCJI" -v ON_ERROR_STOP=1 <<'SQL'
   BEGIN;
   \i naprawa-<uszkodzona_tabela>.sql
   SELECT count(*) FROM <uszkodzona_tabela>;  -- sprawdź liczbę PRZED commitem
   -- COMMIT; -- odkomentuj dopiero po sprawdzeniu liczby wyżej
   SQL
   ```
6. Usuń pomocniczy serwis `postgres-restored-<data>` — kosztuje, jeśli zostanie.

**RPO:** ~0 (PITR ma ziarnistość WAL, praktycznie do sekundy) — **o ile PITR
jest włączony** (patrz pytanie 2 w §2.3). Jeśli nie, jedyna opcja to ostatni
Volume Backup (RPO do 24 h) albo ostatni zrzut offsite (RPO nieznany —
patrz pytanie 4).
**RTO:** **oszacowanie 30–60 min** (provisioning przywróconego serwisu,
weryfikacja, selektywny eksport/import) — **niezmierzone, dopóki §4 nie
zostanie wykonane.**

### 3(b) Utracona cała baza

Rozróżnij dwa podscenariusze — procedura jest inna.

**Projekt Railway wciąż istnieje, ale dane w `postgres` zniknęły/uszkodzone:**

```text
Railway → postgres → Backups → Volume Backups → wybierz najnowszy → Restore
```

> ## ⚠️ To NADPISUJE serwis w miejscu. Przed kliknięciem sprawdź:
> że jesteś w środowisku **`production`**, nie `staging` ani PR-owym —
> nazwa środowiska jest widoczna w górnym wybieraku panelu Railway,
> obok nazwy projektu. Kliknięcie „Restore" na złym środowisku przywraca
> STARE dane NAD nowszymi — to samo ryzyko, co scenariusz 3(a), tylko
> na całej bazie naraz.

**Projekt/serwis Railway sam został usunięty (najgorszy przypadek):**

Jedyna droga to warstwa offsite — o ile istnieje (patrz pytanie 4 w §2.3;
jeśli nie istnieje, to jest miejsce, w którym trzeba to powiedzieć wprost
właścicielowi, nie udawać, że jest plan B).

```bash
# 1. Nowy projekt Railway + nowy serwis Postgres (panel albo
#    `railway.ts` na nowym projekcie — kod aplikacji się nie zmienia)

# 2. Restore najnowszego zrzutu offsite
pg_restore --dbname="$DB_URL_NOWEJ_BAZY" \
  --no-owner --exit-on-error \
  "ostatni-offsite-zrzut.dump"

# 3. Podepnij nowy DB_URL do serwisów web/worker/scheduler
railway variables --set "DB_URL=<nowy_DATABASE_URL>" --environment production

# 4. Redeploy i weryfikacja
curl -s https://kuking.pl/health   # oczekiwane: {"status":"ok"}
```

Potem: odtwórz DNS/WAF/Cache Rules wg `DEPLOYMENT_RUNBOOK.md` KROK 10 (jeśli
projekt Railway się zmienił, adresy `*.up.railway.app` też się zmieniły —
CNAME w Cloudflare trzeba zaktualizować).

**RPO:** zależny od warstwy, która przetrwała — PITR ~5 min (jeśli
infrastruktura Railway w ogóle przetrwała), Volume Backup do 24 h (Daily)
albo 7 dni (Weekly), zrzut offsite = wiek ostatniego pliku (**dziś: NIEZNANY,
bo automatyzacji nie ma — patrz §2.1**).
**RTO:** **oszacowanie 1-4 h** w zależności od tego, czy trzeba tylko przywrócić
bazę, czy całe środowisko od zera wg `DEPLOYMENT_RUNBOOK.md` (tam: „3-4 godziny
plus czekanie na DNS") — **niezmierzone.**

### 3(c) Utracone zdjęcia

**[DZIŚ]**, przed R2: dysk `local` jest ulotny z definicji. Nie ma tu procedury
odtworzenia, bo nie ma z czego odtwarzać — to jest normalny, oczekiwany skutek
tej konfiguracji, opisany w ramce w §1.1. Sprawdź, w jakim trybie jest
środowisko:

```bash
railway variables --environment production | grep FILESYSTEM_DISK
```

Jeśli to `local` i serwis ma już prawdziwe zdjęcia od ludzi — to jest
blokada P0 do rozwiązania KROKIEM 2 z `DEPLOYMENT_RUNBOOK.md`, nie do
zaakceptowania jako ryzyko.

**[PO R2]** — trzy podscenariusze, bo różnią się tym, co przetrwało:

**(c1) Warianty (`r2_publiczne`) utracone, oryginały (`r2`) całe.**
Da się przetworzyć na nowo — ale w repozytorium **nie ma dziś gotowej
komendy** do masowego ponownego przetworzenia (`ProcessUploadedImage`
jest dziś dispatch'owany wyłącznie raz, przy uploadzie —
`app/Domain/Media/Actions/StoreUploadedImage.php:216`). Awaryjnie:

```bash
railway ssh --service worker -- php artisan tinker
```
```php
App\Models\Media::where('variants_disk', 'r2_publiczne')
    ->orWhereNull('variants_disk')
    ->chunkById(100, fn ($batch) => $batch->each(
        fn ($media) => App\Jobs\ProcessUploadedImage::dispatch($media->id)
    ));
```

> **[DO ZWERYFIKOWANIA PRZED UŻYCIEM]** to zakłada, że `ProcessUploadedImage`
> bezpiecznie nadpisuje istniejące warianty tego samego medium, a nie tylko
> obsługuje świeży upload. Sprawdź to na JEDNYM rekordzie testowym, zanim
> puścisz pętlę na wszystkich. Napisanie właściwej komendy
> `kuking:przetworz-zdjecie-ponownie` to dobry kandydat na osobne issue —
> poza zakresem tego dokumentu, bo to jest zmiana kodu.

**(c2) Oryginały (`r2`) też utracone.** 🔴 Bezpowrotna utrata treści wizualnej
dla tych zdjęć — nie ma niczego, z czego odtworzyć piksele. Jedyna obrona
na przyszłość to nie dopuścić do tej sytuacji: rozważyć wersjonowanie
obiektów / retencję po stronie Cloudflare R2 dla bucketu oryginałów, gdy
bucket powstanie. **[DO WERYFIKACJI]** czy plan R2 w użyciu to wspiera —
sprawdzić w dokumentacji Cloudflare przy zakładaniu bucketu (KROK 2
`DEPLOYMENT_RUNBOOK.md`), nie zakładać z góry.

**(c3) `r2_legacy` (konta sprzed migracji) utracony.** 🔴 Bezpowrotna utrata
dla kont, których `kuking:przenies-zdjecia` jeszcze nie objął — to jest ta
sama pozycja co w tabeli §1.1. Sprawdź, ile zostało (pytanie 8, §2.3), zanim
zdecydujesz, jak pilna jest dokończenie migracji.

**RPO/RTO:** dla (c1) RPO=0 (źródło całe), RTO zależny od liczby zdjęć ×
czas przetwarzania jednego (`docs/MEDIA_PIPELINE.md`: 1,3-4,6 s na zdjęcie
w zależności od rozdzielczości) — **oszacowanie, niezmierzone**. Dla (c2)
i (c3) nie ma dziś żadnej warstwy backupu zdjęć — RPO jest w praktyce
nieskończone, dopóki decyzja z pytania 7 (§2.3) i ewentualne wersjonowanie
R2 nie zostaną wdrożone.

---

## 4. Ćwiczenie odtworzeniowe — do wykonania RAZ, żeby zamknąć bramkę alfy

**Warunek bezpieczeństwa tego ćwiczenia:** żaden krok nie zapisuje ani nie
zmienia niczego w bazie `railway` (produkcyjnej) ani w usłudze, na której
działa aplikacja. `pg_dump` w kroku 2 otwiera wyłącznie połączenie **do
odczytu** (standardowe zachowanie `pg_dump` — migawka `REPEATABLE READ`,
bez blokad zapisu); cel przywrócenia to **osobna baza danych** na tym samym
serwerze, nigdy nie podłączona do `DB_URL` aplikacji. Zanim zaczniesz, jeśli
odpowiedź na pytanie 1/2 w §2.3 brzmi „nie" — włącz Daily+Weekly+PITR teraz
(to jest bonus tego ćwiczenia: robisz je i tak, i zaczyna się liczyć okno).

```bash
# Wymagania: railway CLI (>= 5.42.1), psql, pg_dump, pg_restore
pg_dump --version   # potwierdź, że jest zainstalowany, ZANIM wejdziesz w krok 1
```

**Krok 1 — zapisz punkt odniesienia z produkcji (tylko odczyt):**

```bash
railway link                          # projekt kuking, środowisko production
railway connect postgres --tunnel-only
#   Zostaw okno otwarte — wypisze host, port, użytkownika i hasło.
```

W **drugim** terminalu, zanotuj te liczby (będą Twoim dowodem w kroku 5):

```bash
psql "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" <<'SQL'
SELECT count(*) AS users FROM users;
SELECT count(*) AS posts FROM posts;
SELECT count(*) AS recipes FROM recipes;
SELECT count(*) AS cooked_events FROM cooked_events;
SQL
```

**Krok 2 — zrzut, z pomiarem czasu i rozmiaru:**

```bash
STAMP=$(date -u +%Y%m%d-%H%M%S)
time pg_dump "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
  --format=custom --no-owner --file="kuking-${STAMP}.dump"
ls -lh "kuking-${STAMP}.dump"
```

**Krok 3 — baza-piaskownica na TYM SAMYM serwerze** (nie produkcyjna baza
`railway`, osobna baza obok niej):

```bash
psql "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
  -c 'CREATE DATABASE restore_drill;'
```

**Krok 4 — restore z pomiarem czasu (to jest Twoje realne RTO):**

```bash
time pg_restore \
  --dbname="postgresql://postgres:<HASLO>@localhost:<PORT>/restore_drill" \
  --no-owner --exit-on-error "kuking-${STAMP}.dump"
```

**Krok 5 — DOWÓD MIERZALNY, nie „wygląda dobrze":**

```bash
psql "postgresql://postgres:<HASLO>@localhost:<PORT>/restore_drill" <<'SQL'
SELECT count(*) AS users FROM users;
SELECT count(*) AS posts FROM posts;
SELECT count(*) AS recipes FROM recipes;
SELECT count(*) AS cooked_events FROM cooked_events;
SQL
```

Ćwiczenie jest zaliczone, gdy **każda z czterech liczb z kroku 5 jest
identyczna** z odpowiadającą liczbą z kroku 1 (dopuszczalna różnica: wiersze
dopisane do produkcji między krokiem 1 a 2 — jeśli się różnią, to o więcej
niż to tłumaczy, to jest realny problem, nie sukces do zaraportowania).
Dodatkowo, jeśli którekolwiek konto testowe ma włączone 2FA, sprawdź, że
`two_factor_secret` w `restore_drill` nie jest `NULL` (nie musisz go
odszyfrowywać — sam fakt, że wartość jest obecna, potwierdza że kolumny
zaszyfrowane `APP_KEY` też przeżyły zrzut i restore).

**Krok 6 — sprzątanie:**

```bash
psql "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
  -c 'DROP DATABASE restore_drill;'
```

Zapisz wynik — czas z kroku 2, czas z kroku 4, rozmiar pliku, i czy dowód
z kroku 5 się zgodził — w tabeli w §5.

**Czego to ćwiczenie NIE testuje:** odtworzenia zdjęć (niemożliwe przed R2 —
patrz §3(c)) i restore z Volume Backup/PITR (to osobne mechanizmy Railway,
warte przećwiczenia OSOBNO raz na kwartał wg §6, ale nie na potrzeby zamknięcia
bramki alfy — ta bramka pyta o „restore", a ten dowód go daje).

---

## 5. Wynik ćwiczenia — do wypełnienia

| Data | Kto | Rozmiar zrzutu | Czas zrzutu | Czas restore (RTO) | Wiek zrzutu (RPO) | Dowód (§4 krok 5) zgodny? | Co nie zadziałało |
|---|---|---|---|---|---|---|---|
| | | | | | | | |

---

## 6. Co sprawdzać cyklicznie

Uzupełnia ogólną rutynę z `DEPLOYMENT_RUNBOOK.md` KROK 15 — tu tylko pozycje
specyficzne dla kopii i odtwarzania.

**Co tydzień:**
- Backups nadal włączone w panelu Railway (subskrypcja/plan mógł wygasnąć
  albo zostać zmieniony) — pytanie 1/2 z §2.3, sprawdzone ponownie.
- Jeśli warstwa offsite już istnieje (po rozstrzygnięciu pytania 7): zrzut
  z ostatnich 24 h faktycznie powstał i ma niezerowy rozmiar.

**Co miesiąc:**
- Rozmiar bazy i R2 vs trend — rosnąca baza zmienia RTO restore'u, warto to
  wiedzieć zanim zaskoczy w prawdziwej awarii.
- Ile zdjęć wciąż ma `disk = 'r2_legacy'` — czy migracja `kuking:przenies-zdjecia`
  w ogóle się porusza.

**Co kwartał:**
- **Powtórz pełne ćwiczenie z §4** i dopisz wiersz do tabeli w §5. Backup,
  którego nikt dawno nie przywrócił, jest ponownie tylko nadzieją.
- Gdy R2 już istnieje: przećwicz też odtworzenie zdjęć wg §3(c1) na koncie
  testowym, nie na prawdziwych danych.
- Test przywrócenia PITR do konkretnego znacznika czasu (osobno od
  ćwiczenia w §4, bo to inny mechanizm Railway).

**Co pół roku:**
- Rotacja kluczy R2 i hasła SMTP — procedura w `DEPLOYMENT_RUNBOOK.md` §15.
- Sprawdź, czy okno PITR (ok. 4 tygodnie) wciąż wystarcza wobec tego, jak
  szybko ktoś zauważa problem w praktyce.

---

## Źródła i powiązane dokumenty

- `docs/infra/DEPLOYMENT_RUNBOOK.md` — KROK 6.4 (włączenie backupów), KROK 13
  (wcześniejsza wersja ćwiczenia), KROK 14 (rollback kodu), KROK 15 (rutyna)
- `docs/infra/INFRA_DECISION.md` §9 (sekrety), §10 (backupy — architektura
  i uzasadnienie trzech warstw), §13 (ryzyka)
- `docs/DATABASE.md` — pełna lista 25 tabel i ich zawartość
- `docs/MEDIA_PIPELINE.md` — pipeline zdjęć, koszty pamięci przetwarzania
- `docs/decyzje/ADR_RETENCJE.md` — ile dana kategoria danych żyje z definicji
  (to inne pytanie niż backup: retencja to zamierzone kasowanie, backup to
  ochrona przed niezamierzonym)
- `docs/ROADMAP.md` — „Closed alpha gate": „restore przetestowany"
- Railway — backup i restore: https://docs.railway.com/guides/postgres-backups-restores
