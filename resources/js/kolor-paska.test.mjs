import test from 'node:test';
import assert from 'node:assert/strict';
import {ustawKolorPaska} from './kolor-paska.js';

function dokumentZMeta(meta) {
    return {querySelector: (selektor) => (selektor === 'meta[name="theme-color"]' ? meta : null)};
}

function meta(content) {
    return {
        dataset: {jasny: '#F3F4F1', ciemny: '#151714'},
        atrybuty: {content},
        setAttribute(nazwa, wartosc) { this.atrybuty[nazwa] = wartosc; },
    };
}

test('ciemny motyw daje ciemny pasek, jasny — jasny', () => {
    const m = meta('#F3F4F1');
    ustawKolorPaska(dokumentZMeta(m), 'dark');
    assert.equal(m.atrybuty.content, '#151714');
    ustawKolorPaska(dokumentZMeta(m), 'light');
    assert.equal(m.atrybuty.content, '#F3F4F1');
});

test('nieznana wartość motywu to jasny, jak domyślny motyw serwisu (D-019)', () => {
    const m = meta('#151714');
    ustawKolorPaska(dokumentZMeta(m), 'cokolwiek');
    assert.equal(m.atrybuty.content, '#F3F4F1');
});

test('strona bez znacznika i znacznik bez kolorów nic nie psują', () => {
    assert.doesNotThrow(() => ustawKolorPaska(dokumentZMeta(null), 'dark'));
    const m = {dataset: {}, atrybuty: {content: '#F3F4F1'}, setAttribute() { throw new Error('nie powinno'); }};
    assert.doesNotThrow(() => ustawKolorPaska(dokumentZMeta(m), 'dark'));
});
