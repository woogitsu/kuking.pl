import test from 'node:test';
import assert from 'node:assert/strict';

const modul = process.env.KUKING_SKLADNIKI_HTTP_SERVER
    ?? new URL('./skladniki-gotowania-server.mjs', import.meta.url).href;
const { utworzSerwer } = await import(modul);
const serwer = utworzSerwer({
    strona: (_skladniki, _krok, porcje) => `<html><body><div data-przygotowanie-porcje="${porcje}"></div></body></html>`,
    skladniki: () => [],
    modul: '',
    css: '',
    zadaniaPost: [],
});
await new Promise((resolve) => serwer.listen(0, '127.0.0.1', resolve));
const adres = `http://127.0.0.1:${serwer.address().port}`;

test.after(async () => {
    await new Promise((resolve) => serwer.close(resolve));
});

test('fixture HTTP zachowuje poprawne porcje 4 i 8', async () => {
    for (const porcje of [4, 8]) {
        const odpowiedz = await fetch(`${adres}/?porcje=${porcje}`);
        assert.equal(odpowiedz.status, 200);
        assert.match(await odpowiedz.text(), new RegExp(`data-przygotowanie-porcje="${porcje}"`));
    }
});

test('fixture HTTP odmawia odbicia HTML w liczbie porcji', async () => {
    const payload = '4" onmouseover="alert(1)';
    const odpowiedz = await fetch(`${adres}/?porcje=${encodeURIComponent(payload)}`);

    assert.equal(odpowiedz.status, 400, 'FIXTURE_2596_ODMAWIA_HTML');
    assert.doesNotMatch(await odpowiedz.text(), /onmouseover|alert\(1\)/);
});
