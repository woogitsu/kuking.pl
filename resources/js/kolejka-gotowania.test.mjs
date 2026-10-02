import test from 'node:test';
import assert from 'node:assert/strict';

import {
    LIMIT,
    WAZNOSC_MS,
    KLUCZ_KOLEJKI,
    odczytajKolejke,
    zapiszKolejke,
    dodajDoKolejki,
    usunZKolejki,
    parametrKolejki,
    adresKolejki,
    minutnikiKolejki,
    uzupelnijOZapamietane,
    storageDziala,
    zapiszPozycje,
    usunMinutniki,
    wyczyscKolejke,
    czytaj,
    podlaczKolejke,
} from './kolejka-gotowania.js';
import {kluczStanu, zapiszStan} from './minutnik-krok.js';

class FalszywyStorage {
    constructor() {
        this.dane = new Map();
    }

    get length() {
        return this.dane.size;
    }

    key(i) {
        return [...this.dane.keys()][i] ?? null;
    }

    getItem(k) {
        return this.dane.has(k) ? this.dane.get(k) : null;
    }

    setItem(k, v) {
        this.dane.set(k, String(v));
    }

    removeItem(k) {
        this.dane.delete(k);
    }
}

const ZLY_STORAGE = {
    get length() {
        throw new Error('zablokowany');
    },
    getItem() {
        throw new Error('zablokowany');
    },
    setItem() {
        throw new Error('zablokowany');
    },
    removeItem() {
        throw new Error('zablokowany');
    },
    key() {
        throw new Error('zablokowany');
    },
};

const T0 = 1_800_000_000_000;

/** Minimalny ekran potrzebny do wykonania rzeczywistego podlaczKolejke(). */
function ekranKolejki(pozycje, localStorage, teraz, {zAdresu = '1', pominiete = []} = {}) {
    const przekierowania = [];
    const panelMinutnikow = {hidden: true};
    const root = {
        dataset: {
            kolejkaDane: JSON.stringify(pozycje.map((p) => ({...p, tytul: p.tytul ?? p.slug}))),
            kolejkaAdres: '/gotuj-kilka',
            kolejkaZAdresu: zAdresu,
            kolejkaPominiete: JSON.stringify(pominiete),
        },
        querySelector(selector) {
            if (selector === '[data-kolejka-minutniki]') return panelMinutnikow;
            if (selector === '[data-kolejka-minutniki-lista]') return {append() {}};
            if (selector === '[data-kolejka-alarmy]') return {children: []};

            return null;
        },
    };
    const document = {
        querySelectorAll() { return []; },
        querySelector() { return root; },
    };

    podlaczKolejke({
        document,
        localStorage,
        sessionStorage: new FalszywyStorage(),
        location: {replace(adres) { przekierowania.push(adres); }},
        teraz: () => teraz,
        zegar: {now: () => teraz},
        ustawInterwal() { return 1; },
    });

    return przekierowania;
}

test('odczyt identycznego ekranu po 23 h nie odnawia kolejki, więc po 25 h wygasa', () => {
    const ls = new FalszywyStorage();
    const pozycje = [{slug: 'zupa', krok: 2}];
    ls.setItem(KLUCZ_KOLEJKI, zapiszKolejke(pozycje, T0));

    assert.deepEqual(ekranKolejki(pozycje, ls, T0 + 23 * 60 * 60 * 1000), []);
    assert.equal(JSON.parse(ls.getItem(KLUCZ_KOLEJKI)).zapisano, T0, 'SAM_ODCZYT_2505_NIE_ODNAWIA_TTL');

    assert.deepEqual(ekranKolejki(pozycje, ls, T0 + 25 * 60 * 60 * 1000), ['/gotuj-kilka?wygasla=1']);
    assert.equal(ls.getItem(KLUCZ_KOLEJKI), null);
});

test('rzeczywista zmiana kroku na ekranie odnawia termin kolejki', () => {
    const ls = new FalszywyStorage();
    ls.setItem(KLUCZ_KOLEJKI, zapiszKolejke([{slug: 'zupa', krok: 1}], T0));
    const pozniej = T0 + 23 * 60 * 60 * 1000;

    assert.deepEqual(ekranKolejki([{slug: 'zupa', krok: 2}], ls, pozniej), []);
    assert.equal(JSON.parse(ls.getItem(KLUCZ_KOLEJKI)).zapisano, pozniej);
    assert.equal(odczytajKolejke(ls.getItem(KLUCZ_KOLEJKI), T0 + 25 * 60 * 60 * 1000).stan, 'ok');
});

