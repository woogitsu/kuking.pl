import test from 'node:test';
import assert from 'node:assert/strict';
import {kluczWyboru, podlaczPrzelacznik, utworzKontrolerWakeLock, utworzPamiecWyboru} from './wake-lock-gotowania.js';

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

// --- Wybór przeżywa zmianę kroku (issue #1302) ------------------------------

/** `sessionStorage` jednej karty — wspólny dla kolejnych dokumentów kroków. */
function utworzPamiecKarty() {
    const dane = new Map();

    return {
        getItem: (k) => (dane.has(k) ? dane.get(k) : null),
        setItem: (k, v) => dane.set(k, String(v)),
        removeItem: (k) => dane.delete(k),
    };
}

/**
 * Jeden DOKUMENT kroku: świeży checkbox z serwera (odznaczony), świeży
 * kontroler, wspólna karta i wspólne API przeglądarki. Tak wygląda każde
 * „Następny krok” i każde „Oznacz krok jako zrobiony”.
 */
function zaladujKrok(karta, przegladarka, recipeSlug = 'bigos') {
    const nasluchy = [];
    const checkbox = {
        checked: false,
        addEventListener: (zdarzenie, cb) => zdarzenie === 'change' && nasluchy.push(cb),
    };
    const kontroler = utworzKontrolerWakeLock(przegladarka.request, (aktywna) => {
        // Ta sama zasada co w app.js: odmowa odznacza przełącznik.
        if (!aktywna) checkbox.checked = false;
    });
    const odtworzenie = podlaczPrzelacznik(checkbox, kontroler, utworzPamiecWyboru(karta, recipeSlug));

    return {
        checkbox,
        kontroler,
        odtworzenie,
        klik: async () => {
            checkbox.checked = !checkbox.checked;
            nasluchy.forEach((cb) => cb());
            await Promise.resolve();
        },
    };
}

function utworzPrzegladarke({odmawiaOd = Infinity} = {}) {
    const przegladarka = {
        zadania: 0,
        request: () => {
            przegladarka.zadania += 1;

            return przegladarka.zadania >= odmawiaOd
                ? Promise.reject(new Error('odmowa'))
                : Promise.resolve(utworzFalszywaBlokade().sentinel);
        },
    };

    return przegladarka;
}

test('wybor z kroku 1 prosi o blokade na nowo w dokumencie kroku 2 (issue #1302)', async () => {
    const karta = utworzPamiecKarty();
    const przegladarka = utworzPrzegladarke();

    const krok1 = zaladujKrok(karta, przegladarka);
    await krok1.klik();
    await krok1.odtworzenie;
    assert.equal(przegladarka.zadania, 1);

    // Nawigacja: nowy dokument, checkbox znowu odznaczony z serwera.
    const krok2 = zaladujKrok(karta, przegladarka);

    assert.equal(await krok2.odtworzenie, true);
    assert.equal(przegladarka.zadania, 2);
    assert.equal(krok2.checkbox.checked, true);
    assert.equal(krok2.kontroler.jestAktywna(), true);
});

test('odmowa na nowym kroku pokazuje prawdziwy stan, a nastepny krok probuje znowu', async () => {
    const karta = utworzPamiecKarty();
    const przegladarka = utworzPrzegladarke({odmawiaOd: 2});

    await zaladujKrok(karta, przegladarka).klik();

    const krok2 = zaladujKrok(karta, przegladarka);
    assert.equal(await krok2.odtworzenie, false);
    assert.equal(krok2.checkbox.checked, false);
    assert.equal(krok2.kontroler.jestAktywna(), false);

    // Człowiek niczego nie wyłączał — odmowa przeglądarki nie kasuje wyboru.
    zaladujKrok(karta, przegladarka);
    assert.equal(przegladarka.zadania, 3);
});

test('reczne wylaczenie nie wraca po nawigacji; inny przepis nie dziedziczy wyboru', async () => {
    const karta = utworzPamiecKarty();
    const przegladarka = utworzPrzegladarke();

    const krok1 = zaladujKrok(karta, przegladarka);
    await krok1.klik();
    await krok1.klik();

    const krok2 = zaladujKrok(karta, przegladarka);
    assert.equal(await krok2.odtworzenie, false);
    assert.equal(krok2.checkbox.checked, false);
    assert.equal(przegladarka.zadania, 1);

    await zaladujKrok(karta, przegladarka, 'bigos').klik();
    const innyPrzepis = zaladujKrok(karta, przegladarka, 'pierogi');
    assert.equal(await innyPrzepis.odtworzenie, false);
    assert.equal(innyPrzepis.checkbox.checked, false);
});

test('Zakoncz gotowanie kasuje wybor, a niedostepna pamiec niczego nie psuje', async () => {
    const karta = utworzPamiecKarty();
    const przegladarka = utworzPrzegladarke();

    await zaladujKrok(karta, przegladarka).klik();
    assert.equal(karta.getItem(kluczWyboru('bigos')), '1');

    utworzPamiecWyboru(karta, 'bigos').zapamietaj(false);
    assert.equal(karta.getItem(kluczWyboru('bigos')), null);

    const zablokowana = {
        getItem: () => { throw new Error('SecurityError'); },
        setItem: () => { throw new Error('SecurityError'); },
        removeItem: () => { throw new Error('SecurityError'); },
    };
    const krok = zaladujKrok(zablokowana, przegladarka);
    assert.equal(await krok.odtworzenie, false);
    await krok.klik();
    assert.equal(krok.kontroler.jestAktywna(), true);
});
