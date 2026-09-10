# Kuking.pl — decyzja architektoniczna wdrożenia

**Data:** 2026-09-05
**Zakres:** hosting, deploy, storage zdjęć, DNS, sekrety, backupy, obserwowalność, koszty
**Status:** rekomendacja do zatwierdzenia przez właściciela

---

## 1. Decyzja w jednym akapicie

**Railway + Cloudflare, bez Workers.** Railway hostuje aplikację (Laravel na własnym
obrazie Docker z FrankenPHP) oraz PostgreSQL 18. Cloudflare pełni trzy role:
DNS dla `kuking.pl`, CDN/WAF przed Railway oraz R2 jako storage zdjęć.
**Czwartej roli — CDN-u przed R2 na subdomenie `cdn.kuking.pl` — już nie ma:
zdjęcia nie mają własnego adresu, patrz ostrzeżenie w §7.**
**Cloudflare Workers odrzucamy** —
wszystko, do czego byłyby tu potrzebne, robią deklaratywne Cache Rules, Redirect
Rules i R2 custom domain, bez kodu do utrzymania. Deploy jest w pełni
automatyczny przez integrację GitHub ↔ Railway (`main` → produkcja, `staging` →
staging, PR → środowisko preview), a infrastruktura jest opisana w
`.railway/railway.ts` (Railway Infrastructure as Code, TypeScript, GA).

---

## 2. Architektura

> ⚠️ **SPROSTOWANIE, 9 września 2026.** Skrzynka „środowisko PRODUCTION"
> niżej pokazywała trzy osobne serwisy (`web`/`worker`/`scheduler`) i bazę
> z „Backups: Daily + Weekly" oraz „PITR: włączone (~4 tyg.)". **Nieprawda.**
> Zmierzone connectorem Railway tego dnia: produkcja to **jeden** serwis
> aplikacyjny, `kuking.pl`, uruchamiany komendą
> `/usr/local/bin/kuking-entrypoint all` (serwer HTTP + kolejka + harmonogram
> w jednym kontenerze). Trzy-serwisowy podział to `PRODUCTION_SPLIT_SERVICES`
> w `.railway/railway.ts` — stan DOCELOWY, do którego `railway config apply`
> nigdy nie zostało uruchomione. Backupy i PITR nie istnieją wcale: to
> funkcje planu Pro, a Kuking jest na Free/Hobby (D-043, `docs/DECISIONS.md`).
> Diagram niżej pokazuje stan faktyczny.

```text
                          ┌──────────────────────────┐
                          │      UŻYTKOWNIK          │
                          │  przeglądarka / PWA      │
                          └────────────┬─────────────┘
                                       │ HTTPS (TLS 1.3)
                     ┌─────────────────┴──────────────────┐
                     │                                    │
        kuking.pl / www.kuking.pl        cdn.kuking.pl (WYCOFANE, D-020)
                     │                                    │
╔════════════════════▼════════════════════════════════════▼══════════════════╗
║                        CLOUDFLARE  (plan Free)                             ║
║                                                                            ║
║   DNS (strefa kuking.pl)  •  TLS na krawędzi  •  HSTS  •  Brotli           ║
║   WAF Managed Rules       •  Rate limiting /logowanie, /rejestracja        ║
║                                                                            ║
║   ┌────────────────────────────────┐   ┌────────────────────────────────┐  ║
║   │  CACHE RULES (aplikacja)       │   │  CACHE RULES (media) WYCOFANE  │  ║
║   │  /build/*        → 1 rok       │   │  cdn.kuking.pl/*               │  ║
║   │  /favicon, /sw.js→ 1 godz.     │   │  → Cache Everything, 30 dni    │  ║
║   │  /livewire/*     → BYPASS      │   │  ↑ NIE ODTWARZAĆ — D-020       │  ║
║   │  cookie sesji    → BYPASS      │   └───────────────┬────────────────┘  ║
║   └───────────────┬────────────────┘                   │                   ║
╚═══════════════════╪════════════════════════════════════╪═══════════════════╝
                    │ proxy (orange cloud)               │ R2 custom domain
                    │ SSL: Full (strict)                 │ (nie przez Railway)
                    │                                    │
                    │                          ╔═════════▼══════════════════╗
                    │                          ║  CLOUDFLARE R2             ║
                    │                          ║  bucket: kuking-media      ║
                    │                          ║  (BEZ własnej domeny —     ║
                    │                          ║   D-020; adresem zdjęcia   ║
                    │                          ║   jest /zdjecia/{media}/…) ║
                    │                          ║                            ║
                    │                          ║  media/…/thumb-320.webp    ║
                    │                          ║  media/…/feed-960.webp     ║
                    │                          ║  media/…/large-1600.webp   ║
                    │                          ║  egress do internetu: 0 zł ║
                    │                          ╠════════════════════════════╣
                    │                          ║  bucket: kuking-oryginaly  ║
                    │                          ║  (PRYWATNY — bez domeny,   ║
                    │                          ║   r2.dev wyłączone)        ║
                    │                          ║                            ║
                    │                          ║  incoming/{uuid}.jpg       ║
                    │                          ║  pełny EXIF, GPS           ║
                    │                          ║  dostęp: tylko API S3      ║
                    │                          ╚═════════▲══════════════════╝
                    │                                    │
                    │                          presigned PUT (upload)
                    │                          + S3 API (zapis wariantów)
╔═══════════════════▼════════════════════════════════════╪═══════════════════╗
║                RAILWAY   region europe-west4-drams3a (Amsterdam)           ║
║                projekt: ideal-exploration (nazwa nadana przez Railway)     ║
║                                                                            ║
║  ┌─ środowisko PRODUCTION ─────────────────────────────────────────────┐  ║
║  │   ┌─────────────────────────────────────────────────────────────┐   │  ║
║  │   │            kuking.pl — JEDEN serwis, tryb `all`             │   │  ║
║  │   │            FrankenPHP + Caddy · :8080 · /health             │   │  ║
║  │   │                                                             │   │  ║
║  │   │                 pętla kolejki (queue:work)                  │   │  ║
║  │   │                 harmonogram (schedule:work)                 │   │  ║
║  │   │                                                             │   │  ║
║  │   │        pre-deploy: migrate --force --no-interaction         │   │  ║
║  │   │               1 replika, region europe-west4                │   │  ║
║  │   │                                                             │   │  ║
║  │   │         ⚠ tryb `all` jest kruchy: padnięcie procesu         │   │  ║
║  │   │          ubija stronę i kolejkę razem — patrz §10           │   │  ║
║  │   └─────────────────────────────────────────────────────────────┘   │  ║
║  │                                  │                                  │  ║
║  │                                  ▼                                  │  ║
║  │   ┌─────────────────────────────────────────────────────────────┐   │  ║
║  │   │                      Postgres (PG 18)                       │   │  ║
║  │   │                dane • sesje • cache • queue                 │   │  ║
║  │   │                                                             │   │  ║
║  │   │               Backups: BRAK (plan Free/Hobby)               │   │  ║
║  │   │                PITR: BRAK (plan Free/Hobby)                 │   │  ║
║  │   │                                                             │   │  ║
║  │   │            Jedyna planowana kopia: zrzut z #193             │   │  ║
║  │   │             DZIŚ: nie istnieje żadna kopia bazy             │   │  ║
║  │   │             (decyzja D-043, docs/DECISIONS.md)              │   │  ║
║  │   └─────────────────────────────────────────────────────────────┘   │  ║
║  └─────────────────────────────────────────────────────────────────────┘  ║
║                                                                            ║
║  ┌─ STAGING (staging.kuking.pl) ──┐  ┌─ PREVIEW pr-123 (efemeryczne) ──┐  ║
║  │  web (APP_ROLE=all) + postgres │  │  web (APP_ROLE=all) + postgres  │  ║
║  │  Serverless ON (usypianie)     │  │  Serverless ON                  │  ║
║  │  własne sekrety, własna baza   │  │  tworzone/usuwane automatycznie │  ║
║  └────────────────────────────────┘  └─────────────────────────────────┘  ║
╚════════════════════════════════════════════════════════════════════════════╝
              ▲                                    ▲
              │ build + deploy                     │ deployment_status
              │ (Wait for CI)                      │
╔═════════════╪════════════════════════════════════╪═════════════════════════╗
║                            GITHUB                                          ║
║  woogitsu/kuking.pl                                                        ║
║                                                                            ║
║  push main    ──► CI (Pint, Larastan, testy PG18, Vite, audyt, obraz)      ║
║                   └─ zielone ──► Railway buduje i wdraża production        ║
║                                  └─ sukces ──► deploy.yml: smoke + Sentry  ║
║  push staging ──► CI ──► Railway wdraża staging                            ║
║  PR otwarty   ──► CI ──► Railway tworzy pr-N ──► preview.yml: smoke        ║
║  PR .railway/*──► railway-iac.yml: plan w komentarzu ──► merge ──► apply   ║
╚════════════════════════════════════════════════════════════════════════════╝

        ┌──────────────┐  ┌──────────────┐  ┌────────────────────────┐
        │   SENTRY     │  │   POSTHOG    │  │  Resend / Brevo        │
        │  błędy       │  │  EU instance │  │  poczta transakcyjna   │
        │  + wydania   │  │  produkt     │  │  (SMTP)                │
        └──────────────┘  └──────────────┘  └────────────────────────┘
                    ┌────────────────────────────────┐
                    │ UptimeRobot / Better Stack     │
                    │ zewnętrzny monitoring /health  │
                    │ (Railway NIE monitoruje go     │
                    │  po deployu!)                  │
                    └────────────────────────────────┘
```

