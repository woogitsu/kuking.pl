import test from 'node:test';
import assert from 'node:assert/strict';
import { aktywnyTag, licznikWpisow, nastepnaOpcja } from './tagi-w-opisie.js';

test('Granice zgodne z InlineTagTokens; bez URL, email i części niepoprawnego tokenu', () => {
    for (const text of ['#sernik', 'Dziś #sernik', '(#sernik', '„#sernik']) assert.equal(aktywnyTag(text, text.length)?.query, 'sernik');
    for (const text of ['https://x/#sernik', 'a#sernik', 'x@#sernik', '##sernik', '#ser_nik']) assert.equal(aktywnyTag(text, text.length), null);
    assert.deepEqual(aktywnyTag('Dziś #sernik dalej', 9), { from: 5, to: 12, query: 'ser' });
    assert.equal(aktywnyTag('#sernik', 2, 4), null);
    assert.equal(aktywnyTag('#z\u0307urek', 7)?.query, 'żurek');
});
test('Licznik oznacza publiczne wpisy i odmienia polskie liczby', () => {
    for (const [n, expected] of [[0,'0 publicznych wpisów'],[1,'1 publiczny wpis'],[2,'2 publiczne wpisy'],[12,'12 publicznych wpisów'],[22,'22 publiczne wpisy'],[112,'112 publicznych wpisów']]) assert.equal(licznikWpisow(n), expected);
    assert.equal(licznikWpisow(-1), null);
    assert.equal(licznikWpisow('nie liczba'), null);
});
test('Pierwsza strzałka w górę wybiera ostatnią, w dół pierwszą; potem zawijanie', () => {
    assert.equal(nastepnaOpcja(-1, 4, 'ArrowUp'), 3);
    assert.equal(nastepnaOpcja(-1, 4, 'ArrowDown'), 0);
    assert.equal(nastepnaOpcja(0, 4, 'ArrowUp'), 3);
    assert.equal(nastepnaOpcja(3, 4, 'ArrowDown'), 0);
    assert.equal(nastepnaOpcja(-1, 0, 'ArrowUp'), -1);
});