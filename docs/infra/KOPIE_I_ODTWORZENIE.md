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
dopóki ćwiczenie z §4A nie zostanie wykonane i wpisane do tabeli w §5.
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
| **Klucz PRYWATNY kopii bazy** (`kuking-kopie-PRYWATNY.pem`, §7.1) | menedżer haseł właściciela + nośnik offline w innym miejscu fizycznym. **Świadomie NIGDZIE w Railwayu ani w repozytorium** | Zrzuty offsite są szyfrowane odpowiadającym mu kluczem publicznym. Utrata klucza prywatnego **nie kasuje żadnego pliku**, ale czyni WSZYSTKIE kopie bazy nieczytelnymi na zawsze — dokładnie ta sama asymetria, co przy `APP_KEY` wyżej. Cena jest świadoma: dzięki temu przejęcie konta Railway albo bucketu R2 nie daje dostępu do danych. Dlatego DWIE kopie klucza, w DWÓCH miejscach |
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
- **Warstwa offsite MA JUŻ KOD, ale NIE MA JESZCZE DZIAŁAJĄCEGO SERWISU.**
  To są dwie różne rzeczy i trzeba je tu rozdzielić, bo pomylenie ich jest
  dokładnie tym rodzajem fałszywego spokoju, przed którym stoi ten dokument.

  **Jest w repozytorium** (issue #193, decyzja D-043 — pełny opis w §7):
  `docker/kopia/Dockerfile` (obraz z `pg_dump` 18, bez PHP),
  `docker/kopia/kopia-bazy.sh` (zrzut → weryfikacja → szyfrowanie kluczem
  publicznym → wysyłka do R2 → potwierdzenie → retencja),
  `docker/kopia/s3.sh` (podpis SigV4 bez `aws` CLI), deklaracja serwisu
  `kopia-bazy` w `.railway/railway.ts`, czujka `kuking:sprawdz-kopie`
  w aplikacji i testy w `tests/skrypty/kopia-bazy.sh`.

  **Nie ma nigdzie:** bucketu R2, tokenów, klucza szyfrującego ani serwisu
  w Railway — to cztery czynności w panelach, spisane w §7.3, i należą do
  właściciela. **Do ich wykonania liczba kopii bazy wynosi ZERO.**

  Dlaczego to nie mogło pójść tam, gdzie zakładał plan: harmonogram
  aplikacyjny (`routes/console.php`) celowo używa `Schedule::call()` zamiast
  `Schedule::command()` wszędzie, bo `docker/php.ini` ma
  `disable_functions=...,proc_open,...` (hardening, którego AGENTS.md zabrania
  osłabiać) — a `pg_dump` uruchomiony z PHP wymaga właśnie `proc_open` (klasa
  `Symfony\Process`). Dlatego zrzut **przeniesiono** do osobnego obrazu bez
  PHP, a nie „naprawiono" w kontenerze aplikacji. `.github/workflows/*.yml`
  nadal nie zawiera ani `pg_dump`, ani żadnego zadania backupowego — i tak ma
  zostać: poświadczenie do bazy nie ma opuszczać Railwaya (D-043).
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
| 1 | **Rozstrzygnięte (D-043, 9 września 2026) — nie pytanie, tylko fakt do zapamiętania:** Volume Backups **nie są i nie mogą być włączone**. Ta funkcja istnieje wyłącznie w planie Pro, a Kuking jest na Free/Hobby. Pytanie, które ma dziś sens: **na kiedy #193** (zrzut offsite, jedyna planowana kopia)? | `docs/DECISIONS.md` → `## D-043`; postęp: issue #193 |
| 2 | **Rozstrzygnięte (D-043)** — **PITR nie istnieje** na tym planie, więc nie ma czego liczyć ani „od kiedy". Nie sprawdzaj tego w panelu — panel na Free/Hobby nawet nie pokaże tej zakładki | `docs/DECISIONS.md` → `## D-043` |
| 3 | Jaki plan Railway jest dziś aktywny (Hobby czy Pro)? Ma to znaczenie m.in. dla restart policy `ALWAYS` na serwisie `scheduler`, która na Free jest niedostępna (`.railway/railway.ts:691`) | Railway → **Workspace Settings** → **Plan** |
| 4 | Czy istnieje dziś JAKAKOLWIEK kopia bazy poza Railway (ręczny `pg_dump` wykonany kiedykolwiek przez kogokolwiek)? **Automatyzacja jest już w kodzie, ale bucketu ani serwisu nie ma (§2.1, §7.3) — więc odpowiedź „nie" znaczy: zero kopii.** | ręczny plik u właściciela; repozytorium tego nie widzi |
| 5 | Czy serwis `postgres` w środowisku `production` w ogóle już istnieje (czy wdrożenie z `DEPLOYMENT_RUNBOOK.md` zostało wykonane), czy dokument nadal opisuje plan? | Railway → kanwa projektu `kuking` → środowisko `production` |
| 6 | Jaka jest dziś wartość `FILESYSTEM_DISK` na `production`? (Repozytorium sugeruje `local`, bo bucket R2 nie istnieje, ale to wniosek, nie odczyt) | Railway → `production` → serwis `web` → **Variables** → `FILESYSTEM_DISK` |
| 7 | **Rozstrzygnięte (D-043) — nie pytanie, tylko fakt:** offsite `pg_dump` działa w OSOBNYM, minimalnym serwisie Railway (`docker/kopia/`, serwis `kopia-bazy`), nie w kontenerze aplikacji i nie w GitHub Actions. Pytanie, które ma dziś sens: **kiedy zostaną wykonane cztery czynności z §7.3** (bucket R2, dwa tokeny, klucz szyfrujący, serwis cron)? | `docs/DECISIONS.md` → `## D-043`; §7.3 tego dokumentu; postęp: issue #193 |
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

> ## ⚠️ PITR NIE JEST DZIŚ DOSTĘPNY — czytaj dalej wiedząc, czego nie masz
>
> Volume Backups i PITR to funkcje planu **Pro**; Kuking jest na Free/Hobby
> i panel nawet nie pokazuje tej zakładki (D-043, sprawdzone przez
> właściciela). Procedura niżej opisuje więc narzędzie, którego dziś NIE MA,
> i zostaje tu na dzień przejścia na Pro.
>
> **Co robić dzisiaj, gdy to się właśnie stało:** cofnąć się da się wyłącznie
> do ostatniego zrzutu offsite (§7.4) — czyli stracić wszystko, co
> użytkownicy zapisali od tamtej nocy, w KAŻDEJ tabeli, nie tylko w tej
> zepsutej. To jest różnica między „minuta" a „do doby", i jest to
> najmocniejszy argument, żeby wykonać §7.3 dziś, a nie kiedyś.

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

Jedyna droga to warstwa offsite — o ile serwis z §7.3 został już założony.
**Jeśli nie został, planu B NIE MA i trzeba to powiedzieć wprost, a nie
szukać w tym dokumencie procedury, która nie miałaby z czego działać.**

Zrzuty w buckecie są **zaszyfrowane kluczem publicznym**, więc krok 2 wymaga
klucza prywatnego z §7.1 — z menedżera haseł albo z nośnika offline. Nie ma go
ani w Railwayu, ani w tym repozytorium; to jest cena za to, że przejęcie konta
Railway nie daje dostępu do danych. Pełna procedura odczytu, razem ze
sprawdzeniem skrótu: **§7.4**.

```bash
# 1. Nowy projekt Railway + nowy serwis Postgres (panel albo
#    `railway.ts` na nowym projekcie — kod aplikacji się nie zmienia)

# 2. Odszyfruj najnowszy zrzut offsite (szczegóły i weryfikacja: §7.4)
openssl cms -decrypt -binary -inform DER \
  -in "kuking-<znacznik>.dump.cms" \
  -inkey kuking-kopie-PRYWATNY.pem \
  -out ostatni-offsite-zrzut.dump

# 3. Restore
pg_restore --dbname="$DB_URL_NOWEJ_BAZY" \
  --no-owner --exit-on-error \
  "ostatni-offsite-zrzut.dump"

# 4. Podepnij nowy DB_URL do serwisu kuking.pl
#    (dziś jeden serwis aplikacyjny w trybie `all` — nie ma osobnych
#    web/worker/scheduler, patrz sprostowanie w §2.3 i D-038/D-043)
railway variables --set "DB_URL=<nowy_DATABASE_URL>" --service kuking.pl --environment production

# 5. Redeploy i weryfikacja
curl -s https://kuking.pl/health   # oczekiwane: {"status":"ok"}
```

Potem: odtwórz DNS/WAF/Cache Rules wg `DEPLOYMENT_RUNBOOK.md` KROK 10 (jeśli
projekt Railway się zmienił, adresy `*.up.railway.app` też się zmieniły —
CNAME w Cloudflare trzeba zaktualizować).

**RPO:** zależny od warstwy, która przetrwała — PITR ~5 min (jeśli
infrastruktura Railway w ogóle przetrwała), Volume Backup do 24 h (Daily)
albo 7 dni (Weekly), zrzut offsite = wiek ostatniego pliku (**dziś: nieskończony, bo
serwisu kopii jeszcze nie ma — patrz §2.1 i §7.3. Po jego uruchomieniu:
do 24 h, a wiek ostatniej kopii mówi `kuking:sprawdz-kopie`**).
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
railway ssh --service kuking.pl -- php artisan tinker
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
działa aplikacja. `pg_dump` otwiera wyłącznie połączenie **do odczytu**
(standardowe zachowanie `pg_dump` — migawka `REPEATABLE READ`, bez blokad
zapisu); cel przywrócenia to **osobna baza danych**, nigdy nie podłączona do
`DB_URL` aplikacji.

> **Poprzednia wersja tego akapitu kazała tu „włączyć Daily+Weekly+PITR" —
> i to była nieprawda.** Volume Backups i PITR istnieją wyłącznie w planie
> Pro, a Kuking jest na Free/Hobby (D-043). Nie ma czego włączać; jest za to
> §7.3 do wykonania.

### Które ćwiczenie wykonać

| Stan | Ćwiczenie | Co dowodzi |
|---|---|---|
| §7.3 **wykonane** — serwis `kopia-bazy` chodzi i w buckecie leżą pliki | **§4A** | że **prawdziwa kopia** z prawdziwej warstwy da się odczytać i odtworzyć. **To jest ćwiczenie zamykające bramkę alfy i kryterium z #193** |
| §7.3 **jeszcze nie wykonane** (stan na 9 września 2026) | **§4B** | że baza da się zrzucić i odtworzyć — plus daje **pierwszą, ręczną kopię offsite**, dopóki nie ma automatu |

Rób §4A, gdy tylko będzie z czego. §4B nie zastępuje §4A: sprawdza `pg_dump`
i `pg_restore`, a nie tę warstwę, której #193 dotyczy (szyfrowanie, bucket,
klucz prywatny — czyli dokładnie te trzy rzeczy, które w prawdziwej awarii
mogą zawieść).

