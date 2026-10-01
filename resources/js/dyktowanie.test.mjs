import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    JEZYK, KOMUNIKATY, LIMIT_CISZY_MS, ZDANIE_O_DOSTAWCY,
    dopiszTekst, komunikatBledu, utworzDyktowanie, znajdzRozpoznawanie,
} from './dyktowanie.js';

/** Atrapa SpeechRecognition: zapamiętuje ostatnią instancję i pozwala wywoływać zdarzenia. */
function atrapa() {
    class Atrapa {
        constructor() {
            Atrapa.instancje.push(this);
            this.wolane = [];
        }

        start() { this.wolane.push('start'); }

        stop() { this.wolane.push('stop'); }

        abort() { this.wolane.push('abort'); }

        wynik(tekst, koncowy) {
            const wpis = [{ transcript: tekst }];
            wpis.isFinal = koncowy;
            this.onresult({ resultIndex: 0, results: [wpis] });
        }
    }
    Atrapa.instancje = [];
    return Atrapa;
}

/** Zegar, który odpala zaległe timery na żądanie. */
function zegar() {
    const timery = [];
    return {
        ustawCzas: (f, ms) => { timery.push({ f, ms, zywy: true }); return timery.length - 1; },
        zdejmijCzas: (id) => { if (timery[id]) timery[id].zywy = false; },
        odpal: () => timery.filter((t) => t.zywy).forEach((t) => { t.zywy = false; t.f(); }),
        timery,
    };
}

function maszyna() {
    const Rozpoznawanie = atrapa();
    const z = zegar();
    const wstawione = [];
    const stany = [];
    const m = utworzDyktowanie({
        Rozpoznawanie,
        wstaw: (t) => wstawione.push(t),
        naZmiane: (s) => stany.push(s),
        ustawCzas: z.ustawCzas,
        zdejmijCzas: z.zdejmijCzas,
    });
    return { m, Rozpoznawanie, z, wstawione, stany, silnik: () => Rozpoznawanie.instancje.at(-1) };
}

test('Bez SpeechRecognition przycisku nie ma: znajdzRozpoznawanie zwraca null', () => {
    assert.equal(znajdzRozpoznawanie({}), null);
    assert.equal(znajdzRozpoznawanie(undefined), null);
});

test('Jest SpeechRecognition albo webkitSpeechRecognition: dyktowanie jest dostępne', () => {
    const A = atrapa();
    assert.equal(znajdzRozpoznawanie({ SpeechRecognition: A }), A);
    assert.equal(znajdzRozpoznawanie({ webkitSpeechRecognition: A }), A);
});

test('Start ustawia język pl-PL i przechodzi w fazę „slucham”', () => {
    const { m, silnik, stany } = maszyna();
    m.start();

    assert.equal(JEZYK, 'pl-PL');
    assert.equal(silnik().lang, 'pl-PL');
    assert.deepEqual(silnik().wolane, ['start']);
    assert.equal(stany.at(-1).faza, 'slucham');
});

test('Transkrypcja trafia do podglądu, a NIE do pola: bez „Wstaw” nic nie jest wstawione', () => {
    const { m, silnik, wstawione, stany } = maszyna();
    m.start();
    silnik().wynik('dwie szklanki mąki', true);

    assert.equal(stany.at(-1).podglad, 'dwie szklanki mąki');
    assert.deepEqual(wstawione, []);

    silnik().onend();
    assert.equal(stany.at(-1).faza, 'podglad');
    assert.deepEqual(wstawione, [], 'Koniec nasłuchu nie może sam wstawić tekstu do przepisu.');
});

test('„Wstaw do przepisu” oddaje tekst raz i czyści podgląd', () => {
    const { m, silnik, wstawione, stany } = maszyna();
    m.start();
    silnik().wynik('pół kilo cukru', true);
    silnik().onend();

    assert.equal(m.wstaw(), true);
    assert.deepEqual(wstawione, ['pół kilo cukru']);
    assert.deepEqual({ faza: stany.at(-1).faza, podglad: stany.at(-1).podglad }, { faza: 'gotowe', podglad: '' });

    assert.equal(m.wstaw(), false, 'Drugi raz nie ma czego wstawiać.');
    assert.deepEqual(wstawione, ['pół kilo cukru']);
});