## DWA BUCKETY R2, NIE JEDEN — to granica bezpieczeństwa

Ten dokument mówił wcześniej „bucket: kuking-media, incoming/ (prywatne)".
To założenie było nieprawdziwe i kosztowałoby wyciek danych osobowych
(audyt G-01).

**Cloudflare R2 nie implementuje S3-owych ACL na obiektach.** `x-amz-acl` jest
w tabeli zgodności oznaczony jako NIEOBSŁUGIWANY dla `PutObject`. Publiczność
w R2 jest cechą BUCKETU: własnej domeny albo `r2.dev`. Prefiks nie jest
granicą uprawnień — jest tylko fragmentem nazwy klucza.

### Aplikacja nie wysyła już ACL wcale (issue #120, 9 września 2026)

Samo niepodawanie widoczności nie wystarczało: wbudowany sterownik `s3`
liczył ACL **zawsze** (`AwsS3V3Adapter::upload()`), a przy braku podanej
widoczności wypadało `private`. Nagłówek szedł więc do R2 przy każdym
zapisie, a prywatność oryginałów zależała od tego, jak Cloudflare zareaguje
na nagłówek, którego nie obsługuje — czyli od zachowania niegwarantowanego.

Dyski `r2`, `r2_publiczne`, `r2_legacy` i `r2_eksporty` mają dziś
`driver => 'r2'` — własny sterownik (`app/Support/Storage/R2Adapter.php`),
który nie wysyła ani `x-amz-acl`, ani `x-amz-grant-*`, i nigdy nie woła
`GetObjectAcl`/`PutObjectAcl`. Pilnuje tego `ZapisDoR2BezAclTest`, na
prawdziwym, podpisanym żądaniu HTTP — bo w `Storage::fake()` nagłówki nie
istnieją.

**To jest połowa bramki #120 — ta, którą da się zrobić kodem.** Druga połowa
(dwanaście dowodów na prawdziwym R2) jest w `docs/infra/BRAMKA_R2.md`
i należy do właściciela.

Bucket wystawiony pod `cdn.kuking.pl` wystawiał więc CAŁĄ zawartość, razem
z `incoming/`. A adres oryginału dawał się wyprowadzić z publicznego adresu
wariantu, bo obie ścieżki dzielą UUID właściciela, datę i UUID pliku:

```text
https://cdn.kuking.pl/media/{wlasciciel}/2026/09/{uuid}_feed.webp   ← znany
https://cdn.kuking.pl/incoming/{wlasciciel}/2026/09/{uuid}.jpg      ← oryginał
```

Wystarczyło zamienić prefiks, uciąć `_feed` i zgadnąć rozszerzenie spośród
czterech dozwolonych. W oryginale siedzi pełny EXIF, czyli współrzędne GPS
kuchni, w której zrobiono zdjęcie — dokładnie to, przed czym cały potok
mediów miał chronić.

> ### ⚠️ TA TABELA OPISYWAŁA STAN SPRZED D-020 — POPRAWIONA 9 WRZEŚNIA
>
> Wiersz `kuking-media` mówił „własna domena `cdn.kuking.pl`, publiczny
> odczyt". **Decyzja D-020 zabrała temu bucketowi domenę**, bo adres pliku
> w CDN-ie nikogo o nic nie pyta i nie przestaje działać: kto raz go
> skopiował, otwierał zdjęcie także po zablokowaniu, po przełączeniu przepisu
> na prywatny i po decyzji moderacyjnej. Cała macierz widoczności obowiązywała
> stronę HTML i ani jednego piksela.
>
> Zostawienie tu starego opisu było groźne w konkretny sposób: runbook czyta
> się przy pierwszym wdrożeniu albo przy awarii i **wykonuje po kolei**.
> Zdanie „bucket wariantów ma własną domenę" to instrukcja odtworzenia tej
> luki. Znalazł to audyt zewnętrzny (G14) — `DEPLOYMENT_RUNBOOK.md` poprawiono
> w #168, ale ten plik został.

| bucket | zawartość | własna domena | `r2.dev` | dostęp |
|---|---|---|---|---|
| `kuking-oryginaly` | `incoming/` — pliki od użytkownika, pełny EXIF bez GPS | **NIE** | **wyłączone** | wyłącznie API S3 z serwera |
| `kuking-media` | `media/` — warianty WebP bez EXIF | **NIE** (D-020) | **wyłączone** | trasa `/zdjecia/{media}/{wariant}` → Policy → 302 na krótko podpisany adres |

**Adresem zdjęcia jest trasa aplikacji, nie plik.** `Media::url()` zwraca
`route('media.show')`; `MediaController` pyta `DostepDoZdjecia`, ta odwraca
listę rodziców i woła ICH Policy przez `Gate`, a odmowa to 404 nieodróżnialne
od zdjęcia nieistniejącego. Bajty nie idą przez PHP — po decyzji leci
przekierowanie.

**Cena, wprost:** każde żądanie zdjęcia to żądanie do Laravela i kilka zapytań
o rodziców. D-020 świadomie tego nie optymalizuje; dopiero pomiar z produkcji
ma rozstrzygnąć, czy potrzebny jest cache decyzji.

**Czego kod nie załatwia:** zdjęcie klucza `url` z konfiguracji NIE zdejmuje
domeny z bucketu po stronie Cloudflare. Dopóki `cdn.kuking.pl` tam wskazuje,
stare adresy działają dalej — to jest **issue #120** i należy do właściciela.
Lista kontrolna z miejscem na datę: `docs/infra/BRAMKA_R2.md`.

W aplikacji odpowiadają im dyski `r2` i `r2_publiczne`
(`config/filesystems.php`), a w `railway.ts` zmienne `R2_BUCKET`
i `R2_PUBLIC_BUCKET`. Dysk `r2` **świadomie nie ma klucza `url`** — dzięki
temu `Storage::url()` na oryginale rzuci wyjątek zamiast po cichu zwrócić
adres, który publiczny być nie może. Pilnuje tego `RozdzialMagazynowTest`.

**Blokada WAF na `/incoming/*` nie jest równoważnikiem.** Jest lepsza niż nic,
ale jedna omyłkowo usunięta reguła wystawia dane z powrotem; osobny bucket bez
domeny nie ma czego wystawić.

**Czego ten test NIE dowodzi:** że produkcyjne buckety są tak skonfigurowane.
Tego z PHP nie widać. Przed wystawieniem produkcji trzeba przejść bramkę na
prawdziwym R2 — sprawdzenie, że znany adres oryginału zwraca 403/404 przez
każdą publiczną ścieżkę, a serwer czyta go po S3.

### Podział odpowiedzialności

| Warstwa | Co robi | Czego NIE robi |
|---|---|---|
| **Cloudflare DNS** | strefa `kuking.pl`, CNAME flattening na apeksie | nie hostuje niczego |
| **Cloudflare proxy** | TLS, HSTS, kompresja, WAF, rate limiting, cache assetów | nie cache'uje stron zalogowanych ani Livewire |
| **Cloudflare R2** | trwały storage zdjęć, egress do internetu bez opłat | nie przetwarza obrazów (robi to worker) |
| **Railway web** | HTTP, Livewire, sesje, migracje w pre-deploy | nie przetwarza zdjęć, nie trzyma plików |
| **Railway worker** | kolejka: zdjęcia, maile, eksporty, indeks wyszukiwania | nie obsługuje ruchu HTTP |
| **Railway scheduler** | zadania cykliczne (`schedule:work`) | nie wykonuje ciężkiej pracy — kolejkuje ją |
| **Railway postgres** | dane, sesje, cache, kolejka, FTS | nie jest wystawiony publicznie |
| **GitHub Actions** | CI (bramka), IaC plan/apply, smoke testy, operacje awaryjne | **nie buduje ani nie wdraża aplikacji** |

---

## 3. Czy Cloudflare Workers mają tu sens? — **NIE**

To jest świadome „nie", nie przeoczenie. Rozważyłem cztery typowe zastosowania:

| Kandydat na Workera | Werdykt | Dlaczego |
|---|---|---|
| **Przeskalowywanie obrazów na krawędzi** | **NIE** | Warianty (320/960/1600 px) generuje worker Laravela raz, przy uploadzie, i zapisuje do R2 — zgodnie z `docs/MEDIA_PIPELINE.md`. Koszt: 0 zł za transformację. Cloudflare Images liczyłby $0,50/1000 unikalnych transformacji ponad 5000/mies. — przy 10 000 użytkowników to setki tysięcy transformacji. Pre-generowanie jest tańsze, szybsze (cache hit od pierwszego wejścia) i deterministyczne. |
| **Cache / reguły cache'owania** | **NIE** | Cache Rules robią to deklaratywnie, w panelu, bez kodu. Worker byłby regresją: więcej kodu, ta sama funkcja. |
| **Przekierowania (`www` → apex, stare URL-e)** | **NIE** | Redirect Rules (dawne Page Rules) obsługują to na planie Free, z regexem włącznie. |
| **Edge middleware / auth na krawędzi** | **NIE** | Sesje Laravela są w Postgresie i szyfrowane. Worker nie ma jak ich odczytać bez dostępu do bazy i `APP_KEY` — a przeniesienie tam `APP_KEY` byłoby pogorszeniem bezpieczeństwa. Autoryzacja zostaje w jednym miejscu: w Laravelu. |

