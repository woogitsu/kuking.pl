# Kuking.pl — runbook wdrożenia od zera

**Dla kogo:** właściciel projektu, bez doświadczenia DevOps.
**Czas:** 3–4 godziny (plus czekanie na DNS).
**Co uzyskasz:** działającą produkcję na `kuking.pl` z automatycznym deployem z GitHuba.

---

## Jak czytać ten dokument

- Kroki wykonuj **po kolei**. Kolejność ma znaczenie — np. bucket R2 musi
  istnieć, zanim wpiszesz jego klucze do Railway.
- **`[POTRZEBNE OD WŁAŚCICIELA]`** = tu musisz podać własne dane, wykonać
  płatność albo podjąć decyzję. Zebrane w jednym miejscu w §16.
- Bloki `bash` uruchamiasz w terminalu. `→ Panel:` oznacza klikanie w przeglądarce.
- Po każdej sekcji jest **„Sprawdź, że działa"**. Nie idź dalej, jeśli nie działa.

### Zanim zaczniesz — zainstaluj narzędzia

```bash
# Node 22+ (Railway CLI i budowanie assetów)
node --version        # musi być >= 22

# Railway CLI
npm install -g @railway/cli
railway --version     # musi być >= 5.42.1 (IaC wymaga tej wersji)

# Klient PostgreSQL (backupy i restore drill)
#   macOS:   brew install libpq && brew link --force libpq
#   Debian:  sudo apt-get install postgresql-client
pg_dump --version

# GitHub CLI (opcjonalnie, ułatwia ustawianie sekretów)
gh --version
```

---

## KROK 0. Czego potrzebujesz na start

**`[POTRZEBNE OD WŁAŚCICIELA]`**

| # | Co | Uwagi |
|---|---|---|
| 0.1 | Konto **GitHub** z repo `woogitsu/kuking.pl` | prywatne lub publiczne |
| 0.2 | Konto **Railway** (railway.com) + karta płatnicza | plan **Hobby $5/mies.** na start |
| 0.3 | Konto **Cloudflare** z domeną `kuking.pl` w strefie DNS | już masz |
| 0.4 | Adres e-mail do alertów | np. `alerty@kuking.pl` |
| 0.5 | **Decyzja:** dostawca poczty transakcyjnej | rekomendacja: **EmailLabs** (300/dobę, bez karty), zapasowo Brevo — patrz `POCZTA_URUCHOMIENIE.md` §6 |
| 0.6 | **Włącz 2FA** na GitHubie, Railway i Cloudflare | **zrób to teraz**, nie później |

> **Dlaczego 2FA teraz:** konto Cloudflare kontroluje DNS dla `kuking.pl`.
> Przejęcie go oznacza przekierowanie całej domeny. To najsłabsze ogniwo
> w całym łańcuchu.

---

## KROK 1. Pliki w repozytorium

Skopiuj dostarczone pliki do repo, zachowując te ścieżki:

```text
kuking.pl/
├── Dockerfile                        ← obraz produkcyjny
├── docker/
│   ├── Caddyfile                     ← konfiguracja serwera
│   ├── entrypoint.sh                 ← wybór roli (web/worker/scheduler)
│   └── php.ini                       ← opcache, limity uploadu
├── .railway/
│   └── railway.ts                    ← infrastruktura jako kod
└── .github/workflows/
    ├── ci.yml                        ← bramka jakości
    ├── deploy.yml                    ← smoke test + operacje
    ├── preview.yml                   ← smoke test środowisk PR
    └── railway-iac.yml               ← plan/apply infrastruktury
```

```bash
cd ~/kuking.pl

# Skrypt startowy musi być wykonywalny — inaczej kontener nie wstanie
chmod +x docker/entrypoint.sh
git update-index --chmod=+x docker/entrypoint.sh

# SDK Railway (railway.ts importuje "railway/iac")
npm install --save-dev railway

# Upewnij się, że .env NIGDY nie trafi do repo
grep -q '^\.env$' .gitignore || echo '.env' >> .gitignore
grep -q '^/vendor' .gitignore || echo '/vendor' >> .gitignore
grep -q '^/node_modules' .gitignore || echo '/node_modules' >> .gitignore

git checkout -b infra/wdrozenie
git add -A
git commit -m "infra: Dockerfile, Railway IaC, workflowy CI/CD"
git push -u origin infra/wdrozenie
```

### Zmiany, które trzeba wprowadzić w kodzie aplikacji

Te trzy rzeczy **muszą** być w aplikacji, inaczej wdrożenie nie zadziała:

**1. Trasa `/health`** (`routes/web.php`) — musi sprawdzać bazę:

```php
Route::get('/health', function () {
    try {
        DB::connection()->getPdo()->query('SELECT 1');
    } catch (\Throwable $e) {
        return response()->json(['status' => 'error'], 503);
    }
    return response()->json(['status' => 'ok']);
})->name('health');
```

**2. Zaufane proxy** (`bootstrap/app.php`) — bez tego Laravel widzi IP
Cloudflare zamiast użytkownika i generuje URL-e po `http://`:

```php
->withMiddleware(function (Middleware $middleware) {
    // Pierwszy w stosie: z X-Forwarded-For zostaje JEDEN wpis — n-ty od
    // końca, czyli ten dopisany przez naszą infrastrukturę (SEC-01).
    $middleware->prepend(NormalizeForwardedFor::class);

    $middleware->trustProxies(
        at: '*',
        headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
    );
})
```

> **`at: '*'` to nie „ufaj całemu łańcuchowi".** Laravel tłumaczy to na
> `setTrustedProxies([REMOTE_ADDR], …)`, czyli „ufaj tej jednej maszynie,
> która się właśnie połączyła", a Symfony oddaje wtedy OSTATNI wpis
> `X-Forwarded-For`. Ile wpisów na końcu tego nagłówka pochodzi od naszej
> infrastruktury, mówi `KUKING_ZAUFANE_PRZESKOKI` (`config/proxy.php`) —
> i to jest jedyna liczba, którą trzeba tu znać. **Zmierz ją, nie zgaduj:**
> wejdź na serwis przez Cloudflare bez własnego `X-Forwarded-For` i policz
> wpisy, które dotarły do aplikacji. Za mała wartość = wspólny adres dla
> wielu osób i zbyt ostre limity; za duża = powrót podatności W7-01.

**3. Dysk `r2`** (`config/filesystems.php`):

```php
'r2' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'auto'),
    'bucket' => env('AWS_BUCKET'),
    'endpoint' => env('AWS_ENDPOINT'),
    'url' => env('AWS_URL'),
    'use_path_style_endpoint' => false,
    'throw' => true,
],
```

> **Jeśli włączysz `TrustHosts`:** dopisz `healthcheck.railway.app` do listy
> dozwolonych hostów. Inaczej **każdy deploy będzie padał na błąd 400**.

---

## KROK 2. Bucket R2 + klucze + własna domena

Robimy to **przed** Railway, bo klucze R2 są potrzebne w zmiennych Railway.

### 2.1 Utwórz buckety

→ Panel Cloudflare → **R2 Object Storage** → **Create bucket**

| Ustawienie | Produkcja | Staging |
|---|---|---|
| Nazwa | `kuking-media` | `kuking-media-staging` |
| Lokalizacja | **EU (European Union)** | EU |
| Klasa storage | Standard | Standard |

> **Lokalizacja EU jest istotna dla RODO** — zdjęcia użytkowników to dane
> osobowe. Region bucketa **nie da się zmienić po utworzeniu.**

### 2.2 Klucze API `[POTRZEBNE OD WŁAŚCICIELA — zapisz bezpiecznie]`

→ R2 → **API** → **Manage API tokens** → **Create API token**

| Ustawienie | Wartość |
|---|---|
| Nazwa | `kuking-produkcja` |
| Uprawnienia | **Object Read & Write** |
| Zakres | **Apply to specific buckets** → `kuking-media` |
| TTL | bezterminowy (rotacja co 6 mies. wg §15) |