---

### §4A. Ćwiczenie na PRAWDZIWEJ kopii z bucketu

Nic w tej procedurze nie dotyka produkcji poza jednym odczytem liczników
w kroku 1 — a i on jest opcjonalny, bo porównanie da się zrobić z pola
`tabel_z_danymi` w pliku `.meta`.

```bash
# 0. Wymagania: openssl, pg_restore (>= wersja z .meta), klucz PRYWATNY (§7.1),
#    dostęp do bucketu kopii (dowolny klient S3 albo panel Cloudflare).

# 1. Punkt odniesienia z produkcji — TYLKO ODCZYT (opcjonalny, patrz wyżej).
railway ssh --service kuking.pl -- php artisan tinker --execute \
  "echo App\\Models\\User::count(), ' ', App\\Models\\Post::count();"

# 2. Weź NAJNOWSZĄ kopię i jej metadane.
rclone lsl r2:kuking-kopie/baza/ | sort | tail -4

# 3. Odszyfruj i sprawdź skrót — to jest sedno tego ćwiczenia.
time openssl cms -decrypt -binary -inform DER \
  -in kuking-<znacznik>.dump.cms \
  -inkey kuking-kopie-PRYWATNY.pem \
  -out kuking.dump

sha256sum kuking.dump
grep '^sha256_jawnego:' kuking-<znacznik>.meta   # MUSZĄ być identyczne

# 4. Odtwórz do PUSTEJ bazy (lokalnej albo w nowym projekcie) i zmierz czas.
createdb restore_drill
time pg_restore --dbname=restore_drill --no-owner --exit-on-error kuking.dump

# 5. DOWÓD MIERZALNY.
psql restore_drill <<'SQL'
SELECT count(*) AS users FROM users;
SELECT count(*) AS posts FROM posts;
SELECT count(*) AS recipes FROM recipes;
SELECT count(*) AS cooked_events FROM cooked_events;
SQL

# 6. Sprzątanie.
dropdb restore_drill && rm -f kuking.dump
```

