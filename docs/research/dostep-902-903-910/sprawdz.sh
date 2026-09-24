#!/usr/bin/env bash
set -euo pipefail
# Odtworzenie mtime przez przyrząd nie unieważnia skompilowanego Blade.
php artisan view:clear --quiet
# Przyrząd w bazowym SHA używa grep -q za potokiem z pipefail.
# Krótki raport zapobiega SIGPIPE; pełne wyjście zostaje do diagnozy.
set +e
php vendor/bin/phpunit "$@" > storage/dostep-regresje.txt 2>&1
result=$?
set -e
tail -n 25 storage/dostep-regresje.txt | cut -c 1-500
exit "$result"
