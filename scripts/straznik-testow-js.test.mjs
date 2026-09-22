/*
 * =============================================================================
 *  STRAŻNIK: nic nowego w JS nie wypada poza zasięg CI
 * =============================================================================
 *
 *  PO CO TO JEST
 *  CI odpala `node --test` wyłącznie przez ZAMKNIĘTĄ LISTĘ w `package.json`
 *  → skrypt `build`. Lista nie ma żadnej kontroli: plik `*.test.mjs`, którego
 *  ktoś do niej nie dopisał, istnieje w repozytorium, wygląda na test i NIGDY
 *  nie zostaje wykonany. Tak uzbierało się sześć sierot na samym `main`
 *  i dwanaście dalszych po gałęziach — dwie z nich meldowały w swoim raporcie,
 *  że test „jest w CI".
 *
 *  Ta sama dziura jest po drugiej stronie: `resources/js/app.js` to jedyny
 *  punkt wejścia, który Vite wnosi na stronę. Usunięcie stamtąd importu NIE
 *  ZAPALA NICZEGO — `npm run build` wychodzi zerem, testy modułów świecą
 *  zielono (bo importują swój moduł same, wprost), a w przeglądarce leci
 *  ReferenceError. Moduł bez importu jest martwy dokładnie tak samo jak test
 *  poza listą.
 *
 *  CZEGO TEN STRAŻNIK NIE ROBI
 *  Nie sprawdza, czy test jest dobry, ani czy moduł jest używany. Sprawdza
 *  wyłącznie, czy JEST URUCHAMIANY i czy JEST WCZYTYWANY.
 *
 *  KONTROLA DODATNIA JEST W ŚRODKU (AGENTS.md §10). Skan, który nie znajduje
 *  żadnego pliku, przechodzi — więc obie reguły są tu wykonywane najpierw na
 *  podłożonym drzewie z celowo zepsutym plikiem i muszą na nim ZAPALIĆ.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const korzen = fileURLToPath(new URL('..', import.meta.url));

/* Katalogi, w których test `*.test.mjs` MUSI być objęty listą `build`. */
const SKANOWANE = ['resources/js', 'scripts', 'scripts/fixtures'];

// --- Czyste reguły ---------------------------------------------------------
// Wydzielone z odczytu dysku wyłącznie po to, żeby dało się je wykonać na
// podłożonym drzewie w kontroli dodatniej. Produkcyjne wywołanie niżej używa
// dokładnie tych samych funkcji, nie ich kopii.

/** Wyłuskuje z polecenia `build` listę plików podanych `node --test`. */
export function testyZListy(build) {
    const od = build.indexOf('node --test ');

    assert.ok(od >= 0, 'Skrypt `build` przestał wołać `node --test` — strażnik nie ma czego pilnować.');

    const ogon = build.slice(od + 'node --test '.length);
    const koniec = ogon.indexOf(' && ');

    return (koniec >= 0 ? ogon.slice(0, koniec) : ogon).trim().split(/\s+/);
}

/** Pliki `*.test.mjs` obecne na dysku, a nieobecne na liście. */
export function sierotyTestow(naDysku, naLiscie) {
    const lista = new Set(naLiscie);

    return naDysku.filter((plik) => ! lista.has(plik)).sort();
}

