# Przełączenie produkcji na trzy serwisy — #595

**Status (24.09.2026): przygotowanie w repozytorium, nic nie wykonano.**
Nie uruchomiono `railway config plan` ani `apply`, nie łączono się z Railwayem
ani z Cloudflare. Nazwy żywych zasobów pochodzą z pomiaru z 9.09.2026
(`OperacjeWdrozeniaCelujaWIstniejacySerwisTest`) — przed planem trzeba je
potwierdzić w panelu (krok 0). Wykonuje właściciel, w tej kolejności.

## Co zmieniło się w repozytorium

| Zmiana | Po co |
|---|---|
| `.railway/railway.ts`: serwis WWW nazywa się `kuking.pl` (`NAZWA_SERWISU_WWW`), baza `Postgres` (`NAZWA_BAZY`) | Plan porównuje plik z żywym środowiskiem **po nazwie**. Ze starymi nazwami `web`/`postgres` pierwszy apply utworzyłby nowy serwis i **nową, pustą bazę**, przepiął `DB_URL` i zaproponował usunięcie istniejących. |
| `.github/workflows/railway-iac.yml`: `apply` tylko ręcznie (`workflow_dispatch` na `main`, wpisane potwierdzenie), zmiany destrukcyjne domyślnie zablokowane | Wcześniej scalenie każdego PR-a z `.railway/**` stosowało plik na produkcji z `confirm-destructive: true`. Scalenie tego PR-a samo przeprowadziłoby rozbicie. |
| `KUKING_WAIT_FOR_CI` przekazywane z **zmiennej repozytorium** do planu i apply | Bez niej plik ustawia `checkSuites: false`, czyli **wyłącza „Wait for CI”** na produkcji. |
| `KUKING_IAC_STAGING_ROZBITY=true` (zmienna procesu, tylko staging) | Jedyna droga, żeby przećwiczyć rozbicie gdzie indziej niż na produkcji. |
| `scripts/railway/iac.test.mjs` (+ `iac-graf.mjs`), w `check.sh` i w jobie `assets` w CI | Kompiluje `railway.ts` lokalnym SDK, bez sieci, i sprawdza graf: role i `APP_ROLE`, nazwy żywych zasobów, migracje tylko w WWW, jeden harmonogram, domeny tylko na WWW, te same zmienne i obraz w trzech serwisach, `drainingSeconds` workera ≥ limit zadania zdjęć, jeden region; oraz że workflow nie wróci do apply po scaleniu. 16 + 5 kontroli ujemnych w tym samym pliku. |

Strażnik **nie dowodzi**, że żywe środowisko wygląda jak w pomiarze z 9.09
ani że plan będzie taki, jak opisuję niżej. To rozstrzyga dopiero krok 2.

## Warunki wstępne — wszystkie przed krokiem 1

