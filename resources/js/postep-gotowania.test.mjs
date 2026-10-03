import test from 'node:test';
import assert from 'node:assert/strict';
import {INTERWAL_MS, komunikatOStatusie, komunikatOZmianie, podlaczSprawdzanie} from './postep-gotowania.js';

test('komunikatOZmianie: ta sama rewizja nie mówi nic (kontrola dodatnia dla reszty)', () => {
    assert.equal(komunikatOZmianie({aktywna: true, rewizja: 3}, 3), null);
});

test('komunikatOZmianie: inna rewizja mówi o zmianie na innym urządzeniu', () => {
    assert.match(komunikatOZmianie({aktywna: true, rewizja: 4}, 3), /innym urządzeniu/);
});

test('komunikatOZmianie: nowy wiersz z tą samą rewizją odkrywa zmianę #2860', () => {
    assert.match(komunikatOZmianie({aktywna: true, rewizja: 1, id_postepu: 'nowy'}, 1, {}, 'stary'), /innym urządzeniu/, 'POSTEP_2860_POLL_TOZSAMOSC');
    assert.equal(komunikatOZmianie({aktywna: true, rewizja: 1, id_postepu: 'ten-sam'}, 1, {}, 'ten-sam'), null);
});

test('komunikatOZmianie: wygaśnięcie albo wyłączenie jest zgłaszane', () => {
    assert.match(komunikatOZmianie({aktywna: false, rewizja: null}, 3), /wygasło albo zostało wyłączone/);
});

test('komunikatOZmianie: nieczytelna odpowiedź nie straszy', () => {
    assert.equal(komunikatOZmianie(null, 3), null);
    assert.equal(komunikatOZmianie('błąd', 3), null);
    assert.equal(komunikatOZmianie({aktywna: true, rewizja: 'x'}, 3), null);
    assert.equal(komunikatOZmianie({}, 3), null);
});

/** Minimalny pas i środowisko bez prawdziwej przeglądarki. */
function zbuduj({odpowiedz, widocznosc = 'visible', rewizja = '3', idPostepu = null}) {
    const tekst = {textContent: ''};
    const pas = {
        hidden: true,
        dataset: {postepRewizja: rewizja, postepAdres: '/przepisy/x/gotuj/postep'},
        querySelector: () => tekst,
    };
    if (idPostepu !== null) pas.dataset.postepId = idPostepu;
    const wywolania = [];
    const nasluch = {};
    let zegar = null;
    const srodowisko = {
        fetch: async (adres, opcje) => {
            wywolania.push({adres, opcje});
            return odpowiedz();
        },
        document: {
            visibilityState: widocznosc,
            addEventListener: (nazwa, fn) => { nasluch[nazwa] = fn; },
        },
        ustawCzas: (fn, ms) => { zegar = {fn, ms}; },
    };

    return {pas, tekst, wywolania, nasluch, srodowisko, zegar: () => zegar};
}

const ok = (dane) => async () => ({ok: true, json: async () => dane});

test('podlaczSprawdzanie: pyta co INTERWAL_MS i odkrywa pas przy zmianie rewizji', async () => {
    const t = zbuduj({odpowiedz: ok({aktywna: true, rewizja: 5})});
    podlaczSprawdzanie(t.pas, t.srodowisko);

    assert.equal(t.zegar().ms, INTERWAL_MS);
    assert.equal(t.pas.hidden, true, 'bez odpowiedzi pas zostaje ukryty');

    await t.zegar().fn();

    assert.equal(t.wywolania[0].opcje.credentials, 'same-origin');
    assert.equal(t.pas.hidden, false);
    assert.match(t.tekst.textContent, /innym urządzeniu/);
});

test('podlaczSprawdzanie: bez zmiany pas zostaje ukryty', async () => {
    const t = zbuduj({odpowiedz: ok({aktywna: true, rewizja: 3})});
    podlaczSprawdzanie(t.pas, t.srodowisko);
    await t.zegar().fn();

    assert.equal(t.pas.hidden, true);
});

test('podlaczSprawdzanie: OFF i ON z rewizją 1 odkrywa pas bez samoczynnego przeładowania #2860', async () => {
    const t = zbuduj({odpowiedz: ok({aktywna: true, rewizja: 1, id_postepu: 'nowy'}), rewizja: '1', idPostepu: 'stary'});
    podlaczSprawdzanie(t.pas, t.srodowisko);
    await t.zegar().fn();
    assert.equal(t.pas.hidden, false, 'POSTEP_2860_POLL_PAS');
    assert.match(t.tekst.textContent, /innym urządzeniu/);
});