**Kiedy wrócić do tej decyzji.** Worker zacznie mieć sens, gdy:

1. pojawią się **prywatne zdjęcia** wymagające autoryzacji per-obiekt na krawędzi
   (dziś wszystkie opublikowane zdjęcia są publiczne, a nieprzetworzone leżą
   w prywatnym prefiksie `incoming/`);
2. będziesz potrzebować **wariantów on-the-fly** w rozmiarach nieznanych z góry
   (np. redesign z 8 breakpointami) — wtedy Cloudflare Images Transformations,
   ewentualnie z Workerem jako warstwą podpisywania URL-i;
3. zaczniesz robić **A/B testy albo personalizację na krawędzi**.

Do tego czasu Workers to dodatkowy runtime, dodatkowy deploy i dodatkowe miejsce
na błąd — przy zerowej korzyści. **Ostrzeżenie techniczne:** Workers nie mogą
działać w ścieżce `kuking.pl` bez zmiany routingu, a wpuszczenie ich przed
Railway wprowadziłoby trzecie proxy w łańcuchu, co komplikuje nagłówki
`X-Forwarded-*` i debugowanie prawdziwego IP użytkownika.

---

## 4. Środowiska

| | production | staging | preview (per PR) |
|---|---|---|---|
| Domena | `kuking.pl`, `www` | `staging.kuking.pl` | `*.up.railway.app` |
| Gałąź | `main` | `staging` | gałąź PR-a |
| Serwisy | `kuking.pl` (tryb `all`) + `Postgres` — **stan dziś, 9 IX 2026**; `web` + `worker` + `scheduler` + `postgres` to cel `.railway/railway.ts`, `railway config apply` jeszcze nie uruchomione (D-043) | web (`APP_ROLE=all`) + postgres | web (`APP_ROLE=all`) + postgres |
| Baza | **własna** | **własna** | **własna**, tworzona z kopii staginu |
| Sekrety | **własne** | **własne, nieprodukcyjne** | dziedziczone ze **staginu** |
| R2 | `kuking-media` | `kuking-media-staging` | `kuking-media-staging` (wspólny) |
| Serverless (usypianie) | **WYŁĄCZONE** | WŁĄCZONE | WŁĄCZONE |
| `APP_DEBUG` | `false` | `false` | `false` |
| Cykl życia | trwałe | trwałe | usuwane przy zamknięciu PR |
| Cloudflare proxy | tak | tak | nie (domena Railway) |

### Co jest współdzielone, a co nie

**Nigdy nie współdzielone:** baza danych, `APP_KEY`, klucze R2 produkcyjne,
klucze SMTP produkcyjne. Staging nie może fizycznie dotknąć danych
produkcyjnych — to wymóg RODO, nie preferencja.

**Współdzielone świadomie:** bucket R2 między staginem a preview (dane testowe
są bezwartościowe, a osobny bucket na każdy PR to bałagan), projekt Sentry
(rozróżniany polem `environment`), projekt PostHog.

### Jak nie zabić budżetu

1. **Serverless na staging i preview.** Nieużywane środowisko usypia się po
   ~5–10 min bez ruchu wychodzącego i kosztuje wtedy groszowo. Na produkcji
   **wyłączone** — pierwszy request do uśpionego serwisu może zwrócić 502
   i ma cold start, co dla audytorium 50+ znaczy „strona nie działa".
2. **Środowiskiem bazowym PR-ów jest `staging`, nie `production`.** Inaczej
   każdy PR dostawałby kopię produkcyjnych sekretów.
3. **`Focused PR Environments`** + `watchPatterns` — PR dotykający tylko `docs/`
   nie stawia środowiska.
4. **Bot PR Environments wyłączone.** Dependabot otwiera kilkanaście PR-ów
   miesięcznie; każdy z nich stawiałby własną bazę.
5. **Limity pamięci per serwis** (`deploy.limitOverride`) — wyciek pamięci kończy
   się OOM killem widocznym w logach, a nie eskalacją na fakturze.
6. **Alert budżetowy** w Railway (Workspace → Usage → Usage Limits) —
   twardy hamulec.
7. **Zamykaj PR-y.** 20 zapomnianych otwartych PR-ów to 20 baz danych.

---

## 5. Serwisy w Railway — kiedy rozdzielać

Plik `railway.ts` ma jeden przełącznik: `PRODUCTION_SPLIT_SERVICES`.

### Faza alfa (`false`) — produkcja jako jeden serwis

Jeden kontener w trybie `APP_ROLE=all` uruchamia FrankenPHP, `queue:work`
i `schedule:work` obok siebie (patrz `docker/entrypoint.sh`). Koszt Railway
spada z ~$45 do ~$13/mies. Przy 50 użytkownikach i kilkunastu zdjęciach dziennie
nikt nie zauważy, że przetwarzanie obrazu zabiera chwilę CPU stronie.

### Faza beta i dalej (`true`) — trzy serwisy

Rozdziel, gdy zajdzie **którykolwiek** z warunków:

- p95 czasu odpowiedzi rośnie w godzinach szczytu uploadów;
- kolejka `jobs` regularnie ma zaległości > 100 rekordów;
- worker padł na OOM i zabrał ze sobą stronę;
- deploy nie może już przerywać przetwarzania zdjęć.

**Dlaczego w ogóle rozdzielać:**

1. `ProcessUploadedImage` jest **CPU-bound**. W jednym kontenerze z web kradnie
   CPU requestom użytkowników.
2. web i worker **skalują się w innych momentach**: ruch wieczorami, kolejka
   zdjęć po weekendzie.
3. **Izolacja awarii.** Worker może paść na OOM przy patologicznym pliku;
   razem z web zabrałby całą stronę.
4. **Graceful shutdown.** Worker dostaje 120 s na dokończenie zadania
   (`drainingSeconds`), web tylko 30 s. W jednym kontenerze nie da się mieć obu.

**Scheduler jest zawsze osobny w topologii rozdzielonej i ma dokładnie 1 replikę.**
Dwie repliki = dwa razy ten sam digest w skrzynce użytkownika.

**Dlaczego scheduler jako długożyjący proces, a nie Railway Cron:** Railway Cron
ma **minimalną granulację 5 minut** i nie gwarantuje dokładności co do minuty,
a scheduler Laravela musi być odpytywany **co minutę**, żeby `everyMinute()`
i `everyFiveMinutes()` działały zgodnie z definicją. Dlatego `schedule:work`.

---

## 6. Build: własny Dockerfile — decyzja i uzasadnienie

**Decyzja: własny, wieloetapowy `Dockerfile` z FrankenPHP.** Nie Railpack, nie Nixpacks.

Railway domyślnie buduje przez **Railpack** (następca Nixpacks) i faktycznie
rozpoznaje Laravela, uruchamiając go przez php-fpm + Caddy. Odrzucamy to, bo:

| Powód | Konsekwencja Railpacka |
|---|---|
| **Zdjęcia to rdzeń produktu** | Potrzebujemy deterministycznego zestawu rozszerzeń: `pdo_pgsql`, `pgsql`, `intl`, `gd` (z webp/avif), `zip`, `exif`, `pcntl`, `bcmath`, `opcache`. Railpack tego nie gwarantuje i może zmienić między buildami. Brak `exif` = brak usuwania GPS ze zdjęć = wyciek lokalizacji domu użytkownika. |
| **Parytet dev / CI / produkcja** | Ten sam obraz chodzi lokalnie, w CI (job `docker-build`) i na produkcji. Z Railpackiem „działa u mnie" nic nie znaczy. |
| **Jeden obraz, trzy role** | `APP_ROLE` przełącza web/worker/scheduler. Bez tego trzeba by trzymać trzy konfiguracje buildu. |
| **Wyjście z vendor lock-in** | Obraz uruchomisz na Fly.io, Hetznerze, Cloud Run czy VPS bez zmiany linii kodu. Railpack działa **tylko** w Railway. |
| **Kontrola nad `php.ini`** | `opcache`, `memory_limit`, `upload_max_filesize` — krytyczne przy zdjęciach z telefonów. |

Koszt decyzji: ~80 linii do utrzymania. Warto.

### FrankenPHP, nie nginx + php-fpm + supervisord

FrankenPHP to Caddy z wbudowanym PHP: **jeden proces, jeden PID, jeden config**.
Klasyczny stos to trzy procesy, trzy configi i ręczna obsługa sygnałów — a Railway
wysyła `SIGTERM` i oczekuje graceful shutdown. Caddy robi drain sam. Dodatkowo
z pudełka: HTTP/2, kompresja zstd/brotli, Early Hints.

