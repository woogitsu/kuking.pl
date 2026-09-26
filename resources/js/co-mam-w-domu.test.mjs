import test from 'node:test';
import assert from 'node:assert/strict';
import { podpowiedziDoPokazania, komunikatPodpowiedzi } from './co-mam-w-domu.js';

test('Zła odpowiedź serwera nie rysuje niczego', () => {
    assert.deepEqual(podpowiedziDoPokazania(null), []);
    assert.deepEqual(podpowiedziDoPokazania({}), []);
    assert.deepEqual(podpowiedziDoPokazania({ podpowiedzi: 'mąka' }), []);
});

test('Odpadają puste, nietekstowe i powtórzone nazwy', () => {
    assert.deepEqual(
        podpowiedziDoPokazania({ podpowiedzi: ['mąka', ' ', 7, null, 'mąka', ' jajka '] }),
        ['mąka', 'jajka'],
    );
});

test('Nie więcej niż limit', () => {
    const dane = { podpowiedzi: ['a1', 'a2', 'a3', 'a4'] };
    assert.deepEqual(podpowiedziDoPokazania(dane, 2), ['a1', 'a2']);
});

test('Komunikat dla czytnika ekranu mówi, co zrobić, gdy nic nie ma', () => {
    assert.match(komunikatPodpowiedzi(0), /Możesz dodać produkt/);
    assert.equal(komunikatPodpowiedzi(1), 'Jest 1 podpowiedź pod polem.');
    assert.equal(komunikatPodpowiedzi(3), 'Są 3 podpowiedzi pod polem.');
    assert.equal(komunikatPodpowiedzi(5), 'Jest 5 podpowiedzi pod polem.');
    assert.equal(komunikatPodpowiedzi(12), 'Jest 12 podpowiedzi pod polem.');
});
