# syntax=docker/dockerfile:1.7
# =============================================================================
#  Kuking.pl — produkcyjny obraz aplikacji (Laravel 13 / PHP 8.4)
# =============================================================================
#
#  DLACZEGO WŁASNY DOCKERFILE, A NIE RAILPACK?
#  -------------------------------------------
#  Railway domyślnie buduje kod przez Railpack (następca Nixpacks) i owszem —
#  wykrywa Laravela i uruchamia go przez php-fpm + Caddy
#  (https://docs.railway.com/guides/laravel, https://railpack.com/languages/php).
#  Dla Kuking wybieramy jednak własny Dockerfile, bo:
#
#   1. Zdjęcia to rdzeń produktu. Potrzebujemy DETERMINISTYCZNEGO zestawu
#      rozszerzeń PHP (gd z webp/avif, exif, intl z pełnym ICU, zip, pcntl).
#      Railpack tego nie gwarantuje i może to zmienić między buildami.
#   2. Parytet lokalne dev / CI / produkcja — ten sam obraz uruchamiasz u siebie
#      i w GitHub Actions. Z Railpackiem "działa u mnie" nie znaczy nic.
#   3. Jeden obraz obsługuje TRZY role (web / worker / scheduler) przez
#      APP_ROLE — bez duplikowania konfiguracji buildu w trzech serwisach.
#   4. Wyjście z vendor lock-in. Ten obraz uruchomisz na Fly.io, Hetznerze,
#      Google Cloud Run czy zwykłym VPS bez zmiany jednej linii kodu.
#      Railpack działa tylko w Railway.
#   5. Kontrola nad php.ini (opcache, memory_limit, upload_max_filesize) —
#      krytyczne przy uploadzie zdjęć z telefonów.
#
#  Koszt tej decyzji: ~80 linii do utrzymania. Warto.
#
#  DLACZEGO FRANKENPHP, A NIE NGINX + PHP-FPM + SUPERVISORD?
#  --------------------------------------------------------
#  FrankenPHP = Caddy z wbudowanym PHP. Jeden proces, jeden PID, jeden plik
#  konfiguracyjny. Klasyczny stos nginx+fpm+supervisord to trzy procesy,
#  trzy configi i ręczna obsługa sygnałów — w kontenerze, gdzie Railway
#  wysyła SIGTERM i oczekuje graceful shutdown, to prosta droga do
#  ucinanych requestów. Caddy obsługuje graceful drain sam.
#  Dodatkowo dostajemy z pudełka: HTTP/2, kompresję zstd/gzip, Early Hints.
#
#  UWAGA: świadomie NIE włączamy trybu worker (Octane). Livewire 4 trzyma stan
#  komponentów w requeście, a długożyjący worker PHP zwiększa ryzyko wycieku
#  stanu między użytkownikami. Na MVP tryb klasyczny + opcache jest szybki
#  dość, a bezpieczniejszy. Worker mode: patrz komentarz na końcu pliku.
#
#  Wersja bazowa: Debian trixie (nie alpine) — pełny ICU dla intl (polskie
#  sortowanie, formaty daty) i przewidywalny gd z avif/webp. musl na alpine
#  potrafi tu zaskoczyć, a oszczędność ~60 MB obrazu nie jest tego warta.
# =============================================================================


# -----------------------------------------------------------------------------
# ETAP 1 — assety front-endu (Vite 7 + Tailwind 4)
# -----------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /app

# Najpierw manifesty — warstwa npm ci przeżywa zmiany w kodzie Blade/CSS.
COPY package.json package-lock.json ./
# BEZ `--mount=type=cache`. Railway wymaga, żeby id cache'a zawierało LITERALNE
# id serwisu (`id=s/<service id>-<ścieżka>`) i wprost zabrania użycia zmiennych
# środowiskowych w tym miejscu. Każdy serwis (web, worker, scheduler) i każde
# środowisko preview ma inne id, więc jednej wartości nie da się wpisać na
# stałe — a wartość niepasująca do wzorca wywala build:
#
#   dockerfile invalid: flag '--mount=type=cache,id=s/kuking-npm-/root/.npm,
#   target=/root/.npm' is missing the cacheKey prefix from its id at Line 57
#
# Strata jest mniejsza, niż się wydaje: warstwa i tak jest cache'owana przez
# Dockera, dopóki nie zmieni się package-lock.json. Cache mount pomagał tylko
# wtedy, gdy lock SIĘ zmienił — czyli rzadko.
RUN npm ci --no-audit --no-fund