**Świadomie NIE włączamy trybu worker (Octane).** Livewire 4 trzyma stan komponentów
w requeście, a długożyjący worker PHP zwiększa ryzyko wycieku stanu między
użytkownikami. Tryb klasyczny + OPcache jest wystarczająco szybki, a bezpieczniejszy.
Warunek włączenia: >~300 req/s na replikę **i** audyt singletonów.

**Debian trixie, nie Alpine.** Pełny ICU dla `intl` (polskie sortowanie, formaty
daty) i przewidywalny `gd`. musl na Alpine potrafi tu zaskoczyć, a oszczędność
~60 MB obrazu nie jest tego warta.

### Fazy: co się dzieje kiedy

```text
BUILD (bezstanowy, bez sekretów, bez sieci prywatnej)
  ├─ npm ci + vite build                → public/build/*
  ├─ composer install --no-dev
  │     --optimize-autoloader
  │     --classmap-authoritative        → vendor/
  ├─ composer dump-autoload
  ├─ php artisan package:discover       → bootstrap/cache/packages.php
  └─ obraz gotowy

RELEASE (pre-deploy, MA sekrety i sieć prywatną, RAZ na deploy)
  └─ php artisan migrate --force        ← BŁĄD = DEPLOY ZATRZYMANY

RUN (start kontenera, per replika)
  ├─ walidacja APP_KEY, DB_URL
  ├─ php artisan optimize:clear
  ├─ php artisan optimize               ← config/route/view/event cache
  └─ exec frankenphp | queue:work | schedule:work
```

#### Dlaczego `config:cache` w RUNTIME, nie w buildzie

`php artisan config:cache` **zamraża wartości `env()`** w pliku PHP. W buildzie
Railway nie ma jeszcze produkcyjnych zmiennych. Zapiekłbyś tam puste `DB_URL`,
`APP_KEY` i klucze R2, a potem debugował, dlaczego produkcja łączy się z niczym.
Dlatego `php artisan optimize` (config + route + view + event) uruchamiamy
w entrypoincie. Koszt: ~1 s przy starcie kontenera. Zysk: eliminacja całej klasy
błędów „stary cache po deployu".

#### Dlaczego `migrate --force` w RELEASE, nie w BUILD

1. Build jest **bezstanowy** i uruchamiany też dla PR-ów, bez dostępu do
   produkcyjnej bazy ani do prywatnej sieci.
2. Pre-deploy **ma** dostęp do zmiennych serwisu i prywatnej sieci.
3. Niezerowy exit code pre-deploy **zatrzymuje deploy** — zła migracja nie
   wypuści kodu, który jej wymaga.
4. Pre-deploy leci **raz na deploy**, nie raz na replikę. Migracje w start
   command przy 2 replikach uruchomiłyby się równolegle → wyścig o blokady.

`--force` jest konieczne: przy `APP_ENV=production` artisan pyta o potwierdzenie,
a w kontenerze nie ma kto odpowiedzieć — komenda zawisłaby do timeoutu.

#### Healthcheck, graceful shutdown, `APP_KEY`

- **`/health`** musi sprawdzać połączenie z bazą. Healthcheck zwracający zawsze
  200 nie chroni przed niczym. Railway odpytuje go **tylko przy deployu**
  i **nie monitoruje później** — stąd zewnętrzny uptime monitor (§10).
- Requesty healthchecku idą z hosta **`healthcheck.railway.app`**. Jeśli włączysz
  middleware `TrustHosts`, **musisz** dopisać ten host, inaczej deploy będzie
  padał na 400.
- **Graceful shutdown:** `drainingSeconds` = 30 s (web) / 120 s (worker).
  `tini` jako PID 1 przekazuje `SIGTERM`; `queue:work` z rozszerzeniem `pcntl`
  dokańcza bieżące zadanie zamiast porzucić je w połowie przetwarzania zdjęcia.
- **`APP_KEY`** generujesz **raz na środowisko** i traktujesz jak sekret
  najwyższej wagi. Entrypoint **odmawia startu**, gdy jest pusty — lepiej
  czytelny błąd w logach niż 500 na każdym requeście. **Zmiana `APP_KEY` na
  działającej produkcji unieważnia wszystkie sesje i uniemożliwia odszyfrowanie
  danych zaszyfrowanych starym kluczem.** To operacja jednokierunkowa.

---

## 7. Storage zdjęć: R2 od pierwszego dnia

### Decyzja

**Cloudflare R2, `FILESYSTEM_DISK=r2`, od dnia pierwszego.** Nie Railway Volume,
nie AWS S3.

| Opcja | Storage | Egress | Werdykt |
|---|---|---|---|
| **Cloudflare R2** | **$0,015/GB-mies.** | **0 zł** | **WYBRANE** |
| Railway Volume | $0,15/GB-mies. (10× drożej) | $0,05/GB przez aplikację | Odrzucone |
| Railway Buckets | $0,015/GB-mies., operacje darmowe | egress serwisu | Rozważne, ale bez custom domain i cache CF |
| AWS S3 | ~$0,023/GB-mies. | **~$0,09/GB** | Odrzucone |

**Dlaczego nie Railway Volume — argument rozstrzygający:** Railway
udokumentowanie **wprowadza przestój przy każdym redeployu serwisu z zamontowanym
volume'em**, żeby zapobiec uszkodzeniu danych — nawet z działającym healthcheckiem.
Zdjęcia na volume'ie oznaczałyby, że każdy deploy web to widoczna przerwa
w działaniu strony. Do tego: limit 5 GB na planie Hobby, brak CDN, dane
przywiązane do jednego serwisu i regionu.

**Dlaczego R2 nad Railway Buckets — z poprawką z 9 września.** Pierwotny powód
brzmiał: „R2 daje własną domenę (`cdn.kuking.pl`), a przez to Cloudflare Cache,
WAF i Tiered Cache przed zdjęciami". **Ten powód przestał obowiązywać razem
z D-020** — bucket wariantów świadomie NIE ma już własnej domeny, bo adres
pliku omijał całą macierz widoczności.

Wybór R2 zostaje, ale na innych podstawach, które warto wypisać, żeby nikt nie
„przywrócił" domeny w imię tego akapitu: zerowy koszt egressu (przy zdjęciach
to główna pozycja rachunku), ta sama konsola i to samo konto co DNS i WAF,
oraz zgodność z API S3, dzięki której zmiana dostawcy to zmiana zmiennych
środowiskowych. Cache przed zdjęciami wraca dopiero wtedy, gdy pojawi się CDN
umiejący zapytać Kuking o decyzję, zanim odda plik — patrz „Zmiana wymaga"
w D-020.

**Migracja jest już zabezpieczona:** kod domenowy używa wyłącznie Laravel
Filesystem (`docs/MEDIA_PIPELINE.md`), więc zmiana dostawcy to zmiana zmiennych
środowiskowych, nie przepisywanie domeny.

### Presigned upload bezpośrednio do R2 — TAK

**Decyzja: klient wysyła plik prosto do R2 przez presigned PUT.** Aplikacja
generuje URL, ale sam bajt zdjęcia nigdy nie przechodzi przez Railway.

```text
1. Klient (JS)  → zmniejsza zdjęcie w przeglądarce do max 2560 px  ← KLUCZOWE dla 50+
2. Klient        → POST /media/przygotuj  { nazwa, typ, rozmiar }
3. Aplikacja     → waliduje typ i rozmiar, generuje klucz
                   incoming/{uuid}.{ext}, zwraca presigned PUT (10 min)
4. Klient        → PUT prosto do R2 (z paskiem postępu)
5. Klient        → POST /media/gotowe { id }
6. Aplikacja     → HeadObject: sprawdza realny rozmiar i content-type
                 → kolejkuje ProcessUploadedImage
7. Worker        → magic bytes, realny MIME, limit megapikseli, dekodowalność
                 → usuwa GPS i EXIF
                 → generuje thumb 320 / feed 960 / large 1600 (WebP)
                 → zapisuje do media/... (publiczny prefiks)
                 → usuwa incoming/{uuid} po retention
```

**Uzasadnienie pod kątem UX 50+:**

- Upload zdjęcia 8 MB przez LTE trwa 30–90 s. Przez aplikację ten czas
  **blokuje proces PHP** i naraża na timeout Railway. Prosto do R2 idzie
  na **najbliższy punkt Cloudflare** — szybciej i bez ryzyka po stronie serwera.
- **Zmniejszanie w przeglądarce przed uploadem** (canvas, do 2560 px) redukuje
  8 MB do ~800 KB. To **jedna zmiana, która najbardziej podnosi skuteczność
  uploadu** na słabym łączu — a użytkownik 50+ po nieudanym uploadzie zwykle
  nie próbuje drugi raz.
- Pasek postępu jest wiarygodny (przeglądarka zna postęp `PUT`), a nie „kręcące
  się kółko" bez informacji.

**Uzasadnienie pod kątem bezpieczeństwa — nigdy nie ufamy klientowi:**

- Presigned URL jest ważny **10 minut**, obejmuje **jeden klucz** i **jeden
  `ContentType`**. Nie da się nim nadpisać cudzego pliku ani wgrać czegokolwiek
  poza wyznaczonym miejscem.
