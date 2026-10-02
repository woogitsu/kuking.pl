import test from 'node:test';
import assert from 'node:assert/strict';
import {
    KOMUNIKATY, LIMIT_ZAKONCZENIA_MS, STATUSY, komunikatLimitu, mutacjaPozaDyktowaniem, podlacz, posprzataj,
    przerwijWszystkie, utworzRejestr,
} from './dyktowanie.js';

/*
 * Warstwa DOM dyktowania sprawdzana w Node z lekkim, własnym DOM-em (repo nie
 * ma jsdom). Atrapa wiernie odtwarza tylko to, od czego zależy zachowanie:
 * `hidden` zabiera fokus (jak w przeglądarce), `focus()` nie działa na
 * ukryte i odłączone elementy, `isConnected` idzie po rodzicach.
 */
class Wezel {
    constructor(dok, tag) {
        this.dok = dok;
        this.tag = tag;
        this.children = [];
        this.parent = null;
        this.attrs = {};
        this.dataset = {};
        this.listeners = {};
        this.zdarzenia = [];
        this._hidden = false;
        this._tekst = '';
        this.id = '';
        this.value = '';
        this.className = '';
        this.classList = { add: (k) => { this.className = (this.className + ' ' + k).trim(); } };
    }

    get hidden() { return this._hidden; }

    set hidden(v) {
        this._hidden = !!v;
        if (this._hidden && this.dok.activeElement && (this.dok.activeElement === this || this.contains(this.dok.activeElement))) {
            this.dok.activeElement = this.dok.body;
        }
    }

    get textContent() {
        return this._tekst + this.children.map((c) => c.textContent).join('');
    }

    set textContent(v) { this._tekst = String(v); this.children = []; }

    get isConnected() {
        let w = this;
        while (w.parent) w = w.parent;
        return w === this.dok.body;
    }

    append(...dzieci) { dzieci.forEach((d) => { d.parent = this; this.children.push(d); }); }

    remove() {
        if (this.parent) this.parent.children = this.parent.children.filter((c) => c !== this);
        this.parent = null;
    }

    contains(w) {
        if (w === this) return true;
        return this.children.some((c) => c.contains(w));
    }

    setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'id') this.id = String(v); }

    getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }

    addEventListener(typ, f) { (this.listeners[typ] ||= []).push(f); }

    dispatchEvent(zd) { this.zdarzenia.push(zd.type); (this.listeners[zd.type] || []).forEach((f) => f(zd)); return true; }

    click() { if (!this.hidden) this.dispatchEvent({ type: 'click' }); }

    focus() {
        let w = this;
        while (w) {
            if (w.hidden) return;
            w = w.parent;
        }
        if (this.isConnected) this.dok.activeElement = this;
    }

    closest(selektor) {
        const klasa = selektor.replace('.', '');
        for (let w = this; w; w = w.parent) {
            if (w.className.split(/\s+/).includes(klasa)) return w;
        }
        return null;
    }

    wszystkie(test) {
        return [this, ...this.children.flatMap((c) => c.wszystkie(test))].filter(test);
    }
}

function swiat() {
    const dok = { };
    dok.body = new Wezel(dok, 'body');
    dok.activeElement = dok.body;
    dok.createElement = (tag) => new Wezel(dok, tag);
    dok.createElementNS = (ns, tag) => new Wezel(dok, tag);
    dok.getElementById = (id) => dok.body.wszystkie((w) => w.id === id)[0] || null;

    class Silnik {
        constructor() { Silnik.wszystkie.push(this); this.wolane = []; }

        start() { this.wolane.push('start'); }

        stop() { this.wolane.push('stop'); }

        abort() { this.wolane.push('abort'); }

        wynik(tekst, koncowy) {
            const wpis = [{ transcript: tekst }];
            wpis.isFinal = koncowy;
            this.onresult({ resultIndex: 0, results: [wpis] });
        }
    }
    Silnik.wszystkie = [];

    const timery = [];
    const okno = {
        SpeechRecognition: Silnik,
        Event: class { constructor(typ) { this.type = typ; } },
        setTimeout: (f, ms) => { timery.push({ f, ms, zywy: true }); return timery.length - 1; },
        clearTimeout: (id) => { if (timery[id]) timery[id].zywy = false; },
    };

    const pole = new Wezel(dok, 'textarea');
    pole.id = 'f-pole';
    dok.body.append(pole);

    function host(cel = 'f-pole', dane = {}) {
        const h = new Wezel(dok, 'div');
        h.dataset.cel = cel;
        Object.assign(h.dataset, dane);
        dok.body.append(h);
        return h;
    }

    const przyciski = (h) => h.wszystkie((w) => w.tag === 'button');
    const przycisk = (h, napis) => przyciski(h).find((b) => b.textContent.trim().startsWith(napis));

    return { dok, okno, Silnik, timery, pole, host, przycisk, przyciski, rejestr: utworzRejestr() };
}

