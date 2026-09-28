import test from 'node:test';
import assert from 'node:assert/strict';
import { czyPolaZmienione, czyFormularzZmieniony } from './niezapisane-zmiany.js';

const tekst = (value, defaultValue = value) => ({ type: 'text', value, defaultValue });
const formularz = (elements, dataset = {}) => ({ elements, dataset });

test('Niezmieniony formularz nie pyta (issue #899: bez bezwarunkowego dialogu)', () => {
    assert.equal(czyPolaZmienione([
        tekst('Rosół'),
        { type: 'textarea', value: 'Zagotuj wodę.', defaultValue: 'Zagotuj wodę.' },
        { type: 'checkbox', checked: true, defaultChecked: true },
        { type: 'select-one', options: [{ selected: true, defaultSelected: true }, { selected: false, defaultSelected: false }] },
        { type: 'file', files: [] },
    ]), false);
});

test('Wpisany tekst, zaznaczenie, wybór z listy i wybrany plik liczą się jako zmiana', () => {
    assert.equal(czyPolaZmienione([tekst('Rosół babci', 'Rosół')]), true);
    assert.equal(czyPolaZmienione([{ type: 'textarea', value: 'x', defaultValue: '' }]), true);
    assert.equal(czyPolaZmienione([{ type: 'checkbox', checked: false, defaultChecked: true }]), true);
    assert.equal(czyPolaZmienione([{ type: 'radio', checked: true, defaultChecked: false }]), true);
    assert.equal(czyPolaZmienione([{ type: 'select-one', options: [{ selected: false, defaultSelected: true }, { selected: true, defaultSelected: false }] }]), true);
    assert.equal(czyPolaZmienione([{ type: 'file', files: [{ name: 'zupa.jpg' }] }]), true);
});

test('Pola ukryte, przyciski i wyłączone pola nie są zmianą pracy człowieka', () => {
    assert.equal(czyPolaZmienione([
        { type: 'hidden', value: 'nowy-klucz', defaultValue: 'stary' },
        { type: 'submit', value: 'publish', defaultValue: '' },
        { type: 'text', value: 'x', defaultValue: '', disabled: true },
    ]), false);
});

test('Formularz odesłany z błędami pyta zawsze — dane z old() nie są jeszcze w bazie', () => {
    assert.equal(czyFormularzZmieniony(formularz([tekst('Rosół')], { niezapisaneOdSerwera: '' })), true);
    assert.equal(czyFormularzZmieniony(formularz([tekst('Rosół')])), false);
});

test('Lista bez opcji `selected` w HTML-u nie jest zmianą, dopóki nikt nie wybierze innej', () => {
    // Przeglądarka pokazuje wtedy pierwszą dostępną opcję: selected=true,
    // ale defaultSelected=false. Porównanie opcja po opcji uznałoby to za zmianę.
    const lista = (wybrana, opcje = [{}, {}, {}]) => ({
        type: 'select-one',
        options: opcje.map((o, i) => ({ defaultSelected: false, disabled: false, ...o, selected: i === wybrana })),
    });
    assert.equal(czyPolaZmienione([lista(0)]), false);
    assert.equal(czyPolaZmienione([lista(2)]), true);
    // Pierwsza opcja wyłączona — przeglądarka zaczyna od pierwszej dostępnej.
    assert.equal(czyPolaZmienione([lista(1, [{ disabled: true }, {}, {}])]), false);
    // Jawne `selected` nadal wygrywa z pierwszą opcją.
    assert.equal(czyPolaZmienione([lista(1, [{}, { defaultSelected: true }, {}])]), false);
    assert.equal(czyPolaZmienione([lista(0, [{}, { defaultSelected: true }, {}])]), true);
});