Zapisz **natychmiast** — sekret pokazuje się **tylko raz**:

```text
Access Key ID:      ................          → R2_ACCESS_KEY_ID
Secret Access Key:  ................          → R2_SECRET_ACCESS_KEY
Endpoint (S3 API):  https://<ACCOUNT_ID>.r2.cloudflarestorage.com
                                              → R2_ENDPOINT
```

Powtórz dla staginu (token `kuking-staging`, zakres `kuking-media-staging`).

> **Zakres na jeden bucket, nie „all buckets".** Wyciek klucza produkcyjnego
> nie może dać dostępu do niczego więcej.

### 2.3 Własna domena `cdn.kuking.pl`

> ## ⛔ TEGO KROKU NIE WYKONUJ — jest sprzeczny z decyzją właściciela D-020
>
> Ten rozdział powstał, gdy adresem zdjęcia był adres pliku w buckecie.
> **Decyzja D-020 (6 września 2026) to odwróciła:** adresem zdjęcia jest
> trasa aplikacji, która sprawdza uprawnienia, a bucket wariantów traci
> własną domenę. Utworzenie `cdn.kuking.pl` na buckecie wariantów —
> a zwłaszcza razem z regułą „Cache Everything" z §7, Edge i Browser TTL
> 30 dni — **odtworzyłoby dokładnie tę lukę**, którą zamknęło ustalenie
> audytowe W7-02: adres raz skopiowany działa dalej po zablokowaniu, po
> cofnięciu obserwowania i po decyzji moderacyjnej. Najgorszy przypadek
> nazwał audyt wprost: skan odręcznej kartki z rodzinnym przepisem,
> a na niej nazwiska i adresy.
>
> Zmierzone 7 września 2026: `cdn.kuking.pl` nie odpowiada, czyli krok nie
> został wykonany. Ma tak zostać. To samo dotyczy reguły 4 w §7.
>
> Rozdział zostaje w dokumencie, a nie jest kasowany, bo `R2_PUBLIC_URL`
> i `r2_legacy` mają swoją historię, którą trzeba rozumieć przy migracji
> starych zdjęć (`kuking:przenies-zdjecia`). Ale jako INSTRUKCJA jest
> wycofany.


→ R2 → bucket `kuking-media` → **Settings** → **Custom Domains** → **Add**

- Domena: `cdn.kuking.pl`
- Zatwierdź proponowany rekord → **Connect Domain**

Cloudflare **sam utworzy** rekord CNAME. Status zmieni się z *Initializing*
na *Active* w kilka minut.

Wartość do zmiennych: `R2_PUBLIC_URL = https://cdn.kuking.pl`

> **NIE włączaj „Public Development URL" (`r2.dev`)** na produkcji. Ten adres
> jest rate-limitowany, nie przechodzi przez cache ani WAF, i omija wszystkie
> Twoje reguły bezpieczeństwa.

### 2.4 CORS — wymagane dla uploadu bezpośrednio z przeglądarki

→ bucket → **Settings** → **CORS policy** → **Add**

```json
[
  {
    "AllowedOrigins": [
      "https://kuking.pl",
      "https://www.kuking.pl"
    ],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["content-type", "content-length"],
    "ExposeHeaders": ["etag"],
    "MaxAgeSeconds": 3600
  }
]
```

Dla `kuking-media-staging` dodaj `https://staging.kuking.pl` oraz
`https://*.up.railway.app` (środowiska preview).

> **Bez CORS przeglądarka odrzuci presigned PUT** i upload zdjęć nie zadziała —
> a błąd będzie widoczny tylko w konsoli przeglądarki, nie w logach serwera.

**Sprawdź, że działa:**

```bash
curl -I https://cdn.kuking.pl/test.txt
# Oczekiwane: 404 (bucket pusty, ale domena odpowiada).
# Jeśli dostajesz błąd DNS lub TLS — domena jeszcze się nie aktywowała, poczekaj.
```

---

## KROK 3. Poczta transakcyjna

**`[POTRZEBNE OD WŁAŚCICIELA — wybór dostawcy]`**

> **Ten krok ma własny dokument: [`POCZTA_URUCHOMIENIE.md`](POCZTA_URUCHOMIENIE.md).**
> Jest tam komplet zmiennych i rekordów DNS dla pięciu wariantów (EmailLabs
> i Brevo — rekomendowane, §2A–§2B; Postmark, Amazon SES, Resend — §2C–§2E),
> wyjaśnienie, co robi SPF, DKIM i DMARC, oraz sposób sprawdzenia, że poczta
> naprawdę wychodzi. Porównanie dostawców, ceny i rezydencja danych:
> [`../decyzje/POCZTA.md`](../decyzje/POCZTA.md).
>
> Poniżej zostaje tylko to, co dotyczy samego wdrożenia.

### 3.1 Weryfikacja domeny

1. Zarejestruj konto, dodaj domenę `kuking.pl`.
2. Dostawca poda rekordy **SPF**, **DKIM**, (czasem **DMARC**).
3. Dodaj je w Cloudflare → **DNS** → **Records**.

> **Wszystkie rekordy poczty muszą być „DNS only" (szara chmurka), nie Proxied.**
> Cloudflare nie proxuje poczty — proxowanie ich zepsuje weryfikację.

Dodaj też **DMARC** (nie każdy dostawca o to poprosi, ale bez tego trafisz do spamu):

| Typ | Nazwa | Wartość |
|---|---|---|
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:kontakt@kuking.pl` |

> **`p=none` na start, nie `p=quarantine`.** Ostrzejsza polityka ustawiona
> przed przeczytaniem pierwszych raportów `rua` kasuje pocztę z systemów,
> o których się zapomniało (formularz na stronie, hosting, stary newsletter) —
> i robi to po cichu. Zaostrzenie do `p=quarantine`, a potem `p=reject`,
> po 2–4 tygodniach czystych raportów: `POCZTA_URUCHOMIENIE.md` §3.

### 3.2 Dane dostępowe `[POTRZEBNE OD WŁAŚCICIELA — zapisz]`

Przy dostawcy po SMTP (Brevo, EmailLabs, Mailgun — sterownik `smtp` jest już
skonfigurowany, zero zmian w kodzie):

```text
MAIL_HOST      = ................       (np. smtp-relay.brevo.com)
MAIL_PORT      = 587                    (587 → MAIL_SCHEME=tls, 465 → smtps)
MAIL_USERNAME  = ................
MAIL_PASSWORD  = ................       ← sekret
```

Przy dostawcy po API (Postmark, Resend, SES) zmienne są inne, a `railway.ts`
wymaga zmiany `MAIL_MAILER` — komplet w `POCZTA_URUCHOMIENIE.md` §2.

**Sprawdź, że działa:** w panelu dostawcy poczekaj na status „Verified"
przy domenie, a po pierwszym deployu wyślij prawdziwą wiadomość:

```bash
railway ssh -- php artisan kuking:sprawdz-poczte ty@wp.pl
```

Komenda kończy się **porażką**, gdy sterownik to `log` albo `array` — bo wtedy
Laravel przyjmuje wiadomość, zgłasza sukces i nie wysyła jej nikomu.

---

## KROK 4. Sentry

1. → sentry.io → nowy projekt, platforma **Laravel**, region **EU**.
2. Skopiuj **DSN** → `SENTRY_LARAVEL_DSN`
   (format: `https://xxxx@oyyyy.ingest.de.sentry.io/zzzz`).
3. → **Settings → Auth Tokens** → nowy token z zakresem `project:releases`
   → `SENTRY_AUTH_TOKEN` (do GitHub Actions).
4. Zapisz nazwę organizacji i projektu → `SENTRY_ORG`, `SENTRY_PROJECT`.

