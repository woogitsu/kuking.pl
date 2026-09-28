# Deployment: GitHub + Railway

## Topologia startowa

```text
Kuking
├── web
└── postgres
```

Queue MVP: database.

Po wzroście (`PRODUCTION_SPLIT_SERVICES = true` w `.railway/railway.ts`):
```text
Kuking
├── web
├── worker
├── scheduler
└── postgres
```

`scheduler` to **długo działający** proces Laravel `schedule:work` (nie
Railway Cron — ten ma granulację 5 minut, a `everyMinute()` wymaga odpytania
co minutę). Ma zawsze dokładnie 1 replikę: dwie odpalałyby ten sam
harmonogram dwa razy. Pełne uzasadnienie: `docs/infra/INFRA_DECISION.md`
§5, kontrakt ról: `.railway/railway.ts`.

Zdjęcia docelowo: Cloudflare R2.

## GitHub

- `main` → production;
- `staging` → staging;
- feature branches → PR.

Włączyć Railway **Wait for CI**.

## Railway IaC

Nowy kierunek Railway to:
`.railway/railway.ts`

Nie projektować nowego repo wokół starego `railway.json` / `railway.toml`, ponieważ Config as Code jest oznaczone jako deprecated.

## Production env

- `APP_ENV=production`;
- `APP_DEBUG=false`;
- sekrety tylko w platformie;
- osobne staging secrets;
- HTTPS;
- healthcheck;
- backup.

## Dziennik serwera i polityka prywatności

Produkcja loguje na `stderr` (`.railway/railway.ts` → `LOG_CHANNEL`,
`LOG_STDERR_FORMATTER`). Railway przechwytuje `stdout`/`stderr` do swojego
narzędzia dzienników, więc wpisy **nie znikają** razem z instancją — żyją
tyle, ile pozwala plan konta Railway.

Polityka prywatności (§2, wiersz „Wykrywanie i naprawa błędów
technicznych”, oraz tabela dostawców w §3) opisuje ten przepływ.

**Stan na 24.09.2026 (decyzja właściciela, #994):** plan Railway **Hobby**,
w polityce „**do 7 dni**”. Liczby wg dokumentacji Railway (retencja logów):
Free 3 dni, Hobby 7, Pro 30, Enterprise do 90. Przy publicznym starcie
produkcji właściciel przechodzi na **Pro**.

Checklista przejścia na Pro (zrób wszystko w jednym PR-ze):

- [ ] `resources/legal/polityka-prywatnosci.md`, wiersz „Wykrywanie i naprawa
      błędów technicznych”: **„do 7 dni” → „do 30 dni”**;
- [ ] podbij wersję polityki: data w nagłówku („opisuje stan serwisu na …”)
      **i** `config/kuking.php` → `zgody.wersja_polityki` — ta sama data
      (pilnuje `PolitykaOpisujeRetencjeDziennikaSerweraTest`);
- [ ] `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.19: plan Pro, 30 dni;
- [ ] zdanie dla ludzi w `CHANGELOG.md`;
- [ ] kontrola dodatnia w `scripts/kontrole-negatywne-alfa08.py`
      (`POLITYKA_DZIENNIK_DNI`) — tekst mutacji musi odpowiadać nowemu zdaniu.

**Kiedy trzeba zmienić tekst polityki** (razem z datą stanu w jej nagłówku
i `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.19):

- zmiana planu Railway albo ustawień przechowywania dzienników
  (przejście na Pro — checklista wyżej);
- ustawienie `LOG_BLAD_WEBHOOK_URL` (Slack/Discord) albo eksport logów poza
  Railway — to nowy odbiorca zapisu błędu i trafia do tabeli dostawców;
- zmiana `LOG_CHANNEL` na inny odbiornik (plik, zewnętrzne narzędzie
  do zbierania błędów) — nowy odbiorca trafia też do tabeli dostawców.

Powrotu do zdania, które wiązało retencję z życiem instancji, pilnuje
`PolitykaOpisujeRetencjeDziennikaSerweraTest`.

## Kolejki — ile procesów `queue:work` (runbook, #1030)

