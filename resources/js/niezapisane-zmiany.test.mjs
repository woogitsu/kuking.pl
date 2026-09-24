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
