#!/bin/bash
set -euo pipefail
cd /home/mateusz/flota/gpt-pytania-widoki-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_gpt-pytania-widoki DB_USERNAME=kuking DB_PASSWORD=kuking
bash scripts/kontrola-ujemna.sh --nazwa 'Tytul pytania w powiadomieniu #834' --plik app/Domain/Notifications/QuestionNotificationContext.php --zamien 'return Comment::query()' --na 'return collect(); return Comment::query()' --oczekuj 'Każde pytanie ma własny tytuł' --json /tmp/gpt-pytania-widoki-834-negative.json -- vendor/bin/phpunit tests/Feature/QuestionNotificationContextTest.php
bash scripts/kontrola-ujemna.sh --nazwa 'Pomoc rozroznia nowe pytanie #860' --plik resources/views/pages/static/help.blade.php --zamien 'Pytanie w Poradźcie publikujesz dla wszystkich' --na 'Przy każdym wpisie wybierasz odbiorców' --oczekuj 'Pytanie w Poradźcie publikujesz dla wszystkich' --json /tmp/gpt-pytania-widoki-860-negative.json -- vendor/bin/phpunit tests/Feature/QuestionHelpVisibilityTest.php
