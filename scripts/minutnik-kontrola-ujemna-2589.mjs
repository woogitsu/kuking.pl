/* Fizyczna kontrola #2589: cofnięcie tożsamości do dawnego numeru musi oblać. */
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve, sep} from 'node:path';

const katalog = mkdtempSync(join(tmpdir(), 'kuking-minutnik-2589-'));
try {
    const zrodlo = readFileSync('resources/js/minutnik-krok.js', 'utf8');
    const kotwica = 'return indeks + 1;';
    assert.equal(zrodlo.split(kotwica).length, 2, 'Brak dokładnej kotwicy mutacji tożsamości kroku');
    writeFileSync(join(katalog, 'minutnik-krok.mjs'), zrodlo.replace(kotwica, 'return stan.krokPierwotny;'));
    const test = readFileSync('resources/js/minutnik-krok.test.mjs', 'utf8');
    writeFileSync(join(katalog, 'minutnik-krok.test.mjs'), test.replace("'./minutnik-krok.js'", "'./minutnik-krok.mjs'"));

    const wynik = spawnSync(process.execPath,
        ['--test', '--test-name-pattern=2589: edycja', join(katalog, 'minutnik-krok.test.mjs')],
        {encoding: 'utf8'});
    const tekst = `${wynik.stdout ?? ''}\n${wynik.stderr ?? ''}`;
    assert.equal(wynik.status, 1, `Mutant nie oblał jednego testu:\n${tekst}`);
    assert.match(tekst, /TIMER_2589_ZAMIANA_KROKOW/, `Mutant oblał z innej przyczyny:\n${tekst}`);
    assert.match(tekst, /(?:#|ℹ) fail 1\b/, `Mutant oblał więcej niż jeden test:\n${tekst}`);
    process.stdout.write('Kontrola ujemna #2589: dokładnie 1 oczekiwany FAIL przy powrocie do numeru kroku.\n');
} finally {
    const bazowy = resolve(tmpdir()) + sep;
    assert(resolve(katalog).startsWith(bazowy), 'Katalog kontroli musi należeć do systemowego tmp');
    rmSync(katalog, {recursive: true, force: true});
}