- [ ] Wolumen odłączony od `kuking.pl` (#596 — potwierdzone 17.09.2026).
      Wolumen blokuje repliki i wywraca rozbicie.
- [ ] Świeża kopia bazy z dzisiejszej nocy albo ręczny zrzut
      (docs/infra/KOPIE_I_ODTWORZENIE.md). Rozbicie nie dotyka danych,
      ale błędnie przeczytany plan może.
- [ ] Railway CLI ≥ 5.42.1 (`railway --version`), w repo `npm ci`.
- [ ] Zgoda na koszt: szacunek z `railway.ts` 40–65 USD/mies. zamiast 12–18.
      Potwierdź w panelu (Workspace → Usage) i ustaw limit wydatków.
- [ ] Okno o małym ruchu (np. 6:00–8:00), bez trwającej fali maili
      i bez eksportu RODO w toku (`php artisan kuking:sprawdz-kolejke`
      przez `railway ssh --service kuking.pl --environment production`).
- [ ] Ten PR scalony. Wszystkie polecenia niżej uruchamiaj z aktualnego `main`.

## Krok 0 — odczyt stanu (niczego nie zmienia)

1. Panel Railway → projekt (`ideal-exploration` według pomiaru z 9.09) →
   środowisko `production`. Zapisz:
   - **dokładne** nazwy serwisów (wielkość liter!) — oczekiwane `kuking.pl`
     i `Postgres`, ewentualnie ręcznie założony `kopia-bazy`;
   - region `kuking.pl` i `Postgres` (plik: `europe-west4-drams3a`);
   - w `kuking.pl` → Settings: Start Command, limity pamięci/CPU,
     healthcheck, czy „Wait for CI” jest włączone;
   - w `kuking.pl` → Variables: **nazwy** zmiennych (bez wartości).
2. Gdy nazwa serwisu albo bazy jest inna niż w pliku — **stop**. Popraw
   `NAZWA_SERWISU_WWW` / `NAZWA_BAZY` w `.railway/railway.ts` i `APP_SERVICE`
   w `.github/workflows/deploy.yml` w osobnym PR-ze (strażnik pilnuje, że się
   zgadzają), dopiero potem wracaj tutaj.
3. Gdy „Wait for CI” jest włączone: GitHub → Settings → Secrets and variables
   → Actions → **Variables** → `KUKING_WAIT_FOR_CI` = `true`. Lokalnie
   poprzedzaj każde `railway config plan/apply` przez `KUKING_WAIT_FOR_CI=true`.
4. Lista zmiennych współdzielonych, do których odwołuje się plik:

   ```bash
   node --experimental-strip-types --no-warnings scripts/railway/iac-graf.mjs production --wspoldzielone
   ```

   Każda z tych nazw musi istnieć w Project Settings → **Shared Variables**
   środowiska `production`, z tą samą wartością co dziś w serwisie.
   `ctx.shared.X` **odwołuje się** do zmiennej, nie tworzy jej. Jeśli dziś
   `kuking.pl` ma np. `AWS_ACCESS_KEY_ID` wpisane wprost, a `R2_ACCESS_KEY_ID`
   we współdzielonych nie istnieje, apply podmieni wartość na pustą
   i zdjęcia przestaną się wgrywać. Brakujące dopisz **przed** planem.

## Krok 1 — próba na stagingu (zalecane)

Staging jest odrębnym środowiskiem z własną bazą i własnymi sekretami.
Jeśli go nie ma: Project → Environments → New Environment `staging`
— **pusty, nie „duplicate production”** (duplikat kopiuje sekrety
produkcji) — współdzielone zmienne stagingu z krokiem 0.4 i gałąź `staging`
w repo. Jeśli świadomie pomijasz staging, zapisz to w #595 i przejdź do
kroku 2 z tym samym czytaniem planu.

```bash
railway link                                   # projekt, środowisko: staging
KUKING_IAC_STAGING_ROZBITY=true railway config plan
KUKING_IAC_STAGING_ROZBITY=true railway config apply   # interaktywnie, bez --yes
```

Sprawdź na stagingu weryfikację z kroku 4 (wgranie zdjęcia, harmonogram,
restart workera w trakcie zadania). Powrót stagingu do jednego kontenera:
`railway config plan` / `apply` **bez** zmiennej — plan pokaże usunięcie
`worker` i `scheduler` na stagingu, na co tu wolno się zgodzić.

## Krok 2 — plan produkcji i jego czytanie

```bash
railway link                                   # projekt, środowisko: production
KUKING_WAIT_FOR_CI=true railway config plan    # zmienna tylko, jeśli krok 0.3
```

Plan jest bezpieczny do uruchomienia. Wynik porównaj z tabelą:

| W planie | Oczekiwane | Gdy inaczej |
|---|---|---|
| `kuking.pl` | **zmiana** w miejscu: start `… web`, `APP_ROLE=web`, ewentualnie limity, draining, watch patterns | Utworzenie albo usunięcie `kuking.pl` — **stop**, krok 0.2 |
| `worker`, `scheduler` | **utworzenie** | — |
| `Postgres` | brak zmian | Utworzenie, usunięcie, zmiana obrazu, regionu albo wolumenu — **stop**. Zmiana obrazu/regionu bazy to migracja danych, nie część #595. |
| `web`, `postgres` (małe litery) | nie występują | Ktoś cofnął nazwy — **stop** |
| domeny `kuking.pl`, `www.kuking.pl` | brak zmian | Usunięcie/utworzenie domeny = nowy certyfikat i przerwa — **stop** |
| usunięcie **zmiennej** w `kuking.pl` | brak | **Stop.** Zmienna ustawiona tylko w panelu zniknie. Dopisz ją do `appEnv` w `railway.ts` (PR), wyjątek: `KUKING_HTML_EDGE_CACHE_SECONDS` — jej zniknięcie wyłącza cache HTML (bezpieczny kierunek), ale świadomie. |
| zmiana wartości zmiennej sekretnej (wartości są w planie zredagowane) | tylko tam, gdzie krok 0.4 potwierdził zmienną współdzieloną | Nie wiesz, skąd zmiana — **stop** |
| `checkSuites` / „Wait for CI” | brak zmian | Wyłączenie — brak `KUKING_WAIT_FOR_CI=true`, krok 0.3 |
| `kopia-bazy` | brak zmian, jeśli założona ręcznie pod tą nazwą; inaczej utworzenie | Utworzenie bez zmiennych `KOPIA_*`/`R2_KOPIE_*` da nocny błąd z alarmem, nie awarię strony. Zdecyduj: dokończ §7.3 KOPIE_I_ODTWORZENIE albo przyjmij świadomie. |
| nazwa projektu `kuking` | — | Jeśli plan chce zmienić nazwę projektu, jest to kosmetyka; odnotuj. |

Zapisz plan poza repo (wartości są zredagowane, ale plik zostaje u ciebie).
Jakiekolwiek „stop” = nie przechodzisz do kroku 3 w tym oknie.

## Krok 3 — apply

Jedna z dwóch dróg, **nie obie**:

- **lokalnie:** `KUKING_WAIT_FOR_CI=true railway config apply` — bez `--yes`
  i bez `--confirm-destructive`. Jeśli CLI poprosi o zgodę na zmianę
  destrukcyjną, odpowiedz „nie” i wróć do kroku 2;
- **z GitHuba:** Actions → *Railway IaC* → Run workflow, gałąź `main`,
  potwierdzenie `STOSUJE PLAN PRODUKCJI`, pole „Zezwól na usunięcie…”
  **niezaznaczone**. Wymaga `KUKING_DEPLOY_ENABLED=true` i sekretu
  `RAILWAY_TOKEN_PRODUCTION`. Akcja liczy plan od nowa — między krokiem 2
  a tym kliknięciem nic nie zmieniaj w panelu.

Co się dzieje: `kuking.pl` wdraża się ponownie w roli `web` (migracje
w pre-deploy, healthcheck `/health`), `worker` i `scheduler` budują ten sam
obraz i startują. Przez 30 s drenowania stary kontener `all` może jeszcze
wykonywać kolejkę i harmonogram równolegle z nowymi serwisami — to jest
bezpieczne: każde zadanie harmonogramu ma `->onOneServer()`
(`routes/console.php`), a kolejka bazy danych rezerwuje zadania blokadą
wiersza. Zdjęcia przez chwilę mogą czekać w kolejce — nie giną.

## Krok 4 — weryfikacja (w ciągu 15 minut)

1. Panel: trzy serwisy aplikacji **Active**, to samo SHA co `main`.
2. Logi, pierwsza linia entrypointu każdego serwisu:
   `[entrypoint] rola=web …`, `rola=worker …`, `rola=scheduler …`. **Nie może** być
   `tryb ALL`. Worker: `start queue:work …`; scheduler:
   `start harmonogramu …`.
3. `curl -s -o /dev/null -w '%{http_code}\n' https://kuking.pl/health` → `200`.
4. `bash scripts/sprawdz-wdrozenie.sh kuking.pl` (tryb opisany
   w SONDA_WDROZENIA_805_808.md).
5. Wgraj zdjęcie z konta testowego: warianty w ciągu ~minuty, a w logach
   **workera** (nie `kuking.pl`) wpis przetworzenia.
6. Po pełnej minucie w logach **schedulera** przebieg `schedule:run`;
   w `kuking.pl` żadnego.
7. `railway ssh --service kuking.pl --environment production "php artisan kuking:sprawdz-kolejke"`
   — zaległości nie rosną przez 10 minut.
8. Pamięć workera przy zdjęciu (Metrics) poniżej limitu 1024 MB; strona
   w tym czasie bez skoku czasu odpowiedzi.
9. Wpisz do #595: datę, SHA, wynik planu (co zmienił), punkty 1–8.

## Cofnięcie

Od najszybszego. Obie drogi zostawiają dane nietknięte — rozbicie nie
zmienia schematu ani bazy.

**A. Panel (minuty, gdy coś nie działa):**

1. `kuking.pl` → Settings → Start Command
   `/usr/local/bin/kuking-entrypoint all`; Variables → `APP_ROLE=all`;
   Deploy. Poczekaj na **Active** i `tryb ALL` w logach — od teraz kolejka
   i harmonogram znów chodzą w jednym kontenerze.
2. Dopiero potem `worker` i `scheduler` → Settings → usuń serwis (albo
   zatrzymaj wdrożenie). Kolejność ma znaczenie: odwrotna zostawia
   produkcję na chwilę bez kolejki i harmonogramu. Zakładka nakładania się
   jest bezpieczna z tego samego powodu co w kroku 3.
3. Panel rozjechał się teraz z plikiem. Zanim ktoś uruchomi kolejny plan,
   wykonaj B.

**B. Kod (trwałe):** PR z `PRODUCTION_SPLIT_SERVICES = false` w
`.railway/railway.ts`, plan (pokaże usunięcie `worker` i `scheduler`
— tu zgoda jest właściwa), apply przez Run workflow z zaznaczonym polem
zmian destrukcyjnych **wyłącznie** dla tych dwóch serwisów.

Po cofnięciu: kolejne `apply` z `true` znowu rozbije produkcję — to jest
flaga, nie jednorazowe polecenie.

## Po udanym przełączeniu — do osobnych zadań

- `.github/workflows/deploy.yml`, job `operate`: `redeploy` restartuje
  tylko `kuking.pl`; worker i scheduler trzeba dopisać, razem z testem
  `OperacjeWdrozeniaCelujaWIstniejacySerwisTest`, który dziś uznaje nazwy
  `worker` i `scheduler` za zmyślone.
- Sprostowanie przy `PRODUCTION_SPLIT_SERVICES` w `railway.ts` przestaje
  być prawdą — poprawić na stan zmierzony.
- Druga replika WWW dopiero po mieszanym teście obciążeniowym (#605);
  `scheduler` zostaje przy jednej replice zawsze.