```bash
composer require sentry/sentry-laravel

# Podstaw swój DSN w miejsce wartości poniżej (cudzysłowy są istotne —
# bez nich powłoka zinterpretuje znaki < > jako przekierowanie).
php artisan sentry:publish --dsn="https://xxxx@oyyyy.ingest.de.sentry.io/zzzz"
```

> Region **EU** — dane błędów mogą zawierać dane osobowe użytkowników.

---

## KROK 5. PostHog

1. → posthog.com → **EU Cloud** (`eu.i.posthog.com`), nie US.
2. → **Project Settings** → skopiuj **Project API Key** → `POSTHOG_KEY`.
3. `POSTHOG_HOST = https://eu.i.posthog.com`

Darmowy plan: 1 mln zdarzeń/mies. — na alfę i betę wystarczy z zapasem.

---

## KROK 6. Projekt Railway i baza

### 6.1 Projekt

→ railway.com → **New Project** → **Deploy from GitHub repo**
→ wybierz `woogitsu/kuking.pl` → **Add variables** (na razie pomiń) → **Deploy**

Pierwszy deploy **prawdopodobnie się nie uda** — brakuje jeszcze zmiennych.
To normalne, poprawimy to w kroku 8.

→ **Project Settings** → **Name**: `kuking`

### 6.2 Region konta

→ Kliknij swój avatar → **Workspace Settings** → **Preferred region**
→ **EU West Metal (Amsterdam)**

> `railway.ts` i tak wymusza region per serwis, ale ustawienie domyślnego
> chroni przed przypadkowym utworzeniem czegoś w USA.

### 6.3 PostgreSQL 18

→ Kanwa projektu → **+ New** → **Database** → **Add PostgreSQL**

**`[POTRZEBNE OD WŁAŚCICIELA — decyzja]`** Wybierz major **18**, jeśli kreator
go oferuje. Jeśli oferuje tylko 17:

- **Opcja A (zalecana):** weź 17 i zrób upgrade in-place później
  (→ Database → Config → **Major Version Upgrade**). Railway wspiera
  przejście z 14–17 na nowszy major.
- **Opcja B:** utwórz serwis z obrazu `ghcr.io/railwayapp-templates/postgres-ssl:18.3`
  — tracisz wtedy część integracji panelu (Database View).

→ Zmień nazwę serwisu na **`postgres`** (dokładnie tak — `railway.ts` się do
niej odwołuje).

### 6.4 Backupy — **zrób to teraz, nie później**

→ serwis `postgres` → zakładka **Backups**:

1. Włącz **Daily** (6 dni retencji)
2. Włącz **Weekly** (1 miesiąc retencji)
3. Kliknij **Enable PITR** i potwierdź

> **PITR liczy okno dopiero od pierwszego backupu PO włączeniu.** Włączenie
> tego za miesiąc nie pozwoli odtworzyć dzisiejszego stanu. To najczęstsze
> rozczarowanie przy awarii — **włącz teraz.**

**Sprawdź, że działa:** w zakładce Backups pojawi się pierwszy wpis
(kilka minut). PITR pokaże wybierak daty po pierwszym pełnym backupie.

---

## KROK 7. `APP_KEY`

```bash
cd ~/kuking.pl
php artisan key:generate --show
# Wynik, np.: base64:Xy9vQ2h...=
```

Uruchom **dwa razy** — potrzebujesz **osobnego klucza** dla produkcji i staginu.

```text
APP_KEY (production) = base64:................    [POTRZEBNE OD WŁAŚCICIELA — zapisz]
APP_KEY (staging)    = base64:................
```

> **`APP_KEY` to najważniejszy sekret w systemie.** Szyfruje sesje i dane
> w bazie. **Nigdy go nie rotuj na działającej produkcji** — unieważni to
> wszystkie sesje i **trwale** uniemożliwi odszyfrowanie danych zapisanych
> starym kluczem. Zapisz w menedżerze haseł (1Password / Bitwarden), nie
> w notatniku i nie w repo.

---

## KROK 8. Zmienne środowiskowe w Railway

Wpisujemy je jako **Shared Variables na środowisku** — raz, a `railway.ts`
rozdziela je do wszystkich serwisów. To dlatego w `railway.ts` nie ma sekretów.

→ Railway → środowisko **production** → **Variables** → sekcja
**Shared Variables** → **New Shared Variable**

### Pełna lista — środowisko `production`

| Zmienna | Wartość | Sekret? | Opis |
|---|---|---|---|
| `APP_KEY` | `base64:...` (krok 7) | **TAK** | Klucz szyfrowania sesji i danych. Nie rotować. |
| `R2_ACCESS_KEY_ID` | z kroku 2.2 | **TAK** | Access Key ID do bucketa `kuking-media` |
| `R2_SECRET_ACCESS_KEY` | z kroku 2.2 | **TAK** | Secret Access Key R2 |
| `R2_BUCKET` | `kuking-media` | nie | Nazwa bucketa |
| `R2_ENDPOINT` | `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` | nie | Endpoint S3 API R2 |
| `R2_PUBLIC_URL` | `https://cdn.kuking.pl` | nie | Publiczny prefiks URL zdjęć |
| `MAIL_HOST` | np. `smtp.emaillabs.net.pl` (EmailLabs) | nie | Serwer SMTP |
| `MAIL_PORT` | `587` | nie | Port SMTP (STARTTLS) |
| `MAIL_USERNAME` | z kroku 3.2 | nie | Login SMTP |
| `MAIL_PASSWORD` | z kroku 3.2 | **TAK** | Hasło / klucz API SMTP |
| `SENTRY_LARAVEL_DSN` | z kroku 4 | nie | DSN projektu Sentry |
| `POSTHOG_KEY` | z kroku 5 | nie | Project API Key PostHog |

Zaznacz **Sealed** przy wszystkich oznaczonych „**TAK**" — Railway przestanie
wtedy pokazywać wartość w panelu i w CLI.

### Zmienne ustawiane automatycznie przez `railway.ts`

**Nie wpisuj ich ręcznie** — `railway config apply` zrobi to za Ciebie:

`APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`,
`APP_FAKER_LOCALE`, `APP_TIMEZONE`, `APP_ROLE`, `LOG_CHANNEL`, `LOG_STDERR_FORMATTER`,
`LOG_LEVEL`, `DB_CONNECTION`, `DB_URL`, `SESSION_DRIVER`, `SESSION_LIFETIME`,
`SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, `CACHE_STORE`,
`QUEUE_CONNECTION`, `KUKING_ZAUFANE_PRZESKOKI`, `FILESYSTEM_DISK`, `AWS_DEFAULT_REGION`,
`AWS_USE_PATH_STYLE_ENDPOINT`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
`AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_URL`, `MAIL_MAILER`, `MAIL_SCHEME`,
`MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `KUKING_CONTACT_EMAIL`, `SENTRY_ENVIRONMENT`,
`SENTRY_TRACES_SAMPLE_RATE`, `SENTRY_PROFILES_SAMPLE_RATE`, `SENTRY_RELEASE`,
`POSTHOG_HOST`, `PHP_WORKER_MEMORY_LIMIT`

Railway dostarcza też sam: `PORT`, `RAILWAY_PUBLIC_DOMAIN`,
`RAILWAY_PRIVATE_DOMAIN`, `RAILWAY_GIT_COMMIT_SHA`, `RAILWAY_ENVIRONMENT`.

### Ścieżka bez IaC — serwis utworzony klikaniem w panelu

Lista wyżej zakłada, że pozostałe ~40 zmiennych ustawi `railway config apply`.
**Serwis utworzony przez „New Project → Deploy from GitHub repo" nie wie nic
o `railway.ts`** — trzeba mu je podać wprost.

Jest to normalna droga na pierwszy zielony deploy: IaC można przyjąć później.

