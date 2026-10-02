# Przełączenie produkcji na trzy serwisy — #595

**Status (29.09.2026): repozytorium gotowe, przełączenie robi właściciel
według tego runbooka** (D-333, wiersz #595). Nie uruchomiono `railway config
plan` ani `apply`, nie łączono się z Railwayem ani z Cloudflare. Produkcja to
nadal jeden serwis `kuking.pl` w roli `all` (`APP_ROLE=all`). Wykonuje
właściciel, w tej kolejności.

## Stan produkcji, od którego zaczynamy (29.09.2026, same NAZWY zmiennych)

Odczyt koordynatora z panelu, bez wartości:

- serwis `kuking.pl` **nie ma** zmiennych `VAPID_*`, `KUKING_EDGE_*`,
  `OPENAI_IMPORT_KEY`, `AWS_ZDJECIA_KOPIA_*`;
- **nie ma** też `KUKING_HTML_EDGE_CACHE_SECONDS` ani `KUKING_TAG_TYGODNIA`;
- **ma** `RAILWAY_PUBLIC_DOMAIN` — tę Railway wstrzykuje sam (serwis ma
  wygenerowaną domenę `*.up.railway.app`); nie jest ustawiana ręcznie
  i narzędzia z kroku 0 ją pomijają.

Skutek dla przełączenia: Web Push, bramka brzegu, import z OpenAI (#2214 —
czeka na DPA), sprawdzanie kopii zdjęć, cache HTML i tag tygodnia są dziś
**wyłączone** i po apply zostaną wyłączone (pusta referencja do Shared
Variable = wartość domyślna). Apply nie włączy żadnej z nich. To samo
dotyczy drugiego kanału alarmów pocztą (`KUKING_ALARM_EMAIL`, #599): gdy
w Shared Variables go nie ma, zostaje sam Discord (`LOG_BLAD_WEBHOOK_URL`,
która **też** przechodzi na Shared — bez niej alarmy zamilkną). Zmienne, które
serwis ma, poznasz w kroku 0.5 — tej listy repozytorium nie zna.

## Czy `railway config apply` usuwa zmienne spoza pliku? — TAK, traktuj to jako pewne

- `railway.ts` jest deklaracją **pełną**: plan porównuje plik z żywym
  środowiskiem, bez pliku stanu. README paczki `railway` 3.11.0
  (`node_modules/railway/README.md`, sekcja IaC): *„Removing resources or
  variables is destructive and additionally requires `--confirm-destructive`
  in non-interactive or agent sessions”*. Zmienna, którą serwis ma, a plik
  nie — plan pokaże jako usunięcie.
- SDK ma `preserve()` („zachowaj wartość, którą Railway już ma”). **Nie
  używamy go** dla zmiennych aplikacji: nowe serwisy `worker` i `scheduler`
  nie mają żadnej wartości do zachowania, a źródłem prawdy ma zostać plik
  plus Shared Variables. Dlatego wartości przenosimy do Shared Variables
  **przed** apply (krok 0.5).
- Po #1459 (#1013) plan **na pewno** coś usunie z `kuking.pl`: rola `web` nie
  ma zmiennych workera i schedulera (klucz moderacji, puls harmonogramu,
  odczyt kopii, listy urodzinowe…). To jest zamierzone. Stopem jest usunięcie
  zmiennej, której bilans z kroku 0.5 nie przewidział.
- Potwierdzenie na żywo daje dopiero plan z kroku 2 — wynik dopisz do
  `docs/infra/ZMIENNE_SPOZA_IAC.md`, sekcja „Wynik”.

## Co jest w repozytorium

| Element | Po co |
|---|---|
| `.railway/railway.ts`: serwis WWW nazywa się `kuking.pl` (`NAZWA_SERWISU_WWW`), baza `Postgres` (`NAZWA_BAZY`), `PRODUCTION_SPLIT_SERVICES = true` | Plan porównuje plik z żywym środowiskiem **po nazwie**. Ze starymi nazwami `web`/`postgres` pierwszy apply utworzyłby nowy serwis i **nową, pustą bazę**. |
| Zestawy zmiennych per rola (`webEnv`, `workerEnv`, `schedulerEnv`, #1459, #1013, #1014) | Sekrety tylko tam, gdzie ktoś je czyta: OAuth i Turnstile tylko web, klucz moderacji i prywatny klucz Web Push tylko worker, odczyt kopii i puls tylko scheduler. Pilnuje `tests/Feature/ZmienneRailwayaPerRolaTest.php`. |
| `.github/workflows/railway-iac.yml`: `apply` tylko ręcznie (`workflow_dispatch` na `main`, wpisane potwierdzenie), zmiany destrukcyjne domyślnie zablokowane | Scalenie PR-a nie stosuje pliku na produkcji. |
| `KUKING_WAIT_FOR_CI` przekazywane z **zmiennej repozytorium** do planu i apply | Bez niej plik odmawia kompilacji (#1390) zamiast po cichu wyłączyć „Wait for CI”. |
| `KUKING_IAC_STAGING_ROZBITY=true` (zmienna procesu, tylko staging) | Jedyna droga, żeby przećwiczyć rozbicie gdzie indziej niż na produkcji. |
| `scripts/railway/iac.test.mjs` (+ `iac-graf.mjs`), w `check.sh` i w jobie `assets` w CI | Kompiluje `railway.ts` lokalnym SDK, bez sieci, i sprawdza graf: role i `APP_ROLE`, nazwy żywych zasobów, migracje tylko w WWW, jeden harmonogram, domeny tylko na WWW, rdzeń zmiennych w każdej roli, obraz wspólny, `drainingSeconds` workera ≥ limit zadania zdjęć, jeden region, workflow bez apply po scaleniu, bilans zmiennych i blok wycofania z tego runbooka — z kontrolami ujemnymi w tym samym pliku. |
| `scripts/railway/bilans-zmiennych-595.mjs` | Krok 0.5: z nazw zmiennych `kuking.pl` i nazw Shared Variables mówi, które zmienne plan usunie (oczekiwanie albo stop), której wartości zabraknie w Shared i które referencje zostaną puste. Same nazwy, bez wartości. |

Strażniki **nie dowodzą**, że żywe środowisko wygląda jak opis wyżej ani że
plan będzie taki, jak w tabeli z kroku 2. To rozstrzyga dopiero krok 2.

## Warunki wstępne — wszystkie przed krokiem 1

- [ ] Plan Railway **Pro** aktywny (D-333), limit wydatków: twardy 100 USD,
      alert przy 60 USD (Workspace → Usage). `restartPolicyType: "ALWAYS"`
      schedulera i workera oraz 1000 prób restartu na produkcji
      (`PROBY_RESTARTU_PRODUKCJA`, #2302 IN-13) wymagają planu płatnego. Szacunek z `railway.ts`: 40–65
      USD/mies. zamiast 12–18 — zmieści się w limicie, ale sprawdź zużycie
      po tygodniu.
- [ ] Wolumen odłączony od `kuking.pl` (#596 — potwierdzone 17.09.2026).
- [ ] Świeża kopia bazy z dzisiejszej nocy albo ręczny zrzut
      (docs/infra/KOPIE_I_ODTWORZENIE.md). Rozbicie nie dotyka danych,
      ale błędnie przeczytany plan może.
- [ ] Railway CLI ≥ 5.42.1 (`railway --version`), w repo `npm ci`, Node ≥ 22.6.
- [ ] Okno o małym ruchu (np. 6:00–8:00), bez trwającej fali maili
      i bez eksportu RODO w toku (`php artisan kuking:sprawdz-kolejke`
      przez `railway ssh --service kuking.pl --environment production`).
- [ ] Wszystkie polecenia niżej uruchamiaj z aktualnego `main`.

## Krok 0 — odczyt stanu i bilans zmiennych (niczego nie zmienia)

1. Panel Railway → projekt (`ideal-exploration` według pomiaru z 9.09) →
   środowisko `production`. Zapisz:
   - **dokładne** nazwy serwisów (wielkość liter!) — oczekiwane `kuking.pl`
     i `Postgres`, ewentualnie ręcznie założony `kopia-bazy`;
   - region `kuking.pl` i `Postgres` (plik: `europe-west4-drams3a`);
   - w `kuking.pl` → Settings: Start Command, limity pamięci/CPU,
     healthcheck, czy „Wait for CI” jest włączone, jakie domeny ma serwis
     (dwie własne i jedna `*.up.railway.app`).
2. Gdy nazwa serwisu albo bazy jest inna niż w pliku — **stop**. Popraw
   `NAZWA_SERWISU_WWW` / `NAZWA_BAZY` w `.railway/railway.ts` i `APP_SERVICE`
   w `.github/workflows/deploy.yml` w osobnym PR-ze (strażnik pilnuje, że się
   zgadzają), dopiero potem wracaj tutaj.
3. `KUKING_WAIT_FOR_CI` jest **obowiązkowe** (#1390): `true`, gdy „Wait for CI”
   jest włączone, `false`, gdy nie. GitHub → Settings → Secrets and variables
   → Actions → **Variables** → `KUKING_WAIT_FOR_CI`. Lokalnie poprzedzaj każde
   `railway config plan/apply` tą samą wartością (przykłady niżej zakładają
   `true`).
4. Lista Shared Variables, do których odwołuje się plik:

   ```bash
   KUKING_WAIT_FOR_CI=true node --experimental-strip-types --no-warnings scripts/railway/iac-graf.mjs production --wspoldzielone
   ```

   `ctx.shared.X` **odwołuje się** do zmiennej, nie tworzy jej. Uwaga na
   nazwy: w serwisie stoi np. `AWS_ACCESS_KEY_ID`, a plik czyta
   `${{shared.R2_ACCESS_KEY_ID}}` — mapę nazw pokazuje krok 5.
5. **Bilans zmiennych.** Przepisz z panelu (Project Settings → Shared
   Variables, środowisko `production`) same **nazwy** Shared Variables do
   pliku poza repozytorium, po jednej w linii, np. plik `shared-nazwy.txt` w katalogu domowym.
   Potem:

   ```bash
   railway link          # projekt, środowisko production, serwis kuking.pl
   railway variables --service kuking.pl --json \
     | KUKING_WAIT_FOR_CI=true node --experimental-strip-types --no-warnings \
         scripts/railway/bilans-zmiennych-595.mjs production --wspoldzielone ~/shared-nazwy.txt
   echo "kod wyjścia: $?"
   ```

   Skrypt czyta z wejścia tylko klucze i wypisuje tylko nazwy (pilnuje tego
   test). Surowego wyniku `railway variables --json` nie wklejaj nigdzie — są
   w nim wartości. Sekcje wyniku:

   | Sekcja | Znaczenie | Co zrobić |
   |---|---|---|
   | **STOP — `kuking.pl` straci zmienną, której plik nie przenosi nigdzie** | Zmienna stoi tylko w panelu. Apply ją usunie. | PR: dopisz ją do roli, która ją czyta, w `railway.ts` (jak `KUKING_MEDIA_DISK`, #1883), albo — gdy nic jej nie czyta — do `MARTWE` w skrypcie z powodem. Powtórz krok 5. |
   | **STOP — zmienna tylko z panelu (WYJATKI, #2295)** | Plik **świadomie** jej nie deklaruje (lista `WYJATKI` w `tests/Feature/ZmienneRailwayaPerRolaTest.php` albo para `AWS_LEGACY_*`), a apply usunie ją razem z wartością. Najgroźniejsze: `AWS_LEGACY_*` (i `AWS_URL`) — stary bucket to jedyna kopia części najstarszych zdjęć (`docs/infra/STARY_BUCKET_R2_LEGACY.md`). | Instrukcja stoi przy nazwie w wyniku. Dla `AWS_LEGACY_*`: najpierw `railway ssh --service kuking.pl --environment production "php artisan kuking:zaleznosc-od-starego-bucketu --pliki"`. Żaden wiersz nie wskazuje `r2_legacy` → PR przenosi nazwy do `MARTWE` z datą pomiaru. Wiersze są → PR: `AWS_LEGACY_*` w `appEnv` jako `ctx.shared.R2_LEGACY_*` (i usunięcie z `WYJATKI`), Shared `R2_LEGACY_*` z wartościami z panelu. Powtórz krok 5. |
   | **STOP — wartość z panelu przepadnie** | Wartość stoi dziś w serwisie (nazwa po prawej), plik będzie jej szukał w Shared Variable (nazwa po lewej), której nie ma. | Załóż Shared Variable o nazwie z lewej **z tą samą wartością** co zmienna z prawej (skopiuj w panelu; sekrety zaznacz „Sealed”). Powtórz krok 5. |
   | Oczekiwane usunięcie: zmienna przechodzi do innej roli | Plan usunie ją z `kuking.pl`, a dostanie ją `worker` / `scheduler`. | Nic — ta lista to jedyne usunięcia, na które zgodzisz się w kroku 3. Zapisz ją. |
   | Oczekiwane usunięcie: zmienna martwa | Nikt jej nie czyta (dziś: `TRUSTED_PROXIES`). | Nic; też na listę zgód z kroku 3. |
   | Oczekiwane usunięcie: połączenie z bazą idzie przez `DB_URL` | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` — plik daje każdej roli `DB_URL = ${{Postgres.DATABASE_URL}}`, a `url` ma pierwszeństwo (audyt 30.09, §3.1). | Nic; też na listę zgód z kroku 3. |
   | Informacja — referencja bez Shared Variable | Po apply pusto = wartość domyślna, jak dziś. Przy stanie z 29.09: `VAPID_*`, `KUKING_EDGE_*`, `OPENAI_IMPORT_KEY`, `R2_ZDJECIA_KOPIA_*`, `KUKING_HTML_EDGE_CACHE_SECONDS`, `KUKING_TAG_TYGODNIA` i podobne. | Nic, o ile to nazwy funkcji, których produkcja nie ma. Gdy na liście jest coś, co działa dziś (np. klucze R2, EmailLabs) — to znaczy, że stoi w serwisie pod **inną nazwą** niż w pliku: **stop**, wyjaśnij przed planem. |

   **Wartości dosłowne, które apply ustawi inaczej niż domyślne w `config/`
   (IN-04, #2296).** Bilans porównuje same nazwy, więc nie zobaczy zmiennej,
   która w panelu JEST, ale z inną wartością, ani takiej, której w panelu NIE
   MA, a plik ustawia ją na wartość inną niż domyślna z kodu. Lista takich nazw
   to `ROZNE_OD_DOMYSLNYCH` w `scripts/railway/iac.test.mjs` (z opisem skutku,
   pilnuje jej `node --test scripts/railway/iac.test.mjs`). Dla każdej nazwy z
   listy sprawdź w panelu (`railway variables --service kuking.pl`, wartości
   zostają u Ciebie): zmienna jest i ma wartość z pliku — brak zmiany; zmiennej
   nie ma — po apply zachowanie produkcji się zmieni zgodnie z opisem, więc
   przyjmij to świadomie albo ustaw ją w panelu przed apply. Dziś zmieniają
   zachowanie dwie: `KUKING_QUESTIONS_ENABLED` i `KUKING_URODZINY_MAIL_WLACZONY`
   (wiersze planu w kroku 2); reszta to ustawienia, bez których produkcja
   i tak nie działa poprawnie.

   Kod `0` — bilans się zamyka, idź dalej. Kod `1` — sekcje STOP nie są
   puste. Kod `2` — złe wywołanie albo w pliku z nazwami stoi coś, co nie
   wygląda na nazwę (np. wklejona wartość).

## Krok 1 — próba na stagingu (zalecane, odłożone decyzją z 25.09)

Staging jest odrębnym środowiskiem z własną bazą i własnymi sekretami
(`DEPLOYMENT_RUNBOOK.md` §8 — dziś odłożony). Jeśli go nie ma i świadomie
go pomijasz, zapisz to w #595 i przejdź do kroku 2 z tym samym czytaniem
planu. Jeśli go zakładasz: Project → Environments → New Environment
`staging` — **pusty, nie „duplicate production”** (duplikat kopiuje sekrety
produkcji) — Shared Variables stagingu wg kroku 0.4 i gałąź `staging` w repo.

```bash
railway link                                   # projekt, środowisko: staging
KUKING_WAIT_FOR_CI=true KUKING_IAC_STAGING_ROZBITY=true railway config plan
KUKING_WAIT_FOR_CI=true KUKING_IAC_STAGING_ROZBITY=true railway config apply   # interaktywnie, bez --yes
```

Sprawdź na stagingu weryfikację z kroku 4 (wgranie zdjęcia, harmonogram,
restart workera w trakcie zadania). Powrót stagingu do jednego kontenera:
plan / apply **bez** `KUKING_IAC_STAGING_ROZBITY` (z samym
`KUKING_WAIT_FOR_CI`) — plan pokaże usunięcie `worker` i `scheduler` na
stagingu, na co tu wolno się zgodzić.

## Krok 2 — plan produkcji i jego czytanie

```bash
railway link                                   # projekt, środowisko: production
KUKING_WAIT_FOR_CI=true railway config plan > ~/plan-595.txt   # wartość z kroku 0.3; plik poza repo
```

Plan jest bezpieczny do uruchomienia. Wynik porównaj z tabelą:

| W planie | Oczekiwane | Gdy inaczej |
|---|---|---|
| `kuking.pl` | **zmiana** w miejscu: start `… web`, `APP_ROLE=web`, ewentualnie limity, draining, watch patterns | Utworzenie albo usunięcie `kuking.pl` — **stop**, krok 0.2 |
| `worker`, `scheduler` | **utworzenie** | — |
| `Postgres` | brak zmian | Utworzenie, usunięcie, zmiana obrazu, regionu albo wolumenu — **stop**. Zmiana obrazu/regionu bazy to migracja danych, nie część #595. |
| `web`, `postgres` (małe litery) | nie występują | Ktoś cofnął nazwy — **stop** |
| domeny `kuking.pl`, `www.kuking.pl` | brak zmian | Usunięcie/utworzenie domeny = nowy certyfikat i przerwa — **stop** |
| domena `*.up.railway.app` serwisu `kuking.pl` | brak zmian | Plan chce ją usunąć — **stop**. Od niej zależą środowiska PR (komentarz przy `domains` w `railway.ts`). Dopisz ją w PR-ze jako `serviceDomains` serwisu WWW (nazwa domeny z panelu nie jest sekretem) i policz plan od nowa. |
| usunięcie **zmiennej** w `kuking.pl` | **dokładnie** nazwy z sekcji „Oczekiwane usunięcie” bilansu (krok 0.5) — przy stanie z 29.09 m.in. `OPENAI_MODERATION_KEY`, `AWS_KOPIE_*`, `KUKING_PULS_HARMONOGRAMU_URL`, `KUKING_URODZINY_MAIL_WLACZONY`, `TRUSTED_PROXIES`, `DB_HOST`/`DB_PASSWORD`/…, jeśli serwis je ma. **Nigdy** `AWS_LEGACY_*` ani inna nazwa z sekcji „tylko z panelu” | Każda inna — **stop.** Wracasz do kroku 0.5. |
| zmienna w `kuking.pl` zmienia się ze stałej na `${{shared.…}}` | tylko tam, gdzie krok 0.5 potwierdził Shared Variable | Nie wiesz, skąd zmiana — **stop** |
| nowe zmienne w `kuking.pl` (np. `VAPID_PUBLIC_KEY`, `KUKING_EDGE_TRYB`) | referencje z sekcji „Informacja” bilansu — puste, zachowanie domyślne | — |
| `KUKING_QUESTIONS_ENABLED=true` w `kuking.pl` | brak zmian, jeśli serwis już ma tę zmienną z wartością `true` (w `railway.ts` od 25.09.2026) | Plan ją **dodaje** — zmiana zachowania: po apply włączy się dział pytań „Poradźcie”; przyjmij świadomie albo **stop**. Plan pokazuje `false` albo usunięcie — **stop** |
| dodanie `KUKING_URODZINY_MAIL_WLACZONY=true` (serwis z harmonogramem: `kuking.pl` w roli `all` albo `scheduler`) | **zmiana zachowania, oczekiwana** (D-269, #2296): dopóki właściciel nie wykonał kroku W12 z `docs/flota/KROKI_WLASCICIELA_2026-09-29.md`, zmiennej na produkcji nie ma i życzenia mailem nie wychodzą (domyślnie `false`). Po apply zaczną wychodzić do osób ze zgodą. Po W12 — brak zmiany | Plan pokazuje `false` albo usunięcie tej zmiennej — **stop** |
| dodanie `KUKING_DIGEST_WLACZONY` → referencja `${{shared.KUKING_DIGEST_WLACZONY}}` (serwis z harmonogramem) | bez zmiany zachowania: Shared Variable z tą samą wartością co dziś w serwisie albo brak zmiennej (pusto = `false`, podsumowania wyłączone) (#2302, IN-12) | W serwisie stoi `KUKING_DIGEST_WLACZONY=true`, a Shared Variable nie założono — **stop**, podsumowania tygodnia by zgasły |
| dodanie `KUKING_REGISTRATION_OPEN` → referencja `${{shared.KUKING_REGISTRATION_OPEN}}` (serwis WWW) | bez zmiany zachowania: Shared Variable z tą samą wartością co dziś w serwisie albo brak zmiennej (pusto = rejestracja otwarta, jak dziś) (droga do bety, B9) | W serwisie stoi `KUKING_REGISTRATION_OPEN=false` (rejestracja zamknięta na czas przeglądu prawnika), a Shared Variable nie założono — **stop**, po apply `/register` otworzyłby się dla wszystkich |
| zmiana wartości zmiennej sekretnej (wartości są w planie zredagowane) | tylko tam, gdzie krok 0.5 potwierdził Shared Variable | Nie wiesz, skąd zmiana — **stop** |
| `checkSuites` / „Wait for CI” | brak zmian | Wyłączenie — brak `KUKING_WAIT_FOR_CI=true`, krok 0.3 |
| `kopia-bazy` | brak zmian, jeśli założona ręcznie pod tą nazwą; inaczej utworzenie | Utworzenie bez zmiennych `KOPIA_*`/`R2_KOPIE_*` da nocny błąd z alarmem, nie awarię strony. Zdecyduj: dokończ §7.3 KOPIE_I_ODTWORZENIE albo przyjmij świadomie. |
| nazwa projektu `kuking` | — | Jeśli plan chce zmienić nazwę projektu, jest to kosmetyka; odnotuj. |

Zapisz plan poza repo (wartości są zredagowane, ale plik zostaje u ciebie).
Jakiekolwiek „stop” = nie przechodzisz do kroku 3 w tym oknie.

## Krok 3 — apply

**Pierwsze apply #595 rób lokalnie.** Plan zawiera usunięcia zmiennych
z `kuking.pl` (krok 0.5), a usunięcie jest zmianą destrukcyjną:

- **lokalnie (zalecane):** `KUKING_WAIT_FOR_CI=true railway config apply`
  — bez `--yes` i bez `--confirm-destructive`. CLI zapyta o zgodę na zmiany
  destrukcyjne. Zgódź się **tylko wtedy**, gdy to, co wymienia, jest
  dokładnie listą „Oczekiwane usunięcie” z kroku 0.5 i niczym więcej (żadnego
  serwisu, bazy, domeny). Cokolwiek innego — „nie” i wracasz do kroku 2;
- **z GitHuba:** Actions → *Railway IaC* → Run workflow, gałąź `main`,
  potwierdzenie `STOSUJE PLAN PRODUKCJI`. Pole „Zezwól na usunięcie…”
  zezwala na **każde** usunięcie, także serwisu i bazy, bez pytania —
  dlatego przy #595 go **nie zaznaczaj**. Bez niego apply zatrzyma się na
  usunięciu zmiennych; ta droga nadaje się więc tylko wtedy, gdy bilans nie
  miał ani jednego „Oczekiwanego usunięcia”. Wymaga
  `KUKING_DEPLOY_ENABLED=true` i sekretu `RAILWAY_TOKEN_PRODUCTION`.

Między krokiem 2 a apply nic nie zmieniaj w panelu — apply odrzuca plan
policzony na innym stanie środowiska.

Co się dzieje: `kuking.pl` wdraża się ponownie w roli `web` (migracje
w pre-deploy, healthcheck `/health`), `worker` i `scheduler` budują ten sam
obraz i startują — ale **przed startem procesu czekają na migracje web**
(`czekaj_na_migracje` w `docker/entrypoint.sh`, #2044; limit 900 s, potem
kod 1 — szczegóły i zmienne w `docs/DEPLOYMENT.md`, „Migrations”). W ich
logach do czasu końca migracji widać `czekam na migracje serwisu web`, potem
`schemat bazy jest aktualny … — startuję`. Nic w panelu nie trzeba zmieniać:
komendy startowe `…kuking-entrypoint worker` i `… scheduler` są już w pliku.
Przez okno drenowania (`drainingSeconds`; faktyczną
wartość odczytaj w panelu — docs/DEPLOYMENT.md, sekcja „Kolejki”)
stary kontener `all` może jeszcze
wykonywać kolejkę i harmonogram równolegle z nowymi serwisami — to jest
bezpieczne: każde zadanie harmonogramu ma `->onOneServer()`
(`routes/console.php`), a kolejka bazy danych rezerwuje zadania blokadą
wiersza. Zdjęcia przez chwilę mogą czekać w kolejce — nie giną.

## Krok 4 — weryfikacja (w ciągu 15 minut)

1. Panel: trzy serwisy aplikacji **Active**, to samo SHA co `main`.
2. Logi **workera** i **schedulera**: `schemat bazy jest aktualny` przed
   `start queue:work` / `start harmonogramu`. Jeśli zamiast tego jest `nie
   startuję na starym schemacie` — migracja web się nie udała; napraw ją,
   nie obchodź bramki (`MIGRACJE_BRAMKA=0` tylko awaryjnie).
   Logi, pierwsza linia entrypointu każdego serwisu:
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
9. Funkcje opcjonalne — zielony `/health` **nie** dowodzi, że działają (#1014):
   - `railway ssh --service worker --environment production "php artisan kuking:sprawdz-model"`
     — moderacja modelem ma klucz w **workerze**;
   - w **schedulerze** `php artisan kuking:sprawdz-kopie` — odczyt kopii bazy
     działa po przeniesieniu;
   - jeśli `KUKING_PULS_HARMONOGRAMU_URL` był w bilansie: monitor pulsu
     dostaje znak życia z serwisu `scheduler` w ciągu 5 minut;
   - nazwy zmiennych nowych serwisów (bez wartości) mają być te z grafu —
     `railway variables --service worker --json | KUKING_WAIT_FOR_CI=true node --experimental-strip-types --no-warnings scripts/railway/zmienne-spoza-iac.mjs production worker`
     (i to samo dla `scheduler`) ma dać kod `0`.
10. Wpisz do #595: datę, SHA, wynik planu (co zmienił, jakie usunięcia),
    punkty 1–9. Wynik pytania „czy apply usuwa zmienne spoza pliku” dopisz
    do `docs/infra/ZMIENNE_SPOZA_IAC.md`, sekcja „Wynik”.

## Cofnięcie

Od najszybszego. Obie drogi zostawiają dane nietknięte — rozbicie nie
zmienia schematu ani bazy.

**A. Panel (minuty, gdy coś nie działa):**

1. `kuking.pl` → Variables → Raw Editor: **dopisz** (nie zastępuj) zmienne
   workera i schedulera, których rola `web` nie ma. Apply je z `kuking.pl`
   zdjął, a kontener `all` bez nich chodziłby bez klucza moderacji, pulsu,
   odczytu kopii, listów urodzinowych i tygodniowego podsumowania. `${{shared.…}}` to referencja do
   Shared Variable z kroku 0.5, nie wartość — wklej dokładnie tak:

<!-- wycofanie-zmienne:start -->
```text
AWS_KOPIE_ACCESS_KEY_ID=${{shared.R2_KOPIE_ODCZYT_ACCESS_KEY_ID}}
AWS_KOPIE_BUCKET=${{shared.R2_KOPIE_BUCKET}}
AWS_KOPIE_SECRET_ACCESS_KEY=${{shared.R2_KOPIE_ODCZYT_SECRET_ACCESS_KEY}}
AWS_ZDJECIA_KOPIA_ACCESS_KEY_ID=${{shared.R2_ZDJECIA_KOPIA_ODCZYT_ACCESS_KEY_ID}}
AWS_ZDJECIA_KOPIA_BUCKET=${{shared.R2_ZDJECIA_KOPIA_BUCKET}}
AWS_ZDJECIA_KOPIA_SECRET_ACCESS_KEY=${{shared.R2_ZDJECIA_KOPIA_ODCZYT_SECRET_ACCESS_KEY}}
KUKING_DIGEST_WLACZONY=${{shared.KUKING_DIGEST_WLACZONY}}
KUKING_PULS_HARMONOGRAMU_URL=${{shared.KUKING_PULS_HARMONOGRAMU_URL}}
KUKING_URODZINY_MAIL_WLACZONY=true
OPENAI_MODERATION_KEY=${{shared.OPENAI_MODERATION_KEY}}
VAPID_PRIVATE_KEY=${{shared.VAPID_PRIVATE_KEY}}
```
<!-- wycofanie-zmienne:end -->

   (Blok jest wyliczany z `railway.ts` — pilnuje go `iac.test.mjs`. Linie
   o Shared Variables, których nie ma, dają pusto, jak przed przełączeniem.)
2. W tym samym serwisie: Settings → Start Command
   `/usr/local/bin/kuking-entrypoint all`; Variables → `APP_ROLE=all`;
   Deploy. Poczekaj na **Active** i `tryb ALL` w logach — od teraz kolejka
   i harmonogram znów chodzą w jednym kontenerze.
3. Dopiero potem `worker` i `scheduler` → Settings → usuń serwis (albo
   zatrzymaj wdrożenie). Kolejność ma znaczenie: odwrotna zostawia
   produkcję na chwilę bez kolejki i harmonogramu. Nakładanie się jest
   bezpieczne z tego samego powodu co w kroku 3.
4. Panel rozjechał się teraz z plikiem. Zanim ktoś uruchomi kolejny plan,
   wykonaj B.

**B. Kod (trwałe):** PR z `PRODUCTION_SPLIT_SERVICES = false` w
`.railway/railway.ts` (rola `all` dostaje wtedy sumę zestawów, więc blok
z A jest już w pliku), plan (pokaże usunięcie `worker` i `scheduler` —
tu zgoda jest właściwa), apply **lokalnie**, zgoda na zmiany destrukcyjne
wyłącznie dla tych dwóch serwisów.

Po cofnięciu: kolejne `apply` z `true` znowu rozbije produkcję — to jest
flaga, nie jednorazowe polecenie.

## Po udanym przełączeniu — do osobnych zadań

- `.github/workflows/deploy.yml`, job `operate`: `redeploy` restartuje
  tylko `kuking.pl`; worker i scheduler trzeba dopisać, razem z testem
  `OperacjeWdrozeniaCelujaWIstniejacySerwisTest`, który dziś uznaje nazwy
  `worker` i `scheduler` za zmyślone.
- Sprostowanie przy `PRODUCTION_SPLIT_SERVICES` w `railway.ts` przestaje
  być prawdą — poprawić na stan zmierzony.
- Druga replika WWW, osobny worker `media` i PgBouncer — #600, dopiero po
  pomiarach (#605, #598); `scheduler` zostaje przy jednej replice zawsze.
