// =============================================================================
//  Kuking.pl — Railway Infrastructure as Code
//  Docelowa lokalizacja w repo: .railway/railway.ts
// =============================================================================
//
//  STATUS NARZĘDZIA (zweryfikowane 2026-09-05 na SDK `railway@3.11.0`)
//    * Railway IaC w TypeScripcie jest GENERALLY AVAILABLE (Python i Go: beta).
//      https://docs.railway.com/infrastructure-as-code
//    * Config as Code (railway.json / railway.toml) jest DEPRECATED. Istniejące
//      pliki działają do twardego terminu 2026-12-01; nowe serwisy nie mogą już
//      w to wejść.  https://docs.railway.com/config-as-code
//    * Wymaga Railway CLI >= 5.42.1 oraz `npm install railway` w repo.
//
//  WAŻNA UWAGA O DOKUMENTACJI
//  --------------------------
//  Strona https://docs.railway.com/infrastructure-as-code/reference opisuje
//  tylko SKRÓCONY zestaw pól (source, build, start, preDeploy, healthcheck,
//  healthcheckTimeout, replicas, env, domains, volumeMounts). Faktyczne typy
//  w paczce npm `railway` są znacznie bogatsze — sprawdzone przez odczytanie
//  `node_modules/railway/dist/index-C3uk0ruc.d.ts` (typy `IntentServiceConfig`,
//  `DeployConfig`, `BuildConfig`, `ServiceSource`).
//  Dlatego ten plik używa pełnych bloków `build` i `deploy`, które pozwalają
//  zadeklarować restart policy, Serverless, region, graceful shutdown i limity
//  pamięci — czyli rzeczy, które według samej dokumentacji trzeba by klikać
//  ręcznie w panelu.
//
//  URUCHOMIENIE
//    npm install railway
//    railway link                   # wybierz projekt i środowisko
//    railway config plan            # podgląd zmian, NIC nie zmienia
//    railway config apply           # zastosowanie po potwierdzeniu
//
//  W CI (workflows/railway-iac.yml) używamy przypiętego planu:
//    railway config plan  --out railway-plan.json
//    railway config apply --plan railway-plan.json --yes --confirm-destructive
// =============================================================================

import {
  defineRailway,
  github,
  group,
  postgres,
  project,
  service,
} from "railway/iac";

// -----------------------------------------------------------------------------
//  Stałe projektu
// -----------------------------------------------------------------------------

const REPO = "woogitsu/kuking.pl";

/**
 * EU West Metal, Amsterdam — najbliższy region dla polskich użytkowników
 * (~20-30 ms RTT z Warszawy). Identyfikator z tabeli regionów:
 * https://docs.railway.com/deployments/regions#region-options
 *
 * Bez jawnego ustawienia Railway użyłby domyślnego regionu KONTA, który
 * bywa w USA — czyli +100 ms do każdego requestu.
 */
const REGION = "europe-west4-drams3a";

/**
 * Port, na którym nasłuchuje kontener (Caddy/FrankenPHP).
 * Musi się zgadzać z ENV PORT i SERVER_NAME w Dockerfile oraz z portem
 * używanym przez healthcheck Railway.
 *
 * Podajemy go JAWNIE przy domenach, mimo że SDK i tak domyślnie wstawia 8080
 * (sprawdzone eksperymentalnie na railway@3.11.0). Gdyby ktoś kiedyś zmienił
 * PORT w Dockerfile, jawna wartość tutaj natychmiast pokaże niespójność
 * w `railway config plan`, zamiast dać cichy 502 na produkcji.
 */
const APP_PORT = 8080;

/** Domeny produkcyjne. Rekordy DNS w Cloudflare — patrz DEPLOYMENT_RUNBOOK.md. */
const PROD_DOMAINS = [
  { domain: "kuking.pl", port: APP_PORT },
  { domain: "www.kuking.pl", port: APP_PORT },
];
const STAGING_DOMAINS = [{ domain: "staging.kuking.pl", port: APP_PORT }];

/** Limity pamięci (w bajtach) — bezpiecznik przeciw rachunkowi-niespodziance. */
const MB = 1024 * 1024;

/**
 * TOPOLOGIA PRODUKCJI — jedyny przełącznik, który realnie zmienia rachunek.
 *
 *   false (ALFA, ~50 użytkowników)
 *     Produkcja to JEDEN serwis aplikacyjny w trybie APP_ROLE=all
 *     (web + worker + scheduler w jednym kontenerze) + Postgres.
 *     Koszt Railway ~12-18 USD/mies. Wystarcza, gdy zdjęć jest mało
 *     i nikt nie zauważy, że przetwarzanie obrazu zabiera CPU stronie.
 *
 *   true (BETA i dalej, od ~kilkuset aktywnych użytkowników)
 *     Produkcja to trzy osobne serwisy: web, worker, scheduler + Postgres.
 *     Koszt Railway ~40-65 USD/mies.
 *
 * KIEDY PRZEŁĄCZYĆ NA true — którykolwiek z warunków:
 *   * p95 czasu odpowiedzi rośnie w godzinach szczytu uploadów,
 *   * kolejka `jobs` regularnie ma zaległości > 100 rekordów,
 *   * worker padł na OOM i zabrał ze sobą stronę,
 *   * masz płacących użytkowników i deploy nie może już przerywać
 *     przetwarzania zdjęć.
 *
 * Przełączenie to zmiana jednej linii + PR + `railway config apply`.
 * Uzasadnienie pełne: INFRA_DECISION.md, sekcja "Serwisy w Railway".
 */
