import test from 'node:test';
import assert from 'node:assert/strict';
import { przepiszKopie } from './kopia-sasiedniego-pola.js';

function kopia(zrodlo) {
    return { dataset: { kopiaZ: zrodlo }, value: '', disabled: true };
}

function formularz(...kopie) {
    return {
        querySelectorAll(selektor) {
            assert.equal(selektor, 'input[data-kopia-z]');
            return kopie;
        },
    };
}

test('Zapis stanu niesie bieżący szkic odpowiedzi i jej klucz (#845)', () => {
    const tresc = kopia('#odpowiedz-formularz [name=odpowiedz]');
    const klucz = kopia('#odpowiedz-formularz input[type=hidden][name=reply_key]');
    const zrodla = {
        '#odpowiedz-formularz [name=odpowiedz]': { value: 'Szkic 845.' },
        '#odpowiedz-formularz input[type=hidden][name=reply_key]': { value: 'klucz-1' },
    };

    przepiszKopie(formularz(tresc, klucz), (s) => zrodla[s] ?? null);

    assert.deepEqual([tresc.value, tresc.disabled], ['Szkic 845.', false]);
    assert.deepEqual([klucz.value, klucz.disabled], ['klucz-1', false]);
});

test('Brak źródła: kopia zostaje wyłączona, więc nie leci pusta', () => {
    const stan = kopia('#stan-wiadomosci [name=status]:checked');
    stan.disabled = false;

    przepiszKopie(formularz(stan), () => null);

    assert.equal(stan.disabled, true);
    assert.equal(stan.value, '');
});

test('Kopia bierze wartość z chwili wysłania, nie z chwili wczytania strony', () => {
    const notatka = kopia('#stan-wiadomosci [name=handler_note]');
    const zrodlo = { value: 'Stara.' };
    const f = formularz(notatka);

    przepiszKopie(f, () => zrodlo);
    zrodlo.value = 'Nowa notatka 845.';
    przepiszKopie(f, () => zrodlo);

    assert.equal(notatka.value, 'Nowa notatka 845.');
});
