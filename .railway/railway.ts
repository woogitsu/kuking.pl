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
//  W CI (workflows/railway-iac.yml): PR → sam plan (komentarz w PR);
//  apply WYŁĄCZNIE ręcznie (Run workflow na `main`, wpisane potwierdzenie,
//  zmiany destrukcyjne domyślnie zablokowane). Do 24.09.2026 apply szło
//  samo po scaleniu PR-a, z --confirm-destructive — patrz nagłówek workflow.
//  Przełączenie na trzy serwisy: docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md
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
 * NAZWY ISTNIEJĄCYCH ZASOBÓW RAILWAY — tożsamość, nie etykieta (#595).
 *
 * `railway config plan` porównuje ten plik z ŻYWYM środowiskiem po nazwie
 * zasobu; nie ma pliku stanu. Do 24.09.2026 stały tu nazwy `web` i
 * `postgres`, a produkcja (zmierzone 9.09.2026, projekt `ideal-exploration`)
 * ma serwisy `kuking.pl` i `Postgres`. Pierwszy `apply` z tamtymi nazwami:
 *
 *   * UTWORZYŁBY nowy serwis `web` z domenami kuking.pl — zajętymi przez
 *     istniejący serwis — i zaproponował USUNIĘCIE `kuking.pl`;
 *   * UTWORZYŁBY NOWĄ, PUSTĄ bazę `postgres`, przepiął na nią DB_URL
 *     wszystkich serwisów i zaproponował USUNIĘCIE bazy z danymi.
 *
 * Z nazwami zgodnymi z żywym środowiskiem plan ma ZMIENIĆ istniejący
 * serwis w miejscu (start `web` zamiast `all`) i DOPISAĆ `worker`
 * i `scheduler`. Cokolwiek innego w planie — patrz
 * docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md, krok „Czytanie planu”.
 *
 * Zmienna TypeScriptu dalej nazywa się `web` — to rola, nie nazwa w Railway.
 * `NAZWA_SERWISU_WWW` musi się zgadzać z `APP_SERVICE` w
 * `.github/workflows/deploy.yml`; pilnuje tego scripts/railway/iac.test.mjs.
 * Wielkość liter ma znaczenie (`${{Postgres.DATABASE_URL}}`).
 */
const NAZWA_SERWISU_WWW = "kuking.pl";
const NAZWA_BAZY = "Postgres";

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
 *
 * ⚠️ SPROSTOWANIE / STAN FAKTYCZNY (zmierzone connectorem Railway,
 * 9 września 2026): ten plik opisuje stan DOCELOWY, nie stan obowiązujący.
 * Flaga niżej jest `true`, ale `railway config apply` NIE ZOSTAŁO JESZCZE
 * ANI RAZU URUCHOMIONE na tym projekcie. Produkcja dziś to JEDEN serwis,
 * nazwany `kuking.pl` (nie `web`), uruchamiany komendą
 * `/usr/local/bin/kuking-entrypoint all` — dokładnie topologia opisana
 * wyżej dla `false`, mimo że flaga stoi na `true`. Serwisów `worker`
 * i `scheduler` z tego pliku w Railway nie ma; serwis WWW nosi w pliku
 * nazwę żywego serwisu (`NAZWA_SERWISU_WWW`, od 24.09.2026). NIE zmieniaj tej flagi w ramach samego sprostowania
 * dokumentacji — rozbicie na trzy serwisy zostaje celem, dopóki właściciel
 * nie zdecyduje inaczej; zmienia się tylko to, co ten komentarz mówi o dziś.
 */
const PRODUCTION_SPLIT_SERVICES = true;

/**
 * OKNO ZAMKNIĘCIA KONTENERA, W KTÓRYM CHODZI `queue:work` (role `worker`
 * i `all`), w sekundach (audyt B8-03).
 *
 * 130 = `ProcessUploadedImage::$timeout` (120 s, najdłuższe zadanie, na które
 * ktoś czeka na ekranie) + 10 s na zamknięcie procesu. Wcześniej worker miał
 * 120 (równo z limitem, bez zapasu), a rola `all` — 30.
 *
 * CZEGO TO NIE POKRYWA: `GenerateUserExport` ma 900 s. Zadanie ubite przy
 * wdrożeniu wraca po `retry_after` (`config/queue.php`, 960 s) — paczkę
 * i tak dostaje się e-mailem, więc kwadrans opóźnienia jest do przyjęcia,
 * a kontener, który przy każdym z kilkudziesięciu deployów na dobę czeka
 * kwadrans na zamknięcie, nie jest. Krótszy `retry_after` dla kolejek bez
 * eksportu wymaga osobnego połączenia i osobnego procesu `queue:work`
 * (worker czyta jedno połączenie) — patrz #1030.
 *
 * Pilnuje `scripts/railway/iac.test.mjs` (limit czytany z kodu zadania).
 */
const ZAMKNIECIE_Z_KOLEJKA_S = 130;

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
  // Poza produkcją nie rozdzielamy — staging i preview chodzą jako jeden
  // kontener w trybie APP_ROLE=all (wyjątek na próbę: niżej).
  //
  //  WYJĄTEK NA PRÓBĘ (#595): `KUKING_IAC_STAGING_ROZBITY=true` przy
  //  `railway config plan/apply` na stagingu rozbija TAKŻE staging na trzy
  //  serwisy — jedyny sposób, żeby przećwiczyć przełączenie gdzie indziej
  //  niż na produkcji. Bez zmiennej staging zostaje jednym kontenerem `all`.
  //  Czytane tak samo jak KUKING_WAIT_FOR_CI niżej: ze środowiska procesu,
  //  który wykonuje ten plik, nie ze zmiennych Railwaya.
  const splitServices = isProduction
    ? PRODUCTION_SPLIT_SERVICES
    : isStaging && process.env.KUKING_IAC_STAGING_ROZBITY === "true";

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
  const db = postgres(NAZWA_BAZY, { region: REGION });

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
    // Poprzednie klucze szyfrowania, rozdzielone przecinkami — OPCJONALNE.
    // Potrzebne wyłącznie na czas rotacji APP_KEY (PR #1437): nowy klucz
    // szyfruje, stare jeszcze odszyfrowują sesje, ciasteczka i zadania
    // w kolejce (`config/app.php`, `previous_keys`). Poza rotacją — puste.
    // Dostają je wszystkie trzy role, bo każda odszyfrowuje: web sesje,
    // worker ładunki zadań, scheduler zaszyfrowany cache.
    APP_PREVIOUS_KEYS: ctx.shared.APP_PREVIOUS_KEYS,
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

    // --- Storage zdjęć: Cloudflare R2, TRZY BUCKETY --------------------------
    //
    //  To jest granica bezpieczeństwa, a nie porządki (audyt G-01).
    //
    //  Nagłówek mówił „DWA BUCKETY", a blok niżej ustawiał trzy — czwarty
    //  (kopie bazy) dochodzi kilkadziesiąt linijek dalej, z własnym
    //  poświadczeniem. Na tym rozjeździe stanął runbook, który kazał utworzyć
    //  JEDEN bucket, i `docs/infra/BRAMKA_R2.md`, który wymieniał DWA pod
    //  innymi nazwami. Źródłem prawdy jest `config/filesystems.php`: czyta
    //  AWS_BUCKET, AWS_PUBLIC_BUCKET, AWS_EXPORTS_BUCKET, AWS_LEGACY_BUCKET
    //  i AWS_KOPIE_BUCKET. Zmienne `R2_*` po prawej stronie to nazwy
    //  sharedowe w panelu Railway — tylko ten plik je mapuje.
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
    //    R2_PUBLIC_BUCKET  przetworzone warianty WebP (`media/`). Po W7-02
    //                      i D-020 TEŻ bez własnej domeny: adresem zdjęcia
    //                      jest trasa `/zdjecia/{media}/{wariant}`, która
    //                      pyta Policy. (Stało tu „TEN i tylko ten ma
    //                      cdn.kuking.pl" — nieaktualne od 6 IX 2026.)
    //    R2_EXPORTS_BUCKET paczki RODO. Prywatny, patrz niżej.
    //
    //  Kod domenowy używa WYŁĄCZNIE Laravel Filesystem, więc zmiana dostawcy
    //  to zmiana zmiennych, nie przepisywanie domeny (docs/MEDIA_PIPELINE.md).
    FILESYSTEM_DISK: "r2",
    // Surowe uploady kreatora muszą być dostępne między replikami.
    // Prywatny bucket oryginałów: pliki tymczasowe mogą zawierać EXIF/GPS.
    LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK: "r2",
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
    // --- Bucket kopii bazy: poświadczenie odczytu ŻYJE W `kopieOdczytEnv` ---
    //  niżej i trafia WYŁĄCZNIE do schedulera (#1013), bo tylko on wykonuje
    //  `kuking:sprawdz-kopie`.
    AWS_ENDPOINT: ctx.shared.R2_ENDPOINT, // https://<ACCOUNT_ID>.eu.r2.cloudflarestorage.com — jedyny dozwolony kształt (D-255)
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
    //  SMTP NIE DZIAŁA NA TYM PLANIE I NIE ZADZIAŁA (zmierzone 9 IX 2026).
    //
    //  Dokumentacja Railwaya mówi wprost: „SMTP is only available on the Pro
    //  plan and above. Free, Trial, and Hobby plans must use transactional
    //  email services with HTTPS APIs. SMTP is disabled on these plans to
    //  prevent spam and abuse." Właściciel jest na Free i przechodzi na Hobby
    //  — obie blokują SMTP.
    //
    //  Objaw jest gorszy niż błąd: pakiety idą w próżnię, więc zadanie
    //  `App\Notifications\UstawienieNowegoHasla` wchodzi w `RUNNING` i NIGDY
    //  się nie kończy — ani `DONE`, ani `FAIL`. W panelu wygląda to jak
    //  zawieszony worker, nie jak awaria poczty.
    //
    //  Dlatego wysyłamy przez API HTTPS EmailLabs (`docs/DECISIONS.md` D-047).
    //  Sterownik `emaillabs` jest własny (`App\Poczta\TransportEmailLabs`),
    //  zarejestrowany w `App\Providers\PocztaServiceProvider`, bez ani jednej
    //  nowej paczki Composera.
    //
    //  Po zmianie tych wartości sprawdź, że poczta NAPRAWDĘ wychodzi:
    //      railway ssh -- php artisan kuking:sprawdz-poczte ty@wp.pl
    //  Sterownik `log` przyjmuje wiadomość i zgłasza sukces, nie wysyłając
    //  jej nikomu — dlatego nie wolno go tu wpisać „na chwilę".
    MAIL_MAILER: "emaillabs",

    //  Klucze EmailLabs (i uśpione SMTP) żyją w `pocztaEnv` niżej: dostają je
    //  web, worker I scheduler (#1013). Scheduler też, choć list tylko
    //  KOLEJKUJE: `kuking:wyslij-podsumowania` woła `Mail::to()->queue()`,
    //  a `Mail::to()` buduje transport od razu — bez kluczy digest padnie
    //  na `BrakKonfiguracjiEmailLabs`.
    // UWAGA: Symfony przyjmuje TYLKO `smtp` i `smtps`. Stało tu `tls` —
    // wygląda sensownie, opisuje prawdziwą intencję (STARTTLS na 587)
    // i NIE DZIAŁA: transport się nie buduje, a każdy list kończy się
    // `UnsupportedSchemeException` w kolejce, przy poprawnym dostawcy
    // i poprawnym haśle. Na porcie 587 STARTTLS negocjuje się samo, więc
    // właściwą wartością jest `smtp`. `smtps` jest dla portu 465.
    // Pilnuje tego `SchematPocztyJestObslugiwanyTest` — i dlatego ta zmienna
    // zostaje tu nawet uśpiona: gdyby zniknęła, zniknąłby razem z nią jedyny
    // strażnik wartości, którą ktoś kiedyś wpisze z powrotem.
    MAIL_SCHEME: "smtp",

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
    // SENTRY_* i POSTHOG_* USUNIĘTE, A NIE PRZENIESIONE (#1013). Żadna linijka
    // w `config/` ich nie czyta: Sentry'ego i PostHoga nie ma w `composer.json`
    // (D-041, D-104). Rozsyłanie nieczytanego sekretu do trzech procesów
    // poszerza powierzchnię ataku i niczego nie włącza. Gdy integracja
    // powstanie, jej zmienne dopisuje się do roli, która ją wykonuje.

    // Powiadomienie o błędzie 500 na Slacku/Discordzie, dopóki nie da się
    // zainstalować Sentry wyżej (`docs/infra/MONITORING_BLEDOW.md`,
    // `config/logging.php` kanał `blad_webhook`). Puste = wyłączone — Railway
    // wstawi tu pusty string, dopóki właściciel nie założy tej zmiennej
    // sharedowej w panelu (Environment → Variables → Shared Variables).
    // Treść wysyłana na ten adres nie niesie danych osobowych, ale sam adres
    // to sekret (kto go zna, może pisać na kanał właściciela) — dlatego
    // idzie przez `ctx.shared`, tak jak klucze niżej, a nie jako
    // wartość wpisana w tym pliku.
    LOG_BLAD_WEBHOOK_URL: ctx.shared.LOG_BLAD_WEBHOOK_URL,

    // --- Turnstile, Google, Facebook, analityka odwiedzin -------------------
    // Żyją w `wejscieEnv` niżej i trafiają WYŁĄCZNIE do web (#1013): czyta je
    // tylko warstwa HTTP (formularze, trasy logowania, HTML strony, `/health`).

    // --- Runtime kontenera ----------------------------------------------------
    // Worker dekoduje zdjęcia do 24 Mpx (gd potrzebuje ~4 B/piksel);
    // web tyle nie potrzebuje. php.ini nie umie wartości domyślnych,
    // więc entrypoint podaje to flagą `php -d`.
    PHP_WORKER_MEMORY_LIMIT: "512M",
  };

  // ---------------------------------------------------------------------------
  //  ZESTAWY PER ROLA (#1013, #1014)
  //
  //  `appEnv` wyżej to WYŁĄCZNIE to, czego potrzebują wszystkie trzy role
  //  aplikacji: niesekretny rdzeń plus APP_KEY, baza, buckety zdjęć i paczek
  //  RODO (web wgrywa i podpisuje adresy, worker przetwarza i buduje paczki,
  //  scheduler sprząta osierocone zdjęcia, wygasłe paczki i skasowane konta)
  //  oraz adres kanału błędów 500 (błąd może paść w każdej roli).
  //
  //  Wszystko inne dostaje TYLKO rola, która to czyta. Railway nie przekazuje
  //  Shared Variable procesowi samo z siebie — referencję tworzy blok `env`
  //  konkretnej usługi (https://docs.railway.com/variables). Wspólny zestaw
  //  dla wszystkich trzech ról znaczył, że przejęcie workera dekodującego
  //  nieufne zdjęcia dawało sekret OAuth i Turnstile, a przejęcie schedulera —
  //  sekret OAuth, Turnstile i token czyszczenia CDN.
  //
  //  Macierz „zmienna → rola → który kod ją czyta" pilnuje test
  //  `ZmienneRailwayaPerRolaTest`. Dopisując tu zmienną, dopisz ją też tam —
  //  inaczej test zapali, i o to chodzi.
  //
  //  Rola `all` (staging, preview, dzisiejsza produkcja) robi wszystko naraz,
  //  więc dostaje sumę trzech zestawów.
  // ---------------------------------------------------------------------------

  //  --- Poczta: web + worker + scheduler -------------------------------------
  //  web wysyła SYNCHRONICZNIE odpowiedź na „Napisz do nas"
  //  (`WyslijOdpowiedz`, świadomie bez kolejki) i sprawdza gotowość poczty
  //  w `/health` oraz w formularzach (`App\Support\Poczta`); worker wysyła
  //  wszystkie listy z kolejki i paczkę RODO (`GenerateUserExport`).
  //
  //  Scheduler TEŻ BUDUJE TRANSPORT, choć sam niczego nie wysyła. Digest
  //  (`kuking:wyslij-podsumowania`, `Schedule::call()` = ten sam proces)
  //  woła `Mail::to(...)->queue($list)`, a `Mail::to()` najpierw rozwiązuje
  //  mailer domyślny — `MailManager::resolve()` buduje transport od razu,
  //  a `PocztaServiceProvider::transport()` przy pustym EMAILLABS_APP_KEY
  //  rzuca `BrakKonfiguracjiEmailLabs`. Bez kluczy digest nie wyszedłby
  //  wcale, a pierwszy odbiorca straciłby tydzień: jego wiersz
  //  w `weekly_digest_sends` jest zajmowany PRZED `Mail::queue()`.
  //  Powiadomienia `ShouldQueue` (`Notification::route()->notify()`) mailera
  //  przy kolejkowaniu nie budują — ale digest wystarcza. Pilnuje tego
  //  `ZmienneRailwayaPerRolaTest::harmonogram_budujacy_mailer_ma_klucze_poczty`.
  //
  //  SMTP (uśpione, niżej) idzie razem z kluczami EmailLabs: przestawienie
  //  `MAIL_MAILER` na `smtp` albo `failover` musi zadziałać w każdej roli,
  //  która buduje transport, bez drugiej zmiany w tym pliku.
  const pocztaEnv = {
    //  DWA KLUCZE, NIE JEDEN: żądanie niesie nagłówek `Application-Key`
    //  (EMAILLABS_APP_KEY) i `Authorization` (EMAILLABS_SECRET_KEY, 128
    //  znaków). Oba generuje się RAZEM w panelu EmailLabs:
    //  Konto → Ustawienia → API → „Generuj klucz API". Po przeładowaniu
    //  strony klucza autoryzacyjnego nie da się już podejrzeć.
    //
    //  TO NIE SĄ LOGIN I HASŁO SMTP. Dane z sekcji „Konta SMTP" panelu służą
    //  wyłącznie do wysyłki portem 587; API odpowie na nie 401. Pomylenie
    //  jednego z drugim jest tu najbardziej prawdopodobnym błędem
    //  konfiguracji.
    EMAILLABS_APP_KEY: ctx.shared.EMAILLABS_APP_KEY,
    EMAILLABS_SECRET_KEY: ctx.shared.EMAILLABS_SECRET_KEY,
    //  Konto wysyłkowe w kształcie `1.nazwa.smtp` — wymagane pole
    //  `smtpAccount` w każdym żądaniu API. Mimo nazwy NIE jest to login SMTP.
    EMAILLABS_SMTP_ACCOUNT: ctx.shared.EMAILLABS_SMTP_ACCOUNT,

    //  --- SMTP: UŚPIONE, NIE USUNIĘTE ---------------------------------------
    //
    //  Te pięć zmiennych nie konfiguruje dziś niczego, bo `MAIL_MAILER` to
    //  `emaillabs`. Zostają świadomie, jako gotowa droga na wypadek przejścia
    //  na plan Pro (wtedy Railway odblokowuje SMTP) i jako drugie ramię
    //  ewentualnego `failover` u innego dostawcy. Przestawienie `MAIL_MAILER`
    //  z powrotem na `smtp` PRZED zmianą planu przywróci awarię z 9 września.
    MAIL_HOST: ctx.shared.MAIL_HOST,
    MAIL_PORT: ctx.shared.MAIL_PORT,
    MAIL_USERNAME: ctx.shared.MAIL_USERNAME,
    MAIL_PASSWORD: ctx.shared.MAIL_PASSWORD,
  };

  //  --- Wejście i ochrona formularzy: TYLKO web -----------------------------
  //  Formularze z Turnstile, trasy OAuth Google i Facebooka (w tym podpis
  //  żądania usunięcia danych od Meta), beacon analityki w HTML-u oraz
  //  `/health`. Żaden job ani komenda harmonogramu tych kluczy nie czyta.
  const wejscieEnv = {
    // --- Cloudflare Turnstile (D-050, issue #217) -----------------------------
    // Sprawdzenie „czy to człowiek" na formularzach publicznych. Oba klucze
    // idą przez `ctx.shared`, bo powstają w panelu Cloudflare i różnią się
    // między środowiskami (widget jest przypięty do domen).
    //
    // PUSTE = TURNSTILE WYŁĄCZONY i nic się nie psuje — ale na produkcji
    // `/health` oddaje wtedy `status: degraded` z powodem
    // `turnstile_bez_kluczy`, żeby brak ochrony nie był niewidoczny.
    //
    // TURNSTILE_SITE_KEY nie jest sekretem (wchodzi do HTML-a widgetu),
    // TURNSTILE_SECRET_KEY jest — w panelu Railway zaznacz „Sealed".
    TURNSTILE_SITE_KEY: ctx.shared.TURNSTILE_SITE_KEY,
    TURNSTILE_SECRET_KEY: ctx.shared.TURNSTILE_SECRET_KEY,

    // --- Token krawędzi Cloudflare (config/proxy.php, App\Support\TokenKrawedzi) ---
    // Nagłówek X-Kuking-Edge-Token od Cloudflare potwierdza, że żądanie przyszło
    // przez krawędź. Czyta go wyłącznie web (żądania HTTP). PUSTE = bramka
    // wyłączona. `_POPRZEDNI` tylko na czas rotacji. Oba sekrety — „Sealed".
    KUKING_EDGE_TOKEN: ctx.shared.KUKING_EDGE_TOKEN,
    KUKING_EDGE_TOKEN_POPRZEDNI: ctx.shared.KUKING_EDGE_TOKEN_POPRZEDNI,

    // --- Wejście kontem Google (D-069, issue #258) ----------------------------
    // Dodatkowa droga wejścia obok hasła i wiadomości z linkiem. Oba klucze
    // idą przez `ctx.shared`, bo powstają w Google Cloud Console i są
    // przypięte do adresów powrotu, czyli różnią się między środowiskami.
    //
    // PUSTE = TEJ DROGI NIE MA i nic się nie psuje: przycisku nie ma na
    // ekranie, hasło i link działają jak dziś. Na produkcji `/health` oddaje
    // wtedy `status: degraded` z powodem `google_bez_kluczy` — tak samo jak
    // przy Turnstile wyżej, żeby nieistniejąca droga wejścia nie wyglądała
    // jak zdrowe wdrożenie. Świadome wyłączenie: KUKING_WEJSCIE_GOOGLE=false
    // (wtedy konfiguracja niczego nie obiecuje i sygnału nie ma).
    //
    // GOOGLE_CLIENT_ID nie jest sekretem (wchodzi do adresu przekierowania),
    // GOOGLE_CLIENT_SECRET jest — w panelu Railway zaznacz „Sealed".
    // Krok po kroku: docs/infra/DEPLOYMENT_RUNBOOK.md, krok 8D.
    GOOGLE_CLIENT_ID: ctx.shared.GOOGLE_CLIENT_ID,
    GOOGLE_CLIENT_SECRET: ctx.shared.GOOGLE_CLIENT_SECRET,

    // --- Wejście kontem Facebooka (D-113, issue #259) -------------------------
    // Trzecia droga wejścia, obok hasła, linku e-mail i Google. Oba klucze
    // idą przez `ctx.shared`, bo powstają w panelu Meta i są przypięte do
    // adresów powrotu, czyli różnią się między środowiskami.
    //
    // BEZ TYCH DWÓCH LINII KLUCZE NIE DOCHODZĄ DO APLIKACJI, choćby stały
    // w Shared Variables — i to była realna luka do 12 września 2026
    // (issue #259): właściciel wykonałby kilkanaście czynności w panelu
    // Meta, a przycisku i tak by nie było, bez żadnej wskazówki dlaczego.
    //
    // PUSTE = TEJ DROGI NIE MA i nic się nie psuje. Tak zachowują się też
    // WSZYSTKIE środowiska preview i to jest poprawne: Meta dopasowuje adres
    // powrotu znak w znak i nie przyjmuje `*`, a adresy `*.up.railway.app`
    // są losowe (FACEBOOK_LOGIN_URUCHOMIENIE.md §4.4). Na produkcji `/health`
    // oddaje wtedy `status: degraded` z powodem `facebook_bez_kluczy`.
    // Świadome wyłączenie: KUKING_WEJSCIE_FACEBOOK=false.
    //
    // FACEBOOK_CLIENT_ID to w panelu Meta **App ID** i nie jest sekretem
    // (wchodzi do adresu przekierowania). FACEBOOK_CLIENT_SECRET to
    // **App Secret** i JEST sekretem — w panelu Railway zaznacz „Sealed".
    // Tym samym sekretem weryfikuje się podpis żądania usunięcia danych od
    // Meta, więc jego wyciek to nie tylko cudze logowanie.
    // Krok po kroku: docs/infra/DEPLOYMENT_RUNBOOK.md, krok 8E
    // (panel Meta w całości: docs/infra/FACEBOOK_LOGIN_URUCHOMIENIE.md).
    FACEBOOK_CLIENT_ID: ctx.shared.FACEBOOK_CLIENT_ID,
    FACEBOOK_CLIENT_SECRET: ctx.shared.FACEBOOK_CLIENT_SECRET,

    // --- Analityka odwiedzin: Cloudflare Web Analytics (D-092) ----------------
    // Statystyka „skąd ludzie przychodzą i które strony oglądają". Token
    // powstaje w panelu Cloudflare (Web Analytics → Add a site → kuking.pl),
    // więc idzie przez `ctx.shared`, a nie jako wartość wpisana w tym pliku.
    //
    // BEZ TEJ LINII TOKEN NIE DOCHODZI DO APLIKACJI, choćby stał w Shared
    // Variables — dokładnie ta sama luka, która przy FACEBOOK_* kosztowała
    // osobne issue (#259). Tu jest gorsza, bo jej skutku NIE WIDAĆ na żadnym
    // ekranie: bez tokenu `AnalitykaCloudflare::wlaczona()` oddaje `false`,
    // w HTML-u nie ma nawet komentarza, strona wygląda normalnie, a panel
    // Cloudflare świeci zerami.
    //
    // TOKEN NIE JEST SEKRETEM — stoi w HTML-u każdej strony w atrybucie
    // `data-cf-beacon` i tak ma być; nie daje dostępu do panelu ani do
    // danych. W Railwayu NIE zaznaczaj „Sealed" (zaznaczenie nic nie zepsuje,
    // ale sugerowałoby, że wyciek tej wartości jest incydentem — nie jest).
    //
    // PUSTE = ANALITYKI NIE MA i nic się nie psuje. Na produkcji `/health`
    // oddaje wtedy `status: degraded` z powodem `analityka_bez_tokenu`, ale
    // TYLKO dopóki polityka prywatności obiecuje tę analitykę czytelnikom —
    // bo wtedy dokument prawny opisuje przetwarzanie, którego nie ma.
    // Świadome wycofanie analityki to wykreślenie obietnicy z polityki,
    // nie przełącznik (`HealthController::sprawdzAnalityke()`).
    //
    // SAM TOKEN NIE WYSTARCZY: w panelu Cloudflare wariant zbierania danych
    // musi obejmować Unię Europejską, inaczej beacon działa, a panel i tak
    // zostaje pusty — nasz ruch jest niemal w całości unijny. Krok po kroku:
    // docs/infra/DEPLOYMENT_RUNBOOK.md, KROK 8F.
    CLOUDFLARE_ANALYTICS_TOKEN: ctx.shared.CLOUDFLARE_ANALYTICS_TOKEN,
  };

  //  --- Czyszczenie cache CDN: worker + web ---------------------------------
  //  Worker wykonuje `App\Jobs\PurgePublicMediaCache` (issue #959). Web
  //  dostaje je WYŁĄCZNIE dlatego, że `/health` na produkcji sprawdza, czy
  //  czyszczenie ma z czym ruszyć (`HealthController::sprawdzCzyszczenieCdn`);
  //  bez nich rozdzielony web meldowałby `degraded` przy poprawnym workerze.
  //  Scheduler tylko KOLEJKUJE ten job (`KasujZdjecie`), więc ich nie dostaje.
  //  Token ma mieć w Cloudflare wyłącznie uprawnienie „Cache Purge" jednej
  //  strefy — w panelu Railway zaznacz „Sealed".
  const czyszczenieCdnEnv = {
    CLOUDFLARE_ZONE_ID: ctx.shared.CLOUDFLARE_ZONE_ID,
    CLOUDFLARE_PURGE_TOKEN: ctx.shared.CLOUDFLARE_PURGE_TOKEN,
  };

  //  --- Moderacja modelem: TYLKO worker (#1014) -----------------------------
  //  Ocenę treści robi wyłącznie job `App\Jobs\PrzeanalizujTresc` przez
  //  `App\Moderacja\KlientOpenAI`, czyli kolejka: worker po rozdzieleniu
  //  usług, `web` w roli `all` przed nim. Web w roli `web` i scheduler modelu
  //  nie wołają. PUSTE = moderacja modelem WYŁĄCZONA bez błędu (świadomy
  //  fail-open, D-055) — dlatego zielony `/health` NIE dowodzi, że działa.
  //  Sprawdzenie: `php artisan kuking:sprawdz-model` w konsoli serwisu
  //  z kolejką (DEPLOYMENT_RUNBOOK.md, KROK 8B). „Sealed".
  //
  //  TYLKO PRODUKCJA (`isProduction ? … : ""`). Środowisko PR powstaje jako
  //  kopia środowiska bazowego, więc `ctx.shared` na preview może znaczyć
  //  wartość PRODUKCYJNĄ — a staging i preview nie mogą wysyłać treści pod
  //  produkcyjnym kluczem (rachunek, limity, rejestr powierzenia). Poza
  //  produkcją funkcja jest JAWNIE wyłączona, niezależnie od panelu.
  //  Pilnuje `ZmienneRailwayaPerRolaTest::klucz_modelu_i_adres_alarmu_tylko_na_produkcji`.
  const modelEnv = {
    OPENAI_MODERATION_KEY: isProduction ? ctx.shared.OPENAI_MODERATION_KEY : "",
  };

  //  --- Adres alarmów moderacji: web + worker + scheduler (#1014) -----------
  //  Czytają go TRZY role:
  //    web       — `AlarmujOPilnymZgloszeniu`, wołany SYNCHRONICZNIE w żądaniu
  //                zgłoszenia od człowieka (`ReportContent`,
  //                `ZglosNielegalnaTresc`, D-236). Adres jest czytany w web,
  //                zanim powiadomienie trafi do kolejki — bez niego pilne
  //                zgłoszenie po cichu przestaje budzić moderatora;
  //    worker    — `AlarmujModeratora` z `PrzeanalizujTresc`;
  //    scheduler — `kuking:podsumowanie-automatu`
  //                i `kuking:pilnuj-terminow-odwolan`.
  //  PUSTE = te listy nie wychodzą, zostaje sama kolejka w panelu (D-055).
  //
  //  TYLKO PRODUKCJA, z tego samego powodu co klucz modelu wyżej: moderator
  //  nie może dostawać alarmów ze stagingu ani z PR-ów i brać ich za
  //  prawdziwe. Adres nie jest sekretem, ale to dana osoby — nie wpisuj go
  //  w tym pliku.
  const alarmModeratoraEnv = {
    KUKING_MODEL_ALARM_EMAIL: isProduction ? ctx.shared.KUKING_MODEL_ALARM_EMAIL : "",
  };

  //  --- Odczyt kopii bazy i zdjęć: TYLKO scheduler ------------------------
  //  Jedynym konsumentem jest `kuking:sprawdz-kopie` (`StanKopiiBazy`),
  //  uruchamiane z harmonogramu. Ani trasa HTTP, ani job tego nie czytają.
  //
  //  Zrzut robi osobny serwis `kopia-bazy` niżej, własnym tokenem z prawem
  //  ZAPISU. Aplikacja dostaje token z prawem WYŁĄCZNIE do odczytu tego
  //  jednego bucketu i używa go do jednej rzeczy: raz na dobę sprawdza,
  //  czy w buckecie leży świeża kopia (`kuking:sprawdz-kopie`).
  //
  //  Po co dwa tokeny do jednego bucketu: gdyby aplikacja miała prawo
  //  zapisu, udany atak na nią mógłby SKASOWAĆ kopie — czyli dokładnie to,
  //  przed czym ta warstwa ma chronić. Kopia, którą da się zniszczyć
  //  z zaatakowanego serwisu, nie jest kopią offsite.
  //
  //  KOPIA ZDJĘĆ (#1497, D-257) — ta sama zasada, osobny bucket i osobny
  //  token: `kuking-zdjecia-kopia` czyta wyłącznie `kuking:sprawdz-kopie-zdjec`
  //  (dysk `r2_kopia_zdjec`), token ma prawo TYLKO do odczytu tego bucketu.
  //  Komendy nie ma w harmonogramie — właściciel uruchamia ją ręcznie po
  //  migawce (`railway ssh`, docs/infra/DR_ZDJEC_R2.md §6). Dostaje ją
  //  scheduler, nie web: proces bez ruchu z internetu, ten sam, który już
  //  trzyma odczyt kopii bazy. Dziś (rola `all`) i tak ląduje w `kuking.pl`.
  //  Bez tych trzech linii zmienne ustawione w panelu nie dochodzą do
  //  procesu po rozdzieleniu usług, a komenda odmawia (#1014).
  const kopieOdczytEnv = {
    AWS_KOPIE_BUCKET: ctx.shared.R2_KOPIE_BUCKET,
    AWS_KOPIE_ACCESS_KEY_ID: ctx.shared.R2_KOPIE_ODCZYT_ACCESS_KEY_ID,
    AWS_KOPIE_SECRET_ACCESS_KEY: ctx.shared.R2_KOPIE_ODCZYT_SECRET_ACCESS_KEY,
    AWS_ZDJECIA_KOPIA_BUCKET: ctx.shared.R2_ZDJECIA_KOPIA_BUCKET,
    AWS_ZDJECIA_KOPIA_ACCESS_KEY_ID: ctx.shared.R2_ZDJECIA_KOPIA_ODCZYT_ACCESS_KEY_ID,
    AWS_ZDJECIA_KOPIA_SECRET_ACCESS_KEY: ctx.shared.R2_ZDJECIA_KOPIA_ODCZYT_SECRET_ACCESS_KEY,
  };

  //  --- Puls harmonogramu: TYLKO scheduler (#599, #1659) --------------------
  //  `kuking:puls-harmonogramu` co 5 minut daje znak życia zewnętrznemu
  //  monitorowi. Chodzi WYŁĄCZNIE z harmonogramu, więc adres dostaje tylko
  //  scheduler. Adres zawiera token monitora — kto go zna, „karmi" monitor
  //  i zagłusza prawdziwą awarię — więc żyje w Shared Variables („Sealed"),
  //  nie w tym pliku. PUSTE = puls wyłączony bez błędu
  //  (docs/infra/MONITORING_599_KROKI.md, B3).
  const pulsHarmonogramuEnv = {
    KUKING_PULS_HARMONOGRAMU_URL: ctx.shared.KUKING_PULS_HARMONOGRAMU_URL,
  };

  //  --- Konto gospodarza: web + scheduler (#1089, #1375) --------------------
  //  `HostUserResolver` czyta UUID gospodarza w web (`ZalozKonto` —
  //  auto-obserwowanie przy rejestracji, także przez Google i Facebooka;
  //  `PublishPost` — alert pierwszego wpisu) i w schedulerze
  //  (`kuking:policz-kukingow` → `LiczbaKukingow` → `CookEligibility`,
  //  wykluczenie gospodarza z metryk). Worker go nie czyta.
  //  To nie sekret, ale wartość jest RÓŻNA w każdym środowisku (UUID konta
  //  w jego własnej bazie), więc idzie przez `ctx.shared`, nie jako stała.
  //  PUSTE = przejściowy fallback po `KUKING_HOST_USERNAME` (docs/DEPLOYMENT.md).
  const gospodarzEnv = {
    KUKING_HOST_USER_ID: ctx.shared.KUKING_HOST_USER_ID,
  };

  const webEnv = { ...appEnv, ...gospodarzEnv, ...pocztaEnv, ...wejscieEnv, ...czyszczenieCdnEnv, ...alarmModeratoraEnv };
  const workerEnv = {
    ...appEnv,
    ...pocztaEnv,
    ...czyszczenieCdnEnv,
    ...modelEnv,
    ...alarmModeratoraEnv,
  };
  const schedulerEnv = { ...appEnv, ...pocztaEnv, ...alarmModeratoraEnv, ...kopieOdczytEnv, ...pulsHarmonogramuEnv, ...gospodarzEnv };
  const wszystkieRoleEnv = { ...webEnv, ...workerEnv, ...schedulerEnv };

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
  //  Każdy wpis korzenia, który `COPY . .` wnosi do obrazu, ma tu wzorzec
  //  albo powód w rejestrze `W_OBRAZIE_BEZ_WPLYWU` (scripts/railway/iac.test.mjs).
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
      // Komunikaty po polsku (walidacja, hasła). Do 25.09.2026 brakowało
      // tego wpisu: poprawka samego tekstu błędu nie uruchamiała wdrożenia
      // (audyt B10-05).
      "lang/**",
      "docker/**",
      "Dockerfile",
      "composer.json",
      "composer.lock",
      "package.json",
      "package-lock.json",
      // `ignore-scripts=true` dla `npm ci` w etapie assets — zmienia build.
      ".npmrc",
      "vite.config.js",
      "artisan",
    ],
  };

  // ===========================================================================
  //  SERWIS: web (w Railway: NAZWA_SERWISU_WWW = "kuking.pl")
  // ===========================================================================
  const web = service(NAZWA_SERWISU_WWW, {
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
      //  DRUGA KOMENDA: `db:seed`, i to NIE jest pomyłka.
      //
      //  D-025 mówi wprost, że treść zalążkowa ma wejść na produkcję —
      //  pierwsza zaproszona osoba trafiała na pusty feed, a właściciel,
      //  zapytany, odpowiedział „tak, ale jawnie oznaczone". Kod był gotowy
      //  8 września (`TrescZalazkowaSeeder`: 12 kont, 39 przepisów, 80 wpisów,
      //  60 komentarzy, każde konto z `users.is_seeded` i widoczną odznaką
      //  „konto przykładowe"). Wjechał na produkcję i NIC SIĘ NIE STAŁO:
      //  ani ta lista, ani `docker/entrypoint.sh` nie wołały `db:seed`
      //  w żadnym miejscu. Sprawdzone 9 września na żywej stronie —
      //  `/odkryj` nie pokazywał ani jednej odznaki „konto przykładowe".
      //
      //  Klasyczna usterka ostatniego metra: decyzja podjęta, kod napisany,
      //  testy zielone, a funkcji nie ma, bo nikt jej nie uruchomił.
      //
      //  DLACZEGO TO JEST BEZPIECZNE NA PRODUKCJI — trzy niezależne powody,
      //  każdy sprawdzalny w kodzie, żaden nie jest deklaracją:
      //    * `DatabaseSeeder` odcina `DemoSeeder` warunkiem
      //      `if (! app()->environment('production'))` — dane demo nie mają
      //      jak tędy wejść;
      //    * `TrescZalazkowaSeeder` jest idempotentny po naturalnym kluczu:
      //      drugie i kolejne uruchomienie nie tworzy niczego drugi raz
      //      (`TrescZalazkowaSeederTest::test_drugi_przebieg_nic_nie_zmienia`);
      //    * nazwa użytkownika zajęta przez PRAWDZIWEGO człowieka (konto bez
      //      `is_seeded`) nie jest ruszana — import tego konta jest pomijany
      //      i zgłaszany. `TagPromotionSeeder` działa tylko na pustej liście,
      //      więc nie przywróci tagu, który gospodarz zdjął w panelu.
      //
      //  Kolejność ma znaczenie: `migrate` przed `db:seed`, bo seeder pisze do
      //  kolumn, które dokłada migracja. Niezerowy exit zatrzymuje deploy, więc
      //  nieudany seed nie wypuści kodu, który na tej treści polega.
      //
      //  Pilnuje tego `WdrozenieUruchamiaTrescZalazkowaTest` — razem
      //  z kolejnością komend i z tym, że seed na produkcji nie wwozi danych
      //  demo.
      preDeployCommand: [
        "php artisan migrate --force --no-interaction",
        "php artisan db:seed --force --no-interaction",
      ],

      // -----------------------------------------------------------------------
      //  HEALTHCHECK — warunek zero-downtime deploy.
      //  Railway odpytuje /health aż dostanie 2xx i DOPIERO WTEDY przełącza
      //  ruch na nowy deploy. Trasa musi sprawdzać połączenie z bazą;
      //  healthcheck zwracający zawsze 200 nie chroni przed niczym.
      //
      //  Requesty idą z hosta healthcheck.railway.app. Middleware TrustHosts
      //  JEST od 10 września 2026 włączony (D-071), a ten host jest na liście
      //  w `App\Support\ZaufaneHosty` — nie usuwaj go stamtąd, bo wtedy
      //  healthcheck dostaje 400 i deploy nigdy się nie kończy. Pilnuje tego
      //  test `ZaufaneHostyTest::test_healthcheck_railwaya_przechodzi`.
      //  Ratunek bez deployu, gdyby Railway zmienił ten host: zmienna
      //  KUKING_ZAUFANE_HOSTY w panelu (patrz `config/proxy.php`).
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
      //
      //  W ROLI `all` TEN SAM KONTENER TRZYMA `queue:work` (audyt B8-03).
      //  30 s ubijało przetwarzanie zdjęcia (limit 120 s) w połowie:
      //  `failed()` się nie wykonywał, a rezerwacja wisiała w `jobs` do
      //  `retry_after` (960 s) — zdjęcie „za chwilę" przez 16 minut i zużyta
      //  próba. Dlatego `all` dostaje to samo okno co worker.
      // -----------------------------------------------------------------------
      drainingSeconds: splitServices ? 30 : ZAMKNIECIE_Z_KOLEJKA_S,

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
      // Rola "all" = web + worker + scheduler w jednym kontenerze.
      // Oszczędza ~2/3 kosztu (płacisz per serwis za RAM i CPU), za cenę
      // izolacji awarii. Na staging/preview zawsze; na produkcji tylko
      // w fazie alfy (PRODUCTION_SPLIT_SERVICES = false).
      //
      // Rola "all" uruchamia JEDEN proces `queue:work --queue=high,default,media,low`
      // (kolejność = priorytet), nie proces na kolejkę jak rola "worker".
      // Trzy procesy w tym kontenerze 1024 MB mogłyby mieć szczyt naraz —
      // zdjęcie 50 Mpx ~452 MB, eksport do 512M, do tego FrankenPHP — a OOM
      // kładzie też stronę. Ceną jest głodzenie `media`/`low` przy stałej
      // zaległości `default` (#1030); lekarstwem jest osobny serwis `worker`.
      // Ręczna zmiana bez wdrożenia: zmienna QUEUE_WORKERS (docs/DEPLOYMENT.md,
      // „Kolejki"), logika w `listy_kolejek()` w docker/entrypoint.sh.
      //
      // Rozdzielony web dostaje TYLKO swój zestaw; `all` robi pracę trzech
      // ról, więc dostaje ich sumę (patrz „ZESTAWY PER ROLA").
      ...(splitServices ? webEnv : wszystkieRoleEnv),
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
      // SIGTERM dokańczając bieżący job (dzięki rozszerzeniu pcntl).
      // Uzasadnienie liczby przy `ZAMKNIECIE_Z_KOLEJKA_S`.
      drainingSeconds: ZAMKNIECIE_Z_KOLEJKA_S,

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

    // Rola "worker" = osobny proces `queue:work` na każdą kolejkę (high,
    // default, media, low), więc żadna nie głoduje za cudzą zaległością
    // (#1030). `media` zawsze ma jeden proces — dwa zdjęcia 50 Mpx naraz nie
    // mieszczą się w 1024 MB. Szczegóły: `listy_kolejek()` w docker/entrypoint.sh.
    //
    // PAMIĘĆ: `high` (listy wejścia na konto, audyt B8-06) to CZWARTY proces.
    // Wysyła tylko e-maile, więc do szczytu `media` (~452 MB przy 50 Mpx)
    // i eksportu (do 512M) dokłada tyle, ile pusty proces PHP z frameworkiem
    // (rząd kilkudziesięciu MB). Zapas w 1024 MB maleje, ale nie znika;
    // gdyby `media` i eksport miały szczyt naraz — to ta sama granica co
    // przed czwartym procesem, nie nowa.
    env: { ...workerEnv, APP_ROLE: "worker" },
  });

  // ===========================================================================
  //  SERWIS: scheduler  (tylko produkcja)
  //
  //  Uruchamia pętlę `schedule:run` wyrównaną do początku każdej minuty
  //  (`docker/entrypoint.sh`, `petla_harmonogramu`). NIE `schedule:work` —
  //  ten wymaga `proc_open`, wyłączonego w `docker/php.ini`.
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

    env: { ...schedulerEnv, APP_ROLE: "scheduler" },
  });

  // ===========================================================================
  //  SERWIS: kopia-bazy  (tylko produkcja)
  //
  //  JEDYNA KOPIA BAZY, JAKĄ MA TEN PROJEKT — issue #193, decyzja D-043.
  //
  //  Railway na planie Free/Hobby NIE ROBI ŻADNYCH KOPII: Volume Backups
  //  i PITR to funkcje planu Pro. Do powstania tego serwisu liczba kopii
  //  bazy Kuking wynosi ZERO (sprostowanie w D-043), a nie „dwie warstwy
  //  Railwaya plus ta trzecia", jak zakładał INFRA_DECISION.md §10.
  //
  //  DLACZEGO OSOBNY SERWIS, A NIE HARMONOGRAM APLIKACJI
  //  `docker/php.ini` wyłącza `proc_open`, a `pg_dump` wołany z PHP wymaga
  //  dokładnie tej funkcji (`Symfony\Process`). Osłabienia tego hardeningu
  //  zabrania AGENTS.md, więc zrzut jest do PRZENIESIENIA, nie do naprawienia
  //  na miejscu. Ten obraz (`docker/kopia/Dockerfile`) nie ma PHP w ogóle.
  //
  //  DLACZEGO NIE GITHUB ACTIONS: poświadczenie do produkcyjnej bazy
  //  musiałoby trafić do sekretów GitHuba, czyli powstałaby druga kopia
  //  najwrażliwszego klucza, w innym systemie niż baza (D-043).
  //
  //  DLACZEGO Railway Cron, mimo ostrzeżenia przy serwisie `scheduler`
  //  wyżej: tam problemem była GRANULACJA (Laravel scheduler musi być
  //  odpytywany co minutę, a Railway Cron ma minimum 5 minut). Tutaj
  //  granulacja nie ma żadnego znaczenia — kopia raz na dobę może wystartować
  //  minutę czy pięć później. Cron jest za to jedyną formą, w której kontener
  //  wstaje, robi swoje i UMIERA, nie płacąc za nic pomiędzy.
  //  https://docs.railway.com/cron-jobs
  //
  //  ⚠️ TEN SERWIS NIE ISTNIEJE JESZCZE W RAILWAY, tak samo jak `web`,
  //  `worker` i `scheduler` wyżej (`railway config apply` nie zostało
  //  uruchomione ani razu — patrz sprostowanie przy
  //  PRODUCTION_SPLIT_SERVICES). Do czasu pierwszego `apply` trzeba go
  //  założyć RĘCZNIE w panelu, a nie liczyć na ten plik. Dokładna lista
  //  kliknięć: docs/infra/KOPIE_I_ODTWORZENIE.md §7.3.
  // ===========================================================================
  const kopiaBazy = service("kopia-bazy", {
    source,

    build: {
      builder: "DOCKERFILE" as const,
      // DRUGI Dockerfile w repozytorium, świadomie. Uzasadnienie w jego
      // własnym nagłówku.
      dockerfilePath: "docker/kopia/Dockerfile",
      // Kopia bazy nie ma nic wspólnego z kodem aplikacji, więc zmiana
      // kontrolera nie musi jej przebudowywać. Za to zmiana samego skryptu
      // MUSI — inaczej poprawka w kopii nie doszłaby na produkcję.
      watchPatterns: ["docker/kopia/**"],
    },

    deploy: {
      // 02:17 UTC = 03:17/04:17 w Polsce, czyli po całym nocnym sprzątaniu
      // z routes/console.php (03:20-04:50 to retencje) — zrzut ma zawierać
      // stan PO nich, nie w ich środku.
      //
      // Nierówna minuta celowo: gdyby kiedyś doszedł drugi taki serwis,
      // pełne godziny są miejscem, w którym wszystko zderza się ze wszystkim.
      cronSchedule: "17 2 * * *",

      region: REGION,
      numReplicas: 1,

      // NEVER, nie ON_FAILURE. To jest zadanie jednorazowe: nieudany przebieg
      // ma zostać nieudany i zaalarmować (skrypt robi to sam), a nie wstawać
      // w pętli i próbować zrzucać całą bazę co kilkadziesiąt sekund.
      // Ponowienie jest decyzją człowieka albo następnego przebiegu za dobę.
      restartPolicyType: "NEVER",

      // Kontener kończy pracę sam; usypianie nie ma tu czego uśpić.
      sleepApplication: false,

      limitOverride: {
        containers: {
          // Szyfrowanie idzie strumieniowo (`openssl cms -stream`), a zrzut
          // leży na dysku, nie w pamięci — 512 MB z zapasem wystarcza.
          memoryBytes: 512 * MB,
          cpu: 1,
        },
      },
    },

    // ŚWIADOMIE BEZ `...appEnv`. Ten kontener nie potrzebuje APP_KEY, kluczy
    // do bucketów ze zdjęciami, poświadczeń poczty ani DSN-a Sentry — a każdy
    // sekret, który tu wstawimy, dostaje prawo odczytu do procesu trzymającego
    // w rękach zrzut całej bazy. Lista jest więc zamknięta i krótka.
    env: {
      // Referencja do serwisu Postgres — SIEĆ WEWNĘTRZNA Railwaya
      // (host *.railway.internal). Skrypt sam odmawia pracy, gdy dostanie
      // adres publiczny (*.proxy.rlwy.net), bo to wypuszczałoby komplet
      // danych osobowych przez publiczny internet przy każdym przebiegu.
      DB_URL: db.env.DATABASE_URL,

      // --- Bucket kopii: OSOBNY OD BUCKETÓW ZE ZDJĘCIAMI (#193) -------------
      //
      //  Nie prefiks w tym samym buckecie — osobny bucket i OSOBNY TOKEN.
      //  Bucket wariantów bywał wystawiony publicznie pod `cdn.kuking.pl`,
      //  a publiczność w R2 jest cechą BUCKETU, nie obiektu
      //  (patrz komentarz przy dyskach w config/filesystems.php). Zrzut całej
      //  bazy w buckecie, który kiedykolwiek może dostać własną domenę,
      //  jest wypadkiem czekającym na swoją kolej.
      //
      //  Token ma mieć prawo WYŁĄCZNIE do tego jednego bucketu. Gdyby wyciekł,
      //  nie daje dostępu do zdjęć ani do paczek RODO — a zrzuty i tak są
      //  zaszyfrowane kluczem publicznym.
      KOPIA_S3_ENDPOINT: ctx.shared.R2_ENDPOINT,
      KOPIA_S3_BUCKET: ctx.shared.R2_KOPIE_BUCKET,
      KOPIA_S3_KLUCZ: ctx.shared.R2_KOPIE_ACCESS_KEY_ID,
      KOPIA_S3_SEKRET: ctx.shared.R2_KOPIE_SECRET_ACCESS_KEY,
      KOPIA_S3_REGION: "auto", // R2 wymaga literalnie "auto"

      // --- Szyfrowanie -------------------------------------------------------
      //
      //  CERTYFIKAT, CZYLI KLUCZ PUBLICZNY. Tą wartością da się zaszyfrować
      //  i NIE DA SIĘ odszyfrować niczego. Klucz prywatny nie istnieje
      //  w żadnym środowisku uruchomieniowym — jego jedyne kopie są
      //  w menedżerze haseł właściciela i na nośniku offline w innym miejscu
      //  fizycznym (KOPIE_I_ODTWORZENIE.md §7.1).
      //
      //  Skutek: przejęcie tego serwisu, bucketu albo całego konta Railway
      //  daje szyfrogram i nic więcej.
      KOPIA_KLUCZ_PUBLICZNY: ctx.shared.KOPIA_KLUCZ_PUBLICZNY,

      // --- Alarm -------------------------------------------------------------
      //
      //  TEN SAM kanał, co błędy 500 (D-041) — właściciel ma jedno miejsce,
      //  w które patrzy. Treść jest budowana z listy zamkniętej: etap, kod,
      //  odcisk. Bez adresu bazy, bez nazwy bucketu, bez wyjścia pg_dump
      //  (audyt A6-01 — ten kanał wychodzi do usługi, nad którą nie mamy
      //  kontroli).
      KOPIA_WEBHOOK_URL: ctx.shared.LOG_BLAD_WEBHOOK_URL,
      KOPIA_SRODOWISKO: envName,

      // --- Retencja i progi --------------------------------------------------
      KOPIA_PREFIKS: "baza/",
      // 30 dni to kompromis między „da się wrócić przed miesiąc" a rachunkiem
      // za R2 (darmowy pułap 10 GB). Przy zrzucie rzędu 100 MB to ~3 GB.
      KOPIA_RETENCJA_DNI: "30",
      // NIGDY nie zostawiaj mniej niż tyle kopii, niezależnie od wieku.
      // Bez tego progu jedna dłuższa przerwa w działaniu serwisu wystarczyłaby,
      // żeby przebieg wznowiony po niej skasował wszystko, co jeszcze było.
      KOPIA_MINIMUM_KOPII: "7",
      // Kopia starsza niż 36 h przy harmonogramie dobowym znaczy, że co
      // najmniej jeden przebieg wypadł — i to jest alarm, nie ciekawostka.
      KOPIA_ALARM_PO_GODZINACH: "36",
      // docs/DATABASE.md wymienia 25 tabel. Zrzut z mniej niż 20 tabelami
      // znaczy, że DB_URL wskazał NIE TĘ bazę — a plik i tak by powstał
      // i wyglądał poprawnie. To jest ten rodzaj kopii, który jest gorszy
      // od jej braku.
      KOPIA_MIN_TABEL: "20",
    },
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

  //  Kopia bazy TYLKO na produkcji. Zrzut stagingu nie ma czego chronić —
  //  tamte dane są wygenerowane seederem i odtwarzalne w minutę — a kosztowałby
  //  miejsce w tym samym buckecie i mieszałby się w retencji z kopiami, które
  //  naprawdę są komuś potrzebne.
  const dataServices = isProduction ? [db, kopiaBazy] : [db];

  const resources = [group("Aplikacja", appServices), group("Dane", dataServices)];

  return project("kuking", { resources });
});

