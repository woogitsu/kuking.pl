import test from 'node:test';
import assert from 'node:assert/strict';
import { kluczNaBajty, stanEkranu, wybierzKodowanie } from './powiadomienia-push.js';

test('Klucz VAPID w base64url zamienia się na 65 bajtów zaczynających się od 0x04', () => {
    // Klucz publiczny P-256 w formie nieskompresowanej: 0x04 + 64 bajty.
    const bajty = new Uint8Array(65);
    bajty[0] = 4;
    bajty[64] = 255;
    const base64url = Buffer.from(bajty).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

    const wynik = kluczNaBajty(base64url);

    assert.equal(wynik.length, 65);
    assert.equal(wynik[0], 4);
    assert.equal(wynik[64], 255);
});

test('Kodowanie: aes128gcm, gdy przeglądarka je zna albo nic nie mówi; inaczej aesgcm', () => {
    assert.equal(wybierzKodowanie(['aes128gcm', 'aesgcm']), 'aes128gcm');
    assert.equal(wybierzKodowanie(undefined), 'aes128gcm');
    assert.equal(wybierzKodowanie(['aesgcm']), 'aesgcm');
});

test('Przeglądarka bez Web Push: żadnego przycisku, zdanie z podpowiedzią dla iPhone’a', () => {
    const stan = stanEkranu({ wspierane: false, zgoda: 'default', wlaczoneTutaj: false });
    assert.equal(stan.wlacz, false);
    assert.equal(stan.wylacz, false);
    assert.match(stan.tekst, /ekranu początkowego/);
});

test('Po odmowie nie nalegamy: bez przycisku „Włącz”, tylko gdzie to zmienić', () => {
    const stan = stanEkranu({ wspierane: true, zgoda: 'denied', wlaczoneTutaj: false });
    assert.equal(stan.wlacz, false);
    assert.equal(stan.wylacz, false);
    assert.match(stan.tekst, /zablokowane/);
});

test('Bez zgody i bez subskrypcji: jest „Włącz”, nie ma „Wyłącz”', () => {
    assert.deepEqual(stanEkranu({ wspierane: true, zgoda: 'default', wlaczoneTutaj: false }), {
        tekst: 'Powiadomienia na tym urządzeniu są wyłączone.', wlacz: true, wylacz: false,
    });
});

test('Subskrypcja, której serwer nie zna (np. po „Wyłącz wszędzie”), to stan wyłączony', () => {
    const stan = stanEkranu({ wspierane: true, zgoda: 'granted', wlaczoneTutaj: false });
    assert.equal(stan.wlacz, true);
    assert.equal(stan.wylacz, false);
});

test('Włączone tutaj: jest „Wyłącz”, nie ma „Włącz”', () => {
    assert.deepEqual(stanEkranu({ wspierane: true, zgoda: 'granted', wlaczoneTutaj: true }), {
        tekst: 'Powiadomienia na tym urządzeniu są włączone.', wlacz: false, wylacz: true,
    });
});