test('goły adres i nowy tytuł nie odnawiają identycznej kolejki', () => {
    const ls = new FalszywyStorage();
    const pozycje = [{slug: 'zupa', krok: 1}];
    ls.setItem(KLUCZ_KOLEJKI, zapiszKolejke(pozycje, T0));
    const pozniej = T0 + 23 * 60 * 60 * 1000;

    assert.deepEqual(ekranKolejki([], ls, pozniej, {zAdresu: '0'}), ['/gotuj-kilka?p=zupa:1&a=zupa']);
    assert.deepEqual(ekranKolejki([{...pozycje[0], tytul: 'Nowy tytuł'}], ls, pozniej), []);
    assert.equal(JSON.parse(ls.getItem(KLUCZ_KOLEJKI)).zapisano, T0);
});

test('zmiana kolejności i odpadnięcie niedostępnego przepisu są zmianą danych', () => {
    const ls = new FalszywyStorage();
    const poczatkowe = [{slug: 'zupa', krok: 1}, {slug: 'sernik', krok: 1}];
    ls.setItem(KLUCZ_KOLEJKI, zapiszKolejke(poczatkowe, T0));
    const pozniej = T0 + 23 * 60 * 60 * 1000;

    assert.deepEqual(ekranKolejki([...poczatkowe].reverse(), ls, pozniej), []);
    assert.equal(JSON.parse(ls.getItem(KLUCZ_KOLEJKI)).zapisano, pozniej);
    assert.deepEqual(odczytajKolejke(ls.getItem(KLUCZ_KOLEJKI), pozniej).pozycje, [...poczatkowe].reverse());

    const jeszczePozniej = pozniej + 1000;
    assert.deepEqual(ekranKolejki([{slug: 'sernik', krok: 1}], ls, jeszczePozniej, {pominiete: ['zupa']}), []);
    assert.equal(JSON.parse(ls.getItem(KLUCZ_KOLEJKI)).zapisano, jeszczePozniej);
    assert.deepEqual(odczytajKolejke(ls.getItem(KLUCZ_KOLEJKI), jeszczePozniej).pozycje, [{slug: 'sernik', krok: 1}]);
});

test('limit kolejki to 4 przepisy: piąty nie wchodzi, kolejka bez zmian', () => {
    assert.equal(LIMIT, 4);

    let pozycje = [];

    for (const slug of ['zupa', 'kotlet', 'surowka', 'sernik']) {
        const wynik = dodajDoKolejki(pozycje, slug);
        assert.equal(wynik.wynik, 'dodano');
        pozycje = wynik.pozycje;
    }

    assert.equal(pozycje.length, 4);

    const piaty = dodajDoKolejki(pozycje, 'kompot');
    assert.equal(piaty.wynik, 'pelna');
    assert.deepEqual(piaty.pozycje, pozycje);
});

test('ten sam przepis dodany drugi raz nie dubluje się, nawet przy pełnej kolejce', () => {
    const pelna = ['a', 'b', 'c', 'd'].map((slug) => ({slug, krok: 1}));

    assert.equal(dodajDoKolejki(pelna, 'c').wynik, 'juz');
    assert.equal(dodajDoKolejki(pelna.slice(0, 2), 'b').wynik, 'juz');
});

test('zapis z listą dłuższą niż limit jest przycinany do 4 przy odczycie', () => {
    const surowy = JSON.stringify({
        wersja: 1,
        zapisano: T0,
        pozycje: ['a', 'b', 'c', 'd', 'e', 'f'].map((slug) => ({slug, krok: 1})),
    });

    assert.deepEqual(odczytajKolejke(surowy, T0).pozycje.map((p) => p.slug), ['a', 'b', 'c', 'd']);
});

test('kolejka wygasa po 24 godzinach od ostatniej zmiany', () => {
    const surowy = zapiszKolejke([{slug: 'zupa', krok: 2}], T0);

    assert.equal(odczytajKolejke(surowy, T0 + WAZNOSC_MS).stan, 'ok', 'równo 24 h to jeszcze ważna');
    assert.deepEqual(odczytajKolejke(surowy, T0 + WAZNOSC_MS).pozycje, [{slug: 'zupa', krok: 2}]);

    const wygasla = odczytajKolejke(surowy, T0 + WAZNOSC_MS + 1);
    assert.equal(wygasla.stan, 'wygasla');
    assert.deepEqual(wygasla.pozycje, [], 'wygasła kolejka nie wraca z pozycjami');
});