test('Bez SpeechRecognition host zostaje pusty: żadnego przycisku', () => {
    const { dok, okno, host, rejestr } = swiat();
    delete okno.SpeechRecognition;
    const h = host();

    assert.equal(podlacz(h, okno, dok, rejestr), false);
    assert.equal(h.children.length, 0);
});

test('Z SpeechRecognition host dostaje przycisk „Dyktuj” i zdanie o dostawcy; podglądu jeszcze nie widać', () => {
    const { dok, okno, host, przycisk, rejestr } = swiat();
    const h = host();

    assert.equal(podlacz(h, okno, dok, rejestr), true);
    assert.equal(przycisk(h, 'Dyktuj').hidden, false);
    assert.equal(przycisk(h, 'Zakończ dyktowanie').hidden, true);
    assert.equal(przycisk(h, 'Wstaw do przepisu').hidden, true);
    assert.equal(przycisk(h, 'Anuluj').hidden, true);
    assert.match(h.textContent, /Kuking nie nagrywa dźwięku i nie dostaje go/);
    assert.equal(podlacz(h, okno, dok, rejestr), false, 'Drugie podłączenie tego samego hosta nic nie robi.');
});

test('Podgląd NIE jest aria-live (wyniki tymczasowe zasypałyby czytnik), a osobny status jest', () => {
    const { dok, okno, host, przycisk, rejestr, Silnik } = swiat();
    const h = host();
    podlacz(h, okno, dok, rejestr);

    const grupa = h.wszystkie((w) => w.getAttribute('role') === 'group')[0];
    const status = h.wszystkie((w) => w.getAttribute('role') === 'status')[0];
    assert.ok(grupa && grupa.getAttribute('aria-labelledby'));
    assert.equal(h.wszystkie((w) => w.getAttribute('aria-live') === 'polite' && w !== status).length, 0, 'Tylko status może być obszarem live.');
    assert.equal(status.getAttribute('aria-live'), 'polite');

    przycisk(h, 'Dyktuj').click();
    assert.equal(status.textContent, STATUSY.slucham);

    // Wyniki tymczasowe nie zmieniają statusu.
    const silnik = Silnik.wszystkie.at(-1);
    silnik.wynik('mąk', false);
    silnik.wynik('mąka', false);
    assert.equal(status.textContent, STATUSY.slucham);

    // Wynik końcowy tak.
    silnik.wynik('mąka', true);
    assert.equal(status.textContent, STATUSY.fragment);
});

test('Fokus wędruje za zmianą faz: Zakończ -> Wstaw -> pole; po „Anuluj” wraca na „Dyktuj”', () => {
    const { dok, okno, host, przycisk, rejestr, Silnik, pole } = swiat();
    const h = host();
    podlacz(h, okno, dok, rejestr);

    const dyktuj = przycisk(h, 'Dyktuj');
    dyktuj.focus();
    dyktuj.click();
    assert.equal(dok.activeElement, przycisk(h, 'Zakończ dyktowanie'), 'Po „Dyktuj” fokus nie może uciec na body.');

    const silnik = Silnik.wszystkie.at(-1);
    silnik.wynik('szklanka mąki', true);
    silnik.onend();
    assert.equal(dok.activeElement, przycisk(h, 'Wstaw do przepisu'));

    przycisk(h, 'Wstaw do przepisu').click();
    assert.equal(pole.value, 'szklanka mąki');
    assert.equal(dok.activeElement, pole, 'Po wstawieniu fokus jest w polu.');

    przycisk(h, 'Dyktuj').focus();
    przycisk(h, 'Dyktuj').click();
    Silnik.wszystkie.at(-1).wynik('do wyrzucenia', true);
    Silnik.wszystkie.at(-1).onend();
    przycisk(h, 'Anuluj').focus();
    przycisk(h, 'Anuluj').click();
    assert.equal(dok.activeElement, przycisk(h, 'Dyktuj'));
    assert.equal(pole.value, 'szklanka mąki', 'Anuluj niczego nie zmienia w polu.');
});

test('Fokus nie jest kradziony, gdy człowiek jest gdzie indziej (np. cisza kończy nasłuch w tle)', () => {
    const { dok, okno, host, przycisk, rejestr, pole, timery } = swiat();
    const h = host();
    podlacz(h, okno, dok, rejestr);
    przycisk(h, 'Dyktuj').focus();
    przycisk(h, 'Dyktuj').click();

    pole.focus();
    timery.filter((t) => t.zywy).forEach((t) => { t.zywy = false; t.f(); });

    assert.equal(dok.activeElement, pole);
});

