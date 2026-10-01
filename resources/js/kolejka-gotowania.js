/*
 * Kolejka kilku przepisów z niezależnymi minutnikami (#2379, D-333).
 *
 * DECYZJA WŁAŚCICIELA (1 października 2026): kolejka żyje TYLKO w przeglądarce
 * (`localStorage`), bez konta i bez migracji, zgodnie z #2016 („minutniki
 * lokalne”). Limit to 4 przepisy, kolejka wygasa po 24 h od ostatniej zmiany,
 * zamknięcie karty kończy działające minutniki (żyją w `sessionStorage`
 * karty), kolejka zostaje. Dźwięk alarmu jest ten sam co w minutniku
 * pojedynczego przepisu, plus komunikat tekstowy. Bez AI.
 *
 * CO TRZYMAMY. Wyłącznie slug i numer kroku. Treść przepisu nigdy nie trafia
 * do `localStorage`: przy każdym wejściu serwer sprawdza `RecipePolicy::view`
 * i przepis, którego osoba już nie widzi, wypada z kolejki.
 *
 * MINUTNIKI. Nie ma tu drugiej arytmetyki: stan jednego minutnika to ten sam
 * klucz `kuking.minutnik.{slug}.{krok}` i te same funkcje z `minutnik-krok.js`
 * (zegar monotoniczny #751, odtwarzanie po przeładowaniu #740). Dzięki temu
 * minutniki potraw A i B są niezależne z samej konstrukcji klucza.
 *
 * BEZ SKRYPTU ekran działa jako zwykłe linki (stan kolejki jest w adresie),
 * a przycisk „Dodaj do kolejki” ma `hidden` i odkrywa go dopiero ten plik
 * (D-053: żadnego martwego przycisku).
 *
 * Część czysta (bez DOM-u) jest testowana w `kolejka-gotowania.test.mjs`.
 */

import {
    pozostaloSekund,
    formatMinutySekundy,
    kluczStanu,
    zapiszStan,
    odczytajTermin,
    krokZKlucza,
} from './minutnik-krok.js';

/** Ile przepisów mieści kolejka (decyzja właściciela z 1.10.2026). */
export const LIMIT = 4;

/** Ważność kolejki od ostatniej zmiany: 24 godziny. */
export const WAZNOSC_MS = 24 * 60 * 60 * 1000;

/** Klucz w `localStorage`. */
export const KLUCZ_KOLEJKI = 'kuking.kolejka';

const WZORZEC_SLUGA = /^[a-z0-9][a-z0-9-]{0,219}$/;

/**
 * @typedef {{slug: string, krok: number}} Pozycja
 * @typedef {{stan: 'brak'|'ok'|'wygasla', pozycje: Pozycja[]}} OdczytKolejki
 */

/** Pozycje uporządkowane i bezpieczne: poprawne slugi, bez powtórzeń, najwyżej LIMIT. */
function oczyscPozycje(surowe) {
    if (!Array.isArray(surowe)) return [];

    const widziane = new Set();
    const wynik = [];

    for (const p of surowe) {
        if (!p || typeof p.slug !== 'string' || !WZORZEC_SLUGA.test(p.slug) || widziane.has(p.slug)) continue;

        widziane.add(p.slug);
        wynik.push({slug: p.slug, krok: Number.isInteger(p.krok) && p.krok >= 1 ? p.krok : 1});

        if (wynik.length === LIMIT) break;
    }

    return wynik;
}

/**
 * Odczyt kolejki z tekstu z `localStorage`.
 * `brak`: nic nie zapisano albo zapis jest uszkodzony; `wygasla`: minęło
 * ponad 24 h od ostatniej zmiany (pozycje wracają puste, nie ożywiamy ich).
 *
 * @param {string|null|undefined} surowy
 * @param {number} teraz epoka w ms
 * @returns {OdczytKolejki}
 */