> ### `railway.json` NIE działa — sprawdzone na żywym projekcie
>
> Config as Code (`railway.json` / `railway.toml`) jest przez Railway
> **wycofany**. API odpowiada wprost:
>
> ```
> Config as Code (railway.json / railway.toml) is deprecated.
> Use Infrastructure as Code (.railway/railway.ts) instead.
> ```
>
> Plik w katalogu głównym jest po cichu ignorowany. Serwis utworzony klikaniem
> startuje więc z **builderem RAILPACK**, bez komendy startowej, bez
> healthchecku i **bez `preDeployCommand`** — czyli bez migracji. Objaw jest
> mylący: build przechodzi, deploy melduje `SUCCESS`, a kontener chodzi
> w pętli restartów na `relation "cache" does not exist`, bo schemat bazy
> nigdy nie powstał.
>
> Do pierwszego uruchomienia ustaw to **wprost na serwisie** (Settings), albo
> zastosuj IaC:
>
> | ustawienie | wartość |
> |---|---|
> | Builder | `Dockerfile`, ścieżka `Dockerfile` |
> | Start Command | `/usr/local/bin/kuking-entrypoint all` |
> | Pre-Deploy Command | `php artisan migrate --force --no-interaction` |
> | Healthcheck Path | `/health`, timeout `180` |
> | Restart Policy | `ON_FAILURE`, 10 prób |
>
> **Healthcheck nie jest ozdobnikiem.** Bez niego Railway przełącza ruch na
> kontener, który się nie podniósł, i deploy jest „udany" mimo leżącej
> aplikacji. Z nim deploy uczciwie pada na `Healthcheck failure`.

→ serwis → **Variables** → **Raw Editor** → wklej:

```bash
APP_NAME=Kuking
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kuking.pl
APP_LOCALE=pl
APP_FALLBACK_LOCALE=pl
APP_FAKER_LOCALE=pl_PL
APP_TIMEZONE=UTC
APP_ROLE=all
APP_KEY=            # patrz KROK 7 — NIE zostawiaj pustego

LOG_CHANNEL=stderr
LOG_STDERR_FORMATTER=\Monolog\Formatter\JsonFormatter
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_URL=${{Postgres.DATABASE_URL}}

SESSION_DRIVER=database
SESSION_LIFETIME=43200
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
KUKING_ZAUFANE_PRZESKOKI=1

FILESYSTEM_DISK=local
MAIL_MAILER=log
PHP_WORKER_MEMORY_LIMIT=512M
```

**`DB_URL`, nie `DATABASE_URL`.** Laravel czyta `env('DB_URL')`
(`config/database.php`). `${{Postgres.DATABASE_URL}}` to składnia odwołań
Railway: `${{NAZWA_SERWISU.ZMIENNA}}` — `Postgres` musi się zgadzać z nazwą
serwisu bazy na kanwie.

**`APP_ROLE=all`** — przy jednym serwisie web, worker i harmonogram chodzą
w tym samym kontenerze. Bez tego kolejka nie działa, więc **zdjęcia zostają
na zawsze w stanie `PENDING`**, a kary czasowe nigdy nie wygasają.

> ### Dwie wartości są TYMCZASOWE
>
> | zmienna | skutek | kiedy zmienić |
> |---|---|---|
> | `FILESYSTEM_DISK=local` | zdjęcia **znikają przy każdym redeployu** | gdy będzie bucket R2 → `r2` |
> | `MAIL_MAILER=log` | **nikt nie dostanie ani linku aktywacyjnego, ani linku do zmiany hasła** — a wysyłka zgłasza sukces | gdy będzie dostawca → `smtp`, `postmark`, `resend` albo `ses` |
>
> Obie są w porządku na pierwszy zielony deploy i **nie do przyjęcia**, gdy
> wpuszczasz prawdziwych ludzi.
>
> Przy `MAIL_MAILER=log` ekran „Nie pamiętam hasła" świadomie nie przyjmuje
> adresu i odsyła do skrzynki kontaktowej — inaczej pierwsza osoba, która
> zapomni hasła, straciłaby konto bezpowrotnie (`App\Support\Poczta`).
> Instrukcja odblokowania: [`POCZTA_URUCHOMIENIE.md`](POCZTA_URUCHOMIENIE.md).

Po dodaniu R2 i poczty dołóż: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
`AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_URL`, `AWS_DEFAULT_REGION=auto`,
`AWS_USE_PATH_STYLE_ENDPOINT=false`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
`MAIL_PASSWORD`, `MAIL_SCHEME=tls`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
oraz opcjonalnie `SENTRY_LARAVEL_DSN` i `POSTHOG_KEY`.

### Środowisko `staging`

→ Górny wybierak środowiska → **+ New Environment** → **Duplicate**
z `production` → nazwa `staging`

Następnie **podmień** w `staging` te wartości na nieprodukcyjne:

| Zmienna | Wartość dla staginu |
|---|---|
| `APP_KEY` | **drugi** klucz z kroku 7 |
| `R2_BUCKET` | `kuking-media-staging` |
| `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` | klucze tokenu `kuking-staging` |
| `R2_PUBLIC_URL` | `https://kuking-media-staging.<ACCOUNT_ID>.r2.cloudflarestorage.com` lub własna subdomena `cdn-staging.kuking.pl` |

> **Nigdy nie wskazuj staginu na produkcyjny bucket ani produkcyjną bazę.**
> Test na staginu, który usuwa zdjęcia użytkowników, to nie test — to incydent.

---

## KROK 9. Zastosowanie infrastruktury (`railway.ts`)

```bash
cd ~/kuking.pl

railway login
railway link                    # wybierz workspace → projekt kuking → środowisko production

# PODGLĄD — nie zmienia niczego. Przeczytaj wynik uważnie.
railway config plan
```

Zobaczysz listę zmian: utworzenie serwisów `web`, `worker`, `scheduler`,
przypisanie domen, zmiennych, healthchecku.

```bash
# Zastosowanie (poprosi o potwierdzenie)
railway config apply
```

Powtórz dla staginu:

```bash
railway link --environment staging
railway config plan
railway config apply

railway link --environment production   # wróć na produkcję
```

**Jeśli `plan` odrzuci któreś pole** (`limitOverride`, `drainingSeconds`,
`sleepApplication`, `checkSuites`): usuń je z `railway.ts` i ustaw ręcznie
w panelu (krok 12). Reszta konfiguracji zadziała bez zmian.

**Sprawdź, że działa:** na kanwie projektu widzisz serwisy `web`, `worker`,
`scheduler`, `postgres` w dwóch grupach: „Aplikacja" i „Dane".

---

## KROK 10. Domena w Cloudflare

### 10.1 Dodaj domeny w Railway

→ serwis **web** → **Settings** → **Networking** → **Custom Domain**

Dodaj **`kuking.pl`**. Railway pokaże **dwa rekordy** — skopiuj oba:

```text
CNAME   kuking.pl              →  xxxxxx.up.railway.app
TXT     _railway.kuking.pl     →  <długi ciąg weryfikacyjny>
```

Powtórz dla `www.kuking.pl`. W środowisku `staging` dodaj `staging.kuking.pl`.

> **Plan Hobby ma limit 2 domen custom na serwis.** `kuking.pl` + `www` = dokładnie 2.
> Na trzecią (np. `sklep.kuking.pl`) będziesz potrzebować planu Pro.

### 10.2 Rekordy w Cloudflare

→ Cloudflare → `kuking.pl` → **DNS** → **Records** → **Add record**

| Typ | Nazwa | Wartość | Proxy | TTL |
|---|---|---|---|---|
| CNAME | `@` | `xxxxxx.up.railway.app` | **Proxied** 🟠 | Auto |
| CNAME | `www` | `xxxxxx.up.railway.app` | **Proxied** 🟠 | Auto |
| TXT | `_railway` | `<ciąg z Railway>` | — | Auto |
| CNAME | `staging` | `yyyyyy.up.railway.app` | **Proxied** 🟠 | Auto |
| TXT | `_railway.staging` | `<ciąg dla staginu>` | — | Auto |

