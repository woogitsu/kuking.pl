/*
 * Issue #742 — podgląd wybranego zdjęcia zwalniał `object URL` WYŁĄCZNIE po
 * zdarzeniu `load`. Plik z poprawnym nagłówkiem image/jpeg, którego treść
 * jest uszkodzona, kończy się `error` — bez sprzątania. Kolejny wybór albo
 * wyczyszczenie pola (`replaceChildren()`) usuwał miniatury z DOM-u, ale nie
 * zwalniał ich adresów `blob:`.
 *
 * CO TU JEST NAPRAWDĘ TESTOWANE
 * Prawdziwy fragment `resources/js/app.js` (funkcja `kotwicaPodPolem`,
 * `zwolnijPodgladObjectUrls` i oba nasłuchy: `change` na polu plików i
 * `livewire-upload-error` z kreatora), uruchomiony w `node:vm` na ręcznie
 * zbudowanym, minimalnym DOM-ie z licznikami `URL.createObjectURL` /
 * `URL.revokeObjectURL`. To jest pomiar WYWOŁAŃ prawdziwego handlera na
 * atrapach, NIE pomiar pamięci przeglądarki — atrapa DOM-u nie odtwarza
 * cyklu życia prawdziwego <img> (np. rzeczywistego dekodowania obrazu).
 * Próbę w prawdziwej przeglądarce robi osobno `scripts/podglad-przed-
 * wyslaniem.mjs` (inny aspekt tej samej funkcji — czas i źródło `blob:`).
 *
 *   node --test scripts/podglad-object-url.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { komunikatWyboru, moznaUsuwacZWyboru, usunPlikZWyboru } from '../resources/js/usun-zdjecie-z-wyboru.js';

const zrodloPelne = readFileSync(new URL('../resources/js/app.js', import.meta.url), 'utf8');
const poczatek = zrodloPelne.indexOf('function kotwicaPodPolem(input) {');
const koniec = zrodloPelne.indexOf('// --- Fokus na podsumowaniu błędów');
assert.ok(poczatek >= 0 && koniec > poczatek, 'Nie znaleziono bloku podglądu zdjęć w app.js — zmieniły się kotwice.');
const zrodlo = zrodloPelne.slice(poczatek, koniec);

// --- Minimalny DOM: tylko tyle, ile potrzebuje testowany fragment --------

class FakeNode extends EventTarget {
    constructor() {
        super();
        this.children = [];
        this.parentElement = null;
    }

    appendChild(dziecko) {
        this.children.push(dziecko);
        dziecko.parentElement = this;

        return dziecko;
    }

    replaceChildren() {
        this.children = [];
    }

    querySelectorAll(selektor) {
        const [tag, klasa] = selektor.split('.');
        const wynik = [];

        const idz = (wezel) => {
            for (const dziecko of wezel.children) {
                if ((!tag || dziecko.tagName === tag) && (!klasa || dziecko.classList.has(klasa))) {
                    wynik.push(dziecko);
                }
                idz(dziecko);
            }
        };

        idz(this);

        return wynik;
    }
}

class FakeElement extends FakeNode {
    constructor(tagName) {
        super();
        this.tagName = tagName;
        this.dataset = {};
        this._className = '';
        this._attrs = {};
    }

    get className() { return this._className; }
    set className(wartosc) { this._className = wartosc; }
    get classList() { return new Set(this._className.split(' ').filter(Boolean)); }
    setAttribute(klucz, wartosc) { this._attrs[klucz] = wartosc; }
    focus() { FakeElement.fokus = this; }
    get textContent() { return this._text ?? this.children.map((d) => d.textContent).join(''); }
    set textContent(wartosc) { this._text = wartosc; }
    getAttribute(klucz) { return this._attrs[klucz] ?? null; }

    get nextElementSibling() {
        if (!this.parentElement) {
            return null;
        }

        const indeks = this.parentElement.children.indexOf(this);

        return this.parentElement.children[indeks + 1] ?? null;
    }

    insertAdjacentElement(pozycja, element) {
        assert.equal(pozycja, 'afterend', 'Test obsługuje tylko "afterend" — tyle używa kod źródłowy.');
        assert.ok(this.parentElement, 'Element bez rodzica nie ma gdzie wstawić sąsiada.');

        const indeks = this.parentElement.children.indexOf(this);
        this.parentElement.children.splice(indeks + 1, 0, element);
        element.parentElement = this.parentElement;
    }
}

class HTMLInputElement extends FakeElement {
    constructor(id, pliki = []) {
        super('input');
        this.id = id;
        this.type = 'file';
        this.files = pliki;
    }
}

class HTMLLabelElement extends FakeElement {
    constructor(htmlFor) {
        super('label');
        this.htmlFor = htmlFor;
    }
}

class HTMLImageElement extends FakeElement {
    constructor() {
        super('img');
        this._src = '';
    }

    get src() { return this._src; }
    set src(wartosc) { this._src = wartosc; }
}

class FakeDocument extends FakeNode {
    createTextNode(tekst) {
        return { textContent: tekst, children: [] };
    }

    createElement(tag) {
        if (tag === 'img') {
            return new HTMLImageElement();
        }

        return new FakeElement(tag);
    }

    getElementById(id) {
        const idz = (wezel) => {
            for (const dziecko of wezel.children) {
                if (dziecko.id === id) {
                    return dziecko;
                }

                const znaleziony = idz(dziecko);

                if (znaleziony) {
                    return znaleziony;
                }
            }

            return null;
        };

        return idz(this);
    }
}

/** Plik z fałszywym `.type`, żeby przejść filtr `image/*` — treść nieważna. */
function plikObrazu(nazwa = 'zdjecie.jpg') {
    return { name: nazwa, type: 'image/jpeg' };
}