# Vite potrzebuje configu, źródeł i widoków (Tailwind 4 skanuje Blade).
COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
COPY routes ./routes
# CAŁY `scripts/`, A NIE WYLICZANKA POJEDYNCZYCH PLIKÓW.
#
# Do 22 września stały tu cztery `COPY` na konkretne pliki. Ta gałąź dopisuje
# do polecenia `build` dziesięć testów JS, których nic wcześniej nie
# uruchamiało — i wyliczanka przestała wystarczać. Zmierzone w katalogu
# odtwarzającym ten etap plik po pliku: `node --test` z listy `build`
# kończył się ZIELONO na 33 testach, podczas gdy pełne drzewo daje 61.
# Dwadzieścia osiem testów znikało bez jednego czerwonego wiersza, bo Node
# pomija nieistniejącą ścieżkę zamiast zgłosić błąd — dokładnie regresja
# #1085, dla której powstał `ObrazAssetowMaPlikiTestowTest`.
#
# Wyliczanki nie da się tu utrzymać, bo te testy ciągną też SWOJE moduły:
# `port-grupy.test.mjs` → `port-grupy.mjs`, `korpus-605.test.mjs` →
# `generator-obciazenia-605.mjs`, `probnik-605.test.mjs` →
# `probnik-obciazenia-605.sh`, `przyrzad-605.test.mjs` →
# `serwer-scenariuszy-605.mjs`, a `fixtures/*.test.mjs` → `dostepnosc.mjs`
# i własne sąsiedztwo. Każdy przyszły test dokładałby kolejne dwa wiersze
# i kolejną okazję, żeby o jednym zapomnieć — czyli żeby obraz znów zbudował
# się zielono z mniejszym pokryciem.
#
# KOSZT JEST ŚWIADOMY: warstwa unieważnia się przy każdej zmianie w
# `scripts/`, nie tylko w czterech plikach. To 2,1 MB kontekstu i etap
# przejściowy — nic z niego nie trafia do obrazu końcowego, który bierze
# z `assets` wyłącznie `/app/public/build`.
COPY scripts ./scripts
# `service-worker-marka.test.mjs` czyta `public/sw.js`. `.dockerignore`
# wycina `public/build`, `public/hot` i `public/storage`, więc wchodzą tu
# same źródła statyczne (452 KB), a nie wynik poprzedniego builda.
COPY public ./public

# Node pomija nieistniejący plik podany do `--test` zamiast kończyć błędem.
# Bez tej bramki obraz budował się zielono, uruchamiając 21 zamiast 33 testów.
#
# Bramka NIE jest już listą nazw do ręcznego dopisywania: pyta wprost
# `package.json`, czego zażąda `npm run build`, i sprawdza, że KAŻDY z tych
# plików naprawdę leży w obrazie. Dzięki temu pilnuje także testów dodanych
# po dzisiejszym dniu, bez ruszania tego pliku.
RUN node --input-type=module -e "\
import { readFileSync, existsSync } from 'node:fs';\
const build = JSON.parse(readFileSync('package.json', 'utf8')).scripts.build;\
const czlon = build.split('&&').map((s) => s.trim()).find((s) => s.startsWith('node --test '));\
if (!czlon) { console.error('Polecenie build nie wola juz node --test.'); process.exit(1); }\
const pliki = czlon.slice('node --test '.length).split(' ').filter((a) => a && !a.startsWith('-'));\
const brak = pliki.filter((f) => !existsSync(f));\
if (brak.length) { console.error('Etap assets nie ma plikow testowych: ' + brak.join(', ')); process.exit(1); }\
console.log('Etap assets ma komplet ' + pliki.length + ' plikow testowych z polecenia build.');"
RUN npm run build
# Wynik: /app/public/build/{manifest.json,assets/*}


# -----------------------------------------------------------------------------
# ETAP 2 — zależności PHP (Composer)
# -----------------------------------------------------------------------------
# Ten sam obraz bazowy co runtime, żeby platform-check Composera i skompilowane
# rozszerzenia zgadzały się 1:1 z tym, na czym aplikacja faktycznie pobiegnie.
FROM dunglas/frankenphp:1-php8.4-trixie AS vendor

# install-php-extensions jest częścią obrazu FrankenPHP
# (docker-php-extension-installer).
RUN install-php-extensions \
      pdo_pgsql \
      pgsql \
      intl \
      gd \
      zip \
      exif \
      pcntl \
      bcmath \
      opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Warstwa zależności osobno od kodu — zmiana kontrolera nie unieważnia vendora.
COPY composer.json composer.lock ./

# --no-scripts, bo skrypty post-install Laravela (package:discover) wymagają
# pełnego drzewa aplikacji, którego jeszcze nie ma. Uruchamiamy je niżej.
# Bez cache mount — uzasadnienie przy `npm ci` wyżej.
RUN COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
      --no-dev \
      --no-interaction \
      --no-progress \
      --no-scripts \
      --prefer-dist \
      --optimize-autoloader \
      --classmap-authoritative