test('„Anuluj” czyści podgląd i niczego nie wstawia', () => {
    const { m, silnik, wstawione, stany } = maszyna();
    m.start();
    silnik().wynik('szczypta soli', true);
    silnik().onend();
    m.anuluj();

    assert.deepEqual(wstawione, []);
    assert.deepEqual({ faza: stany.at(-1).faza, podglad: stany.at(-1).podglad }, { faza: 'gotowe', podglad: '' });
});

test('Kolejne dyktowanie dopisuje do podglądu, nie kasuje poprzedniego', () => {
    const { m, silnik, stany } = maszyna();
    m.start();
    silnik().wynik('pierwsze zdanie', true);
    silnik().onend();
    m.start();
    silnik().wynik('drugie zdanie', true);
    silnik().onend();

    assert.equal(stany.at(-1).podglad, 'pierwsze zdanie drugie zdanie');
});

test('Wynik tymczasowy jest widoczny w podglądzie, ale nie zostaje po końcu, jeśli nie został potwierdzony', () => {
    const { m, silnik, stany } = maszyna();
    m.start();
    silnik().wynik('mąk', false);
    assert.equal(stany.at(-1).podglad, 'mąk');

    silnik().onend();
    assert.equal(stany.at(-1).podglad, '');
    assert.equal(stany.at(-1).faza, 'blad');
});

test('Odmowa mikrofonu: komunikat po polsku mówi, co zrobić; nic się nie wstawia', () => {
    const { m, silnik, wstawione, stany } = maszyna();
    m.start();
    silnik().onerror({ error: 'not-allowed' });

    assert.equal(stany.at(-1).faza, 'blad');
    assert.equal(stany.at(-1).komunikat, KOMUNIKATY.odmowa);
    assert.match(stany.at(-1).komunikat, /Zezwól na mikrofon/);
    assert.match(stany.at(-1).komunikat, /wpisać tekst klawiaturą/);
    assert.deepEqual(wstawione, []);
});

test('Brak sieci i brak mikrofonu mają własne komunikaty', () => {
    assert.equal(komunikatBledu('network'), KOMUNIKATY.siec);
    assert.match(KOMUNIKATY.siec, /Sprawdź internet/);
    assert.equal(komunikatBledu('audio-capture'), KOMUNIKATY.brakMikrofonu);
    assert.equal(komunikatBledu('service-not-allowed'), KOMUNIKATY.odmowa);
    assert.equal(komunikatBledu('coś-nowego'), KOMUNIKATY.inny);
});

test('Własne przerwanie („aborted”) nie jest błędem dla człowieka', () => {
    assert.equal(komunikatBledu('aborted'), null);
});

test('Błąd po wyniku zostawia podgląd do zatwierdzenia — podyktowany tekst nie ginie', () => {
    const { m, silnik, stany, wstawione } = maszyna();
    m.start();
    silnik().wynik('trzy jajka', true);
    silnik().onerror({ error: 'network' });

    assert.equal(stany.at(-1).faza, 'podglad');
    assert.equal(stany.at(-1).podglad, 'trzy jajka');
    assert.equal(stany.at(-1).komunikat, KOMUNIKATY.siec);

    m.wstaw();
    assert.deepEqual(wstawione, ['trzy jajka']);
});

test('Cisza: po limicie czasu nasłuch się kończy i jest komunikat', () => {
    const { m, silnik, z, stany } = maszyna();
    m.start();

    assert.equal(z.timery.at(-1).ms, LIMIT_CISZY_MS);
    z.odpal();

    assert.deepEqual(silnik().wolane, ['start', 'abort']);
    assert.equal(stany.at(-1).faza, 'blad');
    assert.equal(stany.at(-1).komunikat, KOMUNIKATY.cisza);
});

