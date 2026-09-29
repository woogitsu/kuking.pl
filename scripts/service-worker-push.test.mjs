/*
 * Web Push po stronie przeglądarki (#35, etap 2: „service worker obsługuje
 * payload i kliknięcie”). Wykonujemy prawdziwe źródło `public/sw.js` z atrapą
 * `self`, bez przeglądarki i bez VAPID.
 */
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const ORIGIN = 'https://kuking.test';

function worker() {
    const events = {};
    const pokazane = [];
    const otwarte = [];
    const okna = [];
    runInNewContext(readFileSync(new URL('../public/sw.js', import.meta.url), 'utf8'), {
        URL,
        caches: { async open() { return { async addAll() {}, async put() {} }; }, async keys() { return []; }, async delete() {}, async match() {} },
        async fetch() { throw new Error('offline'); },
        self: {
            location: { origin: ORIGIN },
            addEventListener(nazwa, fn) { events[nazwa] = fn; },
            async skipWaiting() {},
            registration: { async showNotification(tytul, opcje) { pokazane.push({ tytul, opcje }); } },
            clients: {
                async claim() {},
                async matchAll() { return okna; },
                async openWindow(adres) { otwarte.push(adres); },
            },
        },
    });
    return { events, pokazane, otwarte, okna };
}

async function push(w, data) {
    let praca;
    w.events.push({ data, waitUntil(p) { praca = p; } });
    await praca;
}

async function klik(w, adres) {
    let praca;
    let zamkniete = false;
    w.events.notificationclick({
        notification: { close() { zamkniete = true; }, data: adres === undefined ? undefined : { url: adres } },
        waitUntil(p) { praca = p; },
    });
    await praca;
    return zamkniete;
}

test('push pokazuje powiadomienie z treścią z payloadu', async () => {
    const w = worker();
    await push(w, { json: () => ({ title: 'Marek ugotował Twój przepis', body: 'Zupa jest gotowa.', tag: 'g1', url: '/przepisy/zupa' }) });
    assert.equal(w.pokazane.length, 1);
    assert.equal(w.pokazane[0].tytul, 'Marek ugotował Twój przepis');
    assert.equal(w.pokazane[0].opcje.body, 'Zupa jest gotowa.');
    assert.equal(w.pokazane[0].opcje.tag, 'g1');
    assert.equal(w.pokazane[0].opcje.data.url, `${ORIGIN}/przepisy/zupa`);
});

test('push bez danych albo z uszkodzonym payloadem i tak pokazuje neutralne powiadomienie', async () => {
    for (const data of [undefined, { json() { throw new Error('zły json'); } }, { json: () => ({}) }]) {
        const w = worker();
        await push(w, data);
        assert.equal(w.pokazane.length, 1, 'Push przyszedł, więc człowiek ma coś zobaczyć.');
        assert.equal(w.pokazane[0].tytul, 'Kuking');
        assert.equal(w.pokazane[0].opcje.body, 'Masz nowe powiadomienie w Kuking.');
        assert.equal(w.pokazane[0].opcje.data.url, `${ORIGIN}/powiadomienia`);
    }
});

test('adres spoza serwisu w payloadzie zamienia się na listę powiadomień', async () => {
    for (const obcy of ['https://obcy.example/x', 'javascript:alert(1)', 42]) {
        const w = worker();
        await push(w, { json: () => ({ title: 'x', url: obcy }) });
        assert.equal(w.pokazane[0].opcje.data.url, `${ORIGIN}/powiadomienia`);
    }
});

test('kliknięcie zamyka powiadomienie i otwiera adres z payloadu', async () => {
    const w = worker();
    assert.equal(await klik(w, `${ORIGIN}/przepisy/zupa`), true);
    assert.deepEqual(w.otwarte, [`${ORIGIN}/przepisy/zupa`]);
});

test('kliknięcie bez adresu albo z obcym adresem otwiera listę powiadomień', async () => {
    for (const adres of [undefined, 'https://obcy.example/x']) {
        const w = worker();
        await klik(w, adres);
        assert.deepEqual(w.otwarte, [`${ORIGIN}/powiadomienia`]);
    }
});

test('kliknięcie ustawia fokus na już otwartej karcie zamiast otwierać drugą', async () => {
    const w = worker();
    let fokus = 0;
    w.okna.push({ url: `${ORIGIN}/powiadomienia`, focus() { fokus++; return Promise.resolve(); } });
    await klik(w, `${ORIGIN}/powiadomienia`);
    assert.equal(fokus, 1);
    assert.deepEqual(w.otwarte, []);
});