test('zmiana kolejki odnawia 24 godziny', () => {
    const stary = zapiszKolejke([{slug: 'zupa', krok: 1}], T0);
    const dzienPozniej = T0 + WAZNOSC_MS - 1000;
    const dodane = dodajDoKolejki(odczytajKolejke(stary, dzienPozniej).pozycje, 'kotlet');
    assert.equal(dodane.wynik, 'dodano');
    const odnowiony = zapiszKolejke(dodane.pozycje, dzienPozniej);

    assert.equal(odczytajKolejke(odnowiony, dzienPozniej + WAZNOSC_MS).stan, 'ok');
});

test('uszkodzony albo pusty zapis to „brak”, nie wyjątek', () => {
    for (const surowy of [null, undefined, '', 'nie json', '{}', '{"zapisano":"x"}', '[]', 'null']) {
        assert.equal(odczytajKolejke(surowy, T0).stan, 'brak', String(surowy));
    }
});

test('odczyt odrzuca śmieci w pozycjach (slug spoza wzorca, powtórzenia, zły krok)', () => {
    const surowy = JSON.stringify({
        zapisano: T0,
        pozycje: [
            {slug: '../etc/passwd', krok: 1},
            {slug: 'Zupa', krok: 1},
            {slug: 'zupa', krok: 0},
            {slug: 'zupa', krok: 3},
            {slug: 'kotlet', krok: 'x'},
            null,
        ],
    });

    assert.deepEqual(odczytajKolejke(surowy, T0).pozycje, [
        {slug: 'zupa', krok: 1},
        {slug: 'kotlet', krok: 1},
    ]);
});

test('usunięcie przepisu zachowuje kolejność pozostałych', () => {
    const pozycje = [{slug: 'a', krok: 2}, {slug: 'b', krok: 1}, {slug: 'c', krok: 3}];

    assert.deepEqual(usunZKolejki(pozycje, 'b'), [{slug: 'a', krok: 2}, {slug: 'c', krok: 3}]);
});

test('adres kolejki niesie slugi, kroki i aktywną potrawę', () => {
    const pozycje = [{slug: 'zupa', krok: 2}, {slug: 'kotlet', krok: 1}];

    assert.equal(parametrKolejki(pozycje), 'zupa:2,kotlet:1');
    assert.equal(adresKolejki('/gotuj-kilka', pozycje, 'kotlet'), '/gotuj-kilka?p=zupa:2,kotlet:1&a=kotlet');
    assert.equal(adresKolejki('/gotuj-kilka', pozycje), '/gotuj-kilka?p=zupa:2,kotlet:1');
});

test('minutniki potraw A i B mają osobne klucze i nie mieszają się z cudzymi', () => {
    assert.notEqual(kluczStanu('zupa', 1), kluczStanu('kotlet', 1));

    const klucze = [
        kluczStanu('zupa', 1),
        kluczStanu('zupa', 3),
        kluczStanu('kotlet', 1),
        kluczStanu('zupa-grzybowa', 1),
        kluczStanu('obcy', 2),
        'kuking.cos-innego',
    ];

    assert.deepEqual(
        minutnikiKolejki(klucze, ['zupa', 'kotlet']),
        [
            {klucz: kluczStanu('zupa', 1), slug: 'zupa', krok: '1'},
            {klucz: kluczStanu('zupa', 3), slug: 'zupa', krok: '3'},
            {klucz: kluczStanu('kotlet', 1), slug: 'kotlet', krok: '1'},
        ],
        'slug „zupa” nie łapie minutnika „zupa-grzybowa”',
    );
});

