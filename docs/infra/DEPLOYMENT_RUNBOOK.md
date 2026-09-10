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
    'driver' => 'r2',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'auto'),
    'bucket' => env('AWS_BUCKET'),
    'endpoint' => env('AWS_ENDPOINT'),
    // Świadomie BEZ `url`. Oryginały niosą pełny EXIF — ten bucket nie ma
    // własnej domeny, a `Storage::url()` ma tu rzucić wyjątek (W7-02).
    'use_path_style_endpoint' => false,
    'throw' => true,
],
```

> **`driver => 'r2'`, nie `'s3'` — i to nie jest kosmetyka (issue #120).**
> Wbudowany sterownik `s3` wysyła `x-amz-acl` przy każdym zapisie, także gdy
> nikt o widoczność nie prosił. R2 tego nagłówka nie obsługuje dla `PutObject`
> i nie gwarantuje, jak na niego zareaguje. Sterownik `r2`
> (`app/Support/Storage/R2Adapter.php`) nie wysyła ACL wcale. Dotyczy
> **wszystkich czterech** dysków R2: `r2`, `r2_publiczne`, `r2_legacy`,
> `r2_eksporty`.

> **`TrustHosts` JEST włączony** (D-071) i `healthcheck.railway.app` jest już
> na liście w `App\Support\ZaufaneHosty`. Nie usuwaj go — inaczej **każdy
> deploy będzie padał na błąd 400** i nie będzie jak wypchnąć poprawki.
> Gdyby Railway kiedyś zmienił ten host, naprawa **bez deployu** to zmienna
> `KUKING_ZAUFANE_HOSTY` w panelu Railwaya (patrz `config/proxy.php`).

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
> został wykonany. Ma tak zostać. To samo dotyczy **reguły 4 w §10.5**.
>
> Rozdział zostaje w dokumencie, a nie jest kasowany, bo `R2_PUBLIC_URL`
> i `r2_legacy` mają swoją historię, którą trzeba rozumieć przy migracji
> starych zdjęć (`kuking:przenies-zdjecia`). Ale jako INSTRUKCJA jest
> wycofany.

**Zamiast tego kroku: nic.** Adresem zdjęcia jest dziś trasa
`/zdjecia/{media}/{wariant}` (`MediaController`), która pyta Policy treści
nadrzędnej i dopiero wtedy przekierowuje na krótko podpisany adres R2. Bucket
wariantów nie potrzebuje własnej domeny i nie ma jej mieć. Przejdź od razu
do §2.4.

<details>
<summary>Treść wycofanego kroku — do czytania, NIE do wykonywania</summary>

> Wszystko poniżej tej linii było instrukcją do 6 września 2026. Zostaje jako
> historia potrzebna przy `kuking:przenies-zdjecia`, bo stary bucket
> (`r2_legacy`) nadal ma publiczny adres, dopóki migracja nie dojdzie do
> końca. **Nie wykonuj tego na nowym buckecie.**

→ R2 → bucket `kuking-media` → **Settings** → **Custom Domains** → **Add**

- Domena: `cdn.kuking.pl`
- Zatwierdź proponowany rekord → **Connect Domain**

Cloudflare **sam utworzy** rekord CNAME. Status zmieni się z *Initializing*
na *Active* w kilka minut.

Wartość do zmiennych: `R2_PUBLIC_URL = https://cdn.kuking.pl`

> **NIE włączaj „Public Development URL" (`r2.dev`)** na produkcji. Ten adres
> jest rate-limitowany, nie przechodzi przez cache ani WAF, i omija wszystkie
> Twoje reguły bezpieczeństwa.

**Sprawdzenie z tamtej instrukcji** (dziś ma NIE działać — patrz §11, punkt 8):

```bash
curl -I https://cdn.kuking.pl/test.txt
```

</details>

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

**Sprawdź, że działa:** dopiero po pierwszym deployu, wgrywając zdjęcie
z przeglądarki (§11, test ręczny 13). CORS-u nie da się sprawdzić `curl`-em
w sposób, który cokolwiek dowodzi — odrzucenie robi przeglądarka, na podstawie
nagłówków odpowiedzi, i widać je wyłącznie w jej konsoli.

> Stało tu `curl -I https://cdn.kuking.pl/test.txt` z komentarzem „oczekiwane
> 404, a błąd DNS znaczy poczekaj". To sprawdzenie należało do **wycofanego**
> §2.3, nie do CORS-u, i po D-020 mówiło rzecz odwrotną do prawdy: dziś błąd
> DNS jest wynikiem POPRAWNYM (audyt zewnętrzny, G14).

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
MAIL_PORT      = 587                    (587 → MAIL_SCHEME=smtp, 465 → smtps)
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