> ## NAJCZĘSTSZY BŁĄD
>
> **Rekord TXT jest OBOWIĄZKOWY.** Bez niego domena zwraca **404, nawet gdy
> CNAME poprawnie się rozwiązuje** — Railway nie potwierdził własności domeny
> i nie kieruje na nią ruchu. Jeśli po 15 minutach widzisz 404, sprawdź
> **najpierw** rekord TXT.

Apex jako CNAME jest poprawny — Cloudflare stosuje **CNAME flattening**.

Poczekaj, aż w Railway obok domeny pojawi się **zielony znacznik**
(zwykle 2–15 min). Zobaczysz też komunikat „Cloudflare proxy detected" —
to jest oczekiwane.

### 10.3 SSL/TLS

→ Cloudflare → **SSL/TLS** → **Overview**

1. Jeśli widzisz **Automatic SSL/TLS** → przełącz na **Custom SSL/TLS**
2. Wybierz **Full (strict)**

→ **SSL/TLS** → **Edge Certificates**:

| Ustawienie | Wartość |
|---|---|
| Always Use HTTPS | **ON** |
| Minimum TLS Version | **TLS 1.2** |
| Opportunistic Encryption | ON |
| TLS 1.3 | ON |
| Automatic HTTPS Rewrites | ON |
| HSTS | **na razie OFF** — włączysz w kroku 11.4 |

> **Nigdy nie ustawiaj trybu `Flexible`.** Oznacza on nieszyfrowany odcinek
> Cloudflare → Railway. Railway wystawia prawdziwy, publicznie zaufany
> certyfikat, więc `Full (strict)` jest osiągalny i wymagany.

### 10.4 Przekierowanie `www` → apex

→ **Rules** → **Redirect Rules** → **Create rule**

- Nazwa: `www na apex`
- Warunek: **Hostname** *equals* `www.kuking.pl`
- Akcja: **Dynamic redirect**
- Expression:
  ```text
  concat("https://kuking.pl", http.request.uri.path)
  ```
- Kod: **301**, **Preserve query string: ON**

### 10.5 Cache Rules

→ **Caching** → **Cache Rules** → **Create rule**

**Reguła 1 — „Assety Vite"** (kolejność: pierwsza)

```text
Gdy:   starts_with(http.request.uri.path, "/build/")
Wtedy: Cache eligibility     = Eligible for cache
       Edge TTL              = 1 rok
       Browser TTL           = 1 rok
```

**Reguła 2 — „Statyka PWA"**

```text
Gdy:   http.request.uri.path in {"/favicon.ico" "/robots.txt" "/manifest.webmanifest"}
Wtedy: Cache eligibility = Eligible, Edge TTL = 1 godzina
```

**Reguła 3 — „NIE cache'uj aplikacji"** (kolejność: **ostatnia**, ale
**przed** regułą mediów)

```text
Gdy którykolwiek:
     starts_with(http.request.uri.path, "/livewire/")
  or starts_with(http.request.uri.path, "/api/")
  or starts_with(http.request.uri.path, "/konto/")
  or starts_with(http.request.uri.path, "/ustawienia/")
  or http.request.uri.path in {"/logowanie" "/rejestracja" "/wyloguj"}
  or http.cookie contains "kuking_session"
  or http.request.method ne "GET"
Wtedy: Cache eligibility = Bypass cache
```

**Reguła 4 — „Media z R2"**

```text
Gdy:   http.host eq "cdn.kuking.pl"
Wtedy: Cache eligibility = Eligible
       Edge TTL          = 30 dni
       Browser TTL       = 30 dni
```

Dodatkowo → **Caching** → **Tiered Cache** → **Smart Tiered Cache: ON**.

> **Reguła 3 jest zabezpieczeniem ochrony danych osobowych, nie optymalizacją.**
> Podanie scache'owanej strony jednego zalogowanego użytkownika innemu to
> incydent RODO. Warunek na ciasteczko `kuking_session` łapie każdą stronę,
> której nie wymieniliśmy z nazwy.

### 10.6 WAF i rate limiting

→ **Security** → **WAF**

1. **Managed Rules** → włącz **Cloudflare Managed Ruleset**
2. **Rate limiting rules** → **Create rule**:
   ```text
   Nazwa:      Ochrona logowania
   Gdy:        http.request.uri.path eq "/logowanie" and http.request.method eq "POST"
   Licznik:    po adresie IP
   Limit:      10 żądań / 10 minut
   Akcja:      Block, czas trwania 10 minut
   ```
3. → **Security** → **Bots** → **Bot Fight Mode: ON**

> Plan Free ma ograniczoną liczbę reguł rate limiting. Jeśli mieści się tylko
> jedna — użyj jej na logowanie (ochrona przed credential stuffing).

### 10.7 Wydajność

→ **Speed** → **Optimization**: **Brotli ON**, **Early Hints ON**.
**Rocket Loader: OFF** — psuje Livewire.

---

## KROK 11. Pierwszy deploy i weryfikacja

### 11.1 Sekrety w GitHubie

→ Railway → **Project Settings** → **Tokens** → utwórz **dwa** tokeny
projektowe: jeden dla `production`, jeden dla `staging`.

```bash
gh secret set RAILWAY_TOKEN_PRODUCTION   # wklej token produkcyjny
gh secret set RAILWAY_TOKEN_STAGING      # wklej token staginu
gh secret set SENTRY_AUTH_TOKEN          # z kroku 4

gh variable set SENTRY_ORG     --body "twoja-organizacja"
gh variable set SENTRY_PROJECT --body "kuking"
```

### 11.2 Wdrożenie

```bash
# Najpierw staging
git checkout -b staging
git push -u origin staging

# Poczekaj na zielone CI, potem sprawdź staging.kuking.pl.
# Gdy działa — na produkcję:
git checkout main
git merge infra/wdrozenie
git push origin main
```

Obserwuj: **GitHub → Actions** (CI), potem **Railway → serwis web → Deployments**.

W logach deployu poszukaj potwierdzenia, że wszystko działa jak zaplanowano:

```text
Using detected Dockerfile!            ← Railway użył naszego Dockerfile
Running pre-deploy command...         ← migracje
[entrypoint] rola=web env=production  ← entrypoint wybrał rolę
[entrypoint] przebudowa cache konfiguracji...
```

### 11.3 Checklista smoke testów

**Wszystko poniżej robi jedno polecenie:**

```bash
./scripts/sprawdz-wdrozenie.sh kuking.pl
```

Skrypt nie potrzebuje żadnych kluczy ani dostępu do paneli — pyta z zewnątrz,
tak jak przeglądarka użytkownika. Przerywa, gdy serwis nie odpowiada, zamiast
meldować „w porządku" o czymś, czego nie sprawdził. Kod wyjścia `1` przy
błędach, więc nadaje się też do CI.

Polecenia niżej zostają jako źródło i do ręcznego dochodzenia, gdy skrypt
pokaże problem.