Ćwiczenie jest zaliczone, gdy **wszystkie trzy rzeczy naraz**: skrót z kroku 3
się zgadza, `pg_restore` w kroku 4 kończy się bez błędu, a liczniki z kroku 5
odpowiadają produkcji z dokładnością do wpisów dodanych po zrzucie. Wynik —
razem z czasem odszyfrowania i czasem restore — idzie do tabeli w §5.

**Klucz prywatny wraca po ćwiczeniu tam, skąd go wziąłeś, i znika z dysku
komputera.** To jest jedyna rzecz, której utrata unieważnia całą tę warstwę
(§1.1).

---

### §4B. Ćwiczenie ręczne — gdy bucketu jeszcze nie ma

Ta procedura zostaje bez zmian od pierwszej wersji tego dokumentu i ma dziś
dodatkową wartość: **plik, który po niej zostanie, jest jedyną kopią bazy
Kuking, jaka istnieje.** Odłóż go w bezpieczne miejsce, nie do katalogu
`Pobrane`.

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
patrz §3(c)), szyfrowania ani odczytu z bucketu (to jest §4A) oraz restore
z Volume Backup/PITR — **tych dwóch nie da się przećwiczyć w ogóle, bo na
planie Free/Hobby nie istnieją** (D-043). Poprzednia wersja tego akapitu
odsyłała do ich kwartalnego ćwiczenia; nie ma czego ćwiczyć.

