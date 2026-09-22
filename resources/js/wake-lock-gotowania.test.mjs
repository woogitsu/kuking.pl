import test from 'node:test';
import assert from 'node:assert/strict';
import {utworzKontrolerWakeLock} from './wake-lock-gotowania.js';

/** Fałszywa blokada, którą test zwalnia ręcznie -- symulacja przeglądarki. */
function utworzFalszywaBlokade() {
    let naZwolnienie = null;

    return {
        sentinel: {
            addEventListener(event, cb) {
                if (event === 'release') naZwolnienie = cb;
            },
            release() {
                naZwolnienie?.();

                return Promise.resolve();
            },
        },
        zwolnijAutomatycznie() {
            naZwolnienie?.();
        },
    };
}

test('wlacz() ustawia stan aktywny i wywoluje naZmianeStanu(true)', async () => {
    const {sentinel} = utworzFalszywaBlokade();
    const stany = [];
    const kontroler = utworzKontrolerWakeLock(() => Promise.resolve(sentinel), (aktywna) => stany.push(aktywna));

    await kontroler.wlacz();

    assert.equal(kontroler.jestAktywna(), true);
    assert.deepEqual(stany, [true]);
});

test('automatyczne zwolnienie blokady zeruje stan, zeby dalo sie ja odzyskac (issue #739)', async () => {
    const {sentinel, zwolnijAutomatycznie} = utworzFalszywaBlokade();
    const stany = [];
    const kontroler = utworzKontrolerWakeLock(() => Promise.resolve(sentinel), (aktywna) => stany.push(aktywna));

    await kontroler.wlacz();
    assert.equal(kontroler.jestAktywna(), true);

    // Przegladarka SAMA zwalnia blokade (np. zmiana karty) -- to jest
    // dokladnie ten scenariusz, ktory wczesniej NIE zerowal stanu.
    zwolnijAutomatycznie();

    // KLUCZOWY DOWOD: po automatycznym zwolnieniu jestAktywna() musi wrocic
    // do false, zeby logika "wroc na karte i odzyskaj blokade" (w app.js,
    // warunek na podstawie jestAktywna()) w ogole miala szanse zadzialac.
    // Przed poprawka ten test bylby falszywy: stara zmienna nigdy nie
    // wracala do null po zdarzeniu release.
    assert.equal(kontroler.jestAktywna(), false);
    assert.deepEqual(stany, [true, false]);

    // Odzyskanie po powrocie na karte dziala tak samo jak pierwsze wlaczenie.
    await kontroler.wlacz();
    assert.equal(kontroler.jestAktywna(), true);
});

test('wylacz() zwalnia blokade i zgłasza stan nieaktywny', async () => {
    const {sentinel} = utworzFalszywaBlokade();
    const stany = [];
    const kontroler = utworzKontrolerWakeLock(() => Promise.resolve(sentinel), (aktywna) => stany.push(aktywna));

    await kontroler.wlacz();
    await kontroler.wylacz();

    assert.equal(kontroler.jestAktywna(), false);
    assert.deepEqual(stany, [true, false]);
});

test('awaria request() (np. oszczedzanie baterii) nie zostawia stanu aktywnego', async () => {
    const stany = [];
    const kontroler = utworzKontrolerWakeLock(() => Promise.reject(new Error('odmowa')), (aktywna) => stany.push(aktywna));

    const wynik = await kontroler.wlacz();

    assert.equal(wynik, false);
    assert.equal(kontroler.jestAktywna(), false);
    assert.deepEqual(stany, [false]);
});
