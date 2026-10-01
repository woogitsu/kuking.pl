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
    storageDziala,
    zapiszPozycje,
    usunMinutniki,
    wyczyscKolejke,
    czytaj,
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
    const odnowiony = zapiszKolejke(odczytajKolejke(stary, dzienPozniej).pozycje, dzienPozniej);

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