- Plik ląduje w prefiksie **`incoming/`, który NIE jest publiczny**. Dopóki worker
  go nie zwaliduje, nikt go nie zobaczy.
- Walidacja właściwa jest **po stronie serwera, po uploadzie**: magic bytes,
  realny MIME, limit megapikseli, próba dekodowania. `ContentType` z presigned
  URL to deklaracja klienta, nie dowód.
- **EXIF i GPS usuwane obowiązkowo** — zdjęcie z kuchni zawiera współrzędne domu
  użytkownika. To wymóg z `docs/MEDIA_PIPELINE.md` i realne ryzyko RODO.
- Klucze R2 mają zakres **tylko do tego jednego bucketa**.

**Ścieżka awaryjna:** jeśli presign zawiedzie (np. blokada CORS w egzotycznej
przeglądarce), formularz przechodzi na upload przez aplikację. Limity w `php.ini`
(`upload_max_filesize=24M`) są ustawione właśnie na tę ścieżkę.

> **Uwaga wdrożeniowa:** Livewire ma wbudowane wsparcie dla tymczasowych uploadów
> na S3, co upraszcza implementację (`livewire.temporary_file_upload.disk`).
> **[do weryfikacji]** dokładna nazwa i kształt tej opcji w Livewire 4 —
> sprawdź w dokumentacji przed implementacją; sama decyzja architektoniczna
> (presigned, bezpośrednio do R2) się nie zmienia.

---

## 8. Domena i DNS w Cloudflare

### Rekordy DNS w strefie `kuking.pl`

| Typ | Nazwa | Wartość | Proxy | Po co |
|---|---|---|---|---|
| CNAME | `kuking.pl` (apex) | `<xxxx>.up.railway.app` | **Proxied** (orange) | Apex przez CNAME flattening Cloudflare |
| CNAME | `www` | `<xxxx>.up.railway.app` | **Proxied** | Obsługa `www`, potem redirect na apex |
| TXT | `_railway.<...>` | wartość z panelu Railway | — (TXT się nie proxuje) | **Weryfikacja własności** |
| CNAME | `staging` | `<yyyy>.up.railway.app` | **Proxied** | Staging |
| TXT | `_railway.staging...` | wartość z panelu Railway | — | Weryfikacja staginu |
| CNAME | `cdn` | tworzone **automatycznie** przez R2 | Proxied (R2 ustawia) | Domena bucketa R2 |
| TXT | `@` | `v=spf1 include:... -all` | — | SPF poczty |
| CNAME/TXT | wg dostawcy poczty | DKIM | — | DKIM |
| TXT | `_dmarc` | `v=DMARC1; p=quarantine; rua=mailto:...` | — | DMARC |

**KRYTYCZNE — najczęstszy błąd:** Railway wymaga **obu** rekordów: CNAME **i**
TXT. Bez rekordu TXT domena zwraca **404 nawet gdy CNAME poprawnie się rozwiązuje**,
bo Railway nie potwierdził własności. Po dodaniu obu zobaczysz w panelu
„Cloudflare proxy detected" — to jest oczekiwane.

### SSL/TLS

- **Tryb: `Full (strict)`.** Railway wystawia dla domeny custom prawdziwy,
  publicznie zaufany certyfikat, więc `strict` jest osiągalny i **jest
  wymagany** — tryb `Flexible` oznaczałby nieszyfrowany odcinek
  Cloudflare → Railway.
- **Wyłącz „Automatic SSL/TLS"**, ustaw **Custom SSL/TLS → Full (strict)**.
  Automatyczny tryb sam dobiera ustawienie na podstawie sondowania; nie chcemy,
  żeby cokolwiek zmieniało nam poziom szyfrowania bez naszej wiedzy.
- **Always Use HTTPS:** ON.
- **Minimum TLS Version:** 1.2.
- **HSTS:** włączyć **po** potwierdzeniu, że wszystko działa po HTTPS.
  `max-age=15552000` (6 mies.), `includeSubDomains` ON, **`preload` dopiero
  po kilku tygodniach stabilności** — preload jest praktycznie nieodwracalny.
- HSTS ustawiamy **tylko w Cloudflare**, nie w Caddy — jedno miejsce, jedna prawda.

### `www` vs apex

**Kanoniczny jest apex `kuking.pl`.** Krótszy, lepszy do wymawiania przez telefon
(istotne dla marketingu w grupie 50+). `www` istnieje i **przekierowuje 301**
na apex — Redirect Rule w Cloudflare:

```text
Gdy: hostname równa się "www.kuking.pl"
Wtedy: dynamic redirect → concat("https://kuking.pl", http.request.uri.path)
       kod 301, zachowaj query string
```

Obie domeny są zadeklarowane w `railway.ts`, żeby Railway wystawił certyfikat
dla obu — inaczej `www` dałoby błąd TLS **przed** wykonaniem przekierowania.

### Cache Rules

**Co cache'ować:**

| Reguła | Warunek | Akcja |
|---|---|---|
| Assety Vite | `starts_with(http.request.uri.path, "/build/")` | Cache eligible, Edge TTL **1 rok**, Browser TTL 1 rok |
| Statyka PWA | ścieżka w `/favicon.ico`, `/robots.txt`, `/manifest.webmanifest` | Edge TTL 1 godz. |
| Media (osobna strefa hosta) | `http.host == "cdn.kuking.pl"` | **Cache Everything**, Edge TTL 30 dni, **Smart Tiered Cache ON** |

Pliki Vite mają hash w nazwie (`app-a1b2c3.js`), więc roczny TTL jest bezpieczny —
nowy build to nowa nazwa.

**Czego NIE cache'ować — reguła BYPASS:**

```text
Gdy którykolwiek warunek:
    starts_with(http.request.uri.path, "/livewire/")
 or starts_with(http.request.uri.path, "/api/")
 or http.request.uri.path in {"/logowanie" "/rejestracja" "/wyloguj"}
 or starts_with(http.request.uri.path, "/konto/")
 or starts_with(http.request.uri.path, "/ustawienia/")
 or http.cookie contains "kuking_session"
 or http.request.method != "GET"
Wtedy: Bypass cache
```

**Dlaczego to jest krytyczne, a nie kosmetyczne:** scache'owanie na krawędzi
strony zalogowanego użytkownika i podanie jej innemu użytkownikowi to **incydent
ochrony danych osobowych**, nie usterka wydajności. Warunek na ciasteczko sesji
jest tu najważniejszy — łapie każdą stronę, której nie wymieniliśmy z nazwy.
Caddy dubluje tę ochronę nagłówkami `Cache-Control: no-store` + `Vary: Cookie`
(`docker/Caddyfile`) — dwie niezależne warstwy.

**Endpointy Livewire** (`/livewire/update`) to POST-y zwracające fragmenty stanu
komponentu konkretnego użytkownika. Nigdy, pod żadnym warunkiem, nie mogą być
cache'owane.

### WAF i rate limiting

- **Cloudflare Managed Ruleset** — ON (plan Free).
- **Rate limiting** na `/logowanie` i `/rejestracja`: np. 10 żądań / 10 min / IP.
  Plan Free daje ograniczoną liczbę reguł — jeśli mieści się tylko jedna, ustaw ją
  na logowanie (ochrona przed credential stuffing).
- **Bot Fight Mode** — ON, ale **sprawdź, czy nie blokuje uploadu** na `cdn.kuking.pl`.
- Uzupełniająco **rate limiting w Laravelu** (`throttle`) — Cloudflare można
  ominąć, jeśli ktoś odkryje adres `*.up.railway.app`.

> **Wzmocnienie [do weryfikacji]:** można całkowicie odciąć ruch omijający
> Cloudflare, dodając w WAF regułę na nagłówek współdzielonego sekretu
> (Cloudflare dodaje, Laravel wymaga). Wymaga Transform Rule i middleware —
> rozważ, gdy projekt zacznie być celem ataków.

---

## 9. Sekrety

### Gdzie co żyje

| Miejsce | Co | Uwagi |
|---|---|---|
| **Railway Shared Variables** (per środowisko) | `APP_KEY`, klucze R2, SMTP, Sentry DSN, PostHog | **Źródło prawdy dla runtime'u.** Ustawiane raz w panelu, referencowane w `railway.ts` przez `ctx.shared.X` |
| **GitHub Actions Secrets** | `RAILWAY_TOKEN_PRODUCTION`, `RAILWAY_TOKEN_STAGING`, `SENTRY_AUTH_TOKEN` | Tylko do IaC i operacji, nie do runtime'u |
| **GitHub Actions Variables** | `SENTRY_ORG`, `SENTRY_PROJECT` | Nietajne |
| **Repozytorium** | **NIC** | `.env.example` zawiera wyłącznie puste klucze |

**Kluczowa własność `railway.ts`: nie ma w nim ani jednego sekretu.** Plik
referencuje wartości (`ctx.shared.APP_KEY` → `${{shared.APP_KEY}}`), a nie je
przechowuje. Dzięki temu cała infrastruktura może być w publicznym repo.

### Co NIGDY nie wchodzi do repo

`.env`, `APP_KEY`, klucze R2, hasła SMTP, tokeny Railway, `SENTRY_AUTH_TOKEN`,
zrzuty bazy z danymi użytkowników.

