<?php

declare(strict_types=1);

/*
 * =============================================================================
 *  Kuking.pl — ta sama rodzina baz jednorazowych, co w `bezpiecznik-bazy.mjs`
 * =============================================================================
 *
 *  PO CO TO JEST
 *  Skrypty pomiarowe (`scripts/*.mjs`) przepuszczają nazwę bazy przez
 *  `scripts/bezpiecznik-bazy.mjs`, który rozpoznaje RODZINY nazw — między
 *  innymi `…_pomiar`. Fixture'y PHP, które te skrypty wołają, miały wpisane
 *  osobne listy DOSŁOWNYCH nazw (`kuking_a11y`, `kuking_proof431`, …).
 *
 *  Dwie listy trzymane osobno rozjeżdżają się przy pierwszej zmianie i tak
 *  właśnie się stało: gdy job `dostepnosc` w `.github/workflows/ci.yml` dostał
 *  własne bazy (`kuking_kafel_pomiar`, `kuking_focus_pomiar`,
 *  `kuking_widok_pomiar`, #736), bezpiecznik po stronie `.mjs` je przyjął,
 *  a fixture PHP odmówił:
 *
 *      Error: Command failed: php scripts/fixtures/karuzela-mieszana.php
 *      Karuzela431 wymaga izolowanej lokalnej bazy pomiarowej […]
 *
 *  Dlatego reguła stoi tu RAZ, a fixture'y ją wołają. Zgodność obu stron
 *  pilnuje `tests/Feature/FixturePomiarowyPrzyjmujeBazyZCiTest.php`.
 *
 *  REGUŁA (dosłownie ta z `bezpiecznik-bazy.mjs`)
 *  Wpuszczamy jednorazową bazę pomiarową: nazwę kończącą się na `_pomiar`
 *  albo zaczynającą się od `kuking_qa_`. ODMOWA jest silniejsza od zgody, więc
 *  rodziny chronione (`kuking`, `kuking_test*`, `kuking_race*`, próby kopii,
 *  `railway*`, bazy systemowe) nie przejdą, nawet gdyby pasowały do wzorca —
 *  `kuking_test_pomiar` też nie.
 *
 *  Nazwa nierozpoznana to ODMOWA, nie zgoda: koszt pomyłki jest niesymetryczny.
 *  Fixture robi `migrate:fresh --seed` albo sieje dane, więc zgoda na cudzą
 *  bazę kosztuje czyjś dzień pracy i jest nieodwracalna.
 */

/** Rodziny nazw, których żaden fixture pomiarowy nie tknie. */
const KUKING_RODZINY_CHRONIONE = [
    '/^kuking$/',
    '/^kuking_test(_|$)/',
    '/^kuking_race(_|$)/',
    '/^proba_wycofania(_|$)/',
    '/^proba_odtworzenia(_|$)/',
    '/^kuking_zrodlo_proby(_|$)/',
    '/^railway/',
    '/^(postgres|template0|template1)$/',
];

/**
 * Ogólna rodzina baz jednorazowych, niezwiązana z konkretnym skryptem.
 *
 * `kuking_port_*` to rodzina baz portowych z `ci.yml` (`kuking_port_pomiar`,
 * `kuking_port_panel`, `kuking_port_referrer`). Sam `kuking_port_pomiar`
 * przechodził już przez `/_pomiar$/`, ale job `dostepnosc` podaje krokowi
 * axe-core także `kuking_port_referrer` (#1052), który na `_pomiar` się nie
 * kończy — i strażnik odrzucał go razem z całą rodziną. Wpisujemy tu RODZINĘ,
 * nie pojedynczą nazwę, bo następna baza portowa miałaby dokładnie ten sam
 * problem. Rodziny chronione są sprawdzane WCZEŚNIEJ, więc ten wzorzec nie
 * może przepuścić ani `kuking`, ani `kuking_test*`, ani `railway*`.
 */
const KUKING_RODZINY_JEDNORAZOWE = ['/_pomiar$/', '/^kuking_qa_/', '/^kuking_port_/'];

/**
 * Czy na tej bazie wolno postawić dane pomiarowe.
 *
 * PostgreSQL składa identyfikator bez cudzysłowu do małych liter, więc
 * porównujemy po `strtolower()` — inaczej `KUKING_TEST` omijałby całą kontrolę.
 */
function kukingJestBazaPomiarowa(string $baza): bool
{
    $porownywana = strtolower(trim($baza));

    if (! preg_match('/^[a-z][a-z0-9_]*$/', $porownywana)) {
        return false;
    }

    foreach (KUKING_RODZINY_CHRONIONE as $wzor) {
        if (preg_match($wzor, $porownywana)) {
            return false;
        }
    }

    foreach (KUKING_RODZINY_JEDNORAZOWE as $wzor) {
        if (preg_match($wzor, $porownywana)) {
            return true;
        }
    }

    return false;
}

/**
 * Bazy nazwane wprost przez pojedyncze fixture'y — zostają obok rodziny,
 * bo nie kończą się na `_pomiar`, a są używane od dawna.
 *
 * `kuking_test` przechodzi wyłącznie pod GitHub Actions: tam baza jest
 * jednorazowa (kontener `postgres:18-alpine` na czas joba), a na maszynie
 * człowieka to jest baza testowa kopii roboczej, której nie wolno skasować.
 */
function kukingWolnoUzycBazyFixture(string $baza, array $nazwaneWprost = []): bool
{
    if (in_array($baza, $nazwaneWprost, true)) {
        return true;
    }

    if ($baza === 'kuking_test' && getenv('GITHUB_ACTIONS') === 'true') {
        return true;
    }

    return kukingJestBazaPomiarowa($baza);
}