---

## 5. Wynik ćwiczenia — do wypełnienia

**Ta tabela jest PUSTA i to jest jedyne, co dziś zamyka temat.**
`docs/ROADMAP.md` stawia w bramce alfy warunek „restore przetestowany",
a #193 kończy się kryterium „odtworzenie z tej warstwy wykonane raz
i udokumentowane z datą". Dopóki nie ma tu wiersza, warunek jest niespełniony,
niezależnie od tego, ile kodu stoi w `docker/kopia/`. **Zrzut, którego nikt
nie odtworzył, jest obietnicą, nie kopią.**

Ćwiczenie z §4A wymaga rzeczy, których repozytorium nie ma i mieć nie może:
dostępu do produkcyjnej bazy, bucketu R2 i klucza prywatnego. Ten wiersz
wypełnia **człowiek**, po §7.3.

| Data | Kto | Ćwiczenie (§4A/§4B) | Rozmiar zrzutu | Czas odszyfrowania | Czas restore (RTO) | Wiek zrzutu (RPO) | Skrót z `.meta` zgodny? | Liczniki zgodne? | Co nie zadziałało |
|---|---|---|---|---|---|---|---|---|---|
| | | | | | | | | | |

### 5.1 Próby na środowisku deweloperskim — NIE zamykają bramki

Osobna tabela, żeby nie dało się jednego wziąć za drugie. Poniższe przebiegi
dowodzą, że **mechanizm** działa: zrzut → weryfikacja → szyfrowanie → wysyłka
→ odszyfrowanie samym kluczem prywatnym → `pg_restore`. Nie dowodzą niczego
o produkcji, bo nie było w nich ani produkcyjnej bazy, ani prawdziwego R2.

| Data | Co uruchomiono | Wynik | Czego to NIE sprawdziło |
|---|---|---|---|
| 9 IX 2026 | `docker/kopia/kopia-bazy.sh` na lokalnej bazie po migracjach, z podstawionym bucketem na dysku, prawdziwym `openssl cms` i prawdziwą parą kluczy RSA | Zrzut 125 592 B, 42 tabele z danymi, szyfrogram 126 147 B. Odszyfrowanie **samym kluczem prywatnym** dało plik o identycznym `sha256`; `pg_restore` wczytał go do pustej bazy bez błędu (42 tabele) | Serwera PostgreSQL **18** (lokalnie 16), prawdziwej rozmowy z R2 (podpis SigV4 sprawdzony wektorami AWS, nie wobec Cloudflare), zbudowania obrazu (brak Dockera w środowisku), harmonogramu Railway |

---

## 6. Co sprawdzać cyklicznie

Uzupełnia ogólną rutynę z `DEPLOYMENT_RUNBOOK.md` KROK 15 — tu tylko pozycje
specyficzne dla kopii i odtwarzania.

**Co tydzień:**
- **Czy kopia z ostatnich 24 h faktycznie powstała.** Jedno polecenie,
  z dowolnego miejsca:

  ```bash
  railway ssh --service kuking.pl -- php artisan kuking:sprawdz-kopie
  ```

  Zielone = jest świeża kopia. Czerwone = serwis `kopia-bazy` przestał
  chodzić. **„Czujka jest WYŁĄCZONA" = §7.3 wciąż nie zostało wykonane
  i kopii nie ma żadnej** — to nie jest to samo co spokój.
- ~~Backups włączone w panelu Railway~~ — **nie ma czego sprawdzać.**
  Volume Backups i PITR to funkcje planu Pro (D-043). Ta pozycja stała tu
  do 9 września 2026 i była nieprawdą, którą dawało się odhaczyć.

**Co miesiąc:**
- Rozmiar bazy i R2 vs trend — rosnąca baza zmienia RTO restore'u, warto to
  wiedzieć zanim zaskoczy w prawdziwej awarii.
- Ile zdjęć wciąż ma `disk = 'r2_legacy'` — czy migracja `kuking:przenies-zdjecia`
  w ogóle się porusza.

**Co kwartał:**
- **Powtórz pełne ćwiczenie z §4A** i dopisz wiersz do tabeli w §5. Backup,
  którego nikt dawno nie przywrócił, jest ponownie tylko nadzieją.
- **Sprawdź, czy klucz prywatny kopii wciąż da się odczytać z OBU miejsc**
  (§7.1) — menedżer haseł i nośnik offline. Nośnik, którego nikt nie włożył
  do gniazda od roku, nie jest kopią klucza, tylko wspomnieniem o niej:

  ```bash
  openssl rsa -in kuking-kopie-PRYWATNY.pem -check -noout
  ```
