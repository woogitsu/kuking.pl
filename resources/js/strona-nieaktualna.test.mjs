import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { trescKomunikatu, niesiePrzesylanie, podlaczStronaNieaktualna } from './strona-nieaktualna.js';

const calosc = (t) => [t.naglowek, ...t.akapity].join(' ');

test('Poza kreatorem: mówi co się stało i co zrobić, bez obietnic o szkicu', () => {
    const t = calosc(trescKomunikatu({ zapis: null, zdjecieWToku: false }));
    assert.match(t, /Ta strona jest nieaktualna/);
    assert.match(t, /nową wersję Kukinga/);
    assert.match(t, /Odśwież stronę i zrób to jeszcze raz\./);
    assert.doesNotMatch(t, /szkic|zdjęcie/i);
});

test('Kreator z zapisanym szkicem: szkic zostaje, ale ostatnia zmiana mogła przepaść', () => {
    const t = calosc(trescKomunikatu({ zapis: 'szkic', zdjecieWToku: false }));
    assert.match(t, /Szkic zapisany wcześniej zostaje\./);
    assert.match(t, /Ostatnia zmiana w formularzu mogła się jednak nie zapisać/);
    assert.doesNotMatch(t, /Zdjęcie/);
});

test('Kreator bez zapisu: NIE obiecuje, że cokolwiek jest bezpieczne', () => {
    const t = calosc(trescKomunikatu({ zapis: 'brak', zdjecieWToku: false }));
    assert.match(t, /nie jest jeszcze zapisany/);
    assert.match(t, /formularz będzie pusty/);
    assert.doesNotMatch(t, /zostaje/);
});

test('Zdjęcie w trakcie przesyłania: każe dodać je jeszcze raz, także przy zapisanym szkicu', () => {
    const t = calosc(trescKomunikatu({ zapis: 'szkic', zdjecieWToku: true }));
    assert.match(t, /Szkic zapisany wcześniej zostaje\./);
    assert.match(t, /Zdjęcie wybrane przed chwilą nie zostało przesłane\. Po odświeżeniu dodaj je jeszcze raz\./);
});

test('Przesyłanie rozpoznane po akcjach Livewire w żądaniu', () => {
    const zadanie = (nazwy) => ({ messages: new Set([{ actions: new Set(nazwy.map((name) => ({ name }))) }]) });
    assert.equal(niesiePrzesylanie(zadanie(['_startUpload'])), true);
    assert.equal(niesiePrzesylanie(zadanie(['_finishUpload'])), true);
    assert.equal(niesiePrzesylanie(zadanie(['next'])), false);
    assert.equal(niesiePrzesylanie(undefined), false);
});

// --- Podpięcie pod Livewire na atrapie DOM-u ---------------------------------

function atrapa({ zapis } = {}) {
    const sluchacze = {};
    const elementy = [];
    const element = (tag) => {
        const el = {
            tag, id: '', className: '', attrs: {}, children: [], textContent: '', focused: false,
            setAttribute(k, v) { this.attrs[k] = v; },
            getAttribute(k) { return this.attrs[k] ?? null; },
            addEventListener(typ, fn) { this[`on${typ}`] = fn; },
            prepend(dziecko) { this.children.unshift(dziecko); elementy.push(dziecko); },
            replaceChildren(...d) { this.children = d; },
            focus() { this.focused = true; },
        };
        return el;
    };
    const main = element('main');
    const kreator = zapis ? Object.assign(element('div'), { attrs: { 'data-kreator-zapis': zapis } }) : null;
    const dokument = {
        body: element('body'),
        createElement: element,
        querySelector: (s) => (s === 'main' ? main : s === '[data-kreator-zapis]' ? kreator : null),
        getElementById: (id) => elementy.find((e) => e.id === id) ?? null,
    };
    const okno = {
        przeladowania: 0,
        location: { reload() { okno.przeladowania++; } },
        addEventListener(typ, fn) { (sluchacze[typ] ??= []).push(fn); },
        wyslij(typ, detail) { (sluchacze[typ] ?? []).forEach((fn) => fn({ detail })); },
    };
    const livewire = {
        interceptory: [],
        interceptRequest(cb) { this.interceptory.push(cb); },
        // Odtwarza gałąź błędu z livewire.esm.js 4.4.3: bez preventDefault()
        // przy 419 Livewire woła angielskie confirm().
        odpowiedz(status, request = { messages: [] }) {
            let bledy = [];
            this.interceptory.forEach((cb) => cb({ request, onError: (fn) => bledy.push(fn) }));
            let zablokowane = false;
            bledy.forEach((fn) => fn({ response: { status }, preventDefault: () => { zablokowane = true; } }));
            return { confirmLivewire: status === 419 && !zablokowane };
        },
    };
    return { dokument, okno, livewire, main };
}