Liczbę procesów i ich kolejki wybiera `listy_kolejek()` w
`docker/entrypoint.sh`:

| Rola | Domyślnie | Dlaczego |
|------|-----------|----------|
| `worker` (osobny kontener) | 4 procesy: `high`, `default`, `media`, `low` | żadna kolejka nie czeka za zaległością innej; `high` (listy wejścia na konto, B8-06) to lekki proces z samymi e-mailami |
| `all` (produkcja dziś, jeden kontener 1024 MB) | 2 procesy: `high,default` i `media,low` (D-311) | zaległość maili nie wstrzymuje zdjęć ani eksportu; ciężkie `media` i `low` dzielą proces, więc ich szczyty (zdjęcie 50 Mpx ~452 MB, eksport do 512M) nie schodzą się z WWW |

W każdym procesie kolejność na liście to **ścisły priorytet**. W roli `all`
fala maili na `default` nie wstrzymuje już zdjęć ani eksportu (osobny
proces), ale w ciężkim procesie `low` czeka za stałą zaległością `media`.
Widać to w `php artisan kuking:sprawdz-kolejke` — rośnie zaległość `low`
przy żywym `media`. Lekarstwo docelowe: osobny serwis `worker`
(`PRODUCTION_SPLIT_SERVICES` w `.railway/railway.ts`). Do 26.09.2026 rola
`all` miała jeden proces `high,default,media,low` — powrót do niego:
`QUEUE_WORKERS="high,default,media,low"`.

Ręczne sterowanie, bez wdrożenia kodu (zmienna w panelu Railway + restart):

- `QUEUE_WORKERS` — procesy rozdzielone **spacją**, w każdym lista po
  przecinku. Wygrywa z domyślną wartością każdej roli. Przykład dla `all`
  przy dużym zapasie pamięci: `QUEUE_WORKERS="high,default media low"`.
  Każda lista musi zawierać `high` — inaczej listy logowania zostaną w bazie.
  **Nigdy** nie dawaj `media` do dwóch procesów.
- `QUEUE_NAMES` — dawna zmienna: lista po przecinku dla **jednego** procesu.
  Działa jako alias, gdy `QUEUE_WORKERS` nie jest ustawione; przy obu naraz
  `QUEUE_NAMES` jest pomijane z ostrzeżeniem w logu.

Zatrzymanie (deploy, SIGTERM): entrypoint przekazuje TERM każdemu procesowi
`queue:work` i czeka, aż dokończy bieżące zadanie. Okno na to daje
`drainingSeconds` w `.railway/railway.ts`:

| Topologia IaC | Rola | `drainingSeconds` |
| --- | --- | ---: |
| `splitServices=false` | `all` | 130 s |
| `splitServices=true` | `web` | 30 s |
| `splitServices=true` | `worker` | 130 s |
| `splitServices=true` | `scheduler` | 30 s |

130 s obejmuje limit przetwarzania zdjęcia (120 s) i 10 s zapasu. Nie
gwarantuje ukończenia eksportu danych, którego limit wynosi 900 s; przerwane
zadanie wraca do kolejki po `retry_after` i jest ponawiane.