/* Atrapa `DataTransfer` — Node jej nie ma; zachowuje się jak prawdziwa lista. */
class AtrapaDataTransfer {
    constructor() {
        const pliki = [];
        this.files = pliki;
        this.items = { add: (plik) => pliki.push(plik) };
    }
}

function fixture({ usuwanie = false, KlasaDataTransfer = AtrapaDataTransfer } = {}) {
    const document = new FakeDocument();
    const window = document; // kod źródłowy nasłuchuje 'livewire-upload-error' na `window`
    const utworzoneUrls = new Set();
    const zwolnioneUrls = new Set();
    let licznik = 0;

    const URL_atrapa = {
        createObjectURL(plik) {
            const adres = 'blob:test/' + (licznik += 1) + '-' + plik.name;
            utworzoneUrls.add(adres);

            return adres;
        },
        revokeObjectURL(adres) {
            zwolnioneUrls.add(adres);
        },
    };

    const input = new HTMLInputElement('f-photos');
    if (usuwanie) {
        input.dataset.usuwanieZdjec = '';
    }
    const etykieta = new HTMLLabelElement('f-photos');
    document.appendChild(input);
    document.appendChild(etykieta);

    runInNewContext(zrodlo, {
        document, window, URL: URL_atrapa,
        HTMLInputElement, HTMLLabelElement,
        console,
        komunikatWyboru,
        moznaUsuwacZWyboru: () => moznaUsuwacZWyboru(KlasaDataTransfer),
        usunPlikZWyboru: (pole, indeks) => usunPlikZWyboru(pole, indeks, KlasaDataTransfer),
    });
    FakeElement.fokus = null;

    function wybierz(pliki) {
        input.files = pliki;
        const zdarzenie = new Event('change');
        Object.defineProperty(zdarzenie, 'target', { value: input });
        document.dispatchEvent(zdarzenie);
    }

    function bladWysylkiLivewire(komunikat) {
        input.dataset.bladWysylki = komunikat;
        const zdarzenie = new Event('livewire-upload-error');
        Object.defineProperty(zdarzenie, 'target', { value: input });
        window.dispatchEvent(zdarzenie);
    }

    function pojemnik() {
        return document.getElementById('f-photos-podglad');
    }

    function przyciskiUsun() {
        return pojemnik().querySelectorAll('button.podglad-wyboru-usun');
    }

    return {
        input, wybierz, bladWysylkiLivewire, pojemnik, przyciskiUsun,
        info: () => pojemnik().children[0]?.textContent,
        utworzone: () => utworzoneUrls.size,
        zwolnione: () => zwolnioneUrls.size,
        bezZwolnienia: () => utworzoneUrls.size - zwolnioneUrls.size,
    };
}