export function odczytajKolejke(surowy, teraz) {
    if (!surowy) return {stan: 'brak', pozycje: []};

    let dane;

    try {
        dane = JSON.parse(surowy);
    } catch {
        return {stan: 'brak', pozycje: []};
    }

    if (!dane || !Number.isFinite(dane.zapisano)) return {stan: 'brak', pozycje: []};

    if (teraz - dane.zapisano > WAZNOSC_MS) return {stan: 'wygasla', pozycje: []};

    const pozycje = oczyscPozycje(dane.pozycje);

    return {stan: pozycje.length > 0 ? 'ok' : 'brak', pozycje};
}

/** Tekst do `localStorage`; `zapisano` odświeża 24 h przy każdej zmianie. */
export function zapiszKolejke(pozycje, teraz) {
    return JSON.stringify({wersja: 1, zapisano: teraz, pozycje: oczyscPozycje(pozycje)});
}

/**
 * Dodanie przepisu. `juz`: jest w kolejce (bez zmian), `pelna`: jest już
 * LIMIT przepisów (bez zmian), `dodano`: nowa pozycja na końcu od kroku 1.
 *
 * @returns {{pozycje: Pozycja[], wynik: 'dodano'|'juz'|'pelna'}}
 */
export function dodajDoKolejki(pozycje, slug) {
    if (pozycje.some((p) => p.slug === slug)) return {pozycje, wynik: 'juz'};
    if (pozycje.length >= LIMIT) return {pozycje, wynik: 'pelna'};

    return {pozycje: [...pozycje, {slug, krok: 1}], wynik: 'dodano'};
}

export function usunZKolejki(pozycje, slug) {
    return pozycje.filter((p) => p.slug !== slug);
}

/** `[{zupa,2},{kotlet,1}]` → `"zupa:2,kotlet:1"` — format parametru `p` na serwerze. */
export function parametrKolejki(pozycje) {
    return pozycje.map((p) => `${p.slug}:${p.krok}`).join(',');
}

/** Adres ekranu kolejki dla danej listy (slugi to `[a-z0-9-]`, więc nie wymagają kodowania). */
export function adresKolejki(bazowy, pozycje, aktywny = null) {
    const czesci = [`p=${parametrKolejki(pozycje)}`];

    if (aktywny) czesci.push(`a=${aktywny}`);

    return `${bazowy}?${czesci.join('&')}`;
}

/**
 * Klucze minutników należące do przepisów z kolejki — po jednym wpisie na
 * minutnik. Zapis cudzego przepisu albo śmieć w `sessionStorage` są pomijane.
 *
 * @param {string[]} klucze wszystkie klucze `sessionStorage`
 * @param {string[]} slugi
 * @returns {{klucz: string, slug: string, krok: string}[]}
 */
export function minutnikiKolejki(klucze, slugi) {
    const wynik = [];

    for (const klucz of klucze) {
        for (const slug of slugi) {
            const krok = krokZKlucza(klucz, slug);

            if (krok !== null) {
                wynik.push({klucz, slug, krok});
                break;
            }
        }
    }

    return wynik;
}

// --- Bezpieczny dostęp do storage (prywatne okno, zablokowane dane) ---------

export function czytaj(storage, klucz) {
    try {
        return storage.getItem(klucz);
    } catch {
        return null;
    }
}

/** `true`, gdy zapis się udał. */
export function zapisz(storage, klucz, wartosc) {
    try {
        storage.setItem(klucz, wartosc);

        return true;
    } catch {
        return false;
    }
}

export function usun(storage, klucz) {
    try {
        storage.removeItem(klucz);
    } catch {
        // Brak dostępu do storage nie może psuć ekranu.
    }
}

function wszystkieKlucze(storage) {
    try {
        return Array.from({length: storage.length}, (_, i) => storage.key(i)).filter((k) => typeof k === 'string');
    } catch {
        return [];
    }
}

