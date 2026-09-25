import test from 'node:test';
import assert from 'node:assert/strict';
import { stanPrzelacznika } from './pokaz-haslo.js';

test('Stan początkowy: hasło zamaskowane, przycisk mówi „Pokaż hasło”', () => {
    assert.deepEqual(stanPrzelacznika(false), {
        type: 'password', napis: 'Pokaż hasło', pressed: 'false', komunikat: 'Hasło jest ukryte.',
    });
});

test('Po przełączeniu: pole jawne, przycisk mówi „Ukryj hasło” i jest wciśnięty', () => {
    assert.deepEqual(stanPrzelacznika(true), {
        type: 'text', napis: 'Ukryj hasło', pressed: 'true', komunikat: 'Hasło jest widoczne.',
    });
});
