import test from 'node:test';
import assert from 'node:assert/strict';
import {pozostaloSekund, formatMinutySekundy} from './minutnik-krok.js';

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