test('„Wstaw” dopisuje do pola i woła input oraz change (Livewire/licznik); wpisany tekst zostaje', () => {
    const { dok, okno, host, przycisk, rejestr, Silnik, pole } = swiat();
    pole.value = 'Wymieszaj.';
    const h = host();
    podlacz(h, okno, dok, rejestr);

    przycisk(h, 'Dyktuj').click();
    Silnik.wszystkie.at(-1).wynik('Dodaj mleko.', true);
    Silnik.wszystkie.at(-1).onend();
    assert.equal(pole.value, 'Wymieszaj.', 'Do „Wstaw” pole jest nietknięte.');
    assert.deepEqual(pole.zdarzenia, []);

    przycisk(h, 'Wstaw do przepisu').click();
    assert.equal(pole.value, 'Wymieszaj. Dodaj mleko.');
    assert.deepEqual(pole.zdarzenia, ['input', 'change']);
});

test('Identyfikatory są unikalne także po usunięciu i dodaniu wiersza z tym samym data-cel', () => {
    const { dok, okno, host, rejestr } = swiat();
    const a = host();
    podlacz(a, okno, dok, rejestr);
    a.remove();
    const b = host();
    podlacz(b, okno, dok, rejestr);
    const c = host();
    podlacz(c, okno, dok, rejestr);

    const ids = [a, b, c].flatMap((h) => h.wszystkie((w) => w.id !== '').map((w) => w.id));
    assert.equal(new Set(ids).size, ids.length, `Zdublowane id: ${ids.join(', ')}`);
});

test('Gdy host zniknie ze strony, nasłuch się kończy, a spóźniony wynik niczego nie wstawia', () => {
    const { dok, okno, host, przycisk, rejestr, Silnik, pole } = swiat();
    const h = host();
    podlacz(h, okno, dok, rejestr);
    przycisk(h, 'Dyktuj').click();
    const silnik = Silnik.wszystkie.at(-1);

    h.remove();
    silnik.wynik('spóźnione', true);

    assert.ok(silnik.wolane.includes('abort'), 'Silnik musi dostać abort po zniknięciu pola.');
    assert.equal(pole.value, '');
});

test('posprzataj() kończy sesję hosta, którego już nie ma, i usuwa go z rejestru', () => {
    const { dok, okno, host, przycisk, rejestr, Silnik } = swiat();
    const h = host();
    podlacz(h, okno, dok, rejestr);
    przycisk(h, 'Dyktuj').click();
    const silnik = Silnik.wszystkie.at(-1);

    h.remove();
    posprzataj(rejestr);

    assert.ok(silnik.wolane.includes('abort'));
    assert.equal(rejestr.hosty.size, 0);
    assert.equal(rejestr.aktywna, null);
});

test('Jedna aktywna sesja: start drugiej kończy pierwszą neutralnie, bez „Nic nie usłyszeliśmy”', () => {
    const { dok, okno, host, przycisk, rejestr, Silnik, pole } = swiat();
    const drugie = new Wezel(dok, 'textarea');
    drugie.id = 'f-drugie';
    dok.body.append(drugie);
    const a = host('f-pole');
    const b = host('f-drugie');
    podlacz(a, okno, dok, rejestr);
    podlacz(b, okno, dok, rejestr);

    przycisk(a, 'Dyktuj').click();
    const pierwszy = Silnik.wszystkie.at(-1);
    pierwszy.wynik('pierwsze', true);

    przycisk(b, 'Dyktuj').click();
    const drugi = Silnik.wszystkie.at(-1);

    assert.notEqual(pierwszy, drugi);
    assert.ok(pierwszy.wolane.includes('abort'));
    assert.equal(przycisk(a, 'Zakończ dyktowanie').hidden, true, 'Pierwsza sesja nie słucha już.');
    assert.equal(przycisk(a, 'Wstaw do przepisu').hidden, false, 'Rozpoznany tekst pierwszej sesji zostaje w podglądzie.');
    const bladA = a.wszystkie((w) => w.getAttribute('role') === 'alert')[0];
    assert.equal(bladA.hidden, true, 'Żadnego fałszywego komunikatu o ciszy.');
    assert.equal(pole.value, '');
});