```bash
# 1. Healthcheck (aplikacja + baza)
curl -s https://kuking.pl/health
# Oczekiwane: {"status":"ok"}

# 2. Strona główna
curl -s -o /dev/null -w "%{http_code}\n" https://kuking.pl/
# Oczekiwane: 200

# 3. HTTP przekierowuje na HTTPS
curl -s -o /dev/null -w "%{http_code} -> %{redirect_url}\n" http://kuking.pl/
# Oczekiwane: 301/308 -> https://kuking.pl/

# 4. www przekierowuje na apex
curl -s -o /dev/null -w "%{http_code} -> %{redirect_url}\n" https://www.kuking.pl/
# Oczekiwane: 301 -> https://kuking.pl/

# 5. Ruch idzie przez Cloudflare
curl -sI https://kuking.pl/ | grep -i "^\(cf-ray\|server\)"
# Oczekiwane: cf-ray oraz server: cloudflare

# 6. Assety Vite cache'owane na rok
curl -sI https://kuking.pl/build/manifest.json | grep -i cache-control
# Oczekiwane: public, max-age=31536000, immutable

# 7. Endpoint Livewire NIE jest cache'owany
curl -sI https://kuking.pl/livewire/update | grep -i "cache-control\|cf-cache-status"
# Oczekiwane: no-store / BYPASS

# 8. Domena R2 odpowiada
curl -s -o /dev/null -w "%{http_code}\n" https://cdn.kuking.pl/
# Oczekiwane: 404 (bucket pusty) — ale NIE błąd DNS/TLS

# 9. Tryb debug WYŁĄCZONY  ← test krytyczny dla bezpieczeństwa
curl -s https://kuking.pl/nie-ma-takiej-strony-12345 | grep -ci "ignition\|whoops\|APP_KEY"
# Oczekiwane: 0
# Wynik > 0 znaczy, że strona błędu ujawnia zmienne środowiskowe. Natychmiast
# ustaw APP_DEBUG=false i zredeployuj.

# 10. Nagłówki bezpieczeństwa
curl -sI https://kuking.pl/ | grep -i "x-content-type-options\|x-frame-options"
# Oczekiwane: nosniff, DENY
```

**Testy ręczne w przeglądarce:**

| # | Co sprawdzić | Oczekiwane |
|---|---|---|
| 11 | Rejestracja nowego konta | konto powstaje, przychodzi e-mail (**najpierw** `php artisan kuking:sprawdz-poczte ty@wp.pl` — patrz `POCZTA_URUCHOMIENIE.md` §5) |
| 12 | Logowanie i wylogowanie | sesja działa, ciasteczko `Secure` |
| 13 | **Upload zdjęcia z telefonu** | pasek postępu, potem widoczna miniatura |
| 14 | URL zdjęcia | zaczyna się od `https://cdn.kuking.pl/` |
| 15 | **Zdjęcie NIE zawiera GPS** | `exiftool <plik>` — brak `GPSLatitude` |
| 16 | Interakcja Livewire (polubienie) | działa bez odświeżenia strony |
| 17 | Instalacja PWA | przeglądarka proponuje „Dodaj do ekranu głównego" |
| 18 | Logi Railway | serwis `worker` przetworzył zadanie zdjęcia |
| 19 | Sentry | celowo wywołany błąd pojawia się z poprawnym `release` |
| 20 | PostHog | zdarzenia wpadają do panelu |

> **Punkt 15 jest niepodlegający negocjacji.** Zdjęcie z kuchni zawiera
> współrzędne domu użytkownika. Jeśli GPS przechodzi, wstrzymaj publiczne
> udostępnienie serwisu do naprawy pipeline'u.

### 11.4 Włącz HSTS (dopiero teraz)

Gdy testy 1–10 są zielone: → Cloudflare → **SSL/TLS** → **Edge Certificates**
→ **HSTS** → **Enable**

| Ustawienie | Wartość |
|---|---|
| Max Age | **6 months** |
| Include subdomains | **ON** |
| Preload | **OFF** — włącz po kilku tygodniach stabilności |
| No-Sniff | ON |

> **HSTS jest praktycznie nieodwracalny** w zakresie `max-age`: przeglądarki,
> które raz zobaczą nagłówek, będą wymuszać HTTPS przez 6 miesięcy. `Preload`
> jest jeszcze trudniejszy do wycofania. Dlatego dopiero po potwierdzeniu,
> że HTTPS działa wszędzie.

### 11.5 Zewnętrzny monitoring — **nie pomijaj**

→ uptimerobot.com (darmowy) albo betterstack.com

| Ustawienie | Wartość |
|---|---|
| Typ | HTTP(s) |
| URL | `https://kuking.pl/health` |
| Interwał | 5 minut |
| Oczekiwane słowo | `ok` |
| Alerty | e-mail + **SMS/push** |

> **Railway odpytuje `/health` tylko przy deployu i NIE monitoruje go później.**
> Bez zewnętrznego monitora nikt nie powiadomi Cię o awarii o 3:00 w nocy.
> To 5 minut pracy i 0 zł.

---

## KROK 12. Ustawienia klikane ręcznie w panelu

Te rzeczy albo nie należą do `railway.ts`, albo trzeba je potwierdzić.

### Ustawienia projektu

→ **Project Settings** → **Environments**:

| Ustawienie | Wartość |
|---|---|
| **Enable PR Environments** | **ON** |
| **Base environment** dla PR-ów | **`staging`** ← nie `production`! |
| **Enable Focused PR Environments** | ON |
| **Enable Bot PR Environments** | **OFF** |

> **Środowiskiem bazowym PR-ów musi być `staging`.** Przy `production` każdy
> pull request — także z forka — dostawałby kopię produkcyjnych sekretów.

### Per serwis — potwierdź, że `railway.ts` to ustawił

→ każdy serwis → **Settings**:

| Ustawienie | web | worker | scheduler |
|---|---|---|---|
| Region | EU West (Amsterdam) | EU West | EU West |
| Restart policy | On Failure / 10 | On Failure / 10 | **Always** |
| Healthcheck Path | `/health` | — | — |
| Pre-deploy Command | `php artisan migrate --force --no-interaction` | — | — |
| **Pre-deploy Timeout** | **600 s** ← ustaw ręcznie | — | — |
| Serverless | **OFF** | **OFF** | **OFF** |
| **Wait for CI** | **ON** | **ON** | **ON** |
| Replicas | 1 | 1 | **1 — nie zmieniać** |

> **Pre-deploy Timeout** pojawia się w panelu **dopiero po** wpisaniu komendy
> pre-deploy. Bez timeoutu zawieszona migracja blokuje deploy w nieskończoność.

W środowisku `staging`: **Serverless ON** dla `web`.

### Alert budżetowy

→ **Workspace Settings** → **Usage** → **Usage Limits**

| Pole | Wartość na start |
|---|---|
| Soft limit (e-mail) | **$25** |
| Hard limit (zatrzymanie) | **$60** |

> **`[POTRZEBNE OD WŁAŚCICIELA — decyzja]`** Hard limit **wyłącza serwisy**
> po przekroczeniu. Chroni przed rachunkiem-niespodzianką, ale oznacza
> przestój. Ustaw go z zapasem 3× nad spodziewanym rachunkiem.

---

## KROK 13. Restore drill

**Wykonaj teraz** (na pustej bazie jest szybko) i potem **raz na kwartał**.
Backup, którego nigdy nie przywróciłeś, jest niesprawdzony.

```bash
# 1. Tunel do bazy — baza NIE jest wystawiana publicznie
railway link --environment production
railway connect postgres --tunnel-only
#    Zostaw otwarte. Wypisze host, port, użytkownika i HASŁO.

# 2. DRUGI terminal — zrzut
STAMP=$(date -u +%Y%m%d-%H%M%S)
pg_dump "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
  --format=custom --no-owner --file="kuking-${STAMP}.dump"
ls -lh "kuking-${STAMP}.dump"

# 3. Baza-piaskownica (NIE dotykamy produkcyjnej)
psql "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
  -c 'CREATE DATABASE restore_drill;'

# 4. Restore z pomiarem — ten czas to Twoje realne RTO
time pg_restore \
  --dbname="postgresql://postgres:<HASLO>@localhost:<PORT>/restore_drill" \
  --no-owner --exit-on-error "kuking-${STAMP}.dump"

# 5. Weryfikacja liczby wierszy
psql "postgresql://postgres:<HASLO>@localhost:<PORT>/restore_drill" \
  -c "SELECT relname, n_live_tup FROM pg_stat_user_tables ORDER BY n_live_tup DESC LIMIT 20;"

# 6. Sprzątanie
psql "postgresql://postgres:<HASLO>@localhost:<PORT>/railway" \
  -c 'DROP DATABASE restore_drill;'
```