test('load: podgląd zwalnia adres po wczytaniu, tak jak dotychczas', () => {
    const f = fixture();
    f.wybierz([plikObrazu()]);
    const [img] = f.pojemnik().querySelectorAll('img.podglad-wyboru-zdjecie');
    img.dispatchEvent(new Event('load'));

    assert.equal(f.utworzone(), 1);
    assert.equal(f.zwolnione(), 1);
    assert.equal(f.bezZwolnienia(), 0);
});

test('error: podgląd, którego nie da się zdekodować, TEŻ zwalnia swój adres', () => {
    const f = fixture();
    f.wybierz([plikObrazu()]);
    const [img] = f.pojemnik().querySelectorAll('img.podglad-wyboru-zdjecie');
    img.dispatchEvent(new Event('error'));

    assert.equal(f.utworzone(), 1);
    assert.equal(f.zwolnione(), 1, 'Adres podglądu, który się nie zdekodował, nigdy nie został zwolniony.');
});

test('wyczyszczenie pola przed load/error zwalnia adresy nieudanych podglądów', () => {
    const f = fixture();
    f.wybierz([plikObrazu('a.jpg'), plikObrazu('b.jpg')]);
    // Ani load, ani error nie zdążyły się jeszcze odpalić — tak jak przy
    // szybkiej zmianie wyboru w prawdziwej przeglądarce.
    f.wybierz([]);

    assert.equal(f.utworzone(), 2);
    assert.equal(f.zwolnione(), 2, 'Wyczyszczenie pola usunęło miniatury z DOM-u, ale nie zwolniło ich adresów.');
});

test('trzykrotny wybór z mieszanym load/error nie zostawia żadnego wycieku', () => {
    const f = fixture();

    for (let i = 0; i < 3; i += 1) {
        f.wybierz([plikObrazu(`n${i}.jpg`)]);
        const [img] = f.pojemnik().querySelectorAll('img.podglad-wyboru-zdjecie');
        img.dispatchEvent(new Event(i % 2 === 0 ? 'load' : 'error'));
    }
    f.wybierz([]);

    assert.equal(f.utworzone(), 3);
    assert.equal(f.zwolnione(), 3);
    assert.equal(f.bezZwolnienia(), 0);
});

test('błąd wysyłki Livewire zwalnia podgląd, który wtedy jeszcze nie wczytał obrazu', () => {
    const f = fixture();
    f.wybierz([plikObrazu()]);
    // Livewire wysyła plik natychmiast po wyborze — błąd może dojść, zanim
    // przeglądarka zdąży wywołać load/error na samej miniaturze.
    f.bladWysylkiLivewire('To zdjęcie waży za dużo.');

    assert.equal(f.utworzone(), 1);
    assert.equal(f.zwolnione(), 1, 'Błąd wysyłki Livewire usunął podgląd z DOM-u, ale nie zwolnił jego adresu.');
    assert.equal(f.pojemnik().children.length, 0, 'Podgląd miniatury po błędzie wysyłki powinien zniknąć — zdjęcia na serwerze nie ma.');
});