/** Czy ta przeglądarka w ogóle pozwala coś zapamiętać (prywatne okno bywa bez tego). */
export function storageDziala(storage) {
    const probny = 'kuking.kolejka.proba';

    if (!zapisz(storage, probny, '1')) return false;

    usun(storage, probny);

    return true;
}

/**
 * Zapis kolejki: pusta lista usuwa klucz zamiast zapisywać pustą kolejkę.
 * @returns {boolean} czy się udało
 */
export function zapiszPozycje(storage, pozycje, teraz) {
    if (pozycje.length === 0) {
        usun(storage, KLUCZ_KOLEJKI);

        return true;
    }

    return zapisz(storage, KLUCZ_KOLEJKI, zapiszKolejke(pozycje, teraz));
}

/** Kasuje zapisy minutników wskazanych przepisów. */
export function usunMinutniki(sessionStorage, slugi) {
    for (const {klucz} of minutnikiKolejki(wszystkieKlucze(sessionStorage), slugi)) {
        usun(sessionStorage, klucz);
    }
}

// --- Przycisk „Dodaj do kolejki gotowania” ------------------------------------

function podlaczDodawanie(blok, s) {
    if (blok.dataset.kolejkaGotowe) return;

    // Bez możliwości zapamiętania kolejki przycisk byłby martwy — zostaje ukryty.
    if (!storageDziala(s.localStorage)) return;

    blok.dataset.kolejkaGotowe = '1';

    const {slug, tytul, adres} = blok.dataset;
    const przycisk = blok.querySelector('[data-kolejka-dodaj-przycisk]');
    const komunikat = blok.querySelector('[data-kolejka-dodaj-komunikat]');
    const odnosnik = blok.querySelector('[data-kolejka-dodaj-link]');

    const odczyt = () => {
        const o = odczytajKolejke(czytaj(s.localStorage, KLUCZ_KOLEJKI), s.teraz());

        if (o.stan === 'wygasla') {
            usun(s.localStorage, KLUCZ_KOLEJKI);
        }

        return o.pozycje;
    };

    const pokaz = (tekst, pozycje, aktywny) => {
        komunikat.textContent = tekst;
        odnosnik.href = adresKolejki(adres, pozycje, aktywny);
        odnosnik.hidden = false;
    };

    const poczatkowe = odczyt();

    if (poczatkowe.some((p) => p.slug === slug)) {
        pokaz(`„${tytul}” jest w kolejce gotowania (${poczatkowe.length} z ${LIMIT}).`, poczatkowe, slug);
    }

    przycisk.addEventListener('click', () => {
        const {pozycje, wynik} = dodajDoKolejki(odczyt(), slug);

        if (wynik === 'pelna') {
            pokaz(`Kolejka ma już ${LIMIT} przepisy. Otwórz kolejkę i usuń któryś, żeby dodać „${tytul}”.`, pozycje, null);

            return;
        }

        if (wynik === 'dodano' && !zapiszPozycje(s.localStorage, pozycje, s.teraz())) {
            komunikat.textContent = 'Ta przeglądarka nie pozwala zapamiętać kolejki (może to okno prywatne). Gotuj ten przepis w trybie jednego przepisu.';

            return;
        }

        pokaz(
            wynik === 'juz'
                ? `„${tytul}” jest już w kolejce gotowania (${pozycje.length} z ${LIMIT}).`
                : `Dodano do kolejki gotowania: „${tytul}” (${pozycje.length} z ${LIMIT}).`,
            pozycje,
            slug,
        );
    });

    blok.hidden = false;
}

// --- Ekran kolejki ------------------------------------------------------------

