import test from 'node:test';
import assert from 'node:assert/strict';
import {pozostaloSekund, formatMinutySekundy, kluczStanu, zapiszStan, odczytajStan, odczytajTermin, krokZKlucza, PRZETERMINOWANIE_NAJWYZEJ_MS} from './minutnik-krok.js';

test('pozostaloSekund liczy z zegara monotonicznego, nie ze zegara sciennego (issue #751)', () => {
    // Start minutnika: 5 minut = 300 sekund, na dowolnym punkcie zegara
    // monotonicznego (nie zero -- performance.now() rzadko zaczyna od zera).
    const start = 123456;
    const termin = start + 300_000;

    // Naprawde minelo 100 sekund monotonicznego czasu -- zostalo 200.
    assert.equal(pozostaloSekund(termin, start + 100_000), 200);

    // KLUCZOWY DOWOD: gdyby liczenie oparto na Date.now() (zegarze sciennym),
    // korekta NTP w trakcie odliczania natychmiast zmienialaby wynik, mimo
    // ze naprawde uplynelo dokladnie tyle samo czasu. Ta funkcja w ogole
    // nie widzi zegara sciennego -- przyjmuje wylacznie odczyty zegara
    // monotonicznego, wiec korekta zegara systemowego nie ma tu jak wejsc.
    assert.equal(pozostaloSekund(termin, start + 100_000), 200);

    // Nie schodzi ponizej zera, gdy termin juz minal.
    assert.equal(pozostaloSekund(termin, start + 999_000), 0);
});

test('formatMinutySekundy pokazuje sekundy zawsze na dwoch cyfrach', () => {
    assert.equal(formatMinutySekundy(0), '0:00');
    assert.equal(formatMinutySekundy(5), '0:05');
    assert.equal(formatMinutySekundy(65), '1:05');
    assert.equal(formatMinutySekundy(3661), '61:01');
});

test('kluczStanu rozroznia przepis i krok, zeby minutniki sie nie mieszaly', () => {
    assert.notEqual(kluczStanu('zupa', 1), kluczStanu('zupa', 2));
    assert.notEqual(kluczStanu('zupa', 1), kluczStanu('kotlety', 1));
});

test('odczytajStan po przeladowaniu liczy nowy termin wzgledem SWIEZEGO performance.now() (issue #740)', () => {
    const zapis = zapiszStan(300, /* terminEpoka */ 1_000_000 + 200_000);

    // Strona zaladowala sie ponownie: performance.now() zaczyna od zera,
    // a od zapisania stanu minelo (na zegarze sciennym) 50 sekund.
    const stan = odczytajStan(zapis, /* terazEpoka */ 1_050_000, /* terazMonotoniczny */ 0);

    assert.ok(stan);
    // Zostalo 150 sekund odliczania (200 - 50) -- i to wzgledem NOWEGO
    // punktu zerowego zegara monotonicznego tej strony, nie starego.
    assert.equal(pozostaloSekund(stan.terminMonotoniczny, 0), 150);
});

test('odczytajStan zwraca null, gdy zapis jest pusty, uszkodzony albo termin juz minal', () => {
    assert.equal(odczytajStan(null, 0, 0), null);
    assert.equal(odczytajStan('{niepoprawny json', 0, 0), null);
    assert.equal(odczytajStan(zapiszStan(60, 1000), /* terazEpoka */ 5000, 0), null);
});

test('odczytajTermin nie gubi terminu, ktory minal w trakcie przeladowania (issue #1301)', () => {
    // Minutnik kroku 1 skonczyl sie 2 sekundy przed zaladowaniem kroku 2.
    const stan = odczytajTermin(zapiszStan(60, 1000), /* terazEpoka */ 3000, /* terazMonotoniczny */ 500);

    assert.ok(stan, 'Termin w przeszlosci musi wrocic, zeby alarm zdazyl zabrzmiec');
    assert.equal(stan.terminMonotoniczny, -1500);
    assert.equal(pozostaloSekund(stan.terminMonotoniczny, 500), 0);

    assert.equal(odczytajTermin(null, 0, 0), null);
    assert.equal(odczytajTermin('{niepoprawny json', 0, 0), null);
    assert.equal(odczytajTermin(JSON.stringify({sekundyCalkiem: 'x', terminEpoka: 1}), 0, 0), null);
});

test('odczytajTermin pomija minutnik porzucony dawno po terminie (przeglad #1301)', () => {
    // 20 minut w kroku 3, "Zakoncz gotowanie" bez "Anuluj", powrot po 3 godzinach.
    const terminEpoka = 1_000_000;
    const poTrzechGodzinach = terminEpoka + 3 * 60 * 60 * 1000;

    assert.equal(odczytajTermin(zapiszStan(1200, terminEpoka), poTrzechGodzinach, 0), null,
        'Zapis sprzed godzin to porzucony minutnik, nie alarm do zagrania');

    // Na granicy jeszcze dzwoni, tuz za nia juz nie.
    assert.ok(odczytajTermin(zapiszStan(1200, terminEpoka), terminEpoka + PRZETERMINOWANIE_NAJWYZEJ_MS, 0));
    assert.equal(odczytajTermin(zapiszStan(1200, terminEpoka), terminEpoka + PRZETERMINOWANIE_NAJWYZEJ_MS + 1, 0), null);
});

test('krokZKlucza odczytuje krok tylko z kluczy minutnika TEGO przepisu (issue #1301)', () => {
    assert.equal(krokZKlucza(kluczStanu('zupa', 2), 'zupa'), '2');
    assert.equal(krokZKlucza(kluczStanu('zupa', 12), 'zupa'), '12');
    assert.equal(krokZKlucza(kluczStanu('zupa-pomidorowa', 2), 'zupa'), null);
    assert.equal(krokZKlucza(kluczStanu('kotlety', 2), 'zupa'), null);
    assert.equal(krokZKlucza('kuking.cos-innego', 'zupa'), null);
    assert.equal(krokZKlucza(kluczStanu('zupa', ''), 'zupa'), null);
    assert.equal(krokZKlucza(null, 'zupa'), null);
});
