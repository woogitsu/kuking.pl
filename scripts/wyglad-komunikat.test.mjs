/*
 * Granica, której ten test pilnuje: komunikat porażki pomiaru wyglądu ma
 * donieść LICZBY w całości i nie donieść ANI SŁOWA ze strony.
 *
 * Obie połowy są potrzebne. Bez pierwszej pozycja M-4 rejestru migotania
 * zostaje hipotezą: `TimeoutError w …/szybki-wyglad.mjs:240` nie mówi ani
 * gdzie stała strona, ani gdzie stał przycisk. Bez drugiej do dziennika CI —
 * który czyta każdy, kto ma do niego wgląd — wychodzi to, co pomiar wpisał
 * w pole logowania.
 *
 *   node --test scripts/wyglad-komunikat.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { komunikatPomiaru, sprawdzLiscie } from './wyglad-komunikat.mjs';
import { komunikatBledu } from './panel-komunikat.mjs';

const HASLO = 'haslo-testowe-123';

const POMIAR = {
  scrollY: 445,
  wysokoscDokumentu: 2955,
  okno: 456,
  wPrzeplywie: false,
  widget: { x: 12, y: 2107, szerokosc: 360, wysokosc: 65 },
  blad: { x: 34, y: 323, szerokosc: 650, wysokosc: 117 },
  bledow: 1,
  zaslania: true,
};

test('cztery liczby dochodzą do dziennika w całości', () => {
  const wynik = komunikatPomiaru('P581_WYGLAD_ZASLANIA_BLAD', POMIAR);

  assert.match(wynik, /^P581_WYGLAD_ZASLANIA_BLAD: /);
  for (const liczba of ['445', '2955', '2107', '323']) {
    assert.ok(wynik.includes(liczba), `Brakuje liczby ${liczba} w: ${wynik}`);
  }
});

test('komunikat przechodzi przez granicę poświadczeń nieskrócony', () => {
  const wynik = komunikatPomiaru('P581_WYGLAD_NIE_USTAPIL', POMIAR);
  const blad = new Error(wynik);

  /* To jest cały powód przedrostka: `komunikatBledu` zwija wszystko, co nie
     pasuje do `^P581_[A-Z_]+(?::|$)`, więc kod bez przedrostka zniknąłby
     razem z liczbami. */
  assert.equal(komunikatBledu(blad), wynik);
  assert.notEqual(komunikatBledu(new Error(`WYGLAD_NIE_USTAPIL: ${JSON.stringify(POMIAR)}`)), `WYGLAD_NIE_USTAPIL: ${JSON.stringify(POMIAR)}`);
});

test('napis w diagnostyce kończy się wyjątkiem, a nie wyciekiem', () => {
  const zTrescia = { ...POMIAR, blad: { ...POMIAR.blad, tekst: `Wpisano ${HASLO}` } };

  assert.throws(
    () => komunikatPomiaru('P581_WYGLAD_ZASLANIA_BLAD', zTrescia),
    (blad) => {
      assert.match(blad.message, /nie-liczbowy liść w dane\.blad\.tekst/);
      assert.ok(!blad.message.includes(HASLO), `Wartość liścia wyszła w wyjątku: ${blad.message}`);

      return true;
    },
  );
});

test('strażnik łapie napis także pod tablicą i pod zagnieżdżeniem', () => {
  assert.throws(() => sprawdzLiscie({ kroki: [{ scrollY: 0 }, { scrollY: 'sto' }] }), /dane\.kroki\[1\]\.scrollY/);
  assert.throws(() => sprawdzLiscie({ blad: { rect: new Date() } }), /dane\.blad\.rect/);
  assert.throws(() => sprawdzLiscie({ scrollY: Number.NaN }), /nie jest skończoną liczbą/);
  assert.doesNotThrow(() => sprawdzLiscie({ kroki: [{ scrollY: 0, widget: null, zaslania: false }] }));
});

test('kod bez przedrostka jest odmawiany na miejscu', () => {
  assert.throws(() => komunikatPomiaru('WYGLAD_ZASLANIA_BLAD', POMIAR), /P581_ZLY_KOD_POMIARU/);
  assert.throws(() => komunikatPomiaru('P581_wyglad', POMIAR), /P581_ZLY_KOD_POMIARU/);
});
