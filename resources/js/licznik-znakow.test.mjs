import test from 'node:test';
import assert from 'node:assert/strict';
import { komunikatLicznika, dlugoscWZnakach } from './licznik-znakow.js';

test('Pod limitem: mówi ile zostało, z poprawną polską odmianą', () => {
    assert.equal(komunikatLicznika(0, 4000), 'Zostało 4000 znaków z 4000.');
    assert.equal(komunikatLicznika(3999, 4000), 'Zostało 1 znak z 4000.');
    assert.equal(komunikatLicznika(3998, 4000), 'Zostało 2 znaki z 4000.');
    assert.equal(komunikatLicznika(3996, 4000), 'Zostało 4 znaki z 4000.');
    assert.equal(komunikatLicznika(3990, 4000), 'Zostało 10 znaków z 4000.');
});

test('Dokładnie na limicie: zero zostało, nie "za długo"', () => {
    assert.equal(komunikatLicznika(4000, 4000), 'Zostało 0 znaków z 4000.');
});

test('Nad limitem: mówi o ile skrócić, nie tylko "za długo" (AGENTS.md, błąd mówi co zrobić)', () => {
    assert.equal(komunikatLicznika(4001, 4000), 'Tekst jest za długi o 1 znak. Skróć go o tyle, żeby wysłać.');
    assert.equal(komunikatLicznika(4002, 4000), 'Tekst jest za długi o 2 znaki. Skróć go o tyle, żeby wysłać.');
    assert.equal(komunikatLicznika(4014, 4000), 'Tekst jest za długi o 14 znaków. Skróć go o tyle, żeby wysłać.');
});

test('Długość liczy punkty kodowe, nie jednostki UTF-16 (emoji spoza BMP liczy się raz)', () => {
    // 😀 (U+1F600) zajmuje DWIE jednostki UTF-16, ale to jeden znak.
    assert.equal('😀'.length, 2);
    assert.equal(dlugoscWZnakach('😀'), 1);
    assert.equal(dlugoscWZnakach('Zażółć gęślą jaźń 😀😀😀'), 21);
});
