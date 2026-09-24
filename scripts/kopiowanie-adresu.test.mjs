import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../resources/js/app.js', import.meta.url), 'utf8');
const start = source.indexOf("for (const blok of document.querySelectorAll('[data-podziel-sie]')) {");
const end = source.indexOf('\n/* ===', start);
assert.ok(start > 0 && end > start, 'Nie znaleziono całego handlera udostępniania.');
const handler = source.slice(start, end);

for (const mode of ['schowek', 'stara metoda', 'odmowa']) {
    test(`kopiowanie: ${mode}`, async () => {
        let click;
        let selected = false;
        const field = {value: 'https://kuking.pl/wpisy/proba', focus() {}, select() { selected = true; }};
        const echo = {textContent: ''};
        const button = {hidden: true, addEventListener(name, fn) { if (name === 'click') click = fn; }};
        const block = {dataset: {}, querySelector(selector) {
            return {'[data-podziel-pole]': field, '[data-podziel-kopiuj]': button, '[data-podziel-echo]': echo}[selector] ?? null;
        }};
        vm.runInNewContext(handler, {
            navigator: {clipboard: {async writeText(value) { assert.equal(value, field.value); if (mode !== 'schowek') throw new Error('Odmowa'); }}},
            document: {querySelectorAll: () => [block], execCommand: () => mode === 'stara metoda'},
        });
        assert.equal(button.hidden, false);
        assert.equal(typeof click, 'function');
        await click();
        if (mode === 'odmowa') {
            assert.equal(selected, true);
            assert.match(echo.textContent, /dotknij|przytrzymaj/i, 'BRAK_INSTRUKCJI_DOTYKU');
            assert.match(echo.textContent, /Kopiuj/);
            assert.match(echo.textContent, /Ctrl\+C/);
            assert.match(echo.textContent, /Cmd\+C/);
        } else assert.equal(echo.textContent, 'Skopiowano adres.');
    });
}
