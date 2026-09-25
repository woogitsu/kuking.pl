# Lista kroków panelowych do bramki zamkniętej alfy

**Dla kogo:** właściciel. **Sporządzone:** 25.09.2026, na `origin/main`
`9dddf0f0`. **Stan Railway** odczytany tego dnia przez Railway MCP (tylko
odczyt, same nazwy zmiennych, `valuesRedacted: true`):

- projekt `ideal-exploration`, **jedno** środowisko `production`, serwisy
  `kuking.pl` i `Postgres`; **brak** `staging`, **brak** `kopia-bazy`;
- **brak Shared Variables** (plik IaC odwołuje się do 41 nazw);
- domeny serwisu `kuking.pl`: tylko własna `kuking.pl` (port 8080),
  **brak** domeny `*.up.railway.app`;
- `sealedVariableNames` puste — **żaden sekret nie jest zapieczętowany**;
- **nowe od 18.09:** w serwisie `kuking.pl` jest `LOG_BLAD_WEBHOOK_URL`,
  `OPENAI_MODERATION_KEY`, `CLOUDFLARE_ZONE_ID`, `CLOUDFLARE_PURGE_TOKEN`
  (czy wartości są poprawne — z odczytu nazw nie wiadomo);
- nadal **brak**: `KUKING_R2_PUBLICZNE_ADRESY`, `KUKING_PULS_HARMONOGRAMU_URL`,
  `KUKING_HOST_USER_ID`, `KUKING_EDGE_TOKEN`, `AWS_KOPIE_*`, `R2_KOPIE_*`,
  `KOPIA_KLUCZ_PUBLICZNY`.

**Czego ten dokument NIE robi:** niczego nie zmienia w Railway, Cloudflare
ani GitHubie. Każdy krok jest stanem panelu, który trzeba wykonać i zapisać
z datą w podanym issue. Szczegóły kliknięć są w dokumentach źródłowych —
tutaj jest **kolejność, zależności, sprawdzenie i droga cofnięcia**.

Bramka z `docs/ROADMAP.md` („Closed alpha gate”): 20+ realnych osób,
stabilny upload, brak blokerów UX, moderacja działa, restore przetestowany.
Mapa kroków na te pięć warunków jest na końcu (§G).

Oznaczenia:

