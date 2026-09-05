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
RUN --mount=type=cache,id=s/kuking-npm-/root/.npm,target=/root/.npm \
    npm ci --no-audit --no-fund

# Vite potrzebuje configu, źródeł i widoków (Tailwind 4 skanuje Blade).
COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
COPY routes ./routes

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
RUN --mount=type=cache,id=s/kuking-composer-/tmp/composer-cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
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
LABEL org.opencontainers.image.source="https://github.com/matmaxalez/kuking.pl"
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

# Skrypty Composera dopiero teraz — mają już pełne drzewo aplikacji.
# artisan package:discover zapisuje bootstrap/cache/packages.php (nie zależy od env).
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && php artisan package:discover --ansi

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
USER www-data

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