test('Wynik odświeża licznik ciszy: zegar po wyniku nie przerywa mówienia z poprzedniego timera', () => {
    const { m, silnik, z, stany } = maszyna();
    m.start();
    const pierwszy = z.timery.length - 1;
    silnik().wynik('ser', false);

    assert.equal(z.timery[pierwszy].zywy, false, 'Stary licznik ciszy musi zostać zdjęty.');
    assert.equal(stany.at(-1).faza, 'slucham');
});

test('Zdarzenia z poprzedniego nasłuchu (po anulowaniu) są ignorowane', () => {
    const { m, silnik, stany, wstawione } = maszyna();
    m.start();
    const stary = silnik();
    m.anuluj();
    stary.wynik('spóźniony tekst', true);
    stary.onend();

    assert.equal(stany.at(-1).podglad, '');
    assert.deepEqual(wstawione, []);
});

test('„Zakończ” woła stop() i pozwala odebrać ostatni wynik', () => {
    const { m, silnik, stany } = maszyna();
    m.start();
    silnik().wynik('liść laurowy', false);
    m.zakoncz();
    silnik().wynik('liść laurowy', true);
    silnik().onend();

    assert.deepEqual(silnik().wolane, ['start', 'stop']);
    assert.equal(stany.at(-1).faza, 'podglad');
    assert.equal(stany.at(-1).podglad, 'liść laurowy');
});

test('Rzucony wyjątek z konstruktora albo start() daje komunikat, nie ciszę', () => {
    const wstawione = [];
    const stany = [];
    const Zepsuta = class { constructor() { throw new Error('nie ma'); } };
    const m = utworzDyktowanie({ Rozpoznawanie: Zepsuta, wstaw: (t) => wstawione.push(t), naZmiane: (s) => stany.push(s) });
    m.start();

    assert.equal(stany.at(-1).faza, 'blad');
    assert.equal(stany.at(-1).komunikat, KOMUNIKATY.inny);
});

test('dopiszTekst dopisuje na końcu i nigdy nie zastępuje wpisanego tekstu', () => {
    assert.equal(dopiszTekst('', 'mąka'), 'mąka');
    assert.equal(dopiszTekst('Wymieszaj.', 'Dodaj mleko.'), 'Wymieszaj. Dodaj mleko.');
    assert.equal(dopiszTekst('Wymieszaj. ', 'Dodaj mleko.'), 'Wymieszaj. Dodaj mleko.');
    assert.equal(dopiszTekst('1 cebula', '2 marchewki', 'nowa-linia'), '1 cebula\n2 marchewki');
    assert.equal(dopiszTekst('Krok 1.', 'Krok 2.', 'akapit'), 'Krok 1.\n\nKrok 2.');
    assert.equal(dopiszTekst('Już wpisane', '   '), 'Już wpisane');
    assert.equal(dopiszTekst('Już wpisane', ''), 'Już wpisane');
});

test('Zdanie przy przycisku mówi o dostawcy przeglądarki i o tym, że Kuking nie dostaje dźwięku', () => {
    assert.equal(
        ZDANIE_O_DOSTAWCY,
        'Dyktowanie obsługuje Twoja przeglądarka i może wysyłać dźwięk do swojego dostawcy (np. Google lub Apple). Kuking nie nagrywa dźwięku i nie dostaje go — tylko tekst, który wstawisz do przepisu.',
    );
});

test('Skrypt nie nagrywa i nie wysyła niczego sam: brak getUserMedia, MediaRecorder, fetch, XHR, sendBeacon, WebSocket', () => {
    const zrodlo = readFileSync(new URL('./dyktowanie.js', import.meta.url), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');

    for (const zakazane of ['getUserMedia', 'MediaRecorder', 'AudioContext', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'WebSocket', 'localStorage', 'sessionStorage']) {
        assert.equal(zrodlo.includes(zakazane), false, `dyktowanie.js nie może używać: ${zakazane}`);
    }
});