- **Sprawdź, czy `pg_dump` w obrazie kopii nadal odpowiada wersji serwera.**
  Skrypt odmawia pracy i alarmuje sam, gdy serwer wyprzedzi klienta — ale
  lepiej dowiedzieć się o tym z własnej inicjatywy niż z alarmu. Nie potrzeba
  do tego ani Railwaya, ani dostępu do bazy: obie liczby są w pliku `.meta`
  ostatniej kopii:

  ```bash
  grep -E '^(serwer_postgresql|pg_dump):' kuking-<znacznik>.meta
  ```

  Gdy serwer podskoczy do 19, podnieś tag obrazu bazowego
  w `docker/kopia/Dockerfile` (`FROM postgres:18`) — pilnuje tego także
  test dymny w `.github/workflows/ci.yml`.
- Gdy R2 już istnieje: przećwicz też odtworzenie zdjęć wg §3(c1) na koncie
  testowym, nie na prawdziwych danych.
- ~~Test przywrócenia PITR do konkretnego znacznika czasu~~ — **skreślone:
  PITR nie istnieje na tym planie** (D-043). Wraca na tę listę tego dnia,
  w którym projekt przejdzie na plan Pro, i nie wcześniej.

**Co pół roku:**
- Rotacja kluczy R2 i hasła SMTP — procedura w `DEPLOYMENT_RUNBOOK.md` §15.
  Dotyczy też **obu tokenów bucketu kopii** (§7.3 krok 2). Klucza
  szyfrującego (§7.1) NIE rotujemy przy tej okazji: nowa para unieważnia
  odczyt wszystkich starszych kopii, więc rotacja klucza to osobna, świadoma
  operacja — stary klucz prywatny trzyma się tak długo, jak długo żyje
  najstarsza kopia zaszyfrowana starym kluczem (przy retencji 30 dni:
  co najmniej miesiąc po zmianie).
- Sprawdź, czy okno PITR (ok. 4 tygodnie) wciąż wystarcza wobec tego, jak
  szybko ktoś zauważa problem w praktyce.

---

## 7. Warstwa offsite — zrzut bazy poza Railwayem

**To jest DZISIAJ JEDYNA planowana kopia bazy Kuking**, nie „trzecia warstwa".
Volume Backups i PITR, o których mówi `INFRA_DECISION.md` §10, są funkcjami
planu **Pro**; Kuking jest na Free/Hobby i tych funkcji tam nie ma (D-043).
Dopóki ta warstwa nie chodzi na produkcji, liczba kopii bazy wynosi **zero**.

**Gdzie to mieszka w kodzie:**

| Plik | Co robi |
|---|---|
| `docker/kopia/Dockerfile` | obraz: `postgres:18` (czyli `pg_dump` 18) + `openssl` + `curl`. Bez PHP |
| `docker/kopia/kopia-bazy.sh` | cały przebieg: zrzut → weryfikacja → szyfrowanie → wysyłka → potwierdzenie → retencja |
| `docker/kopia/s3.sh` | podpis AWS SigV4 i wysyłka do R2 przez `curl` (bez `aws` CLI) |
| `.railway/railway.ts` → serwis `kopia-bazy` | harmonogram (Railway Cron, 02:17 UTC), limity, zmienne |
| `app/Domain/Kopie/StanKopiiBazy.php` | czujka po stronie aplikacji: czy kopie NADAL powstają |
| `app/Console/Commands/SprawdzKopieBazy.php` | `kuking:sprawdz-kopie` — ta czujka z ręki i z harmonogramu |
| `tests/skrypty/kopia-bazy.sh` | testy skryptu: wektory AWS, treść alarmu, retencja, szyfrowanie w obie strony |

**Dlaczego osobny serwis, a nie harmonogram aplikacji:** `docker/php.ini`
wyłącza `proc_open`, bez którego `pg_dump` z PHP nie wystartuje, a `AGENTS.md`
zabrania osłabiać ten hardening. Dlaczego nie GitHub Actions: poświadczenie do
produkcyjnej bazy musiałoby trafić do sekretów GitHuba. Pełne uzasadnienie:
`docs/DECISIONS.md` → `## D-043`.

### 7.1 🔴 Klucz szyfrujący — gdzie mieszka i czego NIE WOLNO z nim zrobić

Zrzut to **komplet danych osobowych wszystkich kont** w jednym pliku.
Szyfrujemy go **kluczem publicznym** (CMS/PKCS#7: losowy AES-256 na plik,
klucz sesji zamknięty RSA), a nie hasłem — i ta różnica jest tu sednem, nie
detalem:

```text
klucz PUBLICZNY (certyfikat)   →  Railway, zmienna KOPIA_KLUCZ_PUBLICZNY
                                   szyfruje; NIE odszyfrowuje NICZEGO
klucz PRYWATNY                 →  menedżer haseł właściciela
                                   + nośnik offline w INNYM miejscu fizycznym
                                   NIGDY w Railwayu, NIGDY w repozytorium,
                                   NIGDY w GitHubie, NIGDY w logu
```

Skutek praktyczny: kto przejmie serwis kopii, bucket R2 albo całe konto
Railway, dostaje **szyfrogram i nic więcej**. Cena tej własności jest
symetryczna i trzeba ją znać: **utrata klucza prywatnego czyni WSZYSTKIE
kopie nieczytelnymi na zawsze.** Dlatego dwie kopie klucza, w dwóch różnych
miejscach — dokładnie tak samo, jak `APP_KEY` w tabeli §1.1.

**Wygenerowanie pary (raz, na komputerze właściciela, nie w Railwayu):**

```bash
openssl req -x509 -newkey rsa:4096 -sha256 -days 7300 -nodes \
  -keyout kuking-kopie-PRYWATNY.pem \
  -out    kuking-kopie-publiczny.pem \
  -subj   "/CN=Kuking kopia bazy"

chmod 600 kuking-kopie-PRYWATNY.pem
```

Do Railwaya (Environment → Variables → Shared Variables) wkleja się
**wyłącznie zawartość `kuking-kopie-publiczny.pem`** jako
`KOPIA_KLUCZ_PUBLICZNY`. Jeśli panel psuje wartości wieloliniowe, wolno wkleić
`base64 -w0 kuking-kopie-publiczny.pem` — skrypt przyjmuje jedno i drugie.

**Sprawdzenie, że nie pomylono plików** (część publiczna zaczyna się od
`-----BEGIN CERTIFICATE-----`, prywatna od `-----BEGIN PRIVATE KEY-----`):

```bash
head -1 kuking-kopie-publiczny.pem   # musi być CERTIFICATE
openssl x509 -in kuking-kopie-publiczny.pem -noout -fingerprint -sha256
```

Ten odcisk trafia do każdego pliku `.meta` w buckecie — po nim rozpoznasz,
którym kluczem odszyfrować dany zrzut, gdy kiedyś dojdzie do rotacji.

### 7.2 Co dokładnie ląduje w buckecie

```text
baza/kuking-20260910-021700Z.dump.cms   zrzut pg_dump (format custom), zaszyfrowany
baza/kuking-20260910-021700Z.meta       metadane, JAWNE — bez danych osobowych
```

Plik `.meta` niesie: znacznik czasu, wersję serwera i `pg_dump`, liczbę tabel
w zrzucie, rozmiary, `sha256` jawnego i zaszyfrowanego pliku, odcisk
certyfikatu oraz **sam certyfikat (część publiczną)**. Dzięki temu do
odszyfrowania wystarcza klucz prywatny — nie trzeba szukać, który to był
certyfikat.

Nazwa pliku jest źródłem prawdy o wieku kopii (nie `LastModified` obiektu):
mówi, **kiedy zrobiono zrzut**, a nie kiedy plik trafił do bucketu.

### 7.3 `[DO WYKONANIA PRZEZ WŁAŚCICIELA]` — czego kod nie mógł zrobić sam

Kod jest gotowy i przetestowany; **nie działa jeszcze nic**, bo poniższe
cztery rzeczy dzieją się w panelach, do których repozytorium nie ma dostępu.

**1. Bucket R2** (Cloudflare → R2 → Create bucket)

- nazwa np. `kuking-kopie`, region `EU`,
- **osobny bucket, nie prefiks w buckecie zdjęć.** Publiczność w R2 jest cechą
  BUCKETU, nie obiektu (audyt G-01) — zrzut całej bazy w buckecie, który
  kiedykolwiek może dostać własną domenę CDN, jest wypadkiem czekającym
  na swoją kolej,
- **bez własnej domeny, `r2.dev` WYŁĄCZONE.** Publiczny dostęp wykluczony.

**2. Dwa tokeny R2 do tego jednego bucketu** (R2 → Manage API Tokens)

| Token | Uprawnienie | Dla kogo |
|---|---|---|
| zapis | Object Read & Write, **tylko ten bucket** | serwis `kopia-bazy` |
| odczyt | Object Read only, **tylko ten bucket** | aplikacja (czujka) |

Dwa, nie jeden, i to nie jest formalizm: gdyby aplikacja miała prawo zapisu,
udany atak na nią mógłby **skasować kopie** — czyli dokładnie to, przed czym
ta warstwa ma chronić.

**3. Zmienne sharedowe w Railway** (Environment `production` → Variables →
Shared Variables). Wartości bierzesz z kroków 1-2 i z §7.1:

```text
R2_KOPIE_BUCKET                      nazwa bucketu z kroku 1
R2_KOPIE_ACCESS_KEY_ID               token ZAPISU  (dla serwisu kopii)
R2_KOPIE_SECRET_ACCESS_KEY
R2_KOPIE_ODCZYT_ACCESS_KEY_ID        token ODCZYTU (dla aplikacji)
R2_KOPIE_ODCZYT_SECRET_ACCESS_KEY
KOPIA_KLUCZ_PUBLICZNY                certyfikat z §7.1 — CZĘŚĆ PUBLICZNA
```

`R2_ENDPOINT` i `LOG_BLAD_WEBHOOK_URL` są już na tej liście z innych powodów;
serwis kopii korzysta z tych samych wartości.

**4. Serwis `kopia-bazy` w Railway**

> ## ⚠️ NIE URUCHAMIAJ DZIŚ `railway config apply`
> `.railway/railway.ts` opisuje stan DOCELOWY z trzema serwisami
> (`web`, `worker`, `scheduler`), a produkcja ma dziś JEDEN serwis o nazwie
> `kuking.pl` w trybie `all` — `apply` nie zostało uruchomione ani razu.
> Zastosowanie tego pliku dziś **skasowałoby `kuking.pl`** i postawiło trzy
> serwisy, których nikt nie skonfigurował. Serwis kopii zakłada się więc
> RĘCZNIE, a deklaracja w `railway.ts` czeka na dzień pierwszego `apply`.

Panel Railway → **+ New** → **GitHub Repo** → `woogitsu/kuking.pl`, a potem
w ustawieniach nowego serwisu:

```text
Nazwa                 kopia-bazy
Settings → Build
  Builder             Dockerfile
  Dockerfile Path     docker/kopia/Dockerfile
  Watch Paths         docker/kopia/**
Settings → Deploy
  Cron Schedule       17 2 * * *          (02:17 UTC — po nocnych retencjach)
  Restart Policy      Never               (nieudany zrzut NIE ma wstawać w pętli)
  Region              europe-west4-drams3a
Settings → Networking  BEZ domeny publicznej (to nie serwer HTTP)
Variables             DB_URL = referencja do serwisu Postgres
                      (Variable Reference → Postgres → DATABASE_URL,
                       host *.railway.internal — NIE publiczny proxy.rlwy.net)
                      KOPIA_S3_ENDPOINT      = ${{shared.R2_ENDPOINT}}
                      KOPIA_S3_BUCKET        = ${{shared.R2_KOPIE_BUCKET}}
                      KOPIA_S3_KLUCZ         = ${{shared.R2_KOPIE_ACCESS_KEY_ID}}
                      KOPIA_S3_SEKRET        = ${{shared.R2_KOPIE_SECRET_ACCESS_KEY}}
                      KOPIA_KLUCZ_PUBLICZNY  = ${{shared.KOPIA_KLUCZ_PUBLICZNY}}
                      KOPIA_WEBHOOK_URL      = ${{shared.LOG_BLAD_WEBHOOK_URL}}
                      KOPIA_SRODOWISKO       = production
```

Pozostałe `KOPIA_*` (retencja, progi) mają wartości domyślne w skrypcie
i w `railway.ts` — nie musisz ich wpisywać, dopóki nie chcesz innych.

**5. Pierwsze uruchomienie — z ręki, zanim zaufasz harmonogramowi**

Panel Railway → serwis `kopia-bazy` → **Deployments** → **Run Now**, a potem
przeczytaj log. **Nie czekaj na 02:17** — pierwszy przebieg o trzeciej nad
ranem, o którym dowiesz się z alarmu albo (gorzej) nie dowiesz się w ogóle,
to nie jest sposób na sprawdzenie czegokolwiek.

W logu ma stanąć, w tej kolejności:

```text
[kopia] baza: postgresql://…:***@postgres.railway.internal:5432/railway
[kopia] PostgreSQL: serwer 18, pg_dump 18
[kopia] tabel z danymi w zrzucie: 25          ← musi zgadzać się z docs/DATABASE.md
[kopia] szyfruję (odcisk klucza SHA-256: …)   ← porównaj z odciskiem z §7.1
[kopia] potwierdzone: … B w buckecie
[kopia] GOTOWE: baza/kuking-…-…Z.dump.cms (… B)
```

Skrypt **sam sprawdza środowisko i zgodność wersji, ZANIM dotknie bazy**,
więc brakująca zmienna albo za stary `pg_dump` kończą przebieg alarmem
i niezerowym kodem, nie połowicznym zrzutem. Jest też tryb samej walidacji
(`kopia-bazy.sh --sprawdz`, bez zrzutu) — używa go CI po zbudowaniu obrazu,
a z ręki daje się go uruchomić, wpisując tę komendę tymczasowo jako **Custom
Start Command** serwisu.