> Pełna procedura odtworzenia (trzy scenariusze, ćwiczenie, tabela wyników)
> żyje teraz w [`KOPIE_I_ODTWORZENIE.md`](KOPIE_I_ODTWORZENIE.md) — ten
> dokument w razie sprzeczności wygrywa. Tu zostaje tylko włączenie backupów
> jako część wdrożenia od zera.

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
| ~~`R2_PUBLIC_URL`~~ | — | — | **NIE USTAWIAJ.** Wycofane razem z §2.3 (D-020). Nic w kodzie tej zmiennej nie czyta — sprawdzone `rg -n R2_PUBLIC_URL config app routes resources`, zero trafień. Adresem zdjęcia jest trasa `/zdjecia/{media}/{wariant}`. Stary bucket, dopóki `kuking:przenies-zdjecia` nie dojdzie do końca, używa `AWS_LEGACY_URL` (dysk `r2_legacy`) — to inna zmienna i inny bucket. |
| `MAIL_HOST` | np. `smtp.emaillabs.net.pl` (EmailLabs) | nie | Serwer SMTP |
| `MAIL_PORT` | `587` | nie | Port SMTP (STARTTLS) |
| `MAIL_USERNAME` | z kroku 3.2 | nie | Login SMTP |
| `MAIL_PASSWORD` | z kroku 3.2 | **TAK** | Hasło / klucz API SMTP |
| `SENTRY_LARAVEL_DSN` | z kroku 4 | nie | DSN projektu Sentry |
| `POSTHOG_KEY` | z kroku 5 | nie | Project API Key PostHog |
| `TURNSTILE_SITE_KEY` | z kroku 8A | nie | Site Key widgetu Turnstile — wchodzi do HTML-a, nie jest sekretem |
| `TURNSTILE_SECRET_KEY` | z kroku 8A | **TAK** | Secret Key widgetu Turnstile |
| `GOOGLE_CLIENT_ID` | z kroku 8D | nie | Client ID OAuth — wchodzi do adresu przekierowania, nie jest sekretem |
| `GOOGLE_CLIENT_SECRET` | z kroku 8D | **TAK** | Client secret OAuth (wejście kontem Google, D-069) |

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
`MAIL_FROM_ADDRESS`, `KUKING_CONTACT_EMAIL`, `SENTRY_ENVIRONMENT`,
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
`MAIL_PASSWORD`, `MAIL_SCHEME=smtp`, `MAIL_FROM_ADDRESS`
oraz opcjonalnie `SENTRY_LARAVEL_DSN` i `POSTHOG_KEY`.

`MAIL_FROM_NAME` **celowo nie jest na tej liście.** Nieustawiona zmienna
daje nazwę nadawcy „<gospodarz> z Kuking" złożoną w `config/mail.php`;
ustawiona — cicho odwraca decyzję produktową o podpisywaniu listów imieniem
(`docs/infra/POCZTA_URUCHOMIENIE.md` §5).

### Środowisko `staging`

→ Górny wybierak środowiska → **+ New Environment** → **Duplicate**
z `production` → nazwa `staging`

Następnie **podmień** w `staging` te wartości na nieprodukcyjne:

| Zmienna | Wartość dla staginu |
|---|---|
| `APP_KEY` | **drugi** klucz z kroku 7 |
| `R2_BUCKET` | `kuking-media-staging` |
| `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` | klucze tokenu `kuking-staging` |
| ~~`R2_PUBLIC_URL`~~ | **NIE USTAWIAJ** — ta sama zmienna, ten sam powód co w tabeli produkcyjnej wyżej. `cdn-staging.kuking.pl` nie ma powstać. |

> **Nigdy nie wskazuj staginu na produkcyjny bucket ani produkcyjną bazę.**
> Test na staginu, który usuwa zdjęcia użytkowników, to nie test — to incydent.

---

## KROK 8A. Cloudflare Turnstile — captcha na formularzach publicznych

**Kiedy:** po kroku 8, przed pierwszym wpuszczeniem ludzi.
**Ile zajmuje:** pięć minut w panelu Cloudflare, dwie zmienne w Railway.
**Co się stanie, jeśli tego nie zrobisz:** nic się nie zepsuje — formularze
działają jak dziś, chroni je limit zapytań. Ale `/health` będzie od tej pory
oddawał `status: degraded` (patrz niżej), bo konfiguracja obiecuje ochronę,
której nie ma. To jest zamierzone: cicha, nieistniejąca ochrona jest gorsza
niż jej jawny brak.

Decyzja i uzasadnienie: [`docs/DECISIONS.md` D-050](../DECISIONS.md), issue #217.

### 8A.1 Co kliknąć w panelu Cloudflare