> **Pułapka CLI:** `railway config pull --include-variables` **wpisuje odtajnione
> wartości zmiennych do pliku `railway.ts`**. Nigdy nie używaj tej flagi
> w repozytorium. Bez niej sekrety renderują się jako `preserve()`.

### Rotacja

| Sekret | Częstość | Jak |
|---|---|---|
| Klucze R2 | co 6 mies. i po każdym odejściu współpracownika | Utwórz **nowy** token w R2 → podmień w Railway → redeploy → sprawdź upload → **usuń stary** |
| Hasło SMTP | co 12 mies. | Analogicznie |
| Tokeny Railway | co 12 mies. | Nowy token → podmień sekret w GitHubie → uruchom `railway-iac.yml` → usuń stary |
| Sentry DSN | tylko przy incydencie | DSN nie jest tajny w sensie ścisłym (jest w kliencie JS) |
| **`APP_KEY`** | **nie rotować** | Rotacja unieważnia sesje i uniemożliwia odszyfrowanie starych danych |
| Hasło Postgresa | zarządza Railway | Nie ruszać ręcznie |

**Zasada rotacji bez przestoju:** zawsze **dodaj nowy klucz przed usunięciem
starego** i zweryfikuj działanie w międzyczasie.

Włącz **2FA na GitHubie, Railway i Cloudflare** — to najsłabsze ogniwo całego
łańcucha. Konto Cloudflare kontroluje DNS, czyli może przekierować całą domenę.

---

## 10. Backupy Postgresa i restore drill

> **Runbook operacyjny — procedura krok po kroku, trzy scenariusze awarii,
> ćwiczenie do wykonania i tabela wyników — jest teraz w
> [`KOPIE_I_ODTWORZENIE.md`](KOPIE_I_ODTWORZENIE.md)** i w razie sprzeczności
> wygrywa. Ta sekcja zostaje jako uzasadnienie architektoniczne (dlaczego
> trzy warstwy, nie jedna) i punkt odniesienia dla decyzji z dnia wdrożenia.

> ## ⚠️ DWIE Z TRZECH WARSTW OPISANYCH NIŻEJ NIE ISTNIEJĄ NA NASZYM PLANIE
>
> **Sprostowanie z 9 września 2026 (D-043, sprawdzone przez właściciela
> w panelu).** Volume Backups i PITR są funkcjami planu **Pro**; Kuking jest
> na Free i przechodzi na Hobby. Panel na tych planach nawet nie pokazuje tej
> zakładki, więc instrukcji „Ustaw teraz: Daily + Weekly + Enable PITR"
> niżej **NIE DA SIĘ wykonać** — a dawała się odhaczyć, i to jest gorsze
> niż jej brak.
>
> Zostaje **jedna** warstwa: zrzut logiczny poza Railwayem. Nie jest więc
> „ostatnią linią obrony", tylko **jedyną**. Zbudowana w issue #193
> (`docker/kopia/`), z procedurą i listą czynności właściciela w
> [`KOPIE_I_ODTWORZENIE.md`](KOPIE_I_ODTWORZENIE.md) §7.
>
> **Nie działa też „na schedulerze", jak mówi akapit „Offsite" niżej.**
> `docker/php.ini` wyłącza `proc_open`, bez którego `pg_dump` z PHP nie
> wystartuje, więc zrzut mieszka w OSOBNYM serwisie Railway w obrazie bez
> PHP (D-043). Tabela niżej zostaje jako uzasadnienie architektoniczne
> i jako opis stanu po ewentualnym przejściu na plan Pro.

Railway daje trzy niezależne warstwy. **Docelowo używamy wszystkich trzech**,
bo każda chroni przed czymś innym — dziś dostępna jest tylko trzecia
(patrz ramka wyżej).

| Warstwa | Co to | Chroni przed | Nie chroni przed |
|---|---|---|---|
| **Volume Backups** | snapshoty volume'a (Daily 6 dni, Weekly 1 mies., Monthly 3 mies.) | zły deploy, pomyłka w danych | usunięciem volume'a (**backupy giną razem z nim**) |
| **PITR** (pgBackRest) | ciągła archiwizacja WAL, okno ~4 tyg. | `DROP TABLE` o 14:32 → przywróć stan na 14:31 | usunięciem projektu |
| **Zrzuty logiczne** (`pg_dump`) | przenośny plik poza Railway | **usunięciem projektu**, migracją do innego dostawcy | niczym — to ostatnia linia obrony |

**Ustaw teraz:** Postgres → Backups → **Daily + Weekly** oraz **Enable PITR**.

> **PITR liczy okno od pierwszego backupu PO włączeniu.** Włączenie dziś **nie**
> pozwala wrócić do wczoraj. To najczęstsze rozczarowanie — włącz to w dniu
> pierwszego deployu, nie wtedy, gdy będzie potrzebne.

**Offsite:** zrzuty `pg_dump` na schedulerze, raz na dobę, do **innego dostawcy
niż Railway** (bucket R2). Zrzut w tym samym projekcie nie chroni przed
usunięciem projektu.

### Restore drill — procedura do wykonania

Wykonaj **teraz** (na pustej bazie), a potem **raz na kwartał**.
Backup, którego nigdy nie przywróciłeś, jest niesprawdzony.

```bash
# 0. Wymagania: railway CLI, psql, pg_dump, pg_restore
#    macOS:  brew install libpq
#    Debian: sudo apt-get install postgresql-client

# 1. Tunel do produkcyjnej bazy (bez wystawiania jej publicznie)
railway link                       # wybierz projekt kuking / production
railway connect postgres --tunnel-only
#    Zostaw to okno otwarte. Wypisze host, port, użytkownika i hasło.

# 2. W DRUGIM terminalu — zrzut w formacie custom (skompresowany,
#    pozwala na selektywny i równoległy restore; plain SQL tego nie umie)
STAMP=$(date -u +%Y%m%d-%H%M%S)
pg_dump "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
  --format=custom --no-owner \
  --file="kuking-${STAMP}.dump"

ls -lh "kuking-${STAMP}.dump"      # zapisz rozmiar

# 3. Baza-piaskownica na TYM SAMYM serwerze (NIE dotykamy produkcyjnej)
psql "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
  -c 'CREATE DATABASE restore_drill;'

# 4. Restore z pomiarem czasu — to jest Twoje realne RTO
time pg_restore \
  --dbname="postgresql://postgres:<HASLO>@localhost:<PORT>/restore_drill" \
  --no-owner --exit-on-error \
  "kuking-${STAMP}.dump"

# 5. Weryfikacja: liczby wierszy muszą się zgadzać z produkcją
psql "postgresql://postgres:<HASLO>@localhost:<PORT>/restore_drill" <<'SQL'
SELECT relname, n_live_tup
FROM pg_stat_user_tables
ORDER BY n_live_tup DESC
LIMIT 20;
SQL
#    Sprawdź też ręcznie kilka najnowszych wierszy w users, recipes, posts.

# 6. Sprzątanie
psql "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
  -c 'DROP DATABASE restore_drill;'
```

**Zapisz dwie liczby z każdego drillu:**

| Metryka | Znaczenie | Cel |
|---|---|---|
| **RTO** — czas restore'u z kroku 4 | jak długo strona jest offline | < 30 min |
| **RPO** — wiek zrzutu | ile danych tracisz | < 24 h (zrzut), < 5 min (PITR) |

### Którą warstwę użyć — drzewo decyzyjne

| Sytuacja | Działanie |
|---|---|
| Zły deploy zepsuł dane godzinę temu | **PITR** → przywróć stan na minutę przed deployem → skopiuj wiersze |
| Potrzebny cały stan z wczoraj | **Volume backup** (restore, potem Deploy w staged changes) |
| Migracja do innego dostawcy / regionu | **Zrzut logiczny** |
| Usunięto projekt albo volume | **Tylko offsite zrzut logiczny** |

### Rollback kodu ≠ rollback bazy

**To najważniejsze zdanie w tym rozdziale.** Rollback kodu **nie cofa migracji**.
Jeśli deploy dodał kolumnę, stary kod zwykle to zniesie. Jeśli **usunął lub
zmienił nazwę** kolumny — rollback kodu wyprodukuje natychmiastowe błędy SQL.

Dlatego migracje muszą być **backward-compatible**, wzorcem
**expand → migrate/backfill → switch → contract** (`docs/DEPLOYMENT.md`):

1. **expand** — dodaj nową kolumnę, nie ruszaj starej (deploy 1)
2. **migrate** — przepisz dane w tle
3. **switch** — kod czyta z nowej (deploy 2)
4. **contract** — usuń starą kolumnę (deploy 3, **po dniach, nie minutach**)

Krok „contract" jest jedynym nieodwracalnym i wykonuje się go, gdy nie ma
już wątpliwości.

---

## 11. Obserwowalność

### Minimum na start (wszystko w darmowych planach)

