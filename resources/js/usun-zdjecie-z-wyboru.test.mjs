/*
 * Issue #884 — usunięcie jednego nowego zdjęcia z wyboru przed wysłaniem.
 *
 * Testuje czyste funkcje modułu na atrapie `DataTransfer` (Node jej nie ma).
 * To NIE jest dowód na prawdziwy `FileList` przeglądarki — ten sprawdza się
 * w Chromium osobno (opis w PR). Tu mierzymy regułę: po usunięciu pole ma
 * dokładnie pozostałe pliki w tej samej kolejności, a gdy przeglądarka nie
 * pozwala podmienić listy, funkcja NIE melduje sukcesu.
 *
 *   node --test resources/js/usun-zdjecie-z-wyboru.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { komunikatWyboru, moznaUsuwacZWyboru, usunPlikZWyboru } from './usun-zdjecie-z-wyboru.js';

class AtrapaDataTransfer {
    constructor() {
        const pliki = [];
        this.files = pliki;
        this.items = { add: (plik) => pliki.push(plik) };
    }
}

const plik = (name) => ({ name, size: name.length, lastModified: 1, type: 'image/jpeg' });

test('usunięcie środkowego z A/B/C zostawia w polu dokładnie A i C, w tej kolejności', () => {
    const [a, b, c] = [plik('a.jpg'), plik('b.jpg'), plik('c.jpg')];
    const input = { files: [a, b, c] };

    assert.equal(usunPlikZWyboru(input, 1, AtrapaDataTransfer), true);
    assert.deepEqual(input.files.map((p) => p.name), ['a.jpg', 'c.jpg']);
    assert.ok(!input.files.includes(b));
});

test('usunięcie ostatniego pliku zostawia puste pole', () => {
    const input = { files: [plik('a.jpg')] };

    assert.equal(usunPlikZWyboru(input, 0, AtrapaDataTransfer), true);
    assert.equal(input.files.length, 0);
});

test('bez DataTransfer (stara przeglądarka) nic się nie zmienia i nie ma „sukcesu”', () => {
    const pliki = [plik('a.jpg'), plik('b.jpg')];
    const input = { files: pliki };

    assert.equal(moznaUsuwacZWyboru(undefined), false);
    assert.equal(usunPlikZWyboru(input, 0, undefined), false);
    assert.equal(input.files, pliki);
});

test('przeglądarka, która po cichu ignoruje podstawienie listy, NIE melduje sukcesu', () => {
    const pliki = [plik('a.jpg'), plik('b.jpg')];
    const input = {};
    Object.defineProperty(input, 'files', { get: () => pliki, set: () => {} });

    assert.equal(usunPlikZWyboru(input, 0, AtrapaDataTransfer), false);
    assert.equal(input.files.length, 2);
});

test('numer spoza listy niczego nie usuwa', () => {
    const pliki = [plik('a.jpg')];
    const input = { files: pliki };

    assert.equal(usunPlikZWyboru(input, 3, AtrapaDataTransfer), false);
    assert.equal(usunPlikZWyboru(input, -1, AtrapaDataTransfer), false);
    assert.equal(input.files, pliki);
});

test('licznik odmienia „zdjęcie” poprawnie (dotąd było „Wybrano 7 zdjęcia.”)', () => {
    assert.equal(komunikatWyboru(1), 'Wybrano 1 zdjęcie.');
    assert.equal(komunikatWyboru(3), 'Wybrano 3 zdjęcia.');
    assert.equal(komunikatWyboru(7), 'Wybrano 7 zdjęć.');
    assert.equal(komunikatWyboru(12), 'Wybrano 12 zdjęć.');
    assert.equal(komunikatWyboru(22), 'Wybrano 22 zdjęcia.');
});