- **[PR #N]** — krok wymaga kodu, który jest dziś **tylko w otwartym PR-ze**;
- **[kod — brak PR]** — krok wymaga zmiany w kodzie, której nikt jeszcze nie
  przygotował;
- **Stop** — jeśli sprawdzenie nie wyszło, nie idź do kolejnego kroku.

---

## 0. Kolejność wykonania — jedna lista przez wszystkie grupy

Grupy A–F są pogrupowane tematycznie, ale kilka kroków z D i E **musi**
poprzedzić `apply`. Wykonuj w tej kolejności:

| # | Krok | Czas | Zależy od | Odblokowuje |
|---|---|---|---|---|
| 1 | A1 odczyt stanu produkcji | 15 min | — | #595 krok 0 |
| 2 | A2 2FA i dostęp do kont | 15 min | — | wszystko niżej |
| 3 | E1 próba kanału alarmów | 10 min | — | #599, alarm kopii |
| 4 | E3 rozliczenie starych `failed_jobs` | 20 min | E1 | E2, #599 |
| 5 | E2 zewnętrzny monitor dostępności | 15 min | E3 | #599, obserwacja apply |
| 6 | D1 ręczny zrzut produkcji i odtworzenie | 45 min | A1 | **warunek apply**, #594 |
| 7 | F1 umówienie prawnika (start równoległy, długi czas oczekiwania) | 30 min | — | #8, D9, C2 |
| 8 | A3 budżet i limit Railway | 10 min | — | #595 (zgoda na koszt), #599 |
| 9 | A4 decyzja o zmiennych tylko-w-panelu | 15 min (+ PR) | A1 | B2 bez „stop” |
| 10 | D2–D3 bucket kopii, tokeny, zmienne kopii | 30 min | D1 (klucz) | apply bez martwego `kopia-bazy`, #193 |
| 11 | A5 Shared Variables produkcji | 45–60 min | A1, A4, D3 | **B2**, #595, #1013 |
| 12 | A6 GitHub: zmienne, sekret, środowisko | 15 min | A1 | B3 z GitHuba |
| 13 | A7 środowisko `staging` | 60–90 min | A5 (wzór listy) | B1, C4, E5, #975 |
| 14 | A8 PR Environments (#975) | 5 min | A7 | #975 |
| 15 | E6 zrzuty metryk „przed” | 10 min | — | #599 porównanie |
| 16 | B1 próba rozbicia na stagingu | 60 min | A7 | B2 |
| 17 | B2 plan produkcji i czytanie | 30 min | A5, A4, D1, B1 | B3 |
| 18 | B3 apply produkcji | 15 min + wdrożenie | B2 | #595, #600, #599 |
| 19 | B4 weryfikacja po apply (+ odbiór #601) | 30 min | B3 | #595, #601 |
| 20 | B5 ustawienia ręczne po apply | 15 min | B3 | #595 |
| 21 | D4 pierwszy przebieg `kopia-bazy` | 20 min | B3, D3 | #193 |
| 22 | E4 puls harmonogramu | 20 min | B3 | #599 |
| 23 | B6 odczyt budżetu połączeń | 15 min | B3 | #598, #600 |
| 24 | C1 przegląd strefy Cloudflare (odczyt) | 20 min | — | C3–C6 |
| 25 | C2 ustawienia bucketów R2 | 30 min | C1 | C3, #120, #619 |
| 26 | C3 bramka R2 na produkcji + punkty ręczne na stagingu | 75 min | C2, B3, A7 | **#120**, stabilny upload |
| 27 | D5 nazajutrz: czujka kopii | 5 min | D4 + 1 noc | #193 |
| 28 | D6 odtworzenie z bucketu, RPO/RTO | 45 min | D5 | **#193, #594** |
| 29 | E5 próby alarmów na stagingu | 40 min | A7, E2, E4 | #599 |
| 30 | F2 moderacja działa | 20 min | B3 | bramka „moderation działa” |
| 31 | F3 testy 50+ (13 sesji) | 2+ tygodnie | A7 albo produkcja, E1, D1 | **#15**, #119 |
| 32 | F4 pierwsze 20 osób | tygodnie | F3 bez blokerów, D6, C3 | **#29** |
| 33 | C4 cache zdjęć (#597) | 45 min | C1, A7 | #597 |
| 34 | C6 token krawędzi (#1306) | 30 min + 1 doba | B3, C1 | #1306 |
| 35 | C5 cache HTML (#610) | 45 min | C4 | #610 |
| 36 | D7–D9 kopia zdjęć, PITR, HA | — | F1 (zdanie w polityce) | #617, #604 |

Kroki 33–36 **nie blokują** zamkniętej alfy (P1/P2). Kroki 1–32 składają
się na bramkę.

---

## A. Przed `railway config apply`

### A1. Odczyt stanu produkcji (niczego nie zmienia)

- **Gdzie:** Railway → projekt `ideal-exploration` → `production`.
  Serwis `kuking.pl` → *Settings* i *Variables*; serwis `Postgres` →
  *Settings* i *Backups*.
- **Co zapisać (poza repo):** dokładne nazwy serwisów (wielkość liter),
  region obu serwisów (plik: `europe-west4-drams3a`), Start Command
  (oczekiwane `… kuking-entrypoint all`), limity CPU/RAM, healthcheck,
  **czy „Wait for CI” jest włączone**, nazwy zmiennych. Na zakładce
  *Backups* dosłowny komunikat (17.09: „only available for customers on the
  Pro plan”).
- **Sprawdzenie:** nazwy `kuking.pl` i `Postgres` zgadzają się
  z `NAZWA_SERWISU_WWW` i `NAZWA_BAZY` w `.railway/railway.ts`. Inaczej —
  **stop**, PR poprawiający nazwy (opis w `PRZELACZENIE_NA_3_SERWISY_595.md`
  krok 0.2).
- **Czas:** 15 min. **Odblokowuje:** #595 (krok 0), #594 (pole P1 karty DR).
- **Ryzyko / cofnięcie:** brak — sam odczyt.

### A2. 2FA i dostęp do kont

- **Gdzie:** GitHub, Railway, Cloudflare, EmailLabs, OpenAI → ustawienia
  bezpieczeństwa konta; Railway → *Workspace → Members*; Cloudflare →
  *Manage Account → Members*.
- **Co ustawić:** 2FA wszędzie; lista członków bez osób zbędnych.
- **Sprawdzenie:** każde konto pokazuje 2FA włączone.
- **Czas:** 15 min. **Odblokowuje:** bezpieczeństwo wszystkich kroków
  (konto Cloudflare kontroluje DNS całej domeny — `DEPLOYMENT_RUNBOOK.md` KROK 0).
- **Ryzyko / cofnięcie:** utrata telefonu = blokada konta; zapisz kody
  zapasowe w menedżerze haseł.

### A3. Budżet i limit wydatków Railway

- **Gdzie:** Railway → *Workspace Settings → Usage → Usage Limits*.
- **Co ustawić:** soft limit (e-mail) i hard limit. **Decyzja właściciela:**
  rozbicie na trzy serwisy to szacunkowo 40–65 USD/mies. zamiast 12–18
  (komentarz w `railway.ts`, #595). Wartości 25/60 USD z `DEPLOYMENT_RUNBOOK.md`
  §12 są **sprzed rozbicia** — hard limit 60 USD mógłby wyłączyć produkcję
  w zwykłym miesiącu. Hard limit ustaw z zapasem nad nowym szacunkiem.
- **Sprawdzenie:** panel pokazuje oba progi i adres e-mail powiadomień.
- **Czas:** 10 min. **Odblokowuje:** #595 (zgoda na koszt), #599 (budżet
  kosztów). **Ryzyko / cofnięcie:** hard limit **zatrzymuje serwisy** —
  cofnięcie: podnieś limit w tym samym miejscu.

### A4. Zmienne ustawione tylko w panelu — decyzja przed planem

`railway config apply` ustawia zestaw zmiennych z pliku. Zmienna, która
stoi dziś w serwisie, a nie ma jej w `railway.ts`, pojawi się w planie jako
**usunięcie** (w `PRZELACZENIE_NA_3_SERWISY_595.md` krok 2 to „stop”).
Odczyt z 25.09 pokazuje trzy takie nazwy:

| Zmienna | Co się stanie po apply | Decyzja |
|---|---|---|
| `KUKING_QUESTIONS_ENABLED` | wróci do domyślnego `false` (`config/kuking.php`) — pytania „Poradźcie” znikną, jeśli dziś są włączone | odczytaj wartość w panelu; jeśli `true` → **[kod — brak PR]** dopisać do `appEnv` w `railway.ts` przed B2 |
| `KUKING_MEDIA_DISK` | wraca do `FILESYSTEM_DISK`, które plik ustawia na `r2` | jeśli dziś `r2` — usunięcie jest bez skutku, zapisz to świadomie; inna wartość → **stop**, wyjaśnić |
| `TRUSTED_PROXIES` | znika | **usunięcie zamierzone** (komentarz SEC-01 w `railway.ts`: aplikacja nigdy jej nie czytała) |

- **Gdzie:** Railway → `kuking.pl` → *Variables* (wartości są dziś widoczne,
  bo nic nie jest zapieczętowane).
- **Czas:** 15 min, plus ewentualny PR. **Odblokowuje:** B2 bez „stop”.
- **Ryzyko / cofnięcie:** decyzja jest odwracalna — zmienną można po apply
  wpisać ręcznie, ale następny apply znów ją usunie, dopóki nie trafi do pliku.

### A5. Shared Variables środowiska `production`

To jest krok, od którego zależy, czy po apply zdjęcia, poczta i logowanie
dalej działają. `ctx.shared.X` w `railway.ts` **odwołuje się** do zmiennej,
nie tworzy jej; brak nazwy po apply = pusta wartość w nowych serwisach.

- **Gdzie:** Railway → `production` → *Variables* → *Shared Variables* →
  *New Shared Variable*.
- **Najpierw:** skopiuj obecne wartości z serwisu `kuking.pl` do menedżera
  haseł. Pieczęć (*Sealed*) jest nieodwracalna — po niej wartości nie da się
  odczytać nikomu, także Tobie.
- **Pełna lista nazw** (41) — z pliku, nie z pamięci:

  ```bash
  node --experimental-strip-types --no-warnings scripts/railway/iac-graf.mjs production --wspoldzielone
  ```

- **Co wpisać:**

  | Shared Variable | Skąd wartość |
  |---|---|
  | `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_PUBLIC_BUCKET`, `R2_EXPORTS_BUCKET`, `R2_ENDPOINT` | dzisiejsze `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_PUBLIC_BUCKET`, `AWS_EXPORTS_BUCKET`, `AWS_ENDPOINT` w `kuking.pl`. **`R2_ENDPOINT` musi mieć kształt `https://<32 hex>.eu.r2.cloudflarestorage.com`** (D-255) — inny kształt wyłącza dyski R2 |
  | `APP_KEY`, `APP_PREVIOUS_KEYS`, `CLOUDFLARE_ANALYTICS_TOKEN`, `CLOUDFLARE_PURGE_TOKEN`, `CLOUDFLARE_ZONE_ID`, `EMAILLABS_APP_KEY`, `EMAILLABS_SECRET_KEY`, `EMAILLABS_SMTP_ACCOUNT`, `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `KUKING_MODEL_ALARM_EMAIL`, `LOG_BLAD_WEBHOOK_URL`, `OPENAI_MODERATION_KEY`, `TURNSTILE_SECRET_KEY`, `TURNSTILE_SITE_KEY` | ta sama nazwa w `kuking.pl` |
  | `KUKING_HOST_USER_ID` | UUID konta gospodarza w bazie produkcji (`docs/DEPLOYMENT.md`, „Konto gospodarza”); nieznany → **pusta** |
  | `R2_KOPIE_BUCKET`, `R2_KOPIE_ACCESS_KEY_ID`, `R2_KOPIE_SECRET_ACCESS_KEY`, `R2_KOPIE_ODCZYT_ACCESS_KEY_ID`, `R2_KOPIE_ODCZYT_SECRET_ACCESS_KEY`, `KOPIA_KLUCZ_PUBLICZNY` | z kroku D3; jeśli D3 jeszcze nie zrobione → **puste** (wtedy patrz B2, wiersz `kopia-bazy`) |
  | `KUKING_PULS_HARMONOGRAMU_URL`, `KUKING_EDGE_TOKEN`, `KUKING_EDGE_TOKEN_POPRZEDNI`, `R2_ZDJECIA_KOPIA_BUCKET`, `R2_ZDJECIA_KOPIA_ODCZYT_ACCESS_KEY_ID`, `R2_ZDJECIA_KOPIA_ODCZYT_SECRET_ACCESS_KEY` | **puste** — wypełniają je E4, C6 i D7 |
  | `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | **puste** — plan Hobby blokuje SMTP (D-116) |

- **Pieczęć:** zaznacz *Sealed* przy sekretach (klucze, sekrety, `APP_KEY`,
  `APP_PREVIOUS_KEYS`, adres webhooka i adres pulsu). Nazwy bucketów, identyfikatory
  klientów OAuth, `TURNSTILE_SITE_KEY` i `CLOUDFLARE_ZONE_ID` nie są sekretami.
- **Sprawdzenie:** liczba i nazwy w panelu = wynik komendy wyżej. Porównanie
  wartości: dla każdej pary z pierwszego wiersza tabeli — ta sama wartość
  co w serwisie (przed pieczęcią).
- **Czas:** 45–60 min. **Odblokowuje:** B2, #595 (krok 0.4), #1013.
- **Ryzyko:** literówka albo pominięta nazwa — po apply puste
  `AWS_*` = zdjęcia przestają się wgrywać. **Cofnięcie:** do chwili apply
  Shared Variables nie działają na nic (serwis ma własne zmienne), więc można
  je poprawiać do woli; po apply — popraw wartość i *Redeploy*.

### A6. GitHub — tylko jeśli apply ma iść z Actions

- **Gdzie:** GitHub → repo → *Settings → Secrets and variables → Actions*
  oraz *Settings → Environments → production*.
- **Co ustawić:** zmienna `KUKING_WAIT_FOR_CI` = `true`, jeśli A1 pokazał
  włączone „Wait for CI” (bez niej plan **wyłączy** tę bramkę); zmienna
  `KUKING_DEPLOY_ENABLED` = `true`; sekret `RAILWAY_TOKEN_PRODUCTION`;
  w środowisku `production` *Required reviewers* = właściciel.
- **Sprawdzenie:** Actions → *Railway IaC* pokazuje przycisk *Run workflow*,
  job czeka na zatwierdzenie.
- **Kod w otwartych PR-ach, który warto scalić przed apply z GitHuba:**
  **[PR #1595]** (plan odmawia bez jawnego `KUKING_WAIT_FOR_CI`),
  **[PR #1624]** (plan/apply produkcji tylko dla PR-a do `main`),
  **[PR #1697]** (recenzent także przed planem z PR-a),
  **[PR #1707]** (przypięta wersja `@railway/cli`, token tylko w krokach,
  które go używają). Apply lokalny z CLI ich nie wymaga.
- **Czas:** 15 min. **Odblokowuje:** B3 z GitHuba.
- **Ryzyko / cofnięcie:** `KUKING_DEPLOY_ENABLED=false` wyłącza wszystkie
  automatyczne operacje; sekret można usunąć.

### A7. Środowisko `staging`

- **Gdzie:** Railway → wybierak środowisk → *New Environment* → `staging`,
  **puste, nie „Duplicate production”**. (`DEPLOYMENT_RUNBOOK.md` §8 każe
  duplikować — to jest sprzeczne z `PRZELACZENIE_NA_3_SERWISY_595.md`
  krok 1 i z #975: duplikat kopiuje sekrety produkcji. Wiąże nowszy
  dokument.)
- **Co ustawić:** Shared Variables stagingu z tą samą listą nazw co A5, ale
  **własne wartości**: drugi `APP_KEY`, buckety `kuking-oryginaly-staging`,
  `kuking-media-staging`, `kuking-eksporty-staging` z osobnym tokenem R2
  (Cloudflare → R2 → *Create bucket*, jurysdykcja **EU**; *Manage API
  Tokens* → token tylko na te trzy buckety), pozostałe sekrety puste albo
  testowe — **nigdy produkcyjne**. Gałąź `staging` w repo. Po pierwszym
  apply stagingu (B1): *Custom Domain* `staging.kuking.pl` na serwisie WWW
  i w Cloudflare DNS rekord CNAME (proxied) + TXT `_railway.staging`.
  *Serverless* ON dla WWW stagingu.
- **Sprawdzenie:** `curl -s https://staging.kuking.pl/health` → 200;
  w Railway środowisko ma własny `Postgres`.
- **Czas:** 60–90 min. **Odblokowuje:** B1, C3 (punkty 7–12), C4, C5, E1
  (próba bez produkcji), E5, #975 (baza PR-ów), F3 (jeśli testy 50+ mają
  iść na stagingu).
- **Ryzyko:** koszt (Serverless go ogranicza); pomyłka w bucketach =
  staging pisze do produkcji. **Cofnięcie:** usunięcie środowiska
  `staging` w *Project Settings → Environments* (nie dotyka produkcji).
- **Jeśli świadomie pomijasz staging:** zapisz to w #595 z datą. Wtedy B1,
  C4 i E5 nie mają gdzie się odbyć przed produkcją.

### A8. PR Environments (#975)

- **Gdzie:** Railway → *Project Settings → Environments*.
- **Co ustawić:** *Enable PR Environments* ON, **Base environment:
  `staging`** (nie `production`), *Bot PR Environments* OFF.
- **Sprawdzenie:** najbliższy PR dostaje środowisko `pr-N`; w logach
  entrypointu klucz preview nadany lokalnie (`docker/klucz-preview.sh`),
  nie klucz stagingu. **Nie zdejmuj pieczęci z `APP_KEY` stagingu**, żeby
  „naprawić” preview.
- **Czas:** 5 min. **Zależy od:** A7. **Odblokowuje:** #975 (kod jest na
  `main`; test dymny czekający na status wdrożenia — **[PR #1595]**).
- **Ryzyko / cofnięcie:** przełącznik OFF.

---

## B. Apply i rozdział web / worker / scheduler

Procedura źródłowa: `docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md`. Warunki:
A1–A5 zrobione, **D1 zrobione** (żadnego apply bez kopii bazy —
`DEPLOYMENT_RUNBOOK.md` KROK 9), E1–E2 zrobione (awaria w trakcie apply
ma kogoś obudzić), okno małego ruchu, CLI ≥ 5.42.1.

### B1. Próba na stagingu

- **Gdzie:** terminal, `railway link` → `staging`.
- **Co:** `KUKING_IAC_STAGING_ROZBITY=true railway config plan`, potem
  `… apply` interaktywnie, bez `--yes`.
- **Sprawdzenie:** weryfikacja jak w B4 na stagingu: wgranie zdjęcia,
  harmonogram w logach schedulera, restart workera w trakcie zadania.
- **Czas:** 60 min. **Odblokowuje:** B2.
- **Cofnięcie:** plan/apply **bez** zmiennej — usuwa `worker`
  i `scheduler` tylko na stagingu.

### B2. Plan produkcji i jego czytanie

- **Gdzie:** terminal, `railway link` → `production`;
  `KUKING_WAIT_FOR_CI=true railway config plan` (zmienna tylko, jeśli A1
  pokazał włączone „Wait for CI”).
- **Oczekiwane:** `kuking.pl` — zmiana w miejscu na rolę `web`;
  `worker`, `scheduler` — utworzenie; `Postgres` — bez zmian; domeny — bez
  zmian; `kopia-bazy` — utworzenie; usunięcia zmiennych — tylko te
  zaakceptowane w A4.
- **Stop, gdy:** utworzenie/usunięcie `kuking.pl` albo `Postgres`, zmiana
  obrazu/regionu/wolumenu bazy, zmiana domen, usunięcie zmiennej spoza A4,
  wyłączenie `checkSuites`. Wiersz `kopia-bazy` bez wypełnionych `R2_KOPIE_*`
  = nocny błąd z alarmem, nie awaria strony — lepiej najpierw D2–D3.
- **Czas:** 30 min. Plan zapisz poza repo. **Odblokowuje:** B3.
- **Ryzyko / cofnięcie:** plan niczego nie zmienia.

### B3. Apply produkcji

- **Gdzie:** jedna z dróg, nie obie: lokalnie
  `KUKING_WAIT_FOR_CI=true railway config apply` (bez `--yes`
  i `--confirm-destructive`; prośba o zgodę destrukcyjną → „nie”) albo
  GitHub → Actions → *Railway IaC* → *Run workflow* na `main`, potwierdzenie
  `STOSUJE PLAN PRODUKCJI`, pole zmian destrukcyjnych **niezaznaczone**.
- **Co się dzieje:** `kuking.pl` wdraża się w roli `web` (migracje
  w pre-deploy), `worker` i `scheduler` startują z tego samego obrazu,
  powstaje `kopia-bazy`. Przez ok. 30 s stary kontener `all` może jeszcze
  pracować równolegle — zadania harmonogramu mają `onOneServer()`, kolejka
  blokuje wiersze.
- **Czas:** 15 min + wdrożenie. **Odblokowuje:** #595, a pośrednio #598,
  #599 (metryki per usługa), #600, #193 (serwis kopii), #1306 (token trafia
  tylko do WWW).
- **Cofnięcie A (minuty, panel):** `kuking.pl` → Start Command
  `/usr/local/bin/kuking-entrypoint all`, `APP_ROLE=all`, *Deploy*;
  poczekaj na `tryb ALL` w logach; **dopiero potem** usuń `worker`
  i `scheduler`. **Cofnięcie B (trwałe):** PR z
  `PRODUCTION_SPLIT_SERVICES = false` i apply z zgodą na usunięcie tylko tych
  dwóch serwisów. Dane i schemat są nietknięte w obu drogach.

### B4. Weryfikacja w ciągu 15 minut (+ odbiór #601)

1. Trzy serwisy aplikacji *Active*, to samo SHA co `main`.
2. Pierwsza linia logu: `rola=web`, `rola=worker`, `rola=scheduler`; nigdzie
   `tryb ALL`.
3. `curl -s -o /dev/null -w '%{http_code}\n' https://kuking.pl/health` → `200`.
4. `bash scripts/sprawdz-wdrozenie.sh kuking.pl`.
5. **Wgraj zdjęcie z konta testowego** — warianty w ok. minutę, wpis
   przetworzenia w logach **workera**. To jest też brakujący odbiór
   produkcyjnego media joba z **#601** (jedno dekodowanie z #625): zapisz
   w #601 datę, czas przetworzenia i pamięć workera z *Metrics*.
6. Po minucie `schedule:run` w logach schedulera, w `kuking.pl` żadnego.
7. `railway ssh --service worker -- php artisan kuking:sprawdz-kolejke` —
   zaległość nie rośnie przez 10 minut.
8. Pamięć workera przy zdjęciu poniżej 1024 MB.
9. Wynik do #595.

- **Czas:** 30 min. **Odblokowuje:** zamknięcie #595, #601; warunek
  „stabilny upload”.
- **Ryzyko / cofnięcie:** jak B3.

### B5. Ustawienia ręczne po apply

- **Gdzie:** każdy nowy serwis → *Settings*.
- **Co:** „Wait for CI” ON na trzech serwisach (jeśli było w A1);
  **Pre-deploy Timeout 600 s** na `kuking.pl` (pole pojawia się dopiero przy
  komendzie pre-deploy — `DEPLOYMENT_RUNBOOK.md` §12); `scheduler` —
  1 replika, nigdy więcej.
- **Znane braki po apply:** `.github/workflows/deploy.yml` (job `operate`)
  restartuje tylko `kuking.pl` — **[kod — brak PR]**; osobne procesy na
  kolejkę w roli `worker` (żeby eksport nie blokował maili) —
  **[PR #1622]**, z otwartym pytaniem, czy 1024 MB starczy na trzy procesy.
- **Czas:** 15 min. **Ryzyko / cofnięcie:** przełączniki w panelu.

### B6. Budżet połączeń po apply (#598) i decyzje odłożone (#600, #604)

- **Co:** `railway ssh --service kuking.pl -- php artisan kuking:budzet-polaczen`
  przed i w trakcie najbliższego wdrożenia; szereg czasowy w *Logs*, filtr
  `kuking:budzet-polaczen` (kanał `pomiary` ma poziom `info` niezależnie od
  `LOG_LEVEL=warning`). Wynik z datą do `docs/DATABASE.md` §598 i do #598.
- **Nie przed alfą:** druga replika WWW, PgBouncer, osobny worker `media`
  (#600) i Postgres HA (#604) — decyzje z pomiarów, nie z liczby osób. Na alfę
  wystarczy jawny zapis w #600: „jeszcze niepotrzebne, liczby z B6”.
- **Czas:** 15 min. **Odblokowuje:** #598, warunki #600.

---

## C. Cloudflare i R2

### C1. Przegląd strefy (odczyt)

- **Gdzie:** dash.cloudflare.com → strefa `kuking.pl`: *DNS → Records*,
  *SSL/TLS → Overview* i *Edge Certificates*, *Rules → Redirect Rules*,
  *Caching → Cache Rules* i *Configuration*, *Rules → Page Rules*,
  *Workers Routes*, *Rules → Transform Rules*, *Security → Bots*.
- **Co zapisać:** `kuking.pl` i `www` jako *Proxied*; SSL **Full (strict)**,
  Always Use HTTPS ON; przekierowanie `www` → apex (w Railway nie ma domeny
  `www`); każda reguła cache/„Cache Everything”, Worker albo transformacja
  nagłówków odpowiedzi (reguła omijająca Policy dla `/zdjecia/*` = **stop**,
  D-020); stan „Always Online”, „Serve stale content”, Bot Fight Mode.
- **Czas:** 20 min. **Odblokowuje:** C3–C6, #1306 (inwentaryzacja drogi
  ruchu). **Ryzyko:** brak — odczyt.

### C2. Ustawienia bucketów R2

- **Gdzie:** Cloudflare → R2 → każdy bucket (oryginały, warianty, eksporty,
  ewentualny stary bucket) → *Settings*; R2 → *Manage API Tokens*.
- **Co ustawić / zapisać:** `r2.dev` **Disabled**, **brak** Custom Domains
  (przed wyłączeniem przepisz adresy `pub-….r2.dev` i domeny — są potrzebne
  w C3); typ lokalizacji — jurysdykcja **`eu`** (#619; potwierdzenie
  właściciela z 25.09 zapisuje **[PR #1681]**); istnienie Bucket Lock
  i lifecycle na żywych bucketach (tylko odczyt, #617 krok 1); zakres
  tokenów. Dziś jeden token ma zapis i kasowanie we wszystkich bucketach
  zdjęć (#617) — zapisz to jako znane ryzyko; zawężenie to osobna praca.
- **Sprawdzenie:** tabela `docs/infra/BRAMKA_R2.md` §3, punkty 4 i 13,
  wypełniona z datą.
- **Czas:** 30 min. **Odblokowuje:** C3, #120, #619.
- **Ryzyko / cofnięcie:** wyłączenie `r2.dev` nie psuje aplikacji (zdjęcia
  idą przez trasę `/zdjecia/…` i podpisane adresy). Włączenie z powrotem —
  ten sam przełącznik, ale nie rób tego.

### C3. Bramka R2 (#120)

- **Gdzie:** Railway → `kuking.pl` → *Variables* + terminal.
- **Co ustawić:** `KUKING_R2_PUBLICZNE_ADRESY` = **wszystkie** adresy z C2,
  z `https://`, także wyłączone. Uwaga: tej zmiennej nie ma w `railway.ts` —
  kolejny apply zaproponuje jej usunięcie. Ustaw ją **tymczasowo** na
  czas bramki i usuń po zapisaniu wyniku, albo dopisz do pliku
  (**[kod — brak PR]**).
- **Co uruchomić:** upewnij się, że jest gotowe zdjęcie (`media.status =
  ready`), potem
  `railway ssh --service kuking.pl -- php artisan kuking:bramka-r2 --zapis`.
- **Sprawdzenie:** ostatnia linia „Część serwerowa bramki PRZESZŁA
  w całości”, kod 0, po jednej linii z kodem odpowiedzi na każdy
  zadeklarowany adres. Wynik z datą do `BRAMKA_R2.md` §3. Punkty 7, 8, 11, 12
  (plik ~14,9 MB, cztery prawdziwe formaty, kasowanie zabiera warianty, zły
  sekret) — **na stagingu**, nie na produkcji.
- **Czas:** 45 min produkcja + 30 min staging. **Odblokowuje:** #120,
  warunek „stabilny upload”.
- **Ryzyko / cofnięcie:** komenda z `--zapis` zapisuje i kasuje jeden obiekt
  próbny; zmienna jest czytana tylko przez bramkę. Usunięcie zmiennej =
  redeploy.

### C4. Cache przekierowań zdjęć (#597) — nie blokuje alfy

- **Gdzie:** Cloudflare → *Caching → Cache Rules*. Wyrażenia: wiąże
  `docs/infra/cloudflare-cache-rules-597-610.json`, kroki:
  `CLOUDFLARE_CACHE_597_610.md` „Krok po kroku w panelu — zdjęcia”.
- **Kolejność:** (1) reguła ochronna BYPASS — od razu, ma być **ostatnia**;
  (2) reguła zdjęć jako Draft; (3) staging (`staging.kuking.pl`
  w wyrażeniach), sonda `CACHE_KIND=media`; (4) produkcja, dwa `curl`
  z filtrem `cf-cache-status|cache-control|set-cookie` (bez `location`).
- **Sprawdzenie:** drugie anonimowe pobranie `HIT`, zalogowany
  `BYPASS`/`DYNAMIC` z `private, no-store`, 404 dla anonima na prywatnym
  zdjęciu, brak `Set-Cookie`.
- **Czas:** 45 min. **Zależy od:** C1, A7. **Odblokowuje:** #597.
- **Cofnięcie:** wyłącz regułę zdjęć → *Purge Cache* prefiksem
  `kuking.pl/zdjecia/`; BYPASS zostaw. Szybsze odcięcie podpisów:
  `KUKING_MEDIA_PUBLIC_SIGNED_URL_MINUTES=5` + redeploy.

### C5. Cache HTML gościa (#610) — nie blokuje alfy

- **Co:** `KUKING_HTML_EDGE_CACHE_SECONDS=120` najpierw na stagingu, reguła
  „HTML gościa” przed końcowym BYPASS, sonda `CACHE_KIND=html`, potem
  produkcja. Okno nieświeżości 120 s wymaga świadomej akceptacji
  (`CLOUDFLARE_CACHE_597_610.md` §#610).
- **Zależność od IaC:** zmiennej nie ma w `railway.ts` — kolejny apply ją
  usunie (bezpieczny kierunek: cache HTML się wyłącza). Trwale:
  **[kod — brak PR]**.
- **Czas:** 45 min. **Zależy od:** C4. **Odblokowuje:** #610.
- **Cofnięcie:** wyłącz regułę → purge → zmienna `0` + redeploy.

### C6. Token krawędzi (#1306) — nie blokuje alfy

Kod trybu obserwacji jest na `main` (`TokenKrawedzi`,
`NormalizeForwardedFor`), `railway.ts` przekazuje `KUKING_EDGE_TOKEN`
i `_POPRZEDNI` do WWW. Kolejność z `docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md`
§4 Blok B jest krytyczna:

1. `openssl rand -hex 32` → Shared Variable `KUKING_EDGE_TOKEN` (Sealed),
   redeploy. Tryb domyślny `obserwacja` — brak tokenu tylko loguje.
2. Cloudflare → *Rules → Transform Rules → Modify Request Header* →
   ustaw `X-Kuking-Edge-Token` na **wszystkich** żądaniach do `kuking.pl`
   (inaczej monitor `/health` dostanie 403 po włączeniu egzekwowania).
3. Doba obserwacji: logi bez ostrzeżeń o brakującym tokenie.
4. Tryb `egzekwowanie`: `KUKING_EDGE_TRYB` nie jest przekazywane przez
   `railway.ts` — **[kod — brak PR]**.

- **Sprawdzenie:** Railway nie ma domeny `*.up.railway.app` (odczyt 25.09:
  `serviceDomains` puste) — zapisz to w #1306 jako potwierdzenie, a nie
  założenie. Czy brzeg Railway przyjmie żądanie z `Host: kuking.pl`
  z pominięciem Cloudflare, z odczytu nie wynika — po to jest token.
- **Czas:** 30 min + doba. **Zależy od:** B3, C1. **Odblokowuje:** #1306.
- **Cofnięcie:** do kroku 3 — usuń regułę i zmienną (obserwacja niczego nie
  blokuje). Po egzekwowaniu: najpierw tryb `obserwacja`, potem reszta.

---

## D. Kopie i restore drill

### D1. Ręczny zrzut produkcji i odtworzenie — **przed apply**

- **Gdzie:** własny komputer (WSL), karta
  `docs/infra/DR594_PIERWSZY_ZRZUT_WLASCICIEL.md`, kroki 0–7.
- **Co:** para kluczy (klucz prywatny do menedżera haseł i na nośnik
  offline, **nigdy** do Railway), tunel `railway connect postgres
  --tunnel-only`, `scripts/kopia-lokalna.sh` z szyfrowaniem, zamknięcie
  tunelu, odtworzenie na świeżym lokalnym klastrze, kontrola ujemna,
  protokół.
- **Sprawdzenie:** `KOPIA POWSTAŁA`, odtworzenie z kodem 0, wypełniony
  protokół; wiersz w `KOPIE_I_ODTWORZENIE.md` §5.
- **Czas:** 45 min. **Odblokowuje:** warunek B2/B3, #594 (5 z 6 pól),
  bramka „restore przetestowany” (część ręczna).
- **Ryzyko:** zrzut to komplet danych osobowych na dysku — dlatego
  szyfrowanie od razu i katalog poza repo. **Cofnięcie:** skasowanie
  katalogu `$DR` po zakończeniu (krok 7 karty).
- **Granica:** odtworzenie starszej kopii przywraca konta wymazane po jej
  dacie. Dziennik wymazań i `kuking:wymaz-ponownie` to **[PR #1719]** — do
  jego scalenia odtworzona baza zostaje wyłącznie próbą i nie wraca do
  ruchu.

### D2. Bucket kopii i dwa tokeny

- **Gdzie:** Cloudflare → R2 → *Create bucket* (np. `kuking-kopie`,
  jurysdykcja EU, bez domeny, `r2.dev` wyłączone) → *Manage API Tokens*:
  token **zapisu** (Object Read & Write, tylko ten bucket) i token
  **odczytu** (Object Read, tylko ten bucket).
- **Sprawdzenie:** bucket widoczny, dwa tokeny ograniczone do niego.
- **Czas:** 20 min. **Źródło:** `KOPIE_I_ODTWORZENIE.md` §7.3 pkt 1–2.
- **Ryzyko / cofnięcie:** usunięcie tokenu/bucketu w panelu.

### D3. Zmienne kopii

- **Gdzie:** Railway → `production` → *Shared Variables*.
- **Co:** `R2_KOPIE_BUCKET`, `R2_KOPIE_ACCESS_KEY_ID`,
  `R2_KOPIE_SECRET_ACCESS_KEY` (token zapisu), `R2_KOPIE_ODCZYT_ACCESS_KEY_ID`,
  `R2_KOPIE_ODCZYT_SECRET_ACCESS_KEY` (token odczytu), `KOPIA_KLUCZ_PUBLICZNY`
  (certyfikat z D1 — **część publiczna**; skrypt odmawia pracy, gdy znajdzie
  w niej klucz prywatny). Sealed poza nazwą bucketu.
- **Czas:** 10 min. **Najlepiej przed B2**, żeby apply utworzył
  `kopia-bazy` z kompletem zmiennych.
- **Cofnięcie:** edycja/usunięcie zmiennych.

### D4. Pierwszy przebieg `kopia-bazy`

- **Gdzie:** Railway → `kopia-bazy` (utworzony przez B3; jeśli apply
  jeszcze nie było — ręcznie według §7.3 pkt 4, **dokładnie pod tą nazwą**,
  żeby plan pokazał „bez zmian”) → *Deployments* → *Run Now*.
- **Sprawdzenie:** w logu `serwer 18, pg_dump 18`, liczba tabel, odcisk
  klucza zgodny z D1, `potwierdzone: … B w buckecie`, `GOTOWE`.
- **Czas:** 20 min. **Odblokowuje:** #193.
- **Ryzyko / cofnięcie:** restart policy `Never` — nieudany zrzut nie wstaje
  w pętli. Wyłączenie: usunięcie Cron Schedule.

### D5. Nazajutrz — czujka kopii

- **Co:** `railway ssh --service scheduler -- php artisan kuking:sprawdz-kopie`
  (zmienne `AWS_KOPIE_*` dostaje tylko scheduler).
- **Sprawdzenie:** czujka **nie** mówi „WYŁĄCZONA” i widzi kopię z nocy
  (cron `17 2 * * *`).
- **Czas:** 5 min. **Odblokowuje:** #193 („backup działa automatycznie”).

### D6. Odtworzenie z bucketu, RPO/RTO

- **Gdzie:** własny komputer, `KOPIE_I_ODTWORZENIE.md` §4A.
- **Co:** pobranie kopii tokenem odczytu, odszyfrowanie kluczem prywatnym,
  `scripts/proba-odtworzenia.sh` na izolowanej bazie, wiersz w §5 z RPO
  (wiek kopii) i RTO (pełny czas od pobrania).
- **Czas:** 45 min. **Odblokowuje:** zamknięcie **#193** i **#594**; bramka
  „restore przetestowany”.
- **Granica:** jak w D1 — **[PR #1719]**.

### D7. Kopia zdjęć (#617) — nie blokuje alfy, zależy od prawnika

- **Gdzie:** `docs/infra/DR_ZDJEC_R2.md` §4, kroki 1–11 (bucket
  `kuking-zdjecia-kopia` EU, rygiel 30 dni tylko na buckecie kopii, lifecycle
  31 dni, trzy tokeny, `R2_ZDJECIA_KOPIA_*` w Shared Variables).
- **Warunek:** zdanie w polityce prywatności o kopii technicznej do 32 dni
  (F1). Bez niego kroki 4–5 nie mają prawa ruszyć. Rygiel na **żywym**
  buckecie oryginałów jest wykluczony (blokowałby usunięcie danych).
- **Jeśli odkładasz:** zapisz w #617 wariant C (świadoma akceptacja ryzyka)
  z datą powrotu do decyzji.
- **Odblokowuje:** #617.

### D8. Backups/PITR Railway i HA (#604) — tylko odczyt kosztu

- **Gdzie:** Railway → `Postgres` → *Backups*; cennik planu Pro.
- **Co:** zapisać, czy przejście na Pro (Volume Backups, PITR, HA) jest
  uzasadnione kosztem. Nie jest warunkiem alfy — offsite `pg_dump` (D2–D6)
  jest warstwą niezależną od dostawcy i zostaje także po przejściu na Pro.
- **Odblokowuje:** #604 (część „koszt”), #594 („wiadomo, czy aktywne”).

---

## E. Monitoring i alarmy

### E1. Próba kanału alarmów

- **Stan:** `LOG_BLAD_WEBHOOK_URL` **jest** od niedawna w serwisie
  `kuking.pl` (odczyt 25.09). Czy dochodzi — nie sprawdzono.
- **Co:** `railway ssh --service kuking.pl -- php artisan kuking:sprawdz-alarm`
  (wysyła jedną oznaczoną wiadomość próbną, nie zapisuje pamięci
  wyciszania, nie wypisuje adresu webhooka).
- **Sprawdzenie:** kod 0 i wiadomość na kanale. Kod 1 = odbiornik odrzucił
  albo zmiennej brak — komenda mówi, co zrobić. Zdarzenie niedoręczenia
  trafia dziś do lokalnego pliku kontenera, nie do Railway Logs (#599,
  komentarz z 21.09).
- **Czas:** 10 min. **Odblokowuje:** #599, alarm kopii (D4 korzysta z tego
  samego adresu), wiersz 18 w `docs/OTWARCIE.md`.
- **Ryzyko / cofnięcie:** brak — jedna wiadomość.

### E2. Zewnętrzny monitor dostępności

- **Gdzie:** UptimeRobot albo Better Stack (`MONITORING_BLEDOW.md` §6).
- **Co:** dwa monitory HTTP(s) na `https://kuking.pl/` i
  `https://kuking.pl/health`, alarm przy kodzie ≠ 200 dwa razy z rzędu,
  interwał 1–5 min, **jeden** jawny kontakt alarmowy. Na domenie
  `kuking.pl`, nie na adresie Railway.
- **Uwaga:** monitor na słowo `ok` w treści wymaga najpierw E3 (dziś
  `/health` jest `degraded` przez stare zadania). Gdy wejdzie
  **[PR #1672]**, szczegóły `checks` będą tylko z nagłówkiem tokenu
  (`KUKING_HEALTH_TOKEN`, nowa zmienna do Shared Variables i `railway.ts`) —
  kod i pole `status` zostają publiczne.
- **Czas:** 15 min. **Zależy od:** E3. **Odblokowuje:** #599, obserwacja B3.
- **Cofnięcie:** wstrzymanie monitora u dostawcy.

### E3. Rozliczenie czterech zadań z 9 września

- **Co:** `railway ssh --service kuking.pl -- php artisan kuking:martwe-zadania --na-sucho`
  (tylko odczyt), zapis dowodu w #599 bez payloadów i adresów, potem to samo
  z `--skasuj`. **Bez `queue:retry`** — tokeny resetu hasła dawno wygasły.
- **Sprawdzenie:** `/health` zwraca `status: ok`.
- **Czas:** 20 min. **Odblokowuje:** E2, sens sygnału `/health`.
- **Ryzyko:** skasowanie jedynego śladu — dlatego najpierw odczyt i zapis.

### E4. Puls harmonogramu

- **Gdzie:** u dostawcy z E2 (albo Healthchecks.io) monitor *heartbeat*:
  sygnał co 5 min, tolerancja 10 min; Railway → *Shared Variables* →
  `KUKING_PULS_HARMONOGRAMU_URL` (Sealed, `https://`), redeploy schedulera.
- **Sprawdzenie:** po 10 minutach monitor pokazuje regularne sygnały.
  Adresu nie wpisuj do repo ani do issue.
- **Czas:** 20 min. **Zależy od:** B3 (zmienna dochodzi tylko do roli,
  w której chodzi harmonogram). **Odblokowuje:** #599 (stojący scheduler).
- **Cofnięcie:** usunięcie zmiennej — komenda przestaje wysyłać.

### E5. Próby alarmów na stagingu

- **Co:** osobny monitor dostępności i osobny heartbeat na stagingu;
  zatrzymaj WWW stagingu na 5 min → alarm i powrót; zatrzymaj scheduler
  stagingu na 20 min → alarm pulsu; `kuking:sprawdz-alarm` na stagingu.
  **Nie wywołuj celowej awarii na produkcji.**
- **Czas:** 40 min. **Zależy od:** A7, E2, E4. **Odblokowuje:** #599
  („sprawdzony kanał alarmów”, test niedostępności).

### E6. Panele zasobów „przed/po”

- **Gdzie:** Railway → każdy serwis i `Postgres` → *Metrics*; Cloudflare →
  *Analytics & Logs*.
- **Co:** zrzut ekranu przed B3 i po B3. Szereg kolejek i połączeń raz
  w tygodniu: *Logs*, filtr `kuking:sprawdz-kolejke` i `kuking:budzet-polaczen`.
- **Czas:** 10 min ×2. **Odblokowuje:** #599 („porównanie przed/po”).
- **Poza alfą:** p95/p99, RPS per trasa, APM — decyzja właściciela
  (`MONITORING_599_KROKI.md` §D).

---

## F. Ludzie

### F1. Prawnik (#8) — zacznij teraz, to najdłuższy czas oczekiwania

- **Co:** umówić przegląd regulaminu, polityki i zasad; przekazać listy
  pytań: #8 („Co zostaje do zrobienia”), `docs/research/DSA-LUKI.md` §5,
  retencja potwierdzeń żądań usunięcia (komentarz z 25.09). Do
  rozstrzygnięcia przy okazji: DPA z podmiotami z polityki, rejestr
  czynności przetwarzania, ścieżka CSAM, data wejścia w życie, zdanie
  o kopii technicznej zdjęć (D7), dziennik wymazań (**[PR #1719]**),
  jurysdykcja UE (**[PR #1681]**), opis sesji i kopii w polityce
  (**[PR #1725]**).
- **Decyzja właściciela:** czy przed przeglądem zamknąć rejestrację na
  zaproszenia — dziś `/register` jest otwarty; zamknięcie to **[kod — brak
  PR]**.
- **Panel przy okazji:** EmailLabs → ustawienia śledzenia → wyłącz Open/Click
  Tracking (polityka mówi, że piksela nie ma; `PRZED_ZAPROSZENIEM_LUDZI.md`
  poz. 5). Sprawdzenie: surowy HTML doręczonego listu bez `click.kuking.pl/track/o/`.
- **Czas:** 30 min na umówienie; przegląd — tygodnie. **Odblokowuje:** #8,
  D7 (#617), C2 (zdanie o UE).
- **Ryzyko / cofnięcie:** zmiany treści prawnej wchodzą PR-em z testami
  `DokumentyPrawneNieKlamiaTest`; nie dopisywać noty o braku weryfikacji
  (D-140).

### F2. Moderacja działa (bramka ROADMAP)

- **Co:** `railway ssh --service worker -- php artisan kuking:sprawdz-model`
  (KROK 8B runbooka) — klucz OpenAI jest w serwisie, czy działa, nie
  wiadomo; skrzynka z `KUKING_MODEL_ALARM_EMAIL` jest czytana; jedno zgłoszenie
  próbne na stagingu → pozycja w panelu moderacji i list do moderatora.
  `KUKING_HOST_USER_ID` ustawione (A5), żeby alert pierwszego wpisu trafiał
  do gospodarza.
- **Czas:** 20 min. **Zależy od:** B3. **Odblokowuje:** warunek „moderation
  działa”. Sufit dobowy alarmów automatu — **[PR #1698]** (nowa zmienna
  `KUKING_MODEL_ALARM_SUFIT` z wartością domyślną).

### F3. Testy z osobami 50+ (#15)

- **Przed pierwszą sesją** (`docs/product/TESTY_Z_UZYTKOWNIKAMI.md`):
  decyzja o rekrutacji (§9 — poza publicznością startową albo z jawnym „to
  jeszcze nie otwarcie”); środowisko takie jak beta, nie `localhost`;
  `TrescZalazkowaSeeder` (inaczej zadanie „żurek” nie istnieje);
  `php artisan kuking:sprawdz-poczte`; przejście 10 ścieżek dzień wcześniej;
  zgoda na nagranie ekranu, retencja 3 miesiące, bez imion w notatkach.
- **Sesje:** 13 osób (5 × 50–59, 5 × 60–69, 3 × 70+), Android, iPhone,
  komputer, ≥ 2 osoby, które nigdy nic nie publikowały; 45–60 min + 15 min
  notatek; rozłożone na ≥ 2 tygodnie.
- **iPhone = #119:** przy każdym uczestniku z iPhone’em zapisz, co dociera
  (zdjęcie z aparatu, ze Zdjęć, z Plików) i co zrobił po komunikacie o HEIC.
- **Sprawdzenie:** lista problemów „problem | ilu z 13 | bloker? | zadanie”,
  każdy bloker jako issue `obszar: ux`.
- **Zależy od:** A7 albo produkcja, E1, D1 (nie zapraszać na bazę bez kopii).
- **Odblokowuje:** #15, warunek „brak blokerów UX”, #119 (test urządzeń),
  #29 (Bramka A: blokery = 0).

### F4. Pierwsze 20 osób (#29)

- **Co:** lista 40–60 konkretnych osób/miejsc, kontakt indywidualny,
  concierge onboarding, gospodarz odpowiada na każdy wpis
  (`/admin/bez-odpowiedzi`), ok. 2 h dziennie; eksperyment „zaproś jedną
  bliską osobę” (komentarz z 21.09).
- **Sprawdzenie:** Bramka A z liczb `kuking:raport`: ≥ 20 osób z wpisem,
  WAC/zarejestrowani ≥ 50%, ≥ 10 osób z ≥ 3 wpisami, ≥ 15 „Ugotowałem”
  (≥ 8 nie od gospodarza), 100% wpisów z odpowiedzią, mediana ≤ 3 h,
  awarie uploadu < 2%. STOP przy WAC < 35%.
- **Zależy od:** F3 bez blokerów, D6, C3, E1–E2 (`PRZED_ZAPROSZENIEM_LUDZI.md`,
  „BLOKUJE”). **Odblokowuje:** #29, warunek „20+ realnych userów”;
  30 dni danych HEIC dla #119.

---

## G. Mapa na bramkę zamkniętej alfy

| Warunek z `ROADMAP.md` | Kroki | Issues |
|---|---|---|
| 20+ realnych userów | F4 | #29 |
| stabilny upload | B4 (pkt 5), C3, E6, awarie uploadu < 2% w F4 | #595, #601, #120 |
| brak blockerów UX | F3 | #15, #119 |
| moderation działa | F2 | — |
| restore przetestowany | D1, D6 | #594, #193 |

Warunki towarzyszące (bez nich bramka nie ma sensu, choć ROADMAP ich nie
nazywa): alarm dochodzi (E1, E2, E4), prawnik przed pierwszymi realnymi
osobami (F1, #8).

## H. Kod potrzebny do tych kroków

**W otwartych PR-ach:** #1595, #1624, #1697, #1707 (A6), #1622 (B5),
#1672 (E2), #1681 (C2, F1), #1698 (F2), #1719 (D1, D6, F1), #1725 (F1).

**Bez PR-a** (do zlecenia, jeśli decyzja zapadnie):

- `KUKING_QUESTIONS_ENABLED` w `appEnv` `railway.ts`, jeśli dziś `true` (A4);
- `KUKING_R2_PUBLICZNE_ADRESY` w `railway.ts`, jeśli ma zostać na stałe (C3);
- `KUKING_HTML_EDGE_CACHE_SECONDS` w `railway.ts` (C5);
- `KUKING_EDGE_TRYB` w `railway.ts` przed egzekwowaniem tokenu (C6);
- `deploy.yml` job `operate` restartujący także `worker` i `scheduler` (B5);
- zamknięcie rejestracji na zaproszenia, jeśli taka decyzja (F1).

## I. Źródła

`docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md` · `docs/infra/DEPLOYMENT_RUNBOOK.md`
(KROK 8, 9, 10, 11.5, 12) · `docs/infra/KOPIE_I_ODTWORZENIE.md` §7.3, §8 ·
`docs/infra/DR594_PIERWSZY_ZRZUT_WLASCICIEL.md` · `docs/infra/BRAMKA_R2.md`
§2a, §3 · `docs/infra/CLOUDFLARE_CACHE_597_610.md` ·
`docs/infra/DR_ZDJEC_R2.md` §4 · `docs/infra/MONITORING_599_KROKI.md` ·
`docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md` §4 · `docs/flota/PRZED_ZAPROSZENIEM_LUDZI.md` ·
`.railway/railway.ts` · `.env.example` · issues #595, #120, #597, #610, #598,
#599, #604, #600, #193, #617, #1306, #975, #594, #601, #119, #29, #15, #8
z komentarzami (odczyt 25.09.2026).