// =============================================================================
//  CZEGO TEN PLIK NADAL NIE OBEJMUJE — ustaw ręcznie w panelu
//  (checklista z dokładnymi kliknięciami: DEPLOYMENT_RUNBOOK.md, krok 12)
//
//   1. PR Environments — Project Settings → Environments → Enable.
//      Środowiskiem BAZOWYM ustaw `staging`, nie `production`, inaczej każdy
//      PR dostanie kopię produkcyjnych sekretów.
//   2. Kopie bazy — NIE DA SIĘ ICH WŁĄCZYĆ NA TYM PLANIE (D-043).
//      Volume Backups i PITR to funkcje planu Pro; na Free i Hobby panel
//      nawet nie pokazuje tej zakładki. Poprzednia wersja tego punktu kazała
//      „kliknąć Daily + Weekly + Enable PITR" i była nieprawdą — a nieprawda
//      w checkliście backupów jest gorsza niż jej brak, bo daje się odhaczyć.
//      Kopie robi serwis `kopia-bazy` wyżej (issue #193): zrzut logiczny,
//      zaszyfrowany kluczem publicznym, w buckecie R2 poza Railwayem.
//      Do założenia RĘCZNIE (bucket + token + zmienne + serwis cron):
//      docs/infra/KOPIE_I_ODTWORZENIE.md §7.3.
//   2b. Zmienne sharedowe wymagane przez `kopia-bazy`, wszystkie nowe:
//      R2_KOPIE_BUCKET, R2_KOPIE_ACCESS_KEY_ID, R2_KOPIE_SECRET_ACCESS_KEY
//      (token z prawem ZAPISU, dla serwisu kopii),
//      R2_KOPIE_ODCZYT_ACCESS_KEY_ID, R2_KOPIE_ODCZYT_SECRET_ACCESS_KEY
//      (token TYLKO DO CZYTANIA, dla czujki w aplikacji),
//      KOPIA_KLUCZ_PUBLICZNY. Klucza PRYWATNEGO nie wolno tu wstawić —
//      to jedyna rzecz, która NIE MA prawa mieszkać w Railwayu.
//   3. Shared variables — wartości sekretów (ten plik je tylko referencuje).
//   3b. Zmienne sharedowe dopisane w #1013/#1014 — do założenia w KAŻDYM
//      środowisku (staging: puste albo testowe, nigdy wartości produkcji;
//      OPENAI_MODERATION_KEY i KUKING_MODEL_ALARM_EMAIL ten plik i tak
//      przekazuje TYLKO na produkcji):
//      OPENAI_MODERATION_KEY (Sealed), KUKING_MODEL_ALARM_EMAIL,
//      CLOUDFLARE_ZONE_ID, CLOUDFLARE_PURGE_TOKEN (Sealed),
//      APP_PREVIOUS_KEYS (Sealed; puste poza rotacją APP_KEY).
//      Które serwisy je dostają: „ZESTAWY PER ROLA" wyżej
//      i DEPLOYMENT_RUNBOOK.md, KROK 8.
//   4. Alerty budżetowe — Workspace → Usage → Usage Limits.
//
//  Uzupełniające: jeśli `railway config plan` odrzuci którekolwiek pole
//  z bloków `deploy`/`build`/`source` (są w typach SDK 3.11.0, ale nie
//  wszystkie w publicznej dokumentacji), usuń je z pliku i ustaw ręcznie
//  w panelu — reszta konfiguracji zadziała bez zmian.
// =============================================================================
