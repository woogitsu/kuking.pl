import test from 'node:test';
import assert from 'node:assert/strict';
import { kluczNaBajty, przygotujWylogowanie, stanEkranu, wybierzKodowanie } from './powiadomienia-push.js';

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

// --- Wylogowanie (#1979) ---------------------------------------------------

function atrapaNawigatora({ subskrypcja = null, rejestracja = true, getRegistration = null } = {}) {
    return {
        serviceWorker: {
            getRegistration: getRegistration ?? (async () => (rejestracja
                ? { pushManager: { getSubscription: async () => subskrypcja } }
                : undefined)),
        },
    };
}

test('Wylogowanie: przeglądarka z subskrypcją wypisuje się i podaje serwerowi jej adres', async () => {
    let wypisana = 0;
    const subskrypcja = { endpoint: 'https://fcm.googleapis.com/fcm/send/basia', unsubscribe: async () => { wypisana += 1; return true; } };

    const adres = await przygotujWylogowanie(atrapaNawigatora({ subskrypcja }), 200);

    assert.equal(adres, 'https://fcm.googleapis.com/fcm/send/basia');
    assert.equal(wypisana, 1);
});

test('Wylogowanie: bez subskrypcji, bez workera i bez Service Workera — pusty adres, bez błędu', async () => {
    assert.equal(await przygotujWylogowanie(atrapaNawigatora({ subskrypcja: null }), 200), '');
    assert.equal(await przygotujWylogowanie(atrapaNawigatora({ rejestracja: false }), 200), '');
    assert.equal(await przygotujWylogowanie({}, 200), '');
    assert.equal(await przygotujWylogowanie(null, 200), '');
});

test('Wylogowanie: nieudane unsubscribe() nie zabiera serwerowi adresu', async () => {
    const subskrypcja = { endpoint: 'https://fcm.googleapis.com/fcm/send/x', unsubscribe: async () => { throw new Error('sieć'); } };

    assert.equal(await przygotujWylogowanie(atrapaNawigatora({ subskrypcja }), 200), 'https://fcm.googleapis.com/fcm/send/x');
});

test('Wylogowanie: błąd i zawieszenie przeglądarki nie zatrzymują wyjścia z konta', async () => {
    const blad = atrapaNawigatora({ getRegistration: async () => { throw new Error('worker'); } });
    assert.equal(await przygotujWylogowanie(blad, 200), '');

    const wisi = atrapaNawigatora({ getRegistration: () => new Promise(() => {}) });
    const start = Date.now();
    assert.equal(await przygotujWylogowanie(wisi, 50), '');
    assert.ok(Date.now() - start < 1000, 'limit czasu musi zadziałać');
});