| Narzędzie | Co pokrywa | Konfiguracja |
|---|---|---|
| **Sentry** | wyjątki PHP i JS, przypisane do commita | `SENTRY_LARAVEL_DSN`, `SENTRY_RELEASE=${{RAILWAY_GIT_COMMIT_SHA}}`, traces 10% |
| **Logi Railway** | stdout/stderr wszystkich serwisów | `LOG_CHANNEL=stderr` + `JsonFormatter` → structured logs |
| **Zewnętrzny uptime** | czy strona **naprawdę** działa | UptimeRobot / Better Stack: `GET /health` co 5 min |
| **Smoke test po deployu** | czy deploy nie zepsuł ścieżek użytkownika | `deploy.yml`, job `verify` |
| **Metryki Railway** | CPU, RAM, sieć per serwis | wbudowane, przeglądaj co tydzień |

### Dlaczego zewnętrzny uptime monitor jest obowiązkowy

**Railway odpytuje `/health` tylko przy deployu i NIE monitoruje go później.**
To jest wprost napisane w dokumentacji. Jeśli produkcja padnie o 3:00 w nocy,
Railway sam Cię o tym nie powiadomi. Zewnętrzny monitor to jedyne źródło
alertu — i jest darmowy. **Nie pomijaj tego kroku.**

Monitoruj `https://kuking.pl/health` (przez Cloudflare) — sprawdzasz wtedy
cały łańcuch: DNS → Cloudflare → Railway → aplikacja → baza.

### Alerty — co ma budzić

| Zdarzenie | Kanał | Pilność |
|---|---|---|
| `/health` nie odpowiada > 5 min | SMS / push | **natychmiast** |
| Deploy nieudany | e-mail + GitHub | wysoka |
| Nowy typ błędu w Sentry | e-mail | wysoka |
| Certyfikat wygasa < 14 dni | e-mail | średnia |
| Zużycie Railway > progu | e-mail | średnia |
| `composer audit` / `npm audit` czerwone | podsumowanie CI | niska, przegląd tygodniowy |

### Czego brakuje, a warto dodać w fazie beta

- **Alert na zaległości w kolejce.** Rosnąca liczba rekordów w `jobs` albo
  jakikolwiek wpis w `failed_jobs` = zdjęcia użytkowników nie są przetwarzane,
  a strona wygląda na sprawną. To najbardziej podstępna awaria w tym systemie.
  Zaimplementuj jako zadanie schedulera raportujące do Sentry.
- **PostHog** — analityka produktowa (retencja, ścieżka publikacji przepisu).
  Instancja **EU** (`eu.i.posthog.com`) ze względu na RODO.
- Dashboard w Grafanie — **dopiero gdy będzie co obserwować.**

---

## 12. Koszty

> **Zastrzeżenie.** Ceny sprawdzone 2026-09-05 wg cenników w §14. Zużycie
> zasobów to **oszacowania** — Railway rozlicza faktyczne CPU/RAM co minutę,
> więc realny rachunek zależy od profilu ruchu. Widełki są celowo szerokie.
> Kwoty w USD netto.

**Stawki Railway:** RAM **$10/GB/mies.**, CPU **$20/vCPU/mies.**,
egress **$0,05/GB**, volume **$0,15/GB/mies.**
Subskrypcja: Hobby **$5**, Pro **$20** — i **zalicza się na poczet zużycia**
(zużyjesz $3 na Hobby → płacisz $5; zużyjesz $8 → płacisz $8).

### Scenariusz A — alfa, ~50 użytkowników

Topologia: `PRODUCTION_SPLIT_SERVICES = false` (jeden serwis + Postgres), plan **Hobby**.

| Pozycja | Szacunek | Kwota |
|---|---|---|
| Railway — web (`APP_ROLE=all`) | ~450 MB RAM, ~0,15 vCPU | $7,50 |
| Railway — postgres | ~350 MB RAM, ~0,05 vCPU, 2 GB volume | $4,80 |
| Railway — egress | ~5 GB | $0,25 |
| Railway — staging (Serverless, ~5% czasu) | | $1,00 |
| **Railway razem** (Hobby $5 wliczone w zużycie) | | **$13–18** |
| Cloudflare | plan Free | **$0** |
| R2 | < 10 GB → darmowy limit | **$0** |
| Sentry | Free, 5 tys. błędów/mies. | **$0** |
| PostHog | Free, 1 mln zdarzeń/mies. | **$0** |
| Poczta (Resend Free, 3 tys. maili) | | **$0** |
| Domena `kuking.pl` | ~50 zł/rok | **~$1** |
| **RAZEM** | | **$15–25 / mies. (≈ 60–100 zł)** |

### Scenariusz B — beta, ~1 000 użytkowników

Topologia: `PRODUCTION_SPLIT_SERVICES = true`, plan **Pro** ($20).

| Pozycja | Szacunek | Kwota |
|---|---|---|
| Railway — web | 600 MB RAM, 0,3 vCPU | $12 |
| Railway — worker | 450 MB RAM, 0,25 vCPU | $9,50 |
| Railway — scheduler | 200 MB RAM, 0,05 vCPU | $3 |
| Railway — postgres | 1 GB RAM, 0,15 vCPU, 10 GB volume | $14,50 |
| Railway — egress | ~30 GB (HTML mały, obrazy z R2) | $1,50 |
| Railway — staging + preview | Serverless | $3 |
| **Railway razem** (Pro $20 wliczone) | | **$45–65** |
| R2 — storage | 30 tys. zdjęć × 3,7 MB ≈ 111 GB, minus 10 GB free | $1,50 |
| R2 — operacje | 120 tys. Class A, ~3 mln Class B → w darmowym limicie | $0 |
| Cloudflare | Free wystarcza | **$0** |
| Sentry | Free może się skończyć → Team $26 | **$0–26** |
| PostHog | ~300 tys. zdarzeń → w darmowym limicie | **$0** |
| Poczta | ~15 tys. maili: Brevo $9 / Resend $20 | **$9–20** |
| Domena | | ~$1 |
| **RAZEM** | | **$60–120 / mies. (≈ 240–480 zł)** |

### Scenariusz C — wzrost, ~10 000 użytkowników

Topologia rozdzielona, web i worker po 2 repliki, plan **Pro**.

| Pozycja | Szacunek | Kwota |
|---|---|---|
| Railway — web ×2 | 1,6 GB RAM, 1,0 vCPU | $36 |
| Railway — worker ×2 | 1,2 GB RAM, 1,0 vCPU | $32 |
| Railway — scheduler | | $3 |
| Railway — postgres | 4 GB RAM, 0,5 vCPU, 60 GB volume | $59 |
| Railway — egress | ~200 GB | $10 |
| Railway — staging + preview | | $8 |
| **Railway razem** | | **$150–220** |
| R2 — storage | 400 tys. zdjęć ≈ 1,48 TB | $22 |
| R2 — operacje | 0,6 mln Class A + ~20 mln Class B ponad limit | $10 |
| R2 — oszczędność: oryginały → Infrequent Access | | −$8 |
| **R2 razem** | | **$25–40** |
| Cloudflare | Free lub Pro (WAF+, więcej reguł) | **$0–25** |
| Sentry Team / Business | | **$26–80** |
| PostHog | ~3 mln zdarzeń; **z próbkowaniem** znacznie taniej | **$20–100** |
| Poczta | ~150 tys. maili | **$30–90** |
| Domena | | ~$1 |
| **RAZEM** | | **$250–450 / mies. (≈ 1000–1800 zł)** |

### Co realnie zdominuje rachunek

Zgodnie z `docs/COSTS.md`: **nie framework**. Kolejność wg wielkości:

1. **RAM Postgresa** — największa pojedyncza pozycja w scenariuszu C ($40 z $59).
   Optymalizacja: indeksy i zapytania, nie większa maszyna.
2. **Storage zdjęć** — rośnie liniowo i **nigdy nie maleje**. Dlatego retention
   na oryginałach i Infrequent Access są realną oszczędnością, nie mikro-optymalizacją.
3. **PostHog** — rośnie z liczbą zdarzeń, nie użytkowników. Próbkuj od początku.
4. **Poczta transakcyjna** — digesty potrafią zaskoczyć. Pozwól je wyłączać.

---

## 13. Ryzyka i pułapki

### Wysokie

| # | Ryzyko | Skutek | Co robimy |
|---|---|---|---|
| 1 | **Rozliczenie za faktyczne zużycie** — wyciek pamięci albo pętla w kolejce eskalują koszt bez ostrzeżenia | rachunek-niespodzianka | `deploy.limitOverride` (twardy limit RAM/CPU per serwis), `--max-time`/`--max-jobs`/`--memory` w `queue:work`, alert budżetowy w Railway |
| 2 | **Brak monitoringu `/health` po deployu** — Railway sprawdza go tylko przy wdrożeniu | awaria w nocy niezauważona | **obowiązkowy** zewnętrzny uptime monitor (§11) |
| 3 | **Rollback nie cofa migracji** | rollback kodu → błędy SQL | migracje backward-compatible (expand/contract), `contract` po dniach, PITR jako plan B |
| 4 | **Cache'owanie stron zalogowanych na krawędzi** | wyciek danych osobowych | dwie niezależne warstwy: Cache Rule BYPASS na ciasteczko sesji + `no-store`/`Vary: Cookie` w Caddy |
| 5 | **PITR nie działa retroaktywnie** | brak możliwości odtworzenia stanu z przed włączenia | włączyć **w dniu pierwszego deployu** |
| 6 | **Zmiana `APP_KEY`** | utrata sesji i nieodwracalna utrata dostępu do zaszyfrowanych danych | zakaz rotacji, jasna adnotacja w runbooku |