const PRODUCTION_SPLIT_SERVICES = true;

// =============================================================================
export default defineRailway((ctx) => {
  // ---------------------------------------------------------------------------
  //  Rozpoznanie środowiska
  //
  //  production — kuking.pl, gałąź main, 4 serwisy, pełne SLA
  //  staging    — staging.kuking.pl, gałąź staging, 2 serwisy, Serverless ON
  //  pr-*       — środowiska PR tworzone i usuwane automatycznie przez Railway
  // ---------------------------------------------------------------------------
  const isProduction = ctx.isEnvironment("production");
  const isStaging = ctx.isEnvironment("staging");

  // Czy produkcja ma rozdzielone serwisy (web/worker/scheduler)?
  // Poza produkcją NIGDY nie rozdzielamy — staging i preview zawsze
  // chodzą jako jeden kontener w trybie APP_ROLE=all.
  const splitServices = isProduction && PRODUCTION_SPLIT_SERVICES;

  // Cokolwiek innego to efemeryczne środowisko PR. Konfigurujemy je tak samo
  // jak staging (jeden serwis, Serverless włączony, brak domeny custom),
  // więc nie potrzebujemy osobnej flagi — wystarczy `!isProduction`.

  // ctx.environment jest typu `string | undefined`, więc normalizujemy raz.
  const envName = ctx.environment ?? (isProduction ? "production" : "unknown");

  // ---------------------------------------------------------------------------
  //  BAZA DANYCH — PostgreSQL 18
  //
  //  Obraz Railway `postgres-ssl` ma opublikowany tag 18.x (sprawdzone
  //  2026-09-05: ghcr.io/railwayapp-templates/postgres-ssl:18.3), więc
  //  wymóg właściciela "PostgreSQL 18, żadnego MySQL" jest spełniony.
  //  Konkretny major wybiera się przy zakładaniu serwisu w panelu — DSL
  //  deklaruje intencję "tu stoi Postgres", provisioning robi Railway.
  //  https://docs.railway.com/databases/postgresql
  //
  //  KAŻDE środowisko ma WŁASNĄ bazę. Zero współdzielenia: staging nie może
  //  dotknąć produkcyjnych danych użytkowników (RODO + zdrowy rozsądek).
  // ---------------------------------------------------------------------------
  const db = postgres("postgres", { region: REGION });

  // ---------------------------------------------------------------------------
  //  ZMIENNE WSPÓLNE
  //
  //  ZASADA ŻELAZNA: w tym pliku NIE MA ANI JEDNEGO SEKRETU.
  //  Sekrety żyją jako "shared variables" na środowisku Railway (ustawiane raz,
  //  w panelu) i są tu tylko REFERENCOWANE przez ctx.shared.NAZWA, co kompiluje
  //  się do ${{shared.NAZWA}}.
  //
  //  ctx.shared wskazuje na ISTNIEJĄCĄ zmienną — NIE tworzy jej. Najpierw
  //  założ ją w panelu (Environment → Variables → Shared Variables),
  //  potem `railway config apply`. Lista wszystkich: DEPLOYMENT_RUNBOOK.md.
  // ---------------------------------------------------------------------------
  const appEnv = {
    // --- Rdzeń Laravela -------------------------------------------------------
    APP_NAME: "Kuking",
    APP_ENV: isProduction ? "production" : "staging",
    APP_DEBUG: "false", // NIGDY "true" na czymkolwiek dostępnym z internetu
    APP_KEY: ctx.shared.APP_KEY, // inny dla każdego środowiska
    APP_URL: isProduction
      ? "https://kuking.pl"
      : isStaging
        ? "https://staging.kuking.pl"
        : // Preview: Railway wstrzykuje własną domenę serwisu
          "https://${{RAILWAY_PUBLIC_DOMAIN}}",
    APP_LOCALE: "pl",
    APP_FALLBACK_LOCALE: "pl",
    APP_FAKER_LOCALE: "pl_PL",

    // Aplikacja i baza pracują w UTC; prezentacja przelicza na Europe/Warsaw.
    // Chroni przed klasą błędów wokół zmiany czasu.
    APP_TIMEZONE: "UTC",

    // --- Logi -----------------------------------------------------------------
    // stderr, bo filesystem kontenera jest ulotny — plik logu zniknie przy
    // restarcie. JsonFormatter daje w Railway structured logs (filtrowanie
    // po polach, nie po regexie).
    // https://docs.railway.com/guides/laravel#logging
    LOG_CHANNEL: "stderr",
    LOG_STDERR_FORMATTER: "\\Monolog\\Formatter\\JsonFormatter",
    LOG_LEVEL: isProduction ? "warning" : "debug",

    // --- Baza danych ----------------------------------------------------------
    DB_CONNECTION: "pgsql",
    // Referencja do serwisu Postgres. Ruch idzie prywatną siecią Railway
    // (tunele Wireguard) — bez kosztu egress i bez wystawiania bazy na
    // publiczny internet.  https://docs.railway.com/networking/private-networking
    DB_URL: db.env.DATABASE_URL,

    // --- Sesje, cache, kolejka ------------------------------------------------
    // MVP: wszystko w Postgresie. Redis dopiero po POMIARZE (docs/ARCHITECTURE.md)
    // — jeden serwis mniej do utrzymania i opłacenia.
    // Skutek uboczny, który nam sprzyja: sesje i cache są od razu współdzielone
    // między replikami, więc skalowanie poziome web jest bezpieczne od dnia 1.
    SESSION_DRIVER: "database",
    SESSION_LIFETIME: "43200", // 30 dni — audytorium 50+ nie chce się logować co tydzień
    SESSION_ENCRYPT: "true",
    SESSION_SECURE_COOKIE: "true",
    SESSION_SAME_SITE: "lax",
    CACHE_STORE: "database",
    QUEUE_CONNECTION: "database",

    // --- Zaufane proxy --------------------------------------------------------
    //  Łańcuch: Cloudflare → Railway edge → kontener. Bez zaufania do
    //  nagłówków X-Forwarded-* Laravel widzi adres proxy zamiast użytkownika
    //  (psuje limity i logi bezpieczeństwa) i generuje URL-e po http://
    //  zamiast https:// (mixed content, pętle przekierowań).
    //
    //  TRUSTED_PROXIES ZOSTAŁO USUNIĘTE, A NIE PRZENIESIONE (ustalenie SEC-01).
    //
    //  Ta zmienna NIGDY nie była przez aplikację czytana. `bootstrap/app.php`
    //  ma `trustProxies(at: '*')` wpisane na sztywno, a jedyną inną nazwą,
    //  której szuka framework, jest legacy `config('trustedproxy.proxies')` —
    //  pliku `config/trustedproxy.php` w tym repozytorium nie ma. Zmienna
    //  wyglądała więc jak przełącznik i nie przełączała niczego: ustawienie
    //  jej na listę adresów nie zmieniłoby zachowania ani o krok.
    //  (Ten sam kształt błędu co usunięty limit 'upload' i martwe
    //  `kuking.media_disk` — patrz komentarze w `config/kuking.php`.)
    //
    //  PRAWDZIWY przełącznik jest teraz jeden i niżej.
    //
    //  KUKING_ZAUFANE_PRZESKOKI — ile wpisów w `X-Forwarded-For` dopisuje
    //  nasza własna infrastruktura. Aplikacja czyta adres klienta jako n-ty
    //  wpis OD KOŃCA łańcucha, bo proxy dopisuje na końcu, a klient może
    //  dopisywać tylko na początku. Pełne uzasadnienie i sposób POMIARU tej
    //  liczby: `config/proxy.php` oraz `App\Http\Middleware\NormalizeForwardedFor`.
    //
    //  Zostawiamy 1 do czasu pomiaru na żywej infrastrukturze (Blok B krok 6
    //  z docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md). Za mała wartość jest
    //  niegroźna (adres wspólny → limity zbyt ostre); za duża przywraca
    //  podatność, bo odczyt wchodzi w obszar wypełniany przez klienta.
    KUKING_ZAUFANE_PRZESKOKI: "1",

    // --- Storage zdjęć: Cloudflare R2, DWA BUCKETY ---------------------------
    //
    //  To jest granica bezpieczeństwa, a nie porządki (audyt G-01).
    //
    //  Cloudflare nie implementuje S3-owych ACL na obiektach — `x-amz-acl`
    //  jest w tabeli zgodności oznaczony jako NIEOBSŁUGIWANY dla PutObject.
    //  Publiczność w R2 jest cechą BUCKETU: własna domena albo r2.dev.
    //  Jeden bucket pod `cdn.kuking.pl` wystawiał więc także prefiks
    //  `incoming/` z ORYGINAŁAMI, a te niosą pełny EXIF, czyli współrzędne
    //  GPS kuchni. Adres oryginału dawało się wyprowadzić z publicznego
    //  adresu wariantu — ten sam UUID, ta sama data, inny prefiks.
    //
    //    R2_BUCKET         oryginały (`incoming/`). BEZ własnej domeny,
    //                      r2.dev WYŁĄCZONE. Dostęp tylko przez API S3.
    //    R2_PUBLIC_BUCKET  przetworzone warianty WebP (`media/`). TEN i tylko
    //                      ten ma `cdn.kuking.pl`.
    //
    //  Kod domenowy używa WYŁĄCZNIE Laravel Filesystem, więc zmiana dostawcy
    //  to zmiana zmiennych, nie przepisywanie domeny (docs/MEDIA_PIPELINE.md).
    FILESYSTEM_DISK: "r2",
    AWS_DEFAULT_REGION: "auto", // R2 wymaga literalnie "auto"
    AWS_USE_PATH_STYLE_ENDPOINT: "false",
    AWS_ACCESS_KEY_ID: ctx.shared.R2_ACCESS_KEY_ID,
    AWS_SECRET_ACCESS_KEY: ctx.shared.R2_SECRET_ACCESS_KEY,
    AWS_BUCKET: ctx.shared.R2_BUCKET,
    AWS_PUBLIC_BUCKET: ctx.shared.R2_PUBLIC_BUCKET,
    // Trzeci bucket, też prywatny: paczki z danymi (RODO). Osobny od
    // oryginałów, bo to inny cykl życia (TTL 7 dni) i inna zawartość —
    // kopia CAŁEGO konta w jednym pliku ZIP.
    AWS_EXPORTS_BUCKET: ctx.shared.R2_EXPORTS_BUCKET,
    // JAWNIE, nie z wartości domyślnej. Produkcja ma OSOBNE kontenery web,
    // worker i scheduler bez wspólnego wolumenu: paczkę buduje worker,
    // a pobranie obsługuje web. Na dysku `local` plik powstawał w jednym
    // kontenerze, a szukano go w drugim — w bazie `ready`, u człowieka 404
    // (audyt W3-01).
    KUKING_EXPORT_DISK: "r2_eksporty",
    AWS_ENDPOINT: ctx.shared.R2_ENDPOINT, // https://<ACCOUNT_ID>.r2.cloudflarestorage.com
    //  AWS_URL ZOSTAŁO USUNIĘTE, A NIE PRZENIESIONE (audyt W7-02, P0).
    //
    //  Była to własna domena bucketa wariantów za CDN Cloudflare i to ona
    //  była adresem każdego zdjęcia w serwisie. Taki adres nikogo o nic nie
    //  pyta: kto raz go skopiował, otwierał zdjęcie także po zablokowaniu,
    //  po cofnięciu obserwowania i po przełączeniu przepisu na prywatny.
    //  Najgorszy przypadek to skan odręcznej kartki z przepisem rodzinnym
    //  (`recipes.source_scan_media_id`) — z nazwiskami i adresami.
    //
    //  Adresem zdjęcia jest teraz trasa aplikacji `/zdjecia/{id}/{wariant}`:
    //  pyta Policy treści nadrzędnej i przekierowuje (302) na adres podpisany
    //  kluczem S3, ważny kilka minut. Bajty dalej nie idą przez PHP.
    //
    //  ZDJĘCIE TEJ ZMIENNEJ NIE ZDEJMUJE DOMENY Z BUCKETU. Dopóki
    //  `cdn.kuking.pl` wskazuje bucket wariantów, stare adresy działają dalej
    //  — to jest ręczna czynność w panelu Cloudflare, issue #120, i należy
    //  do właściciela.

    // --- Poczta transakcyjna --------------------------------------------------
    //
    //  `smtp` zakłada dostawcę, który daje login i hasło SMTP (Brevo,
    //  EmailLabs, Mailgun, Postmark, Resend — każdy z nich ma bramkę SMTP).
    //  Przy dostawcy po API zamień na `postmark`, `resend` albo `ses`
    //  i dołóż jego klucz — komplet zmiennych i rekordów DNS dla trzech
    //  wariantów jest w `docs/infra/POCZTA_URUCHOMIENIE.md`.
    //
    //  Po zmianie tych wartości sprawdź, że poczta NAPRAWDĘ wychodzi:
    //      railway ssh -- php artisan kuking:sprawdz-poczte ty@wp.pl
    //  Sterownik `log` przyjmuje wiadomość i zgłasza sukces, nie wysyłając
    //  jej nikomu — dlatego nie wolno go tu wpisać „na chwilę".
    MAIL_MAILER: "smtp",
    MAIL_HOST: ctx.shared.MAIL_HOST,
    MAIL_PORT: ctx.shared.MAIL_PORT,
    MAIL_USERNAME: ctx.shared.MAIL_USERNAME,
    MAIL_PASSWORD: ctx.shared.MAIL_PASSWORD,
    // `tls` = STARTTLS na porcie 587. Przy porcie 465 musi tu być `smtps`,
    // inaczej połączenie wisi do timeoutu zamiast dać czytelny błąd.
    MAIL_SCHEME: "tls",

    //  JEDEN ADRES W OBIE STRONY (decyzja właściciela, 7 IX 2026).
    //
    //  Stało tu `kuchnia@kuking.pl`, podczas gdy kod pokazywał ludziom
    //  `kontakt@kuking.pl` (`config/kuking.php`, pouczenia DSA, polityka
    //  prywatności, ekran „Nie pamiętam hasła"). Z kodu nie dało się
    //  ustalić, która skrzynka odbiera odpowiedzi — a odpowiedź na list
    //  z linkiem do zmiany hasła to dla osoby 60+ najbardziej naturalna
    //  reakcja. `config/mail.php` i `.env.example` poprawiono wtedy,
    //  ten plik został pominięty i `railway config apply` wpisywał starą
    //  wartość z powrotem.
    //
    //  Żadnego `noreply@` — `docs/brand/BRAND_EXTENDED.md` tego zabrania.
    //  Pilnuje tego test `NadawcaPocztyNieJestNoreplyTest`, który czyta
    //  także ten plik.
    //
    //  Staging wysyła z własnego adresu CELOWO: list ze środowiska
    //  testowego ma być rozpoznawalny na pierwszy rzut oka i nie może
    //  podszywać się pod produkcję. `KUKING_CONTACT_EMAIL` idzie za nim,
    //  żeby adres pokazywany i adres nadawcy zgadzały się w KAŻDYM
    //  środowisku — to jest ta sama zasada, tylko konsekwentnie.
    MAIL_FROM_ADDRESS: isProduction ? "kontakt@kuking.pl" : "staging@kuking.pl",

    // MAIL_FROM_NAME CELOWO NIEUSTAWIONE (z gałęzi D-037, scalonej w #129).
    //
    // Nazwa nadawcy składa się z imienia gospodarza
    // (`config('kuking.community.host_name')`, dziś „Ula") i „z Kuking" —
    // `config/mail.php`. Wpisana tutaj wartość WYGRYWA z tamtą, bo
    // `env()` jest w tej linijce pierwsze: dopóki stało tu „Kuking",
    // zmiana gospodarza nie miała żadnego skutku ani na produkcji, ani na
    // stagingu, a testy tego nie widziały, bo CI kopiuje `.env.example`.
    //
    // Gospodarz zmienia się w JEDNYM miejscu — `KUKING_HOST_NAME`
    // (`config/kuking.php`). Tę zmienną wolno tu przywrócić wyłącznie po to,
    // żeby nadpisać nazwę nadawcy DORAŹNIE, wbrew konfiguracji.

    KUKING_CONTACT_EMAIL: isProduction
      ? "kontakt@kuking.pl"
      : "staging@kuking.pl",

    // --- Obserwowalność -------------------------------------------------------
    SENTRY_LARAVEL_DSN: ctx.shared.SENTRY_LARAVEL_DSN,
    SENTRY_ENVIRONMENT: envName,
    // 100% błędów, ale tracing tylko próbkowany — tracing najszybciej zjada
    // darmowy limit planu.
    SENTRY_TRACES_SAMPLE_RATE: isProduction ? "0.1" : "0",
    SENTRY_PROFILES_SAMPLE_RATE: "0",
    // Railway wstrzykuje SHA commita → Sentry przypisze błąd do wydania
    // i pokaże, który commit go wprowadził.
    SENTRY_RELEASE: "${{RAILWAY_GIT_COMMIT_SHA}}",

    POSTHOG_KEY: ctx.shared.POSTHOG_KEY,
    POSTHOG_HOST: "https://eu.i.posthog.com", // instancja EU — dane w UE (RODO)

    // Powiadomienie o błędzie 500 na Slacku/Discordzie, dopóki nie da się
    // zainstalować Sentry wyżej (`docs/infra/MONITORING_BLEDOW.md`,
    // `config/logging.php` kanał `blad_webhook`). Puste = wyłączone — Railway
    // wstawi tu pusty string, dopóki właściciel nie założy tej zmiennej
    // sharedowej w panelu (Environment → Variables → Shared Variables).
    // Treść wysyłana na ten adres nie niesie danych osobowych, ale sam adres
    // to sekret (kto go zna, może pisać na kanał właściciela) — dlatego
    // idzie przez `ctx.shared`, tak jak SENTRY_LARAVEL_DSN wyżej, a nie jako
    // wartość wpisana w tym pliku.
    LOG_BLAD_WEBHOOK_URL: ctx.shared.LOG_BLAD_WEBHOOK_URL,

    // --- Runtime kontenera ----------------------------------------------------
    // Worker dekoduje zdjęcia do 24 Mpx (gd potrzebuje ~4 B/piksel);
    // web tyle nie potrzebuje. php.ini nie umie wartości domyślnych,
    // więc entrypoint podaje to flagą `php -d`.
    PHP_WORKER_MEMORY_LIMIT: "512M",
  };

  // ---------------------------------------------------------------------------
  //  ŹRÓDŁO KODU
  //
  //  Deploy napędza integracja GitHub ↔ Railway:
  //    push do main    → production
  //    push do staging → staging
  //    PR              → środowisko PR (natywne, tworzone i usuwane automatycznie)
  //
  //  checkSuites: true = "Wait for CI" — Railway czeka na zielone check suites
  //  z GitHuba przed zbudowaniem deployu. Bez tego push do main wypuszcza kod
  //  z czerwonymi testami.
  //  [do weryfikacji] pole `checkSuites` istnieje w typie ServiceSource w SDK
  //  3.11.0, ale nie jest opisane w publicznej dokumentacji. Po pierwszym
  //  `railway config apply` potwierdź w panelu, że przełącznik "Wait for CI"
  //  jest włączony; jeśli nie — kliknij go ręcznie.
  //
  //  UWAGA O GAŁĘZI W ŚRODOWISKACH PR:
  //  Sprawdzone eksperymentalnie na railway@3.11.0 — github() ZAWSZE ustawia
  //  branch, domyślnie "main". Nie da się go pominąć z poziomu DSL. Nie jest
  //  to problem: w środowiskach PR gałęzią zarządza sam Railway (podmienia ją
  //  na gałąź pull requesta przy tworzeniu środowiska), więc wartość
  //  zadeklarowana tutaj jest wtedy ignorowana. Dla jasności ustawiamy
  //  dla preview tę samą gałąź co produkcja i opisujemy to w komentarzu,
  //  zamiast udawać, że pole jest opcjonalne.
  // ---------------------------------------------------------------------------
  //  UWAGA (wrzesień 2026): przy `checkSuites: true` Railway czeka na check
  //  suite. Jeśli CI nie produkuje check suite dla danej gałęzi, Railway czeka
  //  w nieskończoność — i NIC BY SIĘ NIE ZDEPLOYOWAŁO. Dlatego bramka jest
  //  sterowana zmienną środowiskową i domyślnie wyłączona.
  //
  //  Stan: wyzwalacze CI są już włączone w .github/workflows/ci.yml (D-010),
  //  ale `main` czeka jeszcze na pierwszy zielony przebieg.
  //
  //  Włącz bramkę (`KUKING_WAIT_FOR_CI=true`) DOPIERO PO pierwszym zielonym
  //  przebiegu CI na gałęzi domyślnej — nie wcześniej. Kolejność jest częścią
  //  decyzji D-010, nie preferencją.
  //  Do tego czasu bramką jakości jest lokalny hook pre-push
  //  (scripts/install-hooks.sh). Szczegóły: docs/infra/CI_BEZ_ACTIONS.md
  const czekajNaCI = process.env.KUKING_WAIT_FOR_CI === "true";

  const source = github(REPO, {
    branch: isStaging ? "staging" : "main",
    checkSuites: czekajNaCI,
  });

  // ---------------------------------------------------------------------------
  //  BUILD — własny Dockerfile, nie Railpack
  //
  //  builder: "DOCKERFILE" deklaruje to jawnie. Railway i tak sam wykrywa
  //  Dockerfile w katalogu głównym, ale jawna deklaracja chroni przed cichym
  //  przełączeniem na Railpack, gdyby ktoś kiedyś zmienił nazwę pliku.
  //  Uzasadnienie wyboru Dockerfile vs Railpack: patrz nagłówek Dockerfile
  //  i INFRA_DECISION.md.
  //
  //  watchPatterns: przebudowuj tylko, gdy zmieniło się coś, co wpływa na
  //  obraz. Zmiana README albo docs/ nie musi kosztować buildu (a build
  //  kosztuje minuty i pieniądze). Wymagane też przez Focused PR Environments.
  // ---------------------------------------------------------------------------
  const build = {
    builder: "DOCKERFILE" as const,
    dockerfilePath: "Dockerfile",
    watchPatterns: [
      "app/**",
      "bootstrap/**",
      "config/**",
      "database/**",
      "public/**",
      "resources/**",
      "routes/**",
      "docker/**",
      "Dockerfile",
      "composer.json",
      "composer.lock",
      "package.json",
      "package-lock.json",
      "vite.config.js",
      "artisan",
    ],
  };

  // ===========================================================================
  //  SERWIS: web
  // ===========================================================================
  const web = service("web", {
    source,
    build,

    deploy: {
      // -----------------------------------------------------------------------
      //  ROLA MUSI PODĄŻAĆ ZA `splitServices`.
      //
      //  Entrypoint wybiera rolę tak: ROLE="${1:-${APP_ROLE:-web}}" — czyli
      //  ARGUMENT WYGRYWA z APP_ROLE. Sprawdzone na samym skrypcie:
      //
      //      startCommand "...web" + APP_ROLE=all  →  ROLE=web
      //      brak argumentu       + APP_ROLE=all  →  ROLE=all
      //
      //  Przy `splitServices=false` (staging, preview i produkcja w fazie alfa)
      //  istnieje TYLKO ten jeden serwis, a APP_ROLE ustawiamy niżej na "all".
      //  Zahardkodowane "web" nadpisywało to i kontener startował bez kolejki
      //  i bez harmonogramu. Nic przy tym nie wybuchało — healthcheck zdaje,
      //  strona działa. Ciche skutki:
      //
      //    * zdjęcia zostają na zawsze w stanie PENDING (warianty generuje
      //      zadanie w kolejce), więc główna akcja serwisu nie kończy się;
      //    * `kuking:zdejmij-wygasle-kary` nigdy nie chodzi, więc kara
      //      „na 7 dni" staje się dożywotnia (#40);
      //    * eksporty danych RODO nigdy nie powstają.
      // -----------------------------------------------------------------------
      startCommand: splitServices
        ? "/usr/local/bin/kuking-entrypoint web"
        : "/usr/local/bin/kuking-entrypoint all",

      // -----------------------------------------------------------------------
      //  MIGRACJE — faza RELEASE, nie BUILD.
      //
      //  Dlaczego pre-deploy, a nie build:
      //    * build jest bezstanowy, uruchamiany też dla PR-ów, bez dostępu do
      //      produkcyjnej bazy ani do prywatnej sieci;
      //    * pre-deploy MA dostęp do zmiennych serwisu i do prywatnej sieci;
      //    * niezerowy exit code pre-deploy ZATRZYMUJE deploy — zła migracja
      //      nie wypuści kodu, który jej wymaga;
      //    * pre-deploy leci RAZ na deploy, a nie raz na replikę (start command
      //      przy 2 replikach odpaliłby migracje równolegle → wyścig o blokady).
      //  https://docs.railway.com/deployments/pre-deploy-command
      //
      //  --force jest konieczne: przy APP_ENV=production artisan domyślnie pyta
      //  o potwierdzenie, a w kontenerze nie ma kto odpowiedzieć — komenda
      //  zawisłaby do timeoutu.
      //
      //  Pre-deploy działa w OSOBNYM kontenerze bez zamontowanych volume'ów —
      //  dla nas bez znaczenia, bo volume'ów nie używamy (zdjęcia w R2).
      // -----------------------------------------------------------------------
      preDeployCommand: ["php artisan migrate --force --no-interaction"],

      // -----------------------------------------------------------------------
      //  HEALTHCHECK — warunek zero-downtime deploy.
      //  Railway odpytuje /health aż dostanie 2xx i DOPIERO WTEDY przełącza
      //  ruch na nowy deploy. Trasa musi sprawdzać połączenie z bazą;
      //  healthcheck zwracający zawsze 200 nie chroni przed niczym.
      //
      //  Requesty idą z hosta healthcheck.railway.app — jeśli włączysz
      //  middleware TrustHosts, MUSISZ dopisać ten host, inaczej deploy będzie
      //  padał na 400.
      //  https://docs.railway.com/deployments/healthchecks
      // -----------------------------------------------------------------------
      healthcheckPath: "/health",
      healthcheckTimeout: isProduction ? 180 : 300,

      // -----------------------------------------------------------------------
      //  Region i repliki.
      //  1 replika na start. Druga ma sens, gdy p95 czasu odpowiedzi rośnie
      //  pod obciążeniem — nie "na zapas", bo płacisz za RAM i CPU liniowo.
      // -----------------------------------------------------------------------
      region: REGION,
      numReplicas: 1,

      // -----------------------------------------------------------------------
      //  Restart policy. ON_FAILURE = restart tylko po błędzie (domyślne).
      //  10 prób to maksimum dostępne również na planach darmowych.
      //  https://docs.railway.com/deployments/restart-policy
      // -----------------------------------------------------------------------
      restartPolicyType: "ON_FAILURE",
      restartPolicyMaxRetries: 10,

      // -----------------------------------------------------------------------
      //  Graceful shutdown. Railway wysyła SIGTERM i czeka `drainingSeconds`,
      //  zanim ubije kontener. Caddy w tym czasie dokańcza otwarte requesty.
      //  30 s z zapasem pokrywa najdłuższy sensowny request HTTP.
      // -----------------------------------------------------------------------
      drainingSeconds: 30,

      // -----------------------------------------------------------------------
      //  Serverless (dawniej App Sleeping): usypia serwis po ~5-10 min bez
      //  ruchu wychodzącego.
      //    produkcja → WYŁĄCZONE. Pierwszy request do uśpionego serwisu może
      //                zwrócić 502 i ma cold start. Dla audytorium 50+ to
      //                znaczy "strona nie działa".
      //    staging/preview → WŁĄCZONE. Tu 502 nikogo nie boli, a rachunek
      //                spada praktycznie do zera między sesjami testowymi.
      //  UWAGA: ustawienie działa od NASTĘPNEGO deployu, nie natychmiast.
      //  https://docs.railway.com/deployments/serverless
      // -----------------------------------------------------------------------
      sleepApplication: !isProduction,

      // -----------------------------------------------------------------------
      //  Twardy limit zasobów — bezpiecznik przeciw rachunkowi-niespodziance.
      //  Railway rozlicza faktyczne zużycie ($10/GB RAM/mies., $20/vCPU/mies.),
      //  więc wyciek pamięci w pętli potrafi zaskoczyć na fakturze.
      //  Przekroczenie limitu = OOM kill = restart widoczny w logach
      //  ("out of memory"), a nie cicha eskalacja kosztu.
      // -----------------------------------------------------------------------
      limitOverride: {
        containers: {
          memoryBytes: (isProduction ? 1024 : 768) * MB,
          cpu: isProduction ? 2 : 1,
        },
      },
    },

    // Domeny custom tylko dla trwałych środowisk. Środowiska PR dostają
    // automatycznie domenę *.up.railway.app — pod warunkiem, że serwis
    // w środowisku BAZOWYM ma wygenerowaną domenę Railway.
    // https://docs.railway.com/environments#domains-in-pr-environments
    domains: isProduction ? PROD_DOMAINS : isStaging ? STAGING_DOMAINS : [],

    env: {
      ...appEnv,
      // Rola "all" = web + worker + scheduler w jednym kontenerze.
      // Oszczędza ~2/3 kosztu (płacisz per serwis za RAM i CPU), za cenę
      // izolacji awarii. Na staging/preview zawsze; na produkcji tylko
      // w fazie alfy (PRODUCTION_SPLIT_SERVICES = false).
      APP_ROLE: splitServices ? "web" : "all",
    },
  });

  // ===========================================================================
  //  SERWIS: worker  (tylko produkcja)
  //
  //  KIEDY WYDZIELAĆ WORKERA OSOBNO?
  //  Od pierwszego dnia produkcji, bo:
  //    1. ProcessUploadedImage jest CPU-bound (dekodowanie i skalowanie zdjęć
  //       24 Mpx). W jednym kontenerze z web kradłby CPU requestom
  //       użytkowników — przy audytorium 50+ każde 500 ms boli podwójnie.
  //    2. web i worker skalują się w PRZECIWNYCH momentach: ruch rośnie
  //       wieczorami, kolejka zdjęć po weekendowym gotowaniu.
  //    3. Worker może paść na OOM przy patologicznym pliku. Razem z web
  //       zabrałby ze sobą całą stronę.
  //    4. Deploy web nie może przerywać przetwarzania zdjęć w połowie.
  //
  //  Na staging/preview NIE wydzielamy — tam liczy się rachunek, nie SLA.
  // ===========================================================================
  const worker = service("worker", {
    source,
    build,

    deploy: {
      startCommand: "/usr/local/bin/kuking-entrypoint worker",

      // Brak healthchecku: to nie serwer HTTP, nie ma czego odpytywać.
      // Zdrowie workera monitorujemy inaczej — alert na rosnącą liczbę
      // rekordów w tabelach `jobs` i `failed_jobs` (INFRA_DECISION.md).

      // Brak preDeployCommand: migracje uruchamia WYŁĄCZNIE serwis web.
      // Trzy serwisy migrujące równolegle to wyścig o blokady w Postgresie.

      region: REGION,
      numReplicas: 1,

      restartPolicyType: "ON_FAILURE",
      restartPolicyMaxRetries: 10,

      // Worker dostaje DŁUGIE okno na zamknięcie: `queue:work` reaguje na
      // SIGTERM dokańczając bieżący job (dzięki rozszerzeniu pcntl). 120 s
      // wystarcza na przetworzenie nawet dużego zdjęcia, więc deploy nie
      // porzuca zadania w połowie.
      drainingSeconds: 120,

      // Worker NIE MOŻE być usypiany — kolejka musi być odbierana ciągle,
      // a Serverless usypia po braku ruchu wychodzącego.
      sleepApplication: false,

      limitOverride: {
        containers: {
          // Więcej RAM niż web. Liczby są ZMIERZONE (szczyt RSS procesu przy
          // przetworzeniu jednego zdjęcia wraz z trzema wariantami, gd, PHP 8.4):
          //     12 Mpx → 161 MB      24 Mpx → 254 MB      50 Mpx → 452 MB
          // 50 Mpx to limit z `config/kuking.php`, czyli najgorszy dozwolony
          // przypadek. 1024 MB zostawia nad nim ponad dwukrotny zapas.
          //
          // Wcześniej stało tu „24 Mpx to ~100 MB" — pomiar pokazał 254 MB.
          // Zapas był więc liczony od liczby wziętej z szacunku 4 bajtów
          // na piksel, który pomija bufory pośrednie przy skalowaniu.
          memoryBytes: 1024 * MB,
          cpu: 2,
        },
      },
    },

    env: { ...appEnv, APP_ROLE: "worker" },
  });

  // ===========================================================================
  //  SERWIS: scheduler  (tylko produkcja)
  //
  //  Uruchamia `php artisan schedule:work` — długożyjący proces wywołujący
  //  schedule:run co minutę.
  //
  //  DLACZEGO NIE Railway Cron (mimo że DSL ma pole deploy.cronSchedule):
  //    Railway Cron ma minimalną granulację 5 MINUT i nie gwarantuje
  //    dokładności co do minuty, a scheduler Laravela musi być odpytywany
  //    CO MINUTĘ, żeby everyMinute() i everyFiveMinutes() zachowywały się
  //    zgodnie z definicją.
  //    https://docs.railway.com/cron-jobs (sekcje "Frequency" i "FAQ")
  //
  //  DOKŁADNIE 1 REPLIKA. Dwie repliki = dwa razy ten sam digest w skrzynce
  //  użytkownika. Gdybyś kiedyś musiał mieć więcej, dopisz do zadań
  //  ->onOneServer() (wymaga cache z blokadami atomowymi, czyli Redisa).
  // ===========================================================================
  const scheduler = service("scheduler", {
    source,
    build,

    deploy: {
      startCommand: "/usr/local/bin/kuking-entrypoint scheduler",

      region: REGION,
      numReplicas: 1, // NIE ZMIENIAĆ. Patrz komentarz powyżej.

      // ALWAYS, nie ON_FAILURE: scheduler ma chodzić zawsze. Gdyby wyszedł
      // z kodem 0 (np. po `schedule:work` zamkniętym sygnałem), ON_FAILURE
      // by go nie wskrzesił i zadania cykliczne po cichu przestałyby działać.
      // UWAGA: ALWAYS wymaga planu płatnego (na Free jest niedostępne).
      // https://docs.railway.com/deployments/restart-policy
      restartPolicyType: "ALWAYS",
      restartPolicyMaxRetries: 10,

      drainingSeconds: 30,
      sleepApplication: false,

      limitOverride: {
        containers: {
          // Scheduler tylko wywołuje komendy — same zadania robi worker.
          memoryBytes: 512 * MB,
          cpu: 1,
        },
      },
    },

    env: { ...appEnv, APP_ROLE: "scheduler" },
  });

  // ---------------------------------------------------------------------------
  //  KOMPOZYCJA
  //  Grupy są wyłącznie porządkowe — czytelność kanwy Railway i tego pliku.
  //  https://docs.railway.com/infrastructure-as-code/reference#groups
  // ---------------------------------------------------------------------------
  //  Serwisy worker i scheduler powstają WYŁĄCZNIE przy rozdzielonej
  //  topologii produkcyjnej. W pozostałych przypadkach ich zadania wykonuje
  //  serwis `web` w trybie APP_ROLE=all, a same serwisy nie są deklarowane —
  //  czyli `railway config apply` je USUNIE, jeśli istniały.
  const appServices = splitServices ? [web, worker, scheduler] : [web];

  const resources = [group("Aplikacja", appServices), group("Dane", [db])];

  return project("kuking", { resources });
});