/** Moduły `resources/js/*.js`, których `app.js` nie importuje. */
export function modulyBezImportu(moduly, appJs) {
    const importowane = new Set(
        // Obie formy liczą się jako wczytanie modułu: import dla samego skutku
        // ubocznego (`import './x.js';`) i import z wiązaniami
        // (`import {a, b} from './x.js';`, `import x from './x.js';`).
        // Regexp bez członu `… from` widział tylko tę pierwszą i meldował
        // martwy moduł tam, gdzie app.js wprost coś z niego bierze.
        [...appJs.matchAll(/^\s*import\s+(?:[^'"]*?\sfrom\s+)?['"]\.\/([^'"]+)['"];/gm)].map((m) => m[1]),
    );

    return moduly.filter((plik) => plik !== 'app.js' && ! importowane.has(plik)).sort();
}

// --- KONTROLA DODATNIA -----------------------------------------------------

test('kontrola dodatnia: reguła list testów ZAPALA na podłożonej sierocie', () => {
    const build = 'node scripts/kontrast-marki.mjs && node --test scripts/a.test.mjs && vite build';

    assert.deepEqual(testyZListy(build), ['scripts/a.test.mjs']);
    // Podłożony plik spoza listy MUSI zostać wskazany...
    assert.deepEqual(
        sierotyTestow(['scripts/a.test.mjs', 'scripts/sierota.test.mjs'], testyZListy(build)),
        ['scripts/sierota.test.mjs'],
    );
    // ...a komplet MUSI przejść, inaczej strażnik zapala zawsze i nic nie znaczy.
    assert.deepEqual(sierotyTestow(['scripts/a.test.mjs'], testyZListy(build)), []);
});

test('kontrola dodatnia: reguła importów ZAPALA na module wyrzuconym z app.js', () => {
    const app = "import './jest.js';\n";

    assert.deepEqual(modulyBezImportu(['app.js', 'jest.js', 'niema.js'], app), ['niema.js']);
    assert.deepEqual(modulyBezImportu(['app.js', 'jest.js'], app), []);

    // Import z wiązaniami liczy się tak samo — inaczej strażnik meldowałby
    // martwy moduł tam, gdzie app.js wprost coś z niego bierze, a człowiek
    // „naprawiałby" to dopisywaniem drugiego, zbędnego importu.
    const zWiazaniami = "import {a} from './nazwany.js';\nimport domyslny from './domyslny.js';\n";

    assert.deepEqual(
        modulyBezImportu(['app.js', 'nazwany.js', 'domyslny.js', 'niema.js'], zWiazaniami),
        ['niema.js'],
    );
});

// --- POMIAR NA PRAWDZIWYM DRZEWIE -----------------------------------------

test('każdy plik *.test.mjs jest na liście `build`', () => {
    const build = JSON.parse(readFileSync(join(korzen, 'package.json'), 'utf8')).scripts.build;

    const naDysku = SKANOWANE.flatMap((katalog) =>
        readdirSync(join(korzen, katalog), { withFileTypes: true })
            .filter((wpis) => wpis.isFile() && wpis.name.endsWith('.test.mjs'))
            .map((wpis) => `${katalog}/${wpis.name}`));

    // Skan, który nic nie znalazł, przechodzi — a nie powinien niczego dowodzić.
    assert.ok(naDysku.length >= 10, `Skan znalazł tylko ${naDysku.length} testów — to sam skan jest zepsuty.`);

    assert.deepEqual(
        sierotyTestow(naDysku, testyZListy(build)),
        [],
        'Te pliki *.test.mjs NIE są uruchamiane przez CI. Dopisz je do `build` w package.json '
        + 'albo usuń — plik, który wygląda na test i nigdy nie biegnie, jest gorszy niż jego brak.',
    );
});

test('app.js importuje każdy moduł z resources/js', () => {
    const katalog = join(korzen, 'resources/js');
    const moduly = readdirSync(katalog, { withFileTypes: true })
        .filter((wpis) => wpis.isFile() && wpis.name.endsWith('.js'))
        .map((wpis) => wpis.name);

    assert.ok(moduly.length >= 5, `Skan znalazł tylko ${moduly.length} modułów — to sam skan jest zepsuty.`);

    assert.deepEqual(
        modulyBezImportu(moduly, readFileSync(join(katalog, 'app.js'), 'utf8')),
        [],
        'Te moduły nie są importowane w app.js, więc NIE trafiają na stronę. Vite zbuduje się '
        + 'zielono, testy modułu też — a w przeglądarce poleci ReferenceError.',
    );
});
