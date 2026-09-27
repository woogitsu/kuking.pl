import test from 'node:test';
import assert from 'node:assert/strict';
import { uzgodnijUrzadzenie } from './powiadomienia-push-uzgodnij.js';

function przegladarka(subskrypcja) {
    return { serviceWorker: { getRegistration: async () => ({
        pushManager: { getSubscription: async () => subskrypcja },
    }) } };
}

test('po ponownym logowaniu wysyła dane faktycznej subskrypcji z przeglądarki', async () => {
    const dane = { endpoint: 'https://fcm.googleapis.com/fcm/send/wspolny', keys: { p256dh: 'abc', auth: 'xyz' } };
    let wyslane;
    const odlaczone = await uzgodnijUrzadzenie(przegladarka({ toJSON: () => dane }), async (payload) => {
        wyslane = payload;
        return { ok: true, json: async () => ({ odlaczone: true }) };
    });

    assert.equal(odlaczone, true);
    assert.deepEqual(wyslane, dane);
});

test('bez subskrypcji nie wysyła żądania ani nie tworzy nowej zgody', async () => {
    let liczba = 0;
    assert.equal(await uzgodnijUrzadzenie(przegladarka(null), async () => { liczba++; }), false);
    assert.equal(liczba, 0);
});

test('niepełne klucze i błąd sieci pozostawiają aplikację działającą', async () => {
    let liczba = 0;
    assert.equal(await uzgodnijUrzadzenie(przegladarka({ toJSON: () => ({ endpoint: 'x', keys: {} }) }), async () => { liczba++; }), false);
    assert.equal(liczba, 0);
    assert.equal(await uzgodnijUrzadzenie(przegladarka({ toJSON: () => ({ endpoint: 'x', keys: { p256dh: 'a', auth: 'b' } }) }), async () => {
        throw new Error('offline');
    }), false);
});