test('419: blokuje confirm() Livewire i wstawia polski komunikat z przyciskiem', () => {
    const { dokument, okno, livewire, main } = atrapa({ zapis: 'szkic' });
    podlaczStronaNieaktualna(livewire, okno, dokument);

    assert.equal(livewire.odpowiedz(419).confirmLivewire, false, 'angielskie okienko Livewire nie może się pokazać');

    const ramka = main.children[0];
    assert.equal(ramka.attrs.role, 'alert');
    const [h, ...reszta] = ramka.children;
    const przycisk = reszta.at(-1);
    assert.equal(h.textContent, 'Ta strona jest nieaktualna');
    assert.equal(h.focused, true, 'fokus idzie na komunikat');
    assert.equal(przycisk.tag, 'button');
    assert.equal(przycisk.textContent, 'Odśwież stronę');
    assert.match(przycisk.className, /\bbtn\b/);
    assert.ok(reszta.some((p) => /Szkic zapisany wcześniej zostaje/.test(p.textContent)));

    przycisk.onclick();
    assert.equal(okno.przeladowania, 1);

    // Drugie 419 nie dokłada drugiej ramki.
    livewire.odpowiedz(419);
    assert.equal(main.children.length, 1);
});

test('Kontrola dodatnia: atrapa naprawdę zgłasza confirm(), gdy nikt nie woła preventDefault()', () => {
    const { livewire } = atrapa();
    assert.equal(livewire.odpowiedz(419).confirmLivewire, true);
});

test('Inne błędy (500) zostają Livewire’owi — nie udajemy nieaktualnej strony', () => {
    const { dokument, okno, livewire, main } = atrapa();
    podlaczStronaNieaktualna(livewire, okno, dokument);
    livewire.odpowiedz(500);
    assert.equal(main.children.length, 0);
});

test('Zdjęcie rozpoczęte w nieaktualnej karcie trafia do komunikatu', () => {
    const { dokument, okno, livewire, main } = atrapa({ zapis: 'brak' });
    podlaczStronaNieaktualna(livewire, okno, dokument);
    okno.wyslij('livewire-upload-start', { id: 'k1', property: 'heroPhoto' });
    livewire.odpowiedz(419);
    const teksty = main.children[0].children.map((e) => e.textContent).join(' ');
    assert.match(teksty, /nie jest jeszcze zapisany/);
    assert.match(teksty, /Zdjęcie wybrane przed chwilą nie zostało przesłane/);
});

test('app.js wczytuje moduł i podpina go pod Livewire; kreator wystawia stan zapisu', () => {
    const app = readFileSync(new URL('./app.js', import.meta.url), 'utf8');
    assert.match(app, /import \{podlaczStronaNieaktualna\} from '\.\/strona-nieaktualna\.js';/);
    assert.match(app, /podlaczStronaNieaktualna\(window\.Livewire\)/);
    const kreator = readFileSync(new URL('../views/components/recipe-wizard.blade.php', import.meta.url), 'utf8');
    assert.match(kreator, /data-kreator-zapis="\{\{ \$recipeId === null \? 'brak'/);
    const zrodlo = readFileSync(new URL('./strona-nieaktualna.js', import.meta.url), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    assert.doesNotMatch(zrodlo, /\bconfirm\s*\(/, 'bez window.confirm');
});
