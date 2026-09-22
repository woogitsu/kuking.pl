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

function fixture() {
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
    const etykieta = new HTMLLabelElement('f-photos');
    document.appendChild(input);
    document.appendChild(etykieta);

    runInNewContext(zrodlo, {
        document, window, URL: URL_atrapa,
        HTMLInputElement, HTMLLabelElement,
        console,
    });

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

    return {
        wybierz, bladWysylkiLivewire, pojemnik,
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