// =============================================================================
//  CZEGO TEN PLIK NADAL NIE OBEJMUJE — ustaw ręcznie w panelu
//  (checklista z dokładnymi kliknięciami: DEPLOYMENT_RUNBOOK.md, krok 12)
//
//   1. PR Environments — Project Settings → Environments → Enable.
//      Środowiskiem BAZOWYM ustaw `staging`, nie `production`, inaczej każdy
//      PR dostanie kopię produkcyjnych sekretów.
//   2. Backupy Postgresa — serwis Postgres → Backups → Daily + Weekly,
//      oraz Enable PITR. PITR liczy okno od PIERWSZEGO backupu po włączeniu,
//      więc włącz to PRZED tym, jak będzie potrzebne.
//      https://docs.railway.com/guides/postgres-backups-restores
//   3. Shared variables — wartości sekretów (ten plik je tylko referencuje).
//   4. Alerty budżetowe — Workspace → Usage → Usage Limits.
//
//  Uzupełniające: jeśli `railway config plan` odrzuci którekolwiek pole
//  z bloków `deploy`/`build`/`source` (są w typach SDK 3.11.0, ale nie
//  wszystkie w publicznej dokumentacji), usuń je z pliku i ustaw ręcznie
//  w panelu — reszta konfiguracji zadziała bez zmian.
// =============================================================================
