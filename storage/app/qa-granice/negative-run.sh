#!/usr/bin/env bash
set -euo pipefail
cd /home/mateusz/flota/gpt-wyszukiwanie-granice-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_gpt-wyszukiwanie-granice DB_USERNAME=kuking DB_PASSWORD=kuking
bash scripts/kontrola-ujemna.sh --nazwa 'Prefiks @ #886' --plik app/Domain/Search/SearchQuery.php --zamien '? substr($phrase, 1)' --na '? $phrase' --oczekuj 'Skopiowana nazwa nie odnalazła profilu' --json storage/kontrola-886.json -- vendor/bin/phpunit tests/Feature/PrefiksNazwyWWyszukiwaniuTest.php
bash scripts/kontrola-ujemna.sh --nazwa 'Limit długości #885' --plik app/Domain/Search/SearchQuery.php --zamien 'MAX_PHRASE_LENGTH = 120' --na 'MAX_PHRASE_LENGTH = 1000' --oczekuj 'Długa fraza nie ma błędu przy polu' --json storage/kontrola-885.json -- vendor/bin/phpunit tests/Feature/DlugoscFrazyWyszukiwaniaTest.php
bash scripts/kontrola-ujemna.sh --nazwa 'Bezpośrednie wejście domenowe #885' --plik app/Domain/Search/SearchQuery.php --zamien 'self::phraseValidator($phrase)->validate();' --na '/* Kontrola ujemna: bez walidacji domeny. */' --oczekuj 'Domena przyjęła zbyt długą frazę' --json storage/kontrola-885-domena.json -- vendor/bin/phpunit tests/Feature/DlugoscFrazyWyszukiwaniaTest.php