# -----------------------------------------------------------------------------
# ETAP 3 — obraz runtime
# -----------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-trixie AS runtime

LABEL org.opencontainers.image.title="kuking.pl"
LABEL org.opencontainers.image.source="https://github.com/woogitsu/kuking.pl"
LABEL org.opencontainers.image.licenses="proprietary"

# Te same rozszerzenia co w etapie vendor. Trzymaj listy zsynchronizowane.
RUN install-php-extensions \
      pdo_pgsql \
      pgsql \
      intl \
      gd \
      zip \
      exif \
      pcntl \
      bcmath \
      opcache \
  && apt-get update \
  && apt-get install -y --no-install-recommends \
      postgresql-client \
      tini \
  && rm -rf /var/lib/apt/lists/*
#  postgresql-client → pg_dump / psql dla awaryjnego backupu i restore drill
#  tini              → poprawny init w PID 1 (reaping zombie, przekazywanie sygnałów)

# Konfiguracja PHP i serwera
COPY docker/php.ini      /usr/local/etc/php/conf.d/zz-kuking.ini
COPY docker/Caddyfile    /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/kuking-entrypoint
RUN chmod +x /usr/local/bin/kuking-entrypoint

WORKDIR /app

# Kod aplikacji + zależności + zbudowane assety.
# Kolejność: najpierw rzadko zmienny vendor, potem kod, na końcu assety.
COPY --from=vendor  /app/vendor       ./vendor
COPY . .
COPY --from=assets  /app/public/build ./public/build

# ZNACZNIK WYDANIA — data i godzina powstania TEGO obrazu, w UTC, ISO-8601.
#
# Stopka serwisu pokazuje ją obok skrótu commita (`App\Support\Wersja`).
# Skrót mówi, CO jest wdrożone; data mówi, KIEDY — a to jest pytanie, które
# pada częściej i na które siedem znaków szesnastkowych nie odpowiada nikomu
# bez historii gita pod ręką.
#
# DLACZEGO PLIK, A NIE ZMIENNA ŚRODOWISKOWA: Railway nie wstrzykuje czasu
# wdrożenia — wśród `RAILWAY_*` nie ma takiej zmiennej. Build jest jedynym
# miejscem, które ten moment zna.
#
# DLACZEGO TA WARSTWA, A NIE WYŻEJ: leży za `COPY . .`, więc unieważnia się
# przy każdej zmianie kodu. Znacznik odpowiada zatem wydaniu, a nie dacie
# pierwszego builda sprzed tygodnia. Przy ponownym wdrożeniu TEGO SAMEGO
# commita warstwa może wejść z cache'u i data zostanie stara — i tak ma być:
# to nadal jest to samo wydanie.
RUN date -u +%Y-%m-%dT%H:%M:%SZ > /app/bootstrap/wydanie.txt

# Skrypty Composera dopiero teraz — mają już pełne drzewo aplikacji.
# artisan package:discover zapisuje bootstrap/cache/packages.php (nie zależy od env).
#
# Composer musi tu być SKOPIOWANY osobno: obraz `dunglas/frankenphp` go nie ma,
# a etap `vendor` to inny stage — jego /usr/bin/composer nie przenosi się sam.
# Bez tego build padał na `/bin/sh: 1: composer: not found` (exit 127), czyli
# obrazu produkcyjnego NIE DAŁO SIĘ zbudować.
#
# Kasujemy binarkę w tej samej warstwie, w której jej używamy — inaczej
# zostałaby w obrazie produkcyjnym, a `rm` w osobnym RUN i tak nie zmniejsza
# obrazu (poprzednia warstwa nadal zawiera plik).
#
# `--no-scripts` jest tu KONIECZNE, nie kosmetyczne. Bez niego composer odpala
# hook `post-autoload-dump`, czyli `Illuminate\Foundation\ComposerScripts::
# postAutoloadDump`, a ten uruchamia `@php artisan package:discover` przez
# Symfony Process — który wymaga `proc_open`. My mamy `proc_open` WYŁĄCZONE
# w docker/php.ini (disable_functions, świadome utwardzenie), więc build padał na:
#
#     The Process class relies on proc_open, which is not available
#     on your PHP installation.
#
# Nie osłabiamy z tego powodu php.ini. `package:discover` i tak wołamy niżej
# wprost — bez Procesu, bez proc_open, z tym samym skutkiem.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
 && php artisan package:discover --ansi \
 && rm -f /usr/bin/composer

# ---------------------------------------------------------------------------
# Czego świadomie NIE robimy w buildzie:
#
#  * php artisan config:cache  → config:cache "zamraża" wartości env() w pliku.
#    W buildzie Railway NIE MA jeszcze produkcyjnych zmiennych ani dostępu do
#    private network. Zapiekłbyś tu puste DB_URL, APP_KEY i klucze R2, a potem
#    debugował, dlaczego produkcja łączy się z niczym. config:cache robimy
#    w entrypoincie, przy starcie kontenera (patrz docker/entrypoint.sh).
#
#  * php artisan migrate  → build jest bezstanowy, uruchamiany też dla PR-ów,
#    bez dostępu do produkcyjnej bazy. Migracje należą do fazy RELEASE:
#    Railway pre-deploy command (deklarowany w .railway/railway.ts). Pre-deploy
#    ma dostęp do zmiennych i private network, a jego niezerowy exit code
#    ZATRZYMUJE deploy — czyli zła migracja nie wypuści zepsutego kodu.
#    Źródło: https://docs.railway.com/deployments/pre-deploy-command
#
#  route:cache / view:cache / event:cache też robimy w entrypoincie, jednym
#  `php artisan optimize` — trwa ~1 s i eliminuje całą klasę błędów
#  "stary cache po deployu".
# ---------------------------------------------------------------------------

# Katalogi zapisywalne. Filesystem kontenera jest ulotny (brak volume — zdjęcia
# idą do R2, sesje/cache/kolejka do Postgresa), więc storage/ służy tylko
# jako scratch: skompilowane widoki i tymczasowe pliki uploadu.
RUN mkdir -p \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/views \
      storage/framework/testing \
      storage/app/public \
      storage/logs \
      bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Nie działamy jako root. Port > 1024, więc CAP_NET_BIND_SERVICE nie jest
# potrzebne — Caddy słucha na :8080 (patrz SERVER_NAME w entrypoincie).
RUN chown -R www-data:www-data /data/caddy /config/caddy

# ŚWIADOMIE BEZ `USER www-data`, mimo że aplikacja NIE działa jako root.
#
# Zejście na `www-data` robi entrypoint (sekcja 0), a nie ta instrukcja —
# i to jest jedyna różnica, ale różnica istotna. Railway montuje świeży
# wolumin jako `root:root`. Obraz startujący od razu jako `www-data` nie ma
# prawa założyć w nim podkatalogu: `mkdir` pada, `set -e` zabija start,
# kontener wpada w pętlę i znika CAŁA STRONA — mimo że problem dotyczy
# wyłącznie katalogu ze zdjęciami. Dokładnie to zdarzyło się na produkcji
# przy podpinaniu woluminu: serwis poszedł w 404.
#
# Entrypoint przekazuje katalog na własność `www-data` i natychmiast schodzi
# z uprawnień przez `setpriv`. Efekt jest ten sam co `USER www-data`, tylko
# o kilka instrukcji później — po tej jednej rzeczy, do której root jest
# potrzebny.
#
# Sprawdzenie w BUILDZIE, nie w runtime: bez `setpriv` entrypoint zostawiłby
# aplikację jako root i powiedziałby o tym wyłącznie w logu, którego nikt
# nie czyta. Lepiej, żeby nie zbudował się obraz.
RUN command -v setpriv >/dev/null 2>&1 \
 || { echo 'BŁĄD: brak setpriv — entrypoint nie zejdzie z uprawnień roota'; exit 1; }

# Wartości domyślne. Railway nadpisze PORT i wszystkie sekrety.
ENV APP_ROLE=web \
    PORT=8080 \
    SERVER_NAME=":8080" \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    FRANKENPHP_NO_COMPRESS=0

EXPOSE 8080

# Healthcheck dla uruchomień poza Railway (docker run / compose / Fly).
# W Railway healthcheck robi platforma (healthcheck: "/health" w railway.ts).
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1:".(getenv("PORT")?:8080)."/health") ? 0 : 1);'

# tini jako PID 1: przekazuje SIGTERM do entrypointu → Caddy robi graceful
# drain, a queue:work kończy bieżący job zamiast go porzucić.
ENTRYPOINT ["/usr/bin/tini", "-g", "--"]
CMD ["/usr/local/bin/kuking-entrypoint", "web"]

# =============================================================================
#  Jeśli kiedyś zabraknie wydajności (>~300 req/s na replikę):
#  włącz Laravel Octane w trybie worker FrankenPHP, dodając:
#      ENV FRANKENPHP_CONFIG="worker ./public/index.php"
#  i `composer require laravel/octane`.
#  PRZED włączeniem: audyt singletonów i statycznych właściwości pod kątem
#  wycieku stanu między requestami — z Livewire to realne ryzyko.
#  Źródło: https://frankenphp.dev/docs/worker/
# =============================================================================