Trzy różne rzeczy — nie myl ich (#2056):

1. **Wartość wyliczana przez IaC.** Tabela wyżej to wartość wyliczana przez
   IaC z `.railway/railway.ts`, nie potwierdzona konfiguracja produkcji.
   Plik ma `PRODUCTION_SPLIT_SERVICES = true`, więc `railway config apply`
   na produkcji wyliczy wariant rozdzielony (`web` 30 s, `worker` 130 s,
   `scheduler` 30 s) i przy okazji rozbije produkcję na trzy serwisy
   (#595, docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md). Wiersz
   `splitServices=false` / `all` / 130 s dotyczy dziś stagingu
   i środowisk PR.
2. **Stan odczytany 28.09.2026 (migawka, tylko do odczytu).** Produkcja
   działa jako jeden serwis `kuking.pl` w roli `all` (Start Command
   `/usr/local/bin/kuking-entrypoint all`). W sekcji `deploy` tego serwisu
   pole `drainingSeconds` **nie było ustawione** — obowiązuje domyślna
   wartość Railway, nie 130 s z pliku. IaC na produkcji nie został jeszcze
   zastosowany. Migawka może być nieaktualna: przed wnioskami odczytaj
   stan jeszcze raz.
3. **Jak odczytać faktyczną wartość.** Panel Railway → projekt →
   środowisko `production` → serwis `kuking.pl` → Settings → Deploy →
   sekcja zamykania wdrożenia (Teardown), pole czasu drenowania
   (Draining time, w sekundach). Puste pole = domyślna Railway. Sprawdź
   też Variables, czy nie ma tam `RAILWAY_DEPLOYMENT_DRAINING_SECONDS`.
   Druga droga, bez klikania: `KUKING_WAIT_FOR_CI=true railway config plan`
   na produkcji — różnica w `drainingSeconds` między plikiem a żywą usługą
   pojawi się w planie. Plan niczego nie zmienia; przy odczycie nie
   uruchamiaj `apply`.

Ustawienie 130 s dla dzisiejszej roli `all` to decyzja właściciela:
`apply` z tego pliku nie da produkcyjnej roli `all` 130 s, tylko rozdzieli
usługi. Zostaje ręczne pole w panelu albo przełączenie z #595. Wpis
w panelu rozjeżdża się z plikiem — następny `plan` pokaże go jako zmianę.

## Migrations

Preferowany rollout:
```text
build
→ CI passed
→ deploy
→ migrate --force
→ healthcheck
```

Ryzykowne zmiany:
`expand → migrate/backfill → switch → contract`.

### Kolejność w topologii split: worker i scheduler czekają na migracje (#2044)

Migracje uruchamia **wyłącznie `web`**, w pre-deploy (`kuking:migruj-pod-blokada`).
Railway nie ma bramki między usługami, a `worker` i `scheduler` wdrażają się
z tego samego commita równolegle z `web`. Dlatego pilnuje tego sam obraz,
w `docker/entrypoint.sh` (`czekaj_na_migracje`), bez nowej usługi i bez zmian
w panelu:

```text
web:        pre-deploy (migrate → wdrożenie → seed → import) → healthcheck → ruch
worker:     start kontenera → czeka, aż `migrate:status` nie ma oczekujących → queue:work
scheduler:  start kontenera → czeka, aż `migrate:status` nie ma oczekujących → schedule:run
```

Jak to działa i co widać w logach:

- Czekanie sprawdza migracje **z obrazu tej usługi** (`php artisan migrate:status
  --pending=1`). Worker nie migruje sam — trzy migratory to wyścig o blokady.
- Log workera/schedulera: `czekam na migracje serwisu web — oczekujących: N`
  (co ok. 30 s), a na końcu `schemat bazy jest aktualny … — startuję`.
  Baza niedostępna albo pusta (brak tabeli `migrations`) to też „jeszcze nie";
  surowego komunikatu z bazy nie wypisujemy.
- **Limit czekania: 900 s** (`MIGRACJE_LIMIT_S`; pre-deploy web ma 600 s).
  Po nim kontener kończy się kodem 1 i log mówi: `nie startuję na starym
  schemacie`. Najczęstsza przyczyna: migracja web się nie udała — sprawdź log
  jej pre-deploy. Railway ponawia worker (`ON_FAILURE`, 10 prób) i scheduler
  (`ALWAYS`); po wyczerpaniu prób zrestartuj usługę ręcznie.
- Przerwa między próbami: `MIGRACJE_ODSTEP_S` (domyślnie 5 s).
- **Awaryjne wyłączenie**: `MIGRACJE_BRAMKA=0` w zmiennych usługi (log ostrzega
  wprost). Używaj tylko wtedy, gdy sama bramka blokuje start, a schemat jest
  sprawdzony ręcznie.
- Rola `all` (dzisiejsza produkcja) bramki nie potrzebuje: działa w kontenerze
  web, który wstaje dopiero po pre-deploy.
- Cofnięcie kodu workera (schemat NOWSZY niż kod) nie blokuje startu: liczą się
  tylko migracje, które obraz zna, a baza ich jeszcze nie ma.

Czego bramka **nie** załatwia — to nadal zgodność wsteczna migracji:

- W trakcie przełączenia **stare** procesy (okno drenowania `worker`/`scheduler`,
  stary kontener `all`) pracują dalej na już zmigrowanym schemacie. Migracja
  musi więc być zgodna z kodem poprzedniej wersji (expand → switch → contract);
  `DROP`/`RENAME` kolumny w jednym wdrożeniu z jej użyciem jest zakazane
  (ścieżka C w `docs/infra/DEPLOYMENT_RUNBOOK.md`, „Rollback").
- Przez czas czekania nowy worker nie przetwarza kolejki, a zadania czekają
  w tabeli `jobs` (nie giną). Długa migracja = dłuższa cisza workera.

Test: `bash tests/skrypty/bramka-migracji.sh` (atrapa `php`, bez bazy; ten sam
plik ma kontrole ujemne na sześciu zepsutych kopiach bramki), wołany z
`scripts/check.sh`.

## Konto gospodarza (`KUKING_HOST_USER_ID`, #1089)

Mechanizmy społeczności (auto-obserwowanie przy rejestracji, alert pierwszego
wpisu, wykluczenia w analityce) rozpoznają gospodarza po stabilnym UUID konta,
nie po nazwie profilu. Nazwę da się zmienić, a zwolnioną może zająć ktoś inny.

Przed wdrożeniem kodu z #1089:

1. Odczytaj UUID aktualnego konta gospodarza zapytaniem **tylko do odczytu**
   (np. w konsoli bazy z rolą bez prawa zapisu albo w transakcji
   `BEGIN READ ONLY; … ROLLBACK;`):

   ```sql
   SELECT u.id, p.username
   FROM users u JOIN profiles p ON p.user_id = u.id
   WHERE lower(p.username) = lower('<obecna nazwa gospodarza>');
   ```

   Oczekiwany wynik: dokładnie jeden wiersz. Zero albo więcej wierszy —
   zatrzymaj się i wyjaśnij, zanim cokolwiek ustawisz.
2. Wpisz wartość `id` do **Shared Variable** `KUKING_HOST_USER_ID` środowiska
   (wartość tylko w platformie, nie w repozytorium). `railway.ts` przekazuje
   ją web i schedulerowi; każde środowisko ma własny UUID.
3. Wdróż kod. Dopiero potem gospodarz może zmienić nazwę profilu.

Zachowanie konfiguracji:

- **`KUKING_HOST_USER_ID` pusty** — działa przejściowy fallback po nazwie
  z `KUKING_HOST_USERNAME` (jak przed #1089). Jest bezpieczny tylko dopóki
  nikt nie zmienia nazwy konta gospodarza.
- **`KUKING_HOST_USER_ID` ustawiony poprawnie** — nazwa profilu nie ma
  znaczenia; `KUKING_HOST_USERNAME` nie jest czytane przez te mechanizmy.
- **`KUKING_HOST_USER_ID` ustawiony, ale błędny** (nie-UUID albo nieistniejące
  konto) — serwis celowo działa **bez gospodarza** i nie cofa się do nazwy.
  Nowi użytkownicy nie obserwują nikogo automatycznie, alert pierwszego wpisu
  nie trafia do nikogo. Sprawdź wartość i popraw zmienną.

Migracja `2026_09_21_100900_create_first_post_events` (już wdrożona) nie jest
zmieniana: jej backfill wykonał się raz, po nazwie gospodarza z dnia
wdrożenia. Jej `down()` nadal odtwarza dane po `KUKING_HOST_USERNAME`; jeżeli
nazwa gospodarza się zmieni, rollback tej migracji może świadomie odmówić
(D-088) — to bezpieczny kierunek, dane zostają.

## Rollback

Kod:
- poprzedni commit/deployment.

Baza:
- migracje mają być backward-compatible;
- rollback kodu nie naprawia automatycznie destrukcyjnej migracji.

## Staging

Osobna:
- baza;
- storage;
- secrets.

Nie kopiować produkcyjnych danych użytkowników bez potrzeby i podstawy.