test('czyszczenie kolejki kasuje listę i minutniki jej potraw, cudze minutniki zostają', () => {
    const ls = new FalszywyStorage();
    const ss = new FalszywyStorage();

    zapiszPozycje(ls, [{slug: 'zupa', krok: 1}, {slug: 'kotlet', krok: 2}], T0);
    ss.setItem(kluczStanu('zupa', 1), zapiszStan(300, T0 + 300_000));
    ss.setItem(kluczStanu('kotlet', 2), zapiszStan(600, T0 + 600_000));
    ss.setItem(kluczStanu('obcy', 1), zapiszStan(60, T0 + 60_000));

    wyczyscKolejke({localStorage: ls, sessionStorage: ss, teraz: () => T0}, ['zupa', 'kotlet']);

    assert.equal(czytaj(ls, KLUCZ_KOLEJKI), null);
    assert.equal(ss.getItem(kluczStanu('zupa', 1)), null);
    assert.equal(ss.getItem(kluczStanu('kotlet', 2)), null);
    assert.notEqual(ss.getItem(kluczStanu('obcy', 1)), null, 'przepis spoza kolejki nie traci minutnika');
});

test('czyszczenie bierze też potrawy zapisane w przeglądarce, których nie ma na ekranie', () => {
    const ls = new FalszywyStorage();
    const ss = new FalszywyStorage();

    zapiszPozycje(ls, [{slug: 'zupa', krok: 1}, {slug: 'sernik', krok: 1}], T0);
    ss.setItem(kluczStanu('sernik', 1), zapiszStan(60, T0 + 60_000));

    wyczyscKolejke({localStorage: ls, sessionStorage: ss, teraz: () => T0}, ['zupa']);

    assert.equal(ss.getItem(kluczStanu('sernik', 1)), null);
});

test('zapis pustej kolejki usuwa klucz zamiast zapisywać pustą listę', () => {
    const ls = new FalszywyStorage();

    zapiszPozycje(ls, [{slug: 'zupa', krok: 1}], T0);
    assert.notEqual(czytaj(ls, KLUCZ_KOLEJKI), null);

    zapiszPozycje(ls, [], T0);
    assert.equal(czytaj(ls, KLUCZ_KOLEJKI), null);
});

test('zablokowany storage (prywatne okno) nie rzuca wyjątku', () => {
    assert.equal(storageDziala(ZLY_STORAGE), false);
    assert.equal(czytaj(ZLY_STORAGE, KLUCZ_KOLEJKI), null);
    assert.equal(zapiszPozycje(ZLY_STORAGE, [{slug: 'zupa', krok: 1}], T0), false);
    assert.doesNotThrow(() => usunMinutniki(ZLY_STORAGE, ['zupa']));
    assert.doesNotThrow(() => wyczyscKolejke({localStorage: ZLY_STORAGE, sessionStorage: ZLY_STORAGE, teraz: () => T0}, ['zupa']));
    assert.equal(storageDziala(new FalszywyStorage()), true);
});

test('nieaktualny adres z innej karty nie kasuje przepisu dodanego w międzyczasie', () => {
    const lokalne = [{slug: 'zupa', krok: 1}, {slug: 'kotlet', krok: 2}, {slug: 'sernik', krok: 1}];
    const dane = [{slug: 'zupa', krok: 2}, {slug: 'kotlet', krok: 2}];

    assert.deepEqual(uzupelnijOZapamietane(lokalne, dane, []), [...dane, {slug: 'sernik', krok: 1}]);
});

test('przepis usunięty przyciskiem albo odrzucony przez serwer nie wraca do adresu', () => {
    const lokalne = [{slug: 'zupa', krok: 1}, {slug: 'kotlet', krok: 1}];
    const dane = [{slug: 'zupa', krok: 1}];

    assert.equal(uzupelnijOZapamietane(lokalne, dane, ['kotlet']), null);
    assert.equal(uzupelnijOZapamietane(lokalne, dane, new Set(['kotlet'])), null);
});

test('uzupełnianie adresu mieści się w limicie i się kończy', () => {
    const lokalne = ['a', 'b', 'c', 'd'].map((slug) => ({slug, krok: 1}));

    // Adres ma już komplet: nic nie wraca, więc nie ma pętli przekierowań.
    assert.equal(uzupelnijOZapamietane(lokalne, lokalne, []), null);

    const wynik = uzupelnijOZapamietane(lokalne, [{slug: 'a', krok: 1}, {slug: 'b', krok: 1}, {slug: 'c', krok: 1}], []);
    assert.equal(wynik.length, LIMIT);

    // Po odtworzeniu adresu zapamiętane = adres, więc drugi raz nic nie wraca.
    assert.equal(uzupelnijOZapamietane(lokalne, wynik, []), null);
});
