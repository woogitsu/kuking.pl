/**
 * =============================================================================
 *  Testy bezpiecznika bazy pomiarowej (`scripts/bezpiecznik-bazy.mjs`, #736)
 * =============================================================================
 *
 *  CO TU JEST SPRAWDZANE
 *  Zachowanie, nie kształt komunikatów: co bezpiecznik WPUSZCZA, a co ODRZUCA.
 *  Sedno jest w dwóch zdaniach:
 *
 *    1. nazwa z rodziny chronionej NIGDY nie przechodzi — także wtedy, gdy
 *       wygląda jak jednorazowa (`kuking_test_pomiar`),
 *    2. nazwa NIEROZPOZNANA jest ODMOWĄ, a nie zgodą.
 *
 *  Drugie zdanie jest tym, czego nie miała poprzednia wersja: lista
 *  `BAZY_ZAKAZANE = ['kuking', 'kuking_test']` przepuszczała wszystko, czego
 *  na niej nie było — a po #736 żadna kopia robocza nie nazywa się już
 *  `kuking_test`, więc nie trafiała nigdy.
 *
 *  Uruchomienie:  node --test scripts/bezpiecznik-bazy.test.mjs
 *  Do `php artisan test` wciąga ten plik `tests/Feature/BezpiecznikBazyPomiarowejTest.php`.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { ustalBazePomiarowa } from './bezpiecznik-bazy.mjs';

const KATALOG = dirname(fileURLToPath(import.meta.url));
const DOMYSLNA = 'kuking_kafel_pomiar';

/**
 * Usuwa komentarze przed skanem. BEZ TEGO SKAN NIE SPRAWDZA NICZEGO.
 *
 * Każdy z tych skryptów ma nad wywołaniem akapit wyjaśniający, po co jest
 * `ustalBazePomiarowa()`. Skan szukający nazwy w całym pliku był więc zielony
 * także po WYCIĘCIU wywołania — wystarczał sam komentarz o nim. Złapała to
 * kontrola ujemna: mutacja podmieniła wywołanie na atrapę, a test przeszedł.
 *
 * Zgrubność wyrażeń jest tu świadoma: gdyby zjadły za dużo (np. `/*` wewnątrz
 * literału), skan stanie się SUROWSZY, a nie łagodniejszy — czyli pomyłka
 * pójdzie w bezpieczną stronę.
 */
function bezKomentarzy(kod) {
    return kod
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/(^|[^:])\/\/.*$/gm, '$1');
}

function ustal(DB_DATABASE, dodatkowe = {}) {
    return ustalBazePomiarowa({
        domyslna: DOMYSLNA,
        skrypt: 'scripts/probny.mjs',
        srodowisko: { DB_DATABASE, ...dodatkowe },
    });
}

test('wpuszcza wlasna baze domyslna skryptu', () => {
    assert.equal(ustal(undefined), DOMYSLNA);
    assert.equal(ustal(''), DOMYSLNA);
    assert.equal(ustal('   '), DOMYSLNA);
    assert.equal(ustal(DOMYSLNA), DOMYSLNA);
});

test('wpuszcza wariant wlasnej bazy, zeby dwie osoby mogly mierzyc naraz', () => {
    assert.equal(ustal(`${DOMYSLNA}_wt_e`), `${DOMYSLNA}_wt_e`);
});

test('wpuszcza ogolna rodzine baz jednorazowych', () => {
    assert.equal(ustal('kuking_port_pomiar'), 'kuking_port_pomiar');
    assert.equal(ustal('kuking_qa_cokolwiek'), 'kuking_qa_cokolwiek');
});

/**
 * SEDNO NAPRAWY. Nazwa bazy testowej kopii roboczej zawiera od #736 skrót
 * ścieżki katalogu, więc żadna lista dosłownych łańcuchów jej nie złapie.
 * Bezpiecznik musi rozpoznawać RODZINĘ.
 */
test('odrzuca baze testowa kopii roboczej w kazdej postaci', () => {
    for (const nazwa of [
        'kuking_test',
        'kuking_test_kuking_681_tagi_3f2a9c14',
        'kuking_test_a11y',
        'KUKING_TEST_KUKING_681_TAGI_3F2A9C14',
    ]) {
        assert.throws(() => ustal(nazwa), /ODMAWIAM STARTU/, `przeszło: ${nazwa}`);
    }
});