### Średnie

| # | Ryzyko | Skutek | Co robimy |
|---|---|---|---|
| 7 | **Brak rekordu TXT** przy domenie Railway | domena zwraca 404 mimo poprawnego CNAME | wprost w runbooku jako krok obowiązkowy |
| 8 | **Cold start i 502 po uśpieniu (Serverless)** | pierwszy request nie działa | Serverless **wyłączony na produkcji**; `--retry` w smoke testach preview |
| 9 | **`healthcheck.railway.app` blokowany przez `TrustHosts`** | każdy deploy pada na 400 | udokumentowane w `railway.ts`, `Caddyfile` i runbooku |
| 10 | **Volume wymusza przestój przy redeployu** | widoczna przerwa na każdym deployu | **nie używamy volume'ów** — zdjęcia w R2, stan w Postgresie |
| 11 | **Scheduler w 2 replikach** | podwójne maile do użytkowników | `numReplicas: 1` z komentarzem „NIE ZMIENIAĆ" |
| 12 | **Config as Code wygasa 2026-12-01** | nagła utrata możliwości konfiguracji | jesteśmy od razu na IaC (`railway.ts`), nie na `railway.json` |
| 13 | **Zapomniane środowiska preview** | narastający koszt | Serverless + `Focused PR Environments` + Bot PR Environments OFF |
| 14 | **Darmowe limity Sentry/PostHog kończą się bez ostrzeżenia** | utrata widoczności błędów | próbkowanie tracingu 10%, przegląd zużycia co miesiąc |

### Vendor lock-in i plan wyjścia z Railway

**Ocena: lock-in jest niski i to jest wynik świadomej decyzji.**

| Element | Przenośność | Uwaga |
|---|---|---|
| Aplikacja | **Wysoka** | Własny `Dockerfile` — uruchomisz na Fly.io, Hetznerze, Cloud Run, VPS |
| Postgres | **Wysoka** | Zwykły PG 18; `pg_dump`/`pg_restore` przenosi wszystko |
| Zdjęcia | **Wysoka** | Już **poza** Railway (R2); Laravel Filesystem abstrahuje dostawcę |
| DNS | **Wysoka** | W Cloudflare, nie w Railway — przepięcie to zmiana jednego CNAME |
| Kolejka, sesje, cache | **Wysoka** | W Postgresie, bez usług zarządzanych |
| `railway.ts` | **Niska** | ~500 linii, **jedyny** element specyficzny dla Railway |
| PR Environments, pre-deploy, Serverless | Średnia | Do odtworzenia inaczej u innego dostawcy |

**Procedura wyjścia (realistycznie 1–2 dni):**

1. `pg_dump --format=custom` z produkcji (mamy to przećwiczone w restore drillu).
2. Uruchom ten sam obraz Docker u nowego dostawcy; ustaw te same zmienne.
3. `pg_restore` do nowej bazy.
4. R2 **zostaje bez zmian** — jest niezależne od hostingu.
5. Przepnij CNAME w Cloudflare. TTL 5 min → przełączenie prawie natychmiastowe.
6. Odtwórz: healthcheck, pre-deploy (migracje), cron/scheduler, CI/CD.

**Kroki 1–4 są przećwiczone w ramach kwartalnego restore drillu.** To nie jest
teoretyczny plan — to ta sama procedura, którą i tak wykonujesz regularnie.
Jedyna praca „od zera" to krok 6.

**Czego świadomie nie używamy, żeby nie pogłębiać lock-inu:** Railway Buckets
(zamiast nich R2), Railway Cron (zamiast niego `schedule:work`), Railway Redis
(kolejka w Postgresie).

---

## 14. Źródła

**Railway — Infrastructure as Code i konfiguracja**
- Infrastructure as Code — https://docs.railway.com/infrastructure-as-code
- Referencja DSL TypeScript — https://docs.railway.com/infrastructure-as-code/reference
- `railway config` (plan/apply/pull/migrate) — https://docs.railway.com/cli/config
- Config as Code (**deprecated**, cutoff 2026-12-01) — https://docs.railway.com/config-as-code
- Akcja GitHub `railwayapp/config` — https://github.com/railwayapp/config
- SDK TypeScript — https://github.com/railwayapp/railway-ts-sdk, https://www.npmjs.com/package/railway

**Railway — build, deploy, runtime**
- Dockerfiles — https://docs.railway.com/builds/dockerfiles
- Railpack — https://docs.railway.com/builds/railpack
- Konfiguracja buildu i watch paths — https://docs.railway.com/builds/build-configuration
- Pre-deploy command — https://docs.railway.com/deployments/pre-deploy-command
- Healthchecks — https://docs.railway.com/deployments/healthchecks
- Restart policy — https://docs.railway.com/deployments/restart-policy
- Regiony — https://docs.railway.com/deployments/regions
- Serverless / app sleeping — https://docs.railway.com/deployments/serverless
- Cron jobs (min. 5 min) — https://docs.railway.com/cron-jobs
- Autodeploy z GitHuba — https://docs.railway.com/deployments/github-autodeploys
- Prywatna sieć — https://docs.railway.com/networking/private-networking
- Domeny custom — https://docs.railway.com/networking/domains/working-with-domains
- Przewodnik Laravel — https://docs.railway.com/guides/laravel
- Post-deploy w GitHub Actions — https://docs.railway.com/guides/github-actions-post-deploy

**Railway — środowiska, dane, cennik**
- Środowiska i PR Environments — https://docs.railway.com/environments
- Preview deployments — https://docs.railway.com/guides/preview-deployments-with-pr-environments
- Środowiska PR przez GitHub Actions — https://docs.railway.com/guides/github-actions-pr-environment
- PostgreSQL — https://docs.railway.com/databases/postgresql
- Backup i restore Postgresa — https://docs.railway.com/guides/postgres-backups-restores
- Upgrade major Postgresa — https://docs.railway.com/databases/postgresql-major-upgrade
- Obraz `postgres-ssl` (tag 18.3) — https://github.com/railwayapp-templates/postgres-ssl
- Cennik — https://docs.railway.com/pricing, https://railway.com/pricing
- Plany i limity — https://docs.railway.com/pricing/plans
- Kontrola kosztów — https://docs.railway.com/pricing/cost-control

**Cloudflare**
- Cennik R2 — https://developers.cloudflare.com/r2/pricing/
- R2 public buckets i custom domains — https://developers.cloudflare.com/r2/buckets/public-buckets/
- R2 presigned URLs — https://developers.cloudflare.com/r2/api/s3/presigned-urls/
- R2 CORS — https://developers.cloudflare.com/r2/buckets/cors/
- Cennik Images / Transformations — https://developers.cloudflare.com/images/pricing/
- Tryby szyfrowania SSL/TLS — https://developers.cloudflare.com/ssl/origin-configuration/ssl-modes/
- Cache Rules — https://developers.cloudflare.com/cache/how-to/cache-rules/
- Tiered Cache — https://developers.cloudflare.com/cache/how-to/tiered-cache/
- Rate limiting rules — https://developers.cloudflare.com/waf/rate-limiting-rules/
- CNAME flattening (apex) — https://developers.cloudflare.com/dns/cname-flattening/

**Pozostałe**
- FrankenPHP — Docker — https://frankenphp.dev/docs/docker/
- FrankenPHP — tryb worker — https://frankenphp.dev/docs/worker/
- Cennik Sentry — https://sentry.io/pricing/
- Cennik PostHog — https://posthog.com/pricing
- Cennik Resend — https://resend.com/pricing
- Cennik Brevo — https://www.brevo.com/pricing/

**Dokumenty projektowe (blueprint)**
- `docs/DEPLOYMENT.md`, `docs/ARCHITECTURE.md`, `docs/MEDIA_PIPELINE.md`, `docs/COSTS.md`

### Elementy oznaczone `[do weryfikacji]`

| Element | Dlaczego | Gdzie |
|---|---|---|
| `source.checkSuites` („Wait for CI") | istnieje w typach SDK 3.11.0, brak w publicznej dokumentacji | `railway.ts` |
| Identyfikator regionu `europe-west4-drams3a` | tabela regionów podaje pełny ID, przykłady IaC skrócony `europe-west4` | `railway.ts` |
| Pola `deploy.*` (`limitOverride`, `drainingSeconds`, `sleepApplication`) | w typach SDK, nie w referencji IaC | `railway.ts` |
| Składnia nieinteraktywnego `railway ssh <serwis> <komenda>` | może się różnić między wersjami CLI | `deploy.yml` |
| Konfiguracja uploadu Livewire 4 na S3 | nazwa opcji do potwierdzenia w dokumentacji Livewire 4 | §7 |
| Dostępność PostgreSQL 18 w kreatorze Railway | obraz `postgres-ssl:18.3` istnieje; potwierdź wybór w panelu | `railway.ts`, runbook |
| Reguła WAF na sekret współdzielony (odcięcie ruchu omijającego CF) | wymaga Transform Rule + middleware | §8 |
| Aktualna cena Cloudflare Pro | zmienna, sprawdź przed zakupem | §12 |