> **`railway run` NIE zadziała do tego sprawdzenia**, choć wygląda na
> najprostszą drogę: uruchamia komendę na TWOIM komputerze, tylko ze
> zmiennymi z Railwaya — a `DB_URL` wskazuje host `*.railway.internal`,
> którego spoza sieci Railwaya nie da się rozwiązać.

**6. Czujka w aplikacji** — po założeniu bucketu dopisz w Railway do serwisu
aplikacji `AWS_KOPIE_BUCKET`, `AWS_KOPIE_ACCESS_KEY_ID`,
`AWS_KOPIE_SECRET_ACCESS_KEY` (token ODCZYTU) i sprawdź:

```bash
railway ssh --service kuking.pl -- php artisan kuking:sprawdz-kopie
```

Dopóki tych zmiennych nie ma, komenda mówi wprost **„czujka jest
WYŁĄCZONA"** — i to jest ważniejsze niż jej milczenie, bo cisza z powodu
braku konfiguracji wygląda identycznie jak cisza z powodu „wszystko
w porządku".

### 7.4 Odtworzenie z tej warstwy — BEZ dostępu do aplikacji

Ta procedura **nie potrzebuje Kuking, PHP, Laravela ani konta Railway.**
Potrzebuje trzech rzeczy: klucza prywatnego z §7.1, dostępu do bucketu
i `openssl` + `pg_restore` na dowolnym komputerze.

```bash
# 1. Weź najnowszy zrzut z bucketu. Dowolny klient S3 — tu przez rclone
#    skonfigurowany na R2 (albo pobierz plik z panelu Cloudflare ręcznie).
rclone lsl r2:kuking-kopie/baza/ | sort | tail -4

# 2. Odszyfruj. TYLKO to wymaga klucza prywatnego i nic więcej.
openssl cms -decrypt -binary -inform DER \
  -in  kuking-20260910-021700Z.dump.cms \
  -inkey kuking-kopie-PRYWATNY.pem \
  -out kuking.dump

# 3. Sprawdź, że bajty są te same, co przy zrzucie (skrót jest w .meta).
sha256sum kuking.dump
grep '^sha256_jawnego:' kuking-20260910-021700Z.meta

# 4. Przeczytaj spis treści archiwum, ZANIM je gdziekolwiek wczytasz.
pg_restore --list kuking.dump | grep -c 'TABLE DATA'

# 5. Odtwórz do PUSTEJ bazy. --no-owner: role z tamtego projektu nie istnieją.
createdb kuking_odtworzona
pg_restore --dbname=kuking_odtworzona --no-owner --exit-on-error kuking.dump
```

Gdyby krok 2 zapytał o certyfikat odbiorcy, wyjmij go z `.meta` (leży tam
w całości, to część publiczna) i dodaj `-recip cert.pem`.

Wersja `pg_restore` musi być **równa albo nowsza** niż `pg_dump`, który zrobił
zrzut — numer stoi w `.meta` w polu `pg_dump`.

### 7.5 Czego ta warstwa NIE obejmuje

- **Zdjęć.** Zrzut to sama baza. Warianty i oryginały w R2 mają własne ryzyko,
  opisane w §1.1 i §3(c) — i nie mają dziś żadnej kopii.
- **`APP_KEY`.** Zrzut zawiera zaszyfrowane nim kolumny (`two_factor_secret`),
  więc bez `APP_KEY` odtworzona baza jest kompletna, ale te dwie kolumny
  pozostają nieczytelne. `APP_KEY` ma własny wiersz w tabeli §1.1.
- **Sytuacji, w której serwis kopii przestaje się uruchamiać.** Sam skrypt
  alarmuje, gdy jego przebieg się nie udał, i sprawdza wiek poprzedniej kopii
  — ale kod, który nie chodzi, nie może o sobie donieść. Dlatego czujka po
  stronie aplikacji (`kuking:sprawdz-kopie`) patrzy na to z drugiej strony,
  a §6 dokłada przegląd raz na tydzień. **Trzeciego niezależnego świadka nie
  ma** — jeśli padnie i serwis kopii, i aplikacja, milczenie będzie zupełne.

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
- `docs/DECISIONS.md` → `## D-043` — dlaczego zrzut robi osobny serwis
  Railway, a nie scheduler aplikacji ani GitHub Actions
- `docker/kopia/` — obraz i skrypt tej warstwy; `tests/skrypty/kopia-bazy.sh`
  — jego testy (wektory AWS, treść alarmu, retencja, szyfrowanie w obie strony)
- Railway — backup i restore: https://docs.railway.com/guides/postgres-backups-restores
  (**uwaga: opisane tam Volume Backups i PITR wymagają planu Pro**, więc dla
  Kuking na Free/Hobby ta strona opisuje funkcje niedostępne — D-043)
- Railway Cron (harmonogram serwisu kopii): https://docs.railway.com/cron-jobs