1. Zaloguj się na [dash.cloudflare.com](https://dash.cloudflare.com) na to
   samo konto, na którym jest DNS `kuking.pl`.
2. W menu po lewej: **Turnstile** → **Add widget**.
3. **Widget name:** `kuking.pl` (dowolna, to tylko etykieta w panelu).
4. **Hostnames** — dodaj **wszystkie**, pod którymi serwis ma działać:
   - `kuking.pl`
   - `www.kuking.pl`
   - `kuking-pl-production.up.railway.app` (adres z Railway — bez niego
     widget nie zadziała na środowisku Railwaya)
   - `localhost` **tylko** jeśli chcesz testować u siebie z prawdziwymi
     kluczami; normalnie nie jest potrzebny, bo lokalnie Turnstile jest
     wyłączony (puste klucze).
5. **Widget Mode:** **Managed**. To jest ten tryb, który w przeważającej
   większości przypadków nie prosi człowieka o nic — żadnych obrazków
   z przejściami dla pieszych. Nie wybieraj „Interactive".
6. **Create**. Cloudflare pokaże dwie wartości:
   - **Site Key** — zaczyna się od `0x4AAA…`, jest **publiczny** (wchodzi do
     kodu strony, każdy go widzi);
   - **Secret Key** — **to jest sekret**, nie wklejaj go nigdzie poza Railway,
     nie wysyłaj mailem, nie wklejaj do issue na GitHubie.

Widget jest darmowy i bez limitu zapytań — Cloudflare nie każe za niego płacić.

### 8A.2 Co wpisać w Railway

Environment `production` → **Variables** → **Shared Variables**
(`railway.ts` odwołuje się do nich przez `ctx.shared`, więc muszą istnieć
pod dokładnie tymi nazwami):

| Zmienna | Wartość | Sealed? |
|---|---|---|
| `TURNSTILE_SITE_KEY` | Site Key z 8A.1 | nie |
| `TURNSTILE_SECRET_KEY` | Secret Key z 8A.1 | **TAK — zaznacz „Sealed"** |

Potem `railway config apply` (albo, jeśli chodzisz bez IaC, wpisz obie
zmienne wprost w serwisie `kuking.pl`) i **restart serwisu** — Laravel czyta
konfigurację przy starcie.

Dla środowiska `staging` zrób osobny widget albo dopisz domenę staginu do
listy hostnames w tym samym widgetcie. Ten sam Secret Key wolno użyć w obu.

### 8A.3 Sprawdzenie, że naprawdę działa

```bash
# 1. Healthcheck przestaje narzekać (przed wgraniem kluczy: "degraded")
curl -s https://kuking.pl/health | jq '.status, .checks.turnstile'
# oczekiwane: "ok"  oraz  { "ok": true }

# 2. Widget jest na stronie rejestracji
curl -s https://kuking.pl/register | grep -c 'cf-turnstile'
# oczekiwane: liczba większa od zera
```

Potem otwórz `https://kuking.pl/register` w przeglądarce: nad przyciskiem
„Załóż konto" ma być zdanie „Zanim wyślesz, sprawdzamy, że formularza nie
wypełnia automat" i pod nim ramka Turnstile, która sama zamienia się
w zielony ptaszek. **Jeśli ramka nie zamienia się w nic i widać błąd
`Error: 400020`** — Site Key nie pasuje do domeny; wróć do 8A.1 punkt 4.

### 8A.4 Czego się NIE spodziewać (i o co nie prosić)

- **Turnstile ZABLOKUJE formularz osobie z wyłączonym JavaScriptem** — i tak
  ma być od 9 września 2026 (decyzja właściciela, D-050; pierwsza wersja
  przepuszczała puste pole). Taka osoba zobaczy w miejscu widgetu ramkę
  `<noscript>` ze zdaniem, czego konkretnie nie da się zrobić, i adresem
  e-mail, pod którym siedzi człowiek. Jeśli po wgraniu kluczy ta ramka NIE
  pojawia się przy wyłączonym skrypcie — to jest usterka do naprawienia od
  razu, a nie drobiazg: bez niej ludzie stoją przed martwym przyciskiem.
- **Kto ma JavaScript, a mimo to dostał odmowę**, zobaczy inny komunikat:
  o tym, że sprawdzenie się nie wczytało (blokada reklam, słabe łącze), co
  z tym zrobić i gdzie napisać. Dwa różne teksty dla dwóch różnych sytuacji
  to wymóg z D-050, nie stylistyka.
- **Odrzucenia z braku tokenu widać w dzienniku** (`Log::warning`, wpis
  „Turnstile: formularz odrzucony, bo nie przyszedł token" z nazwą miejsca,
  bez adresu IP). Po tygodniu od wdrożenia przejrzyj je: to jest jedyna
  odpowiedź na pytanie, czy zamknęliśmy komuś drzwi.
- **Turnstile nie zastępuje limitów zapytań** — one zostają bez zmian.
- **Awaria Cloudflare nie zamknie rejestracji.** Gdy `siteverify` nie
  odpowiada, formularz przechodzi, a w dzienniku ląduje ostrzeżenie. To się
  zaciśnięciem NIE zmieniło.

### 8A.5 Jak to wyłączyć w minutę

Wyczyść `TURNSTILE_SITE_KEY` i `TURNSTILE_SECRET_KEY` (albo, punktowo,
ustaw np. `TURNSTILE_NA_LOGOWANIU=false`) i zrestartuj serwis. Nie ma
migracji do cofania — Turnstile nie zapisuje niczego do bazy.

Po wyłączeniu wszystkiego ustaw też wszystkie sześć `TURNSTILE_NA_*`
na `false`, żeby `/health` nie zgłaszał `degraded`: brak kluczy jest błędem
tylko wtedy, gdy konfiguracja nadal obiecuje ochronę.

---

## KROK 8B. Moderacja modelem — sprawdzenie, czy klucz naprawdę działa

**Kiedy:** po wgraniu `OPENAI_MODERATION_KEY` do zmiennych Railway.
**Ile zajmuje:** jedno polecenie.

```
php artisan kuking:sprawdz-model
```

W konsoli Railway (zakładka *Console* przy serwisie `kuking.pl`) — jesteś już
wtedy w kontenerze, więc bez `railway ssh`.

**Dlaczego to jest osobny krok, a nie sprawdzenie w `/health`.** Klient modelu
(`App\Moderacja\KlientOpenAI`) celowo zwraca `null` przy KAŻDEJ porażce: brak
klucza, awaria sieci, HTTP 401, odpowiedź w nieznanym kształcie. Dla aplikacji
to jedyne poprawne zachowanie — „nie wiemy" nie może znaczyć „treść jest
w porządku", a awaria cudzej usługi nie może zatrzymać czyjegoś wpisu. Skutek
uboczny jest jednak taki, że **działająca i wyłączona moderacja wyglądają
identycznie z zewnątrz**. Ta komenda robi jedno prawdziwe zapytanie i mówi, co
z niego wyszło.

W `/health` tego nie ma świadomie: Turnstile ma tam sprawdzenie, bo bez kluczy
konfiguracja **obiecuje ochronę, której nie ma**. Model niczego nie obiecuje —
brak klucza znaczy „funkcja wyłączona" i jest to stan dopuszczalny, więc
`degraded` byłoby fałszywym alarmem na każdym środowisku bez klucza (CI,
lokalnie, staging).

**Co zobaczysz:**

| Wynik | Co znaczy |
|---|---|
| `Działa — model ocenił tekst i odpowiedział` | klucz dobry, endpoint dobry, nazwa modelu dobra |
| `WYŁĄCZONA — nie ma klucza` | zmienna nie doszła; sprawdź, czy wdrożenie po jej dodaniu się skończyło |
| `HTTP 401` | klucz zły albo unieważniony — najczęściej skopiowana spacja na końcu |
| `HTTP 403` | klucz ograniczony bez prawa do `/v1/moderations` (*Model capabilities* w panelu OpenAI) |
| `HTTP 404` | nazwa modelu nie istnieje; ustaw `KUKING_MODEL_NAZWA`, bez wdrożenia |
| `HTTP 429` | limit tempa; odczekaj minutę |
| `HTTP 5xx` | awaria OpenAI; nasz kod przepuszcza wtedy wpisy dalej |
| `nie ma pola results` | rozmawiamy z czymś innym niż API moderacji — sprawdź `KUKING_MODEL_ENDPOINT` |

Ocenę zdjęć sprawdza się osobno: `php artisan kuking:sprawdz-model --zdjecie`.
To jest przy tym serwisie ważniejsze od tekstu — zdjęcia są tym, czego nikt nie
przeczyta, dopóki ktoś nie zgłosi.

Klucz nie jest wypisywany nigdzie w wyniku, nawet fragmentem. Pilnuje tego test.

---

## KROK 8C. R2 — sprawdzenie, czy oryginały naprawdę nie są publiczne

**Po przestawieniu `FILESYSTEM_DISK=r2` i `KUKING_MEDIA_DISK=r2`, przed
wystawieniem `cdn.kuking.pl`:**

```
railway ssh -- php artisan kuking:bramka-r2 --zapis
```

Ta komenda odhacza siedem z dwunastu punktów bramki z issue #120 — prawdziwymi
żądaniami do prawdziwego R2, na prawdziwym zdjęciu z bazy. Wgraj więc najpierw
jedno zdjęcie przez formularz: bez zdjęcia komenda mówi „nie ma na czym
sprawdzać" i **oblewa**, bo brak dowodu nie jest dowodem.

**Co zobaczysz:**

| Wynik | Co znaczy |
|---|---|
| `Część serwerowa bramki PRZESZŁA` | podpisy działają, bucket nie oddaje nic bez podpisu, `PutObject` przechodzi bez ACL |
| `Serwis NIE zapisuje zdjęć do R2` | `FILESYSTEM_DISK`/`KUKING_MEDIA_DISK` jeszcze nie są `r2`; komenda odmawia sprawdzania dysku lokalnego |
| `ALARM: bucket wariantów oddaje pliki BEZ podpisu` | włączony `r2.dev` albo publiczna domena — wyłącz w panelu R2 |
| `ALARM: oryginał z pełnym EXIF-em` | bucket oryginałów jest publiczny. To najgorszy możliwy wynik: w oryginale siedzi GPS kuchni |
| `ALARM: leży tam N plik(ów)` | w publicznym buckecie są klucze `incoming/` — przenieś je i skasuj |
| `ALARM: w wariancie siedzi blok EXIF` | przekodowanie nie zdjęło EXIF-u; wariant idzie do każdego, kto widzi wpis |
| `NIE WIEMY` | brak odpowiedzi z sieci albo nieudane listowanie. **Liczy się jak oblany** |
| `TEN SAM bucket` | oba dyski wskazują jeden bucket; ustaw `AWS_PUBLIC_BUCKET` |

Bez `--zapis` bramka nie jest domknięta (nie ma dowodu na `PutObject` bez ACL).
`--zapis` zapisuje **jeden** plik tekstowy w prefiksie `bramka/` i kasuje go po
odczycie — mówi o tym przed zrobieniem i sprząta także po wyjątku.

Reszta punktów wymaga człowieka i panelu Cloudflare: przełącznik `r2.dev`,
zdjęcie ~14,9 MB, po jednej próbce JPEG/PNG/WebP/AVIF z aparatu, kasowanie
wpisu razem z wariantami i ścieżka błędu przy złym sekrecie. Komenda wypisuje
je na końcu. Wynik z datą wpisz do `docs/infra/BRAMKA_R2.md`.

Klucze API nie są wypisywane nigdzie w wyniku — ani endpoint z identyfikatorem
konta, ani sygnatura podpisanego adresu. Pilnuje tego test.

---

## KROK 8D. Wejście kontem Google — dwa klucze z Google Cloud Console

**Kiedy:** po kroku 8, przed kampanią startową.
**Ile zajmuje:** pięć minut w Google Cloud Console, dwie zmienne w Railway.
**Co się stanie, jeśli tego nie zrobisz:** nic się nie zepsuje — przycisku
„Wejdź kontem Google" po prostu nie będzie na ekranie, a hasło i wiadomość
z linkiem działają jak dziś. Ale `/health` będzie oddawał `status: degraded`
z powodem `google_bez_kluczy` (patrz 8D.4), bo konfiguracja obiecuje drogę
wejścia, której nie ma. To jest zamierzone: cicha, nieistniejąca droga jest
gorsza niż jej jawny brak.

Decyzja i uzasadnienie: [`docs/DECISIONS.md` D-069](../DECISIONS.md), issue #258.

### 8D.1 Co kliknąć w Google Cloud Console

1. Wejdź na [console.cloud.google.com](https://console.cloud.google.com)
   i zaloguj się kontem Google, które ma zostać właścicielem tej integracji
   (najlepiej tym samym, na którym trzymasz sprawy Kuking — **nie** kontem
   prywatnym, którego ktoś kiedyś nie odzyska).
2. Na górnym pasku wybierz projekt albo **New Project**:
   - **Project name:** `kuking`. **Create**.
3. Menu po lewej (☰) → **APIs & Services** → **OAuth consent screen**.
   To trzeba wypełnić RAZ, zanim Google w ogóle pozwoli utworzyć klucze:
   - **User Type:** **External** → **Create**;
   - **App name:** `Kuking` — **to jest nazwa, którą człowiek zobaczy** na
     ekranie zgody („Kuking chce uzyskać dostęp do Twojego konta Google"),
     więc nie wpisuj tu `kuking-prod-2`;
   - **User support email:** `kontakt@kuking.pl`;
   - **App logo:** możesz pominąć teraz i dodać później;
   - **Application home page:** `https://kuking.pl`;
   - **Privacy policy link:** `https://kuking.pl/prywatnosc`
     — **to pole jest obowiązkowe do zdjęcia trybu testowego**, a nasza
     polityka wymienia już Google (D-069);
   - **Terms of service link:** `https://kuking.pl/regulamin`;
   - **Authorized domains:** `kuking.pl`;
   - **Developer contact information:** `kontakt@kuking.pl`;
   - **Save and continue**.
4. **Scopes** (następny ekran): dodaj **dokładnie trzy** i ani jednego
   więcej — `openid`, `.../auth/userinfo.email`,
   `.../auth/userinfo.profile`. Wszystkie trzy są „non-sensitive", więc
   **nie wymagają weryfikacji aplikacji przez Google** i nie ma tu żadnego
   czekania na przegląd. Gdyby ktoś kiedyś dopisał tu cokolwiek innego,
   Google zażąda weryfikacji, a ekran zgody zacznie ostrzegać ludzi. →
   **Save and continue**.
5. **Test users**: dopóki aplikacja jest w trybie **Testing**, wejść tą
   drogą mogą TYLKO konta wypisane na tej liście (limit 100). Dopisz
   **swoje konto Google i konto mamy**, żeby przetestować to na prawdziwych
   ludziach. → **Save and continue** → **Back to dashboard**.
6. **PRZED KAMPANIĄ: OAuth consent screen → „Publish app" → Confirm.**
   Bez tego kroku przycisk zadziała Tobie i nie zadziała nikomu z Facebooka —
   obcy człowiek dostanie po angielsku „Access blocked: this app is not
   ready". Przy naszych trzech zakresach publikacja jest natychmiastowa,
   bez przeglądu ze strony Google.
7. **APIs & Services** → **Credentials** → **Create credentials** →
   **OAuth client ID**:
   - **Application type:** **Web application**;
   - **Name:** `kuking.pl` (etykieta w panelu, człowiek jej nie widzi);
   - **Authorized JavaScript origins:** **zostaw puste.** Nasza droga nie
     używa skryptu Google — to jest zwykłe przekierowanie po stronie
     serwera;
   - **Authorized redirect URIs** — **dodaj wszystkie trzy, co do znaku**
     (bez ukośnika na końcu, z `https`):

     ```text
     https://kuking.pl/wejdz/google/wroc
     https://www.kuking.pl/wejdz/google/wroc
     https://kuking-pl-production.up.railway.app/wejdz/google/wroc
     ```

     Jeśli masz staging, dodaj też jego adres. Chcesz testować u siebie
     na `php artisan serve` — dodaj `http://127.0.0.1:8000/wejdz/google/wroc`
     (Google dopuszcza `http` **tylko** dla `localhost`/`127.0.0.1`).

     **To jest jedyne miejsce w całym kroku, w którym literówka objawia się
     dopiero u człowieka:** Google odpowiada wtedy `redirect_uri_mismatch`,
     a my odsyłamy tę osobę na logowanie hasłem i zapisujemy błąd
     w dzienniku. Skopiuj te adresy stąd, nie przepisuj.
   - **Create**. Google pokaże dwie wartości:
     - **Client ID** — kończy się na `.apps.googleusercontent.com`, jest
       **publiczny** (wchodzi do adresu, na który odsyłamy człowieka);
     - **Client secret** — **to jest sekret**. Nie wklejaj go nigdzie poza
       Railway, nie wysyłaj pocztą, nie wklejaj do issue na GitHubie.

Logowanie kontem Google jest darmowe i bez limitu — Google nie każe za nie
płacić.

### 8D.2 Co wpisać w Railway

Environment `production` → **Variables** → **Shared Variables**
(`railway.ts` odwołuje się do nich przez `ctx.shared`, więc muszą istnieć
pod dokładnie tymi nazwami):

| Zmienna | Wartość | Sealed? |
|---|---|---|
| `GOOGLE_CLIENT_ID` | Client ID z 8D.1 | nie |
| `GOOGLE_CLIENT_SECRET` | Client secret z 8D.1 | **TAK — zaznacz „Sealed"** |

Potem `railway config apply` (albo, jeśli chodzisz bez IaC, wpisz obie
zmienne wprost w serwisie `kuking.pl`) i **restart serwisu** — Laravel czyta
konfigurację przy starcie.

Dla środowiska `staging` możesz użyć tego samego klienta, jeśli dopisałeś
adres staginu do listy z 8D.1.

### 8D.3 Sprawdzenie, że naprawdę działa

```bash
# 1. Healthcheck przestaje narzekać (przed wgraniem kluczy: "degraded")
curl -s https://kuking.pl/health | jq '.status, .checks.google'
# oczekiwane: "ok"  oraz  { "ok": true }

# 2. Przycisk jest na ekranie logowania i rejestracji
curl -s https://kuking.pl/login | grep -c 'wejdz/google'
# oczekiwane: liczba większa od zera
```

Potem otwórz `https://kuking.pl/login` w przeglądarce. Pod formularzem hasła
ma być karta „Masz konto Google? Wejdź jednym kliknięciem" z przyciskiem
**„Wejdź kontem Google"**. Kliknij go **kontem, które NIE ma jeszcze konta
w Kuking**: powinieneś zobaczyć ekran zgody Google z nazwą **Kuking**,
a po powrocie nasz ekran „Jeszcze dwie rzeczy i konto gotowe" z imieniem
i podpowiedzianą nazwą — i z **dwoma niezaznaczonymi haczykami**. Konto
powstaje dopiero po ich zaznaczeniu.

### 8D.4 Czego się NIE spodziewać (i o co nie prosić)

- **Ta droga nie zastępuje hasła ani wiadomości z linkiem** i nigdy nie
  będzie jedyna: część naszych ludzi ma adresy `@wp.pl`, `@o2.pl`
  i `@interia.pl`, gdzie konta Google nie ma (D-069).
- **Osoba, która ma już u nas konto na ten adres, NIE wejdzie jednym
  kliknięciem od razu.** Zobaczy ekran „Połączyć to konto z kontem
  Google?" i dopiero po potwierdzeniu wejdzie. Tak ma być — to nie usterka.
- **Osoba, której konto ma NIEPOTWIERDZONY u nas adres, dostanie odmowę**
  i zdanie „wejdź hasłem albo linkiem i potwierdź adres". To jest
  najważniejsze zabezpieczenie tej funkcji, nie utrudnienie: bez niego
  ktoś mógłby założyć konto na cudzy adres i przechwycić je w chwili, gdy
  prawdziwy właściciel przyjdzie przez Google (D-069, reguła 2).
- **Moderator i administrator tą drogą nie wejdą wcale** — tam obowiązuje
  hasło i kod z aplikacji.
- **Konta Google z niepotwierdzonym adresem nie wejdą nigdzie.** Google
  mówi nam wprost, czy adres potwierdziło, i bez tego potwierdzenia nie
  robimy nic.
- **Awaria Google nie zamyka drzwi**: człowiek wraca na ekran logowania ze
  zdaniem po polsku, a hasło i „Wyślij mi link do zalogowania" stoją tam,
  gdzie stały. Angielskiego kodu od Google nie pokazujemy nikomu.
- **Odmowy i awarie widać w dzienniku** (`Log::warning` / `Log::error`,
  bez adresu e-mail i bez tokenu). Po tygodniu od wdrożenia przejrzyj je:
  to jedyna odpowiedź na pytanie, czy zamknęliśmy komuś drzwi.
- **Turnstile na tej drodze nie stoi** i to jest decyzja, nie
  przeoczenie — uzasadnienie w D-069, rozstrzygnięcie 5.

### 8D.5 Jak to wyłączyć w minutę

```bash
KUKING_WEJSCIE_GOOGLE=false   # + restart serwisu
```

Przycisk znika z ekranu logowania i rejestracji, trasy odsyłają na logowanie
ze zdaniem po polsku, **powiązania w bazie zostają nietknięte i nikt nie
traci dostępu** — konto założone tą drogą ma potwierdzony adres, więc zostaje
mu wiadomość z linkiem i „Nie pamiętam hasła".

Drugi sposób (mocniejszy): wyczyść `GOOGLE_CLIENT_ID` i `GOOGLE_CLIENT_SECRET`.
Wtedy ustaw też `KUKING_WEJSCIE_GOOGLE=false`, żeby `/health` nie zgłaszał
`degraded` — brak kluczy jest błędem tylko wtedy, gdy konfiguracja nadal
obiecuje tę drogę.

**Migracji do cofania NIE MA i nie cofaj jej dla wyłączenia funkcji.**
`migrate:rollback` na `2026_09_10_500000_add_google_account_to_users`
skasowałby powiązania — a dla części osób to jedyna droga wejścia, jaką znają
(hasła nigdy nie ustawiały). Dlatego to cofnięcie **samo odmawia**, dopóki
nie powiesz mu wprost `KUKING_ROLLBACK_KASUJ_POWIAZANIA_GOOGLE=true`.

---

## KROK 9. Zastosowanie infrastruktury (`railway.ts`)

> ⚠️ **SPROSTOWANIE, 9 września 2026 — przeczytaj przed uruchomieniem czegokolwiek
> w tym kroku.** Zmierzone connectorem Railway tego dnia: na produkcji istnieje
> dziś **jeden** serwis aplikacyjny, `kuking.pl`, uruchamiany komendą
> `/usr/local/bin/kuking-entrypoint all` (serwer HTTP + kolejka + harmonogram
> w jednym kontenerze, jedna replika, region europe-west4). Serwisów `web`,
> `worker`, `scheduler` **nie ma i nigdy nie było** — `railway config apply`
> **nie zostało jeszcze ani razu uruchomione** na tym projekcie. `railway.ts`
> opisuje stan docelowy (patrz komentarz nad `PRODUCTION_SPLIT_SERVICES`
> w tym pliku), nie stan obecny.
>
> To znaczy, że `railway config plan` niżej może dziś zaproponować **więcej
> niż „utworzenie" trzech nowych serwisów obok istniejącego** — może chcieć
> zmienić nazwę/rolę istniejącego serwisu `kuking.pl`, przenieść jego domenę
> albo jego zmienne. **Przeczytaj cały wynik `plan` przed potwierdzeniem
> `apply`** — nie zakładaj z góry, co zrobi. I nie uruchamiaj `apply` na
> `production`, dopóki nie istnieje żadna kopia bazy (dziś: nie istnieje
> żadna — patrz `docs/DECISIONS.md` D-043 i `docs/infra/KOPIE_I_ODTWORZENIE.md`)
> — operacja na serwisach jest odwracalna, utrata jedynej kopii danych nie jest.

```bash
cd ~/kuking.pl

railway login
railway link                    # wybierz workspace → projekt kuking → środowisko production

# PODGLĄD — nie zmienia niczego. Przeczytaj wynik uważnie.
railway config plan
```

Docelowo — jeśli plan wygląda tak, jak zakłada `railway.ts` — zobaczysz listę
zmian: utworzenie serwisów `web`, `worker`, `scheduler`, przypisanie domen,
zmiennych, healthchecku. Ale to jest opis ZAMIERZONEGO wyniku, nie gwarancja:
skoro na produkcji istnieje dziś serwis o innej nazwie (`kuking.pl`, nie
`web`), `plan` może pokazać coś innego niż samo „utworzenie" — czytaj wynik,
nie tę listę.

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
`scheduler`, `postgres` w dwóch grupach: „Aplikacja" i „Dane" — **o ile
`apply` zostało uruchomione i przebiegło zgodnie z planem**. Stan sprzed tego
kroku (i stan na 9 września 2026, zanim ktokolwiek to uruchomił) to jeden
serwis `kuking.pl` w trybie `all` + `Postgres`.

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

> ## ⛔ TEJ REGUŁY NIE TWÓRZ — wycofana razem z §2.3 (decyzja D-020)
>
> Zakłada host `cdn.kuking.pl`, którego po D-020 nie ma i mieć nie ma.
> Gdyby ktoś utworzył domenę Z TĄ regułą, dostałby najgorszy z możliwych
> wariantów: adres pliku omijający Policy **plus** 30 dni cache'u na brzegu
> i w przeglądarce. Decyzja moderacyjna, blokada i przełączenie przepisu na
> prywatny nie miałyby wtedy żadnego wpływu na to, co ktoś już otworzył.
>
> Zdjęcia idą dziś trasą `/zdjecia/{media}/{wariant}`, którą **reguła 3**
> (Bypass dla `kuking_session`) i tak wyklucza z cache'u — i tak ma zostać.
> Treść reguły zostaje poniżej wyłącznie jako historia.

<details>
<summary>Treść wycofanej reguły — NIE do wpisania</summary>

```text
Gdy:   http.host eq "cdn.kuking.pl"
Wtedy: Cache eligibility = Eligible
       Edge TTL          = 30 dni
       Browser TTL       = 30 dni
```

</details>

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

# 8. Domena R2 NIE odpowiada  ← test krytyczny dla prywatności, odwrócony po D-020
curl -s -o /dev/null -w "%{http_code}\n" https://cdn.kuking.pl/
# Oczekiwane: BŁĄD DNS (curl kończy się kodem 6, „Could not resolve host").
# Jakakolwiek odpowiedź HTTP — także 404 — znaczy, że bucket wariantów ma
# własną domenę, czyli że adres pliku znowu omija Policy. Wtedy: usuń Custom
# Domain w panelu R2, zanim pójdziesz dalej. Patrz §2.3 i D-020.

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
| 14 | URL zdjęcia | zaczyna się od `https://kuking.pl/zdjecia/` — to trasa aplikacji, nie plik w buckecie (D-020, audyt W7-02). Adres `cdn.kuking.pl` znaczy, że coś poszło źle: patrz punkt 8 wyżej. |
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

> **Aktualna, pełna wersja tego ćwiczenia (z dowodem mierzalnym i tabelą
> wyników) jest w [`KOPIE_I_ODTWORZENIE.md`](KOPIE_I_ODTWORZENIE.md) §4-5.**
> Wersja niżej zostaje jako część ciągłego runbooku wdrożenia od zera, ale
> w razie sprzeczności wygrywa tamten dokument.

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
| 28 | `healthcheck.railway.app` w `TrustHosts` (WŁĄCZONE, D-071) | `app/Support/ZaufaneHosty.php` | 1 |
| 29 | Presigned upload + usuwanie EXIF/GPS | `app/Jobs/ProcessUploadedImage.php` | §7 decyzji |

---

## Szybka pomoc — najczęstsze problemy

| Objaw | Najczęstsza przyczyna | Naprawa |
|---|---|---|
| **404 na `kuking.pl`**, CNAME działa | brak rekordu **TXT** | dodaj TXT z panelu Railway (krok 10.2) |
| **Deploy pada: „healthcheck failed with status 400"** | `TrustHosts` blokuje `healthcheck.railway.app` | ustaw `KUKING_ZAUFANE_HOSTY` w panelu Railwaya (naprawa bez deployu), potem popraw `App\Support\ZaufaneHosty` |
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