**Zapisz wynik w tabeli i aktualizuj przy każdym drillu:**

| Data | Rozmiar zrzutu | Czas restore (RTO) | Wiek zrzutu (RPO) | Uwagi |
|---|---|---|---|---|
| | | | | |

Cel: **RTO < 30 min**, **RPO < 24 h** (zrzut) / **< 5 min** (PITR).

---

## KROK 14. Rollback — co robić, gdy deploy zepsuł produkcję

### Najpierw ustal, CO jest zepsute

```bash
curl -s https://kuking.pl/health          # aplikacja żyje?
railway logs --service web --environment production | tail -50
```

→ Sentry: czy pojawił się nowy typ błędu po ostatnim deployu?

### Ścieżka A — deploy BEZ migracji (najczęstsza, najbezpieczniejsza)

→ Railway → serwis **web** → **Deployments** → poprzedni **udany** deploy
→ menu `...` → **Redeploy**

Powtórz dla `worker` i `scheduler`. Czas: 2–5 minut.

Albo z GitHuba: **Actions** → **Deploy** → **Run workflow** →
environment `production`, action `redeploy`.

### Ścieżka B — deploy Z migracją NIEDESTRUKCYJNĄ (dodanie kolumny/tabeli)

Rollback kodu jest **bezpieczny** — stary kod zignoruje nową kolumnę.
Postępuj jak w ścieżce A. **Nie wycofuj migracji.**

### Ścieżka C — deploy Z migracją DESTRUKCYJNĄ (`DROP`/`RENAME` kolumny)

> ## STOP — ROLLBACK KODU TEGO NIE NAPRAWI
>
> Stary kod będzie szukał kolumny, której już nie ma → natychmiastowe błędy SQL.

1. **Nie panikuj i nie klikaj rollbacku.**
2. → Postgres → **Backups** → wybierz moment **minutę przed deployem**
   → **Restore to this moment**.
3. Railway utworzy **nowy** serwis `postgres-restored-<data>` **obok**
   produkcyjnego. Produkcja działa dalej — nic nie tracisz.
4. Zweryfikuj dane w kopii (`railway connect`, `psql`, sprawdź liczby wierszy).
5. Przenieś brakujące dane do produkcyjnej bazy albo przepnij `DB_URL`
   na przywróconą instancję.
6. **Dopiero teraz** zrób rollback kodu.

### Jak w ogóle nie znaleźć się w ścieżce C

Migracje muszą być **backward-compatible** — wzorzec
**expand → migrate → switch → contract**:

| Deploy | Krok | Co robi |
|---|---|---|
| 1 | **expand** | dodaj nową kolumnę, **nie ruszaj** starej |
| — | **migrate** | przepisz dane w tle (kolejka) |
| 2 | **switch** | kod czyta z nowej kolumny |
| 3 | **contract** | usuń starą kolumnę — **po dniach, nie minutach** |

Tylko krok „contract" jest nieodwracalny i robisz go, gdy nie ma wątpliwości.
Zapewnia to, że rollback o jeden deploy w tył **zawsze** jest bezpieczny.

---

## KROK 15. Rutyna utrzymaniowa

### Co tydzień (15 min)

- [ ] Sentry: przegląd nowych błędów
- [ ] Railway → Metrics: CPU/RAM per serwis — trend, nie chwila
- [ ] Railway → Usage: zużycie vs budżet
- [ ] Podsumowanie CI: `composer audit` / `npm audit`
- [ ] Zaległości w kolejce: `SELECT count(*) FROM jobs; SELECT count(*) FROM failed_jobs;`

### Co miesiąc (1 h)

- [ ] Sprawdź, czy zaszły warunki do `PRODUCTION_SPLIT_SERVICES = true` (§5 decyzji)
- [ ] Zużycie R2 vs darmowy limit
- [ ] Zużycie darmowych limitów Sentry / PostHog
- [ ] Zamknij zapomniane PR-y (każdy to działające środowisko)
- [ ] Aktualizacje zależności (Dependabot / `composer outdated`)

### Co kwartał (2 h)

- [ ] **Restore drill** (krok 13) — zapisz RTO i RPO
- [ ] Przegląd Cache Rules i reguł WAF
- [ ] Przegląd uprawnień: kto ma dostęp do GitHuba, Railway, Cloudflare
- [ ] Test przywrócenia PITR do konkretnego znacznika czasu

### Co pół roku

- [ ] **Rotacja kluczy R2** (patrz niżej)
- [ ] Przegląd wersji majora PostgreSQL
- [ ] Weryfikacja rekordów SPF/DKIM/DMARC

### Rotacja kluczy R2 — bez przestoju

**Zasada: zawsze dodaj nowy klucz PRZED usunięciem starego.**

```text
1. Cloudflare R2 → Manage API tokens → utwórz NOWY token
   (te same uprawnienia i zakres na bucket)
2. Railway → production → Shared Variables → podmień
   R2_ACCESS_KEY_ID i R2_SECRET_ACCESS_KEY
3. GitHub → Actions → Deploy → action: redeploy
   (config:cache przebudowuje się przy starcie kontenera)
4. SPRAWDŹ: wgraj testowe zdjęcie i obejrzyj je
5. DOPIERO TERAZ usuń stary token w Cloudflare
```

Ta sama procedura dla hasła SMTP i tokenów Railway.
**`APP_KEY` — nigdy nie rotuj** (patrz krok 7).

---

## KROK 16. Zestawienie `[POTRZEBNE OD WŁAŚCICIELA]`

### Konta i płatności

| # | Co | Koszt | Krok |
|---|---|---|---|
| 1 | Konto Railway + karta, plan **Hobby** | $5/mies. | 0.2, 6 |
| 2 | Konto Cloudflare (domena już w strefie) | $0 (Free) | 0.3 |
| 3 | Konto Sentry | $0 (Free) | 4 |
| 4 | Konto PostHog (**EU Cloud**) | $0 (Free) | 5 |
| 5 | Konto dostawcy poczty | $0–20/mies. | 3 |
| 6 | Konto uptime monitoringu | $0 | 11.5 |
| 7 | **2FA włączone wszędzie** | — | 0.6 |

### Decyzje do podjęcia

| # | Decyzja | Rekomendacja | Krok |
|---|---|---|---|
| 8 | Dostawca poczty | Resend (alfa) → Brevo (beta) | 3 |
| 9 | Major PostgreSQL, jeśli 18 niedostępne | 17 + upgrade in-place | 6.3 |
| 10 | Topologia produkcji | `PRODUCTION_SPLIT_SERVICES = false` na alfę | §5 decyzji |
| 11 | Limity budżetu Railway | soft $25 / hard $60 | 12 |
| 12 | Adres e-mail alertów | `alerty@kuking.pl` | 0.4 |
| 13 | Adres nadawcy poczty | `kontakt@kuking.pl` — **jeden adres w obie strony** (decyzja właściciela, 7 IX 2026: kod pokazywał ludziom `kontakt@`, a wysyłał z `kuchnia@`; z kodu nie dało się ustalić, która skrzynka odbiera). W repozytorium poprawione wszędzie, łącznie z `.railway/railway.ts`, który wcześniej pominięto i który przy `railway config apply` wpisywał `kuchnia@` z powrotem. **Zostaje jedna czynność ręczna: jeśli w panelu Railway `MAIL_FROM_ADDRESS` było ustawiane osobno, usuń je stamtąd albo popraw — wartość z panelu wygra z plikiem.** Pilnuje tego test `NadawcaPocztyNieJestNoreplyTest` | `railway.ts` |

### Sekrety do wygenerowania i bezpiecznego zapisania

> **Wszystkie do menedżera haseł** (1Password / Bitwarden).
> **Żaden nie wchodzi do repozytorium.**