function podlaczEkran(root, s) {
    if (root.dataset.kolejkaGotowe) return;

    root.dataset.kolejkaGotowe = '1';

    /** @type {{slug: string, krok: number, tytul: string}[]} */
    let dane = [];

    try {
        dane = JSON.parse(root.dataset.kolejkaDane ?? '[]');
    } catch {
        dane = [];
    }

    const bazowy = root.dataset.kolejkaAdres;
    const zAdresu = root.dataset.kolejkaZAdresu === '1';
    const tytuly = new Map(dane.map((p) => [p.slug, p.tytul]));
    const odczyt = odczytajKolejke(czytaj(s.localStorage, KLUCZ_KOLEJKI), s.teraz());

    // 1. Wygasła: czyścimy kolejkę i minutniki, wracamy z jasnym komunikatem.
    if (odczyt.stan === 'wygasla') {
        usun(s.localStorage, KLUCZ_KOLEJKI);
        usunMinutniki(s.sessionStorage, dane.map((p) => p.slug));

        if (root.dataset.kolejkaWygasla !== '1') {
            s.location.replace(`${bazowy}?wygasla=1`);

            return;
        }
    }

    // 2. Goły adres: kolejka z przeglądarki zamienia się w adres.
    if (!zAdresu) {
        if (odczyt.stan === 'ok') {
            s.location.replace(adresKolejki(bazowy, odczyt.pozycje, odczyt.pozycje[0].slug));

            return;
        }

        root.querySelector('[data-kolejka-bez-js]')?.setAttribute('hidden', '');

        return;
    }

    // 3. Adres jest zweryfikowany przez serwer (Policy): to on jest prawdą.
    // Przepisy, które wypadły, tracą też minutniki.
    const zostaja = new Set(dane.map((p) => p.slug));
    usunMinutniki(s.sessionStorage, odczyt.pozycje.filter((p) => !zostaja.has(p.slug)).map((p) => p.slug));
    zapiszPozycje(s.localStorage, dane.map(({slug, krok}) => ({slug, krok})), s.teraz());

    if (dane.length === 0) return;

    podlaczMinutniki(root, s, dane, tytuly, bazowy);
    podlaczCzyszczenie(root, s, dane, bazowy);
}

function podlaczCzyszczenie(root, s, dane, bazowy) {
    const blok = root.querySelector('[data-kolejka-czyszczenie]');

    if (!blok) return;

    const otworz = blok.querySelector('[data-kolejka-wyczysc]');
    const pytanie = blok.querySelector('[data-kolejka-wyczysc-potwierdz]');

    blok.hidden = false;

    otworz.addEventListener('click', () => {
        pytanie.hidden = false;
        otworz.hidden = true;
        pytanie.querySelector('[data-kolejka-wyczysc-nie]').focus();
    });

    pytanie.querySelector('[data-kolejka-wyczysc-nie]').addEventListener('click', () => {
        pytanie.hidden = true;
        otworz.hidden = false;
        otworz.focus();
    });

    pytanie.querySelector('[data-kolejka-wyczysc-tak]').addEventListener('click', () => {
        wyczyscKolejke(s, dane.map((p) => p.slug));
        s.location.assign(`${bazowy}?p=`);
    });
}

/** „Wyczyść kolejkę”: lista i minutniki wszystkich potraw. */
export function wyczyscKolejke(s, slugi) {
    const zapisane = odczytajKolejke(czytaj(s.localStorage, KLUCZ_KOLEJKI), s.teraz()).pozycje.map((p) => p.slug);

    usunMinutniki(s.sessionStorage, [...new Set([...slugi, ...zapisane])]);
    usun(s.localStorage, KLUCZ_KOLEJKI);
}

// --- Minutniki: wspólna lista z nazwą potrawy -----------------------------------

const POWTORZENIA_CO_MS = 5000;
const POWTORZENIA_NAJWYZEJ = 12;