test('podlaczSprawdzanie: ukryta karta nie pyta serwera', async () => {
    const t = zbuduj({odpowiedz: ok({aktywna: true, rewizja: 9}), widocznosc: 'hidden'});
    podlaczSprawdzanie(t.pas, t.srodowisko);
    await t.zegar().fn();

    assert.equal(t.wywolania.length, 0);
    assert.equal(t.pas.hidden, true);
});

test('podlaczSprawdzanie: błąd sieci i odpowiedź 500 są po cichu pomijane', async () => {
    const siec = zbuduj({odpowiedz: async () => { throw new Error('offline'); }});
    podlaczSprawdzanie(siec.pas, siec.srodowisko);
    await siec.zegar().fn();
    assert.equal(siec.pas.hidden, true);

    const blad = zbuduj({odpowiedz: async () => ({ok: false})});
    podlaczSprawdzanie(blad.pas, blad.srodowisko);
    await blad.zegar().fn();
    assert.equal(blad.pas.hidden, true);
});

test('podlaczSprawdzanie: pas bez rewizji albo adresu nie zakłada zegara', () => {
    const t = zbuduj({odpowiedz: ok({}), rewizja: ''});
    podlaczSprawdzanie(t.pas, t.srodowisko);

    assert.equal(t.zegar(), null);
});

test('komunikatOZmianie: własne zdania wspólnego gotowania (#2385) wypierają domyślne', () => {
    const teksty = {zmiana: 'Druga osoba zmieniła postęp.', koniec: 'Sesja się skończyła.'};

    assert.equal(komunikatOZmianie({aktywna: true, rewizja: 4}, 3, teksty), 'Druga osoba zmieniła postęp.');
    assert.equal(komunikatOZmianie({aktywna: false, rewizja: null}, 3, teksty), 'Sesja się skończyła.');
    // Kontrola dodatnia: bez własnych zdań zostają domyślne.
    assert.match(komunikatOZmianie({aktywna: true, rewizja: 4}, 3, {}), /innym urządzeniu/);
    // Ta sama rewizja nadal nie mówi nic.
    assert.equal(komunikatOZmianie({aktywna: true, rewizja: 3}, 3, teksty), null);
});

test('komunikatOStatusie: 403 i 404 mówią o końcu sesji tylko gdy strona dała własne zdanie (#2385)', () => {
    const teksty = {koniec: 'Ta sesja już się skończyła.'};

    assert.equal(komunikatOStatusie(404, teksty), 'Ta sesja już się skończyła.');
    assert.equal(komunikatOStatusie(403, teksty), 'Ta sesja już się skończyła.');
    // Kontrola ujemna: błąd serwera, limit żądań i brak własnego zdania (tryb gotowania) milczą.
    assert.equal(komunikatOStatusie(500, teksty), null);
    assert.equal(komunikatOStatusie(429, teksty), null);
    assert.equal(komunikatOStatusie(404, {}), null);
    assert.equal(komunikatOStatusie(404, {koniec: ''}), null);
});

test('podlaczSprawdzanie: odpowiedź 404 na stan sesji pokazuje zdanie o końcu sesji', async () => {
    const t = zbuduj({odpowiedz: async () => ({ok: false, status: 404})});
    t.pas.dataset.postepKomunikatKoniec = 'Ta sesja już się skończyła albo nie masz do niej dostępu.';
    podlaczSprawdzanie(t.pas, t.srodowisko);
    await t.zegar().fn();

    assert.equal(t.pas.hidden, false);
    assert.match(t.tekst.textContent, /sesja już się skończyła/);
});

test('podlaczSprawdzanie: 404 bez własnego zdania (tryb gotowania) zostaje po cichu pominięty', async () => {
    const t = zbuduj({odpowiedz: async () => ({ok: false, status: 404})});
    podlaczSprawdzanie(t.pas, t.srodowisko);
    await t.zegar().fn();

    assert.equal(t.pas.hidden, true);
    assert.equal(t.tekst.textContent, '');
});

test('podlaczSprawdzanie: tekst trafia do stałego regionu live poza pasem, gdy pas go wskazuje', async () => {
    const t = zbuduj({odpowiedz: ok({aktywna: false})});
    const region = {textContent: ''};
    t.pas.dataset.postepRegion = 'wg-zmiana-tekst';
    t.pas.dataset.postepKomunikatKoniec = 'Koniec sesji.';
    t.srodowisko.document.getElementById = (id) => (id === 'wg-zmiana-tekst' ? region : null);
    podlaczSprawdzanie(t.pas, t.srodowisko);
    await t.zegar().fn();

    assert.equal(region.textContent, 'Koniec sesji.');
    assert.equal(t.tekst.textContent, '', 'wnętrze pasa nie jest ruszane');
    assert.equal(t.pas.hidden, false);
});