test('Za długi tekst nie jest obcinany po cichu: nie wchodzi do pola, zostaje w podglądzie, jest komunikat', () => {
    const { dok, okno, host, przycisk, rejestr, Silnik, pole } = swiat();
    pole.setAttribute('maxlength', '15');
    pole.value = 'Sól';
    const h = host();
    podlacz(h, okno, dok, rejestr);

    przycisk(h, 'Dyktuj').click();
    Silnik.wszystkie.at(-1).wynik('bardzo długi podyktowany tekst', true);
    Silnik.wszystkie.at(-1).onend();
    przycisk(h, 'Wstaw do przepisu').click();

    assert.equal(pole.value, 'Sól');
    const blad = h.wszystkie((w) => w.getAttribute('role') === 'alert')[0];
    assert.equal(blad.hidden, false);
    assert.match(blad.textContent, /nie mieści się w polu/);
    assert.match(blad.textContent, /Nic nie wstawiliśmy/);
    assert.equal(przycisk(h, 'Wstaw do przepisu').hidden, false, 'Tekst czeka w podglądzie.');
    assert.match(h.textContent, /bardzo długi podyktowany tekst/);

    // Mieści się — wchodzi.
    przycisk(h, 'Anuluj').click();
    przycisk(h, 'Dyktuj').click();
    Silnik.wszystkie.at(-1).wynik('krótki', true);
    Silnik.wszystkie.at(-1).onend();
    przycisk(h, 'Wstaw do przepisu').click();
    assert.equal(pole.value, 'Sól krótki');
});

test('Limit z data-licznik też chroni przed obcięciem', () => {
    const { dok, okno, host, przycisk, rejestr, Silnik, pole } = swiat();
    pole.dataset.licznik = '10';
    const h = host();
    podlacz(h, okno, dok, rejestr);
    przycisk(h, 'Dyktuj').click();
    Silnik.wszystkie.at(-1).wynik('dwanaście znaków', true);
    Silnik.wszystkie.at(-1).onend();
    przycisk(h, 'Wstaw do przepisu').click();

    assert.equal(pole.value, '');
    assert.match(h.textContent, /limit pola to 10/);
});

test('„Zakończ” bez onend (Safari/iOS): po awaryjnym czasie sesja kończy się sama i zachowuje tekst', () => {
    const { dok, okno, host, przycisk, rejestr, Silnik, timery } = swiat();
    const h = host();
    podlacz(h, okno, dok, rejestr);
    przycisk(h, 'Dyktuj').click();
    const silnik = Silnik.wszystkie.at(-1);
    silnik.wynik('liść laurowy', true);

    przycisk(h, 'Zakończ dyktowanie').click();
    assert.ok(silnik.wolane.includes('stop'));
    assert.equal(przycisk(h, 'Zakończ dyktowanie').hidden, false, 'Jeszcze czekamy na przeglądarkę.');

    const awaryjny = timery.filter((t) => t.zywy).at(-1);
    assert.equal(awaryjny.ms, LIMIT_ZAKONCZENIA_MS);
    awaryjny.f();

    assert.equal(przycisk(h, 'Zakończ dyktowanie').hidden, true);
    assert.equal(przycisk(h, 'Wstaw do przepisu').hidden, false);
});

test('przerwijWszystkie() (pagehide / ukrycie karty) zatrzymuje nasłuch i zostawia podgląd', () => {
    const { dok, okno, host, przycisk, rejestr, Silnik } = swiat();
    const h = host();
    podlacz(h, okno, dok, rejestr);
    przycisk(h, 'Dyktuj').click();
    Silnik.wszystkie.at(-1).wynik('ser żółty', true);

    przerwijWszystkie(rejestr);

    assert.ok(Silnik.wszystkie.at(-1).wolane.includes('abort'));
    assert.equal(przycisk(h, 'Zakończ dyktowanie').hidden, true);
    assert.equal(przycisk(h, 'Wstaw do przepisu').hidden, false);
});

test('Etykieta przycisku wstawiania i zdanie można ustawić na hoście (inne pola niż przepis)', () => {
    const { dok, okno, host, przycisk, rejestr } = swiat();
    const h = host('f-pole', { wstawNapis: 'Wstaw do komentarza', zdanie: 'Zdanie testowe.' });
    podlacz(h, okno, dok, rejestr);

    assert.ok(przycisk(h, 'Wstaw do komentarza'));
    assert.match(h.textContent, /Zdanie testowe\./);
});

test('Mutacje wewnątrz .dyktowanie są pomijane, zmiany gdzie indziej nie', () => {
    const { dok, okno, host, rejestr } = swiat();
    const h = host();
    podlacz(h, okno, dok, rejestr);
    const wewnatrz = h.children[0];

    assert.equal(mutacjaPozaDyktowaniem([{ target: wewnatrz }]), false);
    assert.equal(mutacjaPozaDyktowaniem([{ target: dok.body }]), true);
    assert.equal(mutacjaPozaDyktowaniem([{ target: wewnatrz }, { target: dok.body }]), true);
});

test('Komunikat limitu odmienia „znak” i „znaków”', () => {
    assert.match(komunikatLimitu(1, 10), /1 znak /);
    assert.match(komunikatLimitu(5, 10), /5 znaków/);
});

test('Komunikaty błędów po polsku mówią, co zrobić', () => {
    for (const tresc of Object.values(KOMUNIKATY)) {
        assert.match(tresc, /Naciśnij|naciśnij|Podłącz/);
    }
});
