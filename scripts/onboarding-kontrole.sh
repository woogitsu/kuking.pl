#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ "${DB_HOST:-}" != 127.0.0.1 || "${DB_PORT:-}" != 55439 || "${DB_DATABASE:-}" != kuking_flota_gpt-onboarding ]]; then
    echo 'Użyj własnej bazy onboardingu na 127.0.0.1:55439.' >&2
    exit 1
fi
mkdir -p output/onboarding
bash scripts/kontrola-ujemna.sh --nazwa '#851 przenoszenie wyboru w przeglądarce' \
    --plik app/Http/Controllers/OnboardingController.php \
    --zamien '$selected = $selectedProfiles->pluck('\''username'\'')->all();' --na '$selected = [];' \
    --oczekuj '851: wyszukiwanie zgubiło Halinę' --json output/onboarding/851.json \
    -- node scripts/onboarding-browser.mjs
bash scripts/kontrola-ujemna.sh --nazwa '#852 komunikat częściowego wyniku' \
    --plik app/Http/Controllers/OnboardingController.php \
    --zamien 'if ($skipped > 0)' --na 'if (false)' \
    --oczekuj 'Nie udało się dodać wszystkich wybranych osób' --json output/onboarding/852.json \
    -- vendor/bin/phpunit tests/Feature/OnboardingWynikZapisuTest.php
bash scripts/kontrola-ujemna.sh --nazwa '#862 null jest pustą listą' \
    --plik app/Http/Controllers/OnboardingController.php \
    --zamien '$data['\''follow'\''] ?? []' --na '$request->input('\''follow'\'', [])' \
    --oczekuj 'array_map.*null given' --json output/onboarding/862.json \
    -- vendor/bin/phpunit tests/Feature/OnboardingWynikZapisuTest.php
bash scripts/kontrola-ujemna.sh --nazwa '#849 rezerwacja propozycji' \
    --plik app/Support/NazwaUzytkownika.php \
    --zamien 'self::dopuszczalna($baza) && ' --na '' \
    --oczekuj 'admin|pomoc' --json output/onboarding/849.json \
    -- vendor/bin/phpunit tests/Feature/PropozycjaNazwyZewnetrznejTest.php
bash scripts/kontrola-ujemna.sh --nazwa '#850 przywrócenie własnych pól po OAuth' \
    --plik app/Support/ExternalRegistrationDraft.php \
    --zamien 'return $draft['"'"'fields'"'"'];' --na 'return [];' \
    --oczekuj 'Własne imię' --json output/onboarding/850.json \
    -- vendor/bin/phpunit --filter test_wygasniecie_domkniecia_zachowuje
bash scripts/kontrola-ujemna.sh --nazwa 'Widoczny błąd limitu wyboru' \
    --plik resources/views/pages/onboarding/people.blade.php \
    --zamien '<x-blad-grupy name="follow" />' --na '' \
    --oczekuj 'f-follow-error' --json output/onboarding/limit.json \
    -- bash -c 'php artisan view:clear && vendor/bin/phpunit --filter test_nadmiar_wyborow'