test('wiele plików: nieudane pliki zwalniają adresy niezależnie od kolejności zdarzeń', () => {
    const f = fixture();
    f.wybierz([plikObrazu('x.jpg'), plikObrazu('y.jpg'), plikObrazu('z.jpg')]);
    const [x, y, z] = f.pojemnik().querySelectorAll('img.podglad-wyboru-zdjecie');

    // Kolejność zdarzeń w prawdziwej przeglądarce nie jest gwarantowana.
    z.dispatchEvent(new Event('load'));
    x.dispatchEvent(new Event('error'));
    y.dispatchEvent(new Event('error'));

    assert.equal(f.utworzone(), 3);
    assert.equal(f.zwolnione(), 3);
});

// --- Issue #884: „Usuń” przy miniaturze nowego zdjęcia ------------------

test('#884: A/B/C, usunięcie B zostawia w polu dokładnie A i C — nie tylko w DOM-ie', () => {
    const f = fixture({ usuwanie: true });
    f.wybierz([plikObrazu('a.jpg'), plikObrazu('b.jpg'), plikObrazu('c.jpg')]);

    const przyciski = f.przyciskiUsun();
    assert.equal(przyciski.length, 3);
    assert.equal(przyciski[0].type, 'button', '„Usuń” nie może być przyciskiem submit — wysłałby formularz.');
    assert.equal(przyciski[1].textContent, 'Usuń zdjęcie 2 z 3');

    przyciski[1].dispatchEvent(new Event('click'));

    assert.deepEqual(f.input.files.map((p) => p.name), ['a.jpg', 'c.jpg']);
    assert.equal(f.pojemnik().querySelectorAll('img.podglad-wyboru-zdjecie').length, 2);
    assert.equal(f.info(), 'Usunięto zdjęcie. Wybrano 2 zdjęcia.');
    assert.equal(FakeElement.fokus, f.przyciskiUsun()[1], 'Fokus powinien przejść na „Usuń” następnego zdjęcia.');
    // Trzy stare miniatury (bez load/error) zwolnione przy przerysowaniu;
    // dwie nowe są na ekranie i zwolnią się przy swoim load/error.
    assert.equal(f.zwolnione(), 3, 'Przerysowanie podglądu zostawiło niezwolnione adresy blob:.');
});

test('#884: usunięcie ostatniego zdjęcia daje pusty stan z komunikatem i fokus na polu', () => {
    const f = fixture({ usuwanie: true });
    f.wybierz([plikObrazu('a.jpg')]);
    f.przyciskiUsun()[0].dispatchEvent(new Event('click'));

    assert.equal(f.input.files.length, 0);
    assert.equal(f.przyciskiUsun().length, 0);
    assert.equal(f.info(), 'Usunięto zdjęcie. Nie ma teraz wybranego żadnego zdjęcia.');
    assert.equal(FakeElement.fokus, f.input);
});

test('#884: przeglądarka bez DataTransfer nie dostaje przycisków — podgląd jak dotąd', () => {
    const f = fixture({ usuwanie: true, KlasaDataTransfer: null });
    f.wybierz([plikObrazu('a.jpg'), plikObrazu('b.jpg')]);

    assert.equal(f.przyciskiUsun().length, 0);
    assert.equal(f.pojemnik().querySelectorAll('img.podglad-wyboru-zdjecie').length, 2);
});

test('#884: pole bez data-usuwanie-zdjec (np. kreator Livewire) nie dostaje przycisków', () => {
    const f = fixture();
    f.wybierz([plikObrazu('a.jpg'), plikObrazu('b.jpg')]);

    assert.equal(f.przyciskiUsun().length, 0);
});

test('#884: licznik przy 7 zdjęciach mówi „zdjęć”, nie „zdjęcia”', () => {
    const f = fixture();
    f.wybierz(Array.from({ length: 7 }, (_, i) => plikObrazu(`p${i}.jpg`)));

    assert.equal(f.info(), 'Wybrano 7 zdjęć.');
});