| # | Sekret | Skąd | Krok |
|---|---|---|---|
| 14 | `APP_KEY` — **produkcja** | `php artisan key:generate --show` | 7 |
| 15 | `APP_KEY` — **staging** | to samo, drugie uruchomienie | 7 |
| 16 | `R2_ACCESS_KEY_ID` + `R2_SECRET_ACCESS_KEY` (prod) | R2 API token | 2.2 |
| 17 | `R2_ACCESS_KEY_ID` + `R2_SECRET_ACCESS_KEY` (staging) | R2 API token | 2.2 |
| 18 | `R2_ENDPOINT` (zawiera Account ID) | panel R2 | 2.2 |
| 19 | `MAIL_USERNAME` + `MAIL_PASSWORD` | dostawca poczty | 3.2 |
| 20 | `SENTRY_LARAVEL_DSN` | Sentry | 4 |
| 21 | `SENTRY_AUTH_TOKEN` | Sentry, zakres `project:releases` | 4 |
| 22 | `POSTHOG_KEY` | PostHog | 5 |
| 23 | `RAILWAY_TOKEN_PRODUCTION` | Railway Project Tokens | 11.1 |
| 24 | `RAILWAY_TOKEN_STAGING` | Railway Project Tokens | 11.1 |

### Zmiany w kodzie aplikacji (przed pierwszym deployem)

| # | Co | Gdzie | Krok |
|---|---|---|---|
| 25 | Trasa `/health` sprawdzająca bazę | `routes/web.php` | 1 |
| 26 | `NormalizeForwardedFor` + `trustProxies(at: '*')` z jawnym zestawem nagłówków | `bootstrap/app.php`, `config/proxy.php` | 1 |
| 27 | Dysk `r2` | `config/filesystems.php` | 1 |
| 28 | `healthcheck.railway.app` w `TrustHosts` (jeśli włączone) | `bootstrap/app.php` | 1 |
| 29 | Presigned upload + usuwanie EXIF/GPS | `app/Jobs/ProcessUploadedImage.php` | §7 decyzji |

---

## Szybka pomoc — najczęstsze problemy

| Objaw | Najczęstsza przyczyna | Naprawa |
|---|---|---|
| **404 na `kuking.pl`**, CNAME działa | brak rekordu **TXT** | dodaj TXT z panelu Railway (krok 10.2) |
| **Deploy pada: „healthcheck failed with status 400"** | `TrustHosts` blokuje `healthcheck.railway.app` | dopisz ten host |
| **Deploy pada: „service unavailable"** | aplikacja nie słucha na `$PORT` | sprawdź `SERVER_NAME=":${PORT}"` w entrypoincie |
| **Pętla przekierowań (`ERR_TOO_MANY_REDIRECTS`)** | tryb SSL `Flexible` | przełącz na **Full (strict)** |
| **500 na każdej stronie** | brak / zły `APP_KEY` | sprawdź logi — entrypoint wypisze czytelny komunikat |
| **Brak CSS i JS** | build Vite nie wszedł do obrazu | sprawdź job `assets` w CI i `public/build/manifest.json` |
| **Upload zdjęcia nie działa, konsola pokazuje błąd CORS** | brak polityki CORS na buckecie | krok 2.4 |
| **Zdjęcia nie pojawiają się po uploadzie** | worker nie działa / kolejka stoi | `railway logs --service worker`, `SELECT * FROM failed_jobs` |
| **Livewire przestaje odpowiadać po chwili** | endpointy Livewire cache'owane na krawędzi | sprawdź regułę BYPASS (krok 10.5) |
| **Podwójne maile do użytkowników** | scheduler w 2 replikach | ustaw `numReplicas: 1` |
| **Pierwsze wejście na staging zwraca 502** | Serverless uśpił serwis | to normalne; odśwież stronę |
| **Rachunek Railway skoczył** | wyciek pamięci lub pętla w kolejce | Metrics per serwis, `failed_jobs`, limity z §12 |

## Kontakty awaryjne

- Railway — status: https://status.railway.com · wsparcie: https://station.railway.com
- Cloudflare — status: https://www.cloudflarestatus.com
- GitHub — status: https://www.githubstatus.com

---

## Źródła

**Railway**
- Infrastructure as Code — https://docs.railway.com/infrastructure-as-code
- Referencja IaC — https://docs.railway.com/infrastructure-as-code/reference
- `railway config` — https://docs.railway.com/cli/config
- Domeny custom (CNAME + **TXT**) — https://docs.railway.com/networking/domains/working-with-domains
- Healthchecks (host `healthcheck.railway.app`) — https://docs.railway.com/deployments/healthchecks
- Pre-deploy command i timeout — https://docs.railway.com/deployments/pre-deploy-command
- Restart policy — https://docs.railway.com/deployments/restart-policy
- Serverless / usypianie — https://docs.railway.com/deployments/serverless
- Regiony — https://docs.railway.com/deployments/regions
- Środowiska i PR Environments — https://docs.railway.com/environments
- PostgreSQL — https://docs.railway.com/databases/postgresql
- Backup i restore — https://docs.railway.com/guides/postgres-backups-restores
- Upgrade major PG — https://docs.railway.com/databases/postgresql-major-upgrade
- Przewodnik Laravel — https://docs.railway.com/guides/laravel
- Plany i limity (2 domeny na Hobby) — https://docs.railway.com/pricing/plans
- Kontrola kosztów — https://docs.railway.com/pricing/cost-control

**Cloudflare**
- R2 public buckets / custom domains — https://developers.cloudflare.com/r2/buckets/public-buckets/
- R2 CORS — https://developers.cloudflare.com/r2/buckets/cors/
- R2 presigned URLs — https://developers.cloudflare.com/r2/api/s3/presigned-urls/
- R2 tokeny API — https://developers.cloudflare.com/r2/api/tokens/
- Cennik R2 — https://developers.cloudflare.com/r2/pricing/
- Tryby SSL/TLS — https://developers.cloudflare.com/ssl/origin-configuration/ssl-modes/
- HSTS — https://developers.cloudflare.com/ssl/edge-certificates/additional-options/http-strict-transport-security/
- Cache Rules — https://developers.cloudflare.com/cache/how-to/cache-rules/
- Tiered Cache — https://developers.cloudflare.com/cache/how-to/tiered-cache/
- Redirect Rules — https://developers.cloudflare.com/rules/url-forwarding/
- Rate limiting — https://developers.cloudflare.com/waf/rate-limiting-rules/
- CNAME flattening — https://developers.cloudflare.com/dns/cname-flattening/

**Pozostałe**
- FrankenPHP Docker — https://frankenphp.dev/docs/docker/
- Laravel — deployment — https://laravel.com/docs/13.x/deployment
- Sentry Laravel — https://docs.sentry.io/platforms/php/guides/laravel/
- PostHog — https://posthog.com/docs
- Resend SMTP — https://resend.com/docs/send-with-smtp
- Brevo SMTP — https://help.brevo.com/hc/en-us/articles/209462765

### Oznaczone `[do weryfikacji]`

| Element | Dlaczego | Gdzie |
|---|---|---|
| Dostępność PostgreSQL 18 w kreatorze Railway | obraz `postgres-ssl:18.3` istnieje; potwierdź wybór w panelu | 6.3 |
| Akceptacja pól `deploy.*` / `source.checkSuites` przez `railway config plan` | są w typach SDK 3.11.0, nie w publicznej referencji IaC | 9, 12 |
| Składnia `railway ssh <serwis> <komenda>` (nieinteraktywnie) | zależna od wersji CLI — sprawdź `railway ssh --help` | 14 |
| Dokładna nazwa opcji uploadu Livewire 4 na S3 | potwierdź w dokumentacji Livewire 4 | 1, §7 decyzji |
| Aktualne ceny Resend / Brevo / Sentry / PostHog | cenniki się zmieniają — sprawdź przed zakupem | 3, §12 decyzji |
| Liczba reguł rate limiting na planie Cloudflare Free | limit bywa zmieniany | 10.6 |
