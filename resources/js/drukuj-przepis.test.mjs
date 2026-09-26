import test from 'node:test';
import assert from 'node:assert/strict';
import { czyDrukowac } from './drukuj-przepis.js';

const okno = { print() {} };
const przycisk = { closest: (s) => (s === '[data-drukuj-przepis]' ? {} : null) };
const inny = { closest: () => null };

test('Zwykłe kliknięcie w „Drukuj przepis” otwiera okno drukowania', () => {
    assert.equal(czyDrukowac({ target: przycisk, button: 0 }, okno), true);
});

test('Kliknięcie gdzie indziej nie drukuje (kontrola ujemna)', () => {
    assert.equal(czyDrukowac({ target: inny, button: 0 }, okno), false);
});

test('Ctrl/Cmd+klik i środkowy przycisk zostają przeglądarce', () => {
    assert.equal(czyDrukowac({ target: przycisk, button: 0, ctrlKey: true }, okno), false);
    assert.equal(czyDrukowac({ target: przycisk, button: 0, metaKey: true }, okno), false);
    assert.equal(czyDrukowac({ target: przycisk, button: 1 }, okno), false);
});

test('Bez window.print() odnośnik działa jak zwykły link do instrukcji', () => {
    assert.equal(czyDrukowac({ target: przycisk, button: 0 }, {}), false);
});