function podlaczMinutniki(root, s, dane, tytuly, bazowy) {
    const slugi = dane.map((p) => p.slug);
    const panel = root.querySelector('[data-kolejka-minutniki]');
    const lista = root.querySelector('[data-kolejka-minutniki-lista]');
    const pasAlarmow = root.querySelector('[data-kolejka-alarmy]');
    const blokKroku = root.querySelector('[data-kolejka-minutnik]');
    const przyciskStart = blokKroku?.querySelector('[data-kolejka-minutnik-start]') ?? null;
    const komunikatKroku = blokKroku?.querySelector('[data-kolejka-minutnik-komunikat]') ?? null;

    /** @type {Map<string, {slug: string, krok: string, sekundyCalkiem: number, terminMonotoniczny: number, li: HTMLElement|null, tekst: HTMLElement|null}>} */
    const dzialajace = new Map();

    for (const {klucz, slug, krok} of minutnikiKolejki(wszystkieKlucze(s.sessionStorage), slugi)) {
        // Termin, który minął podczas przeładowania, nie znika: alarm zabrzmi
        // (jak w minutniku pojedynczym, #1301). Starsze niż 15 min to porzucone.
        const stan = odczytajTermin(czytaj(s.sessionStorage, klucz), s.teraz(), s.zegar.now());

        if (stan === null) {
            usun(s.sessionStorage, klucz);
            continue;
        }

        dzialajace.set(klucz, {slug, krok, ...stan, li: null, tekst: null});
    }

    const nazwa = (slug) => tytuly.get(slug) ?? slug;

    const odswiezPrzycisk = () => {
        if (!blokKroku || !przyciskStart) return;

        const klucz = kluczStanu(blokKroku.dataset.slug, blokKroku.dataset.krok);

        przyciskStart.hidden = dzialajace.has(klucz);
    };

    // --- alarmy ---
    let powtorzenia = 0;
    let zegarAlarmu = null;

    const zatrzymajAlarm = () => {
        if (zegarAlarmu !== null) {
            s.wyczyscInterwal(zegarAlarmu);
            zegarAlarmu = null;
        }
    };

    const graj = () => {
        powtorzenia += 1;

        if (powtorzenia > POWTORZENIA_NAJWYZEJ || pasAlarmow.children.length === 0) {
            zatrzymajAlarm();

            return;
        }

        s.zagrajAlarm();
    };

    const pokazAlarm = (slug, krok) => {
        const ramka = s.document.createElement('div');
        ramka.className = 'flash-ramka flash-ramka-blad stack';
        ramka.setAttribute('role', 'alert');

        const tekst = s.document.createElement('p');
        tekst.className = 'flash m-0';
        tekst.textContent = `${nazwa(slug)}: czas minął (krok ${krok}).`;

        const zamknij = s.document.createElement('button');
        zamknij.type = 'button';
        zamknij.className = 'btn btn-secondary';
        zamknij.textContent = `Zamknij komunikat: ${nazwa(slug)}`;
        zamknij.addEventListener('click', () => {
            ramka.remove();
            pasAlarmow.hidden = pasAlarmow.children.length === 0;
        });

        ramka.append(tekst, zamknij);
        pasAlarmow.append(ramka);
        pasAlarmow.hidden = false;

        s.zagrajAlarm();
        powtorzenia = 0;

        if (zegarAlarmu === null) {
            zegarAlarmu = s.ustawInterwal(graj, POWTORZENIA_CO_MS);
        }
    };

    // --- lista ---
    const dodajWiersz = (klucz, m) => {
        const li = s.document.createElement('li');
        li.className = 'kolejka-minutnik-wiersz';

        const tekst = s.document.createElement('span');
        tekst.className = 'kolejka-minutnik-tekst';
        tekst.setAttribute('role', 'timer');
        tekst.setAttribute('aria-live', 'off');

        const anuluj = s.document.createElement('button');
        anuluj.type = 'button';
        anuluj.className = 'btn btn-secondary';
        anuluj.textContent = `Anuluj minutnik: ${nazwa(m.slug)}, krok ${m.krok}`;
        anuluj.addEventListener('click', () => {
            usun(s.sessionStorage, klucz);
            dzialajace.delete(klucz);
            li.remove();
            panel.hidden = dzialajace.size === 0;
            odswiezPrzycisk();

            if (komunikatKroku && blokKroku && klucz === kluczStanu(blokKroku.dataset.slug, blokKroku.dataset.krok)) {
                komunikatKroku.textContent = 'Minutnik anulowany.';
            }
        });

        li.append(tekst, anuluj);
        lista.append(li);
        m.li = li;
        m.tekst = tekst;
    };

    const rysuj = () => {
        const teraz = s.zegar.now();

        for (const [klucz, m] of [...dzialajace]) {
            const pozostalo = pozostaloSekund(m.terminMonotoniczny, teraz);

            if (pozostalo === 0) {
                // Minutnik dzwoni najwyżej raz: zapis znika, zanim pokażemy alarm.
                usun(s.sessionStorage, klucz);
                dzialajace.delete(klucz);
                m.li?.remove();
                pokazAlarm(m.slug, m.krok);

                continue;
            }

            if (!m.li) dodajWiersz(klucz, m);

            m.tekst.textContent = `${nazwa(m.slug)}, krok ${m.krok}: ${formatMinutySekundy(pozostalo)}`;
        }

        panel.hidden = dzialajace.size === 0;
        odswiezPrzycisk();
    };

    if (przyciskStart) {
        przyciskStart.hidden = false;
        przyciskStart.addEventListener('click', () => {
            const sekundy = Number(blokKroku.dataset.sekundy);

            if (!Number.isFinite(sekundy) || sekundy <= 0) return;

            const {slug, krok} = blokKroku.dataset;
            const klucz = kluczStanu(slug, krok);

            // Epoka tylko do zapisu stanu (przetrwanie przeładowania); odliczanie
            // idzie po zegarze monotonicznym.
            zapisz(s.sessionStorage, klucz, zapiszStan(sekundy, s.teraz() + sekundy * 1000));
            dzialajace.set(klucz, {slug, krok, sekundyCalkiem: sekundy, terminMonotoniczny: s.zegar.now() + sekundy * 1000, li: null, tekst: null});

            if (komunikatKroku) komunikatKroku.textContent = `Minutnik ustawiony na ${blokKroku.dataset.etykieta}.`;

            rysuj();
        });
    }

    rysuj();
    s.ustawInterwal(rysuj, 500);
}