test('odrzuca pozostale rodziny chronione', () => {
    for (const nazwa of [
        'kuking',
        'kuking_race_kuking_681_tagi_3f2a9c14',
        'proba_wycofania_cos',
        'proba_odtworzenia_test',
        'kuking_zrodlo_proby_cos',
        'railway',
        'postgres',
        'template1',
    ]) {
        assert.throws(() => ustal(nazwa), /ODMAWIAM STARTU/, `przeszło: ${nazwa}`);
    }
});

/**
 * Odmowa musi być SILNIEJSZA od zgody. Inaczej wystarczyłoby dopisać `_pomiar`
 * do nazwy bazy testowej, żeby skrypt ją skasował.
 */
test('rodzina chroniona bije rodzine jednorazowa', () => {
    assert.throws(() => ustal('kuking_test_pomiar'), /ODMAWIAM STARTU/);
    assert.throws(() => ustal('kuking_race_pomiar'), /ODMAWIAM STARTU/);
});

/**
 * TO JEST RÓŻNICA WOBEC LISTY ZAKAZÓW: nieznana nazwa nie przechodzi.
 * Nie wiemy, czyja to baza ani co w niej stoi, a za chwilę byłby na niej
 * `migrate:fresh`.
 */
test('odrzuca nazwe, ktorej nie rozpoznaje jako jednorazowej', () => {
    for (const nazwa of ['moja_baza', 'kuking_dev_wt_e', 'dane_klienta', 'kuking_a11y']) {
        assert.throws(() => ustal(nazwa), /nie rozpoznaj/, `przeszło: ${nazwa}`);
    }
});

test('odrzuca nazwe, ktora nie jest identyfikatorem PostgreSQL-a', () => {
    for (const nazwa of ['kuking pomiar', 'kuking-pomiar', '1_pomiar', "x'; DROP DATABASE y;--"]) {
        assert.throws(() => ustal(nazwa), /ODMAWIAM STARTU/, `przeszło: ${nazwa}`);
    }
});

test('odrzuca produkcje niezaleznie od nazwy bazy', () => {
    assert.throws(() => ustal(DOMYSLNA, { APP_ENV: 'production' }), /APP_ENV=production/);
    assert.throws(() => ustal(DOMYSLNA, { APP_ENV: 'PRODUCTION' }), /APP_ENV=production/);
});

/**
 * KONTROLA DODATNIA DO CAŁEGO PLIKU — i jedyna asercja, która pilnuje, żeby
 * bezpiecznik był FAKTYCZNIE WŁĄCZONY.
 *
 * Testy wyżej sprawdzają samą funkcję. Funkcja może być bez zarzutu i nikomu
 * niepotrzebna, jeśli skrypty przestaną ją wołać — a właśnie to stało się
 * poprzedniemu zabezpieczeniu. Ten test skanuje `scripts/*.mjs` i wymaga, żeby
 * każdy plik robiący `migrate:fresh` wołał `ustalBazePomiarowa()`.
 */
test('kazdy skrypt robiacy migrate:fresh wola bezpiecznik', () => {
    const destrukcyjne = readdirSync(KATALOG)
        .filter((plik) => plik.endsWith('.mjs') && plik !== 'bezpiecznik-bazy.mjs')
        .map((plik) => ({ plik, tresc: bezKomentarzy(readFileSync(join(KATALOG, plik), 'utf8')) }))
        .filter(({ tresc }) => /migrate:fresh|db:wipe|\bdropdb\b/.test(tresc));

    // Pułapka 2 z docs/PULAPKI_TESTOW.md: skan bez trafień jest zielony
    // i nic nie znaczy. Wiemy, że tych skryptów jest kilkanaście.
    assert.ok(
        destrukcyjne.length >= 15,
        `Skan znalazł tylko ${destrukcyjne.length} skryptów z migrate:fresh — `
        + 'sprawdza wtedy pustkę, nie kod.',
    );

    const bezBezpiecznika = destrukcyjne
        .filter(({ tresc }) => !tresc.includes('ustalBazePomiarowa('))
        .map(({ plik }) => plik);

    assert.deepEqual(
        bezBezpiecznika,
        [],
        'Te skrypty robią `migrate:fresh`, a nie wołają ustalBazePomiarowa() — '
        + 'czyli skasują bazę wskazaną przez DB_DATABASE bez pytania:\n  '
        + bezBezpiecznika.join('\n  '),
    );
});
