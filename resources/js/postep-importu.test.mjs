import test from 'node:test';
import assert from 'node:assert/strict';
import { podlaczPostepImportu, CO_ILE_MS } from './postep-importu.js';

function swiat({ koncowy = false, odpowiedz = '<div data-koncowy="1">gotowe</div>' } = {}) {
    const blok = {
        innerHTML: '',
        getAttribute: () => '/import/abc?fragment=1',
        querySelector: (sel) => (sel === '[data-koncowy="1"]' && (koncowy || blok.innerHTML.includes('data-koncowy="1"')) ? {} : null),
    };
    const dokument = { querySelector: (sel) => (sel === '[data-postep-importu]' ? blok : null) };
    const zapytania = [];
    let zadanie = null;
    let zatrzymano = false;
    const okno = {
        fetch: (adres) => { zapytania.push(adres); return Promise.resolve({ ok: true, text: () => Promise.resolve(odpowiedz) }); },
        setInterval: (fn, ms) => { zadanie = { fn, ms }; return 7; },
        clearInterval: () => { zatrzymano = true; },
    };
    return { blok, dokument, okno, zapytania, zadanie: () => zadanie, zatrzymano: () => zatrzymano };
}

test('Odświeża co 5 s tylko blok postępu i zatrzymuje się na stanie końcowym', async () => {
    const s = swiat();
    assert.equal(podlaczPostepImportu(s.dokument, s.okno), 7);
    assert.equal(s.zadanie().ms, CO_ILE_MS);
    s.zadanie().fn();
    await new Promise((r) => setTimeout(r, 0));
    assert.deepEqual(s.zapytania, ['/import/abc?fragment=1']);
    assert.match(s.blok.innerHTML, /gotowe/);
    assert.equal(s.zatrzymano(), true);
});

test('Stan już końcowy — nie odświeża wcale', () => {
    const s = swiat({ koncowy: true });
    assert.equal(podlaczPostepImportu(s.dokument, s.okno), null);
    assert.equal(s.zadanie(), null);
});

test('Bez fetch — zostaje odnośnik „Sprawdź, czy już gotowe”, zero zegarów', () => {
    const s = swiat();
    delete s.okno.fetch;
    assert.equal(podlaczPostepImportu(s.dokument, s.okno), null);
});

// #2328: opóźniona starsza odpowiedź nie cofa pokazanego stanu.
function swiatRecznyFetch() {
    const blok = {
        innerHTML: '',
        getAttribute: () => '/import/abc?fragment=1',
        querySelector: (sel) => (sel === '[data-koncowy="1"]' && blok.innerHTML.includes('data-koncowy="1"') ? {} : null),
    };
    const dokument = { querySelector: (sel) => (sel === '[data-postep-importu]' ? blok : null) };
    const oczekujace = [];
    let zadanie = null;
    let zatrzymano = false;
    const okno = {
        fetch: () => new Promise((rozwiaz) => { oczekujace.push((html) => rozwiaz({ ok: true, text: () => Promise.resolve(html) })); }),
        setInterval: (fn) => { zadanie = fn; return 9; },
        clearInterval: () => { zatrzymano = true; },
    };
    return { blok, dokument, okno, oczekujace, tik: () => zadanie(), zatrzymano: () => zatrzymano };
}

const chwila = () => new Promise((r) => setTimeout(r, 0));

test('Odpowiedź A wraca po końcowej B — ekran zostaje „gotowe”', async () => {
    const s = swiatRecznyFetch();
    podlaczPostepImportu(s.dokument, s.okno);
    s.tik(); // A
    s.tik(); // B
    s.oczekujace[1]('<div data-koncowy="1">gotowe</div>');
    await chwila();
    assert.match(s.blok.innerHTML, /gotowe/);
    assert.equal(s.zatrzymano(), true);

    s.oczekujace[0]('<div data-koncowy="0">w toku</div>');
    await chwila();
    assert.match(s.blok.innerHTML, /gotowe/, 'Opóźniona odpowiedź cofnęła stan końcowy.');

    // Po stanie końcowym zegar już nie pyta.
    s.tik();
    assert.equal(s.oczekujace.length, 2);
});

test('Starsza odpowiedź nie nadpisuje nowszego stanu pośredniego', async () => {
    const s = swiatRecznyFetch();
    podlaczPostepImportu(s.dokument, s.okno);
    s.tik();
    s.tik();
    s.oczekujace[1]('<div>krok 2</div>');
    await chwila();
    s.oczekujace[0]('<div>krok 1</div>');
    await chwila();
    assert.match(s.blok.innerHTML, /krok 2/);
});
