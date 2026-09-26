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