// --- Punkt wejścia -----------------------------------------------------------------

/**
 * @param {Partial<{document: Document, localStorage: Storage, sessionStorage: Storage, location: Location,
 *   teraz: () => number, zegar: {now: () => number}, zagrajAlarm: () => void,
 *   ustawInterwal: typeof setInterval, wyczyscInterwal: typeof clearInterval}>} srodowisko
 */
export function podlaczKolejke(srodowisko = {}) {
    const dok = srodowisko.document ?? (typeof document === 'undefined' ? null : document);

    if (!dok) return;

    let localStorage = srodowisko.localStorage;
    let sessionStorage = srodowisko.sessionStorage;

    // Samo odwołanie do `window.localStorage` potrafi rzucić (zablokowane dane).
    try {
        localStorage ??= window.localStorage;
    } catch {
        localStorage = undefined;
    }

    try {
        sessionStorage ??= window.sessionStorage;
    } catch {
        sessionStorage = undefined;
    }

    if (!localStorage || !sessionStorage) return;

    const s = {
        document: dok,
        localStorage,
        sessionStorage,
        location: srodowisko.location ?? window.location,
        teraz: srodowisko.teraz ?? (() => Date.now()),
        zegar: srodowisko.zegar ?? performance,
        zagrajAlarm: srodowisko.zagrajAlarm ?? (() => {}),
        ustawInterwal: srodowisko.ustawInterwal ?? ((f, ms) => window.setInterval(f, ms)),
        wyczyscInterwal: srodowisko.wyczyscInterwal ?? ((id) => window.clearInterval(id)),
    };

    dok.querySelectorAll('[data-kolejka-dodaj]').forEach((blok) => podlaczDodawanie(blok, s));

    const ekran = dok.querySelector('[data-kolejka-ekran]');

    if (ekran) podlaczEkran(ekran, s);
}
